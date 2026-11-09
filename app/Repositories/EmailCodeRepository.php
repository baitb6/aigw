<?php
/**
 * 邮箱验证码仓储
 * ------------------------------------------------------------
 * 一次注册/改密流程的验证码生命周期：
 *   发送 → 存 hashCode（绝不存明文）→ 校验 → 标记已用
 *
 * 安全约束（都写进查询条件，不靠应用层判断）：
 *   - 同一邮箱 60 秒内只能发一次（冷却）
 *   - 同一邮箱 24 小时内最多 N 次（默认 10）
 *   - 同一 IP 24 小时内最多 M 次（默认 30，防换邮箱刷）
 *   - 单码最多校验 5 次，超过即作废
 *   - 有效期默认 300 秒
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class EmailCodeRepository
{
    private const TABLE = 'aigw_email_code';

    public function __construct(private Database $db)
    {
        $this->ensureTable();
    }

    /** 运行时自愈建表（与 Schema::ensure 双保险，测试脚本可独立跑） */
    private function ensureTable(): void
    {
        try {
            $this->db->statement(
                'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
                  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `email`       VARCHAR(190) NOT NULL COMMENT \'目标邮箱（小写归一）\',
                  `purpose`     VARCHAR(32)  NOT NULL DEFAULT \'register\' COMMENT \'用途：register|reset|bind\',
                  `code_hash`   CHAR(64)     NOT NULL DEFAULT \'\' COMMENT \'验证码 SHA-256（不存明文）\',
                  `expires_at`  BIGINT       NOT NULL DEFAULT 0 COMMENT \'到期时间戳（秒）\',
                  `attempts`    INT          NOT NULL DEFAULT 0 COMMENT \'已校验次数\',
                  `used_at`     BIGINT       NOT NULL DEFAULT 0 COMMENT \'使用时间戳，0=未使用\',
                  `ip`          VARCHAR(64)  NOT NULL DEFAULT \'\' COMMENT \'请求来源 IP\',
                  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_email_purpose` (`email`, `purpose`, `id`),
                  -- 覆盖 IP 24h 配额查询（WHERE ip=? AND created_at>=...）
                  KEY `idx_ip_time` (`ip`, `created_at`),
                  KEY `idx_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'邮箱验证码（注册/找回密码）\''
            );
        } catch (\Throwable $e) {
            // 无建表权限时静默降级，由 Schema::ensure 兜底
        }
    }

    /** 邮箱归一：去空白 + 小写（避免 A@B 与 a@b 重复发码） */
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** 验证码哈希：加固定盐，避免库内被彩虹表反查 */
    public static function hash(string $email, string $purpose, string $code): string
    {
        return hash('sha256', self::normalize($email) . '|' . $purpose . '|' . $code);
    }

    /**
     * 冷却与配额检查（发码前调用）
     *
     * @return array{ok:bool, reason:string} ok=true 表示可以发
     */
    public function canSend(string $email, string $purpose, string $ip, int $cooldown = 60, int $perEmailDaily = 10, int $perIpDaily = 30): array
    {
        $email = self::normalize($email);

        // 同邮箱冷却（用 SQL 端时间差，避免 PHP 时区与 MySQL 会话时区错配）
        if ($cooldown > 0) {
            $wait = $this->db->scalar(
                'SELECT GREATEST(0, ? - TIMESTAMPDIFF(SECOND, MAX(`created_at`), NOW()))
                 FROM `' . self::TABLE . '`
                 WHERE `email` = ? AND `purpose` = ?',
                [$cooldown, $email, $purpose]
            );
            $wait = (int) $wait;
            if ($wait > 0) {
                return ['ok' => false, 'reason' => 'sending too fast: ' . $wait . 's'];
            }
        }

        // 同邮箱 24h 配额
        $emailCount = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `' . self::TABLE . '`
             WHERE `email` = ? AND `purpose` = ? AND `created_at` >= DATE_SUB(NOW(), INTERVAL 1 DAY)',
            [$email, $purpose]
        );
        if ($perEmailDaily > 0 && $emailCount >= $perEmailDaily) {
            return ['ok' => false, 'reason' => 'email daily quota reached'];
        }

        // 同 IP 24h 配额（ip 为空时跳过，例如 CLI 自检）
        if ($ip !== '') {
            $ipCount = (int) $this->db->scalar(
                'SELECT COUNT(*) FROM `' . self::TABLE . '`
                 WHERE `ip` = ? AND `created_at` >= DATE_SUB(NOW(), INTERVAL 1 DAY)',
                [$ip]
            );
            if ($perIpDaily > 0 && $ipCount >= $perIpDaily) {
                return ['ok' => false, 'reason' => 'ip daily quota reached'];
            }
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * 写入一条验证码（明文只在这一刻存在，之后只留 hash）
     */
    public function create(string $email, string $purpose, string $code, int $ttl, string $ip): int
    {
        $email = self::normalize($email);
        $now = time();

        // 同邮箱同用途的旧码全部作废，避免多码并发
        $this->db->statement(
            'UPDATE `' . self::TABLE . '` SET `used_at` = ?
             WHERE `email` = ? AND `purpose` = ? AND `used_at` = 0',
            [$now, $email, $purpose]
        );

        return $this->db->insert(
            'INSERT INTO `' . self::TABLE . '`
             (`email`, `purpose`, `code_hash`, `expires_at`, `attempts`, `used_at`, `ip`)
             VALUES (?, ?, ?, ?, 0, 0, ?)',
            [$email, $purpose, self::hash($email, $purpose, $code), $now + $ttl, $ip]
        );
    }

    /**
     * 校验验证码；成功即标记已用（一次性）
     *
     * @return array{ok:bool, reason:string} reason: ok | expired | used | mismatch | too_many
     */
    public function verify(string $email, string $purpose, string $code, int $maxAttempts = 5): array
    {
        $email = self::normalize($email);
        $code = trim($code);
        if ($code === '') {
            return ['ok' => false, 'reason' => 'empty'];
        }

        $row = $this->db->first(
            'SELECT * FROM `' . self::TABLE . '`
             WHERE `email` = ? AND `purpose` = ? AND `used_at` = 0
             ORDER BY `id` DESC LIMIT 1',
            [$email, $purpose]
        );

        if ($row === null) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        $id = (int) $row['id'];
        $now = time();

        if ((int) $row['expires_at'] < $now) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        if ((int) $row['attempts'] >= $maxAttempts) {
            $this->invalidate($id);
            return ['ok' => false, 'reason' => 'too_many'];
        }

        if (!hash_equals((string) $row['code_hash'], self::hash($email, $purpose, $code))) {
            // 竞态修复（审计 L-3）：次数累加与上限判定合并为一条原子 UPDATE，
            // 高并发下不会再突破单码 maxAttempts 上限（ exceeded 时 affected=0）。
            $this->db->statement(
                'UPDATE `' . self::TABLE . '` SET `attempts` = `attempts` + 1 WHERE `id` = ? AND `attempts` < ?',
                [$id, $maxAttempts]
            );
            return ['ok' => false, 'reason' => 'mismatch'];
        }

        $this->invalidate($id);
        return ['ok' => true, 'reason' => 'ok'];
    }

    /** 标记作废（使用成功或超限） */
    public function invalidate(int $id): void
    {
        $this->db->statement(
            'UPDATE `' . self::TABLE . '` SET `used_at` = ? WHERE `id` = ? AND `used_at` = 0',
            [time(), $id]
        );
    }

    /** 清理过期与已用记录（计划任务/管理台可调） */
    public function prune(int $keepDays = 7): int
    {
        return $this->db->statement(
            'DELETE FROM `' . self::TABLE . '` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [max(1, $keepDays)]
        );
    }

    /** 最近一条发码记录（管理台排查用，不含明文） */
    public function latest(string $email, string $purpose = 'register'): ?array
    {
        return $this->db->first(
            'SELECT `id`, `email`, `purpose`, `expires_at`, `attempts`, `used_at`, `ip`, `created_at`
             FROM `' . self::TABLE . '`
             WHERE `email` = ? AND `purpose` = ?
             ORDER BY `id` DESC LIMIT 1',
            [self::normalize($email), $purpose]
        );
    }
}
