<?php
/**
 * 管理台 · 模型目录
 *  - 列表（按供应商分组）/ 手工新增 / 改名/开关/删除
 *  - 批量开关、从账号真实拉取模型并同步
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\ModelRepository;
use App\Repositories\ProviderRepository;
use App\Services\ModelCatalogSync;
use App\Support\Audit;
use App\Support\Tenant;

class ModelController
{
    public function __construct(
        private ModelRepository $modelRepository,
        private ProviderRepository $providerRepository,
        private AccountRepository $accountRepository,
        private ModelCatalogSync $modelSync,
    ) {}

    /** GET /api/admin/models */
    public function index(Request $request): Response
    {
        $providerKey = trim((string) $request->query('provider', ''));
        $models = $this->modelRepository->all($providerKey !== '' ? $providerKey : null);

        // 按供应商分组，便于卡片式展示
        $grouped = [];
        foreach ($models as $model) {
            $key = $model['provider_key'];
            if (!isset($grouped[$key])) {
                $config = $this->providerRepository->find($key);
                $grouped[$key] = [
                    'provider_key' => $key,
                    'display_name' => $config['display_name'] ?? $key,
                    'enabled'      => $config['enabled'] ?? false,
                    'kind'         => $config['kind'] ?? 'builtin',
                    'models'       => [],
                ];
            }
            $grouped[$key]['models'][] = $model;
        }

        // 汇总每个供应商的模型倍率区间（倍率来自上游同步，用于费用换算）
        foreach ($grouped as &$group) {
            $rates = [];
            foreach ($group['models'] as $m) {
                if ($m['rate'] !== null) {
                    $rates[] = (float) $m['rate'];
                }
            }
            $group['rate_count'] = count($rates);
            $group['rate_min']   = $rates ? min($rates) : null;
            $group['rate_max']   = $rates ? max($rates) : null;
            $group['rate_avg']   = $rates ? array_sum($rates) / count($rates) : null;
        }
        unset($group);

        return Response::json([
            'ok'   => true,
            'data' => [
                'groups'    => array_values($grouped),
                'total'     => count($models),
                'enabled'   => count(array_filter($models, fn($m) => $m['enabled'])),
                'rated'     => count(array_filter($models, fn($m) => $m['rate'] !== null)),
                'suppliers' => array_values(array_map(
                    fn($p) => ['provider_key' => $p['provider_key'], 'display_name' => $p['display_name']],
                    $this->providerRepository->all()
                )),
            ],
        ]);
    }

    /**
     * GET /api/user/models  普通用户可见的模型目录（可管理）
     *
     * 作用域：全局模型（user_id=0）+ 本人私有模型（user_id=本人），由 ModelRepository 统一过滤。
     * 供应商范围：本人拥有账号的 + 本人自建的（避免把一堆自己用不到的全局供应商全倒出来）。
     *
     * ⚠️ 与管理台 index() 的区别：
     *   · **不过滤停用模型** —— 用户现在能自己开关模型，必须能看到停用项才能重新启用；
     *   · 结构与管理台 index() 对齐，便于共享同一页面组件（scoped 前端）。
     */
    public function userIndex(Request $request): Response
    {
        $providerKey = trim((string) $request->query('provider', ''));

        $scope = Tenant::scope();
        $visibleProviders = $this->scopedProviderKeys($scope);

        $models = array_values(array_filter(
            $this->modelRepository->all($providerKey !== '' ? $providerKey : null),
            function (array $m) use ($visibleProviders): bool {
                // 供应商边界优先：只展示「本人有账号 / 本人自建」的供应商下的模型。
                // ⚠️ 不能让「本人私有行」无条件可见 —— 否则用户删掉账号后残留的
                //    私有模型会重新冒出来，且会泄露自己已不再可用的上游构成。
                return $visibleProviders === null || isset($visibleProviders[$m['provider_key']]);
            }
        ));

        $grouped = [];
        foreach ($models as $model) {
            $key = $model['provider_key'];
            if (!isset($grouped[$key])) {
                $config = $this->providerRepository->find($key);
                $grouped[$key] = [
                    'provider_key' => $key,
                    'display_name' => $config['display_name'] ?? $key,
                    'enabled'      => (bool) ($config['enabled'] ?? true),
                    'kind'         => $config['kind'] ?? 'builtin',
                    'user_id'      => (int) ($config['user_id'] ?? 0),
                    'models'       => [],
                ];
            }
            $grouped[$key]['models'][] = $model;
        }

        foreach ($grouped as &$group) {
            $rates = [];
            foreach ($group['models'] as $m) {
                if ($m['rate'] !== null) {
                    $rates[] = (float) $m['rate'];
                }
            }
            $group['rate_count'] = count($rates);
            $group['rate_min']   = $rates ? min($rates) : null;
            $group['rate_max']   = $rates ? max($rates) : null;
            $group['rate_avg']   = $rates ? array_sum($rates) / count($rates) : null;
        }
        unset($group);

        // 可选供应商（供「添加模型」下拉）：本人有账号的 + 本人自建的。
        // ⚠️ 账号型供应商可能不在目录表（`find()` 返回 null），此时用 key 兜底展示，
        //    否则用户在列表里看得见该供应商、却无法给它加模型。
        $suppliers = [];
        if ($visibleProviders !== null) {
            foreach (array_keys($visibleProviders) as $key) {
                $config = $this->providerRepository->find($key);
                $suppliers[] = [
                    'provider_key' => $key,
                    'display_name' => $config['display_name'] ?? $key,
                ];
            }
        }

        return Response::json([
            'ok'   => true,
            'data' => [
                'groups'    => array_values($grouped),
                'total'     => count($models),
                'enabled'   => count(array_filter($models, fn($m) => $m['enabled'])),
                'rated'     => count(array_filter($models, fn($m) => $m['rate'] !== null)),
                'suppliers' => $suppliers,
                'providers' => $suppliers,
            ],
        ]);
    }

    /**
     * 当前作用域可见的供应商键（单一来源，列表/新增/批量开关/同步四处共用）。
     *
     *  · 管理员（scope=null）→ null，代表「不限制」（调用方按全量处理）；
     *  · 普通用户（scope=uid）→ 「本人有账号的」 ∪ 「本人自建的」，
     *    以 `provider_key => true` 的映射返回，便于 O(1) 判定。
     *
     * ⚠️ 为什么不能只用 `ProviderRepository::visible()`：
     *   该方法要求 key 必须存在于 `aigw_provider` 目录表，而**用户账号的 provider_key
     *   可能不在目录表**（账号是独立于供应商配置存在的）。若只认目录表，会出现
     *   「列表里看得见该供应商、点开关却报『请选择有效的供应商』」的错位。
     *
     * ★ 停用即隐身（2026-10-03）：从账号反推的 key **必须再过一遍 `allVisible()`**。
     *   否则用户在「模型目录」页仍能看到「后台已停用的全局供应商」——
     *   它与账号池、供应商页的隐身口径不一致，等于换了个入口把停用信息漏出去
     *   （而且模型开关还能被误操作）。用户自建实例不受影响（`allVisible` 只对
     *   `user_id=0` 施加 enabled 门禁）。
     *
     * @return array<string, true>|null
     */
    private function scopedProviderKeys(?int $scope): ?array
    {
        if ($scope === null) {
            return null;
        }

        $visible = $this->providerRepository->allVisible($scope);

        $keys = [];
        foreach ($this->accountRepository->all(null, $scope) as $account) {
            if (isset($visible[$account->providerKey])) {
                $keys[$account->providerKey] = true;
            }
        }
        foreach ($this->providerRepository->visibleKeys($scope) as $key) {
            $config = $this->providerRepository->find($key);
            // 只把「本人自建的」并入（全局供应商若无账号则不必展示）
            if ($config !== null && (int) ($config['user_id'] ?? 0) === $scope) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * 指定供应商在当前作用域下是否可用（用于写操作的门禁）。
     *
     * 管理员 → 只要目录里存在即可；普通用户 → 必须落在 `scopedProviderKeys()` 边界内。
     */
    private function providerUsable(?int $scope, string $providerKey): bool
    {
        if ($providerKey === '') {
            return false;
        }
        if ($scope === null) {
            return $this->providerRepository->exists($providerKey);
        }
        $keys = $this->scopedProviderKeys($scope);
        return $keys !== null && isset($keys[$providerKey]);
    }

    /** POST /api/admin/models  ·  /api/user/models */
    public function store(Request $request): Response
    {
        $payload = $request->json();
        $providerKey = trim((string) ($payload['provider_key'] ?? ''));
        $modelId = trim((string) ($payload['model_id'] ?? ''));

        // 作用域校验：管理员需目录中存在；普通用户只能给「自己有账号的 / 自己自建的」供应商添模型
        $scope = Tenant::scope();
        if (!$this->providerUsable($scope, $providerKey)) {
            return $this->fail('请选择有效的供应商', $scope === null ? 400 : 404);
        }
        if ($modelId === '') {
            return $this->fail('模型 ID 不能为空');
        }

        $id = $this->modelRepository->add(
            $providerKey,
            $modelId,
            trim((string) ($payload['display_name'] ?? '')),
            !isset($payload['enabled']) || !empty($payload['enabled']),
            true
        );

        Audit::ok('model.create', [
            'target_type' => 'model',
            'target_id'   => (string) $id,
            'summary'     => '新增模型「' . $modelId . '」（' . $providerKey . '）',
            'detail'      => ['provider_key' => $providerKey, 'model_id' => $modelId],
        ]);

        return Response::json(['ok' => true, 'message' => '模型已保存', 'data' => ['id' => $id]]);
    }

    /**
     * PUT /api/admin/models/{id}  ·  /api/user/models/{id}
     *
     * 作用域感知：管理员改全局行；普通用户改本人行；用户改全局行时落本人覆盖行
     * （见 ModelRepository::updateScoped），越权（他人私有行）一律按 404 处理。
     */
    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $payload = $request->json();

        $data = [];
        if (array_key_exists('display_name', $payload)) {
            $data['display_name'] = trim((string) $payload['display_name']);
        }
        if (array_key_exists('enabled', $payload)) {
            $data['enabled'] = !empty($payload['enabled']);
        }
        if (array_key_exists('sort_order', $payload)) {
            $data['sort_order'] = (int) $payload['sort_order'];
        }
        if (array_key_exists('rate', $payload)) {
            $rate = $payload['rate'];
            $data['rate'] = ($rate === null || $rate === '') ? null : (float) $rate;
        }

        if (empty($data)) {
            return $this->fail('没有需要更新的字段');
        }

        if (!$this->modelRepository->existsById($id)) {
            return $this->fail('模型不存在', 404);
        }

        if (!$this->modelRepository->updateScoped($id, $data)) {
            return $this->fail('模型不存在', 404);
        }

        Audit::ok('model.update', [
            'target_type' => 'model',
            'target_id'   => (string) $id,
            'summary'     => '修改模型 #' . $id . '：' . implode('、', array_keys($data)),
            'detail'      => ['fields' => $data],
        ]);

        return Response::json(['ok' => true, 'message' => '模型已更新']);
    }

    /**
     * DELETE /api/admin/models/{id}  ·  /api/user/models/{id}
     *
     * 作用域感知：用户只能删本人行；全局行/他人行一律按 404 处理
     * （用户想「屏蔽」全局模型应改用停用开关，不是删除公共目录）。
     */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        if ($id <= 0) {
            return $this->fail('模型 ID 无效');
        }

        if (!$this->modelRepository->existsById($id)) {
            return $this->fail('模型不存在', 404);
        }

        // 普通用户仅可删本人私有行
        if (!$this->modelRepository->writableById($id)) {
            return $this->fail('模型不存在', 404);
        }

        $this->modelRepository->delete($id);

        Audit::ok('model.delete', [
            'target_type' => 'model',
            'target_id'   => (string) $id,
            'summary'     => '删除模型 #' . $id,
        ]);

        return Response::json(['ok' => true, 'message' => '模型已删除']);
    }

    /** POST /api/admin/models/toggle-provider  ·  /api/user/models/toggle-provider  { provider_key, enabled } */
    public function toggleProvider(Request $request): Response
    {
        $providerKey = $this->readProviderKey($request);

        // 作用域校验：管理员需目录中存在；普通用户只能操作「自己有账号的 / 自建的」供应商
        $scope = Tenant::scope();
        if (!$this->providerUsable($scope, $providerKey)) {
            return $this->fail('请选择有效的供应商', $scope === null ? 400 : 404);
        }

        $enabled = !empty($request->input('enabled'));
        // setEnabledForProvider 内部按作用域限定：用户只切自己名下的行
        $this->modelRepository->setEnabledForProvider($providerKey, $enabled);

        Audit::ok('model.toggle_provider', [
            'target_type' => 'provider',
            'target_id'   => $providerKey,
            'summary'     => ($enabled ? '启用' : '停用') . '供应商「' . $providerKey . '」的全部内置模型',
        ]);

        return Response::json([
            'ok'      => true,
            'message' => $enabled ? '该供应商内置模型已全部启用' : '该供应商内置模型已全部停用',
        ]);
    }

    /**
     * POST /api/admin/models/sync  ·  /api/user/models/sync
     *  { provider_key, account_id? }
     *
     * 从真实账号拉取上游模型并写入目录。
     *  - provider_key 为空 → 对当前作用域内所有已启用供应商执行全量同步；
     *  - 作用域：管理员用全局账号；普通用户**只用自己的账号**（见 ModelCatalogSync::resolveAccount）。
     *
     * ⚠️ 2026-10-04 回滚记录（本方法当前形态 = 同步执行）：
     *   曾改成「异步任务 + 轮询」（HTTP 建任务 → bin/action-worker.php 执行 → 前端轮询），
     *   目的是绕开 CDN 回源超时（EdgeOne 默认 15s，实测 ~50s 就报 524）。
     *   但线上后台 worker 没能接管任务：任务停在 PENDING，60 秒后被判
     *   「后台任务未能启动」，**同步直接失败** —— 比「跑得久但最终成功」更糟，故回滚。
     *   回滚后的行为：请求仍可能被 CDN 在 15~50 秒掐断（客户端看到 524），
     *   但源站 PHP 会把同步跑完（这正是回滚前一直以来的线上行为）。
     *   想再上异步，先解决「worker 起不来」：php tools/action-diagnose.php --run
     *   与 storage/logs/action-worker.log（有没有『启动 worker』行 = PHP-CLI 是否解析到）。
     */
    public function sync(Request $request): Response
    {
        $providerKey = $this->readProviderKey($request);
        $scope       = Tenant::scope();
        $accountId   = (int) $request->input('account_id', 0);

        if ($providerKey === '') {
            $result = $this->modelSync->syncAll($scope);
            if (empty($result['ok'])) {
                return $this->fail((string) $result['message']);
            }

            return Response::json([
                'ok'      => true,
                'message' => $result['message'],
                'data'    => ['providers' => $result['providers'], 'models' => $result['models']],
            ]);
        }

        // 作用域校验：管理员需目录中存在；普通用户只能同步「自己有账号的 / 自建的」供应商
        if (!$this->providerUsable($scope, $providerKey)) {
            return $this->fail('请选择有效的供应商', $scope === null ? 400 : 404);
        }

        $result = $this->modelSync->syncProvider($providerKey, $scope, $accountId);
        if (empty($result['ok'])) {
            return $this->fail((string) $result['message']);
        }

        return Response::json([
            'ok'      => true,
            'message' => $result['message'],
            'data'    => [
                'provider' => $providerKey,
                'total'    => (int) $result['total'],
                'created'  => (int) $result['created'],
                'updated'  => (int) $result['updated'],
            ],
        ]);
    }

    /** 兼容读取供应商键：provider_key 优先，回退 provider */
    private function readProviderKey(Request $request): string
    {
        $key = trim((string) $request->input('provider_key', ''));
        if ($key === '') {
            $key = trim((string) $request->input('provider', ''));
        }
        return $key;
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
