<?php
/**
 * 异步动作任务 —— 后台 worker（CLI）
 *
 * 用法：
 *   php bin/action-worker.php <task_id>
 *   php bin/action-worker.php <task_id> --detach   # 由 Web 提交接口拉起时使用
 *
 * 职责：真正执行被异步化的动作（当前主要是 ZCode「领取额度」），
 * 把结果写回 aigw_action_task。由 Web 端的提交接口通过 proc_open 拉起，
 * **独立于 HTTP 请求生命周期** —— 因此不受 CDN 回源超时（524）约束。
 *
 * 为什么必须独立进程：
 *   PHP-FPM worker 被杀 / 请求结束都会中断执行；而「无痕验证求解」要驱动
 *   真实浏览器，耗时 9~75s，绝不能挂在 HTTP 请求里（那正是 524 的成因）。
 *
 * ⚠️ 本脚本不读 HTTP 上下文：属主/账号/动作全部来自任务行本身（提交时已落库），
 *    避免后台进程误用「当前请求的 Tenant」。
 */

declare(strict_types=1);

define('AIGW_START', microtime(true));
define('AIGW_ROOT', dirname(__DIR__));
define('AIGW_VERSION', '1.0.0');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// ★ 2026-10-02 三修：fatal error 必须落到 **action-worker.log**（与启动行同一文件）。
//   原来写 storage/logs/php-error.log → 用户按提示去看 action-worker.log 时**什么都看不到**
//   （提示与实际落点不一致 = 又一例「静默失败」）。致命错误与启动行落一处才好排查。
ini_set('error_log', AIGW_ROOT . '/storage/logs/action-worker.log');

require AIGW_ROOT . '/bootstrap/autoload.php';

use App\Core\Application;
use App\Repositories\AccountRepository;
use App\Repositories\ActionTaskRepository;
use App\Provider\ProviderManager;
use App\Support\Logger;
use App\Support\Tenant;

$taskId = trim((string) ($argv[1] ?? ''));
if ($taskId === '' || !preg_match('/^[a-f0-9]{32}$/', $taskId)) {
    fwrite(STDERR, "[action-worker] 用法：php bin/action-worker.php <task_id>\n");
    exit(2);
}

/** 去掉可能带上的 --detach 等标志 */
$isDetach = in_array('--detach', $argv ?? [], true);

try {
    $app = new \App\Core\Application(AIGW_ROOT);
    $c   = $app->container();

    /** @var ActionTaskRepository $tasks */
    $tasks = $c->make(ActionTaskRepository::class);
    /** @var AccountRepository $accounts */
    $accounts = $c->make(AccountRepository::class);
    /** @var ProviderManager $providers */
    $providers = $c->make(ProviderManager::class);

    $task = $tasks->findByTaskId($taskId);
    if ($task === null) {
        fwrite(STDERR, "[action-worker] 任务不存在：{$taskId}\n");
        exit(1);
    }

    // 已被别的 worker 抢走（并发保护）→ 直接退出，不重复执行
    // ★ 2026-10-02 三修：**不再**因「状态不是 PENDING」就一律退出。
    //   原写法在有「web 端误杀」时会毁掉任务：web 的 1.2s 乐观确认超时曾把任务
    //   标成 FAILED，worker 起来时状态已非 PENDING → 这里 exit(0) → 任务永久废掉。
    //   现在：状态判定交给下边的 claimForRun()（它能区分「已被真正执行过」与「被误杀从未执行」）。
    //   这里只拦「已被**真正执行过**」的情况（worker_pid > 0）。
    if (($task['status'] ?? '') !== ActionTaskRepository::STATUS_PENDING
        && (int) ($task['worker_pid'] ?? 0) > 0) {
        fwrite(STDOUT, "[action-worker] 任务已由 pid={$task['worker_pid']} 执行（状态 {$task['status']}），跳过\n");
        exit(0);
    }

    // ⚠️ 必须拿到**非 0** 的 pid：补救路径的 CAS 条件是 `worker_pid = 0`，
    //    若本进程写进去的还是 0，等于没标记「已被执行」→ 第二个 worker 可能重复抢。
    //    posix_getpid() 在 Windows 上不存在，用 PHP 内置的 getmypid() 兜底（跨平台）。
    $pid = 0;
    if (function_exists('getmypid')) {
        $pid = (int) getmypid();
    }
    if ($pid <= 0 && function_exists('posix_getpid')) {
        $pid = (int) posix_getpid();
    }
    if ($pid <= 0) {
        // 双路都拿不到（极端情况）：用一个单调递增的时间戳低位避免为 0，
        // 保证 CAS 的「已执行」标记一定成立。
        $pid = (int) (microtime(true) * 1000) % 2000000000 + 1;
    }

    // ★ 原子抢锁：claimForRun 带 WHERE 条件做 CAS（**单条 UPDATE，原子**）。
    //   返回 0 = 已被并发 worker 真正抢走 → **必须立即退出**，否则会重复求解验证码。
    //   注意顺序：先 CAS 再执行，不能「先查后跑」（中间有 TOCTOU 窗口）。
    //   claimForRun 额外允许「从未执行过(worker_pid=0)的 PENDING/FAILED」被抢回 ——
    //   修复 web 端乐观确认超时导致的误杀。
    if ($tasks->claimForRun($taskId, $pid) <= 0) {
        fwrite(STDOUT, "[action-worker] 任务已被其他 worker 抢走，退出\n");
        exit(0);
    }

    // 后台进程没有登录会话：按任务行的属主显式设定作用域。
    // 关键：**必须**用 setRequestScope，而不是依赖会话推导 —— 否则管理员任务
    // （user_id=0）会被当成 null 作用域，普通用户任务会被当成无属主。
    Tenant::setRequestScope((int) ($task['user_id'] ?? 0));

    $accountId = (int) ($task['account_id'] ?? 0);
    $account   = $accounts->find($accountId);
    if ($account === null) {
        $tasks->finish($taskId, false, '账号不存在或已被删除', [], 'NOT_FOUND');
        exit(0);
    }

    $action = (string) ($task['action'] ?? '');
    $params = (array) ($task['params'] ?? []);

    $provider = $providers->get($account->providerKey);
    $result   = $provider->action($account, $action, $params);

    $ok      = (bool) ($result['ok'] ?? false);
    $message = (string) ($result['message'] ?? '');
    $data    = is_array($result['data'] ?? null) ? $result['data'] : [];
    $errCode = (string) ($data['error_code'] ?? '');

    $tasks->finish($taskId, $ok, $message, $data, $errCode);

    // 签到/领取类动作落库（与 HTTP 同步路径保持一致，否则「签到日志」会缺记录）
    if ($action === 'checkin' || $action === 'claim') {
        try {
            $log = $app->container()->make(\App\Repositories\CheckinLogRepository::class);
            $log->record([
                'provider_key'   => $account->providerKey,
                'account_uid'    => $account->uid,
                'nickname'       => $account->nickname,
                'status'         => $ok
                    ? (str_contains($message, '已') && str_contains($message, '领') ? 'ALREADY' : 'SUCCESS')
                    : 'FAILED',
                'message'        => $message,
                'credits_before' => $account->creditsKnown ? $account->credits : null,
                'credits_after'  => isset($result['credits']) ? (float) $result['credits'] : null,
                'trigger'        => 'manual',
            ]);
            if ($ok) {
                $accounts->markCheckedIn($account->id, now_ms());
            }
        } catch (\Throwable $e) {
            Logger::warn('[action-worker] 签到日志落库失败', ['error' => $e->getMessage()]);
        }
    }

    Logger::info('[action-worker] 任务完成', [
        'task_id' => $taskId,
        'action'  => $action,
        'ok'      => $ok,
        'cost'    => round(microtime(true) - AIGW_START, 1),
    ]);

    exit($ok ? 0 : 0); // 任务已落库，进程退出码不表示业务结果
} catch (\Throwable $e) {
    Logger::error('[action-worker] 执行异常：' . $e->getMessage(), [
        'task_id' => $taskId,
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);

    // 尽力把失败写回任务行，避免前端无限轮询
    try {
        $app = isset($app) ? $app : new \App\Core\Application(AIGW_ROOT);
        $tasks = $app->container()->make(ActionTaskRepository::class);
        $tasks->finish($taskId, false, '后台执行异常：' . $e->getMessage(), [], 'WORKER_ERROR');
    } catch (\Throwable $inner) {
        // 落库都失败就只能靠日志了
        Logger::error('[action-worker] 失败结果落库也失败', ['error' => $inner->getMessage()]);
    }

    exit(1);
}
