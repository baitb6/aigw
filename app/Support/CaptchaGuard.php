<?php
/**
 * 图形验证码防爆破闸门
 * ------------------------------------------------------------
 * 为什么需要它：图形验证码本身只是「一次挑战」，若没有配套的取图/校验限流，
 * 攻击者可以对同一会话无限次「取图 → 猜」，或者干脆并发开大量会话暴力试。
 * 本类把两个维度落库（清 cookie 换会话无法绕过）：
 *
 *   1. 取图限流（draw）：同一 IP 每分钟取图上限。防「批量拉图喂 OCR」。
 *   2. 校验失败锁定（verify）：同一 IP 连续答错 N 次 → 锁一段时间；
 *      同一会话也记一份，避免单会话高频猜。
 *
 * 复用 `aigw_login_lockout` 表，用不同的 scope 前缀区分：
 *   captcha:draw   —— 取图计数（identity = IP 哈希）
 *   captcha:verify —— 校验失败（identity = IP 哈希 / 会话哈希）
 * 表结构已存在（scope + identity + fails + first_fail_at + locked_until），
 * 不为一个计数再开一张表。
 */

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;

class CaptchaGuard
{
    private const TABLE = 'aigw_login_lockout';

    /** 取图速率窗口与上限（同一 IP） */
    private const DRAW_WINDOW_SECONDS = 60;
    private const DRAW_MAX_PER_WINDOW = 20;

    /** 校验失败阈值与锁定时长（同一 IP / 会话） */
    private const VERIFY_MAX_FAILS = 8;
    private const VERIFY_LOCK_SECONDS = 600;

    public function __construct(private Database $db)
    {
        Schema::ensure($this->db);
    }

    public static function ipIdentity(string $ip): string
    {
        return 'captcha:ip:' . sha1(strtolower(trim($ip)));
    }

    public static function sessionIdentity(string $sessionId): string
    {
        return 'captcha:sess:' . sha1($sessionId);
    }

    /**
     * 取图前调用：超出速率上限返回 false
     */
    public function allowDraw(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }

        $identity = self::ipIdentity($ip);
        $now = time();

        $row = $this->db->table(self::TABLE)
            ->where('scope', 'captcha:draw')
            ->where('identity', $identity)
            ->first();

        if ($row === null) {
            $this->db->table(self::TABLE)->insert([
                'scope'         => 'captcha:draw',
                'identity'      => $identity,
                'fails'         => 1,
                'first_fail_at' => $now,
                'locked_until'  => 0,
            ]);
            return true;
        }

        // 窗口过期 → 重新计数
        if (($now - (int) ($row['first_fail_at'] ?? 0)) > self::DRAW_WINDOW_SECONDS) {
            $this->db->table(self::TABLE)
                ->where('scope', 'captcha:draw')
                ->where('identity', $identity)
                ->update(['fails' => 1, 'first_fail_at' => $now, 'locked_until' => 0]);
            return true;
        }

        $fails = (int) ($row['fails'] ?? 0);
        if ($fails >= self::DRAW_MAX_PER_WINDOW) {
            return false;
        }

        $this->db->table(self::TABLE)
            ->where('scope', 'captcha:draw')
            ->where('identity', $identity)
            ->update(['fails' => $fails + 1]);

        return true;
    }

    /**
     * 校验前调用：返回剩余锁定秒数（0 = 未锁）
     */
    public function verifyLockedSeconds(string $ip, string $sessionId): int
    {
        $now = time();
        $remaining = 0;

        foreach ($this->verifyIdentities($ip, $sessionId) as $identity) {
            $row = $this->db->table(self::TABLE)
                ->where('scope', 'captcha:verify')
                ->where('identity', $identity)
                ->first();
            if ($row === null) {
                continue;
            }
            $until = (int) ($row['locked_until'] ?? 0);
            if ($until > $now) {
                $remaining = max($remaining, $until - $now);
            }
        }

        return $remaining;
    }

    /**
     * 记录一次校验失败；达到阈值即锁定
     */
    public function recordFailure(string $ip, string $sessionId): void
    {
        $now = time();
        foreach ($this->verifyIdentities($ip, $sessionId) as $identity) {
            $row = $this->db->table(self::TABLE)
                ->where('scope', 'captcha:verify')
                ->where('identity', $identity)
                ->first();

            if ($row === null) {
                $this->db->table(self::TABLE)->insert([
                    'scope'         => 'captcha:verify',
                    'identity'      => $identity,
                    'fails'         => 1,
                    'first_fail_at' => $now,
                    'locked_until'  => 0,
                ]);
                continue;
            }

            if ((int) ($row['locked_until'] ?? 0) > $now) {
                continue;
            }

            $fails = (int) ($row['fails'] ?? 0);
            $firstFailAt = (int) ($row['first_fail_at'] ?? 0);
            if ($firstFailAt <= 0 || ($now - $firstFailAt) > self::VERIFY_LOCK_SECONDS) {
                $fails = 0;
                $firstFailAt = $now;
            }
            $fails++;

            if ($fails >= self::VERIFY_MAX_FAILS) {
                $this->db->table(self::TABLE)
                    ->where('scope', 'captcha:verify')
                    ->where('identity', $identity)
                    ->update(['fails' => 0, 'first_fail_at' => 0, 'locked_until' => $now + self::VERIFY_LOCK_SECONDS]);
                continue;
            }

            $this->db->table(self::TABLE)
                ->where('scope', 'captcha:verify')
                ->where('identity', $identity)
                ->update(['fails' => $fails, 'first_fail_at' => $firstFailAt]);
        }
    }

    /**
     * 校验成功：清掉该 IP / 会话的失败计数
     */
    public function clear(string $ip, string $sessionId): void
    {
        $this->db->table(self::TABLE)
            ->where('scope', 'captcha:verify')
            ->where('identity', 'IN', $this->verifyIdentities($ip, $sessionId))
            ->delete();
    }

    /** @return string[] */
    private function verifyIdentities(string $ip, string $sessionId): array
    {
        $ids = [];
        if ($ip !== '') {
            $ids[] = self::ipIdentity($ip);
        }
        if ($sessionId !== '') {
            $ids[] = self::sessionIdentity($sessionId);
        }
        return $ids;
    }
}
