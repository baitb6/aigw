<?php
/**
 * MCP（Model Context Protocol）接入服务
 *
 * 参考 Operit 的 MCPToolExecutor / MCPRepository / RemoteMcpRuntimeSession：
 * 把 MCP 服务暴露的工具转换成 OpenAI `tools` 结构，供模型调用；
 * 模型返回 tool_calls 后，由本服务转发到对应 MCP 服务执行。
 *
 * 两种传输：
 *  - remote：JSON-RPC over HTTP（httpStream）/ SSE，直连服务端（推荐）
 *  - local ：PHP proc_open 拉起 stdio 子进程，按行做 JSON-RPC（替代 Operit
 *            依赖 Termux 跨环境复制的 MCPBridge；无需手机侧 Linux 环境）
 *
 * 协议：MCP 基于 JSON-RPC 2.0，核心方法 initialize / tools/list / tools/call。
 */

declare(strict_types=1);

namespace App\Services;

use App\Repositories\McpServerRepository;
use App\Support\HttpClient;
use App\Support\Logger;
use App\Support\UrlGuard;
use App\Support\Tenant;

class McpService
{
    /** JSON-RPC 协议版本（MCP 约定） */
    private const PROTOCOL_VERSION = '2024-11-05';

    /** 单次请求超时（秒） */
    private const TIMEOUT = 30;

    /**
     * local stdio 允许拉起的基础命令（basename 比对，不含路径）。
     * 仅覆盖常见 MCP server 运行时；可用 env AIGW_MCP_LOCAL_ALLOW 追加。
     */
    private const LOCAL_COMMAND_ALLOWLIST = [
        'node', 'nodejs', 'npx', 'npm',
        'python', 'python3', 'uv', 'uvx', 'pip', 'pip3',
        'php', 'deno', 'bun', 'bunx',
    ];

    /**
     * 校验 local 命令是否在白名单内（审计 M-3）。
     * 仅比 basename，允许 /usr/bin/node 这类绝对路径写法。
     * public：控制器在写入配置时也调用，让管理员在保存前就拿到明确报错。
     */
    public static function assertCommandAllowed(string $command): void
    {
        $base = strtolower(basename(trim($command)));
        $allowed = self::LOCAL_COMMAND_ALLOWLIST;
        $extra = (string) ($_ENV['AIGW_MCP_LOCAL_ALLOW'] ?? getenv('AIGW_MCP_LOCAL_ALLOW') ?: '');
        if ($extra !== '') {
            foreach (explode(',', $extra) as $name) {
                $name = strtolower(trim($name));
                if ($name !== '') {
                    $allowed[] = $name;
                }
            }
        }
        // Windows 可执行后缀归一化（node.exe / npx.cmd）
        $base = preg_replace('/\.(exe|cmd|bat)$/i', '', $base) ?? $base;
        if (!in_array($base, $allowed, true)) {
            Logger::warn('MCP local 命令被白名单拦截', ['command' => $command]);
            throw new \RuntimeException(
                '本地命令不在白名单内：' . $base
                . '（允许：' . implode(', ', $allowed) . '；可用环境变量 AIGW_MCP_LOCAL_ALLOW 追加）'
            );
        }
    }

    public function __construct(private McpServerRepository $repository) {}

    /**
     * 拉取指定服务的工具清单（MCP tools/list），并写入缓存。
     *
     * @return array{tools:array, status:string, message:string}
     */
    public function refreshTools(array $server): array
    {
        try {
            $tools = $this->listTools($server);
            if ((int) ($server['id'] ?? 0) > 0) {
                $this->repository->cacheTools((int) $server['id'], $tools, 'OK');
            }

            return ['tools' => $tools, 'status' => 'OK', 'message' => ''];
        } catch (\Throwable $e) {
            if ((int) ($server['id'] ?? 0) > 0) {
                $this->repository->cacheTools((int) $server['id'], [], 'FAIL', $e->getMessage());
            }
            Logger::warn('MCP 工具拉取失败', ['server' => $server['name'] ?? '', 'error' => $e->getMessage()]);

            return ['tools' => [], 'status' => 'FAIL', 'message' => $e->getMessage()];
        }
    }

    /**
     * 调用 MCP 工具（MCP tools/call）。
     *
     * @param array $server MCP 服务行（hydrate 后）
     * @param array $args   工具入参
     * @return array{tool:string,result:string,is_error:bool}
     */
    public function callTool(array $server, string $toolName, array $args): array
    {
        try {
            $result = $this->invoke($server, 'tools/call', [
                'name'      => $toolName,
                'arguments' => $args,
            ]);

            // MCP 返回 content 数组：[{type:'text', text:'...'}, ...]
            $text = $this->renderContent($result['result'] ?? []);
            $isError = !empty($result['error']) || !empty($result['result']['isError']);

            if (!empty($result['error'])) {
                $text = 'MCP 错误：' . ($result['error']['message'] ?? json_encode($result['error'], JSON_UNESCAPED_UNICODE));
            }

            return ['tool' => $toolName, 'result' => $text, 'is_error' => $isError];
        } catch (\Throwable $e) {
            return ['tool' => $toolName, 'result' => 'MCP 调用失败：' . $e->getMessage(), 'is_error' => true];
        }
    }

    /**
     * 汇总某用户所有启用 MCP 服务的工具，转成 OpenAI tools 结构。
     *
     * 工具命名 `mcp__<serverName>__<toolName>`（对齐 Operit 的 `pluginId:toolName` 前缀隔离，
     * 避免不同 MCP 服务的同名工具互相覆盖）。
     *
     * @return array{schemas:array, index:array<string,array{server:array,tool:string}>}
     */
    public function collectTools(?int $userId): array
    {
        $schemas = [];
        $index = [];

        foreach ($this->repository->enabledForUser($userId) as $server) {
            if (($server['transport'] ?? 'remote') === 'local' && !Tenant::isAdmin()) {
                continue;
            }
            $tools = $server['tools'] ?? [];
            // 缓存过期则实时刷新一次
            if ($tools === [] || (now_ms() - (int) ($server['tools_cached_at'] ?? 0)) > McpServerRepository::TOOLS_CACHE_MS) {
                $tools = $this->refreshTools($server)['tools'];
            }

            foreach ($tools as $tool) {
                $name = (string) ($tool['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $qualified = 'mcp__' . (int) $server['id'] . '__' . substr($this->slug($name), 0, 20) . '__' . substr(hash('sha256', $name), 0, 24);
                if (isset($index[$qualified])) {
                    continue;
                }
                $schemas[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $qualified,
                        'description' => (string) ($tool['description'] ?? ''),
                        'parameters'  => $tool['inputSchema'] ?? ['type' => 'object', 'properties' => []],
                    ],
                ];
                $index[$qualified] = ['server' => $server, 'tool' => $name];
            }
        }

        return ['schemas' => $schemas, 'index' => $index];
    }

    // ─────────────────── 传输层 ───────────────────

    /** 拉取工具清单 */
    private function listTools(array $server): array
    {
        $result = $this->invoke($server, 'tools/list', []);
        $tools = $result['result']['tools'] ?? [];

        return is_array($tools) ? $tools : [];
    }

    /** 执行一次 JSON-RPC 往返（按传输类型分派） */
    private function invoke(array $server, string $method, array $params): array
    {
        if (($server['transport'] ?? 'remote') === 'local') {
            if (!Tenant::isAdmin()) {
                throw new \RuntimeException('本地 stdio 服务仅管理员可执行');
            }
            return $this->invokeLocal($server, $method, $params);
        }

        return $this->invokeRemote($server, $method, $params);
    }

    /**
     * remote：JSON-RPC over HTTP（Streamable HTTP 传输）。
     *
     * 请求 POST 到 endpoint，响应体即 JSON-RPC 结果。
     * 若服务端以 SSE 返回，则从 `data:` 帧里取第一个 JSON。
     */
    private function invokeRemote(array $server, string $method, array $params): array
    {
        $endpoint = (string) ($server['endpoint'] ?? '');
        if ($endpoint === '') {
            throw new \RuntimeException('MCP 服务未配置 endpoint');
        }

        // 用户可控地址：必须走出站守卫（SSRF 防护）
        UrlGuard::assertOutbound($endpoint);

        $headers = [
            'Accept'       => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
        ];
        foreach ((array) ($server['headers'] ?? []) as $k => $v) {
            if (!preg_match('/^[A-Za-z0-9-]+$/', (string) $k) || preg_match('/[\r\n]/', (string) $v)) {
                throw new \RuntimeException('无效 MCP 请求头');
            }
            if (in_array(strtolower((string) $k), ['host', 'content-length', 'connection', 'mcp-session-id', 'mcp-protocol-version', 'accept', 'content-type'], true)) {
                continue;
            }
            $headers[strtolower((string) $k)] = (string) $v;
        }

        $token = (int) ($server['id'] ?? 0) === 0 && array_key_exists('bearer_token', $server)
            ? (string) $server['bearer_token'] : $this->repository->decryptToken($server);
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $http = (new HttpClient())
            ->withHeaders($headers)
            ->withOutboundGuard()
            ->timeout(self::TIMEOUT, 10);

        if (($server['connection_type'] ?? 'httpStream') === 'sse') {
            return $http->legacySse($endpoint, function (callable $rpc) use ($method, $params): array {
                $id = bin2hex(random_bytes(8));
                $init = $this->parseJsonRpc(json_encode($rpc(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'initialize',
                    'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass(),
                        'clientInfo' => ['name' => 'aaee-gateway', 'version' => '1.0.0']]])), $id);
                if (!in_array($init['result']['protocolVersion'] ?? '', ['2024-11-05', '2025-03-26', '2025-06-18'], true)) {
                    throw new \RuntimeException('不支持的 MCP 协议版本');
                }
                $rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
                $tools = [];
                $seen = [];
                for ($page = 0; $page < 100; $page++) {
                    $id = bin2hex(random_bytes(8));
                    $result = $this->parseJsonRpc(json_encode($rpc(['jsonrpc' => '2.0', 'id' => $id,
                        'method' => $method, 'params' => $params ?: new \stdClass()])), $id);
                    if ($method !== 'tools/list') return $result;
                    if (!is_array($result['result']['tools'] ?? null)) throw new \RuntimeException('MCP tools/list 返回无效 tools');
                    $tools = array_merge($tools, $result['result']['tools']);
                    if (count($tools) > 10000) throw new \RuntimeException('MCP 工具分页超出限制');
                    $cursor = $result['result']['nextCursor'] ?? null;
                    if ($cursor === null || $cursor === '') return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $tools]];
                    if (!is_string($cursor) || isset($seen[$cursor])) throw new \RuntimeException('MCP 工具分页无效或超出限制');
                    $seen[$cursor] = true;
                    $params['cursor'] = $cursor;
                }
                throw new \RuntimeException('MCP 工具分页超出限制');
            });
        }

        $id = bin2hex(random_bytes(8));
        $response = $http->withRpcResponseId($id)->postJson($endpoint, [
            'jsonrpc' => '2.0', 'id' => $id, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'aaee-gateway', 'version' => '1.0.0']],
        ]);
        if (!$response->ok()) {
            throw new \RuntimeException('MCP 初始化返回 HTTP ' . $response->status());
        }
        $init = $this->parseJsonRpc($response->body(), $id);
        $version = $init['result']['protocolVersion'] ?? '';
        if (!in_array($version, ['2024-11-05', '2025-03-26', '2025-06-18'], true)) {
            throw new \RuntimeException('不支持的 MCP 协议版本');
        }
        $http = $http->withHeader('MCP-Protocol-Version', $version);
        $session = $response->header('mcp-session-id');
        if ($session !== null) {
            if ($session === '' || preg_match('/[^\x21-\x7e]/', $session)) {
                throw new \RuntimeException('无效 MCP session');
            }
            $http = $http->withHeader('Mcp-Session-Id', $session);
        }
        $notice = $http->postJson($endpoint, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        if (!$notice->ok()) {
            throw new \RuntimeException('MCP initialized 返回 HTTP ' . $notice->status());
        }
        $tools = [];
        $seen = [];
        for ($page = 0; $page < 100; $page++) {
            $id = bin2hex(random_bytes(8));
            $response = $http->withRpcResponseId($id)->postJson($endpoint, ['jsonrpc' => '2.0', 'id' => $id,
                'method' => $method, 'params' => $params ?: new \stdClass()]);
            if (!$response->ok()) {
                throw new \RuntimeException('MCP 服务返回 HTTP ' . $response->status());
            }
            $result = $this->parseJsonRpc($response->body(), $id);
            if ($method !== 'tools/list') {
                return $result;
            }
            if (!is_array($result['result']['tools'] ?? null)) {
                throw new \RuntimeException('MCP tools/list 返回无效 tools');
            }
            $tools = array_merge($tools, $result['result']['tools']);
            $cursor = $result['result']['nextCursor'] ?? null;
            if ($cursor === null || $cursor === '') {
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $tools]];
            }
            if (!is_string($cursor) || isset($seen[$cursor]) || count($tools) > 10000) {
                throw new \RuntimeException('MCP 工具分页无效或超出限制');
            }
            $seen[$cursor] = true;
            $params['cursor'] = $cursor;
        }
        throw new \RuntimeException('MCP 工具分页超出限制');
    }

    /**
     * local：PHP proc_open 拉起 stdio 子进程，按行收发 JSON-RPC。
     *
     * 参考 Operit 的 MCP stdio 传输，但托管在**服务端**（不再需要 Termux 跨环境复制）：
     * 一次调用 = 启动进程 → 发 initialize → 发目标方法 → 读响应 → 收尾。
     * 无状态短连接，规避 PHP 无常驻进程的限制。
     */
    private function invokeLocal(array $server, string $method, array $params): array
    {
        $command = (string) ($server['command'] ?? '');
        if ($command === '') {
            throw new \RuntimeException('MCP 服务未配置 command');
        }

        // 命令白名单（审计 M-3 修复）：local stdio 虽仅管理员可配置，但管理员
        // 账号一旦失陷，任意 command 即等价于服务器 RCE。收敛为常见运行时
        // 解释器/包管理器，可用环境变量 AIGW_MCP_LOCAL_ALLOW 追加（逗号分隔）。
        self::assertCommandAllowed($command);

        $args = array_map('strval', (array) ($server['args'] ?? []));
        $env = [];
        foreach ((array) ($server['env'] ?? []) as $k => $v) {
            $env[(string) $k] = (string) $v;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(array_merge([$command], $args), $descriptors, $pipes, null, $env ?: null);
        if (!is_resource($process)) {
            throw new \RuntimeException('无法启动 MCP 子进程：' . $command);
        }

        try {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            // ① initialize 握手
            $this->writeLine($pipes[0], [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'initialize',
                'params'  => [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities'    => new \stdClass(),
                    'clientInfo'      => ['name' => 'aaee-gateway', 'version' => '1.0.0'],
                ],
            ]);
            $init = $this->readResponse($pipes[1], 1);
            if (!in_array($init['result']['protocolVersion'] ?? '', ['2024-11-05', '2025-03-26', '2025-06-18'], true)) {
                throw new \RuntimeException('不支持的 MCP 协议版本');
            }

            // ② notifications/initialized
            $this->writeLine($pipes[0], [
                'jsonrpc' => '2.0',
                'method'  => 'notifications/initialized',
            ]);

            $tools = [];
            $seen = [];
            for ($id = 2; $id < 102; $id++) {
                $this->writeLine($pipes[0], [
                    'jsonrpc' => '2.0', 'id' => $id, 'method' => $method,
                    'params' => $params ?: new \stdClass(),
                ]);
                $result = $this->readResponse($pipes[1], $id);
                if ($method !== 'tools/list') {
                    return $result;
                }
                if (!is_array($result['result']['tools'] ?? null)) {
                    throw new \RuntimeException('MCP tools/list 返回无效 tools');
                }
                $tools = array_merge($tools, $result['result']['tools']);
                $cursor = $result['result']['nextCursor'] ?? null;
                if ($cursor === null || $cursor === '') {
                    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $tools]];
                }
                if (!is_string($cursor) || isset($seen[$cursor]) || count($tools) > 10000) {
                    throw new \RuntimeException('MCP 工具分页无效或超出限制');
                }
                $seen[$cursor] = true;
                $params['cursor'] = $cursor;
            }
            throw new \RuntimeException('MCP 工具分页超出限制');
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            @proc_terminate($process);
            @proc_close($process);
        }
    }

    private function writeLine($stdin, array $payload): void
    {
        @fwrite($stdin, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        @fflush($stdin);
    }

    /**
     * 读取匹配指定 id 的 JSON-RPC 响应（跳过通知/其他消息）。
     */
    private function readResponse($stdout, int $expectedId): array
    {
        $deadline = microtime(true) + self::TIMEOUT;
        $buffer = '';

        while (microtime(true) < $deadline) {
            $chunk = @fgets($stdout);
            if ($chunk === false || $chunk === '') {
                usleep(50000);
                continue;
            }

            $buffer .= $chunk;
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);
                $decoded = json_decode($line, true);
                if (is_array($decoded) && ($decoded['id'] ?? null) === $expectedId) {
                    return $this->parseJsonRpc($line, $expectedId);
                }
            }
        }

        throw new \RuntimeException('MCP 子进程响应超时');
    }

    /** 解析 JSON-RPC 响应体（兼容 SSE 包裹） */
    private function parseJsonRpc(string $body, string|int|null $expectedId = null): array
    {
        if (trim($body) === '') {
            throw new \RuntimeException('MCP 返回空响应');
        }

        $messages = [json_decode($body, true)];
        $frames = preg_split('/\r?\n\r?\n/', $body);
        array_pop($frames);
        foreach ($frames as $frame) {
            $data = [];
            foreach (explode("\n", $frame) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr(rtrim($line, "\r"), 5), ' ');
                }
            }
            if ($data !== []) {
                $messages[] = json_decode(implode("\n", $data), true);
            }
        }
        foreach ($messages as $decoded) {
            if (!is_array($decoded) || ($decoded['jsonrpc'] ?? '') !== '2.0'
                || !array_key_exists('id', $decoded) || ($expectedId !== null && $decoded['id'] !== $expectedId)) {
                continue;
            }
            if (array_key_exists('error', $decoded)) {
                throw new \RuntimeException('MCP JSON-RPC 错误：' . (string) ($decoded['error']['message'] ?? '未知错误'));
            }
            if (!is_array($decoded['result'] ?? null)) {
                throw new \RuntimeException('MCP JSON-RPC result 无效');
            }
            return $decoded;
        }

        throw new \RuntimeException('无法解析 MCP 响应');
    }

    /** MCP content 数组 → 纯文本 */
    private function renderContent($result): string
    {
        $content = $result['content'] ?? $result;
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return json_encode($content, JSON_UNESCAPED_UNICODE) ?: '';
        }

        $parts = [];
        foreach ($content as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['type'] ?? '') === 'text') {
                $parts[] = (string) ($item['text'] ?? '');
            } else {
                $parts[] = json_encode($item, JSON_UNESCAPED_UNICODE) ?: '';
            }
        }

        return implode("\n", $parts);
    }

    /** 服务名 → 工具前缀安全字符 */
    private function slug(string $name): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? '';
        return $slug === '' ? 'mcp' : $slug;
    }
}
