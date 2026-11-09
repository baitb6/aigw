<?php
/**
 * 用户行为审计仓储（aigw_audit_log）
 *
 * 与 LogRepository（网关转发明细）互补：这里记录的是**操作事件** ——
 * 谁（actor）在什么时候（created_at）对什么（target）做了什么（action），
 * 结果如何（status），从哪个 IP / 什么 UA 发起的。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Cache;
use App\Support\Schema;

class AuditRepository
{
    private const TABLE = 'aigw_audit_log';

    /** 分页总数缓存时长（秒）——键里带最新 id，写入新行为会立刻失效 */
    private const COUNT_CACHE_TTL = 300;

    /**
     * 筛选字典缓存时长（秒）。
     *
     * 动作/分类的**种类集合**变化极慢（只有代码里新增埋点才会多一个），
     * 但取它要全表 DISTINCT —— 缓存 5 分钟，换掉每次翻页的一次全表扫描。
     */
    private const OPTIONS_CACHE_TTL = 300;

    private bool $schemaChecked = false;

    public function __construct(private Database $db) {}

    /** 老库升级：幂等补齐审计表 */
    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $this->schemaChecked = true;
        Schema::ensure($this->db);
    }

    /**
     * 写入一条审计事件。detail 会被编码为 JSON（超长截断保护）。
     */
    public function record(array $data): int
    {
        $this->ensureSchema();

        $detail = $data['detail'] ?? null;
        if (is_array($detail)) {
            $detail = $detail === [] ? null : json_encode(
                $detail,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
        if (is_string($detail) && mb_strlen($detail) > 20000) {
            $detail = mb_substr($detail, 0, 20000);
        }

        $category = (string) ($data['category'] ?? '');
        if ($category === '') {
            $category = \App\Support\Audit::categoryOf((string) ($data['action'] ?? ''));
        }

        return $this->db->table(self::TABLE)->insert([
            'user_id'     => (int) ($data['user_id'] ?? 0),
            'actor_type'  => mb_substr((string) ($data['actor_type'] ?? 'user'), 0, 16),
            'actor_id'    => (int) ($data['actor_id'] ?? 0),
            'actor_name'  => mb_substr((string) ($data['actor_name'] ?? ''), 0, 64),
            'action'      => mb_substr((string) ($data['action'] ?? ''), 0, 64),
            'category'    => mb_substr($category, 0, 32),
            'target_type' => mb_substr((string) ($data['target_type'] ?? ''), 0, 32),
            'target_id'   => mb_substr((string) ($data['target_id'] ?? ''), 0, 64),
            'summary'     => mb_substr((string) ($data['summary'] ?? ''), 0, 255),
            'detail'      => $detail,
            'ip'          => mb_substr((string) ($data['ip'] ?? ''), 0, 64),
            'ua'          => mb_substr((string) ($data['ua'] ?? ''), 0, 255),
            'method'      => mb_substr((string) ($data['method'] ?? ''), 0, 8),
            'path'        => mb_substr((string) ($data['path'] ?? ''), 0, 255),
            'status'      => (int) ($data['status'] ?? 200),
            'created_at'  => (string) ($data['created_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    /** 分页查询审计事件（$userId 非 null 时锁定该用户，实现属主隔离） */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 20, ?int $userId = null): array
    {
        $this->ensureSchema();

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));

        $query = $this->applyFilters($this->db->table(self::TABLE), $filters, $userId);

        $total = $this->countCached($filters, $userId, $query);
        $rows = $query->orderBy('id', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get();

        foreach ($rows as $i => $row) {
            $rows[$i]['detail'] = $this->decodeDetail($row['detail'] ?? null);
        }

        return [
            'items'    => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /**
     * 分页总数：带缓存且不陈旧 —— 缓存键里编入「当前最大 id」。
     *
     * `COUNT(*)` 随流水量线性变慢，而每次翻页都要跑；把最新 id 编进键后，
     * 有新行为写入时键立刻变化（重新计数），没有新写入时直接命中（跳过计数）。
     *
     * @param mixed $query 已应用筛选的 QueryBuilder
     */
    private function countCached(array $filters, ?int $userId, $query): int
    {
        $maxId = (int) $this->db->scalar('SELECT COALESCE(MAX(`id`), 0) FROM `' . self::TABLE . '`');

        $cacheKey = 'count;max=' . $maxId . ';' . md5(json_encode([$filters, $userId]));

        $hit = Cache::read('audit_count', $cacheKey, self::COUNT_CACHE_TTL);

        if ($hit !== null && isset($hit['value']['n'])) {
            return (int) $hit['value']['n'];
        }

        $total = $query->count();

        Cache::write('audit_count', $cacheKey, ['n' => $total]);

        return $total;
    }

    /** 导出用：按同一筛选取一批（默认 5000，硬上限 20000） */
    public function exportRows(array $filters = [], int $limit = 5000, ?int $userId = null): array
    {
        $this->ensureSchema();

        $limit = min(20000, max(1, $limit));
        $query = $this->applyFilters($this->db->table(self::TABLE), $filters, $userId);

        return $query->orderBy('id', 'DESC')->limit($limit, 0)->get();
    }

    /** 某用户的完整行为时间线（倒序），供管理台用户详情下钻 */
    public function timeline(int $userId, int $limit = 100): array
    {
        $this->ensureSchema();
        if ($userId <= 0) {
            return [];
        }

        $limit = min(500, max(1, $limit));
        $rows = $this->db
            ->table(self::TABLE)
            ->where('user_id', $userId)
            ->orderBy('id', 'DESC')
            ->limit($limit, 0)
            ->get();

        foreach ($rows as $i => $row) {
            $rows[$i]['detail'] = $this->decodeDetail($row['detail'] ?? null);
        }

        return $rows;
    }

    /**
     * 汇总统计：总量 / 今日 / 独立用户 / 失败数 / 分类分布 / Top 动作 / 每日趋势
     */
    public function stats(int $days = 7, ?int $userId = null): array
    {
        $this->ensureSchema();

        $days = max(1, min(90, $days));
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $todayStart = date('Y-m-d 00:00:00');

        $scopeSql = $userId !== null ? ' AND `user_id` = ?' : '';
        $scopeParams = $userId !== null ? [$userId] : [];

        $overall = $this->db->first(
            'SELECT COUNT(*) AS events,
                    COUNT(DISTINCT `user_id`) AS users,
                    SUM(`status` >= 400) AS failed,
                    SUM(`created_at` >= ?) AS today
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql,
            array_merge([$todayStart, $since], $scopeParams)
        ) ?? [];

        $byCategory = $this->db->select(
            'SELECT `category`, COUNT(*) AS events, SUM(`status` >= 400) AS failed
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql . '
             GROUP BY `category` ORDER BY events DESC',
            array_merge([$since], $scopeParams)
        );

        $topActions = $this->db->select(
            'SELECT `action`, `category`, COUNT(*) AS events, SUM(`status` >= 400) AS failed
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql . '
             GROUP BY `action`, `category` ORDER BY events DESC LIMIT 20',
            array_merge([$since], $scopeParams)
        );

        $byActor = $this->db->select(
            'SELECT `actor_type`, COUNT(*) AS events
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql . '
             GROUP BY `actor_type` ORDER BY events DESC',
            array_merge([$since], $scopeParams)
        );

        $daily = $this->db->select(
            'SELECT DATE(`created_at`) AS `day`, COUNT(*) AS events, SUM(`status` >= 400) AS failed
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql . '
             GROUP BY DATE(`created_at`) ORDER BY `day` ASC',
            array_merge([$since], $scopeParams)
        );

        // 补齐缺失日期，保证前端折线连续
        $dailyMap = [];
        foreach ($daily as $row) {
            $dailyMap[(string) $row['day']] = $row;
        }
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $row = $dailyMap[$day] ?? null;
            $series[] = [
                'day'    => $day,
                'events' => (int) ($row['events'] ?? 0),
                'failed' => (int) ($row['failed'] ?? 0),
            ];
        }

        return [
            'overall' => [
                'events' => (int) ($overall['events'] ?? 0),
                'users'  => (int) ($overall['users'] ?? 0),
                'failed' => (int) ($overall['failed'] ?? 0),
                'today'  => (int) ($overall['today'] ?? 0),
            ],
            'by_category' => array_map(fn($r) => [
                'category' => (string) $r['category'],
                'events'   => (int) $r['events'],
                'failed'   => (int) $r['failed'],
            ], $byCategory),
            'top_actions' => array_map(fn($r) => [
                'action'   => (string) $r['action'],
                'category' => (string) $r['category'],
                'events'   => (int) $r['events'],
                'failed'   => (int) $r['failed'],
            ], $topActions),
            'by_actor' => array_map(fn($r) => [
                'actor_type' => (string) $r['actor_type'],
                'events'     => (int) $r['events'],
            ], $byActor),
            'daily' => $series,
        ];
    }

    /**
     * 登录 / 注册专项统计（供「注册登录大屏」使用）。
     *
     * 口径说明（避免两个数据源打架）：
     *   - 注册数：以 `aigw_audit_log` 的 `register.success` 事件为准（含操作者与来源 IP）。
     *     真实用户总量核对用 UserRepository::statusCounts()（aigw_user 行数）。
     *   - 登录：`login.success`；失败 `login.fail`；被锁 `login.locked`。
     *   - 独立登录用户：`COUNT(DISTINCT user_id)`，仅统计 `login.success` 且 user_id > 0
     *     （管理员登录 user_id = 0，不计入用户维度）。
     *
     * 四个动作按天补齐，前端可直接画多条折线，无需再对齐日期。
     */
    public function authStats(int $days = 7, ?int $userId = null): array
    {
        $this->ensureSchema();

        $days = max(1, min(90, $days));
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $todayStart = date('Y-m-d 00:00:00');
        $weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));

        $scopeSql = $userId !== null ? ' AND `user_id` = ?' : '';
        $scopeParams = $userId !== null ? [$userId] : [];

        $overall = $this->db->first(
            "SELECT
                SUM(`action` = 'register.success') AS register_total,
                SUM(`action` = 'login.success')    AS login_total,
                SUM(`action` = 'login.fail')       AS login_failed,
                SUM(`action` = 'login.locked')     AS login_locked,
                COUNT(DISTINCT CASE WHEN `action` = 'login.success' AND `user_id` > 0 THEN `user_id` END) AS login_users,
                SUM(`action` = 'register.success' AND `created_at` >= ?) AS register_today,
                SUM(`action` = 'login.success'    AND `created_at` >= ?) AS login_today,
                SUM(`action` = 'register.success' AND `created_at` >= ?) AS register_week,
                SUM(`action` = 'login.success'    AND `created_at` >= ?) AS login_week
             FROM `" . self::TABLE . "` WHERE `created_at` >= ?" . $scopeSql,
            array_merge([$todayStart, $todayStart, $weekStart, $weekStart, $since], $scopeParams)
        ) ?? [];

        $dailyRows = $this->db->select(
            "SELECT DATE(`created_at`) AS `day`, `action`, COUNT(*) AS `events`
             FROM `" . self::TABLE . "`
             WHERE `created_at` >= ? AND `action` IN ('register.success','login.success','login.fail','login.locked')"
                . $scopeSql . '
             GROUP BY DATE(`created_at`), `action` ORDER BY `day` ASC',
            array_merge([$since], $scopeParams)
        );

        $dailyMap = [];
        foreach ($dailyRows as $row) {
            $dailyMap[(string) $row['day']][(string) $row['action']] = (int) $row['events'];
        }

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $row = $dailyMap[$day] ?? [];
            $register = (int) ($row['register.success'] ?? 0);
            $login    = (int) ($row['login.success'] ?? 0);
            $failed   = (int) ($row['login.fail'] ?? 0);
            $locked   = (int) ($row['login.locked'] ?? 0);

            $series[] = [
                'day'      => $day,
                'register' => $register,
                'login'    => $login,
                'failed'   => $failed,
                'locked'   => $locked,
                'events'   => $register + $login + $failed + $locked,
            ];
        }

        // 注册来源 TOP（按 IP 聚合，批量注册一眼可见）
        $topIps = $this->db->select(
            "SELECT `ip`, COUNT(*) AS `events`, COUNT(DISTINCT `user_id`) AS `users`
             FROM `" . self::TABLE . "`
             WHERE `created_at` >= ? AND `action` = 'register.success' AND `ip` <> ''" . $scopeSql . '
             GROUP BY `ip` ORDER BY events DESC LIMIT 10',
            array_merge([$since], $scopeParams)
        );

        // 登录失败来源 TOP（撞库排查）
        $failIps = $this->db->select(
            "SELECT `ip`, COUNT(*) AS `events`
             FROM `" . self::TABLE . "`
             WHERE `created_at` >= ? AND `action` = 'login.fail' AND `ip` <> ''" . $scopeSql . '
             GROUP BY `ip` ORDER BY events DESC LIMIT 10',
            array_merge([$since], $scopeParams)
        );

        $recentRegister = $this->db->select(
            "SELECT `user_id`, `actor_name`, `summary`, `ip`, `created_at`
             FROM `" . self::TABLE . "`
             WHERE `action` = 'register.success' AND `created_at` >= ?" . $scopeSql . '
             ORDER BY `id` DESC LIMIT 20',
            array_merge([$since], $scopeParams)
        );
        $recentLogin = $this->db->select(
            "SELECT `user_id`, `actor_name`, `summary`, `ip`, `created_at`
             FROM `" . self::TABLE . "`
             WHERE `action` = 'login.success' AND `created_at` >= ?" . $scopeSql . '
             ORDER BY `id` DESC LIMIT 20',
            array_merge([$since], $scopeParams)
        );

        return [
            'overall' => [
                'register_total' => (int) ($overall['register_total'] ?? 0),
                'login_total'    => (int) ($overall['login_total'] ?? 0),
                'login_failed'   => (int) ($overall['login_failed'] ?? 0),
                'login_locked'   => (int) ($overall['login_locked'] ?? 0),
                'login_users'    => (int) ($overall['login_users'] ?? 0),
                'register_today' => (int) ($overall['register_today'] ?? 0),
                'login_today'    => (int) ($overall['login_today'] ?? 0),
                'register_week'  => (int) ($overall['register_week'] ?? 0),
                'login_week'     => (int) ($overall['login_week'] ?? 0),
            ],
            'daily' => $series,
            'top_register_ips' => array_map(fn($r) => [
                'ip'     => (string) $r['ip'],
                'events' => (int) $r['events'],
                'users'  => (int) $r['users'],
            ], $topIps),
            'top_fail_ips' => array_map(fn($r) => [
                'ip'     => (string) $r['ip'],
                'events' => (int) $r['events'],
            ], $failIps),
            'recent_register' => array_map(fn($r) => [
                'user_id'    => (int) $r['user_id'],
                'actor_name' => (string) $r['actor_name'],
                'summary'    => (string) $r['summary'],
                'ip'         => (string) $r['ip'],
                'created_at' => (string) $r['created_at'],
            ], $recentRegister),
            'recent_login' => array_map(fn($r) => [
                'user_id'    => (int) $r['user_id'],
                'actor_name' => (string) $r['actor_name'],
                'summary'    => (string) $r['summary'],
                'ip'         => (string) $r['ip'],
                'created_at' => (string) $r['created_at'],
            ], $recentLogin),
            'days' => $days,
        ];
    }

    /** 可用筛选项字典：出现过的分类与动作（供前端下拉） */
    public function actionOptions(?int $userId = null): array
    {
        $this->ensureSchema();

        // 🔴 这两个 SELECT DISTINCT 是**全表扫描**（可选 user_id 过滤），且被
        // 行为列表接口每次翻页都会调用 —— 属于「结果几乎不变、代价随流水量线性增长」
        // 的典型。下拉字典缺一个刚出现的新动作几分钟无伤大雅，故缓存 5 分钟。
        $cacheKey = 'scope=' . ($userId === null ? 'all' : (string) $userId);

        $hit = Cache::read('audit_options', $cacheKey, self::OPTIONS_CACHE_TTL);

        if ($hit !== null && isset($hit['value']['categories'], $hit['value']['actions'])) {
            return $hit['value'];
        }

        $options = $this->buildActionOptions($userId);

        Cache::write('audit_options', $cacheKey, $options);

        return $options;
    }

    /**
     * @return array{categories:list<string>,actions:list<array{action:string,category:string}>}
     */
    private function buildActionOptions(?int $userId): array
    {
        $scopeSql = $userId !== null ? ' WHERE `user_id` = ?' : '';
        $scopeParams = $userId !== null ? [$userId] : [];

        $categories = $this->db->select(
            'SELECT DISTINCT `category` FROM `' . self::TABLE . '`' . $scopeSql . ' ORDER BY `category` ASC',
            $scopeParams
        );
        $actions = $this->db->select(
            'SELECT DISTINCT `action`, `category` FROM `' . self::TABLE . '`' . $scopeSql . ' ORDER BY `action` ASC',
            $scopeParams
        );

        return [
            'categories' => array_values(array_filter(array_map(fn($r) => (string) $r['category'], $categories))),
            'actions'    => array_map(fn($r) => [
                'action'   => (string) $r['action'],
                'category' => (string) $r['category'],
            ], $actions),
        ];
    }

    /** id => 展示名（昵称 / 用户名 / 邮箱），供审计列表展示「归属用户」 */
    public function userLabels(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn($i) => $i > 0
        )));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->select(
            'SELECT `id`, `username`, `nickname`, `email` FROM `aigw_user` WHERE `id` IN (' . $placeholders . ')',
            $ids
        );

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $label = (string) ($r['nickname'] ?? '');
            if ($label === '') {
                $label = (string) ($r['username'] ?? '');
            }
            if ($label === '') {
                $label = (string) ($r['email'] ?? '');
            }
            $out[$id] = $label !== '' ? $label : ('用户 #' . $id);
        }

        return $out;
    }

    /** 各用户的事件数量：user_id => count（仅 > 0） */
    public function userEventCounts(): array
    {
        $this->ensureSchema();

        $rows = $this->db->select(
            'SELECT `user_id`, COUNT(*) AS `c` FROM `' . self::TABLE . '`
             WHERE `user_id` > 0 GROUP BY `user_id`'
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = (int) $r['c'];
        }

        return $out;
    }

    /** 删除某用户的全部审计记录（删除用户时级联清理） */
    public function deleteByUserId(int $userId): int
    {
        $this->ensureSchema();
        if ($userId <= 0) {
            return 0;
        }

        return $this->db->table(self::TABLE)->where('user_id', $userId)->delete();
    }

    /** 清理过期审计（保留最近 $keepDays 天） */
    public function purge(int $keepDays): int
    {
        $this->ensureSchema();
        if ($keepDays <= 0) {
            return 0;
        }

        return $this->db->statement(
            'DELETE FROM `' . self::TABLE . '` WHERE `created_at` < ?',
            [date('Y-m-d H:i:s', strtotime("-{$keepDays} days"))]
        );
    }

    public function clearAll(): int
    {
        $this->ensureSchema();
        return $this->db->statement('DELETE FROM `' . self::TABLE . '`');
    }

    /** 行数上限保护：只保留最新 $maxRows 条，0 表示不限 */
    public function enforceRowLimit(int $maxRows): int
    {
        if ($maxRows <= 0) {
            return 0;
        }

        $this->ensureSchema();

        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM `' . self::TABLE . '`');
        if ($total <= $maxRows) {
            return 0;
        }

        $cutoff = (int) $this->db->scalar(
            'SELECT `id` FROM `' . self::TABLE . '` ORDER BY `id` DESC LIMIT 1 OFFSET ?',
            [$maxRows - 1]
        );
        if ($cutoff <= 0) {
            return 0;
        }

        return $this->db->statement('DELETE FROM `' . self::TABLE . '` WHERE `id` <= ?', [$cutoff]);
    }

    /** 筛选条件 → 查询构造器（分页与导出共用） */
    private function applyFilters($query, array $filters, ?int $userId = null)
    {
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            $query->where('category', $category);
        }

        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '') {
            $query->where('action', $action);
        }

        $actorType = trim((string) ($filters['actor_type'] ?? ''));
        if ($actorType !== '') {
            $query->where('actor_type', $actorType);
        }

        $ip = trim((string) ($filters['ip'] ?? ''));
        if ($ip !== '') {
            $query->where('ip', $ip);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status === 'failed') {
            $query->whereRaw('`status` >= 400', []);
        } elseif ($status === 'ok') {
            $query->whereRaw('`status` < 400', []);
        }

        // 管理员按用户下钻
        $uid = (int) ($filters['user_id'] ?? 0);
        if ($userId === null && $uid > 0) {
            $query->where('user_id', $uid);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->whereRaw(
                '(`summary` LIKE ? OR `action` LIKE ? OR `actor_name` LIKE ? OR `target_id` LIKE ? OR `detail` LIKE ?)',
                [$like, $like, $like, $like, $like]
            );
        }

        if (!empty($filters['since'])) {
            $query->where('created_at', '>=', (string) $filters['since']);
        }
        if (!empty($filters['until'])) {
            $query->where('created_at', '<=', (string) $filters['until']);
        }

        return $query;
    }

    /** detail 反序列化（前端拿到结构化对象而非裸字符串） */
    private function decodeDetail($detail): ?array
    {
        if (!is_string($detail) || $detail === '') {
            return null;
        }
        $decoded = json_decode($detail, true);
        return is_array($decoded) ? $decoded : ['_raw' => $detail];
    }
}
