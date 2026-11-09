<?php
/**
 * 模型目录仓储（对应 LY1/y 的模型开关与聚合目录）
 *
 * ── 作用域（多租户隔离）────────────────────────────────────────────
 * 表结构：`user_id`（0=管理员下发的全局/内置模型，>0=某用户的私有模型）。
 *
 * 可见性（`scopeFilter()` 统一实现）：
 *  - 管理员（Tenant::scope() === null）→ 不过滤，可见全部行；
 *  - 普通用户（scope = uid）        → 只见 `user_id = 0` **或** `user_id = uid` 的行。
 *
 * 为什么必须隔离：`custom` 供应商是所有用户共用的同一个 provider_key，每个账号
 * 各自带 base_url（用户自填的私有中转站）。不隔离的话，用户 A 同步私有端点后，
 * 模型名会写进公共目录 —— B 既能看到 A 的私有模型清单，又能对同一行 enabled=0
 * 把 A 的模型一并停掉。
 *
 * 写入（`Tenant::writeId()`）：管理员=0（全局），普通用户=本人。
 * ⚠️ 唯一键 (provider_key, model_id, user_id)，同名模型可按用户各存一行。
 *
 * 去重：普通用户视角下同 (provider_key, model_id) 可能同时存在全局行与本人行
 * （例如管理员先同步、用户后同步），此时**只保留本人行**（能力/倍率/开关都是
 * 自己采集的），避免同一模型在列表里出现两次。管理员视角不去重，便于排查。
 *
 * 越权防线：普通用户同步时**绝不写全局行**。若目标模型已有全局行，视为
 * 「已可用」直接跳过，不复制、不覆盖。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\QueryBuilder;
use App\Support\Schema;
use App\Support\Tenant;

class ModelRepository
{
    private const TABLE = 'aigw_model';

    /**
     * 批量同步时单条 SQL 携带的行数上限。
     *
     * 300 行 × 14 列 ≈ 4200 个占位符，远低于 MySQL 原生预处理 65535 的上限，
     * 同时保证几千个模型只需要个位数条语句（见 `batchUpsert()`）。
     */
    private const SYNC_CHUNK = 300;

    /** 同步时要写回的业务列（也是冲突时要更新的列，见 `batchUpsert()`） */
    private const SYNC_PAYLOAD_COLUMNS = [
        'display_name', 'capabilities', 'rate', 'rate_source', 'fee_level',
        'entitlement_cost', 'context_window', 'max_tokens', 'multimodal',
        'reasoning', 'synced_at',
    ];

    private bool $schemaChecked = false;

    public function __construct(private Database $db) {}

    /** 老库升级：幂等补齐模型表的 user_id 列与作用域唯一键 */
    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $this->schemaChecked = true;

        Schema::ensureColumns($this->db, self::TABLE, Schema::MODEL_COLUMNS);
        Schema::ensureModelScopedUnique($this->db);
    }

    /** 把「当前作用域」拼进查询（普通用户 = 全局行 OR 本人行；管理员不过滤） */
    private function scopeFilter(QueryBuilder $query): QueryBuilder
    {
        $scope = Tenant::scope();
        if ($scope === null) {
            return $query;
        }

        return $query->whereRaw('(`user_id` = 0 OR `user_id` = ?)', [$scope]);
    }

    public function all(?string $providerKey = null): array
    {
        $this->ensureSchema();

        $query = $this->db->table(self::TABLE);
        if ($providerKey !== null) {
            $query->where('provider_key', $providerKey);
        }
        $query = $this->scopeFilter($query);

        $rows = $query->orderBy('sort_order', 'ASC')->get();
        $models = array_map(fn(array $row) => $this->normalize($row), $rows);

        // 普通用户：同 (provider_key, model_id) 只留本人行（见类注释「去重」）
        return Tenant::scope() === null ? $models : $this->dedupeOwnFirst($models);
    }

    /**
     * 同 (provider_key, model_id) 折叠为一行，优先保留本人行。
     *
     * 输入已按 sort_order 升序，折叠时保持首次出现的顺序，保证列表顺序稳定。
     *
     * @param  array<int,array<string,mixed>> $models
     * @return array<int,array<string,mixed>>
     */
    private function dedupeOwnFirst(array $models): array
    {
        $scope = Tenant::scope();
        $picked = [];   // key => 下标
        $out = [];

        foreach ($models as $model) {
            $key = $model['provider_key'] . '::' . $model['model_id'];
            $isOwn = (int) $model['user_id'] === $scope;

            if (!isset($picked[$key])) {
                $picked[$key] = count($out);
                $out[] = $model;
                continue;
            }

            // 已有一行：本人行优先替换掉全局行；同优先级保留先出现的
            if ($isOwn && (int) $out[$picked[$key]]['user_id'] !== $scope) {
                $out[$picked[$key]] = $model;
            }
        }

        return $out;
    }

    /** @return string[] 已启用的模型 ID */
    public function enabledModelIds(string $providerKey): array
    {
        $this->ensureSchema();

        $query = $this->db->table(self::TABLE)
            ->where('provider_key', $providerKey)
            ->where('enabled', 1);
        $query = $this->scopeFilter($query);

        $rows = $query->orderBy('sort_order', 'ASC')->get();
        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (string) $row['model_id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * 取单行（当前作用域内）。
     *
     * 同名模型可能分属全局与本人，按「本人行优先」返回，与列表去重口径一致。
     */
    public function find(string $providerKey, string $modelId): ?array
    {
        $this->ensureSchema();

        $ids = $this->candidateIds($providerKey, $modelId);
        return $ids === [] ? null : $this->findById($ids[0]);
    }

    public function isEnabled(string $providerKey, string $modelId): bool
    {
        $model = $this->find($providerKey, $modelId);
        return $model !== null && $model['enabled'];
    }

    /**
     * 当前作用域内该模型所有可见行的主键，按优先级排序（本人行 > 全局行）。
     *
     * @return int[]
     */
    private function candidateIds(string $providerKey, string $modelId): array
    {
        $query = $this->db->table(self::TABLE)
            ->where('provider_key', $providerKey)
            ->where('model_id', $modelId);
        $query = $this->scopeFilter($query);

        $rows = $query->get();
        if (empty($rows)) {
            return [];
        }

        $scope = Tenant::scope();
        usort($rows, function (array $a, array $b) use ($scope): int {
            if ($scope === null) {
                return ((int) $a['id']) <=> ((int) $b['id']);
            }
            $aOwn = (int) $a['user_id'] === $scope ? 0 : 1;
            $bOwn = (int) $b['user_id'] === $scope ? 0 : 1;
            return $aOwn !== $bOwn ? $aOwn <=> $bOwn : ((int) $a['id']) <=> ((int) $b['id']);
        });

        return array_map(fn(array $row) => (int) $row['id'], $rows);
    }

    private function normalize(array $row): array
    {
        $caps = $row['capabilities'] ?? null;
        if (is_string($caps)) {
            $caps = json_decode($caps, true);
        }

        return [
            'id'               => (int) $row['id'],
            'provider_key'     => (string) $row['provider_key'],
            'model_id'         => (string) $row['model_id'],
            'display_name'     => (string) ($row['display_name'] ?: $row['model_id']),
            'enabled'          => (bool) $row['enabled'],
            'is_custom'        => (bool) ($row['is_custom'] ?? false),
            'user_id'          => (int) ($row['user_id'] ?? 0),
            'capabilities'     => is_array($caps) ? $caps : ['stream'],
            'sort_order'       => (int) $row['sort_order'],
            'rate'             => isset($row['rate']) && $row['rate'] !== null ? (float) $row['rate'] : null,
            'rate_source'      => (string) ($row['rate_source'] ?? ''),
            'fee_level'        => isset($row['fee_level']) && $row['fee_level'] !== null ? (int) $row['fee_level'] : null,
            'entitlement_cost' => isset($row['entitlement_cost']) && $row['entitlement_cost'] !== null ? (float) $row['entitlement_cost'] : null,
            'context_window'   => isset($row['context_window']) && $row['context_window'] !== null ? (int) $row['context_window'] : null,
            'max_tokens'       => isset($row['max_tokens']) && $row['max_tokens'] !== null ? (int) $row['max_tokens'] : null,
            'multimodal'       => (bool) ($row['multimodal'] ?? false),
            'reasoning'        => (bool) ($row['reasoning'] ?? false),
            'synced_at'        => $row['synced_at'] ?? null,
        ];
    }

    /**
     * 模型倍率映射（仅返回已采集到倍率的模型）
     *
     * 键为 "provider_key::model_id"，值为 float 倍率。
     *
     * ⚠️ 倍率用于**费用换算**，必须与列表口径一致（作用域过滤 + 去重），
     *    否则同名模型会因全局/私有两份倍率不同而算出两个价格。
     *
     * @return array<string,float>
     */
    public function rateMap(): array
    {
        $this->ensureSchema();

        $query = $this->db->table(self::TABLE)->select('provider_key', 'model_id', 'rate', 'user_id', 'id');
        $query = $this->scopeFilter($query);
        $query = $query->whereRaw('`rate` IS NOT NULL');

        $rows = $query->get();
        $scoped = array_map(fn(array $r) => [
            'provider_key' => $r['provider_key'],
            'model_id'     => $r['model_id'],
            'rate'         => $r['rate'],
            'user_id'      => (int) $r['user_id'],
            'id'           => (int) $r['id'],
        ], $rows);

        if (Tenant::scope() !== null) {
            $scoped = $this->dedupeOwnFirst($scoped);
        }

        $map = [];
        foreach ($scoped as $row) {
            if ($row['rate'] === null) {
                continue;
            }
            $map[$row['provider_key'] . '::' . $row['model_id']] = (float) $row['rate'];
        }

        return $map;
    }

    /** 单个模型的倍率，未采集时返回 null */
    public function rate(string $providerKey, string $modelId): ?float
    {
        $model = $this->find($providerKey, $modelId);
        return $model === null || $model['rate'] === null ? null : (float) $model['rate'];
    }

    /**
     * 批量同步某供应商的模型目录（含上游倍率与详情，保留原有开关状态）
     *
     * ⚠️ 性能契约（2026-10-04 修复「模型上千个时点全量同步会卡住」）：
     *   实现上**不再逐行读写**。旧实现每个模型要发 1~2 条 SQL（新增 = SELECT 查重
     *   + INSERT，已有 = UPDATE），且没有事务包裹 —— 3000 个模型就是上万次网络
     *   往返 + 上万次 autocommit 刷盘，请求被拖到分钟级直至超时。现在改成
     *   「先按作用域分类 → 一个事务里每 300 行一条 `INSERT ... ON DUPLICATE KEY
     *   UPDATE`」，总语句数降到个位数。
     *
     * @param list<array<string,mixed>> $catalog Provider::modelCatalog() 返回结构
     * @return array{created:int,updated:int}
     */
    public function syncCatalog(string $providerKey, array $catalog): array
    {
        $this->ensureSchema();

        if (empty($catalog)) {
            return ['created' => 0, 'updated' => 0];
        }

        $scope = Tenant::scope();
        $userId = Tenant::writeId();

        // 作用域内的已有行。
        //
        // ⚠️ 分成两张表，不能只按 model_id 存——管理员视角能同时看到「全局行」和
        //    「某用户的私有行」，若同 model_id 的两行互相覆盖，就会把管理员同步
        //    落到某个用户的私有行上（旧实现按 sort_order 覆盖 map，确实会踩）。
        //    · $own  → 归属「本次写入者」的行：命中即更新；
        //    · $seen → 作用域内出现过的 model_id：普通用户据此跳过全局行。
        $own = [];
        $seen = [];
        foreach ($this->all($providerKey) as $model) {
            $seen[$model['model_id']] = true;
            if ((int) $model['user_id'] === $userId) {
                $own[$model['model_id']] = $model;
            }
        }

        $now = date('Y-m-d H:i:s');
        $created = 0;
        $updated = 0;
        $sort = 1;
        /** @var list<array<string,mixed>> $rows 全部行共用同一套键与顺序 */
        $rows = [];

        foreach ($catalog as $item) {
            if (!is_array($item)) {
                continue;
            }
            $modelId = trim((string) ($item['model_id'] ?? ''));
            if ($modelId === '') {
                continue;
            }

            $payload = $this->catalogPayload($item, $modelId, $now);

            if (isset($own[$modelId])) {
                $row = $own[$modelId];
                // enabled / is_custom / sort_order 不在更新列里（见 batchUpsert 的
                // $updateColumns），这里带上真实值只为凑齐统一的列集合。
                $payload['enabled']    = (int) !empty($row['enabled']);
                $payload['is_custom']  = (int) !empty($row['is_custom']);
                $payload['sort_order'] = (int) $row['sort_order'];
                $updated++;
            } elseif ($scope !== null && isset($seen[$modelId])) {
                // 普通用户遇到全局行：该模型对他已可见，跳过（不复制、不覆盖全局）
                $sort++;
                continue;
            } else {
                // 新增行才写开关/自定义标记/排序
                $payload['enabled']    = 1;
                $payload['is_custom']  = 0;
                $payload['sort_order'] = $sort;
                $created++;
            }

            $payload['provider_key'] = $providerKey;
            $payload['model_id']     = $modelId;
            $payload['user_id']      = $userId;
            $rows[]                  = $payload;
            $sort++;
        }

        if ($rows !== []) {
            $this->db->transaction(fn() => $this->batchUpsert($rows, self::SYNC_PAYLOAD_COLUMNS));
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * 批量同步某供应商的模型（保留原有开关状态）
     *
     * 与 `syncCatalog()` 同一套性能契约：只补新增行，一次批量落库。
     *
     * @param string[] $modelIds
     */
    public function sync(string $providerKey, array $modelIds): int
    {
        $this->ensureSchema();

        if (empty($modelIds)) {
            return 0;
        }

        $userId = Tenant::writeId();
        $scope  = Tenant::scope();

        // 同 syncCatalog()：按归属拆表。管理员不该因为「某用户的私有行占了同名」
        // 就放弃建自己的全局行。
        $own = [];
        $seen = [];
        foreach ($this->all($providerKey) as $model) {
            $seen[$model['model_id']] = true;
            if ((int) $model['user_id'] === $userId) {
                $own[$model['model_id']] = $model;
            }
        }

        $rows = [];
        $sort = 1;

        foreach ($modelIds as $modelId) {
            $modelId = trim((string) $modelId);
            if ($modelId === '') {
                continue;
            }

            // 已有行（本人的 / 普通用户可见的全局行）一律保持原样，只补新增
            if (isset($own[$modelId]) || ($scope !== null && isset($seen[$modelId]))) {
                $sort++;
                continue;
            }

            $rows[] = [
                'provider_key' => $providerKey,
                'model_id'     => $modelId,
                'user_id'      => $userId,
                'display_name' => $modelId,
                'capabilities' => json_encode(['stream'], JSON_UNESCAPED_UNICODE),
                'enabled'      => 1,
                'is_custom'    => 0,
                'sort_order'   => $sort++,
            ];
        }

        if ($rows !== []) {
            // 并发下同名行可能已被别处插入：冲突时做 no-op 更新（等价 INSERT IGNORE，
            // 但不会像 IGNORE 那样连其它 SQL 错误一起吞掉）。
            $this->db->transaction(fn() => $this->batchUpsert($rows, ['model_id']));
        }

        return count($rows);
    }

    /**
     * 批量 upsert（单条 SQL 多行），依赖唯一键 `uk_model (provider_key, model_id, user_id)`。
     *
     * 冲突时只更新 `$updateColumns` 里的列 —— 因此已存在行的 `enabled` / `is_custom` /
     * `sort_order` **不会被覆盖**，用户手工开关的模型状态得以保留。
     *
     * @param list<array<string,mixed>> $rows          每行必须是同一套键、同一顺序
     * @param string[]                  $updateColumns 唯一键冲突时要更新的列
     */
    private function batchUpsert(array $rows, array $updateColumns): void
    {
        foreach (array_chunk($rows, self::SYNC_CHUNK) as $chunk) {
            $columns = array_keys($chunk[0]);
            $cols = implode(', ', array_map(fn(string $c): string => "`{$c}`", $columns));
            $rowSql = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

            $placeholders = [];
            $bindings = [];
            foreach ($chunk as $row) {
                $placeholders[] = $rowSql;
                foreach ($columns as $column) {
                    $bindings[] = $row[$column] ?? null;
                }
            }

            $updates = implode(', ', array_map(fn(string $c): string => "`{$c}` = VALUES(`{$c}`)", $updateColumns));

            $this->db->statement(
                'INSERT INTO `' . self::TABLE . "` ({$cols}) VALUES " . implode(', ', $placeholders)
                . " ON DUPLICATE KEY UPDATE {$updates}",
                $bindings
            );
        }
    }

    /** catalog 单项 → 落库字段 */
    private function catalogPayload(array $item, string $modelId, string $now): array
    {
        $display = trim((string) ($item['display_name'] ?? '')) ?: $modelId;
        $caps = $item['capabilities'] ?? ['stream'];

        return [
            'display_name'     => $display,
            'capabilities'     => json_encode(is_array($caps) ? array_values($caps) : ['stream'], JSON_UNESCAPED_UNICODE),
            'rate'             => isset($item['rate']) && $item['rate'] !== null ? (float) $item['rate'] : null,
            'rate_source'      => (string) ($item['rate_source'] ?? ''),
            'fee_level'        => isset($item['fee_level']) && $item['fee_level'] !== null ? (int) $item['fee_level'] : null,
            'entitlement_cost' => isset($item['entitlement_cost']) && $item['entitlement_cost'] !== null ? (float) $item['entitlement_cost'] : null,
            'context_window'   => isset($item['context_window']) && $item['context_window'] !== null ? (int) $item['context_window'] : null,
            'max_tokens'       => isset($item['max_tokens']) && $item['max_tokens'] !== null ? (int) $item['max_tokens'] : null,
            'multimodal'       => !empty($item['multimodal']) ? 1 : 0,
            'reasoning'        => !empty($item['reasoning']) ? 1 : 0,
            'synced_at'        => $now,
        ];
    }

    /**
     * 手工添加模型。
     *
     * 普通用户：若已有**本人行** → 更新；若只有**全局行** → 另存一份本人行
     * （用户显式添加，说明他要自己控制开关，不应改到公共目录）。
     * 管理员：维持原行为（有则更新，无则插入全局行）。
     */
    public function add(string $providerKey, string $modelId, string $displayName = '', bool $enabled = true, bool $isCustom = true): int
    {
        $this->ensureSchema();

        $scope = Tenant::scope();
        $existing = $this->find($providerKey, $modelId);

        if ($existing !== null) {
            $isOwn = $scope === null || (int) $existing['user_id'] === $scope;
            if ($isOwn) {
                $this->update($existing['id'], ['enabled' => $enabled, 'display_name' => $displayName ?: $modelId]);
                return (int) $existing['id'];
            }
        }

        return $this->insertScoped($providerKey, $modelId, [
            'display_name' => $displayName ?: $modelId,
            'enabled'      => $enabled ? 1 : 0,
            'is_custom'    => $isCustom ? 1 : 0,
            'capabilities' => json_encode(['stream'], JSON_UNESCAPED_UNICODE),
        ], Tenant::writeId(), 999);
    }

    /** 按作用域插入一行（同名同归属已存在时退化为更新，避免撞唯一键中断整批同步） */
    private function insertScoped(string $providerKey, string $modelId, array $payload, int $userId, int $sortOrder): int
    {
        $payload['provider_key'] = $providerKey;
        $payload['model_id']     = $modelId;
        $payload['user_id']      = $userId;
        $payload['sort_order']   = $payload['sort_order'] ?? $sortOrder;
        $payload['enabled']      = $payload['enabled'] ?? 1;
        $payload['is_custom']    = $payload['is_custom'] ?? 0;
        $payload['display_name'] = $payload['display_name'] ?? $modelId;
        $payload['capabilities'] = $payload['capabilities'] ?? json_encode(['stream'], JSON_UNESCAPED_UNICODE);

        $dup = $this->db->table(self::TABLE)
            ->where('provider_key', $providerKey)
            ->where('model_id', $modelId)
            ->where('user_id', $userId)
            ->first();

        if ($dup !== null) {
            unset($payload['provider_key'], $payload['model_id'], $payload['user_id']);
            $this->db->table(self::TABLE)->where('id', (int) $dup['id'])->update($payload);
            return (int) $dup['id'];
        }

        return $this->db->table(self::TABLE)->insert($payload);
    }

    public function update(int $id, array $data): void
    {
        $this->ensureSchema();

        $allowed = ['enabled', 'display_name', 'sort_order', 'model_id', 'rate'];
        $payload = array_intersect_key($data, array_flip($allowed));
        if (isset($payload['enabled'])) {
            $payload['enabled'] = $payload['enabled'] ? 1 : 0;
        }
        if (array_key_exists('rate', $payload)) {
            $payload['rate'] = ($payload['rate'] === null || $payload['rate'] === '') ? null : (float) $payload['rate'];
        }
        if (empty($payload)) {
            return;
        }
        $this->db->table(self::TABLE)->where('id', $id)->update($payload);
    }

    /**
     * 作用域感知的字段更新（供控制器使用）。
     *
     *  · 管理员 / 本人行 → 直接改该行；
     *  · 用户 + 全局行   → 落一份本人覆盖行（仅覆盖 display_name / rate / enabled），
     *    公共目录不受影响，用户仍能自定义对自己生效的展示与开关；
     *  · 用户 + 他人行   → 拒绝（false）。
     *
     * ⚠️ 落覆盖行时**不含 model_id / sort_order**：改名属于结构性改动，
     *    普通用户无权在公共目录改名，避免把上游模型 ID 改坏影响他人。
     *
     * @return bool 是否生效
     */
    public function updateScoped(int $id, array $data): bool
    {
        $this->ensureSchema();

        $row = $this->db->table(self::TABLE)->where('id', $id)->first();
        if ($row === null) {
            return false;
        }

        $scope = Tenant::scope();
        $rowUserId = (int) ($row['user_id'] ?? 0);

        if ($scope === null || $rowUserId === $scope) {
            $this->update($id, $data);
            return true;
        }

        if ($rowUserId !== 0) {
            return false;
        }

        // 全局行 → 落本人覆盖行（仅取可个性化的三个字段，其余沿用全局行）
        $payload = [];
        if (array_key_exists('display_name', $data)) {
            $payload['display_name'] = (string) $data['display_name'];
        }
        if (array_key_exists('enabled', $data)) {
            $payload['enabled'] = !empty($data['enabled']) ? 1 : 0;
        }
        if (array_key_exists('rate', $data)) {
            $rate = $data['rate'];
            $payload['rate'] = ($rate === null || $rate === '') ? null : (float) $rate;
        }
        if ($payload === []) {
            return false;
        }

        $caps = $row['capabilities'] ?? null;
        if (is_array($caps)) {
            $caps = json_encode(array_values($caps), JSON_UNESCAPED_UNICODE);
        } elseif (!is_string($caps) || $caps === '') {
            $caps = json_encode(['stream'], JSON_UNESCAPED_UNICODE);
        }

        // 覆盖行以全局行为基底，再叠加用户改动
        $payload += [
            'display_name'     => (string) ($row['display_name'] ?: $row['model_id']),
            'enabled'          => (int) ($row['enabled'] ?? 1),
            'is_custom'        => (int) ($row['is_custom'] ?? 0),
            'capabilities'     => $caps,
            'rate'             => $row['rate'] ?? null,
            'rate_source'      => (string) ($row['rate_source'] ?? ''),
            'fee_level'        => $row['fee_level'] ?? null,
            'entitlement_cost' => $row['entitlement_cost'] ?? null,
            'context_window'   => $row['context_window'] ?? null,
            'max_tokens'       => $row['max_tokens'] ?? null,
            'multimodal'       => (int) ($row['multimodal'] ?? 0),
            'reasoning'        => (int) ($row['reasoning'] ?? 0),
            'synced_at'        => $row['synced_at'] ?? null,
        ];

        $this->insertScoped((string) $row['provider_key'], (string) $row['model_id'], $payload, $scope, (int) ($row['sort_order'] ?? 999));

        return true;
    }

    /**
     * 批量切换某供应商模型的启用状态。
     *
     * ⚠️ 按作用域限定：管理员切全部；普通用户**只切自己名下的行**
     *    （`is_custom = 0` 的过滤保留，用于排除手工添加项）。
     */
    public function setEnabledForProvider(string $providerKey, bool $enabled): void
    {
        $this->ensureSchema();

        $scope = Tenant::scope();
        if ($scope === null) {
            $this->db->statement(
                'UPDATE `' . self::TABLE . '` SET `enabled` = ? WHERE `provider_key` = ? AND `is_custom` = 0',
                [$enabled ? 1 : 0, $providerKey]
            );
            return;
        }

        $this->db->statement(
            'UPDATE `' . self::TABLE . '` SET `enabled` = ? WHERE `provider_key` = ? AND `is_custom` = 0 AND `user_id` = ?',
            [$enabled ? 1 : 0, $providerKey, $scope]
        );
    }

    /**
     * 按作用域切换**单个**模型的启用状态。
     *
     * 与 `setEnabledForProvider` 的批量口径一致，但支持「用户点全局行的开关」：
     *  · 管理员            → 直接改该行；
     *  · 用户 + 本人行      → 直接改；
     *  · 用户 + 全局行      → **落一份本人覆盖行**（复制展示字段，enabled 取目标值），
     *    既不污染公共目录，又能真正对自己生效（否则点开关无反应）；
     *  · 用户 + 他人私有行  → 拒绝（返回 false）。
     *
     * @return bool 是否生效（权限不足/行不存在时 false）
     */
    public function setEnabledScoped(int $id, bool $enabled): bool
    {
        $this->ensureSchema();

        $row = $this->db->table(self::TABLE)->where('id', $id)->first();
        if ($row === null) {
            return false;
        }

        $scope = Tenant::scope();
        $rowUserId = (int) ($row['user_id'] ?? 0);

        if ($scope === null || $rowUserId === $scope) {
            $this->db->table(self::TABLE)->where('id', $id)->update(['enabled' => $enabled ? 1 : 0]);
            return true;
        }

        if ($rowUserId !== 0) {
            return false;
        }

        // 全局行：为本人落一份覆盖行。capabilities 可能是 JSON 串或字符串，统一成 JSON。
        $caps = $row['capabilities'] ?? null;
        if (is_array($caps)) {
            $caps = json_encode(array_values($caps), JSON_UNESCAPED_UNICODE);
        } elseif (!is_string($caps) || $caps === '') {
            $caps = json_encode(['stream'], JSON_UNESCAPED_UNICODE);
        }

        $this->insertScoped((string) $row['provider_key'], (string) $row['model_id'], [
            'display_name'   => (string) ($row['display_name'] ?: $row['model_id']),
            'enabled'        => $enabled ? 1 : 0,
            'is_custom'      => (int) ($row['is_custom'] ?? 0),
            'capabilities'   => $caps,
            'rate'           => $row['rate'] ?? null,
            'rate_source'    => (string) ($row['rate_source'] ?? ''),
            'fee_level'      => $row['fee_level'] ?? null,
            'entitlement_cost' => $row['entitlement_cost'] ?? null,
            'context_window' => $row['context_window'] ?? null,
            'max_tokens'     => $row['max_tokens'] ?? null,
            'multimodal'     => (int) ($row['multimodal'] ?? 0),
            'reasoning'      => (int) ($row['reasoning'] ?? 0),
            'synced_at'      => $row['synced_at'] ?? null,
        ], $scope, (int) ($row['sort_order'] ?? 999));

        return true;
    }

    /** 按主键判断当前作用域内是否存在（供控制器做 404 校验） */
    public function existsById(int $id): bool
    {
        return $this->findById($id) !== null;
    }

    /** 按主键取（含作用域校验：越权行一律按「不存在」返回，防 IDOR） */
    public function findById(int $id): ?array
    {
        $this->ensureSchema();

        if ($id <= 0) {
            return null;
        }
        $row = $this->db->table(self::TABLE)->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        if (!$this->visibleRow($row)) {
            return null;
        }

        return $this->normalize($row);
    }

    /** 当前作用域下该行是否可见（用户：全局行或本人行） */
    private function visibleRow(array $row): bool
    {
        $scope = Tenant::scope();
        if ($scope === null) {
            return true;
        }
        $rowUserId = (int) ($row['user_id'] ?? 0);
        return $rowUserId === 0 || $rowUserId === $scope;
    }

    /** 当前作用域下该行是否可写（用户只能写本人行；全局行属管理员下发，不可改） */
    public function writableById(int $id): bool
    {
        $scope = Tenant::scope();
        if ($scope === null) {
            return true;
        }
        $row = $this->db->table(self::TABLE)->where('id', $id)->first();
        if ($row === null) {
            return false;
        }
        return (int) ($row['user_id'] ?? 0) === $scope;
    }

    public function delete(int $id): void
    {
        $this->ensureSchema();
        $this->db->table(self::TABLE)->where('id', $id)->delete();
    }

    public function deleteByProvider(string $providerKey): void
    {
        $this->ensureSchema();
        $this->db->table(self::TABLE)->where('provider_key', $providerKey)->delete();
    }

    /** 删用户时级联清理其私有模型行（全局行不受影响） */
    public function deleteByUserId(int $userId): int
    {
        $this->ensureSchema();
        if ($userId <= 0) {
            return 0;
        }
        return $this->db->table(self::TABLE)->where('user_id', $userId)->delete();
    }
}
