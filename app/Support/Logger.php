<?php
/**
 * 文件日志：按天切分，JSON 结构化
 */

declare(strict_types=1);

namespace App\Support;

class Logger
{
    private static function write(string $level, string $message, array $context = []): void
    {
        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $line = json_encode([
            'time'    => date('Y-m-d H:i:s'),
            'level'   => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        if (config('app.debug', false)) {
            self::write('DEBUG', $message, $context);
        }
    }
}