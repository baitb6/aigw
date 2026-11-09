<?php
/**
 * 用户端 MCP 控制器（/api/user/mcp/*）
 *
 * 参考 Operit 的 MCP 管理界面能力：列出 / 新增 / 修改 / 删除 MCP 服务，
 * 拉取工具清单，并做连通性测试。
 *
 * ⚠️ 属主隔离：所有操作经 Tenant::scope()，普通用户只能操作自己的服务。
 */

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\McpServerRepository;
use App\Services\McpService;
use App\Support\Tenant;

class McpController
{
    public function __construct(
        private McpServerRepository $repository,
        private McpService $service,
    ) {}

    /** GET /api/user/mcp —— 服务列表 */
    public function index(Request $request): Response
    {
        $this->repository->ensureSchema();

        return Response::json([
            'ok'   => true,
            'data' => ['items' => array_map([$this, 'publicServer'], $this->repository->list(Tenant::scope()))],
        ]);
    }

    /** POST /api/user/mcp —— 新增服务 */
    public function store(Request $request): Response
    {
        $payload = $request->json();
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            return $this->fail('服务名不能为空');
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $name)) {
            return $this->fail('服务名只能包含字母、数字、下划线与短横线');
        }

        $scope = Tenant::scope();
        if ($this->repository->nameExists($name, $scope)) {
            return $this->fail('同名服务已存在');
        }

        $transport = ($payload['transport'] ?? 'remote') === 'local' ? 'local' : 'remote';
        if ($transport === 'remote' && trim((string) ($payload['endpoint'] ?? '')) === '') {
            return $this->fail('远程服务必须填写 endpoint');
        }
        if ($transport === 'local' && trim((string) ($payload['command'] ?? '')) === '') {
            return $this->fail('本地服务必须填写 command');
        }

        if ($transport === 'local' && !Tenant::isAdmin()) {
            return $this->fail('本地 stdio 服务仅管理员可配置和执行', 403);
        }
        // 命令白名单前置校验（保存即报错，不用等到执行时才发现被拦）
        if ($transport === 'local') {
            try {
                McpService::assertCommandAllowed((string) ($payload['command'] ?? ''));
            } catch (\Throwable $e) {
                return $this->fail($e->getMessage());
            }
        }
        $data = $this->extract($payload);
        $data['user_id'] = Tenant::writeId();

        $id = $this->repository->create($data);
        $server = $this->repository->findForUser($id, $scope);

        // 建完即尝试拉工具清单（失败也返回成功，只是缓存为空并带 last_status=FAIL）
        if ($server !== null && !empty($server['enabled'])) {
            $this->service->refreshTools($server);
        }

        return Response::json([
            'ok'   => true,
            'data' => ['id' => $id, 'server' => $this->publicServer($this->repository->findForUser($id, $scope))],
        ]);
    }

    /** PUT /api/user/mcp/{id} —— 修改服务 */
    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $scope = Tenant::scope();
        $server = $this->repository->findForUser($id, $scope);

        if ($server === null) {
            return $this->fail('服务不存在', 404);
        }

        $payload = $request->json();
        $data = $this->extract($payload);
        if (!Tenant::isAdmin() && (($server['transport'] ?? '') === 'local' || ($data['transport'] ?? '') === 'local')) {
            return $this->fail('本地 stdio 服务仅管理员可配置和执行', 403);
        }
        // 命令白名单前置校验（改命令或改为 local 时立即校验）
        if (isset($data['command']) || ($data['transport'] ?? $server['transport'] ?? '') === 'local') {
            $cmdToCheck = (string) ($data['command'] ?? $server['command'] ?? '');
            try {
                McpService::assertCommandAllowed($cmdToCheck);
            } catch (\Throwable $e) {
                return $this->fail($e->getMessage());
            }
        }
        if (isset($data['name']) && !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $data['name'])) {
            return $this->fail('服务名只能包含字母、数字、下划线与短横线');
        }

        // name 唯一性（排除自身）
        if (isset($data['name']) && $data['name'] !== '' && $this->repository->nameExists($data['name'], $scope, $id)) {
            return $this->fail('同名服务已存在');
        }

        // token 未填表示不修改（避免把已存凭据清空）
        if (!array_key_exists('bearer_token', $payload)) {
            unset($data['bearer_token']);
        }

        $this->repository->update($id, $data, Tenant::scope());

        return Response::json([
            'ok'   => true,
            'data' => ['server' => $this->publicServer($this->repository->findForUser($id, $scope))],
        ]);
    }

    /** DELETE /api/user/mcp/{id} */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $server = $this->repository->findForUser($id, Tenant::scope());

        if ($server === null) {
            return $this->fail('服务不存在', 404);
        }

        $this->repository->delete($id, Tenant::scope());

        return Response::json(['ok' => true, 'message' => '已删除']);
    }

    /** POST /api/user/mcp/{id}/refresh —— 重新拉取工具清单 */
    public function refresh(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $server = $this->repository->findForUser($id, Tenant::scope());

        if ($server === null) {
            return $this->fail('服务不存在', 404);
        }

        if (($server['transport'] ?? 'remote') === 'local' && !Tenant::isAdmin()) {
            return $this->fail('本地 stdio 服务仅管理员可配置和执行', 403);
        }
        $result = $this->service->refreshTools($server);

        return Response::json([
            'ok'      => $result['status'] === 'OK',
            'message' => $result['status'] === 'OK' ? '工具清单已更新' : $result['message'],
            'data'    => [
                'tools'  => $result['tools'],
                'server' => $this->publicServer($this->repository->findForUser($id, Tenant::scope())),
            ],
        ]);
    }

    /** POST /api/user/mcp/test —— 连通性测试（新增前验证） */
    public function test(Request $request): Response
    {
        $payload = $request->json();

        // 未保存的临时配置：直接构造一个虚拟 server 行做 tools/list
        $probe = $this->extract($payload);
        if (!empty($payload['id'])) {
            $saved = $this->repository->findForUser((int) $payload['id'], Tenant::scope());
            if ($saved === null) {
                return $this->fail('服务不存在', 404);
            }
            if (($probe['bearer_token'] ?? '') === '') {
                unset($probe['bearer_token'], $saved['bearer_token']);
            }
            $probe = array_replace($saved, $probe);
        }
        if (($probe['transport'] ?? 'remote') === 'local' && !Tenant::isAdmin()) {
            return $this->fail('本地 stdio 服务仅管理员可配置和执行', 403);
        }
        $probe['id'] = 0;
        $probe['user_id'] = Tenant::writeId();
        if (($probe['transport'] ?? 'remote') === 'local') {
            $probe['transport'] = 'local';
        }

        $result = $this->service->refreshTools($probe);

        return Response::json([
            'ok'      => $result['status'] === 'OK',
            'message' => $result['status'] === 'OK' ? '连接成功' : $result['message'],
            'data'    => ['tools' => $result['tools']],
        ]);
    }

    /**
     * GET /api/user/mcp/tools —— 汇总本用户全部启用服务的工具清单。
     *
     * 供 App 侧 Agent 循环把网关托管的 MCP 工具作为 function 暴露给模型
     * （命名 `mcp__<server>__<tool>`，与 Operit 的 pluginId:toolName 前缀隔离一致）。
     */
    public function tools(Request $request): Response
    {
        $collected = $this->service->collectTools(Tenant::scope());

        return Response::json([
            'ok'   => true,
            'data' => ['tools' => $collected['schemas']],
        ]);
    }

    /**
     * POST /api/user/mcp/invoke —— 代 App 执行一次 MCP 工具调用。
     *
     * 入参：`{name: "mcp__<server>__<tool>", arguments: {...}}`
     * 服务端按前缀反查所属服务并转发（remote/local 均可）。
     */
    public function invoke(Request $request): Response
    {
        $payload = $request->json();
        $qualified = trim((string) ($payload['name'] ?? ''));
        $arguments = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];

        if ($qualified === '') {
            return $this->fail('name 不能为空');
        }

        $collected = $this->service->collectTools(Tenant::scope());
        $entry = $collected['index'][$qualified] ?? null;

        if ($entry === null) {
            return $this->fail("未知或未启用的工具：{$qualified}", 404);
        }

        $result = $this->service->callTool($entry['server'], $entry['tool'], $arguments);

        return Response::json([
            'ok'      => !$result['is_error'],
            'message' => $result['result'],
            'data'    => ['result' => $result['result'], 'is_error' => $result['is_error']],
        ], $result['is_error'] ? 502 : 200);
    }

    /** 从请求体抽取字段（只接受白名单键） */
    private function extract(array $payload): array
    {
        $data = [];
        foreach (['name', 'transport', 'connection_type', 'endpoint', 'command'] as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = (string) $payload[$key];
            }
        }
        foreach (['args', 'env', 'headers'] as $key) {
            if (array_key_exists($key, $payload) && is_array($payload[$key])) {
                $data[$key] = $payload[$key];
            }
        }
        if (array_key_exists('enabled', $payload)) {
            $data['enabled'] = !empty($payload['enabled']);
        }
        if (array_key_exists('bearer_token', $payload)) {
            $data['bearer_token'] = (string) $payload['bearer_token'];
        }

        return $data;
    }

    private function publicServer(?array $server): ?array
    {
        if ($server !== null) {
            unset($server['_token_enc']);
        }
        return $server;
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
