<?php
/**
 * 深度研究任务仓储（对应 aigw_research_task）
 *
 * 参考 Operit 的 DeepSearch：异步「规划 → 分层执行 → 汇总」。
 * 复用 aigw_action_task 的「提交即返回 task_id → 后台 worker → 轮询」范式，
 * 绕开 CDN 回源超时（524），并把 LLM 多轮调用的长耗时留在服务端。
 *
 * ⚠️ 属主隔离：对外接口必须走 findForUser()。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class ResearchTaskRepository
{
    private const TABLE = 'aigw_research_task';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_DONE    = 'DONE';
    public const STATUS_FAILED  = 'FAILED';

    /** RUNNING 卡死阈值（秒）—— 深度研究耗时较长，给足余量 */
    public const STALE_SECONDS = 600;

    /** PENDING 卡死阈值（秒）—— worker 没起来 */
    public const PENDING_STALE_SECONDS = 60;

    public function __construct(private Database $db) {}

    public function ensureSchema(): void
    {
        Schema::ensure($this->db);
    }

    /** @return array{task_id:string,id:int} */
    public function create(?int $userId, string $topic): array
    {
        $this->ensureSchema();

        $taskId = bin2hex(random_bytes(16));
        $id = $this->db->table(self::TABLE)->insert([
            'task_id' => $taskId,
            'user_id' => $userId ?? 0,
            'topic'   => mb_substr($topic, 0, 500),
            'status'  => self::STATUS_PENDING,
        ]);

        return ['task_id' => $taskId, 'id' => $id];
    }

    public function findByTaskId(string $taskId): ?array
    {
        $this->ensureSchema();

        $row = $this->db->table(self::TABLE)->where('task_id', $taskId)->first();
        return $row === null ? null : $this->hydrate($row);
    }

    public function findForUser(string $taskId, ?int $userId): ?array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('task_id', $taskId);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        $row = $q->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /** CAS 抢占（PENDING → RUNNING），返回受影响行数 */
    public function claim(string $taskId): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)
            ->where('task_id', $taskId)
            ->where('status', self::STATUS_PENDING)
            ->update([
                'status'     => self::STATUS_RUNNING,
                'started_at' => now_ms(),
                'progress'   => '正在规划研究步骤…',
            ]);
    }

    /** 更新进度 / 执行图 / 步骤明细 */
    public function progress(string $taskId, string $progress, ?array $graph = null, ?array $steps = null): void
    {
        $this->ensureSchema();

        $data = ['progress' => mb_substr($progress, 0, 250)];
        if ($graph !== null) {
            $data['graph'] = json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($steps !== null) {
            $data['steps'] = json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $this->db->table(self::TABLE)->where('task_id', $taskId)->update($data);
    }

    public function finish(string $taskId, bool $ok, string $message, string $result = '', string $errorCode = ''): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->where('task_id', $taskId)->update([
            'status'      => $ok ? self::STATUS_DONE : self::STATUS_FAILED,
            'ok'          => $ok ? 1 : 0,
            'message'     => mb_substr($message, 0, 500),
            'result'      => $result === '' ? null : $result,
            'error_code'  => mb_substr($errorCode, 0, 32),
            'finished_at' => now_ms(),
        ]);
    }

    /** 取待处理任务（cron 兜底扫描用），按创建顺序 */
    public function duePending(int $limit = 5): array
    {
        $this->ensureSchema();

        $rows = $this->db->table(self::TABLE)
            ->where('status', self::STATUS_PENDING)
            ->orderBy('id', 'ASC')
            ->limit(max(1, $limit))
            ->get();

        return array_map([$this, 'hydrate'], $rows);
    }

    /** 收口僵尸任务（worker 未起 / 被杀） */
    public function reapStale(): int
    {
        $this->ensureSchema();

        $nowMs = now_ms();
        $runCutMs = $nowMs - self::STALE_SECONDS * 1000;
        $pendCutMs = $nowMs - self::PENDING_STALE_SECONDS * 1000;

        $a = $this->db->table(self::TABLE)
            ->where('status', self::STATUS_RUNNING)
            ->where('started_at', '<', $runCutMs)
            ->where('started_at', '>', 0)
            ->update([
                'status'      => self::STATUS_FAILED,
                'ok'          => 0,
                'message'     => '研究任务超时未完成（后台进程可能已退出）',
                'error_code'  => 'STALE',
                'finished_at' => $nowMs,
            ]);

        $b = $this->db->table(self::TABLE)
            ->where('status', self::STATUS_PENDING)
            ->where('started_at', 0)
            ->update([
                'status'      => self::STATUS_FAILED,
                'ok'          => 0,
                'message'     => '后台任务未能启动（worker 未接管）',
                'error_code'  => 'WORKER_NOT_STARTED',
                'finished_at' => $nowMs,
            ]);

        return $a + $b;
    }

    private function hydrate(array $row): array
    {
        $row['ok'] = (bool) ($row['ok'] ?? false);
        $row['graph'] = $this->decode($row['graph'] ?? null);
        $row['steps'] = $this->decode($row['steps'] ?? null);
        $row['started_at'] = (int) ($row['started_at'] ?? 0);
        $row['finished_at'] = (int) ($row['finished_at'] ?? 0);

        return $row;
    }

    private function decode($raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
