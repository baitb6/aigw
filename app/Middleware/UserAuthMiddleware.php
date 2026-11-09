<?php
/**
 * 普通用户鉴权中间件：基于 PHP 原生 Session
 * 会话键：aigw_user（id/username/nickname/login_at），与管理端 aigw_admin 相互独立
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;

class UserAuthMiddleware
{
    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    public function handle(Request $request, callable $next): Response
    {
        $this->bootSession();

        // HTTP 环境（Session 可用）优先读真实会话；否则回退到进程内注入的登录态（CLI / 测试）
        if (session_status() === PHP_SESSION_ACTIVE) {
            $user = $_SESSION['aigw_user'] ?? null;
        } else {
            $user = self::$user;
        }

        $ttl = (int) config('app.session_ttl', 86400 * 7);

        if (!is_array($user) || empty($user['id'])) {
            return Response::json([
                'ok'    => false,
                'error' => ['message' => '未登录或会话已过期', 'code' => 'unauthenticated'],
            ], 401);
        }

        if (isset($user['login_at']) && (time() - (int) $user['login_at']) > $ttl) {
            unset($_SESSION['aigw_user']);
            return Response::json([
                'ok'    => false,
                'error' => ['message' => '登录状态已过期，请重新登录', 'code' => 'session_expired'],
            ], 401);
        }

        // 会话纪元校验：改密码后旧会话必须立即失效。
        //
        // 为什么不能只靠「清自己的 session」：同账号可能同时开在别的浏览器 / 设备，
        // 那些会话的 cookie 仍然有效，改密本身拦不住它们 —— 而改密的常见动机
        // 恰恰是「怀疑密码泄露了」。抬纪元后，所有旧会话的下一次请求即被判定失效。
        //
        // CLI / 测试注入的登录态（fakeLogin）不带 epoch 字段，跳过校验不做误伤；
        // 取库失败时**放行**（宁可漏挡一次，也不要把所有人挡在门外）。
        if (array_key_exists('epoch', $user)) {
            $current = self::currentEpoch((int) $user['id']);
            if ($current !== null && $current !== (int) $user['epoch']) {
                unset($_SESSION['aigw_user']);
                self::$user = null;
                return Response::json([
                    'ok'    => false,
                    'error' => ['message' => '账号凭据已变更，请重新登录', 'code' => 'credential_changed'],
                ], 401);
            }
        }

        self::$user = $user;

        return $next($request);
    }

    /** 当前登录用户 */
    public static function current(): ?array
    {
        return self::$user;
    }

    /**
     * 写入登录会话
     *
     * @param array $user 用户记录；带 `session_epoch` 时一并写入（改密后据此踢掉旧会话）
     */
    public static function login(array $user): void
    {
        self::bootSession();
        // CLI / 已输出响应体时 Session 无法启动，此时跳过 ID 重建（否则 PHP 会告警）
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['aigw_user'] = [
            'id'       => (int) $user['id'],
            'username' => (string) $user['username'],
            'nickname' => (string) ($user['nickname'] ?? ''),
            'login_at' => time(),
            // 会话水位：登录那一刻账号的纪元。缺列/未取到（老库刚升级、CLI 注入）时
            // 存 0 —— 与库内默认值一致，不会引发误踢。
            'epoch'    => (int) ($user['session_epoch'] ?? 0),
        ];
        self::$user = $_SESSION['aigw_user'];
    }

    /**
     * 刷新当前会话的纪元（改密后调用于**本人**会话，避免把自己也踢下线）。
     * 会话未激活（CLI）时静默返回。
     */
    public static function refreshEpoch(int $epoch): void
    {
        self::bootSession();
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['aigw_user'])) {
            $_SESSION['aigw_user']['epoch'] = $epoch;
        }
        if (self::$user !== null) {
            self::$user['epoch'] = $epoch;
        }
    }

    /**
     * 取账号当前会话纪元；查询失败返回 null（调用方据此放行，不做误伤）
     */
    private static function currentEpoch(int $id): ?int
    {
        if ($id <= 0) {
            return null;
        }
        try {
            return (int) \App\Core\Database::instance()->scalar(
                'SELECT `session_epoch` FROM `aigw_user` WHERE `id` = ?',
                [$id]
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function logout(): void
    {
        self::bootSession();
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['aigw_user']);
        }
        self::$user = null;
    }

    public static function bootSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // 已在其他时机输出过响应体时（如 CLI 冒烟测试/流式场景）不再启动 Session
        if (headers_sent()) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            self::configureStorage();
            session_name((string) config('app.session_name', 'aigw_session'));
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                // HTTPS 环境下补 Secure 标志（审计 SEC-05）：避免会话 Cookie
                // 在明文 HTTP 请求中被携带而遭网络嗅探窃取。
                'secure'   => self::isSecureRequest(),
            ]);
            session_start();
        }
    }

    /**
     * 判定当前请求是否走 HTTPS（用于决定 Session Cookie 是否带 Secure）。
     * 兼容反向代理：识别 X-Forwarded-Proto（当站点部署在 nginx/负载均衡之后）。
     */
    private static function isSecureRequest(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($proto !== '' && strtolower(trim(explode(',', (string) $proto)[0])) === 'https') {
            return true;
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /**
     * 固定会话存储目录：避免 PHP 回退到各 worker 不一致的系统临时目录，
     * 从而导致登录态在刷新后丢失。
     */
    private static function configureStorage(): void
    {
        $dir = AIGW_ROOT . '/storage/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        if ((int) ini_get('session.gc_maxlifetime') < 604800) {
            @ini_set('session.gc_maxlifetime', '604800');
        }
    }

    /**
     * 测试辅助：直接注入登录态（不依赖 HTTP Session）
     */
    public static function fakeLogin(array $user): void
    {
        self::$user = [
            'id'       => (int) $user['id'],
            'username' => (string) $user['username'],
            'nickname' => (string) ($user['nickname'] ?? ''),
            'login_at' => time(),
        ];
    }

    public static function isFaked(): bool
    {
        return !headers_sent() && session_status() !== PHP_SESSION_ACTIVE && self::$user !== null;
    }
}
