<?php
/**
 * 账号+模型级限流冷却仓储（对应 aigw_account_model_cooldown）
 *
 * 使用场景：上游对「某账号 + 某模型」单独限流（429 / 业务码 6004），
 * 此时只应跳过该账号的该模型，而不冻结整个账号（其他模型仍可用）。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class ModelCooldownRepository
{
    private const TABLE = 'aigw_account_model_cooldown';

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

    /**
     * 写入（或续期）账号+模型冷却。唯一键冲突时更新冷却终点与原因。
     */
    public function cool(string $providerKey, string $uid, string $model, int $untilMs, string $reason = ''): void
    {
        if ($uid === '' || $model === '') {
            return;
        }

        $this->ensureSchema();

        $this->db->table(self::TABLE)->upsert([
            'provider_key'  => $providerKey,
            'account_uid'   => mb_substr($uid, 0, 128),
            'model'         => mb_substr($model, 0, 160),
            'reason'        => mb_substr($reason, 0, 255),
            'cooling_until' => $untilMs,
        ], ['reason', 'cooling_until']);
    }

    /** 该账号+模型当前是否处于冷却窗口 */
    public function isCooling(string $providerKey, string $uid, string $model): bool
    {
        $this->ensureSchema();

        $row = $this->db->table(self::TABLE)
            ->where('provider_key', $providerKey)
            ->where('account_uid', $uid)
            ->where('model', $model)
            ->first();

        return $row !== null && (int) $row['cooling_until'] > now_ms();
    }

    /**
     * 某供应商下、指定模型中仍处于冷却的账号集合
     *
     * @return array<string,bool> account_uid => true
     */
    public function coolingMap(string $providerKey, string $model): array
    {
        if ($model === '') {
            return [];
        }

        $this->ensureSchema();

        $rows = $this->db->table(self::TABLE)
            ->where('provider_key', $providerKey)
            ->where('model', $model)
            ->where('cooling_until', '>', now_ms())
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['account_uid']] = true;
        }

        return $map;
    }

    /** 清除某账号+模型的冷却（调用成功后立即恢复） */
    public function clear(string $providerKey, string $uid, string $model): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)
            ->where('provider_key', $providerKey)
            ->where('account_uid', $uid)
            ->where('model', $model)
            ->delete();
    }

    /** 账户维度清除（账号被删除/重置时） */
    public function clearForAccount(string $providerKey, string $uid): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)
            ->where('provider_key', $providerKey)
            ->where('account_uid', $uid)
            ->delete();
    }

    /** 清理已到期冷却，避免表膨胀 */
    public function releaseExpired(): int
    {
        $this->ensureSchema();

        return $this->db->statement(
            'DELETE FROM `' . self::TABLE . '` WHERE `cooling_until` > 0 AND `cooling_until` <= ?',
            [now_ms()]
        );
    }

    /**
     * 仍在冷却中的明细（供管理台展示）
     */
    public function active(int $limit = 200, ?string $providerKey = null): array
    {
        $this->ensureSchema();

        $query = $this->db->table(self::TABLE)
            ->where('cooling_until', '>', now_ms());
        if ($providerKey !== null && $providerKey !== '') {
            $query->where('provider_key', $providerKey);
        }

        return $query->orderBy('cooling_until', 'ASC')->limit(max(1, min(500, $limit)))->get();
    }

    public function clearAll(): int
    {
        $this->ensureSchema();
        return $this->db->statement('DELETE FROM `' . self::TABLE . '`');
    }
}
