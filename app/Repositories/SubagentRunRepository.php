<?php
/**
 * 子代理运行记录仓储（对应 aigw_subagent_run）
 *
 * 参考 Operit 的被动式 Subagent（subagent_run 工具）：主 agent 委托一个
 * 具体任务给子代理，子代理内部以子任务模式跑一次隔离会话，最后回传简短总结。
 *
 * ⚠️ 属主隔离：对外查询必须走 findForUser()。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class SubagentRunRepository
{
    private const TABLE = 'aigw_subagent_run';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAIL    = 'fail';

    public function __construct(private Database $db) {}

    public function ensureSchema(): void
    {
        Schema::ensure($this->db);
    }

    /** 新建一条运行记录（状态 running） */
    public function create(?int $userId, string $runId, string $model, array $params): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)->insert([
            'user_id'        => $userId ?? 0,
            'run_id'         => $runId,
            'model'          => mb_substr($model, 0, 160),
            'task'           => mb_substr((string) ($params['task'] ?? ''), 0, 8000),
            'context_text'   => mb_substr((string) ($params['context_text'] ?? ''), 0, 8000),
            'target_paths'   => json_encode(
                array_values((array) ($params['target_paths'] ?? [])),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'max_tool_calls' => (int) ($params['max_tool_calls'] ?? 8),
            'status'         => self::STATUS_RUNNING,
        ]);
    }

    /** 收口：写入最终状态与总结 */
    public function finish(string $runId, bool $ok, int $toolCount, string $summary, string $error = '', int $durationMs = 0): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->where('run_id', $runId)->update([
            'status'      => $ok ? self::STATUS_SUCCESS : self::STATUS_FAIL,
            'tool_count'  => max(0, $toolCount),
            'summary'     => $summary === '' ? null : $summary,
            'error'       => mb_substr($error, 0, 500),
            'duration_ms' => max(0, $durationMs),
        ]);
    }

    public function findByRunId(string $runId): ?array
    {
        $this->ensureSchema();

        $row = $this->db->table(self::TABLE)->where('run_id', $runId)->first();
        return $row === null ? null : $this->hydrate($row);
    }

    public function findForUser(string $runId, ?int $userId): ?array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('run_id', $runId);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        $row = $q->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /** 最近运行列表（属主隔离） */
    public function listForUser(?int $userId, int $limit = 50): array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        $rows = $q->orderBy('id', 'DESC')->limit(max(1, min(200, $limit)))->get();
        return array_map([$this, 'hydrate'], $rows);
    }

    private function hydrate(array $row): array
    {
        $row['id']           = (int) ($row['id'] ?? 0);
        $row['user_id']      = (int) ($row['user_id'] ?? 0);
        $row['tool_count']   = (int) ($row['tool_count'] ?? 0);
        $row['max_tool_calls'] = (int) ($row['max_tool_calls'] ?? 8);
        $row['duration_ms']  = (int) ($row['duration_ms'] ?? 0);
        $row['target_paths'] = $this->decode($row['target_paths'] ?? null);

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
