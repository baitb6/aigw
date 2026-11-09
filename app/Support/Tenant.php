<?php
/**
 * 多租户上下文：解析当前请求的数据属主作用域。
 *
 * 作用域约定：
 *  - null  → 不限制（管理员控制台，可见全部数据）
 *  - int   → 仅该 user_id 的数据（普通用户控制台 / 网关按 API Key 归属）
 *
 * 控制台作用域由登录会话推导；网关调用作用域由 GatewayAuthMiddleware
 * 解析 API Key 后通过 setRequestScope() 显式写入。
 */

declare(strict_types=1);

namespace App\Support;

use App\Middleware\AdminAuthMiddleware;
use App\Middleware\UserAuthMiddleware;

final class Tenant
{
    /** 网关请求解析出的归属 user_id（0=全局密钥） */
    private static ?int $requestUserId = null;

    /** 是否已由网关鉴权显式设置作用域（优先于会话推导） */
    private static bool $requestScoped = false;

    /** 是否为管理员会话 */
    public static function isAdmin(): bool
    {
        return AdminAuthMiddleware::current() !== null;
    }

    /** 普通用户 id（未登录普通用户时为 null） */
    public static function userId(): ?int
    {
        $user = UserAuthMiddleware::current();
        return ($user !== null && !empty($user['id'])) ? (int) $user['id'] : null;
    }

    /**
     * 当前数据作用域：
     *  - null → 不限制（管理员）
     *  - int  → 仅该 user_id
     */
    public static function scope(): ?int
    {
        if (self::$requestScoped) {
            return self::$requestUserId;
        }
        if (self::isAdmin()) {
            return null;
        }
        return self::userId();
    }

    /** 新建数据时写入的 user_id（管理员/全局 = 0） */
    public static function writeId(): int
    {
        return self::scope() ?? 0;
    }

    /** 由网关鉴权中间件写入按 API Key 解析出的归属用户（0=全局密钥） */
    public static function setRequestScope(?int $userId): void
    {
        self::$requestScoped = true;
        self::$requestUserId = $userId;
    }

    /** 复位进程内状态（测试用，避免用例间串扰） */
    public static function reset(): void
    {
        self::$requestScoped = false;
        self::$requestUserId = null;
    }
}
