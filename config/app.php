<?php
/**
 * 应用基础配置
 */

declare(strict_types=1);

return [
    'name'  => env('AIGW_APP_NAME', 'AI 聚合网关'),
    'debug' => (bool) env('AIGW_APP_DEBUG', false),
    'key'   => env('AIGW_APP_KEY', ''),
    'url'   => env('AIGW_APP_URL', 'http://ai.com'),

    // 管理台登录会话
    'session_name' => 'aigw_session',
    'session_ttl'  => 86400 * 7,

    // 自定义管理台登录入口路径（可用于隐藏默认入口，降低被扫描爆破的概率）
    // 需以 / 开头，例如 AIGW_ADMIN_LOGIN_PATH=/api/admin/x-login
    'admin_login_path' => env('AIGW_ADMIN_LOGIN_PATH', '/api/admin/login'),

    // 允许跨域访问网关接口的额外来源（同源 app.url 始终允许）
    // 多个用逗号分隔，例如：AIGW_CORS_ORIGINS=http://localhost:5173,http://127.0.0.1:5173
    'cors_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AIGW_CORS_ORIGINS', ''))
    ))),
];