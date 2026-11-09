<?php
/**
 * 出站 URL 安全守卫（SSRF 防护）
 * ============================================================================
 * 用途：对**用户可控**的出站地址（目前唯一来源是「自定义供应商」的 base_url）
 * 做统一校验，阻断服务端请求伪造（SSRF / CWE-918）。
 *
 * 三条防线（缺一不可，详见审计报告 SEC-01）：
 *   1. 协议白名单：仅允许 http / https —— 拒绝 file://、gopher://、dict://、
 *      ftp:// 等可被用于读本地文件或构造协议攻击的 scheme；
 *   2. 地址黑名单：解析 host 得到的 IP 若落在回环/私网/链路本地/保留段，一律拒绝
 *      （127.0.0.0/8、10/8、172.16/12、192.168/16、169.254/16、::1、fc00::/7 等）；
 *   3. 连接锁定（防 DNS Rebinding）：校验通过后把 host 钉死到已校验的 IP，
 *      使「校验用的解析结果」与「curl 实际连接的目标」一致，堵死两步之间的重解析窗口。
 *
 * ⚠️ 本类只做**判定**，不发起请求；具体落到 curl 选项由 HttpClient 负责。
 */

declare(strict_types=1);

namespace App\Support;

class UrlGuard
{
    /** 允许的出站协议 */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /** 默认出站端口（未显式指定时用于锁定） */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * 是否允许访问私网/保留地址。
     * 默认 false —— 需要访问内网自建上游的部署，可通过环境变量
     * AIGW_ALLOW_PRIVATE_UPSTREAM=1 显式放行（自担风险，界面上应给出提示）。
     */
    public static function allowPrivate(): bool
    {
        // ⚠️ 不能只用 getenv()：.env 只进 $_ENV（Config::loadEnvFile 不 putenv），
        //    用 env_raw() 三路查找才能读到 .env 里的配置。
        $v = env_raw('AIGW_ALLOW_PRIVATE_UPSTREAM');
        if ($v === null) {
            // 兼容 config（若加载过），无则回退 false
            if (function_exists('config')) {
                return (bool) config('gateway.allow_private_upstream', false);
            }
            return false;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * 校验一个出站 URL；不合法时抛 RuntimeException（消息面向用户可读）。
     *
     * @param string $url 待校验的完整 URL（含 scheme 与 host）
     * @return string 校验通过后用于「钉死连接」的 host（原始 host）
     * @throws \RuntimeException
     */
    public static function assertOutbound(string $url, bool $strict = false): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new \RuntimeException('Base URL 格式不合法，请填写完整的 http(s):// 地址');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new \RuntimeException('仅允许 http/https 协议的上游地址');
        }

        if ($strict && ($scheme !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']))) {
            throw new \RuntimeException('用户模型档案仅允许无凭据的 HTTPS 地址');
        }
        $host = trim((string) $parts['host'], '[]');

        // host 本身即 IP 字面量：直接判定
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (($strict || !self::allowPrivate()) && self::isPrivateIp($host)) {
                throw new \RuntimeException('禁止访问内网/保留地址');
            }
            return;
        }

        $ips = self::resolveHost($host);
        if ($ips === []) {
            throw new \RuntimeException('无法解析上游域名：' . $host);
        }

        foreach ($ips as $ip) {
            if (($strict || !self::allowPrivate()) && self::isPrivateIp($ip)) {
                throw new \RuntimeException('禁止访问内网/保留地址（' . $host . '）');
            }
        }
    }

    /**
     * 返回该 URL 对应的「连接锁定」条目（供 curl 的 CURLOPT_RESOLVE 使用）。
     * 形如 ["host:port:ip"]；无法锁定（如仅有 IPv6）时返回空数组。
     *
     * @return string[]
     */
    public static function resolvePins(string $url, bool $strict = false): array
    {
        if (!$strict && self::allowPrivate()) {
            return []; // 放行私网时不做锁定，保持与内网 DNS 一致
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host   = trim((string) $parts['host'], '[]');
        $port   = isset($parts['port']) ? (int) $parts['port'] : (self::DEFAULT_PORTS[$scheme] ?? 80);

        // IP 字面量无需锁定
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        // 只钉 IPv4：CURLOPT_RESOLVE 的 IPv6 写法（[addr]）在部分 curl 构建上不一致，
        // 这里保守只钉 A 记录；AAAA 已在 assertOutbound() 阶段做过私网判定。
        $ips = self::resolveHost($host);
        if ($ips === []) {
            throw new \RuntimeException('无法锁定上游域名');
        }
        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                throw new \RuntimeException('禁止访问内网/保留地址');
            }
        }
        $ip = $ips[0];
        if (str_contains($ip, ':')) {
            $ip = '[' . $ip . ']';
        }
        return [$host . ':' . $port . ':' . $ip];
    }

    /** host → IP 列表（IPv4 + IPv6，尽力而为） */
    public static function resolveHost(string $host): array
    {
        $ips = self::resolveHostV4($host);

        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    if (!empty($rec['ipv6'])) {
                        $ips[] = (string) $rec['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /** 仅 IPv4 解析 */
    private static function resolveHostV4(string $host): array
    {
        if (!function_exists('gethostbynamel')) {
            return [];
        }
        $ips = @gethostbynamel($host);
        return is_array($ips) ? array_values(array_unique($ips)) : [];
    }

    /** 判定 IP 是否落在私网/回环/链路本地/保留段 */
    public static function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true; // 无法解析的当危险处理
        }

        // no-priv（私网）+ no-res（保留）任一命中即视为不可出站
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
