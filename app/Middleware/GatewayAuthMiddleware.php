<?php
/**
 * 对外网关鉴权中间件：校验 Authorization: Bearer <apiKey>
 * 失败时返回 OpenAI 风格错误体
 *
 * 两类密钥：
 *  - sk-user-*  ：普通用户独立 API Key → 归属该用户，仅能调用自己的账号
 *  - 其他（sk-aigw-* / 免 Key 模式）：管理端全局密钥 → 不限制作用域（可见/可调全部账号）
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\GatewayRepository;
use App\Repositories\UserRepository;
use App\Services\GatewayService;
use App\Support\Tenant;

class GatewayAuthMiddleware
{
    public function __construct(
        private GatewayRepository $gatewayRepository,
        private UserRepository $userRepository
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();

        // 普通用户独立 API Key：解析归属用户并锁定数据作用域
        if ($this->userRepository->isUserKey($token)) {
            $user = $this->userRepository->findActiveByApiKey((string) $token);
            if ($user === null) {
                return $this->unauthorized('用户 API Key 无效或账号已被禁用。');
            }

            Tenant::setRequestScope((int) $user['id']);

            return $next($request);
        }

        // 管理端全局 API Key / 免 Key 模式：不限制作用域
        [$ok, $reason] = $this->gatewayRepository->verify($token);
        if (!$ok) {
            return $this->unauthorized((string) $reason);
        }

        Tenant::setRequestScope(null);

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return Response::json(
            GatewayService::errorPayload($message, 'invalid_request_error', 'invalid_api_key'),
            401
        );
    }
}
