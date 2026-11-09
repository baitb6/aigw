<?php
/**
 * 网关对外限流中间件
 *
 * 必须挂在 GatewayAuthMiddleware 之后：鉴权通过后才有限流的意义，
 * 且此时 Tenant 作用域已确定，便于按 Key/IP 维度收敛。
 *
 * 开关语义（由管理台「网关设置」控制）：
 *   - rate_limit_enabled = false → 完全跳过（默认关闭，不影响存量部署）
 *   - rate_limit_scope = key     → 按调用方 API Key 分桶（免 Key 模式退化为按 IP）
 *   - rate_limit_scope = ip      → 按客户端真实 IP 分桶
 *   - rate_limit_rpm / rate_limit_burst → 令牌桶速率与突发容量
 *
 * 被限流时返回 OpenAI 风格 429，并带 Retry-After / X-RateLimit-* 头，
 * 方便客户端做自适应退避。
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\GatewayRepository;
use App\Services\GatewayService;
use App\Services\RateLimiter;
use App\Support\ClientIp;

class RateLimitMiddleware
{
    public function __construct(
        private GatewayRepository $gatewayRepository,
        private RateLimiter $rateLimiter
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $settings = $this->gatewayRepository->current();

        // 限流开关关闭 → 直接放行
        if (!(bool) ($settings['rate_limit_enabled'] ?? false)) {
            return $next($request);
        }

        $rpm   = (int) ($settings['rate_limit_rpm'] ?? 60);
        $burst = (int) ($settings['rate_limit_burst'] ?? 10);
        $scope = (string) ($settings['rate_limit_scope'] ?? 'key');

        $bucket = $this->resolveBucket($request, $scope);
        $result = $this->rateLimiter->hit($bucket, $rpm, $burst);

        if (!(bool) $result['allowed']) {
            $retryAfter = max(1, (int) $result['retry_after']);

            return Response::json(
                GatewayService::errorPayload(
                    sprintf('请求过于频繁，请 %d 秒后重试（限流：%s）。', $retryAfter, $rpm . ' 次/分钟'),
                    'rate_limit_error',
                    'rate_limit_exceeded'
                ),
                429,
                [
                    'Retry-After'           => (string) $retryAfter,
                    'X-RateLimit-Limit'     => (string) $result['limit'],
                    'X-RateLimit-Remaining' => '0',
                ]
            );
        }

        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $result['limit'])
            ->withHeader('X-RateLimit-Remaining', (string) max(0, (int) $result['remaining']));
    }

    /**
     * 计算限流桶标识
     *
     * - scope=ip：直接用客户端真实 IP
     * - scope=key：优先按 API Key 哈希；免 Key 模式（无 Bearer）退化为按 IP，
     *   否则所有免 Key 调用会挤在同一个桶里互相拖累。
     */
    private function resolveBucket(Request $request, string $scope): string
    {
        if ($scope === 'ip') {
            return 'ip:' . ClientIp::resolve($request);
        }

        $token = $request->bearerToken();
        if ($token !== null && trim($token) !== '') {
            return 'key:' . hash('sha256', trim((string) $token));
        }

        return 'ip:' . ClientIp::resolve($request);
    }
}
