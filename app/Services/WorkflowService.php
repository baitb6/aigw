<?php
/**
 * 工作流执行引擎
 *
 * 参考 Operit 的 WorkflowExecutor / WorkflowScheduler：
 *   - 节点类型：trigger / execute（调工具或模型）/ condition（条件分支）/ logic（逻辑运算）/ extract（字段抽取）
 *   - 图校验：DFS 环检测 + 拓扑排序（拒绝有环图）
 *   - 调度：manual / interval / cron / specific_time，水位 next_run_at 由 worker 扫描
 *
 * 与 Operit 的差异：Operit 用 Android WorkManager 保活；本实现把调度放到
 * **服务端 cron**（bin/workflow-worker.php），更可靠且精度更高（不受 15 分钟下限约束）。
 */

declare(strict_types=1);

namespace App\Services;

use App\Provider\Dto\ChatRequest;
use App\Repositories\WorkflowRepository;
use App\Support\Logger;

class WorkflowService
{
    /** 单次执行最多节点数（防超长图） */
    private const MAX_STEPS = 50;

    public function __construct(
        private WorkflowRepository $repository,
        private GatewayService $gateway,
        private McpService $mcp,
        private \App\Repositories\McpServerRepository $mcpRepository,
    ) {}

    /**
     * 图校验：环检测 + 拓扑排序。
     *
     * @return array{ok:bool,message:string,order:list<string>}
     */
    public function validateGraph(array $graph): array
    {
        $nodes = $graph['nodes'] ?? [];
        $edges = $graph['edges'] ?? [];

        if (!is_array($nodes) || $nodes === []) {
            return ['ok' => false, 'message' => '工作流没有节点', 'order' => []];
        }

        $ids = [];
        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');
            if ($id === '') {
                return ['ok' => false, 'message' => '存在缺少 id 的节点', 'order' => []];
            }
            $ids[$id] = true;
        }

        // 邻接表 + 入度
        $adj = [];
        $indeg = [];
        foreach ($ids as $id => $_) {
            $adj[$id] = [];
            $indeg[$id] = 0;
        }

        foreach ($edges as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            if (!isset($ids[$from]) || !isset($ids[$to])) {
                continue;
            }
            $adj[$from][] = $to;
            $indeg[$to]++;
        }

        // Kahn 拓扑排序（同时完成环检测）
        $queue = [];
        foreach ($indeg as $id => $deg) {
            if ($deg === 0) {
                $queue[] = $id;
            }
        }

        $order = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $order[] = $current;
            foreach ($adj[$current] as $next) {
                $indeg[$next]--;
                if ($indeg[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        if (count($order) !== count($ids)) {
            return ['ok' => false, 'message' => '工作流存在环，无法确定执行顺序', 'order' => []];
        }

        return ['ok' => true, 'message' => '', 'order' => $order];
    }

    /**
     * 执行工作流（按拓扑序逐节点）。
     *
     * 变量上下文 $ctx：节点可用 `{{ 变量名 }}` 引用上游输出（对应 Operit 的 Extract 节点语义）。
     *
     * @return array{ok:bool,message:string,steps:array}
     */
    public function execute(array $workflow, ?int $userId): array
    {
        $graph = $workflow['graph'] ?? [];
        $check = $this->validateGraph($graph);
        if (!$check['ok']) {
            return ['ok' => false, 'message' => $check['message'], 'steps' => []];
        }

        $nodes = [];
        foreach ($graph['nodes'] as $node) {
            $nodes[(string) $node['id']] = $node;
        }

        // 被依赖的节点集合（有入边的节点）—— 只有这些节点的输出会流入后续节点
        $ctx = [];
        $steps = [];
        $ok = true;
        $message = '';

        foreach ($check['order'] as $nodeId) {
            if (count($steps) >= self::MAX_STEPS) {
                $message = '节点数超过上限';
                $ok = false;
                break;
            }

            $node = $nodes[$nodeId];
            $type = (string) ($node['type'] ?? 'execute');

            try {
                $output = $this->runNode($type, $node, $ctx, $userId);
                $ctx[$nodeId] = $output;
                $steps[] = ['id' => $nodeId, 'type' => $type, 'ok' => true, 'output' => $this->snippet($output)];
            } catch (\Throwable $e) {
                $steps[] = ['id' => $nodeId, 'type' => $type, 'ok' => false, 'message' => $e->getMessage()];
                $ok = false;
                $message = "节点 {$nodeId} 执行失败：" . $e->getMessage();
                break; // 单节点失败即中止（对应 Operit 的短路语义）
            }
        }

        if ($ok && $message === '') {
            $message = '执行完成，共 ' . count($steps) . ' 个节点';
        }

        return ['ok' => $ok, 'message' => $message, 'steps' => $steps];
    }

    /**
     * 执行单个节点。
     *
     * 节点结构约定（简化自 Operit）：
     *   trigger  : {type, id}
     *   execute  : {type, id, action:'llm'|'mcp', model?, prompt?, server?, tool?, args?}
     *   condition: {type, id, left, op:'eq'|'ne'|'contains'|'gt'|'lt', right}
     *   logic    : {type, id, op:'and'|'or'|'not', operands:[...]}
     *   extract  : {type, id, from:<nodeId>, path:'a.b'}
     */
    private function runNode(string $type, array $node, array $ctx, ?int $userId): string
    {
        switch ($type) {
            case 'trigger':
                return 'triggered';

            case 'condition':
                return $this->evalCondition($node, $ctx) ? 'true' : 'false';

            case 'logic':
                return $this->evalLogic($node, $ctx) ? 'true' : 'false';

            case 'extract':
                return $this->extract($node, $ctx);

            case 'execute':
            default:
                return $this->executeAction($node, $ctx, $userId);
        }
    }

    /** execute：调用 LLM 或 MCP 工具 */
    private function executeAction(array $node, array $ctx, ?int $userId): string
    {
        $action = (string) ($node['action'] ?? 'llm');

        if ($action === 'mcp') {
            $serverName = (string) ($node['server'] ?? '');
            $tool = (string) ($node['tool'] ?? '');
            $args = (array) ($node['args'] ?? []);

            $server = $this->findServerByName($serverName, $userId);
            if ($server === null) {
                throw new \RuntimeException("未找到 MCP 服务：{$serverName}");
            }
            $args = $this->interpolateArray($args, $ctx);
            $result = $this->mcp->callTool($server, $tool, $args);

            if ($result['is_error']) {
                throw new \RuntimeException($result['result']);
            }

            return $result['result'];
        }

        // 默认走 LLM
        $prompt = $this->interpolate((string) ($node['prompt'] ?? ''), $ctx);
        if (trim($prompt) === '') {
            throw new \RuntimeException('execute 节点缺少 prompt');
        }

        $model = (string) ($node['model'] ?? '');
        if ($model === '') {
            $model = $this->pickModel();
        }
        if ($model === '') {
            throw new \RuntimeException('没有可用模型');
        }

        $request = ChatRequest::fromArray([
            'model'    => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'stream'   => false,
        ]);

        $content = '';
        $this->gateway->complete($request, function (string $delta) use (&$content): void {
            $content .= $delta;
        });

        return $content;
    }

    /** condition：比较运算 */
    private function evalCondition(array $node, array $ctx): bool
    {
        $left = $this->interpolate((string) ($node['left'] ?? ''), $ctx);
        $right = $this->interpolate((string) ($node['right'] ?? ''), $ctx);
        $op = (string) ($node['op'] ?? 'eq');

        return match ($op) {
            'eq'       => $left === $right,
            'ne'       => $left !== $right,
            'contains' => $right !== '' && mb_strpos($left, $right) !== false,
            'gt'       => is_numeric($left) && is_numeric($right) && (float) $left > (float) $right,
            'lt'       => is_numeric($left) && is_numeric($right) && (float) $left < (float) $right,
            default    => false,
        };
    }

    /** logic：与/或/非 */
    private function evalLogic(array $node, array $ctx): bool
    {
        $op = (string) ($node['op'] ?? 'and');
        $operands = array_map(
            fn ($item) => $this->interpolate((string) $item, $ctx) === 'true',
            (array) ($node['operands'] ?? [])
        );

        return match ($op) {
            'or'  => in_array(true, $operands, true),
            'not' => !in_array(true, $operands, true),
            default => $operands !== [] && !in_array(false, $operands, true),
        };
    }

    /** extract：从上游输出里按点路径抽取 */
    private function extract(array $node, array $ctx): string
    {
        $from = (string) ($node['from'] ?? '');
        $path = (string) ($node['path'] ?? '');
        $source = $ctx[$from] ?? '';

        if ($path === '') {
            return $source;
        }

        $decoded = json_decode($source, true);
        if (!is_array($decoded)) {
            return $source;
        }

        $current = $decoded;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : (json_encode($current, JSON_UNESCAPED_UNICODE) ?: '');
    }

    // ── 调度 ───────────────────────────────────────────────────

    /** 计算下次执行时间（对齐 Operit 的 interval/cron/specific_time） */
    public function computeNextRun(array $workflow, int $fromMs): int
    {
        $schedule = (string) ($workflow['schedule'] ?? 'manual');

        return match ($schedule) {
            'interval' => $fromMs + max(WorkflowRepository::MIN_INTERVAL, (int) ($workflow['interval'] ?? 0)) * 1000,
            'specific_time' => (int) ($workflow['specific_at'] ?? 0),
            'cron' => $this->nextCronRun((string) ($workflow['cron'] ?? ''), $fromMs),
            default => 0,
        };
    }

    /**
     * 极简 cron 解析：支持 `分 时 日 月 周` 五段，`*` 与数字。
     * 复杂表达式（区间/步长/列表）暂不支持，返回 0 表示不调度。
     */
    private function nextCronRun(string $expr, int $fromMs): int
    {
        $fields = preg_split('/\s+/', trim($expr)) ?: [];
        if (count($fields) !== 5) {
            return 0;
        }

        [$minute, $hour] = $fields;

        $start = (int) ceil($fromMs / 60000) * 60000; // 对齐到分钟
        for ($i = 1; $i <= 1440 * 366; $i++) { // 最多向后找一年
            $candidate = $start + $i * 60000;
            $m = (int) date('i', (int) ($candidate / 1000));
            $h = (int) date('H', (int) ($candidate / 1000));

            if (($minute === '*' || (int) $minute === $m) && ($hour === '*' || (int) $hour === $h)) {
                return $candidate;
            }
        }

        return 0;
    }

    /** worker 扫描并执行到期工作流 */
    public function runDue(int $limit = 20): array
    {
        $now = now_ms();
        $dueList = $this->repository->due($now, $limit);
        $ran = [];
        $failed = 0;

        foreach ($dueList as $workflow) {
            $result = $this->execute($workflow, (int) ($workflow['user_id'] ?? 0));
            $next = $this->computeNextRun($workflow, $now);

            $this->repository->markRun(
                (int) $workflow['id'],
                $now,
                $next,
                $result['ok'],
                $result['message']
            );

            // 落一条执行记录便于排查
            $task = $this->repository->createTask((int) $workflow['id'], (int) ($workflow['user_id'] ?? 0), 'schedule');
            $this->repository->claimTask($task['task_id']);
            $this->repository->finishTask($task['task_id'], $result['ok'], $result['message'], $result['steps']);

            $ran[] = ['workflow_id' => (int) $workflow['id'], 'ok' => $result['ok']];
            if (!$result['ok']) {
                $failed++;
            }
        }

        Logger::info('工作流调度完成', ['count' => count($dueList), 'failed' => $failed]);

        return ['ran' => $ran, 'total' => count($dueList), 'failed' => $failed];
    }

    // ── 变量插值 ───────────────────────────────────────────────

    /** 把 `{{ nodeId }}` 替换为对应节点输出 */
    private function interpolate(string $text, array $ctx): string
    {
        if ($text === '' || !str_contains($text, '{{')) {
            return $text;
        }

        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_\-]+)\s*\}\}/', function (array $m) use ($ctx): string {
            return (string) ($ctx[$m[1]] ?? '');
        }, $text) ?? $text;
    }

    private function interpolateArray(array $input, array $ctx): array
    {
        $output = [];
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $output[$key] = $this->interpolateArray($value, $ctx);
            } elseif (is_string($value)) {
                $output[$key] = $this->interpolate($value, $ctx);
            } else {
                $output[$key] = $value;
            }
        }

        return $output;
    }

    private function snippet(string $text): string
    {
        return mb_strlen($text) > 500 ? mb_substr($text, 0, 500) . '…' : $text;
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

    /** 按名字找 MCP 服务（供 execute 节点调用） */
    private function findServerByName(string $name, ?int $userId): ?array
    {
        foreach ($this->mcpRepository->enabledForUser($userId) as $server) {
            if ((string) $server['name'] === $name) {
                return $server;
            }
        }

        return null;
    }
}
