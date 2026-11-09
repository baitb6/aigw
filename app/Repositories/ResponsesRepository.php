<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

/**
 * Responses API 响应对象仓储（对应 aigw_response）。
 *
 * 两类用途共用一张表，但语义完全不同：
 *  ① 同步 store：请求结束后把 Responses 对象与上下文落库，供 GET / DELETE /
 *     previous_response_id 使用；生命周期短、写一次即终态。
 *  ② background：提交时先落一条 `queued`，由独立 CLI worker 抢锁执行后写终态；
 *     客户端用 GET 轮询，因此不受 CDN 回源超时（524）约束。
 *
 * ⚠️ 属主隔离：对外接口一律走 `findForTenant()` / `deleteForTenant()`，
 *    必须带 user_id 条件（管理员 scope=null 时不限）。response_id 是随机串，
 *    但**随机不等于授权** —— 少了 user_id 条件，任何人拿到 id 就能读别人的对话。
 */
final class ResponsesRepository
{
    private const TABLE = 'aigw_response';

    /** 排队中（仅 background 会落到这个状态） */
    public const STATUS_QUEUED = 'queued';
    /** worker 已抢锁，正在调上游 */
    public const STATUS_IN_PROGRESS = 'in_progress';
    /** 正常产出 */
    public const STATUS_COMPLETED = 'completed';
    /** 被 max_output_tokens 截断 */
    public const STATUS_INCOMPLETE = 'incomplete';
    /** 执行失败 / 已取消 */
    public const STATUS_FAILED = 'failed';
    /** 已取消（worker 复核 cancel_requested 后写回） */
    public const STATUS_CANCELLED = 'cancelled';

    /** IN_PROGRESS 卡死阈值（秒）：worker 被杀 / 机器重启 */
    public const STALE_SECONDS = 300;

    /**
     * QUEUED 卡死阈值（秒）—— 短得多，语义不同：
     * QUEUED 超时仍未转 IN_PROGRESS，说明 worker **根本没起来**
     * （proc_open 被禁 / PHP-CLI 选错 / 脚本启动即致命错误），
     * 这种任务永远不会有人推进，拖 300s 只会让用户干等。
     * 与 ActionTaskRepository 的 PENDING/STALE 双阈值同源。
     */
    public const PENDING_STALE_SECONDS = 60;

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
     * 新建响应记录（同步 store 与 background 提交共用）。
     */
    public function create(int $userId, array $data): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->insert([
            'user_id'              => $userId,
            'response_id'          => (string) $data['response_id'],
            'previous_response_id' => mb_substr((string) ($data['previous_response_id'] ?? ''), 0, 64),
            'status'               => (string) ($data['status'] ?? self::STATUS_QUEUED),
            'model'                => mb_substr((string) ($data['model'] ?? ''), 0, 160),
            'response_object'      => $this->encode((array) ($data['response_object'] ?? [])),
            'context_messages'     => $this->encode((array) ($data['context_messages'] ?? [])),
            'request_payload'      => $this->encode((array) ($data['request_payload'] ?? [])),
            'background'           => !empty($data['background']) ? 1 : 0,
            'cancel_requested'     => 0,
            'worker_pid'           => 0,
            'started_at'           => (int) ($data['started_at'] ?? 0),
            'finished_at'          => (int) ($data['finished_at'] ?? 0),
        ]);
    }

    /**
     * 对外读取：按 response_id + 属主。
     *
     * @param int|null $userId null = 管理员（不限制属主）
     */
    public function findForTenant(string $responseId, ?int $userId): ?array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('response_id', $responseId);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        $row = $q->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /** 内部读取（worker 使用，不做属主过滤） */
    public function findInternal(string $responseId): ?array
    {
        $this->ensureSchema();

        $row = $this->db->table(self::TABLE)->where('response_id', $responseId)->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * 对外删除。
     *
     * @param int|null $userId null = 管理员
     * @return int 受影响行数（0 = 不存在或不属于该用户）
     */
    public function deleteForTenant(string $responseId, ?int $userId): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('response_id', $responseId);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->delete();
    }

    /** worker 抢锁：queued → in_progress（原子 CAS，未被取消才可抢） */
    public function claim(string $responseId, int $pid = 0): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)
            ->where('response_id', $responseId)
            ->where('status', self::STATUS_QUEUED)
            ->where('cancel_requested', 0)
            ->update([
                'status'     => self::STATUS_IN_PROGRESS,
                'worker_pid' => $pid,
                'started_at' => now_ms(),
            ]);
    }

    /** 写终态（worker / 同步路径共用） */
    public function finish(string $responseId, array $data): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)
            ->where('response_id', $responseId)
            ->update($this->serialize($data + ['finished_at' => now_ms()]));
    }

    /** 标记取消意向（queued 会被 claim 的 CAS 天然拦下） */
    public function requestCancel(string $responseId, ?int $userId): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('response_id', $responseId);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->update(['cancel_requested' => 1]);
    }

    /** 直接把 queued 收口为 cancelled（无人接管时立即生效） */
    public function cancelQueued(string $responseId, ?int $userId): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)
            ->where('response_id', $responseId)
            ->where('status', self::STATUS_QUEUED);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->update([
            'status'           => self::STATUS_CANCELLED,
            'cancel_requested' => 1,
            'finished_at'      => now_ms(),
        ]);
    }

    /** cron 兜底：取待处理的 background 任务（最早的优先） */
    public function duePending(int $limit = 5): array
    {
        $this->ensureSchema();

        $rows = $this->db->table(self::TABLE)
            ->where('status', self::STATUS_QUEUED)
            ->where('background', 1)
            ->where('cancel_requested', 0)
            ->orderBy('id', 'ASC')
            ->limit(max(1, $limit))
            ->get();

        return array_map(fn(array $row): array => $this->hydrate($row), $rows);
    }

    /**
     * 僵尸收口：QUEUED / IN_PROGRESS 超时一律转 failed。
     *
     * 双阈值与 ActionTaskRepository 同源：QUEUED 用短阈值（worker 没起来要尽快暴露），
     * IN_PROGRESS 用长阈值（worker 在跑但可能被杀）。
     */
    public function reapStale(
        int $staleSeconds = self::STALE_SECONDS,
        int $pendingStaleSeconds = self::PENDING_STALE_SECONDS
    ): int {
        $this->ensureSchema();

        $nowMs        = now_ms();
        $runningCutMs = $nowMs - $staleSeconds * 1000;
        $runningCutDt = date('Y-m-d H:i:s', time() - $staleSeconds);
        $queuedCutMs  = $nowMs - $pendingStaleSeconds * 1000;
        $queuedCutDt  = date('Y-m-d H:i:s', time() - $pendingStaleSeconds);

        return $this->db->statement(
            'UPDATE `' . self::TABLE . '`'
            . ' SET `status` = ?, `finished_at` = ?'
            . ' WHERE (`status` = ? AND ((`started_at` > 0 AND `started_at` < ?)'
            . '                             OR (`started_at` = 0 AND `created_at` < ?)))'
            . '    OR (`status` = ? AND `started_at` = 0 AND `created_at` < ?)',
            [
                self::STATUS_FAILED,
                $nowMs,
                self::STATUS_IN_PROGRESS, $runningCutMs, $runningCutDt,
                self::STATUS_QUEUED, $queuedCutDt,
            ]
        );
    }

    /** 按天清理（Scheduler 每日调用） */
    public function prune(int $keepDays): int
    {
        if ($keepDays <= 0) {
            return 0;
        }

        $this->ensureSchema();

        $cutoff = date('Y-m-d H:i:s', time() - $keepDays * 86400);
        return $this->db->statement(
            'DELETE FROM `' . self::TABLE . '` WHERE `created_at` < ?',
            [$cutoff]
        );
    }

    private function serialize(array $data): array
    {
        foreach (['response_object', 'context_messages', 'request_payload'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $this->encode((array) $data[$key]);
            }
        }
        foreach (['background', 'cancel_requested'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $data[$key] ? 1 : 0;
            }
        }
        return $data;
    }

    private function hydrate(array $row): array
    {
        foreach (['response_object', 'context_messages', 'request_payload'] as $key) {
            $row[$key] = $this->decode($row[$key] ?? null);
        }
        $row['user_id']          = (int) ($row['user_id'] ?? 0);
        $row['background']       = (bool) ($row['background'] ?? false);
        $row['cancel_requested'] = (bool) ($row['cancel_requested'] ?? false);
        $row['worker_pid']       = (int) ($row['worker_pid'] ?? 0);
        $row['started_at']       = (int) ($row['started_at'] ?? 0);
        $row['finished_at']      = (int) ($row['finished_at'] ?? 0);
        return $row;
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function decode(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}