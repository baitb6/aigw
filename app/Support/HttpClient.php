<?php
/**
 * 自研 HTTP 客户端，基于 cURL
 * 支持：自定义请求头、JSON、超时、代理、SSE 增量解析（逐块回调）
 */

declare(strict_types=1);

namespace App\Support;

class HttpClient
{
    private array $headers = [];
    private ?string $rpcResponseId = null;

    public function withRpcResponseId(string $id): self
    {
        $clone = clone $this;
        $clone->rpcResponseId = $id;
        return $clone;
    }
    private ?string $proxy = null;
    private int $connectTimeout = 15;
    private int $timeout = self::STREAM_SAFE_TIMEOUT;
    private bool $verifySsl = true;

    /**
     * 是否跟随 3xx 重定向。
     *
     * 🔴 默认**关闭**（SSRF 防护，见审计 SEC-01）：跟随重定向会让攻击者先用
     * 一个合法公网地址通过校验，再由 302 把请求引向内网/跨协议，绕过事前的
     * host 黑名单（TOCTOU）。上游 API 都是固定端点、从不依赖重定向，故安全关闭。
     * 确需跟随的场景用 withRedirects() 显式开启。
     */
    private bool $followRedirects = false;

    /**
     * 出站 URL 守卫开关（协议白名单 + 内网 IP 拒绝 + DNS 锁定）。
     *
     * 默认关闭（仅对**用户可控**地址开启 —— 目前只有自定义供应商的 base_url），
     * 避免对已知常量 host 的正常上游引入额外解析开销与误杀风险。
     * 由 CustomProvider::http() 显式 withOutboundGuard() 打开。
     */
    private bool $guardOutbound = false;
    private bool $strictOutbound = false;

    /**
     * 流式请求的「低速熔断」窗口（秒）。
     *
     * ⚠️ 为什么要有（2026-10-01 血案）：`CURLOPT_TIMEOUT` 只约束**总时长**，
     * 不约束「连着但一直不吐字节」。上游 TCP 连接活着、却彻底静默（对端进程被挂起、
     * 中间设备黑洞）时，curl 会一路傻等，最终由 **PHP 的 `max_execution_time` 先到点**
     * ——而那是**不可捕获的 Fatal error**，直接杀掉整个进程：
     *   · 请求凭空消失，`aigw_call_log` 连一行记录都写不下（日志缺口）；
     *   · 用户端表现「跑了五分钟自己停了」，**没有任何错误提示**；
     *   · 排查时日志干干净净，极易误判成模型/账号问题。
     *
     * 修法：设 `CURLOPT_LOW_SPEED_LIMIT/TIME`——若在 `LOW_SPEED_TIME` 秒内
     * 平均速率低于 `LOW_SPEED_LIMIT` 字节/秒，curl 主动以 errno=28（超时）中断，
     * 抛出一个**可被 catch 的正常异常** → 走错误分类 / 换号重试 / 正常落库。
     *
     * ⚠️ 取值必须**远大于**上游推理期的真实静默时长，否则会误杀正常请求。
     *    实测（`aigw_call_log`，623 条有效样本）首字节最长 **212s**
     *    （trae/Doubao-Seed-Evolving：ft=212414ms，reasoning=3613，正常完成），
     *    故窗口取 240s —— 高于已知最长静默、又低于 PHP 的 300s 上限，
     *    保证「由 curl 先中断」，而不是被 PHP 静默猝死。
     *    ⚠️ 绝大多数上游在推理期会周期性下发 SSE 注释/ping 帧，本熔断实际
     *       只对「彻底黑洞」生效；有 ping 的慢推理不会被误伤。
     */
    private int $lowSpeedTime = 240;
    private int $lowSpeedLimit = 1;

    /**
     * 流式请求的安全总超时（秒）。
     *
     * 🔴 **本值必须严格小于 PHP 的 `max_execution_time`**（现为 300s，
     * 见 `Response::MAX_EXECUTION_SECONDS`）。理由：
     *   · curl 超时到点 → 抛**可捕获异常** → 正常走错误分类/换号/落库；
     *   · PHP 超时到点 → **不可捕获的 Fatal error** → 进程猝死、日志缺口、
     *     客户端「自己停了」且无任何提示。
     * 旧值 600s 恰好**大于** PHP 上限 300s → curl 永远等不到自己超时，
     * 每次都由 PHP 单方面杀死请求（2026-10-01 实测：`00:18:47` 发起的请求在
     * `00:23:47` 被 `Maximum execution time of 300 seconds exceeded` 终止，
     * 且该请求在 `aigw_call_log` 里**完全没有记录**）。
     * 留 20s 余量给异常分类 / 换号重试 / 落库 / 错误帧下发。
     */
    public const STREAM_SAFE_TIMEOUT = 280;

    /** 最近一次响应信息 */
    private array $lastInfo = [];

    /**
     * 进程级「上游字节活动」tick 回调（可选）。
     *
     * 用途：推理模型（Trae/deepseek-v4 / glm-5 系）在**推理阶段**只下发
     * `reasoning_content`，正文 `response` 全程为空。此时 Provider 的 onDelta
     * 一次都不会触发 → 网关无字节可转发 → 客户端白屏到正文出现才见首字节
     * （实测「首字节 = 总耗时」：22.6s/40.5s/56.9s）。
     *
     * 这个钩子在**每收到一段上游字节**时触发（无论是不是正文），调用方可据此
     * 节流地往 SSE 通道补注释帧，让客户端/nginx 知道"链路活着、正在生成"。
     *
     * ⚠️ 刻意做成静态、且**不进入任何 DTO**：Provider 有 8 个实现，
     * 逐个改 `chat()` 签名等于引入大面积回归风险；这里用旁路钩子做到零侵入。
     * 回调必须极轻（自带节流），因为它跑在 curl 的写回调里、每块字节都会经过。
     *
     * @var callable|null
     */
    private static $tick = null;

    /** 设置进程级 tick 回调；传 null 清除。返回旧值便于嵌套场景恢复。 */
    public static function onTick(?callable $tick): ?callable
    {
        $prev = self::$tick;
        self::$tick = $tick;

        return $prev;
    }

    /** 触发 tick（供内部写回调调用） */
    private static function fireTick(): void
    {
        if (self::$tick !== null) {
            (self::$tick)();
        }
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        foreach ($headers as $k => $v) {
            $clone->headers[$k] = $v;
        }
        return $clone;
    }

    public function withBearer(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * 代理配置：支持 http://user:pass@host:port
     */
    public function withProxy(?string $proxy, ?string $username = null, ?string $password = null): self
    {
        $clone = clone $this;
        if ($proxy) {
            $proxy = preg_replace('#^https?://#i', '', $proxy);
            if ($username !== null && $username !== '') {
                $clone->proxy = sprintf('http://%s:%s@%s', $username, $password ?? '', $proxy);
            } else {
                $clone->proxy = 'http://' . $proxy;
            }
        }
        return $clone;
    }

    /**
     * 设置超时。
     *
     * 🔴 **会自动钳制到 `STREAM_SAFE_TIMEOUT` 以下**，防止「curl 超时 > PHP 上限」
     * 的倒挂 —— 那种情况下 curl 永远等不到自己超时，请求会被 PHP 以
     * **不可捕获的 Fatal error** 杀死（日志缺口 + 客户端无提示，2026-10-01 血案）。
     *
     * ⚠️ 各处历史代码写的 `timeout(600, 20)` 语义是「给足时间」，但 600s 已**大于**
     *    PHP 的 300s 上限 → 等于「不设超时」。钳制后实际生效 280s，符合原意且安全。
     *    若未来 PHP 上限调整，只需同步 `Response::MAX_EXECUTION_SECONDS` 与本常量。
     */
    public function timeout(int $seconds, ?int $connect = null): self
    {
        $clone = clone $this;
        $clone->timeout = min($seconds, self::STREAM_SAFE_TIMEOUT);
        if ($connect !== null) {
            $clone->connectTimeout = $connect;
        }
        return $clone;
    }

    public function withoutSslVerify(): self
    {
        $clone = clone $this;
        $clone->verifySsl = false;
        return $clone;
    }

    /** 显式开启 3xx 跟随（默认关闭，见 $followRedirects 注释） */
    public function withRedirects(): self
    {
        $clone = clone $this;
        $clone->followRedirects = true;
        return $clone;
    }

    /**
     * 显式开启出站守卫：仅允许 http/https，拒内网/保留 IP，并把 host 钉死到
     * 已校验 IP（防 DNS Rebinding）。用于**用户可控**地址（自定义供应商 base_url）。
     */
    public function withOutboundGuard(bool $strict = false): self
    {
        $clone = clone $this;
        $clone->guardOutbound = true;
        $clone->strictOutbound = $strict;
        return $clone;
    }

    /**
     * 出站守卫的 curl 选项：协议白名单 + 私网拒绝 + DNS 锁定。
     * 返回选项数组，供 send()/stream()/streamEvents() 三条路径统一合并。
     *
     * @return array<int,mixed>
     */
    private function outboundGuardOpts(string $url): array
    {
        if (!$this->guardOutbound) {
            return [];
        }

        $opts = [];

        // 1) 协议白名单：只允许 http/https。CURLOPT_PROTOCOLS 管首跳，
        //    REDIR_PROTOCOLS 管重定向跳（即使将来有人开了 followRedirects 也安全）。
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $allowed = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $opts[CURLOPT_PROTOCOLS] = $allowed;
            if (defined('CURLOPT_REDIR_PROTOCOLS')) {
                $opts[CURLOPT_REDIR_PROTOCOLS] = $allowed;
            }
        }

        // 2) + 3) 统一走 UrlGuard：scheme / 内网判定 / DNS 锁定
        UrlGuard::assertOutbound($url, $this->strictOutbound);
        $pins = UrlGuard::resolvePins($url, $this->strictOutbound);
        if ($pins !== []) {
            if (!defined('CURLOPT_RESOLVE')) {
                throw new \RuntimeException('当前 cURL 不支持安全 DNS 锁定');
            }
            if (str_contains($pins[0], '[') && !(curl_version()['features'] & CURL_VERSION_IPV6)) {
                throw new \RuntimeException('当前 cURL 不支持 IPv6 上游');
            }
            $opts[CURLOPT_RESOLVE] = $pins;
        }
        if ($this->strictOutbound) {
            $opts[CURLOPT_FOLLOWLOCATION] = false;
            $opts[CURLOPT_PROXY] = '';
        }

        return $opts;
    }

    public function legacySse(string $url, callable $operation): array
    {
        $multi = curl_multi_init();
        $get = curl_init();
        $buffer = '';
        $bytes = 0;
        $endpoint = null;
        $messages = [];
        $failure = null;
        $deadline = microtime(true) + $this->timeout;
        $headers = [];
        foreach ($this->headers as $name => $value) $headers[] = $name . ': ' . $value;
        $guard = $this->outboundGuardOpts($url);
        $origin = parse_url($url);
        curl_setopt_array($get, $guard + [
            CURLOPT_URL => $url, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout, CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$buffer, &$bytes, &$endpoint, &$messages, &$failure, $origin, $url): int {
                $bytes += strlen($chunk);
                if ($bytes > 8 * 1024 * 1024) { $failure = 'MCP SSE 响应超出限制'; return 0; }
                $buffer .= $chunk;
                while (preg_match('/\r?\n\r?\n/', $buffer, $match, PREG_OFFSET_CAPTURE)) {
                    $pos = $match[0][1];
                    $frame = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + strlen($match[0][0]));
                    $event = 'message';
                    $data = [];
                    foreach (explode("\n", $frame) as $line) {
                        $line = rtrim($line, "\r");
                        if (str_starts_with($line, 'event:')) $event = trim(substr($line, 6));
                        if (str_starts_with($line, 'data:')) $data[] = preg_replace('/^ /', '', substr($line, 5));
                    }
                    $payload = implode("\n", $data);
                    if ($event === 'endpoint') {
                        if ($endpoint !== null) { $failure = 'MCP SSE 重复 endpoint'; return 0; }
                        $candidate = $payload;
                        if (preg_match('/[\x00-\x20\x7f\\\\]/', $candidate)) { $failure = '无效 MCP SSE endpoint'; return 0; }
                        if (!preg_match('#^https?://#i', $candidate)) {
                            if ($candidate === '' || str_starts_with($candidate, '//')) { $failure = '无效 MCP SSE endpoint'; return 0; }
                            $authority = $origin['scheme'] . '://' . $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '');
                            $candidate = $authority . (str_starts_with($candidate, '/') ? $candidate : substr($origin['path'] ?? '/', 0, strrpos($origin['path'] ?? '/', '/') + 1) . $candidate);
                        }
                        $target = parse_url($candidate);
                        if (!$target || isset($target['user']) || isset($target['pass']) || isset($target['fragment']) ||
                            strtolower($target['scheme'] ?? '') !== strtolower($origin['scheme']) ||
                            strtolower($target['host'] ?? '') !== strtolower($origin['host']) ||
                            ($target['port'] ?? ($target['scheme'] === 'https' ? 443 : 80)) !== ($origin['port'] ?? ($origin['scheme'] === 'https' ? 443 : 80))) {
                            $failure = 'MCP SSE endpoint 必须同源'; return 0;
                        }
                        $endpoint = $candidate;
                    } elseif ($event === 'message') {
                        $message = json_decode($payload, true);
                        if (is_array($message) && ($message['jsonrpc'] ?? '') === '2.0' && isset($message['id']) && is_string($message['id'])) {
                            if (count($messages) >= 1000) { $failure = 'MCP SSE 消息超出限制'; return 0; }
                            $messages[$message['id']] = $message;
                        }
                    }
                }
                return strlen($chunk);
            },
        ]);
        curl_multi_add_handle($multi, $get);
        $completed = [];
        $getClosed = false;
        $pump = function () use ($multi, $get, &$failure, &$completed, &$getClosed, $deadline): void {
            if ($failure !== null) throw new \RuntimeException($failure);
            if (microtime(true) >= $deadline) throw new \RuntimeException('MCP SSE 超时');
            do { $code = curl_multi_exec($multi, $running); } while ($code === CURLM_CALL_MULTI_PERFORM);
            if ($code !== CURLM_OK) throw new \RuntimeException('MCP SSE 传输失败');
            if ($failure !== null) throw new \RuntimeException($failure);
            $status = (int) curl_getinfo($get, CURLINFO_HTTP_CODE);
            if ($status >= 300) throw new \RuntimeException('MCP SSE 返回 HTTP ' . $status);
            while ($info = curl_multi_info_read($multi)) {
                if ($info['handle'] === $get) {
                    if ($info['result'] !== CURLE_OK) throw new \RuntimeException('MCP SSE GET 传输失败');
                    $getClosed = true;
                } else {
                    $completed[spl_object_id($info['handle'])] = $info['result'];
                }
            }
            if (curl_multi_select($multi, 0.01) === -1) usleep(1000);
        };
        try {
            while ($endpoint === null) {
                $pump();
                if ($endpoint === null && $getClosed) throw new \RuntimeException('MCP SSE 通道已关闭');
            }
            $rpc = function (array $request) use ($multi, &$endpoint, &$messages, $pump, $guard, $headers, &$completed, &$getClosed): array {
                $post = curl_init();
                $postBody = '';
                curl_setopt_array($post, $guard + [
                    CURLOPT_URL => $endpoint, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($request, JSON_UNESCAPED_UNICODE),
                    CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => $this->connectTimeout, CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_SSL_VERIFYPEER => $this->verifySsl, CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
                    CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$postBody): int {
                        $postBody .= $chunk;
                        return strlen($postBody) > 8 * 1024 * 1024 ? 0 : strlen($chunk);
                    },
                ]);
                curl_multi_add_handle($multi, $post);
                try {
                    do {
                        $pump();
                        $done = array_key_exists(spl_object_id($post), $completed);
                        if ($done && $completed[spl_object_id($post)] !== CURLE_OK) throw new \RuntimeException('MCP SSE POST 传输失败');
                        $status = (int) curl_getinfo($post, CURLINFO_HTTP_CODE);
                        if ($status >= 300) throw new \RuntimeException('MCP SSE POST 返回 HTTP ' . $status);
                        if ($done && $status >= 200 && (!isset($request['id']) || isset($messages[$request['id']]))) break;
                        if ($getClosed && isset($request['id']) && !isset($messages[$request['id']])) throw new \RuntimeException('MCP SSE 通道已关闭');
                        if (curl_errno($post)) throw new \RuntimeException('MCP SSE POST 传输失败');
                    } while (true);
                    if (!isset($request['id'])) return [];
                    $result = $messages[$request['id']];
                    unset($messages[$request['id']]);
                    return $result;
                } finally {
                    unset($completed[spl_object_id($post)]);
                    curl_multi_remove_handle($multi, $post);
                    curl_close($post);
                }
            };
            return $operation($rpc);
        } finally {
            curl_multi_remove_handle($multi, $get);
            curl_close($get);
            curl_multi_close($multi);
        }
    }

    public function lastInfo(): array
    {
        return $this->lastInfo;
    }

    /**
     * 当前生效的出站代理（未配置返回 null）。
     *
     * 为**只读**访问器：让需要走 curl_multi 并行发请求的调用方（如成长中心
     * 一次读 7 个端点）能沿用与本客户端完全一致的代理设置，不必重复解析
     * 代理配置，也不会绕过既有的排除名单。
     */
    public function proxy(): ?string
    {
        return $this->proxy;
    }

    public function get(string $url, array $query = []): HttpResponse
    {
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->send('GET', $url);
    }

    public function postJson(string $url, $body = []): HttpResponse
    {
        // 基于副本发送，避免把 Content-Type/Accept 写回实例属性污染后续请求头。
        $client = $this->withHeaders([
            'Content-Type' => 'application/json',
            'Accept'       => $this->headers['Accept'] ?? 'application/json',
        ]);

        return $client->send('POST', $url, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    public function postForm(string $url, array $fields): HttpResponse
    {
        return $this->send('POST', $url, http_build_query($fields));
    }

    public function postRaw(string $url, string $raw, array $headers = []): HttpResponse
    {
        return $this->withHeaders($headers)->send('POST', $url, $raw);
    }

    /**
     * 发 DELETE 请求
     *
     * 补于 2026-10-02：代做成长任务 `automation_1` 需要创建后清理
     * （`DELETE /v2/as/scheduler/tasks/{id}`），而此前 HttpClient 只有
     * GET/POST/POST-form/POST-raw 四种动词。
     *
     * @param array $body 可选请求体（多数 DELETE 不带体，传 null 即不带）
     */
    public function delete(string $url, ?array $body = null): HttpResponse
    {
        $client = $this->withHeaders([
            'Content-Type' => 'application/json',
            'Accept'       => $this->headers['Accept'] ?? 'application/json',
        ]);

        return $client->send('DELETE', $url, $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    private function send(string $method, string $url, ?string $body = null): HttpResponse
    {
        // 🔴 SSRF 修复（审计 SEC-REDIR）：守卫开启时**不再使用 curl 自带的
        //    FOLLOWLOCATION**——它只在首跳前做一次 host 校验，302 之后跳到哪
        //    curl 完全不管（攻击者可先给公网 URL 过审，再 302 到
        //    http://169.254.169.254/ 读云元数据）。
        //    改为：守卫 + 重定向同时开启时，由本方法**手动逐跳跟随**，每一跳
        //    都重新走 outboundGuardOpts（协议白名单 + 内网拒绝 + DNS 锁定）。
        $maxHops = ($this->guardOutbound && $this->followRedirects) ? 5 : 1;

        for ($hop = 1; ; $hop++) {
            $response = $this->sendOnce($method, $url, $body);

            if ($maxHops === 1) {
                return $response;
            }

            $status = $response->status();
            $location = $response->header('location');
            if (!in_array($status, [301, 302, 303, 307, 308], true) || $location === null || $location === '') {
                return $response;
            }
            if ($hop >= $maxHops) {
                return new HttpResponse('', 0, [], '重定向次数超限（>' . $maxHops . '）', $this->headers);
            }

            // 303 一律转 GET；301/302 对非 GET 也按惯例转 GET 并丢弃请求体
            if ($status === 303 || ($method !== 'GET' && $method !== 'HEAD' && in_array($status, [301, 302], true))) {
                $method = 'GET';
                $body = null;
            }

            $next = self::resolveRedirectUrl($url, $location);
            if ($next === null) {
                return new HttpResponse('', 0, [], '无效的 Location 重定向地址', $this->headers);
            }
            // 下一跳的协议/内网/DNS 校验在 sendOnce → outboundGuardOpts 中完成，
            // 校验失败会直接抛 RuntimeException，不会发出请求。
            $url = $next;
        }
    }

    /**
     * 解析 3xx Location（支持相对地址），失败返回 null。
     * 仅允许 http/https 结果，其余协议（file/ftp/gopher…）直接拒绝。
     */
    private static function resolveRedirectUrl(string $base, string $location): ?string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\x00-\x20\x7f]/', $location)) {
            return null;
        }
        if (!preg_match('#^https?://#i', $location)) {
            // 相对地址：基于当前 URL 解析
            $parts = parse_url($base);
            if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
                return null;
            }
            $authority = $parts['scheme'] . '://' . $parts['host']
                . (isset($parts['port']) ? ':' . $parts['port'] : '');
            if (str_starts_with($location, '//')) {
                $location = $parts['scheme'] . ':' . $location;
            } elseif (str_starts_with($location, '/')) {
                $location = $authority . $location;
            } else {
                $dir = substr($parts['path'] ?? '/', 0, (int) strrpos($parts['path'] ?? '/', '/') + 1);
                $location = $authority . $dir . $location;
            }
        }
        if (!preg_match('#^https?://#i', $location)) {
            return null; // 例如 javascript: / file: 伪装
        }

        return $location;
    }

    private function sendOnce(string $method, string $url, ?string $body = null): HttpResponse
    {
        $ch = curl_init();
        $responseHeaders = [];

        // 守卫开启时 FOLLOWLOCATION 恒为 false —— 重定向由 send() 手动逐跳
        // 校验跟随（见 send() 顶部注释），curl 不得自行跳转。
        $follow = $this->followRedirects && !$this->guardOutbound;

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            // 低速熔断：三条 curl 路径（send/stream/streamEvents）统一带上，
            // 使「任何 curl 都不可能被上游假死拖到 PHP 硬上限」成为不变量。
            // 本路径 timeout 通常 20~120s < 窗口 240s，实际不触发，纯防御。
            CURLOPT_LOW_SPEED_LIMIT => $this->lowSpeedLimit,
            CURLOPT_LOW_SPEED_TIME  => $this->lowSpeedTime,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS      => $follow ? 5 : 0,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                $len = strlen($line);
                $trimmed = trim($line);
                if ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$k, $v] = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }
                return $len;
            },
        ];

        // 出站守卫（用户可控地址）：协议白名单 + 内网拒绝 + DNS 锁定
        //
        // 🔴 绝不能用 array_merge 合并！$opts 的键是 CURLOPT_* **整数常量**
        //    （CURLOPT_URL=10002 等），而 array_merge 对整数键做**重新编号**
        //    → 键被冲成 0,1,2,… → curl_setopt_array 整体报
        //    "Argument #2 ($options) must contain only valid cURL options"。
        //    必须用 `+`（保留整数键）。
        //
        //    血案（2026-10-01）：只有 guardOutbound=true 时才会走到本行，
        //    而全站唯一开启守卫的就是 CustomProvider —— 于是症状极其阴间：
        //      · 内置供应商（不合并）一切正常；
        //      · 自定义供应商 chat() 走 stream() 路径（守卫单独 setopt）也正常
        //        → 「模型能聊天」；
        //      · 但 models() / action('test') / credits() 走 send() 路径，
        //        整组选项作废 → 抛异常后被 catch 吞成一条 WARN
        //        → 「模型列表永远拉不出来」。
        //    修复：用 `+` 合并；守卫选项写在左侧，确保与默认选项键冲突时守卫优先。
        if ($this->guardOutbound) {
            $opts = $this->outboundGuardOpts($url) + $opts;
        }

        curl_setopt_array($ch, $opts);

        $headerLines = [];
        foreach ($this->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        if ($headerLines) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($this->proxy && !$this->strictOutbound) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        $rpcBody = '';
        $rpcMatched = false;
        if ($this->rpcResponseId !== null) {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($handle, string $chunk) use (&$rpcBody, &$rpcMatched): int {
                $rpcBody .= $chunk;
                if (strlen($rpcBody) > 8 * 1024 * 1024) return 0;
                $frames = preg_split('/\r?\n\r?\n/', $rpcBody);
                array_pop($frames);
                foreach ($frames as $frame) {
                    $data = [];
                    foreach (explode("\n", $frame) as $line) {
                        if (str_starts_with($line, 'data:')) $data[] = ltrim(substr(rtrim($line, "\r"), 5), ' ');
                    }
                    $message = json_decode(implode("\n", $data), true);
                    if (is_array($message) && ($message['jsonrpc'] ?? '') === '2.0' && ($message['id'] ?? null) === $this->rpcResponseId) {
                        $rpcBody = json_encode($message);
                        $rpcMatched = true;
                        return 0;
                    }
                }
                return strlen($chunk);
            });
        }
        $raw = curl_exec($ch);
        if ($this->rpcResponseId !== null) $raw = $rpcBody;
        $errno = $rpcMatched ? 0 : curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $this->lastInfo = [
            'url'      => $url,
            'status'   => $status,
            'errno'    => $errno,
            'error'    => $error,
            'sent_headers' => $this->headers,
        ];

        curl_close($ch);

        if ($errno !== 0) {
            return new HttpResponse('', 0, $responseHeaders, $error ?: ('cURL 错误 ' . $errno), $this->headers);
        }

        return new HttpResponse((string) $raw, $status, $responseHeaders, null, $this->headers);
    }

    /**
     * SSE 流式请求：每收到一行 data: 就调用 $onChunk($dataLine)
     * $onChunk 返回 false 可提前中断
     *
     * @param callable(string): (bool|null) $onChunk
     */
    public function stream(string $method, string $url, ?string $body, callable $onChunk, ?callable $onStatus = null): array
    {
        $ch = curl_init();
        $buffer = '';
        $status = 0;
        $responseHeaders = [];
        $lastEventId = null;
        $finished = false;

        // 非 SSE 响应体兜底（关键）。
        // 上游限流/鉴权失败时不会发 SSE，而是回一个普通 JSON（Content-Type: application/json），
        // 例如 WorkBuddy 的 429：{"code":6004,"msg":"您的使用量已超出频率限制…"}。
        // 这类响应里**没有 data: 前缀**，会被 $emit 全部静默丢弃 —— 结果：
        //   · 调用方只看到「HTTP 429 且无正文」，拿不到 code=6004；
        //   · parseError 无法按业务码归到 MODEL_RATE，只能瞎猜；
        //   · 用户侧表现为「完全没反应 / 莫名失败」。
        // 所以这里单独累积「没有 data: 前缀」的原始字节，供 lastInfo['body'] 返回。
        $rawBody = '';
        $sawData = false;

        $emit = function (string $line) use ($onChunk, &$lastEventId, &$finished, &$sawData, &$rawBody): void {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                // 事件边界：把缓冲的 event/id 一起提交
                return;
            }
            if (str_starts_with($line, 'data:')) {
                $sawData = true;
                $payload = ltrim(substr($line, 5));
                if ($payload === '') {
                    return;
                }
                $result = $onChunk($payload, $lastEventId);
                if ($result === false) {
                    $finished = true;
                }
            } elseif (str_starts_with($line, 'id:')) {
                $lastEventId = trim(substr($line, 3));
            } else {
                // 非 SSE 行（错误 JSON / 纯文本 / 心跳注释之外的内容）→ 累积留证
                $rawBody .= $line;
            }
        };

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT       => $this->timeout,
            // 低速熔断：连接活着但长时间不吐字节时主动中断（详见 $lowSpeedTime 注释）。
            // 没有它 → 上游假死会把请求一路拖到 PHP 的 300s 硬上限，进程被 Fatal error
            // 杀死、日志缺口、客户端"自己停了"却看不到任何错误。
            CURLOPT_LOW_SPEED_LIMIT => $this->lowSpeedLimit,
            CURLOPT_LOW_SPEED_TIME  => $this->lowSpeedTime,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => $this->followRedirects,
            CURLOPT_MAXREDIRS      => $this->followRedirects ? 5 : 0,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders, &$status) {
                $trimmed = trim($line);
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $m)) {
                    $status = (int) $m[1];
                    $responseHeaders = [];
                } elseif ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$k, $v] = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffer, $emit, &$finished) {
                if ($finished) {
                    return 0; // 中断传输
                }

                // 上游字节活动 → 通知进度钩子（推理阶段无正文时维持 SSE 心跳）
                self::fireTick();

                // 诊断用：把上游 SSE 的原始字节落盘。
                // 默认关闭；调试「上游没发 [DONE] / 内容对不上」这类问题时，
                // 设环境变量 AIGW_DUMP_SSE=<文件路径> 即可，跑完记得删掉该文件。
                $dump = getenv('AIGW_DUMP_SSE');
                if (is_string($dump) && $dump !== '') {
                    @file_put_contents($dump, $chunk, FILE_APPEND);
                }

                $buffer .= $chunk;

                // 兼容 \n 与 \r\n
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $emit($line);
                    if ($finished) {
                        return 0;
                    }
                }

                return strlen($chunk);
            },
        ]);

        // 出站守卫（用户可控地址）
        // 出站守卫（用户可控地址）
        if ($this->guardOutbound) {
            curl_setopt_array($ch, $this->outboundGuardOpts($url));
        }

        $headerLines = [];
        foreach ($this->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        if ($headerLines) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($this->proxy && !$this->strictOutbound) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        // 立即输出，避免中间层缓冲
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 1024);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = $status ?: (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // 处理残留缓冲
        if (!$finished && trim($buffer) !== '') {
            foreach (explode("\n", $buffer) as $line) {
                $emit($line);
            }
        }

        curl_close($ch);

        if ($onStatus) {
            $onStatus($status, $responseHeaders);
        }

        $this->lastInfo = [
            'url'    => $url,
            'status' => $status,
            'errno'  => $errno,
            'error'  => $error,
            // 主动中断（收到 [DONE] 或回调返回 false）→ errno=23 属预期，非故障。
            'aborted' => $finished,
            // 非 SSE 响应体（上游以普通 JSON 回错误时才有内容）。
            // 仅当整条响应里一个 data: 帧都没出现时才回填，避免把正常 SSE 的
            // 尾部残片误当成错误体。调用方据此把 code=6004 等业务码交给 parseError。
            'body'   => (!$sawData && trim($rawBody) !== '') ? trim($rawBody) : '',
        ];

        return $this->lastInfo;
    }

    /**
     * SSE 流式请求（命名事件版）：按 SSE 规范聚合 event:/data: 字段，
     * 在空行（事件边界）处回调 $onEvent($eventName, $data)
     * $onEvent 返回 false 可提前中断
     *
     * 与 stream() 的区别：stream() 只认 data:/id:，用于 OpenAI 兼容流；
     * 本方法额外解析 event: 命名事件，供 Trae 等使用命名事件的协议。
     *
     * @param callable(string, string): (bool|null) $onEvent
     */
    public function streamEvents(string $method, string $url, ?string $body, callable $onEvent, ?callable $onStatus = null): array
    {
        $ch = curl_init();
        $buffer = '';
        $status = 0;
        $responseHeaders = [];
        $finished = false;

        $eventName = '';
        $dataLines = [];
        // 非 SSE 响应体兜底（同 stream()）：上游限流/鉴权失败时回普通 JSON，
        // 没有 data:/event: 前缀会被静默丢弃 → 调用方拿不到业务码。
        $rawBody = '';
        $sawData = false;

        // 事件边界：把缓冲的 event/data 一起提交
        $flush = function () use (&$eventName, &$dataLines, $onEvent, &$finished): void {
            if ($dataLines === [] && $eventName === '') {
                return;
            }
            $data = implode("\n", $dataLines);
            $name = $eventName !== '' ? $eventName : 'message';
            $eventName = '';
            $dataLines = [];

            if ($data === '') {
                return;
            }
            $result = $onEvent($name, $data);
            if ($result === false) {
                $finished = true;
            }
        };

        $emit = function (string $line) use (&$eventName, &$dataLines, $flush, &$sawData, &$rawBody): void {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                $flush();
                return;
            }
            if (str_starts_with($line, ':')) {
                return; // 注释行（心跳）
            }
            if (str_starts_with($line, 'event:')) {
                $eventName = ltrim(substr($line, 6));
                return;
            }
            if (str_starts_with($line, 'data:')) {
                $sawData = true;
                $dataLines[] = ltrim(substr($line, 5));
                return;
            }
            // id: / retry: 等字段忽略
            $rawBody .= $line;
        };

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            // 低速熔断：与 stream() 同源，Trae 走的就是 streamEvents()，
            // 缺了它 → 上游假死会把请求拖到 PHP 的 300s 硬上限（详见 $lowSpeedTime）。
            CURLOPT_LOW_SPEED_LIMIT => $this->lowSpeedLimit,
            CURLOPT_LOW_SPEED_TIME  => $this->lowSpeedTime,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders, &$status) {
                $trimmed = trim($line);
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $m)) {
                    $status = (int) $m[1];
                    $responseHeaders = [];
                } elseif ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$k, $v] = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffer, $emit, &$finished) {
                if ($finished) {
                    return 0; // 中断传输
                }

                // 上游字节活动 → 通知进度钩子（推理阶段无正文时维持 SSE 心跳）
                self::fireTick();

                // 诊断用：把上游 SSE 的原始字节落盘。
                // 默认关闭；调试「上游没发 [DONE] / 内容对不上」这类问题时，
                // 设环境变量 AIGW_DUMP_SSE=<文件路径> 即可，跑完记得删掉该文件。
                $dump = getenv('AIGW_DUMP_SSE');
                if (is_string($dump) && $dump !== '') {
                    @file_put_contents($dump, $chunk, FILE_APPEND);
                }

                $buffer .= $chunk;

                // 兼容 \n 与 \r\n
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $emit($line);
                    if ($finished) {
                        return 0;
                    }
                }

                return strlen($chunk);
            },
        ]);

        // 出站守卫（用户可控地址）
        if ($this->guardOutbound) {
            curl_setopt_array($ch, $this->outboundGuardOpts($url));
        }

        $headerLines = [];
        foreach ($this->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        if ($headerLines) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        // 与 send()/stream() 保持一致：strict 出站模式下强制不走代理
        if ($this->proxy && !$this->strictOutbound) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        // 立即输出，避免中间层缓冲
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 1024);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = $status ?: (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // 处理残留缓冲
        if (!$finished && trim($buffer) !== '') {
            foreach (explode("\n", $buffer) as $line) {
                $emit($line);
            }
        }
        if (!$finished) {
            $flush();
        }

        curl_close($ch);

        if ($onStatus) {
            $onStatus($status, $responseHeaders);
        }

        $this->lastInfo = [
            'url'    => $url,
            'status' => $status,
            'errno'  => $errno,
            'error'  => $error,
            // 主动中断（收到 [DONE] 或回调返回 false）→ errno=23 属预期，非故障。
            'aborted' => $finished,
            // 非 SSE 响应体（上游以普通 JSON 回错误时才有内容）
            'body'   => (!$sawData && trim($rawBody) !== '') ? trim($rawBody) : '',
        ];

        return $this->lastInfo;
    }
}

/**
 * HTTP 响应值对象
 */
class HttpResponse
{
    public function __construct(
        private string $body,
        private int $status,
        private array $headers = [],
        private ?string $error = null,
        private array $requestHeaders = []
    ) {}

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function requestHeaders(): array
    {
        return $this->requestHeaders;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function text(): string
    {
        return $this->body;
    }

    /**
     * 截断后的响应体，用于写日志
     */
    public function snippet(int $length = 400): string
    {
        $body = $this->body;
        if (strlen($body) > $length) {
            $body = substr($body, 0, $length) . '...';
        }
        return $body;
    }
}
