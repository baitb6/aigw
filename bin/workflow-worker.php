<?php
/**
 * 工作流调度 —— 后台 worker（CLI）
 *
 * 用法：
 *   php bin/workflow-worker.php
 *
 * 职责：扫描 aigw_workflow 里 next_run_at 已到期、且处于启用状态的工作流，
 * 按拓扑序逐个节点执行，并把下次执行时间写回 next_run_at。
 *
 * 由系统 cron 周期触发（见 deploy/crontab.txt）。本脚本是**幂等**的：
 * runDue() 内部对每条工作流执行后立即更新 next_run_at，重复触发不会重复执行。
 *
 * ⚠️ 参考 Operit 的 WorkManager 定时任务语义，但把调度放到服务端 cron：
 *    精度更高（不受 Android 15 分钟下限约束），且不依赖客户端存活。
 */

declare(strict_types=1);

define('AIGW_START', microtime(true));
define('AIGW_ROOT', dirname(__DIR__));
define('AIGW_VERSION', '1.0.0');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AIGW_ROOT . '/storage/logs/workflow-worker.log');

require AIGW_ROOT . '/bootstrap/autoload.php';

use App\Services\WorkflowService;
use App\Support\Logger;

try {
    $app = new \App\Core\Application(AIGW_ROOT);
    $c = $app->container();

    /** @var WorkflowService $service */
    $service = $c->make(WorkflowService::class);

    $limit = 20;
    if (isset($argv[1]) && ctype_digit((string) $argv[1])) {
        $limit = max(1, min(200, (int) $argv[1]));
    }

    $result = $service->runDue($limit);

    fwrite(STDOUT, sprintf(
        "[workflow] 到期 %d 个，失败 %d 个，耗时 %.1fs\n",
        $result['total'],
        $result['failed'],
        microtime(true) - AIGW_START
    ));

    exit(0);
} catch (\Throwable $e) {
    Logger::error('[workflow-worker] 执行异常：' . $e->getMessage(), [
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    fwrite(STDERR, '[workflow] 执行异常：' . $e->getMessage() . "\n");
    exit(1);
}
