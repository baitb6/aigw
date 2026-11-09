<?php
/**
 * AI 聚合网关 · 统一入口
 * 所有 HTTP 请求（含前端页面与 /v1 网关接口）都从这里进入。
 */

declare(strict_types=1);

define('AIGW_START', microtime(true));
define('AIGW_ROOT', dirname(__DIR__));
define('AIGW_VERSION', '1.1.0');

// 错误处理：生产环境不把堆栈吐给客户端
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', AIGW_ROOT . '/storage/logs/php-error.log');

require AIGW_ROOT . '/bootstrap/autoload.php';

// 会话存储目录：显式固定为项目内绝对路径。
// 背景：本机 PHP 的 session.save_path 未设置，PHP 会回退到系统临时目录；
// 而 php-cgi 各 worker 解析到的临时目录不一致，导致登录写入的会话在下一次
// 请求（刷新页面）时读不到 → 反复要求重新登录。
(function (): void {
    $dir = AIGW_ROOT . '/storage/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    // 垃圾回收周期需 ≥ 会话有效期，否则未过期的会话文件可能被提前回收
    @ini_set('session.gc_maxlifetime', '604800');
})();

$app = new \App\Core\Application(AIGW_ROOT);
$app->run();