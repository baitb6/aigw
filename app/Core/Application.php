<?php
/**
 * 应用内核：装配容器、加载路由、全局异常兜底
 */

declare(strict_types=1);

namespace App\Core;

use App\Core\Http\Request;
use App\Core\Http\Response;
use Throwable;

class Application
{
    private string $rootPath;
    private Container $container;
    private Router $router;

    public function __construct(string $rootPath)
    {
        $this->rootPath  = $rootPath;
        $this->container = new Container();
        $this->router    = new Router();

        Config::load($rootPath . '/config');

        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(Application::class, $this);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function run(): void
    {
        $request = new Request();

        try {
            $this->registerRoutes();

            $response = $this->router->dispatch($request, $this->container);
        } catch (Throwable $e) {
            $response = $this->renderException($e, $request);
        }

        $response->send();
    }

    private function registerRoutes(): void
    {
        $routes = $this->rootPath . '/routes';
        foreach (glob($routes . '/*.php') ?: [] as $file) {
            /** @var Router $router */
            $router = $this->router;
            /** @var Container $container */
            $container = $this->container;
            require $file;
        }
    }

    /** 测试辅助：复用同一内核实例 */
    private static ?Application $testInstance = null;

    public static function bootForTest(string $rootPath): Application
    {
        return self::$testInstance ??= new Application($rootPath);
    }

    public static function testContainer(): Container
    {
        return self::bootForTest(defined('AIGW_ROOT') ? AIGW_ROOT : dirname(__DIR__, 2))->container();
    }

    public static function testRouter(): Router
    {
        return self::bootForTest(defined('AIGW_ROOT') ? AIGW_ROOT : dirname(__DIR__, 2))->router();
    }

    private function renderException(Throwable $e, Request $request): Response
    {
        \App\Support\Logger::error('未捕获异常：' . $e->getMessage(), [
            'file'  => $e->getFile() . ':' . $e->getLine(),
            'path'  => $request->path(),
            'trace' => explode("\n", $e->getTraceAsString())[0] ?? '',
        ]);

        // 非 debug 环境对外只给通用文案，避免泄漏路径 / SQL / 连接串等内部细节。
        $debug = (bool) config('app.debug', false);
        $message = $debug ? $e->getMessage() : '服务器内部错误，请稍后重试或查看服务端日志';

        if ($request->wantsJson()) {
            return Response::json([
                'error' => [
                    'message'   => $message,
                    'type'      => 'api_error',
                    'code'      => 'internal_error',
                    'api_error' => true,
                ],
            ], 500);
        }

        return Response::html(
            '<pre style="font:14px/1.6 monospace;padding:32px">服务器内部错误：'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</pre>',
            500
        );
    }
}