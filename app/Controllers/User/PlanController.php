<?php
/**
 * 用户端计划模式控制器（/api/user/plan/*）
 *
 * 参考 Operit 的 Plan Mode：生成计划 → 用户确认 → 实施 → 完成。
 * 计划按「用户 + 会话」隔离，客户端以 session 参数区分不同对话。
 */

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\PlanService;
use App\Support\Tenant;

class PlanController
{
    public function __construct(private PlanService $service) {}

    /** GET /api/user/plan?session=xxx —— 查询当前计划 */
    public function show(Request $request): Response
    {
        $session = $this->session($request);
        $plan = $this->service->current(Tenant::scope(), $session);

        return Response::json([
            'ok'   => true,
            'data' => [
                'plan'   => $plan,
                'prompt' => $this->service->toPromptBlock($plan),
                'blocked_tools' => PlanService::BLOCKED_TOOLS,
            ],
        ]);
    }

    /** POST /api/user/plan —— 直接保存一份计划（用户手写或从 AI 回复摘录） */
    public function save(Request $request): Response
    {
        $payload = $request->json();
        $content = trim((string) ($payload['content'] ?? ''));
        if ($content === '') {
            return $this->fail('计划内容不能为空');
        }

        $plan = $this->service->save(
            Tenant::scope(),
            $this->session($request),
            (string) ($payload['title'] ?? '实施计划'),
            $content,
            ($payload['source'] ?? 'manual') === 'ai' ? 'ai' : 'manual'
        );

        return Response::json(['ok' => true, 'data' => ['plan' => $plan]]);
    }

    /** POST /api/user/plan/generate —— 用 AI 生成计划 */
    public function generate(Request $request): Response
    {
        $payload = $request->json();
        $requirement = trim((string) ($payload['requirement'] ?? ''));
        if ($requirement === '') {
            return $this->fail('需求描述不能为空');
        }

        try {
            $content = $this->service->generate($requirement, (string) ($payload['model'] ?? ''));
        } catch (\Throwable $e) {
            return $this->fail('计划生成失败：' . $e->getMessage(), 502);
        }

        if ($content === '') {
            return $this->fail('模型未返回计划内容', 502);
        }

        // 默认落库（便于客户端直接展示与确认）
        if (!empty($payload['save'])) {
            $plan = $this->service->save(
                Tenant::scope(),
                $this->session($request),
                (string) ($payload['title'] ?? 'AI 生成的计划'),
                $content,
                'ai'
            );
            return Response::json(['ok' => true, 'data' => ['content' => $content, 'plan' => $plan]]);
        }

        return Response::json(['ok' => true, 'data' => ['content' => $content, 'plan' => null]]);
    }

    /** POST /api/user/plan/confirm —— 确认执行 */
    public function confirm(Request $request): Response
    {
        $payload = $request->json();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            return $this->fail('id 不能为空');
        }

        $plan = $this->service->confirm($id, Tenant::scope());
        if ($plan === null) {
            return $this->fail('计划不存在', 404);
        }

        return Response::json([
            'ok'   => true,
            'data' => ['plan' => $plan, 'prompt' => $this->service->toPromptBlock($plan)],
        ]);
    }

    /** DELETE /api/user/plan?session=xxx —— 完成 / 清除计划 */
    public function destroy(Request $request): Response
    {
        $count = $this->service->clear(Tenant::scope(), $this->session($request));

        return Response::json(['ok' => true, 'message' => "已清除 {$count} 份计划"]);
    }

    private function session(Request $request): string
    {
        $session = trim((string) ($request->query('session') ?? $request->json()['session'] ?? ''));
        return mb_substr($session === '' ? 'default' : $session, 0, 80);
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
