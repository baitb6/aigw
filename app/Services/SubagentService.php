<?php
/**
 * 被动式子代理服务（参考 Operit 的 subagent 插件 / subagent_run 工具）
 * ============================================================================
 *
 * Operit 里 subagent 是一个 ToolPkg：主 agent 调用 `subagent_run(task, ...)`，
 * 内部以「子任务模式」调用 AI，跑完把结果用轻量 XML 文本协议回传：
 *
 *   <subagent><update run="s1ab" stage="executing" count="2">已调用 read_file</update></subagent>
 *   <subagent><final run="s1ab" success="true" tool_count="2">……2~6 行总结……</final></subagent>
 *
 * 网关侧的移植思路：
 *   - 网关没有 Android 的 EnhancedAIService，但**有** GatewayService::complete()，
 *     在服务端运行隔离 LLM 会话和有限预算的网关工具循环。
 *   - 复用同一套 XML 协议，让 App 侧解析逻辑与 Operit 保持一致。
 *   - 运行记录写入 aigw_subagent_run，便于审计与前端展示。
 *
 * 网关只执行 GatewayToolsService 允许的 HTTP/逻辑工具；手机文件任务由
 * App 侧独立文件子代理执行，网关不接收本地路径任务，也不递归委托。
 * ============================================================================
 */

declare(strict_types=1);

namespace App\Services;

use App\Provider\Dto\ChatRequest;
use App\Repositories\SubagentRunRepository;
use App\Support\Logger;

class SubagentService
{
    /** 总结文本裁剪长度（对齐 Operit 的 320） */
    private const SUMMARY_MAX = 320;

    /** 失败信息裁剪长度（对齐 Operit 的 220） */
    private const ERROR_MAX = 220;

    /** 任务正文上限 */
    private const TASK_MAX = 4000;

    /** 每次运行允许的最大实际工具调用次数 */
    private const MAX_TOOL_CALLS_CAP = 16;

    /** 子代理专用系统提示（对齐 Operit 的 SUBAGENT_EXTRA_CONSTRAINTS） */
    private const SUBAGENT_SYSTEM_PROMPT = <<<'TXT'
你是一个被动的子代理（passive subagent），正在网关服务端运行。
- 本次运行只处理一个被委托的具体任务。
- 紧扣委托任务与给定的目标路径，避免无关探索。
- 不要向用户提问，也不要等待用户输入；若被阻塞，简短说明阻塞点后结束。
- 你的最终输出必须简短、可直接被父代理使用（2~6 行）。
TXT;

    public function __construct(
        private SubagentRunRepository $repository,
        private GatewayService $gateway,
        private ?GatewayToolsService $tools = null,
    ) {}

    /**
     * 执行一次子代理委托任务，返回 XML 协议文本（与 Operit 一致）。
     *
     * @param array{task:string,context_text?:string,target_paths_json?:string,max_tool_calls?:mixed} $params
     */
    public function run(array $params, string $model, ?int $userId): string
    {
        $runId = $this->createRunId();
        $startedAt = now_ms();

        $task = trim((string) ($params['task'] ?? ''));
        $contextText = trim((string) ($params['context_text'] ?? ''));
        $maxToolCalls = $this->parseMaxToolCalls($params['max_tool_calls'] ?? null);

        if ($task === '') {
            return $this->finalXml($runId, false, 0, '执行失败：task 不能为空');
        }
        if (mb_strlen($task) > self::TASK_MAX) {
            $task = mb_substr($task, 0, self::TASK_MAX);
        }

        try {
            $targetPaths = $this->parseTargetPaths($params['target_paths_json'] ?? null);
        } catch (\Throwable $e) {
            return $this->finalXml($runId, false, 0, $this->clip('执行失败：' . $e->getMessage(), self::ERROR_MAX));
        }

        // 落一条运行记录（失败也要能查到）
        try {
            $this->repository->create($userId, $runId, $model, [
                'task'           => $task,
                'context_text'   => $contextText,
                'target_paths'   => $targetPaths,
                'max_tool_calls' => $maxToolCalls,
            ]);
        } catch (\Throwable $e) {
            Logger::warn('子代理运行记录写入失败', ['run_id' => $runId, 'error' => $e->getMessage()]);
        }

        $toolCount = 0;
        try {
            if ($targetPaths !== []) {
                throw new \RuntimeException('网关不能访问手机本地文件；带目标路径的任务必须由 App 文件子代理执行');
            }
            $allowedTools = $params['allowed_tools'] ?? null;
            if ($allowedTools !== null && (!is_array($allowedTools) || !array_is_list($allowedTools)
                || count(array_filter($allowedTools, 'is_string')) !== count($allowedTools))) {
                throw new \InvalidArgumentException('allowed_tools 必须是字符串数组');
            }
            $raw = $this->runCompletion($model, $task, $contextText, $targetPaths, $maxToolCalls, $toolCount, $allowedTools, (string) ($params['system_instruction'] ?? ''));
            $summary = $this->clip($this->extractFinalContent($raw), self::SUMMARY_MAX);
            if ($summary === '') {
                throw new \RuntimeException('子代理未返回总结文本');
            }

            $this->safeFinish($runId, true, $toolCount, $summary, '', now_ms() - $startedAt);
            return $this->finalXml($runId, true, $toolCount, $summary);
        } catch (\Throwable $e) {
            $message = $this->clip('执行失败：' . $e->getMessage(), self::ERROR_MAX);
            $this->safeFinish($runId, false, $toolCount, '', $e->getMessage(), now_ms() - $startedAt);
            return $this->finalXml($runId, false, $toolCount, $message);
        }
    }

    /** 隔离工具循环：仅使用网关显式允许的 HTTP/逻辑工具。 */
    private function runCompletion(string $model, string $task, string $contextText, array $targetPaths, int $maxToolCalls, int &$toolCount, ?array $allowedTools = null, string $instruction = ''): string
    {
        $tools = $this->tools ?? new GatewayToolsService();
        $names = $allowedTools === null ? $tools->names() : array_values(array_intersect($tools->names(), $allowedTools));
        $schemas = array_values(array_filter($tools->schemas(), static fn (array $schema): bool => in_array($schema['function']['name'], $names, true)));
        $messages = [
            ['role' => 'system', 'content' => self::SUBAGENT_SYSTEM_PROMPT . "\n仅可使用提供的网关工具，不能访问手机文件、Shell 或 JS；禁止再次委托子代理。\n" . $this->clip($instruction, 4000)],
            ['role' => 'user', 'content' => $this->buildDelegatedTaskMessage($task, $contextText, $targetPaths, $maxToolCalls)],
        ];
        $failed = false;
        for ($round = 0; $round <= $maxToolCalls; $round++) {
            $request = ChatRequest::fromArray([
                'model' => $model,
                'messages' => $messages,
                'tools' => $toolCount < $maxToolCalls ? $schemas : [],
                'stream' => false,
            ]);
            $content = '';
            $response = $this->gateway->complete($request, function (string $delta) use (&$content): void {
                $content .= $delta;
            });
            $chunk = $response['chunk'] ?? null;
            $calls = $chunk->toolCalls ?? [];
            if ($calls === []) {
                if ($failed) {
                    throw new \RuntimeException('子代理工具执行失败；总结：' . ($content !== '' ? $content : ($chunk->content ?? '')));
                }
                return trim($content !== '' ? $content : (string) ($chunk->content ?? ''));
            }
            $messages[] = ['role' => 'assistant', 'content' => $content, 'tool_calls' => $calls];
            foreach ($calls as $index => $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $id = (string) ($call['id'] ?? '');
                if ($id === '') {
                    throw new \RuntimeException('上游工具调用缺少 id');
                }
                $result = ['is_error' => true, 'result' => '工具预算已用尽'];
                if ($toolCount < $maxToolCalls) {
                    $toolCount++;
                    $args = json_decode((string) ($call['function']['arguments'] ?? '{}'));
                    if (!$args instanceof \stdClass || !in_array($name, $names, true)) {
                        $result['result'] = '工具不在网关允许作用域内或参数不是 JSON 对象';
                    } else {
                        $result = $tools->invoke($name, json_decode(json_encode($args), true));
                    }
                }
                $failed = $failed || $result['is_error'];
                $messages[] = ['role' => 'tool', 'tool_call_id' => $id,
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
            }
        }
        throw new \RuntimeException('子代理工具预算已用尽，未完成总结');
    }

    /** 组装委托任务消息（对齐 Operit 的 buildDelegatedTaskMessage） */
    private function buildDelegatedTaskMessage(string $task, string $contextText, array $targetPaths, int $maxToolCalls): string
    {
        $lines = [];
        $lines[] = '来自父代理的委托任务：';
        $lines[] = $task;

        if ($contextText !== '') {
            $lines[] = '';
            $lines[] = '补充上下文与约束：';
            $lines[] = $contextText;
        }

        if ($targetPaths !== []) {
            $lines[] = '';
            $lines[] = '优先处理的目标路径（除非确有必要，否则不要扩展范围）：';
            foreach ($targetPaths as $index => $path) {
                $lines[] = ($index + 1) . '. ' . $path;
            }
        }

        $lines[] = '';
        $lines[] = '执行规则：';
        $lines[] = '- 只聚焦本次委托任务。';
        $lines[] = '- 优先处理列出的目标路径，避免无关探索。';
        $lines[] = '- 仅在必要时使用工具，建议最大工具调用次数：' . $maxToolCalls . '。';
        $lines[] = '- 不要调用 subagent_run，也不要再委托其它子代理。';
        $lines[] = '- 不要向用户提问。';
        $lines[] = '- 最终输出必须是 2~6 行简短、可直接使用的结论摘要。';

        return implode("\n", $lines);
    }

    /* ── 参数解析 ─────────────────────────────────────────────── */

    /** @return list<string> */
    private function parseTargetPaths($raw): array
    {
        $text = trim((string) ($raw ?? ''));
        if ($text === '') {
            return [];
        }

        $parsed = json_decode($text);
        if (!is_array($parsed) || count(array_filter($parsed, 'is_string')) !== count($parsed)) {
            throw new \InvalidArgumentException('target_paths_json 必须是 JSON 字符串数组');
        }

        $out = [];
        foreach ($parsed as $item) {
            $value = trim((string) $item);
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }

    private function parseMaxToolCalls($raw): int
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return 8;
        }

        $value = (int) $raw;
        if ($value <= 0) {
            return 8;
        }
        return min($value, self::MAX_TOOL_CALLS_CAP);
    }

    /* ── XML 协议（与 Operit subagent.ts 一致） ───────────────── */

    private function finalXml(string $runId, bool $success, int $toolCount, string $message): string
    {
        return '<subagent><final '
            . 'run="' . $this->escapeAttr($runId) . '" '
            . 'success="' . ($success ? 'true' : 'false') . '" '
            . 'tool_count="' . $toolCount . '">'
            . $this->escapeText($message)
            . '</final></subagent>';
    }

    private function createRunId(): string
    {
        $now = base_convert((string) (int) (microtime(true) * 1000), 10, 36);
        $now = substr($now, -6);
        $random = str_pad(base_convert((string) random_int(0, 1295), 10, 36), 2, '0', STR_PAD_LEFT);
        return 's' . $now . $random;
    }

    /* ── 文本处理（对齐 Operit 的 extractFinalNonToolAssistantContent） ── */

    /** 去思维链 + 去工具标记，取最后一段可用文本 */
    private function extractFinalContent(string $raw): string
    {
        $noThinking = trim((string) preg_replace(
            ['#<think(?:ing)?>[\s\S]*?(</think(?:ing)?>|\z)#i', '#<search>[\s\S]*?(</search>|\z)#i'],
            '',
            $raw
        ));

        $tail = $noThinking;
        if (preg_match_all('#(<tool\b[^>]*>[\s\S]*?</tool>)|(<tool_result\b[^>]*>[\s\S]*?</tool_result>)|(<tool\b[^>]*/>)|(<tool_result\b[^>]*/>)#i', $noThinking, $matches, PREG_OFFSET_CAPTURE)) {
            $last = end($matches[0]);
            if (is_array($last) && isset($last[0], $last[1])) {
                $tail = substr($noThinking, (int) $last[1] + strlen((string) $last[0]));
            }
        }

        $stripped = $this->stripMarkup($tail);
        if ($stripped !== '') {
            return $stripped;
        }

        $full = $this->stripMarkup($noThinking);
        if ($full === '') {
            return '';
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('#\n\s*\n+#', $full) ?: []), static fn ($v) => $v !== ''));
        return $parts !== [] ? (string) end($parts) : $full;
    }

    private function stripMarkup(string $text): string
    {
        $clean = preg_replace(
            [
                '#<tool\b[\s\S]*?</tool>#i',
                '#<tool\b[^>]*/>#i',
                '#<tool_result\b[\s\S]*?</tool_result>#i',
                '#<tool_result\b[^>]*/>#i',
                '#<status\b[\s\S]*?</status>#i',
                '#<status\b[^>]*/>#i',
            ],
            '',
            $text
        );
        return trim((string) $clean);
    }

    /** 归一化空白后裁剪（对齐 Operit 的 clipText） */
    private function clip(string $value, int $maxLength): string
    {
        $text = trim((string) preg_replace('#\s+#u', ' ', $value));
        if ($text === '' || mb_strlen($text) <= $maxLength) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, max(0, $maxLength - 3))) . '...';
    }

    private function escapeText(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }

    private function escapeAttr(string $value): string
    {
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#39;'], $value);
    }

    private function safeFinish(string $runId, bool $ok, int $toolCount, string $summary, string $error, int $durationMs): void
    {
        try {
            $this->repository->finish($runId, $ok, $toolCount, $summary, $error, $durationMs);
        } catch (\Throwable $e) {
            Logger::warn('子代理运行记录收口失败', ['run_id' => $runId, 'error' => $e->getMessage()]);
        }
    }
}
