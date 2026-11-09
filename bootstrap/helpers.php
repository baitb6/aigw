<?php
/**
 * 全局辅助函数
 */

declare(strict_types=1);

if (!function_exists('env')) {
    /**
     * 读取环境变量（带默认值），兼容 "true"/"false"/"null"
     */
    function env(string $key, $default = null)
    {
        $value = env_raw($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            default            => $value,
        };
    }
}

if (!function_exists('env_raw')) {
    /**
     * 读取环境变量原始字符串（不做类型转换）。未设置返回 null。
     *
     * ⚠️ **必须三路查找，不能只用 `getenv()`**：
     *   `Config::loadEnvFile()` 只把 `.env` 灌进 `$_ENV`，**从不调用 putenv()**，
     *   所以 `.env` 里写的变量用裸 `getenv()` **永远读不到**。
     *   而 PHP-FPM 池配置里的 `env[XXX]` 才进 `getenv()`。
     *   两条配置路径都得认，否则会出现「.env 配了却不生效」的静默故障。
     *
     * 这个坑在本项目已踩过三次（AIGW_BROWSER_BIN / AIGW_NODE_BIN /
     * AIGW_ALLOW_PRIVATE_UPSTREAM），所以统一收敛到这里，新代码一律用它。
     */
    function env_raw(string $key, $default = null): ?string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v === false || $v === null || $v === '') {
            return $default === null ? null : (string) $default;
        }
        return is_string($v) ? trim($v) : (string) $v;
    }
}

if (!function_exists('config')) {
    function config(string $key, $default = null)
    {
        return \App\Core\Config::get($key, $default);
    }
}

if (!function_exists('now_ms')) {
    /** 当前毫秒时间戳 */
    function now_ms(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}

if (!function_exists('uuid')) {
    function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('short_id')) {
    /** 生成短随机标识，用于 chatcmpl-xxxx */
    function short_id(int $len = 24): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return AIGW_ROOT . '/storage' . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('mask_secret')) {
    /** 敏感信息脱敏展示 */
    function mask_secret(?string $value, int $head = 4, int $tail = 4): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $len = strlen($value);
        if ($len <= $head + $tail) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, $head) . str_repeat('*', 8) . substr($value, -$tail);
    }
}

if (!function_exists('admin_login_path')) {
    /**
     * 规范化后的管理台登录入口路径
     * 通过 AIGW_ADMIN_LOGIN_PATH 自定义，用于隐藏默认入口、降低被扫描爆破概率。
     */
    function admin_login_path(): string
    {
        $path = (string) config('app.admin_login_path', '/api/admin/login');
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/api/admin/login' : $path;
    }
}

if (!function_exists('arr_get')) {
    /** 支持 "a.b.c" 点号路径取值 */
    function arr_get(array $array, string $key, $default = null)
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }
        return $array;
    }
}