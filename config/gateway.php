<?php
/**
 * 网关配置（对外 OpenAI 兼容层）
 */

declare(strict_types=1);

return [
    // 默认监听端口（网页版由 nginx 承载，此值仅用于展示「接入地址」）
    'port' => (int) env('AIGW_GATEWAY_PORT', 51120),

    // 对外访问基址，用于生成接入地址与回调地址
    'base_url' => env('AIGW_GATEWAY_BASE_URL', 'http://ai.com'),

    // 额度冷却时长（毫秒），对应原 App quotaCooldownMillis
    'quota_cooldown_ms' => 10 * 60 * 1000,

    // 软限流冷却时长（毫秒）
    'soft_cooldown_ms' => 60 * 1000,

    // 单账号连续错误上限，超过则进入冷却
    'max_error_count' => 3,

    // 可信反向代理 IP 白名单：仅这些直连来源的 X-Forwarded-For 会被采信
    // （127.0.0.1 / ::1 始终视为可信）。多个用逗号分隔。
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AIGW_TRUSTED_PROXIES', ''))
    ))),

    // ────────────────────────────────────────────────────────────────────────
    // 回源令牌（CDN 场景取真实客户端 IP，**零 IP 段依赖**）
    // ────────────────────────────────────────────────────────────────────────
    // 腾讯云 EdgeOne 的回源 IP 段接口已下线，拿不到段清单 → set_real_ip_from 那套不可用。
    // 改用「回源令牌」：在 CDN 控制台给回源请求加一个自定义头，
    // 值就是这里配置的随机口令。只有带着**正确口令**的请求，客户端 IP 才被采信。
    //
    // 安全性依据：口令是密钥，攻击者不知道 → 无法伪造带正确口令的请求
    //   → 绕过 CDN 直连源站时伪造的头会被判为不可信 → 退回直连 IP。
    //   这比「信任某个 IP 段」更稳：不依赖任何会过期的段清单。
    //
    // 三个值必须配套（缺一不可，详见 deploy/nginx-aigw.conf 的 EO 专章）：
    //   AIGW_ORIGIN_TOKEN        口令本身（openssl rand -hex 32 生成）
    //   AIGW_ORIGIN_TOKEN_HEADER 口令所在的头名（须与 CDN 控制台一致）
    //   AIGW_CLIENT_IP_HEADER    真实客户端 IP 所在的头名（EO 用 EO-Connecting-IP）
    'origin_token'        => trim((string) env('AIGW_ORIGIN_TOKEN', '')),
    'origin_token_header' => strtolower(trim((string) env('AIGW_ORIGIN_TOKEN_HEADER', 'x-aigw-origin-token'))),
    'client_ip_header'    => strtolower(trim((string) env('AIGW_CLIENT_IP_HEADER', 'eo-connecting-ip'))),
];