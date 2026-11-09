<?php
/**
 * 审计埋点门面：一行记录一条「谁在何时对什么做了什么」。
 *
 * 用法（在控制器里）：
 *   Audit::ok('persona.create', ['summary' => '新建思维「客服助手」', 'target_type' => 'persona', 'target_id' => $id]);
 *   Audit::fail('login.fail', 401, ['summary' => '登录失败：邮箱或密码错误']);
 *
 * 自动采集：操作者身份（管理员/普通用户/系统）、真实 IP、User-Agent、HTTP 方法、路径。
 * 静默失败：审计写入的任何异常都被吞掉 —— 监控不能反过来拖垮主流程。
 */

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;
use App\Core\Http\Request;
use App\Middleware\AdminAuthMiddleware;
use App\Middleware\UserAuthMiddleware;
use App\Repositories\AuditRepository;

final class Audit
{
    private static ?AuditRepository $repo = null;

    /** 全局开关：测试或高吞吐场景可临时关闭 */
    private static bool $enabled = true;

    /** 单请求内已采集的公共上下文 */
    private static ?array $runtime = null;

    /** action 前缀 → 分类 */
    private const CATEGORY_MAP = [
        'login'    => 'auth',
        'logout'   => 'auth',
        'register' => 'auth',
        'password' => 'auth',
        'profile'  => 'auth',
        'apikey'   => 'auth',
        'persona'  => 'persona',
        'template' => 'template',
        'account'  => 'account',
        'user'     => 'admin',
        'audit'    => 'audit',
        'settings' => 'gateway',
        'proxy'    => 'gateway',
        'gateway'  => 'gateway',
        'model'    => 'model',
        'provider' => 'provider',
    ];

    public static function enable(bool $on = true): void
    {
        self::$enabled = $on;
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    /** 成功事件 */
    public static function ok(string $action, array $ctx = []): void
    {
        self::log($action, $ctx, 200);
    }

    /** 失败事件 */
    public static function fail(string $action, int $status = 400, array $ctx = []): void
    {
        self::log($action, $ctx, $status);
    }

    /**
     * 记录一条审计事件。
     *
     * @param string $action 事件键（如 login.success / persona.create）
     * @param array  $ctx    可选：user_id / actor_type / actor_id / actor_name / category /
     *                       target_type / target_id / summary / detail / ip / ua / method / path
     * @param int    $status 结果状态码
     */
    public static function log(string $action, array $ctx = [], int $status = 200): void
    {
        if (!self::$enabled) {
            return;
        }

        try {
            $runtime = self::runtime();

            // 操作者身份推导：显式指定优先，否则按会话判断
            $actorType = (string) ($ctx['actor_type'] ?? '');
            $actorId = (int) ($ctx['actor_id'] ?? 0);
            $actorName = (string) ($ctx['actor_name'] ?? '');

            if ($actorType === '') {
                if (Tenant::isAdmin()) {
                    $admin = AdminAuthMiddleware::current() ?? [];
                    $actorType = 'admin';
                    $actorId = $actorId > 0 ? $actorId : (int) ($admin['id'] ?? 0);
                    $actorName = $actorName !== '' ? $actorName : (string) ($admin['username'] ?? '');
                } elseif (($uid = Tenant::userId()) !== null) {
                    $user = UserAuthMiddleware::current() ?? [];
                    $actorType = 'user';
                    $actorId = $actorId > 0 ? $actorId : $uid;
                    $actorName = $actorName !== '' ? $actorName
                        : (string) ($user['nickname'] ?? $user['username'] ?? '');
                } else {
                    $actorType = 'system';
                }
            }

            // 归属用户：显式优先；普通用户默认记到自己名下；管理员默认 0
            $userId = array_key_exists('user_id', $ctx)
                ? (int) $ctx['user_id']
                : ($actorType === 'user' ? $actorId : 0);

            self::repo()->record([
                'user_id'     => $userId,
                'actor_type'  => $actorType,
                'actor_id'    => $actorId,
                'actor_name'  => $actorName,
                'action'      => $action,
                'category'    => (string) ($ctx['category'] ?? self::categoryOf($action)),
                'target_type' => (string) ($ctx['target_type'] ?? ''),
                'target_id'   => (string) ($ctx['target_id'] ?? ''),
                'summary'     => (string) ($ctx['summary'] ?? $action),
                'detail'      => $ctx['detail'] ?? null,
                'ip'          => (string) ($ctx['ip'] ?? $runtime['ip']),
                'ua'          => (string) ($ctx['ua'] ?? $runtime['ua']),
                'method'      => (string) ($ctx['method'] ?? $runtime['method']),
                'path'        => (string) ($ctx['path'] ?? $runtime['path']),
                'status'      => (int) ($ctx['status'] ?? $status),
            ]);
        } catch (\Throwable $e) {
            Logger::warn('审计写入失败', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** action 前缀 → 分类 */
    public static function categoryOf(string $action): string
    {
        $prefix = explode('.', $action, 2)[0];
        return self::CATEGORY_MAP[$prefix] ?? 'other';
    }

    /** 复位进程内状态（测试用） */
    public static function reset(): void
    {
        self::$runtime = null;
    }

    private static function repo(): AuditRepository
    {
        return self::$repo ??= new AuditRepository(Database::instance());
    }

    /** 单请求内只解析一次公共上下文 */
    private static function runtime(): array
    {
        if (self::$runtime !== null) {
            return self::$runtime;
        }

        $ip = '';
        try {
            $ip = ClientIp::resolve(new Request());
        } catch (\Throwable $e) {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        }

        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (mb_strlen($ua) > 255) {
            $ua = mb_substr($ua, 0, 255);
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }

        return self::$runtime = [
            'ip'     => $ip,
            'ua'     => $ua,
            'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
            'path'   => $uri,
        ];
    }
}
