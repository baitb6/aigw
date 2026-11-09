<?php
/**
 * 用户端子代理控制器（/api/user/subagent/*）
 *
 * 参考 Operit 的被动式 Subagent：主 agent 把具体任务委托给子代理，
 * 子代理在服务端跑一次隔离会话并回传 XML 协议文本（update/final）。
 */

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\SubagentRunRepository;
use App\Services\SubagentService;
use App\Support\Tenant;

class SubagentController
{
    /** 未显式指定模型时的兜底模型（网关解析失败则由 GatewayService 报错） */
    private const DEFAULT_MODEL = 'gpt-4o-mini';

    public function __construct(
        private SubagentService $service,
        private SubagentRunRepository $repository,
    ) {}

    /** POST /api/user/subagent/run —— 执行一次子代理委托任务 */
    public function run(Request $request): Response
    {
        $payload = $request->json();

        $task = trim((string) ($payload['task'] ?? ''));
        if ($task === '') {
            return $this->fail('task 不能为空');
        }

        $model = trim((string) ($payload['model'] ?? ''));
        if ($model === '') {
            $model = self::DEFAULT_MODEL;
        }

        $params = [
            'task'              => $task,
            'context_text'      => (string) ($payload['context_text'] ?? ''),
            'target_paths_json' => $payload['target_paths_json'] ?? null,
            'max_tool_calls'    => $payload['max_tool_calls'] ?? null,
            'allowed_tools'     => $payload['allowed_tools'] ?? null,
            'system_instruction' => mb_substr((string) ($payload['system_instruction'] ?? ''), 0, 4000),
        ];

        $xml = $this->service->run($params, $model, Tenant::scope());

        return Response::json(['ok' => true, 'data' => ['result' => $xml]]);
    }

    /** GET /api/user/subagent/runs —— 最近的运行记录列表 */
    public function index(Request $request): Response
    {
        // 钳制上限（审计 L-5）：超大 limit 会拖垮查询
        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        $runs = $this->repository->listForUser(Tenant::scope(), $limit);

        return Response::json(['ok' => true, 'data' => ['runs' => $runs]]);
    }

    /** GET /api/user/subagent/runs/{runId} —— 单次运行详情 */
    public function show(Request $request): Response
    {
        $runId = (string) ($request->routeParam('runId') ?? '');
        if ($runId === '') {
            return $this->fail('runId 不能为空');
        }

        $run = $this->repository->findForUser($runId, Tenant::scope());
        if ($run === null) {
            return $this->fail('运行记录不存在', 404);
        }

        return Response::json(['ok' => true, 'data' => ['run' => $run]]);
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
