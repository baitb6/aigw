<?php
/**
 * 深度研究服务（参考 Operit 的 DeepSearch / deepsearching 插件）
 *
 * 流程（对齐 Operit）：
 *   ① 规划：让 LLM 把研究主题拆成 3~6 个带依赖的子任务，输出 JSON 执行图；
 *   ② 分层执行：按依赖拓扑分层，同层子任务并发（PHP 顺序执行，保持语义一致）；
 *   ③ 汇总：把叶节点结果 + 汇总指令交给 LLM 产出最终报告。
 *
 * 与 Operit 的差异：Operit 钩住消息处理管线、在 Android 端跑；这里做成
 * 服务端异步任务（提交 → worker → 轮询），复用 aigw_research_task。
 */

declare(strict_types=1);

namespace App\Services;

use App\Provider\Dto\ChatRequest;
use App\Repositories\ResearchTaskRepository;
use App\Support\Logger;

class ResearchService
{
    /** 子任务数量上限（对齐 Operit 的 3~6 建议，放宽到 8 兜底） */
    private const MAX_TASKS = 8;

    /** 规划提示词 */
    private const PLAN_PROMPT = <<<'TXT'
你是研究规划专家。请把用户的研究主题拆解为 3~6 个可独立执行、且尽量并行的子任务。

严格要求：
1. 只输出一个 JSON 对象，不要输出 Markdown 代码块、不要任何解释；
2. JSON 结构为：
   {"tasks":[{"id":"t1","name":"子任务名","instruction":"具体要研究/回答的内容","dependencies":[]}],"final_summary_instruction":"如何汇总这些子任务"}
3. 每个子任务的 id 唯一；dependencies 填它依赖的前置子任务 id（无依赖填 []）；
4. 各字段均使用中文。
TXT;

    private const SUBTASK_SYSTEM_PROMPT = <<<'TXT'
你是一名严谨的研究助理。请基于你自己的知识，针对给定子任务给出准确、结构化的回答。
不要提问、不要等待用户输入，直接输出结论要点。
TXT;

    public function __construct(
        private ResearchTaskRepository $repository,
        private GatewayService $gateway,
    ) {}

    /** 提交研究任务（立即返回 task_id，由 worker 异步执行） */
    public function submit(?int $userId, string $topic): array
    {
        return $this->repository->create($userId, $topic);
    }

    public function status(string $taskId, ?int $userId): ?array
    {
        return $this->repository->findForUser($taskId, $userId);
    }

    /**
     * 执行一次研究任务（由 worker 调用）。
     *
     * @return array{ok:bool,message:string,result:string}
     */
    public function run(string $taskId): array
    {
        $task = $this->repository->findByTaskId($taskId);
        if ($task === null) {
            return ['ok' => false, 'message' => '任务不存在', 'result' => ''];
        }
        if ($this->repository->claim($taskId) < 1) {
            return ['ok' => false, 'message' => '任务已被其他 worker 接管', 'result' => ''];
        }

        $topic = (string) ($task['topic'] ?? '');
        $model = $this->pickModel();

        try {
            if ($model === '') {
                throw new \RuntimeException('没有可用的模型来执行研究任务');
            }

            // ① 规划
            $graph = $this->plan($model, $topic);
            $this->repository->progress($taskId, '已规划 ' . count($graph['tasks']) . ' 个子任务', $graph);

            // ② 分层执行
            $steps = $this->executeGraph($taskId, $model, $graph);

            // ③ 汇总
            $this->repository->progress($taskId, '正在汇总研究结论…', $graph, $steps);
            $report = $this->summarize($model, $topic, $graph, $steps);

            $this->repository->finish($taskId, true, '研究完成', $report);

            return ['ok' => true, 'message' => '研究完成', 'result' => $report];
        } catch (\Throwable $e) {
            Logger::warn('深度研究失败', ['task' => $taskId, 'error' => $e->getMessage()]);
            $this->repository->finish($taskId, false, $e->getMessage(), '', 'RESEARCH_ERROR');

            return ['ok' => false, 'message' => $e->getMessage(), 'result' => ''];
        }
    }

    /** ① 规划：产出执行图 */
    private function plan(string $model, string $topic): array
    {
        $content = $this->chat($model, [
            ['role' => 'system', 'content' => self::PLAN_PROMPT],
            ['role' => 'user', 'content' => '研究主题：' . $topic],
        ]);

        $graph = $this->parseGraph($content);

        if ($graph['tasks'] === []) {
            // 规划失败兜底：退化为单任务
            $graph = [
                'tasks' => [[
                    'id'           => 't1',
                    'name'         => '直接研究',
                    'instruction'  => $topic,
                    'dependencies' => [],
                ]],
                'final_summary_instruction' => '综合以上内容，给出完整结论。',
            ];
        }

        return $graph;
    }

    /** ② 分层执行子任务（Kahn 分层，同层顺序执行） */
    private function executeGraph(string $taskId, string $model, array $graph): array
    {
        $tasks = $graph['tasks'];
        $maxRounds = count($tasks) + 1;
        $completed = [];   // id => output
        $steps = [];

        for ($round = 0; $round < $maxRounds; $round++) {
            $ready = [];
            foreach ($tasks as $task) {
                $id = (string) $task['id'];
                if (isset($completed[$id]) || $this->hasStep($steps, $id)) {
                    continue;
                }
                $deps = (array) ($task['dependencies'] ?? []);
                $satisfied = true;
                foreach ($deps as $dep) {
                    if (!isset($completed[(string) $dep])) {
                        $satisfied = false;
                        break;
                    }
                }
                if ($satisfied) {
                    $ready[] = $task;
                }
            }

            if ($ready === []) {
                break; // 无就绪节点（环或全部完成）
            }

            foreach ($ready as $task) {
                $id = (string) $task['id'];
                $instruction = (string) $task['instruction'];

                // 把依赖输出拼进上下文
                $context = '';
                foreach ((array) ($task['dependencies'] ?? []) as $dep) {
                    $depId = (string) $dep;
                    if (isset($completed[$depId])) {
                        $context .= "\n\n【前置子任务 {$depId} 的结论】\n" . $completed[$depId];
                    }
                }

                $this->repository->progress($taskId, "正在执行子任务：{$task['name']}");

                try {
                    $output = $this->chat($model, [
                        ['role' => 'system', 'content' => self::SUBTASK_SYSTEM_PROMPT],
                        ['role' => 'user', 'content' => $instruction . $context],
                    ]);
                    $output = $this->stripThink($output);
                    $ok = true;
                } catch (\Throwable $e) {
                    $output = '子任务执行失败：' . $e->getMessage();
                    $ok = false;
                }

                $completed[$id] = $output;
                $steps[] = [
                    'id'    => $id,
                    'name'  => (string) $task['name'],
                    'ok'    => $ok,
                    'output' => $output,
                ];
                $this->repository->progress($taskId, '已完成：' . $task['name'], null, $steps);
            }
        }

        return $steps;
    }

    /** ③ 汇总 */
    private function summarize(string $model, string $topic, array $graph, array $steps): string
    {
        if ($steps === []) {
            return '未能产出研究内容。';
        }

        // 汇总上下文优先选取「叶任务」（不被其他任务依赖者），对齐 Operit
        $dependent = [];
        foreach ($graph['tasks'] as $task) {
            foreach ((array) ($task['dependencies'] ?? []) as $dep) {
                $dependent[(string) $dep] = true;
            }
        }

        $leaves = array_values(array_filter($steps, static fn ($s) => empty($dependent[$s['id']])));
        $contextSteps = $leaves === [] ? $steps : $leaves;

        $context = '';
        foreach ($contextSteps as $step) {
            $context .= "### {$step['name']}\n{$step['output']}\n\n";
        }

        $instruction = (string) ($graph['final_summary_instruction'] ?? '');
        if ($instruction === '') {
            $instruction = '综合以上各部分内容，给出结构清晰、结论明确的完整研究报告。';
        }

        $prompt = "研究主题：{$topic}\n\n以下是各子任务的研究结论：\n\n{$context}\n"
            . "请据此完成汇总：{$instruction}";

        $report = $this->chat($model, [
            ['role' => 'system', 'content' => '你是资深研究员，擅长把多方材料整合成条理清晰的研究报告。'],
            ['role' => 'user', 'content' => $prompt],
        ]);

        return $this->stripThink($report);
    }

    /** 执行一次非流式 chat，返回拼接后的文本 */
    private function chat(string $model, array $messages): string
    {
        $request = ChatRequest::fromArray([
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
        ]);

        $content = '';
        $this->gateway->complete($request, function (string $delta) use (&$content): void {
            $content .= $delta;
        });

        return trim($content);
    }

    /** 清理思维链标签 */
    private function stripThink(string $text): string
    {
        return trim(preg_replace('#<think(?:ing)?>[\s\S]*?</think(?:ing)?>#i', '', $text) ?? $text);
    }

    /** 从模型输出里解析执行图 */
    private function parseGraph(string $content): array
    {
        $content = $this->stripThink($content);

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return ['tasks' => [], 'final_summary_instruction' => ''];
        }

        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
        if (!is_array($decoded) || !is_array($decoded['tasks'] ?? null)) {
            return ['tasks' => [], 'final_summary_instruction' => ''];
        }

        $tasks = [];
        foreach ($decoded['tasks'] as $index => $task) {
            if (!is_array($task)) {
                continue;
            }
            $id = (string) ($task['id'] ?? ('t' . ($index + 1)));
            $instruction = (string) ($task['instruction'] ?? $task['name'] ?? '');
            if ($instruction === '') {
                continue;
            }
            $deps = is_array($task['dependencies'] ?? null) ? $task['dependencies'] : [];
            $tasks[] = [
                'id'           => $id,
                'name'         => (string) ($task['name'] ?? $id),
                'instruction'  => $instruction,
                'dependencies' => array_values(array_map('strval', $deps)),
            ];
            if (count($tasks) >= self::MAX_TASKS) {
                break;
            }
        }

        return [
            'tasks' => $tasks,
            'final_summary_instruction' => (string) (
                $decoded['final_summary_instruction']
                ?? $decoded['finalSummaryInstruction']
                ?? ''
            ),
        ];
    }

    private function hasStep(array $steps, string $id): bool
    {
        foreach ($steps as $step) {
            if (($step['id'] ?? '') === $id) {
                return true;
            }
        }
        return false;
    }

    private function pickModel(): string
    {
        try {
            foreach ($this->gateway->listModels() as $model) {
                $id = (string) ($model['id'] ?? '');
                if ($id !== '') {
                    return $id;
                }
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }
}
