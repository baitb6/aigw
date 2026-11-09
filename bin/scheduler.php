<?php
/**
 * 定时任务 CLI 入口
 *
 * 用法（计划任务 / 宝塔定时）：
 *   php bin/scheduler.php           # 按网关设置执行（自动签到 / 自动刷新）
 *   php bin/scheduler.php --force   # 忽略开关，强制执行一次
 *
 * 与 HTTP 入口 POST /api/admin/scheduler/run 共用同一套 Scheduler 服务，
 * 二者可任选其一接入系统计划任务。
 */

declare(strict_types=1);

define('AIGW_START', microtime(true));
define('AIGW_ROOT', dirname(__DIR__));
define('AIGW_VERSION', '1.0.0');

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', AIGW_ROOT . '/storage/logs/php-error.log');

require AIGW_ROOT . '/bootstrap/autoload.php';

$force = in_array('--force', $argv ?? [], true);

try {
    $app = new \App\Core\Application(AIGW_ROOT);
    $scheduler = $app->container()->make(\App\Services\Scheduler::class);
    $result = $scheduler->run($force);

    echo '[scheduler] ' . ($result['ran'] ? '已执行' : '已跳过') . '：' . $result['message'] . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
} catch (\Throwable $e) {
    echo '[scheduler] 执行失败：' . $e->getMessage() . PHP_EOL;
    exit(1);
}