<?php
/**
 * 跨域与预检中间件
 * 网关接口（/v1）允许浏览器直连调试，管理台接口限定同源
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;

class CorsMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $origin = $request->header('origin');

        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept, X-Requested-With',
            'Access-Control-Max-Age'       => '86400',
        ];

        // 同源请求（无 Origin）与白名单来源才回 CORS 头；
        // 未命中白名单一律不下发 Access-Control-Allow-Origin，浏览器即拒绝跨域读取。
        $allowed = $this->resolveAllowedOrigin($origin);
        if ($allowed !== null) {
            $headers['Access-Control-Allow-Origin'] = $allowed;
            $headers['Vary'] = 'Origin';
        }

        if ($request->method() === 'OPTIONS') {
            // 预检直接放行（由路由层决定路径是否存在），不进入鉴权链；
            // 非预检请求正常走后续中间件。
            $response = Response::noContent();
        } else {
            $response = $next($request);
        }

        foreach ($headers as $name => $value) {
            $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * 允许的来源：同源（与 app.url 一致）+ config('app.cors_origins') 白名单。
     * 返回 null 表示不下发 CORS 头。
     */
    private function resolveAllowedOrigin(?string $origin): ?string
    {
        if ($origin === null || trim($origin) === '') {
            return null;
        }

        $origin = rtrim(trim($origin), '/');
        $selfHost = rtrim((string) config('app.url', ''), '/');
        if ($selfHost !== '' && strcasecmp($origin, $selfHost) === 0) {
            return $origin;
        }

        foreach ((array) config('app.cors_origins', []) as $candidate) {
            $candidate = rtrim(trim((string) $candidate), '/');
            if ($candidate !== '' && strcasecmp($origin, $candidate) === 0) {
                return $origin;
            }
        }

        return null;
    }
}