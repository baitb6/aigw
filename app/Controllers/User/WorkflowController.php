<?php
/**
 * 用户端工作流控制器（/api/user/workflows/*）
 *
 * 参考 Operit 的 Workflow 管理：列出 / 新增 / 修改 / 删除 / 手动执行 /
 * 查看执行记录。调度由服务端 cron（bin/workflow-worker.php）驱动。
 *
 * 属主隔离：全部经 Tenant::scope()。
 */

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\WorkflowRepository;
use App\Services\WorkflowService;
use App\Support\Tenant;

class WorkflowController
{
    public function __construct(
        private WorkflowRepository $repository,
        private WorkflowService $service,
    ) {}

    /** GET /api/user/workflows —— 列表 */
    public function index(Request $request): Response
    {
        $this->repository->ensureSchema();

        return Response::json([
            'ok'   => true,
            'data' => ['items' => $this->repository->list(Tenant::scope())],
        ]);
    }

    /** POST /api/user/workflows —— 新增 */
    public function store(Request $request): Response
    {
        $payload = $request->json();
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            return $this->fail('名称不能为空');
        }

        $graph = is_array($payload['graph'] ?? null) ? $payload['graph'] : ['nodes' => [], 'edges' => []];
        $check = $this->service->validateGraph($graph);
        if (!$check['ok']) {
            return $this->fail($check['message']);
        }

        $data = $this->extract($payload);
        $data['user_id'] = Tenant::writeId();

        // 计算首次调度时间
        $data['next_run_at'] = $this->service->computeNextRun($data, now_ms());

        $id = $this->repository->create($data);

        return Response::json([
            'ok'   => true,
            'data' => ['workflow' => $this->repository->findForUser($id, Tenant::scope())],
        ]);
    }

    /** PUT /api/user/workflows/{id} —— 修改 */
    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $scope = Tenant::scope();
        $workflow = $this->repository->findForUser($id, $scope);

        if ($workflow === null) {
            return $this->fail('工作流不存在', 404);
        }

        $payload = $request->json();

        if (is_array($payload['graph'] ?? null)) {
            $check = $this->service->validateGraph($payload['graph']);
            if (!$check['ok']) {
                return $this->fail($check['message']);
            }
        }

        $data = $this->extract($payload);

        // 调度变更 → 重算 next_run_at
        if (isset($data['schedule']) || isset($data['interval']) || isset($data['cron']) || isset($data['specific_at'])) {
            $merged = array_merge($workflow, $data);
            $data['next_run_at'] = $this->service->computeNextRun($merged, now_ms());
        }

        $this->repository->update($id, $data, Tenant::scope());

        return Response::json([
            'ok'   => true,
            'data' => ['workflow' => $this->repository->findForUser($id, $scope)],
        ]);
    }

    /** DELETE /api/user/workflows/{id} */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        if ($this->repository->findForUser($id, Tenant::scope()) === null) {
            return $this->fail('工作流不存在', 404);
        }

        $this->repository->delete($id, Tenant::scope());

        return Response::json(['ok' => true, 'message' => '已删除']);
    }

    /**
     * POST /api/user/workflows/{id}/run —— 手动执行
     *
     * 图可能耗时较长；这里同步执行并返回结果（节点数有上限保护）。
     * 若将来单次执行可能超过 CDN 回源超时，可仿照 action_task 改为异步。
     */
    public function run(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $scope = Tenant::scope();
        $workflow = $this->repository->findForUser($id, $scope);

        if ($workflow === null) {
            return $this->fail('工作流不存在', 404);
        }

        $task = $this->repository->createTask($id, Tenant::writeId(), 'manual');
        $this->repository->claimTask($task['task_id']);

        $result = $this->service->execute($workflow, $scope);

        $this->repository->finishTask($task['task_id'], $result['ok'], $result['message'], $result['steps']);
        $this->repository->markRun($id, now_ms(), (int) $workflow['next_run_at'], $result['ok'], $result['message']);

        return Response::json([
            'ok'      => $result['ok'],
            'message' => $result['message'],
            'data'    => [
                'task_id' => $task['task_id'],
                'steps'   => $result['steps'],
            ],
        ]);
    }

    /** GET /api/user/workflows/{id}/tasks —— 执行记录 */
    public function tasks(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        if ($this->repository->findForUser($id, Tenant::scope()) === null) {
            return $this->fail('工作流不存在', 404);
        }

        return Response::json([
            'ok'   => true,
            'data' => ['items' => $this->repository->listTasks($id, Tenant::scope())],
        ]);
    }

    /** POST /api/user/workflows/validate —— 图校验（编辑时即时校验） */
    public function validate(Request $request): Response
    {
        $payload = $request->json();
        $graph = is_array($payload['graph'] ?? null) ? $payload['graph'] : [];

        return Response::json([
            'ok'   => true,
            'data' => $this->service->validateGraph($graph),
        ]);
    }

    private function extract(array $payload): array
    {
        $data = [];
        foreach (['name', 'description', 'schedule', 'cron'] as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = (string) $payload[$key];
            }
        }
        if (is_array($payload['graph'] ?? null)) {
            $data['graph'] = $payload['graph'];
        }
        foreach (['interval', 'specific_at'] as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = (int) $payload[$key];
            }
        }
        if (array_key_exists('enabled', $payload)) {
            $data['enabled'] = !empty($payload['enabled']);
            if (!$data['enabled']) {
                $data['next_run_at'] = 0;
            }
        }

        return $data;
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
