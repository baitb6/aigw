<?php
/**
 * 异步动作任务仓储（对应 aigw_action_task）
 *
 * 为什么要有它（2026-10-02 线上 524 血案）：
 *   线上站点前面挂着 CDN（腾讯云 EdgeOne），其「回源超时」**默认只有 15 秒**
 *   （官方文档：5~600s 可调，默认 15）。而 ZCode「领取额度」这条链路要跑：
 *     ① GET client/configs（免鉴权，~0.3s）
 *     ② 无痕验证求解（驱动真实浏览器，本地实测 9~21s，最坏 75s × 2 次重试）
 *     ③ POST billing/claim
 *   合计很容易超过回源超时 → CDN 回源连接被掐 → 客户端拿到 **524**。
 *   而源站（PHP）其实还在跑、最终可能成功 —— 用户却只看到报错。
 *
 * 解法：把这类动作拆成「提交 → 后台执行 → 轮询」三段，
 *   提交请求**立即返回**（毫秒级），不再占用 CDN 回源连接；
 *   后台 worker 由独立 CLI 进程跑，不受 CDN 超时约束。
 *
 * ⚠️ 属主隔离：`findForUser()` 必须带 user_id 条件（管理员 scope=null 时不限），
 *    否则普通用户能凭 task_id 读到别人的任务结果（越权）。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class ActionTaskRepository
{
    private const TABLE = 'aigw_action_task';

    /** 任务状态 */
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_DONE    = 'DONE';
    public const STATUS_FAILED  = 'FAILED';

    /** 任务存活时长（秒）—— RUNNING 超时未完成视为卡死（worker 被杀/机器重启） */
    public const STALE_SECONDS = 300;

    /**
     * PENDING 卡死阈值（秒）—— 短得多，因为语义不同：
     *   PENDING 超过这个时间还没转 RUNNING，说明 worker **根本没起来**
     *   （proc_open 被禁 / PHP-CLI 选错 / 脚本启动即致命错误），
     *   这种任务永远不会有人来推进，拖 300s 毫无意义。
     *   RUNNING 才是「worker 在跑但可能被杀」，那才需要 STALE_SECONDS。
     *
     * 2026-10-02 线上「卡在正在执行中、看不到成功也没报错」故障的直接教训：
     * 当时统一用 300s，用户在 5 分钟里只能干看着转圈。
     */
    public const PENDING_STALE_SECONDS = 60;

    /**
     * 误杀补救窗口（秒）—— **必须小于 `PENDING_STALE_SECONDS`**。
     *
     * `claimForRun()` 的补救路径允许「`worker_pid=0` 且当前 PENDING/FAILED」的任务被 worker 抢回。
     * 但这个条件对**两类任务同时成立**：
     *   ① **web 乐观确认误杀**（web 抢先 `finish(FAILED, WORKER_START_FAILED)`）→ **应该救**；
     *   ② **被 `reapStale()` 收口的僵尸**（worker 从没起来，60s 后系统已宣告
     *      「后台任务未能启动」并写入 `error_code=WORKER_NOT_STARTED`）→ **不该救**。
     * 二者在库里**完全同形**（都是 `FAILED` + `worker_pid=0`，无标记字段可区分），
     * 故**只能靠时间**把它们分开：误杀发生在提交后**几秒内**，收口发生在**60 秒后**。
     *
     * ⚠️ 因此本值**绝不可 ≥ `PENDING_STALE_SECONDS`**，否则迟到 worker 会复活
     *    已被系统宣告「worker 未接管」的真僵尸 —— 自相矛盾，且白白多烧一次验证码/上游额度。
     *    护栏见 `claimForRun()` 开头的 `min()`。
     *
     * 30s 的依据：worker 若真能被 spawn 起来，正常在**毫秒~几秒**内就抢到锁；
     * 30s 已是极宽松的上限，同时与 `recentForAccount()` 的复用抖动窗（30s）对齐。
     */
    public const RESCUE_WINDOW_SECONDS = 30;

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
     * 建任务（提交阶段调用，必须**极快**：一次 INSERT 后立即返回）
     *
     * @return array{task_id:string,id:int}
     */
    public function create(array $data): array
    {
        $this->ensureSchema();

        $taskId = bin2hex(random_bytes(16)); // 32 hex

        $id = $this->db->table(self::TABLE)->insert([
            'task_id'      => $taskId,
            'user_id'      => (int) ($data['user_id'] ?? 0),
            'provider_key' => mb_substr((string) ($data['provider_key'] ?? ''), 0, 32),
            'account_id'   => (int) ($data['account_id'] ?? 0),
            'account_uid'  => mb_substr((string) ($data['account_uid'] ?? ''), 0, 128),
            'action'       => mb_substr((string) ($data['action'] ?? ''), 0, 32),
            'params'       => json_encode(
                (array) ($data['params'] ?? []),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'status'       => self::STATUS_PENDING,
        ]);

        return ['task_id' => $taskId, 'id' => $id];
    }

    /**
     * 按 task_id 取任务（未经属主过滤，仅供内部 worker 使用）
     */
    public function findByTaskId(string $taskId): ?array
    {
        $this->ensureSchema();

        $row = $this->db->table(self::TABLE)->where('task_id', $taskId)->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * 按 task_id + 属主取任务 —— ★ 对外接口必须走这个。
     *
     * @param int|null $userId null = 管理员（不限制属主）
     */
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

    /**
     * 标记为执行中（worker 抢到任务时调用）。
     *
     * ★ 返回**受影响行数**，调用方必须据此判定是否真的抢到：
     *   1 = 本 worker 抢到，可以执行；0 = 已被别人抢走，必须立即退出。
     *   —— 这是「先 CAS 再执行」的原子闸门。若反过来「先查状态再执行」，
     *   两个 worker 同时在途时会双双读到 PENDING 而**重复执行**（重复烧验证码）。
     */
    public function markRunning(string $taskId, int $pid = 0): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)
            ->where('task_id', $taskId)
            ->where('status', self::STATUS_PENDING)  // 乐观锁：只有 PENDING 能转 RUNNING
            ->update([
                'status'     => self::STATUS_RUNNING,
                'worker_pid' => $pid,
                'started_at' => now_ms(),
            ]);
    }

    /**
     * ★ 2026-10-02 四修：worker 抢锁（**修正「竞态误杀」**）。
     *
     * 背景（线上真实故障）：web 端 `submitAsyncAction()` 在 spawn 成功后做「乐观确认」，
     * 超时就把任务 `finish(FAILED, WORKER_START_FAILED)`。但 worker 是**独立进程**，
     * 冷启动 + 首次 DB 连接在生产上可能 >1.2s —— 于是出现致命竞态：
     *
     *     worker 启动稍慢(>1.2s) → web 把任务误杀成 FAILED
     *       → worker 终于起来，markRunning() 要求 status=PENDING，此时已是 FAILED
     *       → 抢锁失败 → exit(0) → **任务永久废掉，从未执行**
     *
     * 修法：区分两种「非 PENDING」——
     *   · 已被**真正执行过**（`worker_pid > 0`，即曾转 RUNNING）→ 说明另有 worker 在跑，**不抢**（保持并发保护）。
     *   · 从未被执行过（`worker_pid = 0`）且当前是 FAILED/PENDING → 视为「web 端误杀」，
     *     **允许抢回**（CAS 到 RUNNING 并写入本进程 pid）。
     *
     * 并发安全：仍用**单条带 WHERE 条件的 UPDATE**（原子），不会出现两个 worker 同时抢到。
     *
     * ⚠️ **补救路径必须带时间窗**（否则僵尸会"复活"）：
     *   `reapStale()` 把超时任务标 FAILED 时**不改 `worker_pid`**，所以一个 60s 前就被收口的
     *   僵尸任务同样满足「worker_pid=0 + FAILED」。若不限时间，任何一个迟到的 worker
     *   都能把它抢回来执行 —— 那会绕过 60s 短阈值收口的意义。
     *   窗口默认 `RESCUE_WINDOW_SECONDS = 30`，**恒小于 `PENDING_STALE_SECONDS = 60`**（见其注释），
     *   故只可能覆盖「刚被 web 误杀」，不可能覆盖「已被收口的僵尸」。
     *
     * @param int $rescueWithinSeconds 补救窗口（秒）—— 只抢「刚被误杀」的，不复活真僵尸
     * @return int 受影响行数：1 = 抢到，0 = 已被别人执行 / 超出补救窗口（应退出）
     */
    public function claimForRun(string $taskId, int $pid = 0, int $rescueWithinSeconds = self::RESCUE_WINDOW_SECONDS): int
    {
        $this->ensureSchema();

        // ① 正常路径：PENDING → RUNNING
        $n = $this->markRunning($taskId, $pid);
        if ($n > 0) {
            return $n;
        }

        // ② 补救路径：从未被执行过（worker_pid=0）且**刚刚创建** → 允许从 PENDING/FAILED 抢回。
        //    CAS 条件里带 `worker_pid = 0`（不抢别人正在跑的）+ `created_at` 时间窗（不复活僵尸）。
        //
        //    ★ 护栏：窗口**硬性封顶在 `PENDING_STALE_SECONDS` 以下** ——
        //      否则「补救窗口 ≥ 收口阈值」时会复活 reapStale() 已收口的真僵尸（自相矛盾）。
        //      调用方若传了过大的值，这里直接钳掉，不靠调用方自觉。
        $window = min(max(1, $rescueWithinSeconds), self::PENDING_STALE_SECONDS - 1);
        $cutoff = date('Y-m-d H:i:s', time() - $window);

        return $this->db->table(self::TABLE)
            ->where('task_id', $taskId)
            ->where('worker_pid', 0)
            ->where('status', 'IN', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->where('created_at', '>=', $cutoff)
            ->update([
                'status'      => self::STATUS_RUNNING,
                'worker_pid'  => $pid,
                'started_at'  => now_ms(),
                'ok'          => 0,
                'message'     => '',
                'error_code'  => '',
                'result'      => null,
                'finished_at' => 0,
            ]);
    }

    /**
     * 写入最终结果
     *
     * @param array $result 动作返回的 data 段（原样透传给前端）
     */
    public function finish(string $taskId, bool $ok, string $message, array $result = [], string $errorCode = ''): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->where('task_id', $taskId)->update([
            'status'      => $ok ? self::STATUS_DONE : self::STATUS_FAILED,
            'ok'          => $ok ? 1 : 0,
            'message'     => mb_substr($message, 0, 500),
            'result'      => $result === [] ? null : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_code'  => mb_substr($errorCode, 0, 32),
            'finished_at' => now_ms(),
        ]);
    }

    /**
     * 取该账号最近的同动作任务（用于幂等：短时间内重复提交同一动作直接复用）
     *
     * 抖动窗口内（默认 30s）若已有 **真正在跑** 的任务，或任务刚刚完成，
     * 直接复用它 —— 避免用户连点「领取」触发多次验证码求解（每次都要起浏览器、烧上游）。
     *
     * ⚠️ 2026-10-02 线上「卡在正在执行中」的关键缺陷（已修）：
     *   旧实现对本表的 PENDING/RUNNING **一律复用、不看时间**。可 worker 若根本没起来
     *   （proc_open 被禁 / PHP-CLI 选错），任务会永远停在 PENDING ——
     *   于是用户每次重试都被"复用"回同一个僵尸任务，**界面永远卡在正在执行中**，
     *   而且永远等不到结果（除非 Scheduler 五分钟后来收尸）。
     *   现在：PENDING 超过 `$pendingStaleSeconds` 未转 RUNNING → 判定为僵尸，
     *   先落库收口，再返回 null（让调用方建新任务，给用户一条活路）。
     */
    public function recentForAccount(int $accountId, string $action, int $withinSeconds = 30, int $pendingStaleSeconds = self::PENDING_STALE_SECONDS): ?array
    {
        $this->ensureSchema();

        $row = $this->db->table(self::TABLE)
            ->where('account_id', $accountId)
            ->where('action', $action)
            ->orderBy('id', 'DESC')
            ->first();

        if ($row === null) {
            return null;
        }

        $status = (string) ($row['status'] ?? '');

        // ── 活跃任务：只有在「确实还在推进」时才复用 ──────────────────────
        if ($status === self::STATUS_RUNNING) {
            // worker 已接手。但也要防空转：RUNNING 超过 STALE 视为卡死。
            $startedAt = (int) ($row['started_at'] ?? 0);
            if ($startedAt > 0 && now_ms() - $startedAt > self::STALE_SECONDS * 1000) {
                $this->finish(
                    (string) $row['task_id'],
                    false,
                    '任务超时未完成（后台进程可能已退出），请重试。',
                    [],
                    'STALE'
                );
                return null;
            }
            return $this->hydrate($row);
        }

        if ($status === self::STATUS_PENDING) {
            // ★ 僵尸判定：PENDING 长时间不转 RUNNING = worker 根本没起来。
            $createdMs = (int) (strtotime((string) $row['created_at']) * 1000);
            if ($createdMs > 0 && now_ms() - $createdMs > $pendingStaleSeconds * 1000) {
                $this->finish(
                    (string) $row['task_id'],
                    false,
                    '后台任务未能启动（worker 未接管）。请检查 proc_open 是否被禁用、'
                        . 'PHP-CLI 是否可用；详见 storage/logs/action-worker.log。',
                    [],
                    'WORKER_NOT_STARTED'
                );
                return null;
            }
            // 刚提交不久，可能正在起 —— 复用，让前端继续等
            return $this->hydrate($row);
        }

        // ── 已完成任务：仅当在抖动窗口内才复用（防止「今天已领」把新的领取请求吸走）──
        $ts = (int) ($row['started_at'] ?? 0);
        if ($ts === 0) {
            $ts = (int) (strtotime((string) $row['created_at']) * 1000);
        }
        if (now_ms() - $ts > $withinSeconds * 1000) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * 清理过期任务（保留 N 天）—— 由 Scheduler 每日调用
     */
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

    /**
     * 把 PENDING/RUNNING 中的僵尸任务标记为 FAILED
     * （worker 进程被杀 / 机器重启导致任务永远停在 RUNNING；
     *  或 worker 根本没起来导致永远停在 PENDING）
     *
     * ★ 双阈值：PENDING 用短阈值（PENDING_STALE_SECONDS=60），
     *   RUNNING 用长阈值（STALE_SECONDS=300）。理由见两个常量的注释。
     *
     * @return int 受影响行数
     */
    public function reapStale(int $staleSeconds = self::STALE_SECONDS, int $pendingStaleSeconds = self::PENDING_STALE_SECONDS): int
    {
        $this->ensureSchema();

        $nowMs        = now_ms();
        $cutoffMs     = $nowMs - $staleSeconds * 1000;              // RUNNING 的界
        $cutoffDt     = date('Y-m-d H:i:s', time() - $staleSeconds);
        $pendingCutMs = $nowMs - $pendingStaleSeconds * 1000;       // PENDING 的界
        $pendingCutDt = date('Y-m-d H:i:s', time() - $pendingStaleSeconds);

        return $this->db->statement(
            'UPDATE `' . self::TABLE . '`'
            . ' SET `status` = ?, `ok` = 0,'
            . '     `message` = IF(`message` = \'\' OR `message` IS NULL,'
            . '         CASE WHEN `status` = ? THEN \'后台任务未能启动（worker 未接管）。请检查 proc_open / PHP-CLI，见 storage/logs/action-worker.log。\''
            . '              ELSE \'任务超时未完成（worker 可能被杀或机器重启）\' END,'
            . '         `message`),'
            . '     `error_code` = IF(`error_code` = \'\' OR `error_code` IS NULL,'
            . '         CASE WHEN `status` = ? THEN \'WORKER_NOT_STARTED\' ELSE \'STALE\' END,'
            . '         `error_code`),'
            . '     `finished_at` = ?'
            . ' WHERE (`status` = ? AND ((`started_at` > 0 AND `started_at` < ?) OR (`started_at` = 0 AND `created_at` < ?)))'
            . '    OR (`status` = ? AND `started_at` = 0 AND `created_at` < ?)',
            [
                self::STATUS_FAILED,
                self::STATUS_PENDING, self::STATUS_PENDING,
                $nowMs,
                self::STATUS_RUNNING, $cutoffMs, $cutoffDt,
                self::STATUS_PENDING, $pendingCutDt,
            ]
        );
    }

    /**
     * 行 → 数组（JSON 字段解码）
     */
    private function hydrate(array $row): array
    {
        $row['params'] = $this->decodeJson($row['params'] ?? null);
        $row['result'] = $this->decodeJson($row['result'] ?? null);
        $row['ok']     = (bool) ($row['ok'] ?? false);
        return $row;
    }

    private function decodeJson($raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
