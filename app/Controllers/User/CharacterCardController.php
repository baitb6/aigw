<?php
/**
 * 用户端角色卡控制器（/api/user/character-cards/*）
 *
 * 参考 Operit 的 CharacterCard 管理：CRUD + 激活/取消 + 导入（SillyTavern 兼容）
 * + 导出 + 预览系统提示词。
 *
 * ⚠️ 属主隔离：所有操作经 Tenant::scope()。
 */

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\CharacterCardRepository;
use App\Repositories\ModelProfileRepository;
use App\Services\CharacterCardService;
use App\Support\Tenant;

class CharacterCardController
{
    public function __construct(
        private CharacterCardRepository $repository,
        private CharacterCardService $service,
        private ModelProfileRepository $profiles,
    ) {}

    /** GET /api/user/character-cards */
    public function index(Request $request): Response
    {
        $scope = Tenant::scope();
        $active = $this->repository->active($scope);

        return Response::json([
            'ok'   => true,
            'data' => [
                'items'          => $this->repository->list($scope),
                'count'          => $this->repository->countForUser($scope),
                'limit'          => CharacterCardRepository::MAX_PER_USER,
                'active_id'      => $active['id'] ?? null,
            ],
        ]);
    }

    /** POST /api/user/character-cards */
    public function store(Request $request): Response
    {
        $payload = $request->json();
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            return $this->fail('角色名不能为空');
        }

        $scope = Tenant::scope();
        if ($this->repository->nameExists($name, $scope)) {
            return $this->fail('同名角色卡已存在');
        }
        if ($this->repository->countForUser($scope) >= CharacterCardRepository::MAX_PER_USER) {
            return $this->fail('角色卡数量已达上限');
        }

        $data = $this->extract($payload);
        $data['name'] = $name;
        $data['user_id'] = Tenant::writeId();
        if (!$this->profileAvailable($data, $data['user_id'])) {
            return $this->fail('模型档案不存在、已禁用或不属于当前角色卡属主，请解除绑定后重试');
        }

        $id = $this->repository->create($data);

        if (!empty($payload['activate'])) {
            $this->repository->activate($id, $scope);
        }

        return Response::json([
            'ok'   => true,
            'data' => ['card' => $this->repository->findForUser($id, $scope)],
        ]);
    }

    /** GET /api/user/character-cards/{id} */
    public function show(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $card = $this->repository->findForUser($id, Tenant::scope());

        if ($card === null) {
            return $this->fail('角色卡不存在', 404);
        }

        return Response::json([
            'ok'   => true,
            'data' => [
                'card'          => $card,
                'system_prompt' => $this->service->buildSystemPrompt($card),
            ],
        ]);
    }

    /** PUT /api/user/character-cards/{id} */
    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $scope = Tenant::scope();
        $card = $this->repository->findForUser($id, $scope);

        if ($card === null) {
            return $this->fail('角色卡不存在', 404);
        }

        $payload = $request->json();
        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return $this->fail('角色名不能为空');
            }
            if ($this->repository->nameExists($name, $scope, $id)) {
                return $this->fail('同名角色卡已存在');
            }
        }

        $data = $this->extract($payload);
        if (array_key_exists('name', $payload)) {
            $data['name'] = trim((string) $payload['name']);
        }

        if (!$this->profileAvailable($data, (int) ($card['user_id'] ?? 0))) {
            return $this->fail('模型档案不存在、已禁用或不属于当前角色卡属主，请解除绑定后重试');
        }
        if ($data !== []) {
            $this->repository->update($id, $data, Tenant::scope());
        }

        return Response::json([
            'ok'   => true,
            'data' => ['card' => $this->repository->findForUser($id, $scope)],
        ]);
    }

    /** DELETE /api/user/character-cards/{id} */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');

        if ($this->repository->findForUser($id, Tenant::scope()) === null) {
            return $this->fail('角色卡不存在', 404);
        }

        $this->repository->delete($id, Tenant::scope());

        return Response::json(['ok' => true, 'message' => '已删除']);
    }

    /**
     * POST /api/user/character-cards/import —— 导入（SillyTavern / 本项目导出格式）。
     *
     * 入参：`{card: {...}}` 或直接传角色卡字段本身。
     */
    public function import(Request $request): Response
    {
        $payload = $request->json();
        $raw = $payload['card'] ?? $payload;

        if (!is_array($raw) || $raw === []) {
            return $this->fail('card 不能为空');
        }

        $normalized = $this->service->normalize($raw);
        if (!$normalized['ok']) {
            return $this->fail($normalized['error']);
        }

        $scope = Tenant::scope();
        if ($this->repository->countForUser($scope) >= CharacterCardRepository::MAX_PER_USER) {
            return $this->fail('角色卡数量已达上限');
        }

        $data = $normalized['data'];
        $data['user_id'] = Tenant::writeId();
        $data['source'] = 'import';
        if (!$this->profileAvailable($data, $data['user_id'])) {
            return $this->fail('导入角色卡的模型档案不存在、已禁用或属于其他用户，请移除 model_profile_id 后重新导入');
        }

        // 重名时追加序号，保证导入不中断
        $baseName = $data['name'];
        $suffix = 1;
        while ($this->repository->nameExists($data['name'], $scope)) {
            $suffix++;
            $data['name'] = mb_substr($baseName, 0, 120) . " ({$suffix})";
            if ($suffix > 99) {
                return $this->fail('同名角色卡过多，导入失败');
            }
        }

        $id = $this->repository->create($data);

        return Response::json([
            'ok'   => true,
            'data' => ['card' => $this->repository->findForUser($id, $scope)],
        ]);
    }

    /** GET /api/user/character-cards/{id}/export —— 导出为 SillyTavern V2 */
    public function export(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $card = $this->repository->findForUser($id, Tenant::scope());

        if ($card === null) {
            return $this->fail('角色卡不存在', 404);
        }

        return Response::json([
            'ok'   => true,
            'data' => ['card' => $this->service->export($card)],
        ]);
    }

    /** POST /api/user/character-cards/{id}/activate —— 激活 */
    public function activate(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $scope = Tenant::scope();

        if ($this->repository->findForUser($id, $scope) === null) {
            return $this->fail('角色卡不存在', 404);
        }

        $payload = $request->json();
        if (array_key_exists('active', $payload) && empty($payload['active'])) {
            $this->repository->deactivate($scope);
        } else {
            $this->repository->activate($id, $scope);
        }

        return Response::json([
            'ok'   => true,
            'data' => [
                'card'      => $this->repository->findForUser($id, $scope),
                'active_id' => ($this->repository->active($scope)['id'] ?? null),
            ],
        ]);
    }

    private function profileAvailable(array $data, int $ownerId): bool
    {
        $id = (int) ($data['model_profile_id'] ?? 0);
        if ($id === 0) {
            return true;
        }
        if ($id < 0) {
            return false;
        }
        $profile = $this->profiles->findForUser($id, $ownerId);
        return $profile !== null && !empty($profile['enabled']);
    }

    private function extract(array $payload): array
    {
        $data = [];
        foreach (['description', 'character_setting', 'opening_statement', 'other_content_chat',
                  'other_content_voice', 'advanced_custom_prompt', 'marks', 'avatar'] as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = (string) $payload[$key];
            }
        }
        if (array_key_exists('tags', $payload) && is_array($payload['tags'])) {
            $data['tags'] = array_values(array_map('strval', $payload['tags']));
        }
        if (array_key_exists('model_profile_id', $payload)) {
            $data['model_profile_id'] = (int) $payload['model_profile_id'];
        }
        foreach (['is_default'] as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = !empty($payload[$key]);
            }
        }

        return $data;
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
