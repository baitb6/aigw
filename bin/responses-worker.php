<?php
/**
 * Responses API —— 后台执行 worker（CLI）
 *
 * 用法：
 *   php bin/responses-worker.php <response_id>   # 执行单个 background 响应
 *   php bin/responses-worker.php --due [n]       # 扫描并执行最多 n 个 queued 任务（cron 兜底）
 *
 * 职责：把 `background: true` 的 /v1/responses 请求从 HTTP 生命周期里摘出去。
 * 前端 `POST /v1/responses` 只落一条 `queued` 记录就立即返回（毫秒级），
 * 再用 `GET /v1/responses/{id}` 轮询终态 —— 因此不受 CDN 回源超时（524）约束。
 *
 * ⚠️ 由 Web 端通过 proc_open 拉起；也被 cron 兜底扫描（见 deploy/crontab.txt）。
 */

declare(strict_types=1);

define('AIGW_START', microtime(true));
define('AIGW_ROOT', dirname(__DIR__));
define('AIGW_VERSION', '1.0.0');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AIGW_ROOT . '/storage/logs/responses-worker.log');

require AIGW_ROOT . '/bootstrap/autoload.php';

use App\Repositories\ResponsesRepository;
use App\Services\ResponsesService;
use App\Support\Logger;

try {
    $app = new \App\Core\Application(AIGW_ROOT);
    $c = $app->container();

    /** @var ResponsesService $service */
    $service = $c->make(ResponsesService::class);
    /** @var ResponsesRepository $repository */
    $repository = $c->make(ResponsesRepository::class);

    $arg = trim((string) ($argv[1] ?? ''));

    // ── 模式一：扫描待处理任务（cron 兜底） ──────────────────────────
    if ($arg === '--due') {
        $limit = isset($argv[2]) && ctype_digit((string) $argv[2])
            ? max(1, min(50, (int) $argv[2])) : 5;

        $repository->reapStale();

        $done = 0;
        $pending = $repository->duePending($limit);
        foreach ($pending as $row) {
            $result = $service->run((string) $row['response_id']);
            if ($result['ok']) {
                $done++;
            }
        }

        fwrite(STDOUT, sprintf("[responses] 待处理 %d 个，成功 %d 个，耗时 %.1fs\n",
            count($pending), $done, microtime(true) - AIGW_START));
        exit(0);
    }

    // ── 模式二：执行指定响应 ────────────────────────────────────────
    if ($arg === '' || !preg_match('/^resp_[a-f0-9]{24}$/', $arg)) {
        fwrite(STDERR, "[responses-worker] 用法：php bin/responses-worker.php <resp_xxx> | --due [n]\n");
        exit(2);
    }

    $result = $service->run($arg);

    Logger::info('[responses-worker] 任务结束', [
        'response_id' => $arg,
        'ok'          => $result['ok'],
        'cost'        => round(microtime(true) - AIGW_START, 1),
    ]);

    fwrite(STDOUT, sprintf("[responses] %s：%s（耗时 %.1fs）\n",
        $arg, $result['message'], microtime(true) - AIGW_START));

    exit(0);
} catch (\Throwable $e) {
    Logger::error('[responses-worker] 执行异常：' . $e->getMessage(), [
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    fwrite(STDERR, '[responses] 执行异常：' . $e->getMessage() . "\n");
    exit(1);
}