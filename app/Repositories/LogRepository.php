<?php
/**
 * 调用日志仓储（对应 logs/calls 与 logs/requests.json）
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Alerter;
use App\Support\PersonaMark;
use App\Support\Cache;
use App\Support\Schema;
use App\Support\Tenant;

class LogRepository
{
    /** 分页总数缓存时长（秒）——键里带最新 id，写入新日志会立刻失效 */
    private const COUNT_CACHE_TTL = 300;

    private const TABLE = 'aigw_call_log';

    private bool $schemaChecked = false;

    public function __construct(
        private Database $db,
        private ?ModelRepository $models = null,
        private ?GatewayRepository $settings = null,
        private ?PersonaRepository $personas = null,
    ) {}

    /**
     * 老库升级：幂等补齐新增表与调用日志新增列（缓存 Token / 来源 / 会话标识）。
     */
    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $this->schemaChecked = true;

        Schema::ensure($this->db);
        Schema::ensureColumns($this->db, self::TABLE, Schema::CALL_LOG_COLUMNS);
    }

    /** 网关设置（容器未注入时惰性自建，避免构造期循环依赖） */
    private function settings(): GatewayRepository
    {
        return $this->settings ??= new GatewayRepository($this->db);
    }

    /**
     * 内容快照兜底上限（字符数）。
     *
     * 这是**最后一道防炸库护栏**，不是产品级截断 —— 产品级「记不记 / 记多少」
     * 由网关设置 log_request_body + log_content_max_chars 控制。
     * 旧值 20000 太紧：长上下文会话（几十轮 messages）很容易被削掉后半段，
     * 排查"用户到底发了什么"时正好缺那么一截。提到 20 万字，MEDIUMTEXT（16MB）依然安全。
     */
    private const BODY_HARD_LIMIT = 200000;

    /**
     * 内容快照兜底截断：null 原样返回（表示"本次未记录"），
     * 有值则按 BODY_HARD_LIMIT 兜底，避免异常超长内容把 MEDIUMTEXT 撑爆。
     */
    private function clipBody($body): ?string
    {
        if ($body === null) {
            return null;
        }
        $text = (string) $body;
        if ($text === '') {
            return null;
        }
        return mb_strlen($text) > self::BODY_HARD_LIMIT
            ? mb_substr($text, 0, self::BODY_HARD_LIMIT)
            : $text;
    }

    // ================================================================================
    // 正文压缩存储
    // ================================================================================
    //
    // 为什么压缩：请求正文含思维注入块（8~10KB）、回复正文含长回答，二者相加是
    // aigw_call_log 体积的绝对大头（实测 7.28MB 里 698 行正文占绝大部分）。日志本身
    // 是旁路数据，用 CPU 换磁盘是划算交易 —— gzip 解压 10KB 只要几十微秒，
    // 而省下的是真金白银的磁盘与备份时间。
    //
    // 阈值取 512 字节：短正文压缩后 base64 反而更长（gzip 头 18 字节 + base64 膨胀 33%），
    // 只有超过阈值才值得压。低于阈值的明文直接存，读取侧零成本。
    //
    // 为什么用 base64 而不是裸 gzip：MEDIUMTEXT 是字符类型，裸二进制会被字符集转换
    // 破坏（utf8mb4 遇到非法序列直接报错）。base64 后是纯 ASCII，任何字符集都安全。

    /** 低于该字节数不压缩（压缩后反而更长） */
    private const BODY_COMPRESS_MIN = 512;

    /** 编码标记：明文（历史行 / 短正文） */
    public const ENC_RAW = '';

    /** 编码标记：gzip 压缩 + base64 */
    public const ENC_GZIP_B64 = 'gz:b64';

    /**
     * 压缩正文（写入侧）。
     *
     * 返回 [存库字符串, 编码标记, 明文字符数]。不需要压缩时标记为 ENC_RAW。
     * gzip 不可用（zlib 扩展缺失）时静默退化为明文 —— 留痕是旁路，
     * 绝不能因为压缩不了就把整条日志丢掉。
     */
    public function encodeBody($body): array
    {
        $text = $this->clipBody($body);
        if ($text === null) {
            return [null, self::ENC_RAW, 0];
        }

        $plainLen = mb_strlen($text);

        if (!function_exists('gzencode') || strlen($text) < self::BODY_COMPRESS_MIN) {
            return [$text, self::ENC_RAW, $plainLen];
        }

        $packed = @gzencode($text, 6);
        if ($packed === false || $packed === '') {
            return [$text, self::ENC_RAW, $plainLen];
        }

        $encoded = 'gz:' . base64_encode($packed);
        // 压完反而更长（极端随机内容）时留明文
        return strlen($encoded) < strlen($text)
            ? [$encoded, self::ENC_GZIP_B64, $plainLen]
            : [$text, self::ENC_RAW, $plainLen];
    }

    /**
     * 还原正文（读取侧）。
     *
     * 判据是**双条件**：行标记为 gz:b64（说明该行至少有一个字段压过）
     * **且**该字段自带 `gz:` 前缀。只看行标记会误伤同一行里没压过的短字段
     * （请求短、回复长是常态），只看前缀则失去防误判的校验位。
     *
     * 解压失败（数据被手工改过 / 截断）时返回占位提示，不抛异常 ——
     * 一条日志读不出来不该让整个详情接口 500。
     */
    public static function decodeBody($body, string $encoding = ''): ?string
    {
        if ($body === null) {
            return null;
        }
        $text = (string) $body;
        if ($text === '') {
            return null;
        }
        // 未压缩行 / 未压缩字段：原样返回
        if ($encoding !== self::ENC_GZIP_B64 || !str_starts_with($text, 'gz:')) {
            return $text;
        }

        $binary = base64_decode(substr($text, 3), true);
        if ($binary === false) {
            return '[正文解码失败：base64 非法]';
        }

        $plain = @gzdecode($binary);
        if ($plain === false) {
            return '[正文解码失败：gzip 数据损坏]';
        }

        return $plain;
    }

    /**
     * 单条正文的明文字符数（供列表显示体积，不返回正文本身）。
     *
     * 优先用写入时记下的 `req_body_length` / `res_body_length`（精确）。
     * 历史行没有这两个值（改造前落库），退化为：明文直接数、压缩态按半个长度提示，
     * 宁可显示得保守，也不要为了列表精确去解压 20 条正文。
     */
    public static function plainLength(?string $raw, string $encoding, int $stored = 0): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        if ($stored > 0) {
            return $stored;
        }
        if ($encoding !== self::ENC_GZIP_B64 || !str_starts_with($raw, 'gz:')) {
            return mb_strlen($raw);
        }

        // 历史压缩行：宁可显示得保守，也不要为了列表精确去解压 20 条正文。
        // 列表只用来判断"这条大不大"，精确值在详情里给。
        return (int) (strlen($raw) - 3);
    }

    /** 模型倍率表：'provider_key::model_id' => rate（同步时从上游采集，容器未注入时惰性自建） */
    private function rateMap(): array
    {
        $repo = $this->models ??= new ModelRepository($this->db);
        return $repo->rateMap();
    }

    /** 思维仓储（容器未注入时惰性自建），仅用于给留痕算脱敏标签 */
    private function personas(): PersonaRepository
    {
        return $this->personas ??= new PersonaRepository($this->db);
    }

    /** persona_id → 脱敏标签 的进程内缓存（一次请求可能连记多条日志） */
    private static array $labelCache = [];

    /**
     * 注入块在留痕里的替身标签：【系统内置破甲】/【本人自建客服】。
     *
     * 查不到（思维已被删除 / 库异常）时不抛错，退化成【思维 #id】——
     * 留痕是旁路，绝不能因为查不到名字而把一条调用日志整个丢掉。
     */
    public function personaLabel(int $personaId): string
    {
        if ($personaId <= 0) {
            return '【思维】';
        }
        if (isset(self::$labelCache[$personaId])) {
            return self::$labelCache[$personaId];
        }

        $row = null;
        try {
            $row = $this->personas()->find($personaId);
        } catch (\Throwable $e) {
            $row = null;
        }

        $label = $row === null
            ? PersonaMark::label('', 0, $personaId)
            : PersonaMark::label((string) ($row['name'] ?? ''), (int) ($row['user_id'] ?? 0), $personaId);

        return self::$labelCache[$personaId] = $label;
    }

    /**
     * 留痕脱敏 —— **已废弃，仅保留给 tools/redact-log-bodies.php 清理历史行**。
     *
     * 2026-09-30 起详情页要求显示完整思维正文，写入侧不再做替换。
     * 这个方法不再被 record() 调用，但工具脚本仍可用 PersonaMark 自行脱敏，
     * 所以此处只留说明、不留代码路径，避免后来者误以为写入侧还在脱敏。
     *
     * 若要清理历史行（改造前落库的标签态数据无法还原），跑：
     *   php tools/redact-log-bodies.php --apply
     */

    public function record(array $data): int
    {
        // 日志总开关：关闭后不再写入任何调用记录（返回 0 表示未落库）
        if (!$this->settings()->get('log_enabled', true)) {
            return 0;
        }

        $this->ensureSchema();

        $personaId = (int) ($data['persona_id'] ?? 0);

        // 内容留痕：正文**完整保留**（含思维注入块），只在超长时 gzip 压缩。
        // 早先这里会把注入块正文换成【系统内置X】标签，属于"省空间"的取舍；
        // 现在改为按需解压 + 列表/详情分离，省空间不再需要牺牲可读性。
        [$reqBody, $reqEnc, $reqLen] = $this->encodeBody($data['request_body'] ?? null);
        [$resBody, $resEnc, $resLen] = $this->encodeBody($data['response_body'] ?? null);
        // 两者编码一致时只记一个标记（绝大多数情况同压同不压）
        $bodyEnc = ($reqEnc !== self::ENC_RAW || $resEnc !== self::ENC_RAW)
            ? self::ENC_GZIP_B64
            : self::ENC_RAW;

        $id = $this->db->table(self::TABLE)->insert([
            'request_id'        => (string) ($data['request_id'] ?? ''),
            'provider_key'      => (string) ($data['provider_key'] ?? ''),
            'model'             => mb_substr((string) ($data['model'] ?? ''), 0, 160),
            // 客户端**原始** model（未经 resolveModel 解析）。用于回溯路由：
            // 「客户端发 A → 被解析成 provider/B」时，只看 model 列是查不出来的。
            'requested_model'   => mb_substr((string) ($data['requested_model'] ?? ''), 0, 160),
            'account_uid'       => (string) ($data['account_uid'] ?? ''),
            'account_nickname'  => (string) ($data['account_nickname'] ?? ''),
            'status'            => (string) ($data['status'] ?? 'SUCCESS'),
            'error_code'        => (string) ($data['error_code'] ?? ''),
            'error'             => mb_substr((string) ($data['error'] ?? ''), 0, 500),
            'streaming'         => !empty($data['streaming']) ? 1 : 0,
            'retry_count'       => (int) ($data['retry_count'] ?? 0),
            // 同一账号内的重采样轮次：与 retry_count（换账号次数）正交，用于区分
            // 「慢 = 重采样跑满」与「慢 = 单次上游调用本身长」——2026-09-30 排查预算
            // 护栏时因缺此列无法区分，一度误判（详见 Schema.php 该列注释）。
            'resample_count'    => (int) ($data['resample_count'] ?? 0),
            'duration_ms'       => (int) ($data['duration_ms'] ?? 0),
            'first_token_ms'    => (int) ($data['first_token_ms'] ?? 0),
            'prompt_tokens'     => (int) ($data['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($data['completion_tokens'] ?? 0),
            'total_tokens'      => (int) ($data['total_tokens'] ?? 0),
            'cache_read_tokens' => (int) ($data['cache_read_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($data['cache_write_tokens'] ?? 0),
            'source'            => mb_substr((string) ($data['source'] ?? ''), 0, 32),
            'session_key'       => mb_substr((string) ($data['session_key'] ?? ''), 0, 80),
            'persona_id'        => $personaId,
            'persona_hit'       => !empty($data['persona_hit']) ? 1 : 0,
            // 内容留痕（仅在「记录请求体」开启时由网关传入；null = 未记录）。
            // 完整保留思维正文，超 512 字节走 gzip+base64，读取侧按 log_body_encoding 还原。
            'request_body'      => $reqBody,
            'response_body'     => $resBody,
            // 明文字符数：写入时算好，列表读它即可，不必解压正文
            'req_body_length'   => $reqLen,
            'res_body_length'   => $resLen,
            'log_body_encoding' => $bodyEnc,
            // 收尾诊断：上游 finish_reason 与推理 token，用于区分「输出被截断」的真因
            'finish_reason'     => mb_substr((string) ($data['finish_reason'] ?? ''), 0, 32),
            'reasoning_tokens'  => (int) ($data['reasoning_tokens'] ?? 0),
            'client_ip'         => (string) ($data['client_ip'] ?? ''),
            'started_at'        => (int) ($data['started_at'] ?? now_ms()),
            'user_id'           => (int) ($data['user_id'] ?? Tenant::writeId()),
        ]);

        // 失败告警 webhook（新功能）：统一收敛在落库点，覆盖全部失败路径；
        // 内部自带开关检查、分桶节流与 try/catch 静默降级，不影响主链路。
        if (strtoupper((string) ($data['status'] ?? 'SUCCESS')) !== 'SUCCESS') {
            Alerter::onCallFailed($data);
        }

        return $id;
    }

    /**
     * 分页查询调用日志（**列表态：不带正文**）
     *
     * 为什么列表不给正文：请求正文可能带 8~10KB 思维注入块、回复正文可能几万字，
     * 一页 20 条全文就是几百 KB 的响应 —— 实测 189KB / 页，这是列表卡顿的唯一原因。
     * 列表只需要「有没有内容 / 大概多大」，正文交给 findDetail() 按需单条取。
     *
     * 性能收益有两层：
     *   ① 网络与 JSON 编码开销 —— 响应从 189KB 降到约 20KB；
     *   ② MySQL 读取 —— 不 SELECT request_body/response_body 就不读 MEDIUMTEXT
     *      溢出页，这正是拖慢查询的地方。
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 20, ?int $userId = null): array
    {
        $this->ensureSchema();

        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        $query = $this->applyFilters($this->db->table(self::TABLE), $filters, $userId);

        $total = $this->countCached($filters, $userId, $query);
        $rows = $query->orderBy('id', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get();

        // 列表态剔除正文，改用长度摘要占位。前端靠 has_content 决定「详情」按钮是否可用。
        $rows = array_map(function (array $row): array {
            $enc = (string) ($row['log_body_encoding'] ?? '');
            $row['req_body_length'] = self::plainLength(
                $row['request_body'] ?? null,
                $enc,
                (int) ($row['req_body_length'] ?? 0)
            );
            $row['res_body_length'] = self::plainLength(
                $row['response_body'] ?? null,
                $enc,
                (int) ($row['res_body_length'] ?? 0)
            );
            $row['has_content'] = ($row['req_body_length'] > 0 || $row['res_body_length'] > 0);
            // 列表仍返回一个短摘要，方便不点开也能扫一眼（走解压，代价可控：仅前 200 字）
            $row['request_preview'] = self::previewOf($row['request_body'] ?? null, $enc);
            $row['response_preview'] = self::previewOf($row['response_body'] ?? null, $enc);
            unset($row['request_body'], $row['response_body'], $row['log_body_encoding']);
            return $row;
        }, $rows);

        return [
            'items'    => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    /**
     * 分页总数：带缓存，但**保证不陈旧**。
     *
     * `SELECT COUNT(*)` 在筛过的范围上是随数据量线性变慢的，而它每次翻页都要跑一遍。
     * 这里把「当前最大 id」也编进缓存键：
     *   - 没有新日志 → 键不变 → 直接命中，跳过 COUNT(*)；
     *   - 一有新日志 → 键变化 → 立刻重算，不会出现「明明写入成功、总数却没变」。
     * MAX(id) 走主键索引，是 O(1) 的。
     *
     * 唯一的滞后场景是「只删不增」（prune 之后没有新日志），总数会停留在旧值，
     * 直到下一条日志写入 —— 属于可接受范围。
     *
     * @param mixed $query 已应用筛选的 QueryBuilder
     */
    private function countCached(array $filters, ?int $userId, $query): int
    {
        $maxId = (int) $this->db->scalar('SELECT COALESCE(MAX(`id`), 0) FROM `' . self::TABLE . '`');

        $cacheKey = 'count;max=' . $maxId . ';' . md5(json_encode([$filters, $userId])); 

        $hit = Cache::read('list_count', $cacheKey, self::COUNT_CACHE_TTL);

        if ($hit !== null && isset($hit['value']['n'])) {
            return (int) $hit['value']['n'];
        }

        $total = $query->count();

        Cache::write('list_count', $cacheKey, ['n' => $total]);

        return $total;
    }

    /**
     * 单条日志的完整详情（**含完整正文，含思维注入正文**）
     *
     * 供 `GET /api/admin/logs/detail?id=N` 使用。只有点开详情的这一条会走这里，
     * 所以可以放心解压还原全文 —— 单条 10KB 的解压成本在几十微秒量级。
     */
    public function findDetail(int $id, ?int $userId = null): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $this->ensureSchema();

        $query = $this->db->table(self::TABLE)->where('id', $id);
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        $row = $query->first();
        if ($row === null) {
            return null;
        }

        $enc = (string) ($row['log_body_encoding'] ?? '');
        $row['request_body'] = self::decodeBody($row['request_body'] ?? null, $enc);
        $row['response_body'] = self::decodeBody($row['response_body'] ?? null, $enc);
        $row['body_compressed'] = $enc === self::ENC_GZIP_B64;
        // 详情态给精确长度：此刻正文已解压，直接数即可，且必须覆盖写入时可能为 0 的历史行
        $row['req_body_length'] = $row['request_body'] === null ? 0 : mb_strlen((string) $row['request_body']);
        $row['res_body_length'] = $row['response_body'] === null ? 0 : mb_strlen((string) $row['response_body']);
        $row['has_content'] = ($row['req_body_length'] > 0 || $row['res_body_length'] > 0);
        unset($row['log_body_encoding']);

        return $row;
    }

    /**
     * 列表用正文摘要：解压后截前 N 字。
     *
     * 只对压缩态解压一次取头部，不是把全文拉出来再切 —— 这里必须 cheap，
     * 否则列表就白优化了。
     */
    private static function previewOf(?string $raw, string $encoding, int $limit = 200): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        $plain = (string) self::decodeBody($raw, $encoding);
        return mb_strlen($plain) > $limit ? mb_substr($plain, 0, $limit) . '…' : $plain;
    }

    /**
     * 导出用：按与分页相同的筛选条件取一批数据（默认 5000，硬上限 20000）
     */
    public function exportRows(array $filters = [], int $limit = 5000, ?int $userId = null): array
    {
        $limit = min(20000, max(1, $limit));
        $query = $this->applyFilters($this->db->table(self::TABLE), $filters, $userId);

        return $query->orderBy('id', 'DESC')->limit($limit, 0)->get();
    }

    /**
     * 构造「按 user_id 限定」的 SQL 片段（统计类手写 SQL 共用）。
     *
     * 三种形态必须严格区分，否则会静默放宽权限或静默返回全表：
     *  - $userId 非 null        → 单用户（普通用户会话作用域，最高优先）
     *  - $userIds 非 null 数组  → 多用户（管理员按关键词/ID 筛选）
     *  - $userIds === []        → 「筛了但一个都没匹配上」，必须 `1 = 0` 强制空集，
     *                             不能退化成「不加条件」= 全表（那等于筛选失效）
     *  - 两者都为 null          → 不限定（管理员默认视图）
     *
     * @return array{0:string,1:array<int,int>} [SQL 片段（含前导 AND/WHERE 分隔）, 绑定参数]
     */
    private static function userScopeSql(?int $userId, ?array $userIds, string $prefix = ' AND '): array
    {
        if ($userId !== null) {
            return [$prefix . '`user_id` = ?', [$userId]];
        }

        if ($userIds !== null) {
            $ids = [];
            foreach ($userIds as $v) {
                $v = (int) $v;
                if ($v > 0) {
                    $ids[$v] = true;
                }
            }
            $ids = array_keys($ids);
            if ($ids === []) {
                return [$prefix . '1 = 0', []];
            }
            return [
                $prefix . '`user_id` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                $ids,
            ];
        }

        return ['', []];
    }

    /**
     * 把筛选条件应用到查询构造器（分页与导出共用）
     *
     * 用户归属有两种入口：
     *  - $userId（单值）= 会话作用域，普通用户被强制锁定到本人，优先于 $filters；
     *  - $filters['user_ids']（数组）= 管理员的用户筛选（ID / 邮箱 / 昵称命中后解析而来），
     *    空数组表示「筛了但没命中任何人」→ 必须强制空集，不能静默变成全表。
     */
    private function applyFilters($query, array $filters, ?int $userId = null)
    {
        if ($userId !== null) {
            $query->where('user_id', $userId);
        } elseif (array_key_exists('user_ids', $filters)) {
            // 交给 QueryBuilder 的 IN 分支：空数组会自动落成 `1 = 0`
            $query->where('user_id', 'IN', (array) $filters['user_ids']);
        }

        $provider = trim((string) ($filters['provider'] ?? ''));
        if ($provider !== '') {
            $query->where('provider_key', $provider);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', strtoupper($status));
        }

        $model = trim((string) ($filters['model'] ?? ''));
        if ($model !== '') {
            $query->where('model', $model);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            // 简易关键词：模型名或错误信息
            $like = '%' . $keyword . '%';
            $query->whereRaw('(`model` LIKE ? OR `error` LIKE ?)', [$like, $like]);
        }

        if (!empty($filters['since'])) {
            $query->where('created_at', '>=', (string) $filters['since']);
        }
        if (!empty($filters['until'])) {
            $query->where('created_at', '<=', (string) $filters['until']);
        }

        return $query;
    }

    /**
     * 用量统计（对应 Lf2/a）
     *
     * @param int|null        $userId  单用户作用域（普通用户会话；null = 不限）
     * @param array|null      $userIds 多用户作用域（管理员按用户筛选后，统计跟着收窄）
     */
    public function stats(int $days = 7, ?int $userId = null, ?array $userIds = null): array
    {
        $this->ensureSchema();

        $since = date('Y-m-d 00:00:00', strtotime('-' . max(0, $days - 1) . ' days'));

        // 属主隔离：普通用户仅统计自己的调用；管理员不限或按筛选出的用户集合收窄
        [$scopeSql, $scopeParams] = self::userScopeSql($userId, $userIds);

        $overall = $this->db->first(
            'SELECT COUNT(*) AS calls,
                    SUM(`status` = \'SUCCESS\') AS success_calls,
                    SUM(`status` = \'FAILED\')  AS failed_calls,
                    COALESCE(SUM(`prompt_tokens`), 0) AS prompt_tokens,
                    COALESCE(SUM(`completion_tokens`), 0) AS completion_tokens,
                    COALESCE(SUM(`total_tokens`), 0) AS total_tokens,
                    COALESCE(SUM(`cache_read_tokens`), 0) AS cache_read_tokens,
                    COALESCE(SUM(`cache_write_tokens`), 0) AS cache_write_tokens,
                    COALESCE(AVG(`duration_ms`), 0) AS avg_duration
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql,
            array_merge([$since], $scopeParams)
        ) ?? [];

        $byProvider = $this->db->select(
            'SELECT `provider_key`,
                    COUNT(*) AS calls,
                    SUM(`status` = \'SUCCESS\') AS success_calls,
                    COALESCE(SUM(`prompt_tokens`), 0) AS prompt_tokens,
                    COALESCE(SUM(`completion_tokens`), 0) AS completion_tokens,
                    COALESCE(SUM(`total_tokens`), 0) AS total_tokens,
                    COALESCE(SUM(`cache_read_tokens`), 0) AS cache_read_tokens,
                    COALESCE(SUM(`cache_write_tokens`), 0) AS cache_write_tokens,
                    COALESCE(AVG(`duration_ms`), 0) AS avg_duration
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql . '
             GROUP BY `provider_key` ORDER BY calls DESC',
            array_merge([$since], $scopeParams)
        );

        $daily = $this->db->select(
            'SELECT DATE(`created_at`) AS `day`,
                    COUNT(*) AS calls,
                    SUM(`status` = \'SUCCESS\') AS success_calls,
                    COALESCE(SUM(`prompt_tokens`), 0) AS prompt_tokens,
                    COALESCE(SUM(`completion_tokens`), 0) AS completion_tokens,
                    COALESCE(SUM(`total_tokens`), 0) AS total_tokens,
                    COALESCE(SUM(`cache_read_tokens`), 0) AS cache_read_tokens,
                    COALESCE(SUM(`cache_write_tokens`), 0) AS cache_write_tokens
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql . '
             GROUP BY DATE(`created_at`) ORDER BY `day` ASC',
            array_merge([$since], $scopeParams)
        );

        // 账号消耗聚合：按账号（UID + 昵称）汇总调用数、Token 与缓存读写
        $byAccount = $this->db->select(
            'SELECT `account_uid`, MAX(`account_nickname`) AS `account_nickname`,
                    `provider_key`,
                    COUNT(*) AS calls,
                    SUM(`status` = \'SUCCESS\') AS success_calls,
                    SUM(`status` = \'FAILED\')  AS failed_calls,
                    COALESCE(SUM(`prompt_tokens`), 0) AS prompt_tokens,
                    COALESCE(SUM(`completion_tokens`), 0) AS completion_tokens,
                    COALESCE(SUM(`total_tokens`), 0) AS total_tokens,
                    COALESCE(SUM(`cache_read_tokens`), 0) AS cache_read_tokens,
                    COALESCE(SUM(`cache_write_tokens`), 0) AS cache_write_tokens
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql . '
             GROUP BY `account_uid`, `provider_key` ORDER BY total_tokens DESC LIMIT 50',
            array_merge([$since], $scopeParams)
        );

        // 四来源统计：WorkBuddy / WorkBuddy AI 国际版 / CodeBuddy CLI / CodeBuddy CN IDE
        $bySource = $this->db->select(
            'SELECT `source`,
                    COUNT(*) AS calls,
                    SUM(`status` = \'SUCCESS\') AS success_calls,
                    COALESCE(SUM(`prompt_tokens`), 0) AS prompt_tokens,
                    COALESCE(SUM(`completion_tokens`), 0) AS completion_tokens,
                    COALESCE(SUM(`total_tokens`), 0) AS total_tokens,
                    COALESCE(SUM(`cache_read_tokens`), 0) AS cache_read_tokens,
                    COALESCE(SUM(`cache_write_tokens`), 0) AS cache_write_tokens
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql . '
             GROUP BY `source` ORDER BY calls DESC',
            array_merge([$since], $scopeParams)
        );

        $topModels = $this->db->select(
            'SELECT `model`, `provider_key`, COUNT(*) AS calls,
                    COALESCE(SUM(`total_tokens`), 0) AS total_tokens
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql . '
             GROUP BY `model`, `provider_key` ORDER BY calls DESC LIMIT 8',
            array_merge([$since], $scopeParams)
        );

        // 按 Token 用量排行的模型榜（与调用次数榜互补，用于「用量深化」）
        $byModel = $this->db->select(
            'SELECT `provider_key`, `model`, COUNT(*) AS calls,
                    COALESCE(SUM(`prompt_tokens`), 0) AS prompt_tokens,
                    COALESCE(SUM(`completion_tokens`), 0) AS completion_tokens,
                    COALESCE(SUM(`total_tokens`), 0) AS total_tokens,
                    COALESCE(SUM(`cache_read_tokens`), 0) AS cache_read_tokens,
                    COALESCE(SUM(`cache_write_tokens`), 0) AS cache_write_tokens
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql . '
             GROUP BY `provider_key`, `model` ORDER BY total_tokens DESC LIMIT 12',
            array_merge([$since], $scopeParams)
        );

        // 每日 × 供应商 × 模型的 Token 明细，用于把 Token 折算成「每日费用」
        $dailyDetail = $this->db->select(
            'SELECT DATE(`created_at`) AS `day`, `provider_key`, `model`,
                    COALESCE(SUM(`total_tokens`), 0) AS total_tokens
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql . '
             GROUP BY DATE(`created_at`), `provider_key`, `model`',
            array_merge([$since], $scopeParams)
        );

        // 补齐缺失日期，保证前端折线图连续
        $dailyMap = [];
        foreach ($daily as $row) {
            $dailyMap[(string) $row['day']] = $row;
        }
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $row = $dailyMap[$day] ?? null;
            $series[] = [
                'day'               => $day,
                'calls'             => (int) ($row['calls'] ?? 0),
                'success_calls'     => (int) ($row['success_calls'] ?? 0),
                'prompt_tokens'     => (int) ($row['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($row['completion_tokens'] ?? 0),
                'total_tokens'      => (int) ($row['total_tokens'] ?? 0),
                'cache_read_tokens' => (int) ($row['cache_read_tokens'] ?? 0),
                'cache_write_tokens' => (int) ($row['cache_write_tokens'] ?? 0),
                'tokens'            => (int) ($row['total_tokens'] ?? 0),
                'cost'              => 0.0,
            ];
        }

        $overallOut = [
            'calls'             => (int) ($overall['calls'] ?? 0),
            'success_calls'     => (int) ($overall['success_calls'] ?? 0),
            'failed_calls'      => (int) ($overall['failed_calls'] ?? 0),
            'prompt_tokens'     => (int) ($overall['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($overall['completion_tokens'] ?? 0),
            'total_tokens'      => (int) ($overall['total_tokens'] ?? 0),
            'cache_read_tokens' => (int) ($overall['cache_read_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($overall['cache_write_tokens'] ?? 0),
            'avg_duration'      => (int) round((float) ($overall['avg_duration'] ?? 0)),
        ];
        // 前端使用的别名键
        $overallOut['total'] = $overallOut['calls'];
        $overallOut['tokens'] = $overallOut['total_tokens'];
        $overallOut['success_rate'] = $overallOut['calls'] > 0
            ? round($overallOut['success_calls'] / $overallOut['calls'] * 100, 1)
            : 0.0;

        // 按模型倍率把 Token 换算成计费量：cost = total_tokens × model_rate ÷ 1000
        // 倍率来自模型目录（上游同步时自动采集）；未采集到倍率的模型按 1.0（中性）计。
        $rates = $this->rateMap();

        $byProviderModel = $this->db->select(
            'SELECT `provider_key`, `model`, COALESCE(SUM(`total_tokens`), 0) AS total_tokens
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql . '
             GROUP BY `provider_key`, `model`',
            array_merge([$since], $scopeParams)
        );
        $costByProvider = [];
        $weightedByProvider = [];
        foreach ($byProviderModel as $r) {
            $key = (string) $r['provider_key'];
            $model = (string) $r['model'];
            $rate = $rates[$key . '::' . $model] ?? 1.0;
            $tokens = (int) $r['total_tokens'];
            $costByProvider[$key] = ($costByProvider[$key] ?? 0.0) + $tokens * $rate / 1000;
            $weightedByProvider[$key] = ($weightedByProvider[$key] ?? 0.0) + $tokens * $rate;
        }

        $byProviderOut = [];
        $overallCost = 0.0;
        foreach ($byProvider as $r) {
            $key = (string) $r['provider_key'];
            $tokens = (int) $r['total_tokens'];
            $cost = round($costByProvider[$key] ?? 0.0, 4);
            $overallCost += $cost;

            $byProviderOut[] = [
                'provider_key'  => $key,
                'calls'         => (int) $r['calls'],
                'success_calls' => (int) $r['success_calls'],
                'prompt_tokens' => (int) $r['prompt_tokens'],
                'completion_tokens' => (int) $r['completion_tokens'],
                'total_tokens'  => $tokens,
                'cache_read_tokens' => (int) $r['cache_read_tokens'],
                'cache_write_tokens' => (int) $r['cache_write_tokens'],
                'avg_duration'  => (int) round((float) $r['avg_duration']),
                'rate'          => $tokens > 0 ? round(($weightedByProvider[$key] ?? 0.0) / $tokens, 4) : 0.0,
                'cost'          => $cost,
            ];
        }
        $overallOut['cost'] = round($overallCost, 4);

        // 每日费用：把 (日 × 供应商 × 模型) 的 Token 明细按倍率折算后汇总到对应日期
        $dailyCost = [];
        $dailyWeighted = [];
        foreach ($dailyDetail as $r) {
            $day = (string) $r['day'];
            $key = (string) $r['provider_key'];
            $model = (string) $r['model'];
            $rate = $rates[$key . '::' . $model] ?? 1.0;
            $tokens = (int) $r['total_tokens'];
            $dailyCost[$day] = ($dailyCost[$day] ?? 0.0) + $tokens * $rate / 1000;
            $dailyWeighted[$day] = ($dailyWeighted[$day] ?? 0.0) + $tokens * $rate;
        }
        foreach ($series as $i => $row) {
            $day = (string) $row['day'];
            $series[$i]['cost'] = round($dailyCost[$day] ?? 0.0, 4);
            $series[$i]['avg_rate'] = $row['total_tokens'] > 0
                ? round(($dailyWeighted[$day] ?? 0.0) / $row['total_tokens'], 4)
                : 0.0;
        }

        // 按 Token 排行模型榜，附带提示词/补全拆分与费用
        $byModelOut = array_map(function ($r) use ($rates): array {
            $key = (string) $r['provider_key'];
            $model = (string) $r['model'];
            $rate = $rates[$key . '::' . $model] ?? 1.0;
            $tokens = (int) $r['total_tokens'];

            return [
                'model'             => $model,
                'provider_key'      => $key,
                'calls'             => (int) $r['calls'],
                'prompt_tokens'     => (int) $r['prompt_tokens'],
                'completion_tokens' => (int) $r['completion_tokens'],
                'total_tokens'      => $tokens,
                'cache_read_tokens' => (int) $r['cache_read_tokens'],
                'cache_write_tokens' => (int) $r['cache_write_tokens'],
                'tokens'            => $tokens,
                'rate'              => $rate,
                'cost'              => round($tokens * $rate / 1000, 4),
            ];
        }, $byModel);

        // 账号消耗聚合：按账号汇总 Token 与费用（费用按该供应商加权均倍率折算）
        $providerRate = [];
        foreach ($byProviderOut as $p) {
            $providerRate[$p['provider_key']] = (float) ($p['rate'] ?? 0.0);
        }
        $byAccountOut = array_map(function ($r) use ($providerRate): array {
            $key = (string) $r['provider_key'];
            $rate = $providerRate[$key] ?? 0.0;
            $tokens = (int) $r['total_tokens'];

            return [
                'account_uid'       => (string) $r['account_uid'],
                'account_nickname'  => (string) ($r['account_nickname'] ?? ''),
                'provider_key'      => $key,
                'calls'             => (int) $r['calls'],
                'success_calls'     => (int) $r['success_calls'],
                'failed_calls'      => (int) $r['failed_calls'],
                'prompt_tokens'     => (int) $r['prompt_tokens'],
                'completion_tokens' => (int) $r['completion_tokens'],
                'total_tokens'      => $tokens,
                'cache_read_tokens' => (int) $r['cache_read_tokens'],
                'cache_write_tokens' => (int) $r['cache_write_tokens'],
                'rate'              => $rate,
                'cost'              => round($tokens * $rate / 1000, 4),
            ];
        }, $byAccount);

        // 四来源统计（含友好名称）
        $bySourceOut = array_map(function ($r): array {
            $source = (string) $r['source'];
            return [
                'source'            => $source,
                'source_label'      => self::sourceLabel($source),
                'calls'             => (int) $r['calls'],
                'success_calls'     => (int) $r['success_calls'],
                'prompt_tokens'     => (int) $r['prompt_tokens'],
                'completion_tokens' => (int) $r['completion_tokens'],
                'total_tokens'      => (int) $r['total_tokens'],
                'cache_read_tokens' => (int) $r['cache_read_tokens'],
                'cache_write_tokens' => (int) $r['cache_write_tokens'],
            ];
        }, $bySource);

        return [
            'overall' => $overallOut,
            'by_provider' => $byProviderOut,
            'by_model' => $byModelOut,
            'by_account' => $byAccountOut,
            'by_source' => $bySourceOut,
            'daily' => $series,
            'top_models' => array_map(function ($r) use ($rates) {
                $key = (string) $r['provider_key'];
                $model = (string) $r['model'];
                $rate = $rates[$key . '::' . $model] ?? 1.0;
                $tokens = (int) $r['total_tokens'];

                return [
                    'model'        => $model,
                    'provider_key' => $key,
                    'calls'        => (int) $r['calls'],
                    'total_tokens' => $tokens,
                    'rate'         => $rate,
                    'cost'         => round($tokens * $rate / 1000, 4),
                ];
            }, $topModels),
        ];
    }

    /** 四来源友好名称 */
    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            'workbuddy'       => 'WorkBuddy',
            'workbuddy_intl'  => 'WorkBuddy AI 国际版',
            'codebuddy_cli'   => 'CodeBuddy CLI',
            'codebuddy_cn_ide' => 'CodeBuddy CN IDE',
            ''                => '未标识',
            default           => $source,
        };
    }

    /**
     * 清理过期日志（对应 LV1/k.ll）
     */
    public function prune(int $keepDays): int
    {
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
        return $this->db->statement('DELETE FROM `' . self::TABLE . '`');
    }

    /**
     * 行数上限保护（对应 log_max_rows）
     *
     * 超出上限时从最旧记录开始删除，只保留最新 $maxRows 条。
     * 0 表示不限制；删除量按 (总数 - 上限) 一次性算好，避免循环。
     */
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

    /**
     * 正文保留行数治理（对应 log_body_max_rows）
     *
     * 只把「超过最近 N 行」的旧日志**正文清空**，行本身与全部统计字段
     * （token / 耗时 / 状态 / 供应商 / 模型）原样保留。
     *
     * 这样做的意义：正文是日志表体积的绝对大头（实测占 95% 以上），但它只对
     * 「翻旧账看原文」有用；而成功率、平均耗时、Token 统计、错误分布这些
     * 大屏指标全部来自结构化列。所以清正文不会让任何一块大屏少一个数 ——
     * 这是「既不截断正文、又不让库膨胀」的关键取舍点。
     *
     * 幂等：只清 `log_body_encoding` 非空或正文非 NULL 的行，重复跑不会重复计数。
     * 0 表示不限制。
     */
    public function enforceBodyRowLimit(int $maxRows): int
    {
        if ($maxRows <= 0) {
            return 0;
        }

        $this->ensureSchema();

        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM `' . self::TABLE . '`');
        if ($total <= $maxRows) {
            return 0;
        }

        // 找到保留区与清理区的分界 id（保留区 = 最新 maxRows 条）
        $cutoff = (int) $this->db->scalar(
            'SELECT `id` FROM `' . self::TABLE . '` ORDER BY `id` DESC LIMIT 1 OFFSET ?',
            [$maxRows - 1]
        );
        if ($cutoff <= 0) {
            return 0;
        }

        return $this->db->statement(
            'UPDATE `' . self::TABLE . '`
                SET `request_body` = NULL, `response_body` = NULL, `log_body_encoding` = \'\',
                    `req_body_length` = 0, `res_body_length` = 0
              WHERE `id` <= ? AND (`request_body` IS NOT NULL OR `response_body` IS NOT NULL)',
            [$cutoff]
        );
    }

    /**
     * 正文占有情况（用于设置页显示与运维巡检）
     *
     * 返回行数、带正文的行数、正文占用字节估算 —— 都是聚合查询，
     * 不读 MEDIUMTEXT 全文，代价可控。
     */
    public function bodyFootprint(): array
    {
        $this->ensureSchema();

        $row = $this->db->first(
            'SELECT COUNT(*) AS `rows_total`,
                    SUM(CASE WHEN `request_body` IS NOT NULL OR `response_body` IS NOT NULL THEN 1 ELSE 0 END) AS `rows_with_body`,
                    COALESCE(SUM(CHAR_LENGTH(COALESCE(`request_body`, \'\')) + CHAR_LENGTH(COALESCE(`response_body`, \'\'))), 0) AS `body_chars`,
                    SUM(CASE WHEN `log_body_encoding` = ? THEN 1 ELSE 0 END) AS `rows_compressed`
               FROM `' . self::TABLE . '`',
            [self::ENC_GZIP_B64]
        ) ?? [];

        $chars = (int) ($row['body_chars'] ?? 0);

        return [
            'rows_total'      => (int) ($row['rows_total'] ?? 0),
            'rows_with_body'  => (int) ($row['rows_with_body'] ?? 0),
            'rows_compressed' => (int) ($row['rows_compressed'] ?? 0),
            'body_chars'      => $chars,
            // utf8mb4 中文按 3 字节估，仅用于给用户一个量级感
            'body_bytes'      => $chars * 3,
            'body_mb'         => round($chars * 3 / 1048576, 2),
        ];
    }

    /** 各归属用户的调用数量：user_id => count（仅统计 user_id > 0 的普通用户归属） */
    public function userCallCounts(): array
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

    /** 删除某用户归属的全部调用日志（删除用户时级联清理），返回删除数量 */
    public function deleteByUserId(int $userId): int
    {
        $this->ensureSchema();
        if ($userId <= 0) {
            return 0;
        }

        return $this->db->table(self::TABLE)->where('user_id', $userId)->delete();
    }

    /** 最近若干条，用于首页最近调用卡片 */
    public function recent(int $limit = 8, ?int $userId = null): array
    {
        $query = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        return $query->orderBy('id', 'DESC')->limit($limit)->get();
    }

    /**
     * 网关侧**自计量的真实用量**：按 (供应商, 账号 uid) 聚合本网关实际消耗的 token。
     *
     * ⚠️ 为什么必须有它（2026-10-01 血案）：
     *   某些上游（典型：ZCode）**不对外暴露派生 API Key 的用量端点** ——
     *   `billing/balance` 只反映「订阅计划桶」，而对话走的是**另一条独立计费池**，
     *   于是面板读到的额度**永远是满的**（`used_units` 恒 0），用户看到「用了额度但不变」。
     *   既然上游不给，就由网关自己记 —— 这是唯一能反映真实消耗的口径。
     *
     * @param  string|null $providerKey 限定供应商；null = 全部
     * @return array<string, array{calls:int,ok:int,failed:int,prompt:int,completion:int,total:int,cache_read:int,cache_write:int,reasoning:int,today_calls:int,today_total:int,first_at:string,last_at:string}>
     *         键为 `{providerKey}\x00{uid}`
     */
    public function usageByAccount(?string $providerKey = null, ?int $userId = null): array
    {
        $this->ensureSchema();

        $where  = [];
        $params = [];
        if ($providerKey !== null && $providerKey !== '') {
            $where[]  = '`provider_key` = ?';
            $params[] = $providerKey;
        }
        if ($userId !== null) {
            $where[]  = '`user_id` = ?';
            $params[] = $userId;
        }
        $sqlWhere = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

        $rows = $this->db->select(
            'SELECT `provider_key`, `account_uid`,
                    COUNT(*) AS calls,
                    SUM(`status` = \'SUCCESS\') AS ok,
                    SUM(`status` = \'FAILED\')  AS failed,
                    COALESCE(SUM(`prompt_tokens`), 0)     AS prompt_tokens,
                    COALESCE(SUM(`completion_tokens`), 0) AS completion_tokens,
                    COALESCE(SUM(`total_tokens`), 0)      AS total_tokens,
                    COALESCE(SUM(`cache_read_tokens`), 0) AS cache_read_tokens,
                    COALESCE(SUM(`cache_write_tokens`), 0) AS cache_write_tokens,
                    COALESCE(SUM(`reasoning_tokens`), 0)  AS reasoning_tokens,
                    COALESCE(SUM(CASE WHEN `created_at` >= CURDATE() THEN 1 ELSE 0 END), 0) AS today_calls,
                    COALESCE(SUM(CASE WHEN `created_at` >= CURDATE() THEN `total_tokens` ELSE 0 END), 0) AS today_tokens,
                    COALESCE(MIN(`created_at`), \'\') AS first_at,
                    COALESCE(MAX(`created_at`), \'\') AS last_at
             FROM `' . self::TABLE . '`' . $sqlWhere . '
             GROUP BY `provider_key`, `account_uid`',
            $params
        );

        $out = [];
        foreach ($rows as $r) {
            $key = (string) ($r['provider_key'] ?? '') . "\x00" . (string) ($r['account_uid'] ?? '');
            $out[$key] = [
                'calls'        => (int) ($r['calls'] ?? 0),
                'ok'           => (int) ($r['ok'] ?? 0),
                'failed'       => (int) ($r['failed'] ?? 0),
                'prompt'       => (int) ($r['prompt_tokens'] ?? 0),
                'completion'   => (int) ($r['completion_tokens'] ?? 0),
                'total'        => (int) ($r['total_tokens'] ?? 0),
                'cache_read'   => (int) ($r['cache_read_tokens'] ?? 0),
                'cache_write'  => (int) ($r['cache_write_tokens'] ?? 0),
                'reasoning'    => (int) ($r['reasoning_tokens'] ?? 0),
                // 今日口径（自然日 00:00 起），与全时段累计区分
                'today_calls'  => (int) ($r['today_calls'] ?? 0),
                'today_total'  => (int) ($r['today_tokens'] ?? 0),
                'first_at'     => (string) ($r['first_at'] ?? ''),
                'last_at'      => (string) ($r['last_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * 单个账号的网关侧用量（`usageByAccount` 的便捷封装）。
     */
    public function usageOfAccount(string $providerKey, string $uid): array
    {
        $all = $this->usageByAccount($providerKey);
        return $all[$providerKey . "\x00" . $uid] ?? [
            'calls' => 0, 'ok' => 0, 'failed' => 0,
            'prompt' => 0, 'completion' => 0, 'total' => 0,
            'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0,
            'today_calls' => 0, 'today_total' => 0,
            'first_at' => '', 'last_at' => '',
        ];
    }

    /* ============================================================
       大屏专项聚合
       ============================================================ */

    /**
     * 全时段累计（不受 days 窗口限制）——「累计调用 / 成功率 / 平均耗时 / 消耗 Token」
     * 这几张卡要的是"开站至今"，跟窗口统计分开算。
     *
     * 费用照旧：cost = total_tokens × model_rate ÷ 1000（未采集倍率按 1.0）。
     */
    public function allTime(?int $userId = null): array
    {
        $this->ensureSchema();

        $scopeSql = $userId !== null ? ' WHERE `user_id` = ?' : '';
        $scopeParams = $userId !== null ? [$userId] : [];

        $row = $this->db->first(
            'SELECT COUNT(*) AS calls,
                    SUM(`status` = \'SUCCESS\') AS success_calls,
                    SUM(`status` = \'FAILED\')  AS failed_calls,
                    SUM(`streaming` = 1)        AS stream_calls,
                    COUNT(DISTINCT `user_id`)   AS users,
                    COUNT(DISTINCT `provider_key`) AS providers,
                    COUNT(DISTINCT `model`)     AS models,
                    COUNT(DISTINCT `account_uid`)  AS accounts,
                    COALESCE(SUM(`prompt_tokens`), 0) AS prompt_tokens,
                    COALESCE(SUM(`completion_tokens`), 0) AS completion_tokens,
                    COALESCE(SUM(`total_tokens`), 0) AS total_tokens,
                    COALESCE(SUM(`cache_read_tokens`), 0) AS cache_read_tokens,
                    COALESCE(SUM(`cache_write_tokens`), 0) AS cache_write_tokens,
                    COALESCE(SUM(`reasoning_tokens`), 0) AS reasoning_tokens,
                    COALESCE(AVG(`duration_ms`), 0) AS avg_duration,
                    COALESCE(AVG(NULLIF(`first_token_ms`, 0)), 0) AS avg_first_token,
                    COALESCE(MIN(`created_at`), \'\') AS first_at,
                    COALESCE(MAX(`created_at`), \'\') AS last_at
             FROM `' . self::TABLE . '`' . $scopeSql,
            $scopeParams
        ) ?? [];

        $calls = (int) ($row['calls'] ?? 0);
        $success = (int) ($row['success_calls'] ?? 0);

        // 全时段费用：按 (供应商, 模型) 分组算，避免逐行扫全表
        $rates = $this->rateMap();
        $tokenRows = $this->db->select(
            'SELECT `provider_key`, `model`, COALESCE(SUM(`total_tokens`), 0) AS total_tokens
             FROM `' . self::TABLE . '`' . $scopeSql . '
             GROUP BY `provider_key`, `model`',
            $scopeParams
        );
        $cost = 0.0;
        foreach ($tokenRows as $r) {
            $rate = $rates[(string) $r['provider_key'] . '::' . (string) $r['model']] ?? 1.0;
            $cost += (int) $r['total_tokens'] * $rate / 1000;
        }

        return [
            'calls'              => $calls,
            'success_calls'      => $success,
            'failed_calls'       => (int) ($row['failed_calls'] ?? 0),
            'stream_calls'       => (int) ($row['stream_calls'] ?? 0),
            'users'              => (int) ($row['users'] ?? 0),
            'providers'          => (int) ($row['providers'] ?? 0),
            'models'             => (int) ($row['models'] ?? 0),
            'accounts'           => (int) ($row['accounts'] ?? 0),
            'prompt_tokens'      => (int) ($row['prompt_tokens'] ?? 0),
            'completion_tokens'  => (int) ($row['completion_tokens'] ?? 0),
            'total_tokens'       => (int) ($row['total_tokens'] ?? 0),
            'cache_read_tokens'  => (int) ($row['cache_read_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($row['cache_write_tokens'] ?? 0),
            'reasoning_tokens'   => (int) ($row['reasoning_tokens'] ?? 0),
            'avg_duration'       => (int) round((float) ($row['avg_duration'] ?? 0)),
            'avg_first_token'    => (int) round((float) ($row['avg_first_token'] ?? 0)),
            'cost'               => round($cost, 4),
            'success_rate'       => $calls > 0 ? round($success / $calls * 100, 1) : 0.0,
            'first_at'           => (string) ($row['first_at'] ?? ''),
            'last_at'            => (string) ($row['last_at'] ?? ''),
            // 别名，沿用前端既有键名习惯
            'total'              => $calls,
            'tokens'             => (int) ($row['total_tokens'] ?? 0),
        ];
    }

    /**
     * 24 小时分布（窗口内按小时聚合）——「什么时候最忙」一眼可见。
     * 返回定长 24 项，缺的小时补 0，前端直接用不缺口。
     */
    public function hourly(int $days = 7, ?int $userId = null): array
    {
        $this->ensureSchema();

        $since = date('Y-m-d 00:00:00', strtotime('-' . max(0, $days - 1) . ' days'));
        $scopeSql = $userId !== null ? ' AND `user_id` = ?' : '';
        $scopeParams = $userId !== null ? [$userId] : [];

        $rows = $this->db->select(
            'SELECT HOUR(`created_at`) AS `hour`,
                    COUNT(*) AS calls,
                    SUM(`status` = \'SUCCESS\') AS success_calls,
                    COALESCE(SUM(`total_tokens`), 0) AS total_tokens
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql . '
             GROUP BY HOUR(`created_at`) ORDER BY `hour` ASC',
            array_merge([$since], $scopeParams)
        );

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['hour']] = $r;
        }

        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $r = $map[$h] ?? null;
            $out[] = [
                'hour'          => $h,
                'label'         => str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00',
                'calls'         => (int) ($r['calls'] ?? 0),
                'success_calls' => (int) ($r['success_calls'] ?? 0),
                'total_tokens'  => (int) ($r['total_tokens'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * 耗时分布直方图（窗口内）——定位「慢在哪」。
     * 分档：<1s / 1-3s / 3-10s / 10-30s / 30-60s / >60s
     */
    public function durationBuckets(int $days = 7, ?int $userId = null): array
    {
        $this->ensureSchema();

        $since = date('Y-m-d 00:00:00', strtotime('-' . max(0, $days - 1) . ' days'));
        $scopeSql = $userId !== null ? ' AND `user_id` = ?' : '';
        $scopeParams = $userId !== null ? [$userId] : [];

        $row = $this->db->first(
            'SELECT
                SUM(`duration_ms` < 1000)  AS b1,
                SUM(`duration_ms` >= 1000  AND `duration_ms` < 3000)  AS b2,
                SUM(`duration_ms` >= 3000  AND `duration_ms` < 10000) AS b3,
                SUM(`duration_ms` >= 10000 AND `duration_ms` < 30000) AS b4,
                SUM(`duration_ms` >= 30000 AND `duration_ms` < 60000) AS b5,
                SUM(`duration_ms` >= 60000) AS b6
             FROM `' . self::TABLE . '` WHERE `created_at` >= ?' . $scopeSql,
            array_merge([$since], $scopeParams)
        ) ?? [];

        $defs = [
            ['label' => '<1s',   'key' => 'b1'],
            ['label' => '1-3s',  'key' => 'b2'],
            ['label' => '3-10s', 'key' => 'b3'],
            ['label' => '10-30s','key' => 'b4'],
            ['label' => '30-60s','key' => 'b5'],
            ['label' => '>60s',  'key' => 'b6'],
        ];

        $out = [];
        foreach ($defs as $d) {
            $out[] = ['label' => $d['label'], 'calls' => (int) ($row[$d['key']] ?? 0)];
        }

        return $out;
    }

    /**
     * 错误排行（窗口内）——错误码 + 出现次数 + 最近一次原文。
     * 只统计非成功记录，按次数倒序。
     */
    public function errorTop(int $days = 7, int $limit = 10, ?int $userId = null): array
    {
        $this->ensureSchema();

        $since = date('Y-m-d 00:00:00', strtotime('-' . max(0, $days - 1) . ' days'));
        $scopeSql = $userId !== null ? ' AND `user_id` = ?' : '';
        $scopeParams = $userId !== null ? [$userId] : [];

        $limit = max(1, min(50, $limit));

        $rows = $this->db->select(
            'SELECT `error_code`, MAX(`error`) AS `error`, COUNT(*) AS calls,
                    MAX(`created_at`) AS last_at
             FROM `' . self::TABLE . "`
             WHERE `created_at` >= ? AND `status` <> 'SUCCESS'" . $scopeSql . '
             GROUP BY `error_code` ORDER BY calls DESC LIMIT ' . $limit,
            array_merge([$since], $scopeParams)
        );

        return array_map(fn($r) => [
            'error_code' => (string) $r['error_code'],
            'error'      => (string) ($r['error'] ?? ''),
            'calls'      => (int) $r['calls'],
            'last_at'    => (string) ($r['last_at'] ?? ''),
        ], $rows);
    }

    /**
     * 大屏专用：窗口内按用户聚合的调用排行（管理员看全局时用）。
     * 「谁用得最多」——累计调用/Token/成功率/平均耗时一并给出。
     */
    public function byUser(int $days = 7, int $limit = 20): array
    {
        $this->ensureSchema();

        $since = date('Y-m-d 00:00:00', strtotime('-' . max(0, $days - 1) . ' days'));
        $limit = max(1, min(200, $limit));

        $rows = $this->db->select(
            'SELECT `user_id`,
                    COUNT(*) AS calls,
                    SUM(`status` = \'SUCCESS\') AS success_calls,
                    COALESCE(SUM(`total_tokens`), 0) AS total_tokens,
                    COALESCE(AVG(`duration_ms`), 0) AS avg_duration,
                    MAX(`created_at`) AS last_at
             FROM `' . self::TABLE . '` WHERE `created_at` >= ? AND `user_id` > 0
             GROUP BY `user_id` ORDER BY calls DESC LIMIT ' . $limit,
            [$since]
        );

        return array_map(fn($r) => [
            'user_id'       => (int) $r['user_id'],
            'calls'         => (int) $r['calls'],
            'success_calls' => (int) $r['success_calls'],
            'failed_calls'  => max(0, (int) $r['calls'] - (int) $r['success_calls']),
            'total_tokens'  => (int) $r['total_tokens'],
            'avg_duration'  => (int) round((float) $r['avg_duration']),
            'success_rate'  => (int) $r['calls'] > 0
                ? round((int) $r['success_calls'] / (int) $r['calls'] * 100, 1)
                : 0.0,
            'last_at'       => (string) ($r['last_at'] ?? ''),
        ], $rows);
    }
}
