<?php
/**
 * 自研路由器：支持 {param} 占位、分组前缀、中间件管道
 */

declare(strict_types=1);

namespace App\Core;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Support\Tenant;

class Router
{
    /** @var array<int, array{method:string, regex:string, params:string[], handler:mixed, middleware:string[]}> */
    private array $routes = [];

    /**
     * 性能优化：分桶索引，避免每请求对全部 ~250 条路由逐条 preg_match。
     *  - staticMap[method][path]：无占位符的路由，O(1) 精确命中；
     *  - dynamic[method][]：含 {param} 的路由，先 strncmp 静态前缀再上正则。
     * 原 $routes 仍保留，供 405/OPTIONS 兜底的全表扫描使用（冷门路径）。
     *
     * @var array<string, array<string, array>>
     */
    private array $staticMap = [];

    /** @var array<string, array<int, array>> */
    private array $dynamic = [];

    /** @var string[] 当前分组中间件 */
    private array $groupMiddleware = [];

    private string $groupPrefix = '';

    public function get(string $path, $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    public function any(string $path, $handler, array $middleware = []): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            $this->add($method, $path, $handler, $middleware);
        }
    }

    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $prevPrefix = $this->groupPrefix;
        $prevMiddleware = $this->groupMiddleware;

        $this->groupPrefix = $prevPrefix . $prefix;
        $this->groupMiddleware = array_merge($prevMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix = $prevPrefix;
        $this->groupMiddleware = $prevMiddleware;
    }

    private function add(string $method, string $path, $handler, array $middleware): void
    {
        $full = $this->groupPrefix . $path;
        $params = [];

        // /api/accounts/{id} → #^/api/accounts/([^/]+)$#
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $full);

        $route = [
            'method'     => $method,
            'regex'      => '#^' . $regex . '$#',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
            // 静态前缀：第一个 { 之前的字面量，用于动态路由的 strncmp 预筛
            'prefix'     => ($pos = strpos($full, '{')) === false ? $full : substr($full, 0, $pos),
        ];

        $this->routes[] = $route;
        if ($params === []) {
            $this->staticMap[$method][$full] = $route;
        } else {
            $this->dynamic[$method][] = $route;
        }
    }

    public function dispatch(Request $request, Container $container): Response
    {
        $method = $request->method();
        $path = $request->path();

        // ① 静态路由 O(1) 命中（绝大多数请求在这里结束）
        $route = $this->staticMap[$method][$path] ?? null;
        if ($route !== null) {
            $request->setRouteParams([]);
            return $this->runPipeline($route['middleware'], $request, $container, $route['handler']);
        }

        // ② 动态路由：同 method 分桶 + 静态前缀预筛，只有前缀命中才上正则
        foreach ($this->dynamic[$method] ?? [] as $route) {
            if (!str_starts_with($path, $route['prefix'])) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            array_shift($matches);
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = $matches[$i] ?? null;
            }
            $request->setRouteParams($params);

            return $this->runPipeline($route['middleware'], $request, $container, $route['handler']);
        }

        // ③ 兜底（405 / OPTIONS 预检）：冷门路径，保持原有的全表扫描语义
        $pathMatched = false;
        $optionsFallback = null;

        foreach ($this->routes as $route) {
            if (!str_starts_with($path, $route['prefix'])
                || !preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $pathMatched = true;

            if ($route['method'] !== $method) {
                // CORS 预检：记下路径命中的第一个路由，稍后用它的中间件链响应 OPTIONS。
                if ($method === 'OPTIONS' && $optionsFallback === null) {
                    $optionsFallback = $route;
                }
                continue;
            }

            // 正常应已在 ①② 命中；走到这里说明同 method 的正则判定与分桶不一致，按原逻辑放行
            array_shift($matches);
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = $matches[$i] ?? null;
            }
            $request->setRouteParams($params);

            return $this->runPipeline($route['middleware'], $request, $container, $route['handler']);
        }

        // 预检请求：只要路径存在就交给 CORS 中间件返回 204，不进入鉴权，
        // 也不再把 OPTIONS 误判为 405。
        if ($request->method() === 'OPTIONS' && $optionsFallback !== null) {
            return $this->runPipeline(
                $optionsFallback['middleware'],
                $request,
                $container,
                static fn (Request $req): Response => Response::noContent()
            );
        }

        if ($pathMatched) {
            return Response::json([
                'error' => ['message' => '方法不被允许：' . $request->method(), 'type' => 'invalid_request_error'],
            ], 405);
        }

        // SPA 兜底：浏览器直接刷新 /providers、/dashboard 等前端路由时，
        // 回吐控制台外壳（前端 hash 路由会接管），而不是纯文本 404。
        // 仅对非接口前缀的 GET 生效，接口路径仍保持严格的 404。
        if ($request->method() === 'GET' && !$this->isApiPath($request->path())) {
            return $this->spaShell($request->path());
        }

        return new Response('未知路径：' . $request->path(), 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** 是否为后端接口路径（这些路径不参与 SPA 兜底） */
    private function isApiPath(string $path): bool
    {
        foreach (['/api/', '/v1/', '/authorize', '/oauth-callback'] as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 输出前端 SPA 外壳。
     * 管理后台（/admin、/admin/*）回吐 admin.html，其余回吐用户端 index.html。
     */
    private function spaShell(string $path = ''): Response
    {
        $isAdmin = ($path === '/admin' || str_starts_with($path, '/admin/'));
        $file = AIGW_ROOT . '/public/' . ($isAdmin ? 'admin.html' : 'index.html');

        if (!is_file($file)) {
            return new Response('控制台入口文件缺失', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return (new Response((string) file_get_contents($file), 200, ['Content-Type' => 'text/html; charset=utf-8']))
            ->withHeader('Cache-Control', 'no-cache, must-revalidate');
    }

    /**
     * 中间件洋葱模型：middleware -> handler -> 逐层回包
     */
    private function runPipeline(array $middleware, Request $request, Container $container, $handler): Response
    {
        Tenant::reset();

        $next = function (Request $req) use ($container, $handler): Response {
            if (is_callable($handler)) {
                return $container->call($handler, [$req, $container]);
            }

            // "Controller@method" 形式
            if (is_string($handler) && str_contains($handler, '@')) {
                [$class, $method] = explode('@', $handler, 2);
                $controller = $container->make($class);
                return $container->call([$controller, $method], [$req, $container]);
            }

            throw new \RuntimeException('无法解析的路由处理器');
        };

        foreach (array_reverse($middleware) as $mw) {
            $nextClass = $mw;
            $inner = $next;
            $next = function (Request $req) use ($container, $nextClass, $inner): Response {
                $instance = $container->make($nextClass);
                return $instance->handle($req, $inner);
            };
        }

        return $next($request);
    }
}