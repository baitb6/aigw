<?php
/**
 * 用户端深度研究控制器（/api/user/research/*）
 *
 * 参考 Operit 的 DeepSearch：把「规划 → 分层执行 → 汇总」这套多轮研究
 * 做成服务端异步任务 —— 提交立即返回 task_id，后台 worker 执行，前端轮询。
 * 这样既绕开 CDN 回源超时（524），又能让研究过程可持续数分钟。
 */

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\ResearchService;
use App\Support\BackgroundProcess;
use App\Support\Tenant;

class ResearchController
{
    public function __construct(private ResearchService $service) {}

    /** POST /api/user/research —— 提交研究任务，立即返回 task_id */
    public function start(Request $request): Response
    {
        $payload = $request->json();
        $topic = trim((string) ($payload['topic'] ?? ''));
        if ($topic === '') {
            return $this->fail('研究主题不能为空');
        }
        if (mb_strlen($topic) > 500) {
            return $this->fail('研究主题过长（最多 500 字）');
        }

        $created = $this->service->submit(Tenant::scope(), $topic);
        $taskId = (string) $created['task_id'];

        // 拉起后台 worker；起不来也不让请求失败——任务仍可通过 cron 兜底
        $started = BackgroundProcess::startResearchWorker($taskId);

        return Response::json([
            'ok'   => true,
            'data' => [
                'task_id' => $taskId,
                'started' => $started,
            ],
        ]);
    }

    /** GET /api/user/research/{taskId} —— 查询任务进度 / 结果 */
    public function status(Request $request): Response
    {
        $taskId = (string) ($request->routeParam('taskId') ?? '');
        if ($taskId === '') {
            return $this->fail('taskId 不能为空');
        }

        $task = $this->service->status($taskId, Tenant::scope());
        if ($task === null) {
            return $this->fail('研究任务不存在', 404);
        }

        return Response::json(['ok' => true, 'data' => ['task' => $task]]);
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
