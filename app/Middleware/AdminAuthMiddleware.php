<?php
/**
 * 管理台鉴权中间件：基于 PHP 原生 Session
 * 会话键：aigw_admin（id/username/nickname/expires_at）
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;

class AdminAuthMiddleware
{
    /** @var array<string, mixed>|null */
    private static ?array $admin = null;

    public function handle(Request $request, callable $next): Response
    {
        $this->bootSession();

        // HTTP 环境（Session 可用）优先读真实会话；否则回退到进程内注入的登录态（CLI / 测试）
        if (session_status() === PHP_SESSION_ACTIVE) {
            $admin = $_SESSION['aigw_admin'] ?? null;
        } else {
            $admin = self::$admin;
        }

        $ttl = (int) config('app.session_ttl', 86400 * 7);

        if (!is_array($admin) || empty($admin['id'])) {
            return Response::json([
                'ok'    => false,
                'error' => ['message' => '未登录或会话已过期', 'code' => 'unauthenticated'],
            ], 401);
        }

        // 会话过期
        if (isset($admin['login_at']) && (time() - (int) $admin['login_at']) > $ttl) {
            unset($_SESSION['aigw_admin']);
            return Response::json([
                'ok'    => false,
                'error' => ['message' => '登录状态已过期，请重新登录', 'code' => 'session_expired'],
            ], 401);
        }

        // 会话纪元校验（审计 M-2 修复，对齐用户端）：改密码后旧会话必须立即失效。
        // 改密的常见动机是「怀疑凭据泄露」——不抬纪元的话，攻击者已窃取的会话
        // Cookie 在改密后仍可继续用满 7 天 TTL。
        // CLI/测试注入的登录态（fakeLogin）不带 epoch 字段，跳过校验不做误伤；
        // 取库失败时放行（宁可漏挡一次，也不要把管理员全部挡在门外）。
        if (array_key_exists('epoch', $admin)) {
            $current = self::currentEpoch((int) $admin['id']);
            if ($current !== null && $current !== (int) $admin['epoch']) {
                unset($_SESSION['aigw_admin']);
                self::$admin = null;
                return Response::json([
                    'ok'    => false,
                    'error' => ['message' => '账号凭据已变更，请重新登录', 'code' => 'credential_changed'],
                ], 401);
            }
        }

        self::$admin = $admin;

        return $next($request);
    }

    /** 当前登录管理员 */
    public static function current(): ?array
    {
        return self::$admin;
    }

    /**
     * 写入登录会话
     */
    public static function login(array $admin): void
    {
        self::bootSession();
        session_regenerate_id(true);
        $_SESSION['aigw_admin'] = [
            'id'       => (int) $admin['id'],
            'username' => (string) $admin['username'],
            'nickname' => (string) ($admin['nickname'] ?? ''),
            'login_at' => time(),
            // 会话水位：登录那一刻账号的纪元。缺列/未取到时存 0，与库内默认一致。
            'epoch'    => (int) ($admin['session_epoch'] ?? 0),
        ];
        self::$admin = $_SESSION['aigw_admin'];
    }

    /**
     * 刷新当前会话的纪元（改密后调用于**本人**会话，避免把自己也踢下线）。
     */
    public static function refreshEpoch(int $epoch): void
    {
        self::bootSession();
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['aigw_admin'])) {
            $_SESSION['aigw_admin']['epoch'] = $epoch;
        }
        if (self::$admin !== null) {
            self::$admin['epoch'] = $epoch;
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
                'SELECT `session_epoch` FROM `aigw_admin` WHERE `id` = ?',
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
            unset($_SESSION['aigw_admin']);
        }
        self::$admin = null;
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
                // HTTPS 环境下补 Secure 标志（审计 SEC-05）
                'secure'   => self::isSecureRequest(),
            ]);
            session_start();
        }
    }

    /**
     * 判定当前请求是否走 HTTPS（用于决定 Session Cookie 是否带 Secure）。
     * 兼容反向代理：识别 X-Forwarded-Proto。
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
    public static function fakeLogin(array $admin): void
    {
        self::$admin = [
            'id'       => (int) $admin['id'],
            'username' => (string) $admin['username'],
            'nickname' => (string) ($admin['nickname'] ?? ''),
            'login_at' => time(),
        ];

        if (!headers_sent() && session_status() !== PHP_SESSION_ACTIVE) {
            self::bootSession();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['aigw_admin'] = self::$admin;
        }
    }

    /**
     * 测试辅助：是否为「已通过进程内注入」的登录态
     */
    public static function isFaked(): bool
    {
        return !headers_sent() && session_status() !== PHP_SESSION_ACTIVE && self::$admin !== null;
    }
}