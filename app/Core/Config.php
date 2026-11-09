<?php
/**
 * 配置容器：加载 config/*.php，支持 "file.key.sub" 点号读取
 */

declare(strict_types=1);

namespace App\Core;

class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    public static function load(string $configPath): void
    {
        if (self::$loaded) {
            return;
        }

        // .env 优先注入
        self::loadEnvFile(AIGW_ROOT . '/.env');

        foreach (glob($configPath . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            /** @var array<string, mixed> $data */
            $data = require $file;
            self::$items[$name] = $data;
        }

        self::$loaded = true;
    }

    private static function loadEnvFile(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($key === '' || array_key_exists($key, $_ENV)) {
                continue;
            }
            $_ENV[$key] = $value;
        }
    }

    public static function get(string $key, $default = null)
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);

        if (!isset(self::$items[$file])) {
            return $default;
        }

        $value = self::$items[$file];
        foreach ($parts as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function all(string $file): array
    {
        return self::$items[$file] ?? [];
    }

    public static function set(string $key, $value): void
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);

        if (empty($parts)) {
            self::$items[$file] = $value;
            return;
        }

        $ref = &self::$items[$file];
        foreach ($parts as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }
}