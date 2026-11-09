<?php
/**
 * 账号池仓储（对应 account/<providerId>/<uid>.json 与 pool/state/<providerId>.json）
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Provider\Dto\Account;
use App\Support\Crypto;
use App\Support\Logger;
use App\Support\Schema;
use App\Support\Tenant;

class AccountRepository
{
    private const TABLE = 'aigw_account';

    /**
     * 错误计数衰减窗口（毫秒）：距上次计错超过此窗口，则本次错误视为新一轮的首个。
     *
     * 默认 30 分钟 —— 与「连续错误」的语义对齐：只有**短窗口内密集发生**的失败
     * 才应触发熔断。相隔数小时的偶发抖动不该累加，否则长期健康的账号会因
     * 缓慢攒够 max_error_count 而被莫名冷却。
     */
    private const ERROR_DECAY_WINDOW_MS = 30 * 60 * 1000;

    private bool $schemaChecked = false;

    public function __construct(private Database $db) {}

    /**
     * 老库升级：幂等补齐新增表（签到/冷却/粘性/旅行）与账号表新增列（积分到期/退避/租约/签到时间）。
     * 每进程只执行一次 information_schema 检查。
     */
    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $this->schemaChecked = true;

        Schema::ensure($this->db);
        Schema::ensureColumns($this->db, self::TABLE, Schema::ACCOUNT_COLUMNS);
    }

    /** @return Account[] */
    public function all(?string $providerKey = null, ?int $userId = null): array
    {
        $this->ensureSchema();

        $query = $this->db->table(self::TABLE);
        if ($providerKey !== null) {
            $query->where('provider_key', $providerKey);
        }
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return array_map(
            fn(array $row) => Account::fromRow($row),
            $query->orderBy('id', 'ASC')->get()
        );
    }

    /**
     * 可用账号，按调度模式排序
     * - balance ：额度优先（默认），额度未知的排在已知有额度的后面
     * - rotation：轮询，按 used_count 升序（用得少的优先），保证多账号均匀分摊
     * - pinned   ：固定账号优先，其次才按额度排序兜底
     * - expiry   ：按积分到期日分层，临期积分优先消耗
     *
     * 处于「指数退避」等待窗口（next_retry_at 未到）的账号视为未就绪，暂不参与调度。
     *
     * @return Account[]
     */
    public function usable(?string $providerKey = null, string $mode = 'balance', string $pinnedUid = '', ?int $userId = null): array
    {
        $accounts = array_filter(
            $this->all($providerKey, $userId),
            fn(Account $a) => $a->isUsable() && !$a->isBackingOff()
        );

        $accounts = array_values($accounts);

        if ($mode === 'pinned' && $pinnedUid !== '') {
            $accounts = $this->putPinnedFirst($accounts, $pinnedUid);
        }

        return match ($mode) {
            'rotation' => $this->sortByRotation($accounts),
            'expiry'   => $this->sortByExpiry($accounts),
            default    => $this->sortByCredits($accounts),
        };
    }

    /**
     * 到期日分层排序（expiry 模式）：按积分最近到期时间分层，到期越早越先用，
     * 避免临期积分作废；无到期信息（0）或到期较远的账号排在其后，同层内额度高者优先。
     *
     * @param Account[] $accounts
     * @return Account[]
     */
    private function sortByExpiry(array $accounts): array
    {
        usort($accounts, function (Account $a, Account $b) {
            $aExp = $a->creditsExpireAt > 0 ? $a->creditsExpireAt : PHP_INT_MAX;
            $bExp = $b->creditsExpireAt > 0 ? $b->creditsExpireAt : PHP_INT_MAX;
            if ($aExp !== $bExp) {
                return $aExp <=> $bExp;
            }
            $aScore = $a->creditsKnown ? ($a->credits ?? 0) : -1;
            $bScore = $b->creditsKnown ? ($b->credits ?? 0) : -1;
            if ($aScore === $bScore) {
                return $a->errorCount <=> $b->errorCount;
            }
            return $bScore <=> $aScore;
        });

        return $accounts;
    }

    /**
     * 额度优先排序（balance 模式）。固定账号已前置时不重复排序会打乱，故 pinned 模式仅对非固定部分排序。
     *
     * @param Account[] $accounts
     * @return Account[]
     */
    private function sortByCredits(array $accounts): array
    {
        usort($accounts, function (Account $a, Account $b) {
            $aScore = $a->creditsKnown ? ($a->credits ?? 0) : -1;
            $bScore = $b->creditsKnown ? ($b->credits ?? 0) : -1;
            if ($aScore === $bScore) {
                return $a->errorCount <=> $b->errorCount;
            }
            return $bScore <=> $aScore;
        });

        return $accounts;
    }

    /**
     * 轮询排序（rotation 模式）：used_count 升序，最少使用者优先；
     * 平局时额度高者优先，再平局则临期积分（到期更早）优先，最后比错误数。
     *
     * @param Account[] $accounts
     * @return Account[]
     */
    private function sortByRotation(array $accounts): array
    {
        usort($accounts, function (Account $a, Account $b) {
            if ($a->usedCount !== $b->usedCount) {
                return $a->usedCount <=> $b->usedCount;
            }
            $aScore = $a->creditsKnown ? ($a->credits ?? 0) : -1;
            $bScore = $b->creditsKnown ? ($b->credits ?? 0) : -1;
            if ($aScore !== $bScore) {
                return $bScore <=> $aScore;
            }
            $aExp = $a->creditsExpireAt > 0 ? $a->creditsExpireAt : PHP_INT_MAX;
            $bExp = $b->creditsExpireAt > 0 ? $b->creditsExpireAt : PHP_INT_MAX;
            if ($aExp !== $bExp) {
                return $aExp <=> $bExp;
            }
            return $a->errorCount <=> $b->errorCount;
        });

        return $accounts;
    }

    /**
     * 将指定 uid 的账号移到最前，其余保持不变（pinned 模式兜底）
     *
     * @param Account[] $accounts
     * @return Account[]
     */
    private function putPinnedFirst(array $accounts, string $pinnedUid): array
    {
        $pinned = [];
        $rest   = [];
        foreach ($accounts as $account) {
            if ($account->uid === $pinnedUid) {
                $pinned[] = $account;
            } else {
                $rest[] = $account;
            }
        }

        return array_merge($pinned, $this->sortByCredits($rest));
    }

    public function find(int $id): ?Account
    {
        $this->ensureSchema();

        $row = $this->db->table(self::TABLE)->where('id', $id)->first();
        return $row ? Account::fromRow($row) : null;
    }

    public function findByUid(string $providerKey, string $uid): ?Account
    {
        $row = $this->db->table(self::TABLE)
            ->where('provider_key', $providerKey)
            ->where('account_uid', $uid)
            ->first();
        return $row ? Account::fromRow($row) : null;
    }

    /**
     * 跨供应商按 uid 查找账号（uid 仅在供应商内唯一，这里取第一条）
     */
    public function findByUidByAny(string $uid): ?Account
    {
        if ($uid === '') {
            return null;
        }
        $row = $this->db->table(self::TABLE)->where('account_uid', $uid)->first();
        return $row ? Account::fromRow($row) : null;
    }

    /**
     * 新增或更新账号凭据（登录成功后调用）
     */
    public function upsertCredential(string $providerKey, string $uid, array $credential, array $meta = [], string $nickname = '', ?int $userId = null): int
    {
        $this->ensureSchema();
        $userId ??= Tenant::writeId();

        $existing = $this->db->table(self::TABLE)
            ->where('provider_key', $providerKey)
            ->where('account_uid', $uid)
            ->first();

        $payload = [
            'provider_key'   => $providerKey,
            'account_uid'    => $uid,
            'nickname'       => $nickname,
            'credential_enc' => Crypto::encryptArray($credential) ?? '',
            'meta'           => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'status'         => 'READY',
            'reason'         => '',
            'cooling_until'  => 0,
            'error_count'    => 0,
            'last_error'     => '',
        ];

        if ($existing !== null) {
            // 同 uid 账号全局唯一：非管理员不得覆盖他人归属的账号（防串号 / 越权接管）
            $owner = (int) ($existing['user_id'] ?? 0);
            if ($owner !== $userId && !Tenant::isAdmin()) {
                throw new \RuntimeException('该账号已归属于其他用户，无法重复添加');
            }
            unset($payload['provider_key'], $payload['account_uid']);
            $this->db->table(self::TABLE)->where('id', (int) $existing['id'])->update($payload);
            return (int) $existing['id'];
        }

        $payload['enabled_by_user'] = 1;
        $payload['user_id'] = $userId;
        return $this->db->table(self::TABLE)->insert($payload);
    }

    /**
     * 手工添加账号（自定义供应商 / 手工粘贴 Token）
     */
    public function create(string $providerKey, string $uid, array $credential, array $meta = [], string $nickname = '', bool $enabled = true, ?int $userId = null): int
    {
        $userId ??= Tenant::writeId();

        return $this->db->table(self::TABLE)->insert([
            'provider_key'    => $providerKey,
            'account_uid'     => $uid,
            'nickname'        => $nickname,
            'credential_enc'  => Crypto::encryptArray($credential) ?? '',
            'meta'            => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'enabled_by_user' => $enabled ? 1 : 0,
            'status'          => 'READY',
            'user_id'         => $userId,
        ]);
    }

    public function updateCredential(int $id, array $credential, ?array $meta = null): void
    {
        $payload = ['credential_enc' => Crypto::encryptArray($credential) ?? ''];
        if ($meta !== null) {
            $payload['meta'] = json_encode($meta, JSON_UNESCAPED_UNICODE);
        }
        $this->db->table(self::TABLE)->where('id', $id)->update($payload);
    }

    /** 合并更新凭据中的部分字段（如刷新后的 token） */
    public function patchCredential(Account $account, array $patch): void
    {
        if (empty($patch)) {
            return;
        }
        $merged = array_merge($account->credential, $patch);
        $this->updateCredential($account->id, $merged);
    }

    public function updateMeta(int $id, array $meta): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->update([
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** 合并更新 meta 中的部分字段（如额度用量/权益包快照） */
    public function patchMeta(Account $account, array $patch): void
    {
        if (empty($patch)) {
            return;
        }
        $this->updateMeta($account->id, array_merge($account->meta, $patch));
    }

    public function updateStatus(int $id, array $data): void
    {
        $allowed = [
            'status', 'reason', 'cooling_until', 'error_count', 'last_error',
            'credits', 'credits_known', 'credits_detail', 'last_refresh_at',
            'enabled_by_user', 'nickname',
            'credits_expire_at', 'next_retry_at', 'lease_until', 'checkin_at', 'last_error_at',
        ];
        $payload = array_intersect_key($data, array_flip($allowed));
        if (empty($payload)) {
            return;
        }

        foreach (['cooling_until', 'error_count', 'last_refresh_at', 'credits_expire_at', 'next_retry_at', 'lease_until', 'checkin_at', 'last_error_at'] as $intField) {
            if (isset($payload[$intField])) {
                $payload[$intField] = (int) $payload[$intField];
            }
        }
        if (isset($payload['credits_known'])) {
            $payload['credits_known'] = $payload['credits_known'] ? 1 : 0;
        }
        if (isset($payload['enabled_by_user'])) {
            $payload['enabled_by_user'] = $payload['enabled_by_user'] ? 1 : 0;
        }

        $this->db->table(self::TABLE)->where('id', $id)->update($payload);
    }

    /**
     * 标记额度耗尽 → 长冷却
     */
    public function markQuotaExhausted(Account $account, string $message, int $cooldownMs): void
    {
        $this->updateStatus($account->id, [
            'status'        => 'COOLING',
            'reason'        => '额度耗尽',
            'cooling_until' => now_ms() + $cooldownMs,
            'last_error'    => mb_substr($message, 0, 500),
        ]);
        Logger::info('账号进入额度冷却', ['uid' => $account->uid, 'provider' => $account->providerKey, 'cooldown_ms' => $cooldownMs]);
    }

    /**
     * 标记额度耗尽 → 硬冷却到指定时间点（如次日 04:00 额度重置时刻）。
     * 与 markQuotaExhausted 的区别：冷却终点由调用方计算，便于对齐上游额度重置节奏。
     */
    public function markQuotaExhaustedUntil(Account $account, string $message, int $untilMs): void
    {
        $this->updateStatus($account->id, [
            'status'        => 'COOLING',
            'reason'        => '额度耗尽',
            'cooling_until' => $untilMs,
            'last_error'    => mb_substr($message, 0, 500),
        ]);
        Logger::info('账号进入额度硬冷却', ['uid' => $account->uid, 'provider' => $account->providerKey, 'until' => $untilMs]);
    }

    /**
     * 标记资源不存在（404）→ 软冷却，避免持续命中同一异常账号
     */
    public function markNotFound(Account $account, string $message, int $cooldownMs): void
    {
        $this->updateStatus($account->id, [
            'status'        => 'COOLING',
            'reason'        => '资源不存在',
            'cooling_until' => now_ms() + $cooldownMs,
            'last_error'    => mb_substr($message, 0, 500),
        ]);
        Logger::info('账号资源不存在，进入软冷却', ['uid' => $account->uid, 'provider' => $account->providerKey]);
    }

    /**
     * 标记软限流 → 短冷却
     */
    public function markSoftRateLimited(Account $account, string $message, int $cooldownMs): void
    {
        $this->updateStatus($account->id, [
            'status'        => 'COOLING',
            'reason'        => '触发限流',
            'cooling_until' => now_ms() + $cooldownMs,
            'last_error'    => mb_substr($message, 0, 500),
        ]);
        Logger::info('账号进入限流冷却', ['uid' => $account->uid, 'provider' => $account->providerKey]);
    }

    /**
     * 标记上游风控拦截 → 短冷却。
     *
     * 与 markSoftRateLimited 的区别仅在 reason 文案：这条是**上游风控系统**
     * （豆包 shark_admin / 710022004）把该账号标记了，不是"请求太快"。
     * 语义分清楚，排查时一眼能看出"该换号"而不是"该降速"。
     */
    public function markRiskControl(Account $account, string $message, int $cooldownMs): void
    {
        $this->updateStatus($account->id, [
            'status'        => 'COOLING',
            'reason'        => '上游风控拦截',
            'cooling_until' => now_ms() + $cooldownMs,
            'last_error'    => mb_substr($message, 0, 500),
        ]);
        Logger::warn('账号被上游风控标记，进入冷却', [
            'uid' => $account->uid,
            'provider' => $account->providerKey,
            'cooldown_ms' => $cooldownMs,
        ]);
    }

    /**
     * 标记登录失效 → 禁用，等待重新登录
     */
    public function markSessionDead(Account $account, string $message): void
    {
        $this->updateStatus($account->id, [
            'status'     => 'DISABLED',
            'reason'     => '登录已失效',
            'last_error' => mb_substr($message, 0, 500),
        ]);
        Logger::warn('账号登录失效', ['uid' => $account->uid, 'provider' => $account->providerKey]);
    }

    /**
     * 记录一次成功调用：清错误计数、恢复状态、累计用量
     */
    public function markSuccess(Account $account): void
    {
        $this->db->table(self::TABLE)->where('id', $account->id)->update([
            'status'        => 'READY',
            'reason'        => '',
            'cooling_until' => 0,
            'error_count'   => 0,
            'last_error_at' => 0,
        ]);
        $this->db->table(self::TABLE)->where('id', $account->id)->increment('used_count');
    }

    /**
     * 记录一次可恢复错误（未到冷却阈值）
     *
     * 错误计数带**时间窗衰减**：距上次计错已超过 `ERROR_DECAY_WINDOW_MS` 时，
     * 本次视为「新一轮」的首个错误，计数从 1 重新开始。
     *
     * 修的是「攒够 max_error_count 次就熔断」的旧口径：旧实现 `$errorCount + 1`
     * 无时间维度，于是**相隔数天**的偶发上游抖动会缓慢累加，最终把长期健康的
     * 账号打进冷却 —— 表现为「明明一直好用，某天突然被冷却」，且冷却原因写着
     * 「连续错误」，实际那几次错误根本不连续。
     *
     * 只有**短窗口内密集发生**的失败才算「连续错误」，才应触发熔断。
     */
    public function markError(Account $account, string $message, int $maxErrorCount, int $cooldownMs): bool
    {
        $now = now_ms();
        $errorCount = ($account->lastErrorAt > 0 && $now - $account->lastErrorAt > self::ERROR_DECAY_WINDOW_MS)
            ? 1
            : $account->errorCount + 1;

        if ($errorCount >= $maxErrorCount) {
            $this->updateStatus($account->id, [
                'status'        => 'COOLING',
                'reason'        => '连续错误',
                'error_count'   => $errorCount,
                'last_error'    => mb_substr($message, 0, 500),
                'last_error_at' => $now,
                'cooling_until' => $now + $cooldownMs,
            ]);
            return true;
        }

        $this->updateStatus($account->id, [
            'status'        => 'ERROR',
            'error_count'   => $errorCount,
            'last_error'    => mb_substr($message, 0, 500),
            'last_error_at' => $now,
        ]);
        return false;
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $this->updateStatus($id, [
            'enabled_by_user' => $enabled,
            'status'          => $enabled ? 'READY' : 'DISABLED',
            'reason'          => $enabled ? '' : '用户手动停用',
            'cooling_until'   => $enabled ? 0 : 0,
            'error_count'     => $enabled ? 0 : 0,
        ]);
    }

    /** 清除冷却状态 */
    /**
     * 管理台「解除冷却」= 强制复位账号。
     *
     * 除冷却/错误计数外，一并清 `lease_until` 与 `next_retry_at`：
     *   · `lease_until` 残留是「账号全部被在途租约占用」的直接成因（异常退出未走到 finally），
     *     若不清，用户点了解除冷却仍会持续 429，与界面语义不符；
     *   · `next_retry_at` 是错误指数退避门闸，不清则账号仍被挡在门外。
     * 注意这是**显式强制复位**：若此刻真有请求在途，其租约会一并被清，
     * 属管理员明确意图，可接受（TTL 到点本也会自动过期）。
     */
    public function clearCooldown(int $id): void
    {
        $this->updateStatus($id, [
            'status'        => 'READY',
            'reason'        => '',
            'cooling_until' => 0,
            'error_count'   => 0,
            'next_retry_at' => 0,
            'last_error'    => '',
            'last_error_at' => 0,
            'lease_until'   => 0,
        ]);
    }

    public function delete(int $id): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->delete();
    }

    /** 各归属用户的账号数量：user_id => count（仅统计 user_id > 0 的普通用户归属） */
    public function userAccountCounts(): array
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

    /** 删除某用户归属的全部账号（删除用户时级联清理），返回删除数量 */
    public function deleteByUserId(int $userId): int
    {
        $this->ensureSchema();
        if ($userId <= 0) {
            return 0;
        }

        return $this->db->table(self::TABLE)->where('user_id', $userId)->delete();
    }

    /**
     * 池摘要（对应 LW1/e）
     */
    public function summary(?string $providerKey = null, ?int $userId = null): array
    {
        return $this->summarize($this->all($providerKey, $userId));
    }

    /**
     * 对**已给定**的账号集合做摘要统计。
     *
     * ⚠️ 抽成独立方法是为了让调用方能在「已做过可见性过滤」的集合上复用同一套口径。
     *    否则会出现「列表已按新规则过滤、卡片数字仍按全量算」的静默漂移
     *    （用户看到「共 12 个账号」但列表只有 8 条，无从解释）。
     *
     * @param Account[] $accounts
     */
    public function summarize(array $accounts): array
    {
        $summary = [
            'total'            => count($accounts),
            'usable'           => 0,
            'cooling'          => 0,
            'disabled'         => 0,
            'disabled_by_user' => 0,
            'error'            => 0,
        ];

        foreach ($accounts as $account) {
            if (!$account->enabledByUser) {
                $summary['disabled_by_user']++;
                $summary['disabled']++;
                continue;
            }
            if ($account->isCooling()) {
                $summary['cooling']++;
                continue;
            }
            if ($account->status === 'DISABLED') {
                $summary['disabled']++;
                continue;
            }
            if ($account->status === 'ERROR') {
                $summary['error']++;
            }
            $summary['usable']++;
        }

        return $summary;
    }

    /**
     * 按供应商分组摘要
     */
    public function summaryByProvider(?int $userId = null): array
    {
        return $this->groupByProvider($this->all(null, $userId));
    }

    /**
     * 对**已给定**的账号集合按供应商分组摘要（口径同 `summaryByProvider`）。
     *
     * @param Account[] $accounts
     */
    public function groupByProvider(array $accounts): array
    {
        $out = [];
        foreach ($accounts as $account) {
            $out[$account->providerKey][] = $account;
        }

        $result = [];
        foreach ($out as $providerKey => $group) {
            $summary = ['total' => count($group), 'usable' => 0, 'cooling' => 0, 'disabled' => 0, 'error' => 0, 'total_credits' => 0.0, 'credits_known' => 0];
            foreach ($group as $account) {
                if (!$account->enabledByUser || $account->status === 'DISABLED') {
                    $summary['disabled']++;
                    continue;
                }
                if ($account->isCooling()) {
                    $summary['cooling']++;
                    continue;
                }
                if ($account->status === 'ERROR') {
                    $summary['error']++;
                }
                $summary['usable']++;
                if ($account->creditsKnown && $account->credits !== null) {
                    $summary['total_credits'] += $account->credits;
                    $summary['credits_known']++;
                }
            }
            $summary['total_credits'] = round($summary['total_credits'], 2);
            $result[$providerKey] = $summary;
        }

        return $result;
    }

    /**
     * 自动解除已到期的冷却
     */
    public function releaseExpiredCooldowns(): int
    {
        return $this->db->statement(
            'UPDATE `' . self::TABLE . '` SET `status` = ?, `reason` = ? WHERE `status` = ? AND `cooling_until` > 0 AND `cooling_until` <= ?',
            ['READY', '', 'COOLING', now_ms()]
        );
    }

    /** 记录签到时间（用于每日幂等与启动核验） */
    public function markCheckedIn(int $id, int $at): void
    {
        $this->updateStatus($id, ['checkin_at' => $at]);
    }

    /** 写入积分到期时间（毫秒，0=未知/不过期） */
    public function setCreditsExpire(int $id, int $expireAt): void
    {
        $this->updateStatus($id, ['credits_expire_at' => $expireAt]);
    }

    /**
     * 设置指数退避下次可尝试时间（不改变可用状态，仅作为调度门闸）
     */
    public function markBackoff(int $id, int $untilMs): void
    {
        $this->updateStatus($id, ['next_retry_at' => $untilMs]);
    }

    /** 清除退避门闸 */
    public function clearBackoff(int $id): void
    {
        $this->updateStatus($id, ['next_retry_at' => 0]);
    }

    /**
     * 尝试获取在途租约（原子）：仅当当前无有效租约（已过期）时成功。
     * 用于避免同一账号被并发请求重复占用。
     *
     * @return int 成功返回本次写入的到期时间戳（毫秒）；失败返回 0。
     *             返回具体值是为了让调用方记住「我这条租约长什么样」，
     *             后续续期时用作 CAS 的比对条件（见 renewLease）。
     */
    public function acquireLease(int $id, int $ttlMs): int
    {
        $now   = now_ms();
        $until = $now + $ttlMs;
        $affected = $this->db->statement(
            'UPDATE `' . self::TABLE . '` SET `lease_until` = ? WHERE `id` = ? AND (`lease_until` = 0 OR `lease_until` <= ?)',
            [$until, $id, $now]
        );

        return $affected > 0 ? $until : 0;
    }

    /**
     * 释放在途租约。
     *
     * ⚠️ 必须带 CAS 条件（传本进程当初 acquireLease 拿到的值）：
     * 无条件清零会把**抢占者刚建立的租约**一起抹掉 —— 场景是
     * 「本进程租约已过期 → 他人 acquire 成功 → 本进程 finally 收尾 → 清零」，
     * 之后第三方又能拿到租约，两人同时用一个账号，对单账号供应商直接撞上游并发限制。
     * 传入 0 表示强制清零（仅管理台复位 / 测试用）。
     *
     * @param int $expectHeldUntil 本进程当初 acquireLease 拿到的到期时间戳；0=强制清零
     * @return bool 是否真的释放了（false = 已非己有，正确地未动他人租约）
     */
    public function releaseLease(int $id, int $expectHeldUntil = 0): bool
    {
        if ($expectHeldUntil <= 0) {
            $this->updateStatus($id, ['lease_until' => 0]);

            return true;
        }

        $affected = $this->db->statement(
            'UPDATE `' . self::TABLE . '` SET `lease_until` = 0 '
            . 'WHERE `id` = ? AND `lease_until` = ?',
            [$id, $expectHeldUntil]
        );

        return $affected > 0;
    }

    /**
     * 续期在途租约（原子 CAS，防错续他人租约）。
     *
     * ⚠️ 比对条件必须是**本进程当初写入的那个值**（调用方从 acquireLease 拿到并记住），
     * 不能现读一次库当条件 —— 那样只要中间没人改过就恒等，
     * 等于没有校验（实测踩过：抢占场景返回 true）。
     *
     * 条件 `lease_until = $expectHeldUntil` 的语义：
     *   · 自己释放过（=0）→ 不匹配 → 失败（正确地知道自己已失去租约）
     *   · 别人抢占了（值被改成别的）→ 不匹配 → 失败（不覆盖他人）
     * 同时要求 `lease_until > now`，避免"已过期后被人重新申请"的窗口里被本进程续回去。
     *
     * @param int $expectHeldUntil 本进程当初 acquireLease 拿到的到期时间戳
     * @return int 续期后的新到期时间戳；0 = 续期失败（已非己有）
     */
    public function renewLease(int $id, int $ttlMs, int $expectHeldUntil): int
    {
        $now = now_ms();
        $newUntil = $now + $ttlMs;
        if ($expectHeldUntil <= 0) {
            return 0;
        }

        $affected = $this->db->statement(
            'UPDATE `' . self::TABLE . '` SET `lease_until` = ? '
            . 'WHERE `id` = ? AND `lease_until` = ? AND `lease_until` > ?',
            [$newUntil, $id, $expectHeldUntil, $now]
        );

        return $affected > 0 ? $newUntil : 0;
    }

    /**
     * 获取当前租约到期时间（诊断/管理台用），无租约返回 0。
     */
    public function currentLease(int $id): int
    {
        $row = $this->db->table(self::TABLE)->where('id', $id)->first();

        return $row !== null ? (int) ($row['lease_until'] ?? 0) : 0;
    }
}