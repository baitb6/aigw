<?php
/**
 * 账号实体：数据库行 + 解密后的凭据
 */

declare(strict_types=1);

namespace App\Provider\Dto;

use App\Support\Crypto;

class Account
{
    public function __construct(
        public int $id,
        public string $providerKey,
        public string $uid,
        public string $nickname,
        /** @var array<string, mixed> 解密后的凭据 */
        public array $credential,
        /** @var array<string, mixed> 非敏感附加信息 */
        public array $meta = [],
        public ?float $credits = null,
        public bool $creditsKnown = false,
        public string $creditsDetail = '',
        public bool $enabledByUser = true,
        public string $status = 'READY',
        public string $reason = '',
        public int $coolingUntil = 0,
        public int $errorCount = 0,
        public string $lastError = '',
        public int $lastRefreshAt = 0,
        public int $usedCount = 0,
        /** 积分到期时间（毫秒，0=未知/不过期） */
        public int $creditsExpireAt = 0,
        /** 指数退避下次可尝试时间（毫秒） */
        public int $nextRetryAt = 0,
        /** 在途租约到期时间（毫秒），防止并发重复占用 */
        public int $leaseUntil = 0,
        /** 最近签到时间（毫秒） */
        public int $checkinAt = 0,
        /** 最近一次计错时间（毫秒），用于错误计数时间窗衰减 */
        public int $lastErrorAt = 0,
        /** 归属用户 ID（0=管理员/全局） */
        public int $userId = 0,
    ) {}

    public static function fromRow(array $row): self
    {
        $meta = $row['meta'] ?? null;
        if (is_string($meta)) {
            $meta = json_decode($meta, true) ?: [];
        }

        return new self(
            id: (int) $row['id'],
            providerKey: (string) $row['provider_key'],
            uid: (string) $row['account_uid'],
            nickname: (string) ($row['nickname'] ?? ''),
            credential: Crypto::decryptArray($row['credential_enc'] ?? null),
            meta: is_array($meta) ? $meta : [],
            credits: $row['credits'] !== null ? (float) $row['credits'] : null,
            creditsKnown: (bool) ($row['credits_known'] ?? false),
            creditsDetail: (string) ($row['credits_detail'] ?? ''),
            enabledByUser: (bool) ($row['enabled_by_user'] ?? true),
            status: (string) ($row['status'] ?? 'READY'),
            reason: (string) ($row['reason'] ?? ''),
            coolingUntil: (int) ($row['cooling_until'] ?? 0),
            errorCount: (int) ($row['error_count'] ?? 0),
            lastError: (string) ($row['last_error'] ?? ''),
            lastRefreshAt: (int) ($row['last_refresh_at'] ?? 0),
            usedCount: (int) ($row['used_count'] ?? 0),
            creditsExpireAt: (int) ($row['credits_expire_at'] ?? 0),
            nextRetryAt: (int) ($row['next_retry_at'] ?? 0),
            leaseUntil: (int) ($row['lease_until'] ?? 0),
            checkinAt: (int) ($row['checkin_at'] ?? 0),
            lastErrorAt: (int) ($row['last_error_at'] ?? 0),
            userId: (int) ($row['user_id'] ?? 0),
        );
    }

    /**
     * 凭据取值的便捷方法，支持点号路径
     */
    public function cred(string $key, $default = null)
    {
        return $this->credential[$key] ?? $default;
    }

    public function meta(string $key, $default = null)
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * 用户头像地址：优先取 meta.avatar，其次 meta.user_info 内的常见头像字段
     */
    public function avatar(): string
    {
        $direct = $this->meta['avatar'] ?? null;
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        $info = $this->meta['user_info'] ?? null;
        if (is_array($info)) {
            foreach (['avatar', 'Avatar', 'avatar_url', 'avatarUrl', 'icon', 'Icon', 'head_url', 'headUrl'] as $key) {
                $value = $info[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /** 当前是否处于冷却窗口 */
    public function isCooling(): bool
    {
        return $this->coolingUntil > now_ms();
    }

    /**
     * 是否可被调度使用。
     *
     * ⚠️ 必须包含「凭据非空」这一条 —— 实测血案（2026-10-01）：
     * `tests/error-category.php` 的夹具残渣（`credential_enc IS NULL`、`status=READY`）
     * 因为本条判据不查凭据，被 `AccountPool` 当成**正常候选账号**；
     * 真实请求排队 120s 超时后按 `MAX_ATTEMPTS` 换号 → **降级命中空壳号** →
     * 对外报「账号缺少 accessToken，请重新登录」（UPSTREAM 502）。
     * 表现为「并发一高就有几笔莫名 502」，极易误判成并发机制缺陷。
     * 空凭据账号本来就调不通任何上游，**不该进候选池**。
     */
    public function isUsable(): bool
    {
        return $this->enabledByUser
            && $this->status !== 'DISABLED'
            && !$this->isCooling()
            && $this->hasCredential();
    }

    /**
     * 凭据是否非空（能真正用于调用上游）。
     *
     * 判据故意保持宽松：只要是**非空数组**即算有凭据 ——
     * 不同供应商的凭据结构差异极大（token / cookie / 派生 key / 多字段组合），
     * 在这里做字段级校验会误杀合法账号。这里只为挡住「完全没凭据」的空壳行。
     */
    public function hasCredential(): bool
    {
        foreach ($this->credential as $value) {
            if (is_array($value)) {
                if ($value !== []) {
                    return true;
                }
                continue;
            }
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /** 剩余冷却秒数 */
    public function coolingRemainSeconds(): int
    {
        $remain = $this->coolingUntil - now_ms();
        return $remain > 0 ? (int) ceil($remain / 1000) : 0;
    }

    /** 是否处于指数退避等待中 */
    public function isBackingOff(): bool
    {
        return $this->nextRetryAt > now_ms();
    }

    /** 今日是否已签到（按本地签到时间判断） */
    public function isCheckedInToday(): bool
    {
        if ($this->checkinAt <= 0) {
            return false;
        }
        return date('Y-m-d', (int) floor($this->checkinAt / 1000)) === date('Y-m-d');
    }

    /** 积分是否已知且将在 N 天内到期 */
    public function isExpiringWithin(int $days): bool
    {
        if ($this->creditsExpireAt <= 0) {
            return false;
        }
        return $this->creditsExpireAt <= now_ms() + $days * 86400000;
    }

    /** 到期剩余天数（未过期为正，已过期为负；0 表示未知） */
    public function expireDaysLeft(): ?int
    {
        if ($this->creditsExpireAt <= 0) {
            return null;
        }
        $diff = $this->creditsExpireAt - now_ms();
        return (int) ceil($diff / 86400000);
    }
}