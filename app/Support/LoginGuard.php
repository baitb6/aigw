<?php
/**
 * 登录失败锁定（持久化，IP + 账号双维度）
 *
 * 为什么不用 Session：
 *   旧实现把失败次数写在 $_SESSION 里，攻击者清掉 cookie / 换一个会话即可
 *   重置计数，等于没有防护。这里把计数落到数据库表 aigw_login_lockout，
 *   以「IP」与「IP+账号」两个身份维度分别累计，清 cookie 无法绕过。
 *
 * 维度说明：
 *   - ip   ：同一来源 IP 对任意账号的连续失败（防单机撞库）
 *   - pair ：同一来源 IP 对同一账号的连续失败（防定点爆破）
 * 命中阈值后写入 locked_until（秒级时间戳），到期自动解锁。
 */

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;

class LoginGuard
{
    private const TABLE = 'aigw_login_lockout';

    public function __construct(private Database $db)
    {
        Schema::ensure($this->db);
    }

    /**
     * 身份指纹：ip 维度与 pair 维度
     *
     * @return string[]
     */
    public static function identities(string $ip, string $account): array
    {
        $ip = strtolower(trim($ip));
        $account = strtolower(trim($account));

        $ids = ['ip:' . sha1($ip)];
        if ($account !== '') {
            $ids[] = 'pair:' . sha1($ip . '|' . $account);
        }
        return $ids;
    }

    /**
     * 当前剩余锁定秒数（多维度取最大），0 表示未锁定
     */
    public function lockedSeconds(string $scope, string $ip, string $account): int
    {
        $now = time();
        $rows = $this->db->table(self::TABLE)
            ->select('locked_until')
            ->where('scope', $scope)
            ->where('identity', 'IN', self::identities($ip, $account))
            ->get();

        $remaining = 0;
        foreach ($rows as $row) {
            $until = (int) ($row['locked_until'] ?? 0);
            if ($until > $now) {
                $remaining = max($remaining, $until - $now);
            }
        }
        return $remaining;
    }

    /**
     * 记录一次登录失败，达到阈值时锁定
     */
    public function recordFailure(string $scope, string $ip, string $account, int $maxAttempts, int $lockSeconds): void
    {
        $maxAttempts = max(1, $maxAttempts);
        $lockSeconds = max(1, $lockSeconds);

        foreach (self::identities($ip, $account) as $identity) {
            $this->bump($scope, $identity, $maxAttempts, $lockSeconds);
        }
    }

    /**
     * 登录成功：清空该 IP / 账号的失败计数
     */
    public function clear(string $scope, string $ip, string $account): void
    {
        $this->db->table(self::TABLE)
            ->where('scope', $scope)
            ->where('identity', 'IN', self::identities($ip, $account))
            ->delete();
    }

    private function bump(string $scope, string $identity, int $maxAttempts, int $lockSeconds): void
    {
        $now = time();
        $row = $this->db->table(self::TABLE)
            ->where('scope', $scope)
            ->where('identity', $identity)
            ->first();

        if ($row === null) {
            $this->db->table(self::TABLE)->insert([
                'scope'         => $scope,
                'identity'      => $identity,
                'fails'         => 1,
                'first_fail_at' => $now,
                'locked_until'  => 0,
            ]);
            return;
        }

        // 已在锁定期内：不延长，等自然到期
        if ((int) ($row['locked_until'] ?? 0) > $now) {
            return;
        }

        $fails = (int) ($row['fails'] ?? 0);
        $firstFailAt = (int) ($row['first_fail_at'] ?? 0);

        // 失败窗口已过：重新计数，避免长期累积误伤正常用户
        if ($firstFailAt <= 0 || ($now - $firstFailAt) > $lockSeconds) {
            $fails = 0;
            $firstFailAt = $now;
        }

        $fails++;

        if ($fails >= $maxAttempts) {
            $this->db->table(self::TABLE)
                ->where('scope', $scope)
                ->where('identity', $identity)
                ->update([
                    'fails'         => 0,
                    'first_fail_at' => 0,
                    'locked_until'  => $now + $lockSeconds,
                ]);
            return;
        }

        $this->db->table(self::TABLE)
            ->where('scope', $scope)
            ->where('identity', $identity)
            ->update([
                'fails'         => $fails,
                'first_fail_at' => $firstFailAt,
            ]);
    }
}