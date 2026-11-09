<?php
/**
 * HTTP 响应对象，支持 JSON / SSE 流式
 */

declare(strict_types=1);

namespace App\Core\Http;

class Response
{
    /**
     * 单请求执行时间上限（秒）。
     *
     * 与 nginx `fastcgi_read_timeout`、php.ini `max_execution_time` 取同值，
     * 构成约定 14 的「超时三层对齐」：nginx ≥ PHP ≥ 业务预算（120s/45s）。
     *
     * ⚠️ 为什么要在代码里写死：php.ini 的值由 SAPI **启动时**冻结，改完必须重启
     *    PHP 才生效；在跑的 worker 会一直沿用旧值（实测踩过 300 写成却仍旧 60）。
     *    网关入口显式 `set_time_limit()` 到本常量，使这层不再被「忘了重启」静默打回 60s。
     *
     * ⚠️ Windows 下 `max_execution_time` 计的是**墙钟**（Linux 只计 CPU 时间），
     *    故流式长请求在 Windows 上确实会被这个值掐断，必须给足。
     */
    public const MAX_EXECUTION_SECONDS = 300;

    private int $status = 200;
    private array $headers = [];
    private string $content = '';
    private bool $streaming = false;

    public function __construct(string $content = '', int $status = 200, array $headers = [])
    {
        $this->content = $content;
        $this->status  = $status;
        $this->headers = $headers;
    }

    public static function json($data, int $status = 200, array $headers = []): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            array_merge([
                'Content-Type'  => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ], $headers)
        );
    }

    public static function text(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    /**
     * 标记为 SSE 流式响应，实际输出由回调完成
     */
    public static function stream(callable $writer): self
    {
        $response = new self('', 200, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-store, must-revalidate',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
        $response->streaming = true;
        $response->writer = $writer;
        return $response;
    }

    /** @var callable|null */
    private $writer = null;

    public function isStreaming(): bool
    {
        return $this->streaming;
    }

    public function writer(): ?callable
    {
        return $this->writer;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * 全部响应头（小写 key）。
     * 测试用：冒烟测试需要在**不发真响应**的前提下断言 Content-Type 等头，
     * 此前只能拿到 status / body，导致「验证码返回 SVG」这类内容类型回归测不出来。
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        $out = [];
        foreach ($this->headers as $name => $value) {
            $out[strtolower((string) $name)] = (string) $value;
        }
        return $out;
    }

    public function getHeader(string $name): ?string
    {
        return $this->getHeaders()[strtolower($name)] ?? null;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($this->streaming) {
            $this->flushStream();
            return;
        }

        echo $this->content;
    }

    /**
     * 执行流式输出：关闭所有缓冲，逐块 flush
     */
    private function flushStream(): void
    {
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        @ini_set('output_buffering', '0');
        // ⚠️ 不要设 0（无限）：那会让 PHP 这层宽于 nginx，请求最终被 nginx 在 300s
        //    单方掐断而 PHP 毫不知情 → 日志记 SUCCESS、客户端却断流（约定 14 的典型症状）。
        //    与 nginx / php.ini 取同值，保证「nginx 最宽、PHP 次之、业务最紧」。
        @set_time_limit(self::MAX_EXECUTION_SECONDS);

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        ob_implicit_flush(true);

        ($this->writer)();
    }
}