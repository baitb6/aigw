<?php
/**
 * 签到日志仓储（对应 aigw_checkin_log，保留 30 天）
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class CheckinLogRepository
{
    private const TABLE = 'aigw_checkin_log';

    /**
     * 属主隔离条件：日志行 (provider_key, account_uid) 必须归属指定用户
     * 占位符 ? 绑定 user_id；仅用于普通用户视图，管理员（scope=null）不加此条件。
     */
    private const OWNER_EXISTS = 'EXISTS (SELECT 1 FROM `aigw_account` AS `oa`'
        . ' WHERE `oa`.`provider_key` = `aigw_checkin_log`.`provider_key`'
        . ' AND `oa`.`account_uid` = `aigw_checkin_log`.`account_uid`'
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
     * 记录一次签到结果
     *
     * @param string $status SUCCESS|ALREADY|FAILED|SKIPPED
     */
    public function record(array $data): int
    {
        $this->ensureSchema();

        $before = $data['credits_before'] ?? null;
        $after  = $data['credits_after'] ?? null;

        return $this->db->table(self::TABLE)->insert([
            'provider_key'   => (string) ($data['provider_key'] ?? ''),
            'account_uid'    => (string) ($data['account_uid'] ?? ''),
            'nickname'       => mb_substr((string) ($data['nickname'] ?? ''), 0, 128),
            'status'         => (string) ($data['status'] ?? 'SUCCESS'),
            'message'        => mb_substr((string) ($data['message'] ?? ''), 0, 255),
            'credits_before' => $before === null ? null : (float) $before,
            'credits_after'  => $after === null ? null : (float) $after,
            'trigger'        => (string) ($data['trigger'] ?? 'manual'),
            'checked_at'     => (int) ($data['checked_at'] ?? now_ms()),
        ]);
    }

    /**
     * 最近签到日志（可按供应商/账号筛选）
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

    /**
     * 当天签到概览：各供应商成功/已签到/失败计数
     */
    public function todaySummary(?int $userId = null): array
    {
        $this->ensureSchema();

        $since = strtotime('today') * 1000;

        $sql = 'SELECT `provider_key`, `status`, COUNT(*) AS `cnt`
             FROM `' . self::TABLE . '` WHERE `checked_at` >= ?';
        $bindings = [$since];
        if ($userId !== null) {
            $sql .= ' AND ' . self::OWNER_EXISTS;
            $bindings[] = $userId;
        }
        $sql .= ' GROUP BY `provider_key`, `status`';

        $rows = $this->db->select($sql, $bindings);

        $byProvider = [];
        $totals = ['SUCCESS' => 0, 'ALREADY' => 0, 'FAILED' => 0, 'SKIPPED' => 0];
        foreach ($rows as $r) {
            $key = (string) $r['provider_key'];
            $status = (string) $r['status'];
            $cnt = (int) $r['cnt'];
            $byProvider[$key][$status] = $cnt;
            if (isset($totals[$status])) {
                $totals[$status] += $cnt;
            }
        }

        return [
            'total'       => array_sum($totals),
            'success'     => $totals['SUCCESS'],
            'already'     => $totals['ALREADY'],
            'failed'      => $totals['FAILED'],
            'skipped'     => $totals['SKIPPED'],
            'by_provider' => $byProvider,
        ];
    }

    /**
     * 清理过期签到日志（保留 N 天）
     */
    public function prune(int $keepDays): int
    {
        if ($keepDays <= 0) {
            return 0;
        }

        $this->ensureSchema();

        $cutoff = (strtotime('today') - $keepDays * 86400) * 1000;
        return $this->db->statement(
            'DELETE FROM `' . self::TABLE . '` WHERE `checked_at` < ?',
            [$cutoff]
        );
    }

    public function clearAll(): int
    {
        $this->ensureSchema();
        return $this->db->statement('DELETE FROM `' . self::TABLE . '`');
    }
}
