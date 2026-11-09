<?php
/**
 * 用户端对话增强设置控制器（/api/user/chat-settings）
 *
 * 管理三项「请求前改写」能力的开关（参考 Operit）：
 *   · Context Limiter —— 上下文楼层裁剪
 *   · Thinking Guidance —— 思考引导（非推理模型）
 *   · WorldBook —— 世界书注入开关
 *
 * 设置按「用户 + 会话」隔离，客户端以 session 参数区分不同对话。
 */

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\ChatSettingRepository;
use App\Support\Tenant;

class ChatSettingController
{
    public function __construct(private ChatSettingRepository $repository) {}

    /** GET /api/user/chat-settings?session=xxx */
    public function show(Request $request): Response
    {
        $session = $this->session($request);

        return Response::json([
            'ok'   => true,
            'data' => [
                'setting' => $this->repository->get(Tenant::scope(), $session),
                'floor_options' => ChatSettingRepository::FLOOR_OPTIONS,
                'defaults' => ChatSettingRepository::DEFAULTS,
            ],
        ]);
    }

    /** POST /api/user/chat-settings —— 局部保存（只写传入的字段） */
    public function save(Request $request): Response
    {
        $payload = $request->json();

        // 只保留客户端显式传入的字段（false/0 是合法值，不能用 empty 过滤）
        $allowed = ['limiter_enabled', 'floor_limit', 'thinking_enabled', 'thinking_lang', 'worldbook_enabled'];
        $data = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = $payload[$key];
            }
        }

        $setting = $this->repository->save(Tenant::scope(), $this->session($request), $data);

        return Response::json(['ok' => true, 'data' => ['setting' => $setting]]);
    }

    private function session(Request $request): string
    {
        $session = trim((string) ($request->query('session') ?? $request->json()['session'] ?? ''));
        return mb_substr($session === '' ? 'default' : $session, 0, 80);
    }
}
