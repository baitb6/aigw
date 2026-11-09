<?php
/**
 * 公开接口 · IP 维度频控闸门
 * ------------------------------------------------------------
 * 为什么需要它：
 *   对外开放、无需登录、且**会产生真实成本**的接口（注册、发邮箱验证码），
 *   图形验证码只管「一次挑战」，管不了「同一 IP 持续不断地来」。
 *   上线当天最常见的就是脚本换邮箱刷注册 / 刷验证码。
 *
 * 与既有防线的关系（各管一段，不重复）：
 *   - Captcha         ：答不答得对（一次性挑战，响应体零明文）
 *   - CaptchaGuard    ：取图频率 + 答错锁定（防喂 OCR / 暴力猜）
 *   - EmailCodeRepo   ：按邮箱 / IP 的 **日** 配额与冷却（粗粒度、慢生效）
 *   - PublicGuard(本类)：按 IP 的 **每秒级突发** 限流（细粒度、立即生效）
 *   日配额挡不住「10 秒内打 30 发」，本类补的正是这一段。
 *
 * 实现：复用已有的 `aigw_rate_limit` 令牌桶（RateLimiter，事务 + FOR UPDATE，
 *       跨 php-fpm 进程安全）。不新开表、不新增设置列 —— 「配额只有一份定义」，
 *       就是下面 LIMITS 这张表；要调阈值只改这一处，不会漏改别的出口。
 */

declare(strict_types=1);

namespace App\Support;

use App\Services\RateLimiter;
final class PublicGuard
{
    /**
     * 各动作的 IP 维度配额：rpm = 每分钟补充令牌数，burst = 桶额外容量。
     * 桶总容量 = rpm + burst，即「瞬间最多连发这么多次，之后按 rpm/分钟 恢复」。
     *
     * 阈值取向：正常用户绝对够用（人手点不了 14 次/分钟），
     * 同时对脚本形成硬顶。宁可稍宽也不要误伤共享出口（公司/校园 NAT）。
     */
    private const LIMITS = [
        // 注册：一人一次，正常网络下几分钟一次都算多
        'register'   => ['rpm' => 6, 'burst' => 8],
        // 发邮箱验证码：还要叠加上面的 60s 冷却与邮箱/IP 日配额，这里只压突发
        'email_code' => ['rpm' => 4, 'burst' => 6],
    ];

    public function __construct(private RateLimiter $limiter) {}

    /**
     * 记一次访问并判断是否放行
     *
     * @return array{allowed:bool, retry_after:int}
     */
    public function hit(string $action, string $ip): array
    {
        $limit = self::LIMITS[$action] ?? null;

        // 未登记的动作 → 不限流
        if ($limit === null) {
            return ['allowed' => true, 'retry_after' => 0];
        }

        $ip = trim($ip);

        // 取不到 IP 时的两种处理，**绝不能直接放行**：
        //   · CLI（自检脚本 / 计划任务）没有 HTTP 来源，限流无意义 → 放行；
        //   · Web 环境取不到 IP（如 nginx 未下发 REMOTE_ADDR）→ 说明部署配错了，
        //     此时若放行，等于**所有按 IP 的限流静默失效**，而界面上看不出任何异常 ——
        //     典型的静默漂移。故退化为「共享桶」：所有匿名来源共用一个桶，
        //     限流变粗但依然生效，且限流触发即代表部署确有问题，能被发现。
        if ($ip === '') {
            if (PHP_SAPI === 'cli') {
                return ['allowed' => true, 'retry_after' => 0];
            }
            Logger::warn('公开接口限流取不到客户端 IP，退化为共享桶', ['action' => $action]);
            $ip = '__unknown__';
        }

        $result = $this->limiter->hit(
            'pub:' . $action . ':' . strtolower($ip),
            $limit['rpm'],
            $limit['burst']
        );

        return [
            'allowed'     => (bool) $result['allowed'],
            'retry_after' => (int) $result['retry_after'],
        ];
    }

    /** 供测试与文档引用：某动作的配额定义 */
    public static function limits(string $action): ?array
    {
        return self::LIMITS[$action] ?? null;
    }
}
