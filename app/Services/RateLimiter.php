<?php
/**
 * 网关对外限流器（令牌桶，持久化到数据库）
 *
 * 为什么落到数据库而不是进程内存：
 *   php-fpm 是多进程模型，进程内计数无法跨进程共享，等于没有限流。
 *   这里用一张 aigw_rate_limit 表按「桶」维度保存剩余令牌，
 *   在事务内 SELECT ... FOR UPDATE 保证并发安全。
 *
 * 令牌桶语义：
 *   - 桶容量 = rate_limit_rpm + rate_limit_burst（允许短时突发）
 *   - 补充速率 = rate_limit_rpm / 分钟
 *   - 每次请求消耗 1 个令牌；不足则拒绝并给出建议等待秒数
 *
 * 令牌以「千分位」整数存储，避免浮点误差：1000 表示 1 个令牌。
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Schema;

class RateLimiter
{
    private const TABLE = 'aigw_rate_limit';

    /** 1 个令牌对应的千分位单位数 */
    private const TOKEN_UNIT = 1000;

    public function __construct(private Database $db)
    {
        Schema::ensure($this->db);
    }

    /**
     * 是否放行一次请求
     *
     * @return array{allowed:bool, remaining:int, retry_after:int, limit:int}
     */
    public function hit(string $bucket, int $rpm, int $burst): array
    {
        $rpm   = max(1, $rpm);
        $burst = max(0, $burst);

        $capacity = $rpm + $burst;
        $capMilli = $capacity * self::TOKEN_UNIT;
        $now      = now_ms();

        $bucket = mb_substr($bucket, 0, 160);

        return $this->db->transaction(function () use ($bucket, $rpm, $capMilli, $capacity, $now): array {
            // 确保桶存在（首次请求时初始化为满桶）
            $this->db->statement(
                'INSERT IGNORE INTO `' . self::TABLE . '` (`bucket`, `tokens`, `updated_ms`) VALUES (?, ?, ?)',
                [$bucket, $capMilli, $now]
            );

            $row = $this->db->first(
                'SELECT `tokens`, `updated_ms` FROM `' . self::TABLE . '` WHERE `bucket` = ? FOR UPDATE',
                [$bucket]
            );

            $tokens     = (int) ($row['tokens'] ?? $capMilli);
            $updatedMs  = (int) ($row['updated_ms'] ?? $now);

            // 按经过时间补充令牌（rpm 个/分钟 → rpm/60 千分位/毫秒）
            $elapsedMs = max(0, $now - $updatedMs);
            if ($elapsedMs > 0) {
                $refill = intdiv($elapsedMs * $rpm, 60);
                if ($refill > 0) {
                    $tokens = min($capMilli, $tokens + $refill);
                }
            }

            $allowed = $tokens >= self::TOKEN_UNIT;
            if ($allowed) {
                $tokens -= self::TOKEN_UNIT;
            }

            $this->db->statement(
                'UPDATE `' . self::TABLE . '` SET `tokens` = ?, `updated_ms` = ? WHERE `bucket` = ?',
                [$tokens, $now, $bucket]
            );

            $retryAfter = 0;
            if (!$allowed) {
                // 补满 1 个令牌所需毫秒数 = 缺少的千分位 / (rpm/60)
                $needMilli   = self::TOKEN_UNIT - $tokens;
                $retryMs     = (int) ceil($needMilli * 60 / $rpm);
                $retryAfter  = max(1, (int) ceil($retryMs / 1000));
            }

            return [
                'allowed'     => $allowed,
                'remaining'   => intdiv(max(0, $tokens), self::TOKEN_UNIT),
                'retry_after' => $retryAfter,
                'limit'       => $capacity,
            ];
        });
    }

    /** 清理长期未使用的桶（配合定时清理，避免表无限增长） */
    public function prune(int $idleSeconds = 3600): int
    {
        $cutoff = now_ms() - max(60, $idleSeconds) * 1000;
        return $this->db->statement(
            'DELETE FROM `' . self::TABLE . '` WHERE `updated_ms` < ?',
            [$cutoff]
        );
    }
}
