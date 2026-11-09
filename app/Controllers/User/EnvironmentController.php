<?php
/**
 * 用户端环境配置控制器（/api/user/env/*）
 *
 * 参考 Operit 的 SoftwareSettings：
 *  - 环境变量读写（EnvironmentVariableRead/Write）
 *  - 沙箱包清单查询（SandboxPackages）
 *  - 平台/运行时信息
 *
 * ⚠️ 属主隔离：所有操作经 Tenant::scope()。
 */

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\EnvVarRepository;
use App\Services\EnvironmentService;
use App\Support\Tenant;

class EnvironmentController
{
    /** 变量上限长度 */
    private const MAX_VALUE_BYTES = 65536;

    public function __construct(
        private EnvVarRepository $repository,
        private EnvironmentService $service,
    ) {}

    /** GET /api/user/env/variables —— 变量列表 */
    public function index(Request $request): Response
    {
        $scope = Tenant::scope();

        return Response::json([
            'ok'   => true,
            'data' => [
                'items' => $this->repository->list($scope),
                'count' => $this->repository->countForUser($scope),
                'limit' => EnvVarRepository::MAX_PER_USER,
            ],
        ]);
    }

    /** POST /api/user/env/variables —— 写入（同名覆盖） */
    public function store(Request $request): Response
    {
        $payload = $request->json();
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            return $this->fail('变量名不能为空');
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $name)) {
            return $this->fail('变量名只能由字母、数字、下划线组成，且不能以数字开头');
        }

        $value = (string) ($payload['value'] ?? '');
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            return $this->fail('变量值过大');
        }

        $scope = Tenant::scope();
        $existing = $this->repository->findByName($name, $scope);
        if ($existing === null && $this->repository->countForUser($scope) >= EnvVarRepository::MAX_PER_USER) {
            return $this->fail('环境变量数量已达上限');
        }

        $this->repository->set(
            $name,
            $value,
            !empty($payload['is_secret']),
            (string) ($payload['description'] ?? ''),
            $scope,
            Tenant::writeId(),
        );

        return Response::json([
            'ok'   => true,
            'data' => ['variable' => $this->repository->findByName($name, $scope)],
        ]);
    }

    /** PUT /api/user/env/variables/{id} —— 更新 */
    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $scope = Tenant::scope();
        $variable = $this->repository->findForUser($id, $scope);

        if ($variable === null) {
            return $this->fail('环境变量不存在', 404);
        }

        $payload = $request->json();
        $name = trim((string) ($payload['name'] ?? $variable['name']));

        if ($name === '') {
            return $this->fail('变量名不能为空');
        }

        // 改名的重名检查
        if ($name !== $variable['name'] && $this->repository->findByName($name, $scope) !== null) {
            return $this->fail('同名变量已存在');
        }

        $isSecret = array_key_exists('is_secret', $payload)
            ? !empty($payload['is_secret'])
            : $variable['is_secret'];
        $value = array_key_exists('value', $payload)
            ? (string) $payload['value']
            : (string) $variable['_plain_value'];

        // 改名场景：先删旧再写新（set 按名 upsert，无法直接改名）
        if ($name !== $variable['name']) {
            $this->repository->delete($id);
        }

        $this->repository->set(
            $name,
            $value,
            $isSecret,
            (string) ($payload['description'] ?? $variable['description']),
            $scope,
            Tenant::writeId(),
        );

        return Response::json([
            'ok'   => true,
            'data' => ['variable' => $this->repository->findByName($name, $scope)],
        ]);
    }

    /** DELETE /api/user/env/variables/{id} */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');

        if ($this->repository->findForUser($id, Tenant::scope()) === null) {
            return $this->fail('环境变量不存在', 404);
        }

        $this->repository->delete($id);

        return Response::json(['ok' => true, 'message' => '已删除']);
    }

    /** DELETE /api/user/env/variables —— 清空 */
    public function clear(Request $request): Response
    {
        $count = $this->repository->deleteAll(Tenant::scope());

        return Response::json(['ok' => true, 'message' => "已清空 {$count} 项"]);
    }

    /** GET /api/user/env/packages —— 沙箱包清单 */
    public function packages(Request $request): Response
    {
        return Response::json([
            'ok'   => true,
            'data' => ['packages' => $this->service->catalog()],
        ]);
    }

    /** GET /api/user/env/info —— 平台 / 运行时信息 */
    public function info(Request $request): Response
    {
        return Response::json([
            'ok'   => true,
            'data' => ['platform' => $this->service->platformInfo()],
        ]);
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
