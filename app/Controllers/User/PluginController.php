<?php
/**
 * 用户端插件控制器（/api/user/plugins/*）
 *
 * 参考 Operit 的 ToolPkg 管理：安装（上传 manifest）/ 列表 / 启停 / 卸载 /
 * 查看声明的工具与提示词 / 校验 manifest。
 *
 * ⚠️ 属主隔离：所有操作经 Tenant::scope()。
 */

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\PluginRepository;
use App\Services\PluginService;
use App\Support\Tenant;

class PluginController
{
    /** 单次上传 manifest 体积上限（字节） */
    private const MAX_MANIFEST_BYTES = 262144;

    public function __construct(
        private PluginRepository $repository,
        private PluginService $service,
    ) {}

    /** GET /api/user/plugins —— 插件列表 */
    public function index(Request $request): Response
    {
        $scope = Tenant::scope();

        return Response::json([
            'ok'   => true,
            'data' => [
                'items' => array_map([$this->service, 'validatePlugin'], $this->repository->list($scope)),
                'count' => $this->repository->countForUser($scope),
                'limit' => PluginRepository::MAX_PER_USER,
            ],
        ]);
    }

    /**
     * POST /api/user/plugins —— 安装插件。
     *
     * 入参：`{manifest: {...}}` 或直接传 manifest 字段本身。
     */
    public function store(Request $request): Response
    {
        $payload = $request->json();
        $manifest = $payload['manifest'] ?? $payload;

        if (!is_array($manifest) || $manifest === []) {
            return $this->fail('manifest 不能为空');
        }
        if (strlen((string) json_encode($manifest)) > self::MAX_MANIFEST_BYTES) {
            return $this->fail('manifest 体积过大');
        }

        $scope = Tenant::scope();
        $result = $this->service->install($manifest, $scope, Tenant::writeId());

        if (!$result['ok']) {
            return $this->fail($result['error']);
        }

        return Response::json([
            'ok'   => true,
            'data' => ['plugin' => $result['plugin']],
        ]);
    }

    /**
     * POST /api/user/plugins/validate —— 仅校验 manifest，不落库。
     */
    public function validate(Request $request): Response
    {
        $payload = $request->json();
        $manifest = $payload['manifest'] ?? $payload;

        if (!is_array($manifest) || $manifest === []) {
            return $this->fail('manifest 不能为空');
        }

        $parsed = $this->service->parseManifest($manifest);

        return Response::json([
            'ok'   => $parsed['ok'],
            'data' => ['valid' => $parsed['ok'], 'error' => $parsed['error']],
        ], $parsed['ok'] ? 200 : 400);
    }

    /** GET /api/user/plugins/{id} —— 单个插件详情 */
    public function show(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $plugin = $this->repository->findForUser($id, Tenant::scope());

        if ($plugin === null) {
            return $this->fail('插件不存在', 404);
        }

        return Response::json(['ok' => true, 'data' => ['plugin' => $this->service->validatePlugin($plugin)]]);
    }

    /** PUT /api/user/plugins/{id} —— 更新（替换 manifest 或改元数据） */
    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $scope = Tenant::scope();
        $plugin = $this->repository->findForUser($id, $scope);

        if ($plugin === null) {
            return $this->fail('插件不存在', 404);
        }

        $payload = $request->json();
        $data = [];

        // 替换 manifest（重新校验）
        if (isset($payload['manifest']) && is_array($payload['manifest'])) {
            $parsed = $this->service->parseManifest($payload['manifest']);
            if (!$parsed['ok']) {
                return $this->fail($parsed['error']);
            }
            $data['status'] = 'OK';
            $data['status_message'] = '';
            $data['manifest'] = $parsed['manifest'];
            $data['version'] = (string) ($parsed['manifest']['version'] ?? $plugin['version']);
            $data['entry'] = (string) ($parsed['manifest']['main'] ?? $plugin['entry']);
            $data['subpackages'] = (array) ($parsed['manifest']['subpackages'] ?? []);
        }

        foreach (['name', 'description'] as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = (string) $payload[$key];
            }
        }
        if (array_key_exists('enabled', $payload)) {
            $data['enabled'] = !empty($payload['enabled']);
        }

        if ($data !== []) {
            $this->repository->update($id, $data);
        }

        return Response::json([
            'ok'   => true,
            'data' => ['plugin' => $this->repository->findForUser($id, $scope)],
        ]);
    }

    /** POST /api/user/plugins/{id}/toggle —— 启用 / 停用 */
    public function toggle(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $scope = Tenant::scope();
        $plugin = $this->repository->findForUser($id, $scope);

        if ($plugin === null) {
            return $this->fail('插件不存在', 404);
        }

        $payload = $request->json();
        $enabled = array_key_exists('enabled', $payload)
            ? !empty($payload['enabled'])
            : !$plugin['enabled'];

        $this->repository->update($id, ['enabled' => $enabled]);

        return Response::json([
            'ok'   => true,
            'data' => ['plugin' => $this->repository->findForUser($id, $scope)],
        ]);
    }

    /** DELETE /api/user/plugins/{id} —— 卸载 */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');

        if ($this->repository->findForUser($id, Tenant::scope()) === null) {
            return $this->fail('插件不存在', 404);
        }

        $this->repository->delete($id);

        return Response::json(['ok' => true, 'message' => '已卸载']);
    }

    /**
     * GET /api/user/plugins/capabilities —— 汇总启用插件声明的能力。
     *
     * 供 App 侧 Agent 把插件工具并入 function 列表，并取插件系统提示。
     */
    public function capabilities(Request $request): Response
    {
        $scope = Tenant::scope();

        return Response::json([
            'ok'   => true,
            'data' => [
                'tools'         => $this->service->collectToolSchemas($scope),
                'system_prompt' => $this->service->collectSystemPrompt($scope),
            ],
        ]);
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
