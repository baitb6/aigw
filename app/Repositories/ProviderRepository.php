<?php
/**
 * 供应商仓储（对应 settings/provider/<id>.json）
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;
use App\Support\Tenant;

class ProviderRepository
{
    private const TABLE = 'aigw_provider';

    private bool $schemaChecked = false;

    /**
     * 内置供应商默认行：老库升级后不会重跑 schema.sql，缺失的内置行在首次读取时自动补入。
     * 仅「补缺失」，绝不覆盖已有行（用户对 display_name/enabled/hosts 的修改保持不变）。
     *
     * @var array<string, array{0:string,1:string,2:string,3:string[],4:int}>
     */
    private const BUILTIN_DEFAULTS = [
        'trae'           => ['Trae', 'WEBVIEW_CALLBACK', 'doubao-seed-2.0-pro', ['trae-api-cn.mchost.guru', 'api.trae.cn', 'api.trae.com.cn', 'www.trae.cn'], 10],
        'trae_intl'      => ['Trae 国际版', 'WEBVIEW_CALLBACK', 'doubao-seed-2.0-pro', ['a0ai-api-sg.byteintlapi.com', 'api-sg-central.trae.ai', 'www.trae.ai'], 11],
        'loomy'          => ['Loomy', 'SMS_CODE', 'DeepSeek-v3.2', ['xfinfr.com', 'xunfei.cn', 'account.xfinfr.com'], 20],
        'codebuddy'      => ['WorkBuddy', 'DEVICE_CODE', '', ['codebuddy.cn', 'workbuddy.ai', 'copilot.tencent.com'], 30],
        'codebuddy_intl' => ['WorkBuddy 国际版', 'DEVICE_CODE', '', ['workbuddy.ai'], 31],
        'antigravity'    => ['Antigravity', 'OAUTH_LOOPBACK', '', ['de-pa.googleapis.com', 'oauth2.googleapis.com'], 40],
        'doubao'         => ['豆包', 'WEBVIEW_CALLBACK', 'doubao', ['doubao.com', 'api.doubao.com'], 45],
        'zcode'          => ['ZCode', 'DEVICE_CODE', 'glm-4.6', ['open.bigmodel.cn', 'bigmodel.cn'], 50],
        'zcode_intl'     => ['ZCode 国际版', 'DEVICE_CODE', 'glm-4.6', ['api.z.ai', 'zcode.z.ai'], 51],
        'qoder'          => ['Qoder', 'DEVICE_CODE', 'auto', ['openapi.qoder.com.cn', 'gateway.qoder.com.cn', 'qoder.com.cn'], 60],
        'qoder_intl'     => ['Qoder 国际版', 'DEVICE_CODE', 'auto', ['openapi.qoder.sh', 'api2.qoder.sh', 'qoder.com'], 61],
        'xiaohuanxiong'  => ['商汤小浣熊', 'WEBVIEW_CALLBACK', 'sn-glm-5-3-flash', ['xiaohuanxiong.com'], 70],
    ];

    private bool $builtinsEnsured = false;

    /** @var array<string, array>|null */
    private ?array $cache = null;

    public function __construct(private Database $db) {}

    /**
     * 老库升级：幂等补齐 aigw_provider.user_id 作用域列。
     *
     * ⚠️ 注意这里**不**按作用域过滤 `all()` —— `all()` 是网关路由的基础
     *   （`ProviderManager::get()` 靠它把 provider_key 映射到适配器与配置），
     *   **必须**能看到全部供应商，否则用户自建实例的请求会解析成「未知供应商」。
     *   作用域过滤只在面向用户的列表（`visibleKeys()`）里做。
     */
    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $this->schemaChecked = true;

        Schema::ensureColumns($this->db, self::TABLE, Schema::PROVIDER_COLUMNS);
    }

    /** @return array<string, array> 以 provider_key 为键 */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $this->ensureSchema();

        $rows = $this->db->table(self::TABLE)->orderBy('sort_order', 'ASC')->get();

        if ($this->ensureBuiltins(array_column($rows, 'provider_key'))) {
            $rows = $this->db->table(self::TABLE)->orderBy('sort_order', 'ASC')->get();
        }

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['provider_key']] = $this->normalize($row);
        }

        return $this->cache = $map;
    }

    /**
     * 当前作用域可见的供应商配置（key => config）—— **可见性的唯一真源**。
     *
     *  · 管理员（scope=null）→ 全部（含已停用，管理台要能重新启用）；
     *  · 普通用户（scope=uid）→ **已启用的**全局供应商（user_id=0）
     *    + 本人创建的全部实例（user_id=uid，含停用 —— 见下）。
     *
     * ⚠️ 所有「面向普通用户的列举出口」都必须走这里，不得直接用 `all()`：
     *    `all()` 是**网关路由用的全量视图**（必须含所有人的 custom 实例，
     *    否则用户自建实例的请求会解析成「未知供应商」），把它直接下发给用户
     *    等于把别人自建供应商的 key / 显示名一起泄露出去。
     *    血案（2026-10-02）：思维注入页的靶点下拉、总览面板、账号池总览、
     *    调用日志都直接用了 `all()`，用户 B 能完整看到用户 A 的私有端点。
     *
     * ★ 停用即对普通用户隐身（2026-10-03）：
     *    后台某个**全局**（user_id=0）供应商被停用后，普通用户不得再看到它 ——
     *    否则会出现「看得见、点得动、却建不了号」的错觉（`AccountController::store`
     *    会以 `visible()` 判 false 而报「请选择有效的供应商」，用户无从理解）。
     *    **本人自建的实例不停用即隐身**：用户没有「启停自有实例」的入口（`userUpdate`
     *    只允许改 base_url/api_keys/models/alias/display_name），一旦隐藏就再也删不掉，
     *    会留下无法清理的孤儿账号与模型。故此处只对 `user_id === 0` 施加 enabled 门禁。
     *
     * @return array<string, array>
     */
    public function allVisible(?int $scope = null): array
    {
        $all = $this->all();
        if ($scope === null) {
            return $all;
        }

        $out = [];
        foreach ($all as $key => $config) {
            $owner = (int) ($config['user_id'] ?? 0);

            // 他人自建：一律不可见
            if ($owner !== 0 && $owner !== $scope) {
                continue;
            }

            // 全局（内置 / 管理员建）且已停用 → 对普通用户隐身
            if ($owner === 0 && empty($config['enabled'])) {
                continue;
            }

            $out[$key] = $config;
        }

        return $out;
    }

    /**
     * 当前作用域可见的供应商 key。
     *
     * @return string[]
     */
    public function visibleKeys(?int $scope): array
    {
        return array_keys($this->allVisible($scope));
    }

    /**
     * 当前作用域下某供应商是否可见/可操作。
     *
     * ⚠️ 判定必须与 `allVisible()` **完全同源**（直接复用它），不得另写一份 owner 比较：
     *    两者漂移就会出现「列表里看不见、却仍能建号 / 发起登录 / 批量刷新」的静默漏口
     *    ——停用的全局供应商在列表里已隐身，但若这里仍只比 owner，用户仍能拿它建号。
     *    2026-10-03：改为复用 allVisible()，把「停用即隐身」同时约束到写操作。
     */
    public function visible(string $key, ?int $scope): bool
    {
        if ($scope === null) {
            return $this->find($key) !== null;
        }

        return isset($this->allVisible($scope)[$key]);
    }

    /** 是否可写（用户只能改自己创建的；全局/内置属管理员） */
    public function writable(string $key, ?int $scope): bool
    {
        if ($scope === null) {
            return true;
        }
        $config = $this->find($key);
        if ($config === null) {
            return false;
        }
        return (int) ($config['user_id'] ?? 0) === $scope;
    }

    /**
     * 幂等补齐缺失的内置供应商行；返回是否发生了写入。
     *
     * @param array<int, mixed> $existingKeys
     */
    private function ensureBuiltins(array $existingKeys): bool
    {
        if ($this->builtinsEnsured) {
            return false;
        }
        $this->builtinsEnsured = true;

        $existingKeys = array_map('strval', $existingKeys);
        $inserted = false;

        foreach (self::BUILTIN_DEFAULTS as $key => $def) {
            if (in_array($key, $existingKeys, true)) {
                continue;
            }

            try {
                $this->db->table(self::TABLE)->insert([
                    'provider_key'  => $key,
                    'display_name'  => $def[0],
                    'kind'          => 'builtin',
                    'enabled'       => 1,
                    'login_type'    => $def[1],
                    'default_model' => $def[2],
                    'hosts'         => json_encode($def[3], JSON_UNESCAPED_UNICODE),
                    'sort_order'    => $def[4],
                ]);
                $inserted = true;
            } catch (\Throwable $e) {
                // 补齐失败不应阻塞主流程
                \App\Support\Logger::warn('内置供应商行补齐失败', ['provider' => $key, 'error' => $e->getMessage()]);
            }
        }

        return $inserted;
    }

    /** @return array<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function exists(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * 仅已启用的供应商 key。
     *
     * @param int|null $scope null=全部（网关路由 / 管理台）；uid=仅全局 + 本人可见（用户端）
     */
    public function enabledKeys(?int $scope = null): array
    {
        return array_keys(array_filter(
            $this->allVisible($scope),
            static fn(array $p): bool => (bool) $p['enabled']
        ));
    }

    /**
     * 内置供应商**公开目录**（落地页「支持多提供商」区块用）。
     *
     * 与 `all()` 的区别（务必分清）：
     *  · `all()` 供网关路由使用，**必须含全部供应商**（含用户自建的 custom_xxx）；
     *  · 本方法只回内置的公开品牌名与开关状态 —— 用户自建实例的 display_name
     *    属于操作者的私有信息，不该出现在公开落地页上。
     *
     * 名称取 DB 行的 display_name（管理员可改），缺失时回落内置默认名，
     * 保证「后台改名 → 落地页同步」与其他位置同源。
     *
     * @return list<array{key:string,name:string,enabled:bool}>
     */
    public function builtinCatalog(): array
    {
        $all = $this->all();
        $out = [];

        foreach (self::BUILTIN_DEFAULTS as $key => $def) {
            $row  = $all[$key] ?? null;
            $name = $row !== null ? trim((string) $row['display_name']) : '';

            $out[] = [
                'key'     => $key,
                'name'    => $name !== '' ? $name : $def[0],
                'enabled' => $row === null ? true : (bool) $row['enabled'],
            ];
        }

        return $out;
    }

    private function normalize(array $row): array
    {
        $hosts = $row['hosts'] ?? null;
        if (is_string($hosts)) {
            $hosts = json_decode($hosts, true);
        }

        $options = $row['options'] ?? null;
        if (is_string($options)) {
            $options = json_decode($options, true);
        }

        return [
            'id'            => (int) $row['id'],
            'provider_key'  => (string) $row['provider_key'],
            'display_name'  => (string) $row['display_name'],
            'kind'          => (string) $row['kind'],
            'enabled'       => (bool) $row['enabled'],
            'login_type'    => (string) $row['login_type'],
            'default_model' => (string) ($row['default_model'] ?? ''),
            'rate'          => (float) ($row['rate'] ?? 1.0),
            'hosts'         => is_array($hosts) ? $hosts : [],
            'options'       => is_array($options) ? $options : [],
            'sort_order'    => (int) $row['sort_order'],
            'user_id'       => (int) ($row['user_id'] ?? 0),
        ];
    }

    public function update(string $key, array $data): void
    {
        $allowed = ['display_name', 'enabled', 'default_model', 'rate', 'options', 'hosts', 'login_type'];
        $payload = array_intersect_key($data, array_flip($allowed));

        if (array_key_exists('enabled', $payload)) {
            $payload['enabled'] = $payload['enabled'] ? 1 : 0;
        }
        if (array_key_exists('rate', $payload)) {
            $payload['rate'] = round(max(0.0, (float) $payload['rate']), 4);
        }
        if (array_key_exists('options', $payload) && is_array($payload['options'])) {
            $payload['options'] = json_encode($payload['options'], JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('hosts', $payload) && is_array($payload['hosts'])) {
            $payload['hosts'] = json_encode(array_values($payload['hosts']), JSON_UNESCAPED_UNICODE);
        }

        if (empty($payload)) {
            return;
        }

        $this->db->table(self::TABLE)->where('provider_key', $key)->update($payload);
        $this->cache = null;
    }

    /**
     * 作用域感知更新（供控制器使用）。
     *
     * 用户只能改**自己创建**的自定义实例；对全局/内置供应商返回 false
     * （这些属管理员资产，避免用户改坏别人共用的配置）。
     *
     * @return bool 是否生效
     */
    public function updateScoped(string $key, array $data, ?int $scope = null): bool
    {
        $scope ??= Tenant::scope();

        if (!$this->writable($key, $scope)) {
            return false;
        }

        $this->update($key, $data);
        return true;
    }

    /**
     * 新增自定义供应商实例（kind=custom，可多个）。
     *
     * @param int|null $userId 归属用户；null → 取当前作用域（管理员=0，普通用户=本人）
     */
    public function createCustom(string $key, string $displayName, array $options = [], ?int $userId = null): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->insert([
            'provider_key'  => $key,
            'display_name'  => $displayName,
            'kind'          => 'custom',
            'enabled'       => 1,
            'login_type'    => 'API_KEY',
            'default_model' => '',
            'hosts'         => json_encode([], JSON_UNESCAPED_UNICODE),
            'options'       => json_encode($options, JSON_UNESCAPED_UNICODE),
            'sort_order'    => 100,
            'user_id'       => $userId ?? Tenant::writeId(),
        ]);
        $this->cache = null;
    }

    /**
     * 删除自定义供应商实例（作用域感知）。
     *
     * 普通用户只能删**自己创建**的实例；删全局实例（user_id=0）return false。
     *
     * @return bool 是否真的删掉了
     */
    public function deleteCustom(string $key, ?int $userId = null): bool
    {
        $this->ensureSchema();

        $config = $this->find($key);
        if ($config === null || ($config['kind'] ?? '') !== 'custom') {
            return false;
        }

        $scope = $userId ?? Tenant::scope();
        $owner = (int) ($config['user_id'] ?? 0);
        if ($scope !== null && $owner !== $scope) {
            return false;
        }

        $affected = $this->db->table(self::TABLE)
            ->where('provider_key', $key)
            ->where('kind', 'custom')
            ->delete();

        $this->cache = null;
        return $affected > 0;
    }

    // ---------------------------------------------------------
    // 调用前缀（alias）
    //
    // 背景：对外 `/v1/models` 的 id 形如 `前缀/模型名`，其中「前缀」默认就是
    // provider_key。但用户自建实例的 key 被强制带上作用域段
    // （`custom_u12_myapi`），在客户端下拉框里又长又难认；多个自建实例并存时
    // 更是分不清谁是谁。
    //
    // 于是允许单独指定一个「调用前缀」：只作为**对外别名**存在，
    // 不参与任何鉴权/归属判定，provider_key 仍是唯一真相。
    // 未设置时完全回落到 provider_key —— 历史行为零变化。
    // ---------------------------------------------------------

    /** 调用前缀允许的字符集：小写字母/数字开头，长度 1~32，可含 `_` `-` */
    public const ALIAS_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    /** 该供应商对外使用的调用前缀（未设置则回落 provider_key） */
    public function aliasOf(string $key): string
    {
        $alias = trim((string) ($this->find($key)['options']['alias'] ?? ''));

        return $alias !== '' ? $alias : $key;
    }

    /**
     * 调用前缀 → 真实 provider_key。
     *
     * 两条路都认（顺序很重要）：
     *   1. 前缀本身就是 provider_key → 直接命中（保证「配了别名之后，
     *      老的 `provider_key/模型` 调用名依然可用」，不破坏已有客户端）；
     *   2. 否则遍历 alias 表反查。
     *
     * @return string|null 解析不到返回 null
     */
    public function resolvePrefix(string $prefix): ?string
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return null;
        }

        if ($this->exists($prefix)) {
            return $prefix;
        }

        foreach ($this->all() as $key => $config) {
            $alias = trim((string) ($config['options']['alias'] ?? ''));
            if ($alias !== '' && $alias === $prefix) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * 前缀是否已被占用（既查 provider_key，也查其他供应商的 alias）。
     *
     * ⚠️ 必须同时查两边：alias 与 provider_key 共用同一个命名空间，
     *    否则「别名 myapi」与「供应商 myapi」会撞车，resolvePrefix()
     *    的短路顺序会让其中一个永远解析不到。
     *
     * @param string $except 排除自身（编辑时用）
     */
    public function prefixTaken(string $prefix, string $except = ''): bool
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return false;
        }

        foreach ($this->all() as $key => $config) {
            if ((string) $key === $except) {
                continue;
            }
            if ((string) $key === $prefix) {
                return true;
            }
            if (trim((string) ($config['options']['alias'] ?? '')) === $prefix) {
                return true;
            }
        }

        return false;
    }

    /**
     * 校验并归一化一个用户提交的调用前缀。
     *
     * @return string|null 合法返回归一化后的值；非法返回 null（调用方给报错）
     */
    public function normalizeAlias(string $alias, string $except = ''): ?string
    {
        $alias = strtolower(trim($alias));
        if ($alias === '') {
            return '';   // 允许显式清空 = 回落到 provider_key
        }
        if (!preg_match(self::ALIAS_PATTERN, $alias)) {
            return null;
        }
        if ($this->prefixTaken($alias, $except)) {
            return null;
        }

        return $alias;
    }

    public function refresh(): void
    {
        $this->cache = null;
    }
}