<?php
/**
 * 每日活跃注入日志仓储（对应 aigw_activity_log）
 *
 * 用途：
 *  - 记录每个账号每个自然日的活跃注入结果（SUCCESS|FAILED），供管理台展示。
 *  - 唯一键 (provider_key, account_uid, activity_date)：同一天重复执行只更新同一行，
 *    并作为「每账号每日一次」的幂等判定依据（成功当天不再重复请求上游）。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class ActivityLogRepository
{
    private const TABLE = 'aigw_activity_log';

    /**
     * 属主隔离条件：日志行 (provider_key, account_uid) 必须归属指定用户
     * 占位符 ? 绑定 user_id；仅用于普通用户视图，管理员（scope=null）不加此条件。
     */
    private const OWNER_EXISTS = 'EXISTS (SELECT 1 FROM `aigw_account` AS `oa`'
        . ' WHERE `oa`.`provider_key` = `aigw_activity_log`.`provider_key`'
        . ' AND `oa`.`account_uid` = `aigw_activity_log`.`account_uid`'
        . ' AND `oa`.`user_id` = ?)';

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
     * 写入（或更新）某账号今日的活跃注入结果（自然日幂等）
     */
    public function record(array $data): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->upsert([
            'provider_key'  => (string) ($data['provider_key'] ?? ''),
            'account_uid'   => mb_substr((string) ($data['account_uid'] ?? ''), 0, 128),
            'activity_date' => date('Y-m-d'),
            'status'        => mb_substr((string) ($data['status'] ?? 'SUCCESS'), 0, 16),
            'model'         => mb_substr((string) ($data['model'] ?? ''), 0, 160),
            'message'       => mb_substr((string) ($data['message'] ?? ''), 0, 255),
        ], ['status', 'model', 'message']);
    }

    /** 今日该账号是否已成功注入活跃（无记录或失败返回 false） */
    public function doneToday(string $providerKey, string $uid): bool
    {
        $this->ensureSchema();

        $row = $this->db->table(self::TABLE)
            ->where('provider_key', $providerKey)
            ->where('account_uid', $uid)
            ->where('activity_date', date('Y-m-d'))
            ->first();

        return $row !== null && (string) $row['status'] === 'SUCCESS';
    }

    /**
     * 最近活跃注入记录（可按供应商/账号筛选）
     *
     * @param int|null $userId 传入非 null 时，仅返回归属该用户的账号日志（属主隔离）
     */
    public function recent(int $limit = 50, ?string $providerKey = null, ?string $uid = null, ?int $userId = null): array
    {
        $this->ensureSchema();

        $query = $this->db->table(self::TABLE);
        if ($providerKey !== null && $providerKey !== '') {
            $query->where('provider_key', $providerKey);
        }
        if ($uid !== null && $uid !== '') {
            $query->where('account_uid', $uid);
        }
        if ($userId !== null) {
            $query->whereRaw(self::OWNER_EXISTS, [$userId]);
        }

        return $query->orderBy('id', 'DESC')->limit(max(1, min(500, $limit)))->get();
    }

    /** 今日活跃概览：各状态的账号数 */
    public function todaySummary(?int $userId = null): array
    {
        $this->ensureSchema();

        $sql = 'SELECT `status`, COUNT(*) AS `cnt` FROM `' . self::TABLE . '`
             WHERE `activity_date` = ?';
        $bindings = [date('Y-m-d')];
        if ($userId !== null) {
            $sql .= ' AND ' . self::OWNER_EXISTS;
            $bindings[] = $userId;
        }
        $sql .= ' GROUP BY `status`';

        $rows = $this->db->select($sql, $bindings);

        $byStatus = [];
        $total = 0;
        foreach ($rows as $r) {
            $status = (string) $r['status'];
            $cnt = (int) $r['cnt'];
            $byStatus[$status] = $cnt;
            $total += $cnt;
        }

        return ['total' => $total, 'by_status' => $byStatus];
    }

    /** 清理过期日志（保留 N 天） */
    public function prune(int $keepDays): int
    {
        if ($keepDays <= 0) {
            return 0;
        }

        $this->ensureSchema();

        $cutoff = date('Y-m-d', strtotime('today') - $keepDays * 86400);
        return $this->db->statement(
            'DELETE FROM `' . self::TABLE . '` WHERE `activity_date` < ?',
            [$cutoff]
        );
    }

    public function clearAll(): int
    {
        $this->ensureSchema();
        return $this->db->statement('DELETE FROM `' . self::TABLE . '`');
    }
}
