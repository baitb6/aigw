<?php
/**
 * 注册邮箱域名白名单
 * ------------------------------------------------------------
 * 为什么需要：站点开放注册、且注册**必须填真实邮箱**（要么直接当主身份，要么收验证码）。
 * 开放注册的站点最容易被「一次性邮箱 / 批量小号域名」灌 —— 每个域名一次注册，
 * 邮箱唯一索引形同虚设，人机闸门也只挡机器不挡人。把可注册域名收敛到少数
 * 可信域（默认只放 QQ 邮箱），是成本最低、最不误伤的一道口子。
 *
 * 🔴 单一真源：白名单只在这里解析。控制器只调用 allows()/message()，
 *    不得各自写一套 explode/正则 —— 否则一处改了后缀归一化、另一处没改，
 *    就会出现「发码放行、注册拦截」或反过来的静默漂移（本项目的高频事故形态）。
 *
 * 配置值语义（settings.register_email_domains）：
 *   · 空串 / 空数组 → **不限制**（任何合法邮箱都可注册）。
 *     这是刻意的默认：升级老库不会突然把注册锁死；且管理员清空输入框
 *     即可一键恢复「不限域名」，不用改代码。
 *   · 非空 → 只允许列表内的域名（含子域）。
 *
 * 域名匹配规则：
 *   · 大小写不敏感（整体 strtolower 后比较）
 *   · 前导 `@` / `.` 会被剥掉（管理员写 `@qq.com` 或 `.qq.com` 都认）
 *   · 允许子域：配置 `qq.com` 时 `vip.qq.com` 也放行
 *     （用 `$domain === $rule || str_ends_with($domain, '.' . $rule)`，
 *      而不是脆弱的 `str_contains` —— 后者会把 `notqq.com` 判成命中 `qq.com`）
 *   · 国际化域名（中文邮箱）未做 punycode 转换：QQ/主流邮箱都是 ASCII，
 *     真要用中文域就在配置里直接写中文域，此处按 UTF-8 原样比较。
 */

declare(strict_types=1);

namespace App\Support;

final class MailDomain
{
    /**
     * 出厂默认白名单：只允许 QQ 邮箱。
     *
     * foxmail.com 是腾讯与 QQ 邮箱同源的别名域（同一账号体系），一并放行；
     * 若要「严格只许 qq.com」，在后台设置里把 foxmail.com 删掉即可。
     */
    public const DEFAULT_RULES = 'qq.com,foxmail.com';

    /** 配置值最大长度（与列宽 VARCHAR(255) 对齐，留余量给归一化后的串） */
    public const MAX_CONFIG_LEN = 255;

    /**
     * 把任意形态的配置值归一化成「小写、去重、逗号分隔」的域名串。
     * 认数组、认中英文逗号/分号/空白分隔，方便后台传参。
     */
    public static function normalize(mixed $raw): string
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $s = (string) $raw;
            $parts = preg_split('/[,;，；\s]+/u', $s) ?: [];
        }

        $out = [];
        foreach ($parts as $part) {
            $rule = self::normalizeRule((string) $part);
            if ($rule !== '') {
                $out[$rule] = true; // 用键去重
            }
        }

        $joined = implode(',', array_keys($out));

        // 极端情况下管理员粘贴了一长串，截断到列宽以内（按最后一个逗号切，不切碎域名）
        if (strlen($joined) > self::MAX_CONFIG_LEN) {
            $joined = substr($joined, 0, self::MAX_CONFIG_LEN);
            $cut = strrpos($joined, ',');
            $joined = $cut === false ? '' : substr($joined, 0, $cut);
        }

        return $joined;
    }

    /**
     * 解析出规则数组（已归一化）。空数组 = 不限制。
     *
     * @return string[]
     */
    public static function rules(mixed $raw): array
    {
        $normalized = self::normalize($raw);
        if ($normalized === '') {
            return [];
        }
        return explode(',', $normalized);
    }

    /** 是否配置了限制（false = 任意合法邮箱都放行） */
    public static function restricted(mixed $raw): bool
    {
        return self::rules($raw) !== [];
    }

    /**
     * 邮箱是否在白名单内。
     *
     * @param string $email 已经过 trim 的邮箱（内部仍会再规整一次）
     * @param mixed  $raw   配置值（settings.register_email_domains）
     */
    public static function allows(string $email, mixed $raw): bool
    {
        $rules = self::rules($raw);
        if ($rules === []) {
            return true; // 未配置 = 不限制
        }

        $domain = self::domainOf($email);
        if ($domain === '') {
            return false;
        }

        foreach ($rules as $rule) {
            if ($domain === $rule || str_ends_with($domain, '.' . $rule)) {
                return true;
            }
        }

        return false;
    }

    /** 取邮箱的域名部分（小写、去空白）；无 `@` 返回空串 */
    public static function domainOf(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return '';
        }
        return trim(substr($email, $at + 1));
    }

    /**
     * 给用户看的拒绝提示。
     *
     * 只在用户**确实可能不知道规则**时才带出白名单；域名很多（>3）时只报数量，
     * 避免提示语长到把输入框挤走，也避免把整份策略明晃晃地摊给扫描器。
     */
    public static function message(mixed $raw): string
    {
        $rules = self::rules($raw);
        if ($rules === []) {
            return '请输入正确的邮箱地址';
        }
        if (count($rules) <= 3) {
            $shown = implode(' / ', array_map(static fn(string $r): string => '@' . $r, $rules));
            return '仅支持 ' . $shown . ' 邮箱注册';
        }
        return '仅支持指定的 ' . count($rules) . ' 个邮箱域名注册';
    }

    /** 归一化单条规则：小写、剥掉前导 @/.、剥掉路径与空白 */
    private static function normalizeRule(string $rule): string
    {
        $rule = strtolower(trim($rule));
        $rule = ltrim($rule, '@.');
        $rule = trim($rule, '.');

        if ($rule === '') {
            return '';
        }

        // 只接受「标签.标签」形态的域名，挡掉误粘贴 URL / 邮箱 / 乱七八糟的输入
        if (preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $rule) !== 1) {
            return '';
        }
        if (strlen($rule) > 190) {
            return '';
        }

        return $rule;
    }
}
