<?php
/**
 * 深度研究 —— 后台 worker（CLI）
 *
 * 用法：
 *   php bin/research-worker.php <task_id>   # 执行单个任务（由提交接口拉起）
 *   php bin/research-worker.php --due [n]   # 扫描并执行最多 n 个待处理任务（cron 兜底）
 *
 * 职责：执行「规划 → 分层执行子任务 → 汇总」，把结果写回 aigw_research_task。
 * 由 Web 提交接口通过 proc_open 拉起，或由 cron 定期兜底扫描 —— 均独立于
 * HTTP 请求生命周期，因此不受 CDN 回源超时（524）约束。
 *
 * ⚠️ 参考 Operit 的 DeepSearch：把多轮 LLM 调用的长耗时留在服务端后台进程。
 */

declare(strict_types=1);

define('AIGW_START', microtime(true));
define('AIGW_ROOT', dirname(__DIR__));
define('AIGW_VERSION', '1.0.0');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AIGW_ROOT . '/storage/logs/research-worker.log');

require AIGW_ROOT . '/bootstrap/autoload.php';

use App\Repositories\ResearchTaskRepository;
use App\Services\ResearchService;
use App\Support\Logger;

try {
    $app = new \App\Core\Application(AIGW_ROOT);
    $c = $app->container();

    /** @var ResearchService $service */
    $service = $c->make(ResearchService::class);
    /** @var ResearchTaskRepository $repository */
    $repository = $c->make(ResearchTaskRepository::class);

    $arg = trim((string) ($argv[1] ?? ''));

    // ── 模式一：扫描待处理任务（cron 兜底） ──────────────────────────
    if ($arg === '--due') {
        $limit = isset($argv[2]) && ctype_digit((string) $argv[2])
            ? max(1, min(50, (int) $argv[2])) : 5;

        $repository->ensureSchema();
        $repository->reapStale();

        $pending = $repository->duePending($limit);

        $done = 0;
        foreach ($pending as $row) {
            $result = $service->run((string) $row['task_id']);
            if ($result['ok']) {
                $done++;
            }
        }

        fwrite(STDOUT, sprintf("[research] 待处理 %d 个，成功 %d 个，耗时 %.1fs\n",
            count($pending), $done, microtime(true) - AIGW_START));
        exit(0);
    }

    // ── 模式二：执行指定任务 ────────────────────────────────────────
    if ($arg === '' || !preg_match('/^[a-f0-9]{32}$/', $arg)) {
        fwrite(STDERR, "[research-worker] 用法：php bin/research-worker.php <task_id> | --due [n]\n");
        exit(2);
    }

    $result = $service->run($arg);

    Logger::info('[research-worker] 任务结束', [
        'task_id' => $arg,
        'ok'      => $result['ok'],
        'cost'    => round(microtime(true) - AIGW_START, 1),
    ]);

    fwrite(STDOUT, sprintf("[research] %s：%s（耗时 %.1fs）\n",
        $arg, $result['message'], microtime(true) - AIGW_START));

    exit(0);
} catch (\Throwable $e) {
    Logger::error('[research-worker] 执行异常：' . $e->getMessage(), [
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    fwrite(STDERR, '[research] 执行异常：' . $e->getMessage() . "\n");
    exit(1);
}
