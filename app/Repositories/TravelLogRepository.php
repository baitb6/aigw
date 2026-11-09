<?php
/**
 * 猫猫旅行日志仓储（对应 aigw_travel_log）
 *
 * 用途：
 *  - 记录每个账号每个自然日的旅行状态（idle|traveling|arrived|adopted|blocked），
 *    供管理台展示，并承载「每日幂等」判定：到站已领奖 / 领养门槛未达时当日不再重试。
 *  - 唯一键 (provider_key, account_uid, travel_date)：同一天重复执行只更新同一行。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class TravelLogRepository
{
    private const TABLE = 'aigw_travel_log';

    /**
     * 属主隔离条件：日志行 (provider_key, account_uid) 必须归属指定用户
     * 占位符 ? 绑定 user_id；仅用于普通用户视图，管理员（scope=null）不加此条件。
     */
    private const OWNER_EXISTS = 'EXISTS (SELECT 1 FROM `aigw_account` AS `oa`'
        . ' WHERE `oa`.`provider_key` = `aigw_travel_log`.`provider_key`'
        . ' AND `oa`.`account_uid` = `aigw_travel_log`.`account_uid`'
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
     * 写入（或更新）某账号今日的旅行状态（自然日幂等）
     */
    public function record(array $data): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->upsert([
            'provider_key' => (string) ($data['provider_key'] ?? ''),
            'account_uid'  => mb_substr((string) ($data['account_uid'] ?? ''), 0, 128),
            'travel_date'  => date('Y-m-d'),
            'state'        => mb_substr((string) ($data['state'] ?? ''), 0, 16),
            'location'     => mb_substr((string) ($data['location'] ?? ''), 0, 64),
            'reward'       => mb_substr((string) ($data['reward'] ?? ''), 0, 255),
            'message'      => mb_substr((string) ($data['message'] ?? ''), 0, 255),
        ], ['state', 'location', 'reward', 'message']);
    }

    /** 今日该账号已记录的旅行状态（无记录返回 ''） */
    public function todayState(string $providerKey, string $uid): string
    {
        $this->ensureSchema();

        $row = $this->db->table(self::TABLE)
            ->where('provider_key', $providerKey)
            ->where('account_uid', $uid)
            ->where('travel_date', date('Y-m-d'))
            ->first();

        return $row !== null ? (string) $row['state'] : '';
    }

    /**
     * 最近旅行记录（可按供应商/账号筛选）
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

    /** 今日旅行概览：各状态的账号数 */
    public function todaySummary(?int $userId = null): array
    {
        $this->ensureSchema();

        $sql = 'SELECT `state`, COUNT(*) AS `cnt` FROM `' . self::TABLE . '`
             WHERE `travel_date` = ?';
        $bindings = [date('Y-m-d')];
        if ($userId !== null) {
            $sql .= ' AND ' . self::OWNER_EXISTS;
            $bindings[] = $userId;
        }
        $sql .= ' GROUP BY `state`';

        $rows = $this->db->select($sql, $bindings);

        $byState = [];
        $total = 0;
        foreach ($rows as $r) {
            $state = (string) $r['state'];
            $cnt = (int) $r['cnt'];
            $byState[$state] = $cnt;
            $total += $cnt;
        }

        return ['total' => $total, 'by_state' => $byState];
    }

    /** 清理过期旅行日志（保留 N 天） */
    public function prune(int $keepDays): int
    {
        if ($keepDays <= 0) {
            return 0;
        }

        $this->ensureSchema();

        $cutoff = date('Y-m-d', strtotime('today') - $keepDays * 86400);
        return $this->db->statement(
            'DELETE FROM `' . self::TABLE . '` WHERE `travel_date` < ?',
            [$cutoff]
        );
    }

    public function clearAll(): int
    {
        $this->ensureSchema();
        return $this->db->statement('DELETE FROM `' . self::TABLE . '`');
    }
}
