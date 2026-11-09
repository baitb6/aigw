<?php
/**
 * 管理台 · 供应商管理
 *  - 列表 / 开关 / 更新（含自定义供应商的 Base URL 与模型）
 *  - 新建自定义供应商实例（key 形如 custom_xxx）
 *  - 删除自定义供应商实例
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Provider\ProviderManager;
use App\Repositories\AccountRepository;
use App\Repositories\ModelRepository;
use App\Repositories\ProviderRepository;
use App\Support\Audit;
use App\Support\Logger;
use App\Support\Tenant;

class ProviderController
{
    public function __construct(
        private ProviderRepository $providerRepository,
        private AccountRepository $accountRepository,
        private ModelRepository $modelRepository,
        private ProviderManager $providerManager,
        private Database $db,
    ) {}

    /** GET /api/admin/providers */
    public function index(Request $request): Response
    {
        $items = [];
        foreach ($this->providerRepository->all() as $key => $config) {
            $provider = null;
            try {
                $provider = $this->providerManager->get($key);
            } catch (\Throwable) {
                // 适配器不可用时仍然展示配置
            }

            $models = $this->modelRepository->all($key);
            $rates = [];
            foreach ($models as $m) {
                if ($m['rate'] !== null) {
                    $rates[] = (float) $m['rate'];
                }
            }

            $items[] = [
                'provider_key'  => $key,
                'display_name'  => $config['display_name'],
                'kind'          => $config['kind'],
                'enabled'       => $config['enabled'],
                'login_type'    => $config['login_type'],
                'default_model' => $config['default_model'],
                'rate_count'    => count($rates),
                'rate_min'      => $rates ? min($rates) : null,
                'rate_max'      => $rates ? max($rates) : null,
                'rate_avg'      => $rates ? round(array_sum($rates) / count($rates), 4) : null,
                'hosts'         => $config['hosts'],
                'options'       => $this->safeOptions($config['options']),
                'accounts'      => $this->accountRepository->summary($key),
                'model_count'   => count($models),
                'supports_login'=> $config['login_type'] !== 'NONE',
                'adapter_ready' => $provider !== null,
            ];
        }

        return Response::json([
            'ok'   => true,
            'data' => [
                'items' => $items,
                'login_types' => [
                    'WEBVIEW_CALLBACK' => '网页授权回调',
                    'SMS_CODE'         => '短信验证码',
                    'DEVICE_CODE'      => '设备码授权',
                    'OAUTH_LOOPBACK'   => 'OAuth 授权',
                    'API_KEY'          => 'API Key',
                    'NONE'             => '不支持自助登录',
                ],
            ],
        ]);
    }

    /**
     * GET /api/user/providers  普通用户可见的供应商列表
     *  - 仅返回可用元数据，剔除管理员专属配置（脱敏 Key、限速、上游 Host 等）
     *  - 账号统计按当前用户作用域（Tenant::scope()）聚合，互相隔离
     *  - 供应商范围：全局（user_id=0）+ 本人自建的（user_id=本人）
     */
    public function userIndex(Request $request): Response
    {
        $scope = Tenant::scope();
        $items = [];

        foreach ($this->providerRepository->visibleKeys($scope) as $key) {
            $config = $this->providerRepository->find($key);
            if ($config === null) {
                continue;
            }

            $provider = null;
            try {
                $provider = $this->providerManager->get($key);
            } catch (\Throwable) {
                // 适配器不可用时仍然展示配置
            }

            $items[] = [
                'provider_key'   => $key,
                'display_name'   => $config['display_name'],
                'kind'           => $config['kind'],
                'enabled'        => $config['enabled'],
                'login_type'     => $config['login_type'],
                'default_model'  => $config['default_model'],
                'accounts'       => $this->accountRepository->summary($key, $scope),
                'model_count'    => count($this->modelRepository->all($key)),
                'supports_login' => $config['login_type'] !== 'NONE',
                'adapter_ready'  => $provider !== null,
                'user_id'        => (int) ($config['user_id'] ?? 0),
                // 是否本人自建（前端据此决定「可编辑 / 可删除」）
                'mine'           => $scope !== null && (int) ($config['user_id'] ?? 0) === $scope,
            ];
        }

        return Response::json([
            'ok'   => true,
            'data' => [
                'items' => $items,
                'login_types' => [
                    'WEBVIEW_CALLBACK' => '网页授权回调',
                    'SMS_CODE'         => '短信验证码',
                    'DEVICE_CODE'      => '设备码授权',
                    'OAUTH_LOOPBACK'   => 'OAuth 授权',
                    'API_KEY'          => 'API Key',
                    'NONE'             => '不支持自助登录',
                ],
            ],
        ]);
    }

    /**
     * POST /api/user/providers/custom  普通用户自建自定义供应商
     *
     * key 规范化：`custom_u<uid>_<slug>`
     * ⚠️ 必须带 uid 段 —— `aigw_provider.provider_key` 上有**全局唯一键**，
     *    两个用户用同一个名字（如都叫 "myapi"）会互相顶掉，导致后建者报「已存在」
     *    或覆盖前者配置。带 uid 后各用户的实例天然隔离、互不可见。
     */
    public function userStoreCustom(Request $request): Response
    {
        $scope = Tenant::scope();
        if ($scope === null) {
            // 管理员走管理台入口，避免用户端出现「无归属」的实例
            return $this->fail('请使用管理台创建供应商', 403);
        }

        $payload = $request->json();

        $name = trim((string) ($payload['display_name'] ?? ''));
        if ($name === '') {
            return $this->fail('请填写供应商名称');
        }

        $slug = trim((string) ($payload['provider_key'] ?? ''));
        if ($slug === '') {
            $slug = preg_replace('/[^a-z0-9]+/i', '_', strtolower($name)) ?: 'endpoint';
        }
        $slug = trim(preg_replace('/[^a-z0-9_]/i', '', strtolower($slug)) ?: 'endpoint', '_');

        // 用户实例统一前缀，保证全局唯一（含 uid）
        $key = 'custom_u' . $scope . '_' . $slug;
        $key = mb_substr($key, 0, 32);

        if ($this->providerRepository->exists($key)) {
            return $this->fail('同名供应商已存在，请更换名称');
        }

        $options = [
            'base_url' => trim((string) ($payload['base_url'] ?? '')),
            'api_keys' => array_values(array_filter(array_map('trim', (array) ($payload['api_keys'] ?? [])))),
            'models'   => array_values(array_filter(array_map('trim', (array) ($payload['models'] ?? [])))),
            'protocol' => 'openai',
        ];

        // 调用前缀（可选）：让自建实例的对外 id 干净可辨
        // （否则只能是 custom_u<uid>_xxx/模型名 这种又长又难认的形式）
        $aliasRaw = trim((string) ($payload['alias'] ?? ''));
        if ($aliasRaw !== '') {
            $alias = $this->providerRepository->normalizeAlias($aliasRaw);
            if ($alias === null) {
                return $this->fail('调用前缀不合法或已被占用（仅限 a-z 0-9 _ -，且需与其他供应商/前缀互不重复）');
            }
            $options['alias'] = $alias;
        }

        $this->providerRepository->createCustom($key, $name, $options, $scope);

        // 若同时提供了 API Key，直接建账号（归属本人），保证「保存即可跑」
        $createdAccount = false;
        if (!empty($options['api_keys'])) {
            $session = $this->providerManager->get($key)->pollLogin(uuid(), [
                'provider_key' => $key,
                'base_url'     => $options['base_url'],
                'api_key'      => (string) reset($options['api_keys']),
                'nickname'     => $name,
            ]);
            $createdAccount = $session->state === 'success';
        }

        Audit::ok('provider.create', [
            'user_id'     => $scope,
            'target_type' => 'provider',
            'target_id'   => $key,
            'summary'     => '新建自定义供应商「' . $name . '」' . ($createdAccount ? '（并自动添加 1 个账号）' : ''),
            'detail'      => ['model_count' => count($options['models']), 'has_api_key' => !empty($options['api_keys'])],
        ]);

        return Response::json([
            'ok'      => true,
            'message' => $createdAccount
                ? "供应商 {$name} 已创建，并已添加 1 个账号"
                : "供应商 {$name} 已创建",
            'data'    => ['provider_key' => $key],
        ]);
    }

    /** PUT /api/user/providers/{key}  用户修改本人自建供应商（Base URL / Key / 模型清单） */
    public function userUpdate(Request $request): Response
    {
        $key = (string) $request->routeParam('key', '');
        $scope = Tenant::scope();

        $config = $this->providerRepository->find($key);
        if ($config === null || !$this->providerRepository->visible($key, $scope)) {
            return $this->fail('供应商不存在', 404);
        }
        if (!$this->providerRepository->writable($key, $scope)) {
            return $this->fail('内置供应商不可修改', 403);
        }

        $payload = $request->json();
        $current = $config['options'] ?? [];

        if (array_key_exists('base_url', $payload)) {
            $current['base_url'] = trim((string) $payload['base_url']);
        }
        if (array_key_exists('api_keys', $payload)) {
            $keys = $payload['api_keys'];
            $current['api_keys'] = is_array($keys)
                ? array_values(array_filter(array_map('trim', $keys)))
                : array_values(array_filter(preg_split('/[\s,\n]+/', (string) $keys) ?: []));
        }
        if (array_key_exists('models', $payload)) {
            $models = $payload['models'];
            $current['models'] = is_array($models)
                ? array_values(array_filter(array_map('trim', $models)))
                : array_values(array_filter(preg_split('/[\s,\n]+/', (string) $models) ?: []));
        }
        // 调用前缀（可选）：留空 = 清掉别名，回落到 provider_key
        if (array_key_exists('alias', $payload)) {
            $alias = $this->providerRepository->normalizeAlias((string) $payload['alias'], $key);
            if ($alias === null) {
                return $this->fail('调用前缀不合法或已被占用（仅限 a-z 0-9 _ -，且需与其他供应商/前缀互不重复）');
            }
            if ($alias === '') {
                unset($current['alias']);
            } else {
                $current['alias'] = $alias;
            }
        }

        $current['protocol'] = 'openai';

        $data = ['options' => $current];
        if (array_key_exists('display_name', $payload)) {
            $data['display_name'] = mb_substr(trim((string) $payload['display_name']), 0, 64);
        }

        if (!$this->providerRepository->updateScoped($key, $data, $scope)) {
            return $this->fail('供应商不存在', 404);
        }

        Logger::info('用户更新自定义供应商', ['provider' => $key, 'user_id' => $scope]);

        Audit::ok('provider.update', [
            'user_id'     => $scope,
            'target_type' => 'provider',
            'target_id'   => $key,
            'summary'     => '修改自定义供应商配置「' . $key . '」',
            'detail'      => ['fields' => array_keys($data)],
        ]);

        return Response::json(['ok' => true, 'message' => '供应商配置已保存']);
    }

    /** DELETE /api/user/providers/{key}  用户删除本人自建供应商（级联删本人账号与模型） */
    public function userDestroyCustom(Request $request): Response
    {
        $key = (string) $request->routeParam('key', '');
        $scope = Tenant::scope();

        $config = $this->providerRepository->find($key);
        if ($config === null || !$this->providerRepository->visible($key, $scope)) {
            return $this->fail('供应商不存在', 404);
        }
        if (($config['kind'] ?? '') !== 'custom') {
            return $this->fail('内置供应商不可删除，只能停用', 403);
        }
        if (!$this->providerRepository->writable($key, $scope)) {
            return $this->fail('只能删除自己创建的供应商', 403);
        }

        // 级联删本人账号 / 本人模型行 / 供应商，包在同一事务里，避免孤儿数据
        $userId = $scope ?? 0;
        $this->db->transaction(function () use ($key, $userId, $scope) {
            foreach ($this->accountRepository->all($key, $userId > 0 ? $userId : null) as $account) {
                $this->accountRepository->delete($account->id);
            }
            if ($userId > 0) {
                // 只删本人私有模型行，不碰全局行
                $this->db->statement(
                    'DELETE FROM `aigw_model` WHERE `provider_key` = ? AND `user_id` = ?',
                    [$key, $userId]
                );
            } else {
                $this->modelRepository->deleteByProvider($key);
            }
            $this->providerRepository->deleteCustom($key, $scope);
        });

        Logger::info('用户删除自定义供应商', ['provider' => $key, 'user_id' => $scope]);

        Audit::ok('provider.delete', [
            'user_id'     => $scope,
            'target_type' => 'provider',
            'target_id'   => $key,
            'summary'     => '删除自定义供应商「' . $key . '」及其账号 / 模型',
            'detail'      => ['display_name' => (string) ($config['display_name'] ?? '')],
        ]);

        return Response::json(['ok' => true, 'message' => '供应商及其账号已删除']);
    }

    /** PUT /api/admin/providers/{key} */
    public function update(Request $request): Response
    {
        $key = (string) $request->routeParam('key', '');
        if (!$this->providerRepository->exists($key)) {
            return $this->fail('供应商不存在', 404);
        }

        $payload = $request->json();
        $data = [];

        if (array_key_exists('display_name', $payload)) {
            $data['display_name'] = mb_substr(trim((string) $payload['display_name']), 0, 64);
        }
        if (array_key_exists('enabled', $payload)) {
            $data['enabled'] = !empty($payload['enabled']);
        }
        if (array_key_exists('default_model', $payload)) {
            $defaultModel = trim((string) $payload['default_model']);

            // 非空时必须落在该供应商的模型目录里，避免把默认模型写成不存在的字符串，
            // 导致网关按默认模型路由时 503。目录为空（尚未同步）时不做限制。
            if ($defaultModel !== '') {
                $known = array_column($this->modelRepository->all($key), 'model_id');
                if ($known && !in_array($defaultModel, $known, true)) {
                    return $this->fail('默认模型不在该供应商的模型目录中：' . $defaultModel);
                }
            }

            $data['default_model'] = $defaultModel;
        }
        if (array_key_exists('rate', $payload)) {
            $rate = (float) $payload['rate'];
            if ($rate < 0) {
                return $this->fail('倍率不能为负数');
            }
            $data['rate'] = round($rate, 4);
        }

        // 自定义供应商的可运行配置
        $config = $this->providerRepository->find($key);
        if (($config['kind'] ?? '') === 'custom') {
            $current = $config['options'] ?? [];

            if (array_key_exists('base_url', $payload)) {
                $current['base_url'] = trim((string) $payload['base_url']);
            }
            if (array_key_exists('api_keys', $payload)) {
                $keys = $payload['api_keys'];
                $current['api_keys'] = is_array($keys)
                    ? array_values(array_filter(array_map('trim', $keys)))
                    : array_values(array_filter(preg_split('/[\s,\n]+/', (string) $keys) ?: []));
            }
            if (array_key_exists('models', $payload)) {
                $models = $payload['models'];
                $current['models'] = is_array($models)
                    ? array_values(array_filter(array_map('trim', $models)))
                    : array_values(array_filter(preg_split('/[\s,\n]+/', (string) $models) ?: []));
            }

            // 调用前缀（可选）：留空 = 清掉别名，回落到 provider_key
            if (array_key_exists('alias', $payload)) {
                $alias = $this->providerRepository->normalizeAlias((string) $payload['alias'], $key);
                if ($alias === null) {
                    return $this->fail('调用前缀不合法或已被占用（仅限 a-z 0-9 _ -，且需与其他供应商/前缀互不重复）');
                }
                if ($alias === '') {
                    unset($current['alias']);
                } else {
                    $current['alias'] = $alias;
                }
            }

            $current['protocol'] = 'openai';
            $data['options'] = $current;
        }

        if (empty($data)) {
            return $this->fail('没有需要更新的字段');
        }

        $this->providerRepository->update($key, $data);
        Logger::info('供应商配置已更新', ['provider' => $key, 'fields' => array_keys($data)]);
        Audit::ok('provider.update', [
            'target_type' => 'provider',
            'target_id'   => $key,
            'summary'     => '修改供应商配置「' . $key . '」：' . implode('、', array_keys($data)),
            // options 可能含 api_keys 明文，只记字段名不记值
            'detail'      => ['fields' => array_keys($data), 'values' => array_diff_key($data, ['options' => 1])],
        ]);

        return Response::json(['ok' => true, 'message' => '供应商配置已保存']);
    }

    /** POST /api/admin/providers/custom */
    public function storeCustom(Request $request): Response
    {
        $payload = $request->json();

        $name = trim((string) ($payload['display_name'] ?? ''));
        if ($name === '') {
            return $this->fail('请填写供应商名称');
        }

        // key 规范化：custom_<slug>
        $slug = trim((string) ($payload['provider_key'] ?? ''));
        if ($slug === '') {
            $slug = preg_replace('/[^a-z0-9]+/i', '_', strtolower($name)) ?: 'endpoint';
        }
        $slug = trim(preg_replace('/[^a-z0-9_]/i', '', strtolower($slug)) ?: 'endpoint', '_');
        $key = str_starts_with($slug, 'custom') ? $slug : 'custom_' . $slug;
        $key = mb_substr($key, 0, 32);

        if ($this->providerRepository->exists($key)) {
            return $this->fail("供应商标识 {$key} 已存在，请更换名称");
        }

        $options = [
            'base_url' => trim((string) ($payload['base_url'] ?? '')),
            'api_keys' => array_values(array_filter(array_map('trim', (array) ($payload['api_keys'] ?? [])))),
            'models'   => array_values(array_filter(array_map('trim', (array) ($payload['models'] ?? [])))),
            'protocol' => 'openai',
        ];

        // 调用前缀（可选）：对外 /v1/models 的 id 前段。留空则就是 provider_key。
        $aliasRaw = trim((string) ($payload['alias'] ?? ''));
        if ($aliasRaw !== '') {
            $alias = $this->providerRepository->normalizeAlias($aliasRaw);
            if ($alias === null) {
                return $this->fail('调用前缀不合法或已被占用（仅限 a-z 0-9 _ -，且需与其他供应商/前缀互不重复）');
            }
            $options['alias'] = $alias;
        }

        $this->providerRepository->createCustom($key, $name, $options, Tenant::writeId());

        // 若同时提供了 API Key，直接建账号，保证「保存即可跑」
        $createdAccount = false;
        if (!empty($options['api_keys'])) {
            $session = $this->providerManager->get($key)->pollLogin(uuid(), [
                'provider_key' => $key,
                'base_url'     => $options['base_url'],
                'api_key'      => (string) reset($options['api_keys']),
                'nickname'     => $name,
            ]);
            $createdAccount = $session->state === 'success';
        }

        Audit::ok('provider.create', [
            'target_type' => 'provider',
            'target_id'   => $key,
            'summary'     => '新建自定义供应商「' . $name . '」' . ($createdAccount ? '（并自动添加 1 个账号）' : ''),
            'detail'      => ['model_count' => count($options['models']), 'has_api_key' => !empty($options['api_keys'])],
        ]);

        return Response::json([
            'ok'      => true,
            'message' => $createdAccount
                ? "供应商 {$name} 已创建，并已添加 1 个账号"
                : "供应商 {$name} 已创建",
            'data'    => ['provider_key' => $key],
        ]);    }

    /** DELETE /api/admin/providers/{key} */
    public function destroyCustom(Request $request): Response
    {
        $key = (string) $request->routeParam('key', '');
        $config = $this->providerRepository->find($key);

        if ($config === null) {
            return $this->fail('供应商不存在', 404);
        }
        if (($config['kind'] ?? '') !== 'custom') {
            return $this->fail('内置供应商不可删除，只能停用');
        }

        // 级联删除账号 / 模型 / 供应商，包在同一事务里，
        // 避免中途失败留下「供应商已删但账号/模型残留」的孤儿数据。
        // ⚠️ 管理台是全局视图：`Tenant::scope()` 为 null，可删任何 custom 实例（含用户自建的）。
        $this->db->transaction(function () use ($key) {
            foreach ($this->accountRepository->all($key) as $account) {
                $this->accountRepository->delete($account->id);
            }
            $this->modelRepository->deleteByProvider($key);
            $this->providerRepository->deleteCustom($key, null);
        });

        Logger::info('自定义供应商已删除', ['provider' => $key]);

        Audit::ok('provider.delete', [
            'target_type' => 'provider',
            'target_id'   => $key,
            'summary'     => '删除自定义供应商「' . $key . '」及其账号 / 模型',
            'detail'      => ['display_name' => (string) ($config['display_name'] ?? '')],
        ]);

        return Response::json(['ok' => true, 'message' => '供应商及其账号已删除']);
    }

    /**
     * POST /api/admin/providers/{key}/test
     * 用该供应商下第一个可用账号做连通性自检
     *
     * 作用域：管理员用全局账号；普通用户**只用自己的账号**（防越权用他人凭据跑请求）。
     */
    public function test(Request $request): Response
    {
        $key = (string) $request->routeParam('key', '');
        if (!$this->providerRepository->exists($key)) {
            return $this->fail('供应商不存在', 404);
        }

        $scope = Tenant::scope();

        $accountId = (int) $request->input('account_id', 0);
        if ($accountId > 0) {
            $account = $this->accountRepository->find($accountId);
            if ($account === null || $account->providerKey !== $key) {
                return $this->fail('账号不存在', 404);
            }
            if ($scope !== null && $account->userId !== $scope) {
                return $this->fail('账号不存在', 404);
            }
        } else {
            $account = $this->accountRepository->usable($key, 'balance', '', $scope)[0] ?? null;
        }

        if ($account === null) {
            return $this->fail($scope === null ? '该供应商下没有可用账号' : '你名下该供应商没有可用账号');
        }

        try {
            $result = $this->providerManager->get($key)->action($account, 'test');
        } catch (\Throwable $e) {
            return $this->fail('自检失败：' . $e->getMessage());
        }

        return Response::json([
            'ok'      => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'data'    => $result['data'] ?? null,
        ], ($result['ok'] ?? false) ? 200 : 400);
    }

    /** POST /api/user/providers/{key}/test  —— 用户端自检，复用 test() 的作用域逻辑 */
    public function userTest(Request $request): Response
    {
        $key = (string) $request->routeParam('key', '');
        $scope = Tenant::scope();

        if (!$this->providerRepository->visible($key, $scope)) {
            return $this->fail('供应商不存在', 404);
        }

        return $this->test($request);
    }

    /** options 中不回传完整 Key，只给出数量与脱敏值 */
    private function safeOptions(array $options): array
    {
        $out = $options;

        if (isset($out['api_keys'])) {
            $keys = (array) $out['api_keys'];
            $out['api_keys'] = array_map(fn($k) => mask_secret((string) $k, 6, 4), $keys);
            $out['api_key_count'] = count($keys);
        }

        return $out;
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}