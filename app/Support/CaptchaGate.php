<?php
/**
 * 图形验证码 · 统一的「校验 + 防爆破」闸门
 * ------------------------------------------------------------
 * 为什么收口到一个类：验证码校验散落在登录 / 注册 / 发邮箱验证码 / 管理端登录
 * 四处，每处各写一遍就会出现「有的地方加了限流、有的忘了」的静默漂移。
 * 这里把「查锁定 → 校验 → 记失败/清计数」三步统一，调用方只有一行。
 */

declare(strict_types=1);

namespace App\Support;

use App\Core\Http\Request;

class CaptchaGate
{
    public function __construct(private CaptchaGuard $guard) {}

    /**
     * 校验并附带防爆破。返回 null 表示通过；否则返回给前端的中文错误。
     */
    public function check(Request $request): ?string
    {
        $ip = ClientIp::resolve($request);
        $sessionId = self::sessionId();

        $locked = $this->guard->verifyLockedSeconds($ip, $sessionId);
        if ($locked > 0) {
            return '操作过于频繁，请 ' . $locked . ' 秒后再试';
        }

        if (!Captcha::verify($request->input('captcha'))) {
            $this->guard->recordFailure($ip, $sessionId);
            return '验证码错误或已过期，请点击图片刷新后重试';
        }

        $this->guard->clear($ip, $sessionId);
        return null;
    }

    private static function sessionId(): string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return (string) session_id();
        }
        return '';
    }
}
