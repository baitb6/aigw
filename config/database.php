<?php
/**
 * 数据库配置（小皮面板 MySQL 8.0）
 */

declare(strict_types=1);

return [
    'host'     => env('AIGW_DB_HOST', '127.0.0.1'),
    'port'     => (int) env('AIGW_DB_PORT', 3306),
    'database' => env('AIGW_DB_DATABASE', 'ai_com'),
    'username' => env('AIGW_DB_USERNAME', 'root'),
    // 不提供默认口令，必须在 .env 中显式配置
    'password' => env('AIGW_DB_PASSWORD', ''),
    'charset'  => 'utf8mb4',
    'prefix'   => 'aigw_',
];