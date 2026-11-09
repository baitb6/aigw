<?php
/**
 * 客户端真实 IP 解析
 * ------------------------------------------------------------
 * 两个分支，按优先级：
 *
 *   ① 回源令牌分支（CDN 场景，**零 IP 段依赖**）
 *      前置条件：`.env` 配了 `AIGW_ORIGIN_TOKEN`，且 CDN 控制台给**回源请求**
 *      加上了同值的自定义头（默认 `x-aigw-origin-token`）。
 *      命中口令时才采信 `AIGW_CLIENT_IP_HEADER`（EO 为 `EO-Connecting-IP`）。
 *
 *   ② 可信代理分支（直连 / 本机 nginx 反代）
 *      仅当直连来源属于可信代理（本机 / config('gateway.trusted_proxies')）
 *      时才采信 X-Forwarded-For，否则客户端可随意伪造来源 IP 污染调用日志 /
 *      绕过 IP 限流。
 *
 * 🔴 血案一（2026-10-02）：旧实现取 `explode(',', $xff)[0]` —— **取最左边那一项**。
 *    X-Forwarded-For 是**追加**语义：每经过一跳代理就在**尾部**追加一个地址。
 *    于是 `XFF: <攻击者伪造>, <真实IP>` 中，最左边恰恰是攻击者自己写的那一项。
 *    只要站点前面挂了 CDN / 反代（回源节点在 trusted_proxies 白名单里），
 *    攻击者每次请求换一个假 IP 就能：
 *      · 让按 IP 的限流（注册频控 / 取图限流 / 登录锁定 / 发码配额）全部失效；
 *      · 把审计日志里的来源 IP 写成任意值。
 *    正确做法是 **从右往左** 找第一个「非可信代理」地址 —— 与 nginx
 *    `real_ip_recursive` 的语义一致：右侧离自己更近，可信度更高。
 *
 * 🔴 血案二（2026-10-02）：走腾讯云 EdgeOne(EO) 后，访客 IP 全部塌缩成 1~2 个桶
 *    → **真实用户被误伤 429**（不是限流失效，是限流过粗）。
 *    根因：EO 回源时 `REMOTE_ADDR` 是 EO 节点 IP、XFF 最左是客户端伪造项，
 *    两处都不可信；而 EO 的回源 IP 段接口已下线（2026-07-31 停更 / 08-31 下线），
 *    `set_real_ip_from <EO 段>` 那套**拿不到段清单**，手工维护必然过期。
 *    → 改用**回源令牌**：口令是密钥，攻击者不知道，
 *      绕过 CDN 直连源站时伪造的头一律判为不可信 → 退回直连 IP。
 *      不依赖任何会过期的段清单。
 *
 * ⚠️ 部署侧配套（应用层修不完的部分）：
 *    a) 默认 `fastcgi_pass_request_headers on` 会把客户端原始 X-Forwarded-For 原样
 *       带给 PHP，故需要在 fastcgi 配置里显式
 *           fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr;
 *    b) **CDN 场景下 (a) 是错的** —— `$remote_addr` 是 CDN 节点 IP，覆写后反而把
 *       真实 IP 抹掉。CDN 场景必须改用回源令牌（见 ①），并确认 CDN 给的
 *       客户端 IP 头名与 `AIGW_CLIENT_IP_HEADER` 一致。
 *    c) nginx 侧必须同时加口令闸门 `if ($http_x_aigw_origin_token != "<口令>") { return 403; }`
 *       —— 应用层闸门保证「不采信」，nginx 闸门保证「直接连源站扫不到东西」。
 *       两层都要，缺一层都只是把攻击面挪个位置。
 */

declare(strict_types=1);

namespace App\Support;

use App\Core\Http\Request;
use App\Support\Logger;

final class ClientIp
{
    /** 解析结果的来源标记（诊断用） */
    public const SRC_ORIGIN_TOKEN = 'origin_token';
    public const SRC_XFF_PROXY    = 'xff_proxy';
    public const SRC_REMOTE       = 'remote';
    public const SRC_XFF_FALLBACK = 'xff_fallback';

    /** @var array<string, array{ip:string, source:string}> 单进程内解析缓存（上限见 CACHE_MAX） */
    private static array $cache = [];

    private const CACHE_MAX = 16;

    /**
     * 头名必须是小写 token（`[a-z0-9][a-z0-9-]*`）。
     *
     * 🔴 为什么必须校验：`.env` 若**末尾没有换行**，追加配置会把两行粘成一行，
     *    于是 `AIGW_CLIENT_IP_HEADER` 的值变成
     *    `eo-connecting-ipaigw_origin_token=xxxx` 这种垃圾；
     *    代码里 `$request->header(垃圾名)` 永远取不到值 → **静默退回**
     *    （表现为「令牌配了却始终不生效、全站共用一个限流桶」），
     *    而**没有任何一处会报错**。这个坑在本地实测中真实触发过。
     *    故此处显式拒绝非法头名并留一条 warn 日志，把静默变可见。
     */
    private static function headerName(string $name, string $fallback): string
    {
        static $warned = false;
        $n = strtolower(trim($name));
        if ($n !== '' && preg_match('/^[a-z0-9][a-z0-9-]*$/', $n) === 1) {
            return $n;
        }
        if ($n !== '' && !$warned) {
            $warned = true;
            Logger::warn('客户端 IP / 令牌头名不合法，已回退默认值（常见原因：.env 末尾缺换行导致两行粘连）', [
                'given'    => mb_substr($name, 0, 80),
                'fallback' => $fallback,
            ]);
        }
        return $fallback;
    }

    public static function resolve(Request $request): string
    {
        return self::resolveWithSource($request)['ip'];
    }

    /**
     * 解析客户端 IP 并返回来源标记。
     *
     * @return array{ip:string, source:string}
     */
    public static function resolveWithSource(Request $request): array
    {
        $remote      = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $token       = (string) config('gateway.origin_token', '');
        $tokenHeader = self::headerName((string) config('gateway.origin_token_header', ''), 'x-aigw-origin-token');
        $ipHeader    = self::headerName((string) config('gateway.client_ip_header', ''), 'eo-connecting-ip');
        $tokenGiven  = trim((string) ($request->header($tokenHeader) ?? ''));
        $ipGiven     = trim((string) ($request->header($ipHeader) ?? ''));
        $forwarded   = trim((string) ($request->header('x-forwarded-for') ?? ''));

        // ⚠️ 缓存 key 必须把「配置侧的值」也带上：测试与热改配置会在同一进程内
        //    变更 origin_token，若 key 只由请求头构成，就会把上一次的结论复用过来，
        //    变成「改了配置却看不出变化」的假阴性。配置在生产中不变，代价可忽略。
        $key = $remote . "\0" . $token . "\0" . $tokenHeader . "\0" . $ipHeader
             . "\0" . $tokenGiven . "\0" . $ipGiven . "\0" . $forwarded;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $result = self::compute($remote, $token, $tokenGiven, $ipGiven, $forwarded);

        if (count(self::$cache) >= self::CACHE_MAX) {
            self::$cache = [];
        }
        self::$cache[$key] = $result;

        return $result;
    }

    /** 当前请求是否携带了**正确**的回源令牌（诊断 / 探针用） */
    public static function originTokenMatched(Request $request): bool
    {
        $token = (string) config('gateway.origin_token', '');
        if ($token === '') {
            return false;
        }
        $header = self::headerName((string) config('gateway.origin_token_header', ''), 'x-aigw-origin-token');
        return self::tokenMatches($token, (string) ($request->header($header) ?? ''));
    }

    /**
     * 可读的诊断信息（`tools/probe-client-ip.php` 用，帮助现场判断是哪一层配错了）。
     *
     * @return array<string, string|bool>
     */
    public static function explain(Request $request): array
    {
        $resolved = self::resolveWithSource($request);
        $ipHeader = self::headerName((string) config('gateway.client_ip_header', ''), 'eo-connecting-ip');
        $tokenHeader = self::headerName((string) config('gateway.origin_token_header', ''), 'x-aigw-origin-token');

        return [
            'resolved'          => $resolved['ip'],
            'source'            => $resolved['source'],
            'remote_addr'       => trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
            'remote_trusted'    => self::isTrustedProxy(trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) ? 'yes' : 'no',
            'origin_token_set'  => (string) config('gateway.origin_token', '') !== '' ? 'yes' : 'no',
            'token_header'      => $tokenHeader,
            'token_matched'     => self::originTokenMatched($request) ? 'yes' : 'no',
            'client_ip_header'  => $ipHeader,
            'client_ip_value'   => trim((string) ($request->header($ipHeader) ?? '')),
            'x_forwarded_for'   => trim((string) ($request->header('x-forwarded-for') ?? '')),
        ];
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * @return array{ip:string, source:string}
     */
    private static function compute(
        string $remote,
        string $token,
        string $tokenGiven,
        string $ipGiven,
        string $forwarded
    ): array {
        // ① 回源令牌分支 —— 优先级最高。
        //    必须排在可信代理判断**之前**：EO 回源时 REMOTE_ADDR 常是回环（同机 nginx）
        //    或 EO 节点 IP，走 ② 只会拿到节点 IP，访客全部塌缩进同一个桶。
        if ($token !== '' && self::tokenMatches($token, $tokenGiven)) {
            $candidate = self::lastValidIp($ipGiven);
            if ($candidate !== '') {
                return ['ip' => $candidate, 'source' => self::SRC_ORIGIN_TOKEN];
            }
            // 口令对但客户端 IP 头缺失/非法 → CDN 侧「客户端 IP 头」没下发或名字配错。
            // **不静默采信**，往下走 ②；此时大概率会退化成节点 IP（表现=所有人共桶 429），
            // 用 tools/probe-client-ip.php 一看便知是这一层的问题。
        }

        // ② 直连来源不是可信代理：REMOTE_ADDR 由 TCP 层确定，客户端无法影响，
        //    此时**完全忽略 XFF**（连读都不读），伪造自然无从谈起。
        if (!self::isTrustedProxy($remote)) {
            return ['ip' => $remote, 'source' => self::SRC_REMOTE];
        }

        if ($forwarded === '') {
            // 宝塔用 unix socket 时 REMOTE_ADDR 可能取到回环；无 XFF 只能用它
            return ['ip' => $remote, 'source' => self::SRC_REMOTE];
        }

        // 从右往左找第一个非可信代理地址（real_ip_recursive 语义）。
        // 跳过空项 / 非法 IP / 可信代理，取到即返回。
        $chain = array_map('trim', explode(',', $forwarded));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $candidate = $chain[$i];
            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (self::isTrustedProxy($candidate)) {
                continue; // 尚在代理链内部，继续往左找
            }
            return ['ip' => $candidate, 'source' => self::SRC_XFF_PROXY];
        }

        // 整条链都是可信代理（或全部非法）→ 退回最左一个合法地址，
        // 仍取不到就用直连地址兜底，绝不返回空串（空串会让按 IP 的限流静默失效）。
        foreach ($chain as $candidate) {
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return ['ip' => $candidate, 'source' => self::SRC_XFF_FALLBACK];
            }
        }

        return ['ip' => $remote, 'source' => self::SRC_REMOTE];
    }

    /**
     * 口令比对。
     *
     * 支持「逗号分隔多值」：CDN 对同名头可能是**追加**而不是覆盖
     * （结果是 `<客户端伪造>, <CDN 真值>`）。逐段比对并**只认全等**，
     * 因此客户端伪造段永远不会误判为命中；同时也能兼容 CDN 追加的情形，
     * 免得因为一个头名的语义差异把整站打成 403。
     */
    private static function tokenMatches(string $expected, string $given): bool
    {
        if ($expected === '' || $given === '') {
            return false;
        }
        foreach (explode(',', $given) as $segment) {
            $segment = trim($segment);
            if ($segment !== '' && hash_equals($expected, $segment)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 取「最后一个」合法 IP。
     *
     * 与 XFF 同理：CDN 若以**追加**方式写入客户端 IP 头，左边是客户端伪造项，
     * 右边才是 CDN 写的真值 → 取最右。单值头（EO 正常形态）结果不变。
     */
    private static function lastValidIp(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $parts = array_map('trim', explode(',', $value));
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if ($parts[$i] !== '' && filter_var($parts[$i], FILTER_VALIDATE_IP) !== false) {
                return $parts[$i];
            }
        }
        return '';
    }

    /** 直连 IP 是否为可信代理（本机 / 配置的白名单） */
    public static function isTrustedProxy(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        foreach ((array) config('gateway.trusted_proxies', []) as $candidate) {
            if (trim((string) $candidate) === $ip) {
                return true;
            }
        }

        return false;
    }
}
