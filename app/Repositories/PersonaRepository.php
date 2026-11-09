<?php
/**
 * 思维注入仓储（对应 pojia-next 的「思维 + 护照 + 靶点状态」三层）
 *
 * 作用域约定（与 Tenant / PromptTemplateRepository 一致）：
 *  - user_id = 0  → 管理员维护的全局思维，对所有网关调用生效
 *  - user_id > 0  → 某普通用户自建思维，仅对该用户自己的调用生效
 *
 * 每个作用域内至多有一个 is_default=1 的思维，作为「自动注入」使用；
 * 没有默认思维时回退到第一个启用中的思维。
 *
 * 三张表分工：
 *  - aigw_persona          思维本体（正文 / 模式 / 封条 / 自证口令）
 *  - aigw_persona_revision 修订快照 —— 每次保存前留一份，即「改前备份」
 *  - aigw_persona_target   靶点状态 —— 每个供应商一行，记录注入次数与最近错误
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;
use App\Support\Seal;

class PersonaRepository
{
    public const TABLE = 'aigw_persona';
    private const REV = 'aigw_persona_revision';
    private const TGT = 'aigw_persona_target';
    private const UTG = 'aigw_persona_user_target';
    private const UEB = 'aigw_persona_user_enable';

    public const MODES = ['prepend', 'append', 'replace'];

    /** 需要按需补齐的列（老库升级路径，CREATE TABLE 不会为已存在的表加列） */
    private const ADDED_COLUMNS = [
        // 精准生效：模型白名单（供应商维度已由 aigw_persona_target 承担）
        'models' => "TEXT NULL COMMENT '生效模型 JSON 数组（模型 ID，支持尾部 * 前缀通配）；空=全部模型'",
    ];

    private bool $schemaChecked = false;

    public function __construct(private Database $db) {}

    /**
     * 运行时结构自愈：老库不重跑 schema.sql，首次访问时补齐三张表。
     * 与 LogRepository 的做法一致，避免「代码升级了但库里没表」直接 500。
     */
    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $this->schemaChecked = true;

        Schema::ensure($this->db);
        Schema::ensureColumns($this->db, self::TABLE, self::ADDED_COLUMNS);
    }

    /* ── 思维本体 ────────────────────────────────────────────── */

    /**
     * 列出某作用域下的思维
     *
     * @param int|null $userId        null=全部（管理员视图）
     * @param bool     $includeGlobal 为 true 时把全局思维一并返回
     * @return list<array>
     */
    public function all(?int $userId = null, bool $includeGlobal = false): array
    {
        $this->ensureSchema();

        $query = $this->db->table(self::TABLE);

        if ($userId !== null) {
            if ($includeGlobal && $userId > 0) {
                $query->whereRaw('(`user_id` = ? OR `user_id` = 0)', [$userId]);
            } else {
                $query->where('user_id', $userId);
            }
        }

        $rows = $query->orderBy('is_default', 'DESC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        return array_map(fn(array $row) => $this->normalize($row), $rows);
    }

    public function find(int $id): ?array
    {
        $this->ensureSchema();

        $row = $this->db->table(self::TABLE)->where('id', $id)->first();
        return $row ? $this->normalize($row) : null;
    }

    /** 当前作用域下「自动注入」应使用的思维（默认思维优先，否则首个启用的思维） */
    public function active(?int $userId): ?array
    {
        $row = $this->db->table(self::TABLE)
            ->where('user_id', $userId ?? 0)
            ->where('enabled', 1)
            ->orderBy('is_default', 'DESC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->first();

        return $row ? $this->normalize($row) : null;
    }

    /**
     * 指定靶点（可选指定模型）应使用的思维。
     *
     * 规则（与 PersonaInjector::status() 的诊断语义保持一致）：
     *  1. 有默认思维 → **默认思维说了算**。它没覆盖这个靶点/模型（不在白名单 / 被显式关闭），
     *     就返回 null（该靶点不注入），而不是"换另一份思维顶上"。
     *     否则「在默认思维里关掉某靶点」会被别的思维静默接管，让人以为关掉了其实没有。
     *  2. 没有默认思维 → 按顺序挑第一份同时覆盖该靶点与模型的思维，支持"一个供应商一个思维"。
     *
     * @param string|null $model    本次解析出的模型 ID；null = 不限定模型（仅按靶点判定）
     * @param list<int>|null $allowIds 用户级开关白名单（null = 不限）；
     *                                 给普通用户注入时传「该用户已开启的思维 id」，
     *                                 空数组 = 一份都没开 → 不注入（开箱默认全关）。
     */
    public function pickFor(?int $userId, string $target, ?string $model = null, ?array $allowIds = null): ?array
    {
        $rows = $this->db->table(self::TABLE)
            ->where('user_id', $userId ?? 0)
            ->where('enabled', 1)
            ->orderBy('is_default', 'DESC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        $candidates = array_map(fn(array $row) => $this->normalize($row), $rows);

        if ($allowIds !== null) {
            $allow = array_map('intval', $allowIds);
            $candidates = array_values(array_filter(
                $candidates,
                fn(array $p) => in_array((int) $p['id'], $allow, true)
            ));
        }

        $covers = function (array $persona) use ($target, $model): bool {
            if (!$this->targetEnabled($persona, $target)) {
                return false;
            }
            // 模型白名单：未指定模型时跳过该维度（保持旧调用语义）
            return $model === null || $this->modelEnabled($persona, $model);
        };

        foreach ($candidates as $persona) {
            if ($persona['is_default']) {
                return $covers($persona) ? $persona : null;
            }
        }

        foreach ($candidates as $persona) {
            if ($covers($persona)) {
                return $persona;
            }
        }

        return null;
    }

    public function create(array $data): int
    {
        $this->ensureSchema();

        $payload = $this->sanitize($data);
        $payload['user_id']    = (int) ($data['user_id'] ?? 0);
        $payload['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $payload['version']    = 1;
        $payload['seal']       = Seal::of((string) ($payload['content'] ?? ''));
        $payload['created_at'] = date('Y-m-d H:i:s');

        $id = $this->db->table(self::TABLE)->insert($payload);

        // 首版即留快照：之后任何一次保存都能回到「最初那一版」
        $this->snapshot($id, '初始版本', $payload);

        if (!empty($payload['is_default'])) {
            $this->makeSoleDefault($id, (int) $payload['user_id']);
        }

        return $id;
    }

    /**
     * 更新思维。正文变化时：
     *   1. 先把「旧版本」存进修订快照（改前备份）
     *   2. version + 1，重算封条
     *   3. 靶点/口令等元数据变化不动版本号
     */
    public function update(int $id, array $data): void
    {
        $current = $this->find($id);
        if ($current === null) {
            return;
        }

        $payload = $this->sanitize($data);
        if (empty($payload)) {
            return;
        }

        $contentChanged = array_key_exists('content', $payload)
            && Seal::of((string) $payload['content']) !== $current['seal'];

        if ($contentChanged) {
            // 改前备份：把当前版本整体落一份快照
            $this->snapshot($id, (string) ($data['note'] ?? '保存前自动备份'), null, $current);

            $payload['version'] = $current['version'] + 1;
            $payload['seal']    = Seal::of((string) $payload['content']);
        }

        $this->db->table(self::TABLE)->where('id', $id)->update($payload);

        if (array_key_exists('is_default', $payload) && !empty($payload['is_default'])) {
            $this->makeSoleDefault($id, (int) $current['user_id']);
        }
    }

    public function delete(int $id): void
    {
        $this->ensureSchema();

        // 删之前先记下归属与「是否默认」——删完这两样都查不到了
        $row = $this->find($id);
        $ownerId = $row !== null ? (int) $row['user_id'] : 0;
        $wasDefault = $row !== null && !empty($row['is_default']);

        $this->db->table(self::REV)->where('persona_id', $id)->delete();
        $this->db->table(self::TGT)->where('persona_id', $id)->delete();
        $this->db->table(self::TABLE)->where('id', $id)->delete();

        // ⚠️ 删掉的是「默认思维」时，同作用域会**一份默认都不剩**。
        // 踩过的坑：先用 update(is_default=true) 把临时思维提为默认 → 原默认被
        // makeSoleDefault 清零；再删掉临时思维 → 整个作用域没有任何 ★。
        // 此时 active() 会静默回退到「首个启用的思维」，于是**界面显示没生效、
        // 实际却在注入另一份**，行为与 UI 不一致且极难排查。
        // 这里补上自愈：默认位空了就顺手把首个启用项提上来，让 ★ 与实际一致。
        if ($wasDefault && !$this->hasDefault($ownerId)) {
            $next = $this->firstEnabled($ownerId);
            if ($next !== null) {
                $this->update((int) $next['id'], ['is_default' => true]);
            }
        }
    }

    /** 该作用域下是否已有默认思维 */
    private function hasDefault(int $userId): bool
    {
        return $this->db->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('is_default', 1)
            ->first() !== null;
    }

    /** 该作用域下首个启用的思维（与 active() 的回退顺序一致） */
    private function firstEnabled(int $userId): ?array
    {
        $row = $this->db->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('enabled', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->first();

        return $row ? $this->normalize($row) : null;
    }

    /** 校验思维归属（防止跨用户越权操作） */
    public function ownedBy(int $id, ?int $userId): bool
    {
        $row = $this->find($id);
        if ($row === null) {
            return false;
        }
        if ($userId === null) {
            return true;
        }
        return (int) $row['user_id'] === $userId;
    }

    /** 仅保留一个默认思维（同一作用域内） */
    private function makeSoleDefault(int $id, int $userId): void
    {
        $this->db->statement(
            'UPDATE `' . self::TABLE . '` SET `is_default` = 0 WHERE `user_id` = ? AND `id` <> ?',
            [$userId, $id]
        );
    }

    /* ── 靶点 ───────────────────────────────────────────────── */

    /** 思维声明的靶点白名单；空数组 = 不限（全部已启用供应商） */
    public function targetList(array $persona): array
    {
        $raw = $persona['targets'] ?? [];
        return is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
    }

    public function targetEnabled(array $persona, string $target): bool
    {
        $declared = $this->targetList($persona);
        if (!empty($declared) && !in_array($target, $declared, true)) {
            return false;
        }

        // 显式关闭过的靶点（aigw_persona_target.enabled=0）一票否决
        $row = $this->db->table(self::TGT)
            ->where('persona_id', (int) $persona['id'])
            ->where('target', $target)
            ->first();

        return !($row && (int) $row['enabled'] === 0);
    }

    /* ── 模型白名单（精准生效的模型维度）────────────────────── */

    /** 思维声明的模型白名单；空数组 = 不限（全部模型） */
    public function modelList(array $persona): array
    {
        $raw = $persona['models'] ?? [];
        return is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
    }

    /**
     * 本次模型是否落在白名单内。
     *   - 白名单为空 → 不限模型，直接放行；
     *   - 支持尾部 * 前缀通配（如 「gpt-4*」命中 gpt-4o / gpt-4-turbo）；
     *   - 其余按模型 ID 精确匹配（大小写不敏感）。
     */
    public function modelEnabled(array $persona, string $model): bool
    {
        $declared = $this->modelList($persona);
        if (empty($declared)) {
            return true;
        }

        $model = strtolower(trim($model));
        if ($model === '') {
            return false;
        }

        foreach ($declared as $pattern) {
            $pattern = strtolower(trim($pattern));
            if ($pattern === '') {
                continue;
            }
            if ($pattern === $model) {
                return true;
            }
            if (str_ends_with($pattern, '*')) {
                $prefix = substr($pattern, 0, -1);
                if ($prefix !== '' && str_starts_with($model, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** 靶点状态表：把「全部已知靶点 × 该思维」补齐成矩阵行 */
    public function targetStates(array $persona, array $knownTargets): array
    {
        $this->ensureSchema();

        $personaId = (int) $persona['id'];
        $declared  = $this->targetList($persona);

        $rows = $this->db->table(self::TGT)->where('persona_id', $personaId)->get();
        $byTarget = [];
        foreach ($rows as $row) {
            $byTarget[(string) $row['target']] = $row;
        }

        $out = [];
        foreach ($knownTargets as $target) {
            $row = $byTarget[$target] ?? null;
            $inScope = empty($declared) || in_array($target, $declared, true);
            $enabled = $inScope && !($row && (int) $row['enabled'] === 0);

            $out[] = [
                'target'         => $target,
                'in_scope'       => $inScope,
                'enabled'        => $enabled,
                'inject_count'   => (int) ($row['inject_count'] ?? 0),
                'last_inject_at' => $row['last_inject_at'] ?? null,
                'last_error'     => (string) ($row['last_error'] ?? ''),
            ];
        }

        return $out;
    }

    public function setTarget(int $personaId, string $target, bool $enabled): void
    {
        $this->ensureSchema();

        $this->db->table(self::TGT)->upsert(
            [
                'persona_id' => $personaId,
                'target'     => $target,
                'enabled'    => $enabled ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            ['enabled', 'updated_at']
        );
    }

    /** 记录一次靶点注入（成功累加计数，失败只记原因，不清零既有计数） */
    public function bumpTarget(int $personaId, string $target, string $error = ''): void
    {
        $now = date('Y-m-d H:i:s');

        if ($error === '') {
            $this->db->statement(
                'INSERT INTO `' . self::TGT . '` (`persona_id`, `target`, `enabled`, `inject_count`, `last_inject_at`, `last_error`)'
                . ' VALUES (?, ?, 1, 1, ?, \'\')'
                . ' ON DUPLICATE KEY UPDATE `inject_count` = `inject_count` + 1, `last_inject_at` = VALUES(`last_inject_at`), `last_error` = \'\'',
                [$personaId, $target, $now]
            );
            return;
        }

        $this->db->statement(
            'INSERT INTO `' . self::TGT . '` (`persona_id`, `target`, `enabled`, `inject_count`, `last_error`)'
            . ' VALUES (?, ?, 1, 0, ?)'
            . ' ON DUPLICATE KEY UPDATE `last_error` = VALUES(`last_error`)',
            [$personaId, $target, mb_substr($error, 0, 255)]
        );
    }

    /* ── 用户级平台开关（普通用户自选在哪些平台接受注入）───────── */

    /**
     * 某用户在各平台上的开关矩阵（**缺省 = 关**）。
     * 无记录 = 该平台不注入，用户必须显式打开才生效。
     */
    public function userTargetStates(int $userId, array $knownTargets): array
    {
        $this->ensureSchema();

        $rows = $this->db->table(self::UTG)->where('user_id', $userId)->get();
        $byTarget = [];
        foreach ($rows as $row) {
            $byTarget[(string) $row['target']] = $row;
        }

        $out = [];
        foreach ($knownTargets as $target) {
            $row = $byTarget[$target] ?? null;
            $out[] = [
                'target'     => $target,
                'enabled'    => $row !== null && (int) $row['enabled'] === 1,
                'explicit'   => $row !== null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }

        return $out;
    }

    /** 该用户在该平台上是否接受注入（**无记录 = 不接受**） */
    public function userTargetEnabled(int $userId, string $target): bool
    {
        $this->ensureSchema();

        $row = $this->db->table(self::UTG)
            ->where('user_id', $userId)
            ->where('target', $target)
            ->first();

        return $row !== null && (int) $row['enabled'] === 1;
    }

    public function setUserTarget(int $userId, string $target, bool $enabled): void
    {
        $this->ensureSchema();

        $this->db->table(self::UTG)->upsert(
            [
                'user_id'    => $userId,
                'target'     => $target,
                'enabled'    => $enabled ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            ['enabled', 'updated_at']
        );
    }

    /** 清掉某用户的全部平台开关记录；返回删除行数（供级联清理计数） */
    public function deleteUserTargets(int $userId): int
    {
        $this->ensureSchema();

        return (int) $this->db->table(self::UTG)->where('user_id', $userId)->delete();
    }

    /* ── 用户级思维开关（普通用户自选哪几份思维对自己生效）───────
       只对**全局（系统）思维**留记录；用户自建思维的开关就是它自己的 enabled 字段。
       与平台开关一样：**无记录 = 关**（开箱默认全关）。                          */

    /**
     * 某用户对一批思维的开关状态（**缺省 = 关**）
     *
     * @param list<int> $personaIds
     * @return array<int,array{persona_id:int,enabled:bool,explicit:bool}>
     */
    public function userEnableStates(int $userId, array $personaIds): array
    {
        $this->ensureSchema();

        $rows = $this->db->table(self::UEB)->where('user_id', $userId)->get();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['persona_id']] = $row;
        }

        $out = [];
        foreach ($personaIds as $pid) {
            $pid = (int) $pid;
            $row = $byId[$pid] ?? null;
            $out[$pid] = [
                'persona_id' => $pid,
                'enabled'    => $row !== null && (int) $row['enabled'] === 1,
                'explicit'   => $row !== null,
            ];
        }

        return $out;
    }

    /** 该用户是否让这份（全局）思维对自己生效（**无记录 = 否**） */
    public function userPersonaEnabled(int $userId, int $personaId): bool
    {
        $this->ensureSchema();

        $row = $this->db->table(self::UEB)
            ->where('user_id', $userId)
            ->where('persona_id', $personaId)
            ->first();

        return $row !== null && (int) $row['enabled'] === 1;
    }

    /**
     * 该用户「已经开启、可以参与注入」的思维 id 集合。
     *
     * = 自建且 enabled=1 的思维 ∪ 用户级开关里显式打开的（全局）思维。
     * 空数组 = 一份都没开 → 注入链一律不注入（开箱默认全关）。
     *
     * @return list<int>
     */
    public function userEnabledIds(int $userId): array
    {
        $this->ensureSchema();

        $mine = $this->db->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('enabled', 1)
            ->get();
        $ids = array_map(fn(array $r) => (int) $r['id'], $mine);

        $rows = $this->db->table(self::UEB)->where('user_id', $userId)->where('enabled', 1)->get();
        foreach ($rows as $row) {
            $ids[] = (int) $row['persona_id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * 互斥开启/关闭：普通用户在任一时刻**只能开启一份**思维。
     *
     * `enabled = true`  → 先把该用户「所有自建思维的 enabled」与「所有用户级思维开关」
     *                     一律清零，再只打开目标那一份；
     * `enabled = false` → 全部清零（等于一份都不开）。
     *
     * 这样 `userEnabledIds()` 的结果恒为 0 或 1 个 id，
     * 用户端「自证口令」也就能明确跟随"当前开启的那一份"。
     */
    public function setUserActivePersona(int $userId, int $personaId, bool $enabled): void
    {
        $this->ensureSchema();

        // 1) 该用户作用域下的自建思维先全部停用（直接改列，不走 update()，避免误触发修订快照）
        $this->db->table(self::TABLE)->where('user_id', $userId)->update(['enabled' => 0]);

        // 2) 用户级思维开关（全局思维）也全部清零
        $this->db->table(self::UEB)->where('user_id', $userId)->update(['enabled' => 0]);

        if (!$enabled) {
            return;
        }

        // 3) 只打开目标那一份
        $persona = $this->find($personaId);
        if ($persona === null) {
            return;
        }

        if ((int) $persona['user_id'] === $userId) {
            $this->db->table(self::TABLE)->where('id', $personaId)->update(['enabled' => 1]);
        } else {
            $this->setUserEnable($userId, $personaId, true);
        }
    }

    /**
     * 该用户「当前开启的那一份」思维（互斥语义下至多一份；没开则为 null）。
     *
     * 用户端用它来显示对应的自证口令。
     */
    public function userActivePersona(int $userId): ?array
    {
        $ids = $this->userEnabledIds($userId);
        if ($ids === []) {
            return null;
        }

        // 互斥下只有一个；万一老数据留了多个，取第一份
        return $this->find((int) $ids[0]);
    }

    public function setUserEnable(int $userId, int $personaId, bool $enabled): void
    {
        $this->ensureSchema();

        $this->db->table(self::UEB)->upsert(
            [
                'user_id'    => $userId,
                'persona_id' => $personaId,
                'enabled'    => $enabled ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            ['enabled', 'updated_at']
        );
    }

    /** 清掉某用户的全部思维开关记录；返回删除行数（供级联清理计数） */
    public function deleteUserEnables(int $userId): int
    {
        $this->ensureSchema();

        return (int) $this->db->table(self::UEB)->where('user_id', $userId)->delete();
    }

    /* ── 修订快照（改前备份 / 还原）────────────────────────────── */

    /** 落一份快照；$source 为空时取思维当前状态 */
    public function snapshot(int $personaId, string $note = '', ?array $payload = null, ?array $source = null): int
    {
        $from = $source ?? $payload ?? $this->find($personaId);
        if ($from === null) {
            return 0;
        }

        $content = (string) ($from['content'] ?? '');
        $version = (int) ($from['version'] ?? 1);
        $seal    = (string) ($from['seal'] ?? '') ?: Seal::of($content);

        $id = $this->db->table(self::REV)->insert([
            'persona_id' => $personaId,
            'user_id'    => (int) ($from['user_id'] ?? 0),
            'version'    => $version,
            'content'    => $content,
            'seal'       => $seal,
            'note'       => mb_substr($note, 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->pruneRevisions($personaId);

        return $id;
    }

    /** @return list<array> 修订列表（倒序，不含正文，避免列表接口过大） */
    public function revisions(int $personaId, int $limit = 50): array
    {
        $this->ensureSchema();

        $rows = $this->db->table(self::REV)
            ->where('persona_id', $personaId)
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(200, $limit)))
            ->get();

        return array_map(fn(array $r) => [
            'id'         => (int) $r['id'],
            'persona_id' => (int) $r['persona_id'],
            'version'    => (int) $r['version'],
            'seal'       => (string) $r['seal'],
            'note'       => (string) ($r['note'] ?? ''),
            'bytes'      => strlen((string) ($r['content'] ?? '')),
            'created_at' => (string) $r['created_at'],
        ], $rows);
    }

    public function revision(int $id): ?array
    {
        $row = $this->db->table(self::REV)->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        return [
            'id'         => (int) $row['id'],
            'persona_id' => (int) $row['persona_id'],
            'user_id'    => (int) $row['user_id'],
            'version'    => (int) $row['version'],
            'content'    => (string) ($row['content'] ?? ''),
            'seal'       => (string) $row['seal'],
            'note'       => (string) ($row['note'] ?? ''),
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * 还原到指定修订：先把「当前版本」也存成快照（还原本身是破坏性的），
     * 再把正文替换为快照内容并把版本号抬到最高，保证「还原后不会再被旧版本覆盖」。
     */
    public function restore(int $personaId, int $revisionId): bool
    {
        $persona = $this->find($personaId);
        $rev = $this->revision($revisionId);
        if ($persona === null || $rev === null || (int) $rev['persona_id'] !== $personaId) {
            return false;
        }

        $this->snapshot($personaId, '还原前自动备份', null, $persona);

        $next = max((int) $persona['version'], $rev['version']) + 1;
        $this->db->table(self::TABLE)->where('id', $personaId)->update([
            'content' => $rev['content'],
            'seal'    => Seal::of((string) $rev['content']),
            'version' => $next,
        ]);

        $this->snapshot($personaId, '还原到 v' . $rev['version'] . '（快照 #' . $revisionId . '）');

        return true;
    }

    /** 保留最近 N 份快照，避免历史无限膨胀 */
    private function pruneRevisions(int $personaId, int $keep = 50): void
    {
        $oldest = $this->db->table(self::REV)
            ->select('id')
            ->where('persona_id', $personaId)
            ->orderBy('id', 'DESC')
            ->limit(1, $keep)
            ->get();

        if (empty($oldest)) {
            return;
        }

        $cutoff = (int) $oldest[0]['id'];
        $this->db->statement(
            'DELETE FROM `' . self::REV . '` WHERE `persona_id` = ? AND `id` < ?',
            [$personaId, $cutoff]
        );
    }

    /* ── 统计 ───────────────────────────────────────────────── */

    public function bumpHit(int $personaId): void
    {
        $this->db->statement(
            'UPDATE `' . self::TABLE . '` SET `hit_count` = `hit_count` + 1, `last_hit_at` = ? WHERE `id` = ?',
            [date('Y-m-d H:i:s'), $personaId]
        );
    }

    public function markSealChecked(int $personaId): void
    {
        $this->db->table(self::TABLE)
            ->where('id', $personaId)
            ->update(['seal_checked_at' => date('Y-m-d H:i:s')]);
    }

    /* ── 字段处理 ───────────────────────────────────────────── */

    private function sanitize(array $data): array
    {
        $payload = [];

        if (array_key_exists('name', $data)) {
            $payload['name'] = mb_substr(trim((string) $data['name']), 0, 64);
        }
        if (array_key_exists('description', $data)) {
            $payload['description'] = mb_substr(trim((string) $data['description']), 0, 255);
        }
        if (array_key_exists('content', $data)) {
            $payload['content'] = (string) $data['content'];
        }
        if (array_key_exists('mode', $data)) {
            $mode = trim((string) $data['mode']);
            $payload['mode'] = in_array($mode, self::MODES, true) ? $mode : 'prepend';
        }
        if (array_key_exists('enabled', $data)) {
            $payload['enabled'] = !empty($data['enabled']) ? 1 : 0;
        }
        if (array_key_exists('is_default', $data)) {
            $payload['is_default'] = !empty($data['is_default']) ? 1 : 0;
        }
        if (array_key_exists('targets', $data)) {
            $targets = $data['targets'];
            if (is_string($targets)) {
                $decoded = json_decode($targets, true);
                $targets = is_array($decoded) ? $decoded : [];
            }
            $targets = is_array($targets) ? array_values(array_unique(array_filter(array_map('strval', $targets)))) : [];
            $payload['targets'] = $targets ? json_encode($targets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }
        if (array_key_exists('models', $data)) {
            $models = $data['models'];
            if (is_string($models)) {
                $decoded = json_decode($models, true);
                $models = is_array($decoded) ? $decoded : [];
            }
            $models = is_array($models)
                ? array_values(array_unique(array_filter(array_map(fn($m) => strtolower(trim((string) $m)), $models))))
                : [];
            $payload['models'] = $models ? json_encode($models, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }
        if (array_key_exists('selfcheck_on', $data)) {
            $payload['selfcheck_on'] = !empty($data['selfcheck_on']) ? 1 : 0;
        }
        if (array_key_exists('selfcheck_pass', $data)) {
            $payload['selfcheck_pass'] = mb_substr(trim((string) $data['selfcheck_pass']), 0, 64);
        }
        if (array_key_exists('selfcheck_reply', $data)) {
            $payload['selfcheck_reply'] = mb_substr(trim((string) $data['selfcheck_reply']), 0, 255);
        }
        if (array_key_exists('sort_order', $data)) {
            $payload['sort_order'] = (int) $data['sort_order'];
        }

        return $payload;
    }

    private function normalize(array $row): array
    {
        $targets = [];
        if (!empty($row['targets'])) {
            $decoded = json_decode((string) $row['targets'], true);
            $targets = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }

        $models = [];
        if (!empty($row['models'])) {
            $decoded = json_decode((string) $row['models'], true);
            $models = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }

        $mode = (string) ($row['mode'] ?? 'prepend');

        return [
            'id'              => (int) $row['id'],
            'user_id'         => (int) $row['user_id'],
            'name'            => (string) $row['name'],
            'description'     => (string) ($row['description'] ?? ''),
            'content'         => (string) ($row['content'] ?? ''),
            'mode'            => in_array($mode, self::MODES, true) ? $mode : 'prepend',
            'enabled'         => (bool) $row['enabled'],
            'is_default'      => (bool) ($row['is_default'] ?? false),
            'version'         => (int) ($row['version'] ?? 1),
            'seal'            => (string) ($row['seal'] ?? ''),
            'seal_checked_at' => $row['seal_checked_at'] ?? null,
            'targets'         => $targets,
            'models'          => $models,
            'selfcheck_on'    => (bool) ($row['selfcheck_on'] ?? true),
            'selfcheck_pass'  => (string) ($row['selfcheck_pass'] ?? ''),
            'selfcheck_reply' => (string) ($row['selfcheck_reply'] ?? ''),
            'hit_count'       => (int) ($row['hit_count'] ?? 0),
            'last_hit_at'     => $row['last_hit_at'] ?? null,
            'sort_order'      => (int) ($row['sort_order'] ?? 0),
            'created_at'      => $row['created_at'] ?? null,
            'updated_at'      => $row['updated_at'] ?? null,
            'bytes'           => strlen((string) ($row['content'] ?? '')),
        ];
    }
}
