<?php
/**
 * 对外网关路由（OpenAI 兼容层）
 * 由 Application::registerRoutes() 自动加载
 *
 * @var \App\Core\Router $router
 */

declare(strict_types=1);

use App\Controllers\AdminConsoleController;
use App\Controllers\AuthCallbackController;
use App\Controllers\ConsoleController;
use App\Controllers\GatewayController;
use App\Middleware\CorsMiddleware;
use App\Middleware\GatewayAuthMiddleware;
use App\Middleware\RateLimitMiddleware;

// 站点首页（营销落地页）
$router->get('/', ConsoleController::class . '@home');

// 用户端控制台入口（前端 SPA 外壳 —— 账号端）
$router->get('/console', ConsoleController::class . '@index');

// 管理后台入口（前端 SPA 外壳 —— 独立于用户端）
$router->get('/admin', AdminConsoleController::class . '@index');

// 健康检查：无需鉴权，便于探活
$router->get('/v1/health', 'App\Controllers\GatewayController@health', [CorsMiddleware::class]);

// 公开状态页数据源（免鉴权，只含聚合概览；开关 status_page_enabled）
$router->get('/v1/status', GatewayController::class . '@statusSummary', [CorsMiddleware::class]);

// 公开状态页本体（仿首页设计语言，10s 轮询 /v1/status）
$router->get('/status', ConsoleController::class . '@status');

// 需要 Bearer 鉴权的对外接口
$router->group('', [CorsMiddleware::class, GatewayAuthMiddleware::class, RateLimitMiddleware::class], function ($router) {
    $router->post('/v1/chat/completions', GatewayController::class . '@chatCompletions');
    $router->post('/v1/responses', GatewayController::class . '@responses');
    // Responses 生命周期端点（store / background 的可检索与可删除能力）
    $router->get('/v1/responses/{responseId}', GatewayController::class . '@responseRetrieve');
    $router->delete('/v1/responses/{responseId}', GatewayController::class . '@responseDelete');
    $router->post('/v1/responses/{responseId}/cancel', GatewayController::class . '@responseCancel');
    $router->get('/v1/models', GatewayController::class . '@models');
});

// 兼容部分客户端会探测根路径
$router->get('/v1', 'App\Controllers\GatewayController@health', [CorsMiddleware::class]);

// OAuth / WebView 回调落地（无需鉴权，由上游重定向过来）
$router->get('/authorize', AuthCallbackController::class . '@handle');

// Antigravity 的 Google 回环回调路径（http://localhost:51121/oauth-callback），
// 由本机 51121 桥接原样转发到此路由，复用同一回调处理器。
$router->get('/oauth-callback', AuthCallbackController::class . '@handle');