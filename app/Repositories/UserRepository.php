<?php
/**
 * 普通用户仓储：自助注册、登录校验、资料维护与独立 API Key
 *
 * 与 AdminRepository 分离，互不影响：管理员仍走 aigw_admin，
 * 普通用户走 aigw_user，各自独立会话与独立对外调用密钥。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;
use RuntimeException;

class UserRepository
{
    private const TABLE = 'aigw_user';

    /** 用户独立 API Key 前缀（与管理端全局 Key 的 sk-aigw- 区分） */
    private const KEY_PREFIX = 'sk-user-';

    /** 账号不存在/被禁时的 dummy bcrypt 哈希（时序对齐用，无对应口令） */
    private const DUMMY_HASH = '$2y$10$d7j369NH..foAIoXLKhqrOusE0orrYVsD2A3vt4iOXev2rSJfsDqC';

    private bool $schemaChecked = false;

    public function __construct(private Database $db) {}

    /**
     * 老库升级：幂等补齐用户表与邮箱唯一索引（写路径调用即可）
     */
    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $this->schemaChecked = true;

        Schema::ensure($this->db);
        Schema::ensureUserEmailUnique($this->db);
        Schema::ensureColumns($this->db, self::TABLE, Schema::USER_COLUMNS);
    }

    public function find(int $id): ?array
    {
        return $this->db->table(self::TABLE)->where('id', $id)->first();
    }

    public function findByUsername(string $username): ?array
    {
        return $this->db->table(self::TABLE)->where('username', $username)->first();
    }

    public function findByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        return $this->db->table(self::TABLE)->where('email', $email)->first();
    }

    public function findByApiKey(string $apiKey): ?array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return null;
        }

        return $this->db->table(self::TABLE)->where('api_key', $apiKey)->first();
    }

    /** 是否为普通用户独立 API Key（sk-user-* 前缀），用于网关鉴权分流 */
    public function isUserKey(?string $apiKey): bool
    {
        return $apiKey !== null && str_starts_with(trim($apiKey), self::KEY_PREFIX);
    }

    /** 按 API Key 取「有效用户」：不存在或已禁用返回 null */
    public function findActiveByApiKey(string $apiKey): ?array
    {
        $user = $this->findByApiKey($apiKey);
        if ($user === null) {
            return null;
        }

        if ((string) ($user['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            return null;
        }

        return $user;
    }

    /**
     * 校验账号密码，成功返回记录（仅 ACTIVE），失败返回 null
     *
     * 账号标识既支持邮箱（注册主身份），也兼容历史用户名。
     */
    public function attempt(string $identifier, string $password): ?array
    {
        $identifier = trim($identifier);
        $user = str_contains($identifier, '@')
            ? $this->findByEmail($identifier)
            : $this->findByUsername($identifier);
        if ($user === null) {
            // 时序侧信道修复（审计 L-1）：账号不存在也执行一次 dummy verify，
            // 抹平「存在与否」的耗时差，防止枚举已注册邮箱/用户名。
            password_verify($password, self::DUMMY_HASH);
            return null;
        }

        if ((string) ($user['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            // 禁用账号同样走 dummy verify，避免「账号存在但被禁」被时序区分出来
            password_verify($password, self::DUMMY_HASH);
            return null;
        }

        if (!password_verify($password, (string) $user['password'])) {
            return null;
        }

        return $user;
    }

    /**
     * 创建用户：以邮箱为主身份，用户名由邮箱自动派生；密码 password_hash 存储，自动分配唯一 API Key
     */
    public function create(string $email, string $password, string $nickname = ''): array
    {
        $this->ensureSchema();

        $email = trim($email);
        $username = $this->deriveUsername($email);

        $id = $this->db->table(self::TABLE)->insert([
            'username' => $username,
            'email'    => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'nickname' => $nickname !== '' ? $nickname : $username,
            'api_key'  => $this->generateUniqueApiKey(),
            'status'   => 'ACTIVE',
        ]);

        return $this->find((int) $id) ?? [];
    }

    /**
     * 由邮箱派生一个唯一用户名（内部句柄，登录仍以邮箱为主）
     */
    private function deriveUsername(string $email): string
    {
        $local = strstr($email, '@', true);
        $local = is_string($local) && $local !== '' ? $local : 'user';

        $base = preg_replace('/[^A-Za-z0-9_]/', '_', $local) ?? '';
        $base = trim($base, '_');
        if (strlen($base) < 3) {
            $base = 'user_' . $base;
        }
        $base = substr($base, 0, 24);

        $candidate = $base;
        $i = 1;
        while ($this->findByUsername($candidate) !== null) {
            $suffix = '_' . $i;
            $candidate = substr($base, 0, 24 - strlen($suffix)) . $suffix;
            $i++;
        }

        return $candidate;
    }

    public function updatePassword(int $id, string $plainPassword): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->update([
            'password' => password_hash($plainPassword, PASSWORD_DEFAULT),
        ]);
    }

    /**
     * 改密码 + 抬会话纪元（**原子的一步**，改密场景一律走这里）。
     *
     * 为什么不拆成「先 updatePassword 再 bump」两步：
     *   中间任何一步失败（或将来有人只改了密码忘了抬纪元）都不会报错，
     *   表现为「密码已换、但别处旧会话还在用」的静默漂移 —— 属安全缺口而非功能故障，
     *   最难发现。合成一条 UPDATE 后就不存在「只做一半」的中间态。
     *
     * 返回抬升后的纪元值，供调用方回写当前会话（否则改密者本人也会被立刻踢下线）。
     */
    public function changePasswordAndBumpEpoch(int $id, string $plainPassword): int
    {
        $this->ensureSchema();

        $this->db->statement(
            'UPDATE `' . self::TABLE . '` SET `password` = ?, `session_epoch` = `session_epoch` + 1 WHERE `id` = ?',
            [password_hash($plainPassword, PASSWORD_DEFAULT), $id]
        );

        return $this->sessionEpoch($id);
    }

    /** 读取当前会话纪元（0 = 从未改过密码） */
    public function sessionEpoch(int $id): int
    {
        $this->ensureSchema();
        return (int) $this->db->scalar(
            'SELECT `session_epoch` FROM `' . self::TABLE . '` WHERE `id` = ?',
            [$id]
        );
    }

    public function updateProfile(int $id, string $nickname, string $email): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->where('id', $id)->update([
            'nickname' => $nickname,
            'email'    => $email,
        ]);
    }

    public function touchLogin(int $id, string $ip): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->update([
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ]);
    }

    /**
     * 重新生成用户独立 API Key（旧 Key 立即失效）
     */
    public function regenerateApiKey(int $id): string
    {
        $key = $this->generateUniqueApiKey();
        $this->db->table(self::TABLE)->where('id', $id)->update(['api_key' => $key]);

        return $key;
    }

    public function setStatus(int $id, string $status): void
    {
        $status = strtoupper($status) === 'DISABLED' ? 'DISABLED' : 'ACTIVE';
        $this->db->table(self::TABLE)->where('id', $id)->update(['status' => $status]);
    }

    public function delete(int $id): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->delete();
    }

    /** 全量用户（管理员视图） */
    public function all(): array
    {
        return $this->db->table(self::TABLE)->orderBy('id', 'DESC')->get();
    }

    public function count(): int
    {
        return (int) $this->db->table(self::TABLE)->count();
    }

    /**
     * 批量取用户，返回 id => 记录 的映射（调用日志列表补「归属用户」用）。
     *
     * 传入的 id 去重、过滤非正数；一次 `IN` 查询搞定，避免每行查一次库。
     *
     * @param  array<int,int|string> $ids
     * @return array<int,array>      键为用户 id
     */
    public function mapByIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = true;
            }
        }
        if ($clean === []) {
            return [];
        }

        $rows = $this->db->table(self::TABLE)
            ->where('id', 'IN', array_keys($clean))
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }

        return $map;
    }

    /**
     * 按关键词解析出用户 id 列表（管理台按用户筛选调用日志/审计用）。
     *
     * 语义与列表页 paginate 的关键词保持一致，避免「列表搜得到、筛选搜不到」：
     *  - 纯数字 → 按主键 ID 精确命中；
     *  - 其他   → 邮箱 / 昵称 / 用户名 模糊匹配。
     *
     * 返回空数组表示确实没匹配到人（上层必须据此筛出空结果，而不是当没筛）。
     *
     * @return array<int,int>
     */
    public function searchIds(string $keyword, int $limit = 500): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        $limit = max(1, min(2000, $limit));
        $query = $this->db->table(self::TABLE);

        if (ctype_digit($keyword)) {
            $like = '%' . $keyword . '%';
            $query->whereRaw('(`id` = ? OR `email` LIKE ? OR `nickname` LIKE ? OR `username` LIKE ?)', [
                (int) $keyword, $like, $like, $like,
            ]);
        } else {
            $like = '%' . $keyword . '%';
            $query->whereRaw('(`email` LIKE ? OR `nickname` LIKE ? OR `username` LIKE ?)', [$like, $like, $like]);
        }

        $rows = $query->orderBy('id', 'ASC')->limit($limit)->get();

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * 管理员视图：按关键词 / 状态分页列出用户
     *
     * @param array{keyword?:string,status?:string} $filters
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        $query = $this->db->table(self::TABLE);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            // 纯数字关键词视为「按用户 ID 精确查找」——管理台列表里 ID 就是主键，
            // 拿数字去 LIKE 邮箱/昵称几乎必然搜不到，反而让人以为「这个 ID 不存在」。
            if (ctype_digit($keyword)) {
                $query->whereRaw('(`id` = ? OR `email` LIKE ? OR `nickname` LIKE ? OR `username` LIKE ?)', [
                    (int) $keyword, '%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%',
                ]);
            } else {
                $like = '%' . $keyword . '%';
                $query->whereRaw('(`email` LIKE ? OR `nickname` LIKE ? OR `username` LIKE ?)', [$like, $like, $like]);
            }
        }

        $status = strtoupper(trim((string) ($filters['status'] ?? '')));
        if ($status === 'ACTIVE' || $status === 'DISABLED') {
            $query->where('status', $status);
        }

        $total = $query->count();
        $rows = $query->orderBy('id', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get();

        return [
            'items'    => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    /** 用户状态汇总：总数 / 启用 / 禁用 */
    public function statusCounts(): array
    {
        $total = (int) $this->db->table(self::TABLE)->count();
        $active = (int) $this->db->table(self::TABLE)->where('status', 'ACTIVE')->count();

        return ['total' => $total, 'active' => $active, 'disabled' => max(0, $total - $active)];
    }

    /**
     * 管理员更新用户资料 / 状态（仅允许白名单字段，邮箱冲突由调用方校验）
     *
     * @param array{nickname?:string,email?:string,status?:string} $fields
     */
    public function updateAdmin(int $id, array $fields): void
    {
        $data = [];
        if (array_key_exists('nickname', $fields)) {
            $data['nickname'] = (string) $fields['nickname'];
        }
        if (array_key_exists('email', $fields)) {
            $data['email'] = (string) $fields['email'];
        }
        if (array_key_exists('status', $fields)) {
            $data['status'] = strtoupper((string) $fields['status']) === 'DISABLED' ? 'DISABLED' : 'ACTIVE';
        }
        if ($data === []) {
            return;
        }

        $this->db->table(self::TABLE)->where('id', $id)->update($data);
    }

    private function generateUniqueApiKey(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $key = self::KEY_PREFIX . bin2hex(random_bytes(24));
            if ($this->findByApiKey($key) === null) {
                return $key;
            }
        }

        throw new RuntimeException('生成用户 API Key 失败');
    }
}
