<?php
/**
 * HTTP 请求对象
 */

declare(strict_types=1);

namespace App\Core\Http;

class Request
{
    private string $method;
    private string $path;
    private array $query;
    private array $headers = [];
    private ?array $body = null;
    private ?string $rawBody = null;
    /** @var array<string, string> 路由参数 */
    private array $routeParams = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->query  = $_GET ?? [];
        $this->path   = $this->resolvePath();
        $this->headers = $this->resolveHeaders();
    }

    private function resolvePath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }
        $uri = rawurldecode($uri);
        $uri = '/' . trim($uri, '/');
        return $uri === '/' ? '/' : rtrim($uri, '/');
    }

    /**
     * 兼容 nginx/php-cgi 与 Apache 两种头传递方式
     */
    private function resolveHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = str_replace('_', '-', $key);
                $headers[strtolower($name)] = $value;
            }
        }

        if (function_exists('getallheaders')) {
            foreach (getallheaders() ?: [] as $k => $v) {
                $headers[strtolower($k)] = $v;
            }
        }

        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth === null || $auth === '') {
            return null;
        }
        if (preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    public function allQuery(): array
    {
        return $this->query;
    }

    /** @var string|null 测试注入的请求体（替代 php://input） */
    private static ?string $testBody = null;

    /**
     * 测试辅助：注入请求体。传 null 恢复正常读取。
     */
    public static function setTestBody(?string $body): void
    {
        self::$testBody = $body;
    }

    public function rawBody(): string
    {
        if (self::$testBody !== null) {
            return self::$testBody;
        }

        if ($this->rawBody === null) {
            $this->rawBody = (string) file_get_contents('php://input');
        }
        return $this->rawBody;
    }

    public function json(): array
    {
        if ($this->body === null) {
            $raw = $this->rawBody();
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            $this->body = is_array($decoded) ? $decoded : [];
        }
        return $this->body;
    }

    public function input(string $key, $default = null)
    {
        return $this->json()[$key] ?? $this->query[$key] ?? $default;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function routeParam(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function isStreamRequested(): bool
    {
        $accept = strtolower($this->header('accept', '') ?? '');
        return str_contains($accept, 'text/event-stream');
    }

    public function wantsJson(): bool
    {
        $accept = strtolower($this->header('accept', '') ?? '');
        return str_contains($accept, 'application/json')
            || str_starts_with($this->path, '/api/')
            || str_starts_with($this->path, '/v1/');
    }
}