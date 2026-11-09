<?php
/**
 * 工作流仓储（对应 aigw_workflow / aigw_workflow_task）
 *
 * 参考 Operit 的 Workflow / WorkflowExecutor / WorkflowScheduler：
 * 工作流是「节点 + 连线」的有向图（Trigger / Execute / Condition / Logic / Extract），
 * 支持 manual / interval / cron / specific_time 四种调度。
 *
 * 调度机制对齐本项目既有 Scheduler：不依赖常驻进程，
 * 由 cron 驱动的 bin/workflow-worker.php 扫描 next_run_at 到期的行并执行。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class WorkflowRepository
{
    private const TABLE = 'aigw_workflow';
    private const TASK_TABLE = 'aigw_workflow_task';

    /** 周期任务最小间隔（秒）—— 与 Operit 的 15 分钟一致 */
    public const MIN_INTERVAL = 900;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_DONE    = 'DONE';
    public const STATUS_FAILED  = 'FAILED';

    public function __construct(private Database $db) {}

    public function ensureSchema(): void
    {
        Schema::ensure($this->db);
    }

    // ── 工作流定义 ─────────────────────────────────────────────

    public function list(?int $userId): array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return array_map([$this, 'hydrate'], $q->orderBy('id', 'DESC')->get());
    }

    public function findForUser(int $id, ?int $userId): ?array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('id', $id);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        $row = $q->first();
        return $row === null ? null : $this->hydrate($row);
    }

    public function create(array $data): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)->insert($this->pack($data));
    }

    /** @param ?int $userId 归属过滤（审计 TOCTOU 修复），语义同 findForUser */
    public function update(int $id, array $data, ?int $userId = null): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('id', $id);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->update($this->pack($data));
    }

    public function delete(int $id, ?int $userId = null): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('id', $id);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->delete();
    }

    /** 到期待执行的工作流（供 worker 扫描） */
    public function due(int $nowMs, int $limit = 20): array
    {
        $this->ensureSchema();

        return array_map([$this, 'hydrate'],
            $this->db->table(self::TABLE)
                ->where('enabled', 1)
                ->where('next_run_at', '>', 0)
                ->where('next_run_at', '<=', $nowMs)
                ->orderBy('next_run_at', 'ASC')
                ->limit($limit)
                ->get()
        );
    }

    /** 更新调度水位（跑完一轮后写回 last_run / next_run） */
    public function markRun(int $id, int $lastRunMs, int $nextRunMs, bool $ok, string $message): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->where('id', $id)->update([
            'last_run_at'  => $lastRunMs,
            'next_run_at'  => $nextRunMs,
            'last_status'  => $ok ? 'OK' : 'FAIL',
            'last_message' => mb_substr($message, 0, 500),
        ]);
        $this->db->table(self::TABLE)->where('id', $id)->increment('run_count');
    }

    // ── 执行记录 ───────────────────────────────────────────────

    public function createTask(int $workflowId, int $userId, string $trigger): array
    {
        $this->ensureSchema();

        $taskId = bin2hex(random_bytes(16));
        $id = $this->db->table(self::TASK_TABLE)->insert([
            'task_id'     => $taskId,
            'workflow_id' => $workflowId,
            'user_id'     => $userId,
            'trigger'     => $trigger,
            'status'      => self::STATUS_PENDING,
        ]);

        return ['task_id' => $taskId, 'id' => $id];
    }

    public function findTaskForUser(string $taskId, ?int $userId): ?array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TASK_TABLE)->where('task_id', $taskId);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->first();
    }

    public function listTasks(int $workflowId, ?int $userId, int $limit = 20): array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TASK_TABLE)->where('workflow_id', $workflowId);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->orderBy('id', 'DESC')->limit($limit)->get();
    }

    /** worker 抢锁（原子 CAS：仅 PENDING 能转 RUNNING） */
    public function claimTask(string $taskId): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TASK_TABLE)
            ->where('task_id', $taskId)
            ->where('status', self::STATUS_PENDING)
            ->update([
                'status'     => self::STATUS_RUNNING,
                'started_at' => now_ms(),
            ]);
    }

    public function finishTask(string $taskId, bool $ok, string $message, array $steps = []): void
    {
        $this->ensureSchema();

        $this->db->table(self::TASK_TABLE)->where('task_id', $taskId)->update([
            'status'      => $ok ? self::STATUS_DONE : self::STATUS_FAILED,
            'ok'          => $ok ? 1 : 0,
            'message'     => mb_substr($message, 0, 500),
            'steps'       => $steps === [] ? null : json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'finished_at' => now_ms(),
        ]);
    }

    // ── 打包 / 整形 ────────────────────────────────────────────

    private function pack(array $data): array
    {
        $packed = [
            'name'        => mb_substr((string) ($data['name'] ?? ''), 0, 128),
            'description' => mb_substr((string) ($data['description'] ?? ''), 0, 512),
            'schedule'    => in_array($data['schedule'] ?? '', ['manual', 'interval', 'cron', 'specific_time'], true)
                ? $data['schedule'] : 'manual',
            'cron'        => mb_substr((string) ($data['cron'] ?? ''), 0, 64),
        ];

        if (array_key_exists('graph', $data)) {
            $packed['graph'] = json_encode((array) $data['graph'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('interval', $data)) {
            $packed['interval'] = max(self::MIN_INTERVAL, (int) $data['interval']);
        }
        if (array_key_exists('specific_at', $data)) {
            $packed['specific_at'] = (int) $data['specific_at'];
        }
        if (array_key_exists('next_run_at', $data)) {
            $packed['next_run_at'] = (int) $data['next_run_at'];
        }
        if (array_key_exists('enabled', $data)) {
            $packed['enabled'] = !empty($data['enabled']) ? 1 : 0;
        }
        if (array_key_exists('user_id', $data)) {
            $packed['user_id'] = (int) $data['user_id'];
        }

        return $packed;
    }

    private function hydrate(array $row): array
    {
        $graph = json_decode((string) ($row['graph'] ?? ''), true);
        $row['graph'] = is_array($graph) ? $graph : ['nodes' => [], 'edges' => []];
        $row['enabled'] = (int) ($row['enabled'] ?? 0) === 1;
        $row['interval'] = (int) ($row['interval'] ?? 0);
        $row['next_run_at'] = (int) ($row['next_run_at'] ?? 0);
        $row['last_run_at'] = (int) ($row['last_run_at'] ?? 0);
        $row['run_count'] = (int) ($row['run_count'] ?? 0);

        return $row;
    }
}
