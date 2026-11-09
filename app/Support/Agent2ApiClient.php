<?php
/**
 * Agent2API 网关 HTTP 客户端
 *
 * 为什么需要这一层：
 *   Agent2API 是独立部署的 OpenAI 兼容网关（Rust 服务 + 两家官方插件），
 *   它的能力分布在**两套**鉴权面上，混用会互相 401：
 *
 *     1) 模型面  /v1/*        —— 只认网关 Key（`Authorization: Bearer a2a_...`）
 *     2) 管理面  /api/*       —— 只认面板令牌（`x-panel-token: <64hex>`，2 小时过期）
 *
 *   适配器里直接拼 curl 会把这两套头搅在一起（本项目历史上出现过
 *   「把面板令牌塞进 Authorization，网关回 model_api_key_required」的坑），
 *   所以统一收在这里：模型面走 `headers()`，管理面走 `panelToken()`。
 *
 * 面板令牌的刷新口径：
 *   令牌由 `POST /api/panel/login` 现签，2 小时有效且**不续期**。
 *   这里按「提前 5 分钟换新」缓存（6600 秒），失败即整表失效重登一次。
 */

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Agent2ApiClient
{
    /** 面板令牌安全有效期（秒）：2 小时签到，留 5 分钟余量 */
    private const PANEL_TOKEN_SAFE_TTL = 6600;

    /**
     * 进程内面板令牌缓存，键 = base|user
     *
     * 用 static 而非实例属性：适配器实例由 ProviderManager 按 key 克隆，
     * 每个平台一个实例；同一进程内多个账号共用一把令牌即可，不必各自登录。
     *
     * @var array<string, array{token:string, expire:int}>
     */
    private static array $panelTokens = [];

    public function __construct(
        private string $baseUrl,
        private string $apiKey = '',
        private string $panelUser = '',
        private string $panelPassword = '',
        private ?HttpClient $http = null,
    ) {
    }

    /**
     * 从账号凭据构造（兼容 camelCase 与 snake_case 两种写法）
     *
     * @param array<string,mixed> $cred
     */
    public static function fromCredential(array $cred, ?HttpClient $http = null): self
    {
        $pick = static function (array $src, array $keys, string $default = ''): string {
            foreach ($keys as $key) {
                $value = $src[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
            return $default;
        };

        return new self(
            baseUrl:       $pick($cred, ['baseUrl', 'base_url', 'gatewayUrl', 'gateway_url'], 'http://127.0.0.1:3065'),
            apiKey:        $pick($cred, ['apiKey', 'api_key', 'gatewayKey', 'gateway_key', 'key']),
            panelUser:     $pick($cred, ['panelUser', 'panel_user', 'username']),
            panelPassword: $pick($cred, ['panelPassword', 'panel_password', 'password']),
            http:          $http,
        );
    }

    // ---------------------------------------------------------
    // 地址
    // ---------------------------------------------------------

    public function base(): string
    {
        $base = trim($this->baseUrl);
        if ($base === '') {
            throw new RuntimeException('Agent2API 网关未配置 Base URL（例：http://127.0.0.1:3065）');
        }

        if (!preg_match('#^https?://#i', $base)) {
            // 少写协议是常见笔误：`127.0.0.1:3065` 直接被 curl 当相对路径
            $base = 'http://' . $base;
        }

        return rtrim($base, '/');
    }

    /** 网关主机名（供 hosts() 做代理排除与 SSRF 白名单） */
    public function host(): string
    {
        $host = parse_url($this->base(), PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    public function v1(string $path): string
    {
        return $this->base() . '/v1' . $path;
    }

    public function api(string $path): string
    {
        return $this->base() . '/api' . $path;
    }

    // ---------------------------------------------------------
    // 模型面（网关 Key）
    // ---------------------------------------------------------

    /** @return array<string,string> */
    public function headers(): array
    {
        $headers = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (trim($this->apiKey) !== '') {
            $headers['Authorization'] = 'Bearer ' . trim($this->apiKey);
        }

        return $headers;
    }

    /**
     * 拉取模型清单
     *
     * 网关的 `/v1/models` 是**开放**接口（无 Key 也返回 200），但调用
     * `/v1/chat/completions` 必须有 Key；故这里不带 Key 也能取到目录，
     * 用于控制台展示与模型同步。
     *
     * @return list<array<string,mixed>> data 数组
     */
    public function models(): array
    {
        $res = $this->client()
            ->withHeaders($this->headers())
            ->timeout(20)
            ->get($this->v1('/models'));

        if (!$res->ok()) {
            throw new RuntimeException(sprintf(
                'Agent2API 模型目录读取失败（HTTP %d）：%s',
                $res->status(),
                $res->snippet(200)
            ));
        }

        $json = $res->json();
        $data = $json['data'] ?? [];

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /** 健康检查（网关自带，无需鉴权） */
    public function health(): array
    {
        $res = $this->client()->timeout(8)->get($this->base() . '/health');

        return [
            'ok'     => $res->ok(),
            'status' => $res->status(),
            'body'   => $res->json(),
        ];
    }

    // ---------------------------------------------------------
    // 管理面（面板令牌）
    // ---------------------------------------------------------

    public function hasPanelCredential(): bool
    {
        return $this->panelUser !== '' && $this->panelPassword !== '';
    }

    /**
     * 取面板令牌：命中缓存直接用，过期/失效则重新登录一次
     *
     * @param bool $force 强制重新登录（缓存令牌被上游判 401 时用）
     */
    public function panelToken(bool $force = false): string
    {
        if (!$this->hasPanelCredential()) {
            throw new RuntimeException('Agent2API 管理接口需要面板账号：请填写 panelUser / panelPassword');
        }

        $cacheKey = $this->base() . '|' . $this->panelUser;
        $cached   = self::$panelTokens[$cacheKey] ?? null;

        if (!$force && is_array($cached) && ($cached['expire'] ?? 0) > time()) {
            return (string) $cached['token'];
        }

        $res = $this->client()
            ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
            ->timeout(20)
            ->postJson($this->api('/panel/login'), [
                'username' => $this->panelUser,
                'password' => $this->panelPassword,
            ]);

        if (!$res->ok()) {
            throw new RuntimeException(sprintf(
                'Agent2API 面板登录失败（HTTP %d）：%s',
                $res->status(),
                $res->snippet(200)
            ));
        }

        $token = (string) ($res->json()['data']['accessToken'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Agent2API 面板登录成功但未返回 accessToken');
        }

        self::$panelTokens[$cacheKey] = [
            'token'  => $token,
            'expire' => time() + self::PANEL_TOKEN_SAFE_TTL,
        ];

        return $token;
    }

    /**
     * 管理面 GET
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed> data 字段（结构视接口而定）
     */
    public function panelGet(string $path, array $query = [], bool $retryOn401 = true): array
    {
        $url = $this->api($path);
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $res = $this->client()
            ->withHeaders(['x-panel-token' => $this->panelToken(), 'Accept' => 'application/json'])
            ->timeout(30)
            ->get($url);

        if ($res->status() === 401 && $retryOn401) {
            $this->panelToken(true);
            return $this->panelGet($path, $query, false);
        }

        return $this->unwrap($res, 'GET ' . $path);
    }

    /**
     * 管理面 POST
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function panelPost(string $path, array $body = [], bool $retryOn401 = true): array
    {
        $res = $this->client()
            ->withHeaders([
                'x-panel-token' => $this->panelToken(),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])
            ->timeout(60)
            ->postJson($this->api($path), $body);

        if ($res->status() === 401 && $retryOn401) {
            $this->panelToken(true);
            return $this->panelPost($path, $body, false);
        }

        return $this->unwrap($res, 'POST ' . $path);
    }

    /** @return array<string,mixed> */
    private function unwrap(HttpResponse $res, string $label): array
    {
        if (!$res->ok()) {
            throw new RuntimeException(sprintf(
                'Agent2API %s 失败（HTTP %d）：%s',
                $label,
                $res->status(),
                $res->snippet(200)
            ));
        }

        $json = $res->json();
        $data = $json['data'] ?? $json;

        return is_array($data) ? $data : [];
    }

    // ---------------------------------------------------------
    // 常用管理接口（全部来自网关前端实际调用的形态）
    // ---------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function accounts(): array
    {
        $data = $this->panelGet('/accounts');
        $rows = $data['accounts'] ?? $data;

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * 插件与提供商清单（两家插件合并后的能力面）
     *
     * 返回结构保留网关原样：
     *   packs[]       已安装插件（id / version / enabled / host.providers[]）
     *   host.providers[]  主机汇总的提供商 key 列表
     *   catalog.plugins[] 索引仓库里可安装的插件
     *
     * @return array<string,mixed>
     */
    public function plugins(): array
    {
        return $this->panelGet('/plugins');
    }

    /**
     * 账号额度/用量（网关按 provider 聚合）
     *
     * @return array<string,mixed>
     */
    public function usage(): array
    {
        try {
            return $this->panelGet('/accounts/usage');
        } catch (RuntimeException) {
            return [];
        }
    }

    /**
     * 签到：传 id 签单个账号，不传签全部
     *
     * @return array<string,mixed>
     */
    public function checkin(?string $accountId = null): array
    {
        return $this->panelPost('/accounts/checkin', $accountId !== null && $accountId !== '' ? ['id' => $accountId] : []);
    }

    /**
     * 刷新账号令牌
     *
     * @return array<string,mixed>
     */
    public function refreshAccount(string $accountId): array
    {
        return $this->panelPost('/accounts/refresh', ['id' => $accountId]);
    }

    /**
     * 自动签到开关状态
     *
     * @return array<string,mixed>
     */
    public function autoCheckinStatus(): array
    {
        try {
            return $this->panelGet('/auto-checkin');
        } catch (RuntimeException) {
            return [];
        }
    }

    private function client(): HttpClient
    {
        return $this->http ?? new HttpClient();
    }
}
