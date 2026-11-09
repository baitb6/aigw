<?php
/**
 * 用户端内置工具控制器（/api/user/tools/*）
 *
 * 提供网关侧内置工具（时间/网页抓取/学术检索/联网检索）的清单与调用代理。
 * App 侧 Agent 循环把这张清单并入 function 列表，调用时经此转发到网关执行。
 */

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\GatewayToolsService;

class ToolsController
{
    public function __construct(private GatewayToolsService $service) {}

    /** GET /api/user/tools —— 内置工具清单（OpenAI function schema） */
    public function index(Request $request): Response
    {
        return Response::json([
            'ok'   => true,
            'data' => ['tools' => $this->service->schemas()],
        ]);
    }

    /**
     * POST /api/user/tools/invoke —— 执行一个内置工具。
     *
     * 入参：`{name: "get_time", arguments: {...}}`
     */
    public function invoke(Request $request): Response
    {
        $payload = $request->json();
        $name = trim((string) ($payload['name'] ?? ''));
        $arguments = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];

        if ($name === '') {
            return $this->fail('name 不能为空');
        }
        if (!in_array($name, $this->service->names(), true)) {
            return $this->fail("未知的内置工具：{$name}", 404);
        }

        $result = $this->service->invoke($name, $arguments);

        return Response::json([
            'ok'      => !$result['is_error'],
            'message' => $result['result'],
            'data'    => ['result' => $result['result'], 'is_error' => $result['is_error']],
        ], $result['is_error'] ? 502 : 200);
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
