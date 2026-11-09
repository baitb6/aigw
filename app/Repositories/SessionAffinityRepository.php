<?php
/**
 * 会话粘性仓储（对应 aigw_session_affinity）
 *
 * 使用场景：同一客户端会话（chat_id / conversation_id / 自定义）在 TTL 窗口内
 * 绑定到同一上游账号，避免多轮对话在不同账号间漂移导致上下文/缓存命中率下降。
 * 绑定会在每次命中时滚动续期；绑定账号失败时立即解绑，下一次请求重新选号。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class SessionAffinityRepository
{
    private const TABLE = 'aigw_session_affinity';

    private bool $schemaChecked = false;

    public function __construct(private Database $db) {}

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $this->schemaChecked = true;

        Schema::ensure($this->db);
    }

    private function normalizeKey(string $sessionKey): string
    {
        return mb_substr(trim($sessionKey), 0, 80);
    }

    /** 原始绑定记录（含过期），未绑定返回 null */
    public function find(string $sessionKey): ?array
    {
        $sessionKey = $this->normalizeKey($sessionKey);
        if ($sessionKey === '') {
            return null;
        }

        $this->ensureSchema();

        return $this->db->table(self::TABLE)->where('session_key', $sessionKey)->first();
    }

    /**
     * 取会话当前绑定的账号；命中且未过期时滚动续期并返回 uid。
     *
     * @param int $ttlMs 续期时长（毫秒）；<=0 表示只读取不续期
     */
    public function boundAccount(string $sessionKey, string $providerKey, int $ttlMs = 0): ?string
    {
        $row = $this->find($sessionKey);
        if ($row === null) {
            return null;
        }

        if ((int) $row['expires_at'] <= now_ms()) {
            // 已过期，顺手清理
            $this->unbind($sessionKey);
            return null;
        }

        if ($providerKey !== '' && (string) $row['provider_key'] !== $providerKey) {
            return null;
        }

        $uid = (string) $row['account_uid'];
        if ($uid === '') {
            return null;
        }

        if ($ttlMs > 0) {
            $this->touch($sessionKey, $ttlMs);
        }

        return $uid;
    }

    /** 绑定（或重绑）会话到指定账号，唯一键冲突时更新账号与到期时间 */
    public function bind(string $sessionKey, string $providerKey, string $model, string $accountUid, int $ttlMs): void
    {
        $sessionKey = $this->normalizeKey($sessionKey);
        if ($sessionKey === '' || $accountUid === '') {
            return;
        }

        $this->ensureSchema();

        $ttlMs = max(1000, $ttlMs);

        $this->db->table(self::TABLE)->upsert([
            'session_key'  => $sessionKey,
            'provider_key' => mb_substr($providerKey, 0, 32),
            'model'        => mb_substr($model, 0, 160),
            'account_uid'  => mb_substr($accountUid, 0, 128),
            'expires_at'   => now_ms() + $ttlMs,
        ], ['provider_key', 'model', 'account_uid', 'expires_at']);
    }

    /** 滚动续期（仅更新到期时间） */
    public function touch(string $sessionKey, int $ttlMs): void
    {
        $sessionKey = $this->normalizeKey($sessionKey);
        if ($sessionKey === '' || $ttlMs <= 0) {
            return;
        }

        $this->ensureSchema();

        $this->db->table(self::TABLE)
            ->where('session_key', $sessionKey)
            ->update(['expires_at' => now_ms() + max(1000, $ttlMs)]);
    }

    /** 解绑单个会话（绑定账号失败时立即调用） */
    public function unbind(string $sessionKey): int
    {
        $sessionKey = $this->normalizeKey($sessionKey);
        if ($sessionKey === '') {
            return 0;
        }

        $this->ensureSchema();

        return $this->db->table(self::TABLE)->where('session_key', $sessionKey)->delete();
    }

    /** 清除绑定到某账号的所有会话（账号删除/禁用时） */
    public function unbindAccount(string $providerKey, string $accountUid): int
    {
        if ($accountUid === '') {
            return 0;
        }

        $this->ensureSchema();

        $query = $this->db->table(self::TABLE)->where('account_uid', $accountUid);
        if ($providerKey !== '') {
            $query->where('provider_key', $providerKey);
        }

        return $query->delete();
    }

    /** 清理已过期绑定，避免表膨胀 */
    public function releaseExpired(): int
    {
        $this->ensureSchema();

        return $this->db->statement(
            'DELETE FROM `' . self::TABLE . '` WHERE `expires_at` > 0 AND `expires_at` <= ?',
            [now_ms()]
        );
    }

    /** 仍在生效的绑定明细（供管理台展示） */
    public function active(int $limit = 200): array
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)
            ->where('expires_at', '>', now_ms())
            ->orderBy('expires_at', 'ASC')
            ->limit(max(1, min(500, $limit)))
            ->get();
    }

    public function clearAll(): int
    {
        $this->ensureSchema();
        return $this->db->statement('DELETE FROM `' . self::TABLE . '`');
    }
}
