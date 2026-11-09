<?php
/**
 * Agent2API 网关适配器
 *
 * 定位：把一台已部署好的 Agent2API 网关（Rust 服务 + 官方插件包）当成
 *      AIGW 的一个**上游**接入。网关里那两套插件的能力（37 个 provider、
 *      网页提供商、账号签到、额度查询、代理池）通过本适配器暴露给 AIGW 控制台。
 *
 * 为什么不是「把插件搬进 PHP」：
 *   agent2api-providers / agent2api-web-providers 是随网关进程加载的**原生 ELF 插件**
 *   （ed25519 签名、由网关 host 提供 ABI），PHP 进程无法加载它们；
 *   能复用的是它们在网关里注册出来的**结果**——provider 目录、模型目录、
 *   账号池与签到/额度接口。故这里做的是「能力桥接」，不是「代码合并」。
 *
 * 多实例：
 *   provider_key 支持 `agent2api`（聚合：网关全部模型放一个供应商）与
 *   `agent2api_<provider>`（拆分：网关里每个 provider 在 AIGW 里各占一行）两种形态，
 *   由 options.upstream_provider 区分；bin/agent2api-sync.php 负责批量生成。
 *
 * 凭据（三处合并，后者覆盖前者）：
 *   1) aigw_provider.options = { base_url, api_keys: [], panel_user, panel_password, upstream_provider, models }
 *   2) aigw_account.credential  = { baseUrl, apiKey, panelUser, panelPassword }
 *
 * SSRF 说明：
 *   CustomProvider 强制 withOutboundGuard()（用户可填任意地址，是公网多租户风险）。
 *   本适配器的 base_url 同样是管理端填写，但**典型部署就是 127.0.0.1:3065 或内网机器**，
 *   套上「拒绝内网」的守卫会直接把主用法打死。故这里不加守卫，
 *   把边界交给「只有管理员能改供应商配置」这一层；若把网关放到公网，
 *   请自行在 options 里限定域名并用防火墙收敛出站。
 */

declare(strict_types=1);

namespace App\Provider\Providers;

use App\Provider\AbstractProvider;
use App\Provider\Dto\Account;
use App\Provider\Dto\ChatRequest;
use App\Provider\Dto\Chunk;
use App\Provider\Dto\CreditInfo;
use App\Provider\Dto\LoginSession;
use App\Repositories\ProviderRepository;
use App\Repositories\ProxyRepository;
use App\Support\Agent2ApiClient;
use App\Support\HttpClient;
use App\Support\Logger;
use RuntimeException;

class Agent2ApiProvider extends AbstractProvider
{
    /** 默认 key；ProviderManager 会用 withKey() 注入真实 key（支持 agent2api_xxx 多实例） */
    private string $key = 'agent2api';

    /** @var array<string,mixed>|null 本 key 对应的供应商配置缓存 */
    private ?array $optionsCache = null;

    public function __construct(
        ProxyRepository $proxyRepository,
        private ProviderRepository $providerRepository,
    ) {
        parent::__construct($proxyRepository);
    }

    public function withKey(string $key): void
    {
        $this->key = $key;
        $this->optionsCache = null;
    }

    public function id(): string
    {
        return $this->key;
    }

    public function displayName(): string
    {
        $name = trim((string) ($this->options()['display_name'] ?? ''));

        return $name !== '' ? $name : 'Agent2API 网关';
    }

    /**
     * 网关用「网关 Key」而非账号密码调模型，故登录方式沿用 API_KEY 表单
     * （AIGW 控制台据此渲染「粘贴凭据」入口）。
     */
    public function loginType(): string
    {
        return 'API_KEY';
    }

    public function hosts(): array
    {
        $host = $this->client(null)->host();

        return $host !== '' ? [$host] : [];
    }

    public function supportsImageInput(): bool
    {
        // 网关原样转发 OpenAI messages 结构，图片块得以保留
        return true;
    }

    // ---------------------------------------------------------
    // 配置与客户端
    // ---------------------------------------------------------

    /** @return array<string,mixed> */
    private function options(): array
    {
        if ($this->optionsCache !== null) {
            return $this->optionsCache;
        }

        $config = $this->providerRepository->find($this->key);

        return $this->optionsCache = is_array($config['options'] ?? null) ? $config['options'] : [];
    }

    /**
     * 构造客户端：供应商级配置打底，账号级凭据覆盖
     */
    private function client(?Account $account): Agent2ApiClient
    {
        $options = $this->options();

        $cred = [
            'baseUrl'       => (string) ($options['base_url'] ?? $options['baseUrl'] ?? ''),
            'apiKey'        => self::firstKey($options),
            'panelUser'     => (string) ($options['panel_user'] ?? $options['panelUser'] ?? ''),
            'panelPassword' => (string) ($options['panel_password'] ?? $options['panelPassword'] ?? ''),
        ];

        if ($account !== null) {
            foreach (['baseUrl', 'apiKey', 'panelUser', 'panelPassword'] as $field) {
                $value = trim((string) $account->cred($field, ''));
                if ($value !== '') {
                    $cred[$field] = $value;
                }
            }
        }

        return Agent2ApiClient::fromCredential($cred, $this->http());
    }

    /** @param array<string,mixed> $options */
    private static function firstKey(array $options): string
    {
        $keys = $options['api_keys'] ?? $options['apiKeys'] ?? [];
        if (is_string($keys) && trim($keys) !== '') {
            return trim($keys);
        }
        if (is_array($keys)) {
            foreach ($keys as $key) {
                if (is_string($key) && trim($key) !== '') {
                    return trim($key);
                }
            }
        }

        return trim((string) ($options['api_key'] ?? $options['apiKey'] ?? ''));
    }

    /** 本实例绑定的上游 provider key（拆分模式）；空 = 聚合全部 */
    private function upstreamProvider(): string
    {
        return trim((string) ($this->options()['upstream_provider'] ?? ''));
    }

    // ---------------------------------------------------------
    // 对话
    // ---------------------------------------------------------

    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        $client = $this->client($account);

        // 网关按模型 id 自己选账号/节点，这里不做任何改写；
        // stream=true 是网关出 SSE 的前提，缺了会拿回一整块 JSON 而解析不出增量。
        $payload = $request->toUpstreamPayload([
            'stream' => true,
            'model'  => $request->model,
        ]);

        $headers = array_merge($client->headers(), ['Accept' => 'text/event-stream']);

        return $this->parseOpenAiSse(
            $client->v1('/chat/completions'),
            $payload,
            $headers,
            $account,
            $onDelta
        );
    }

    // ---------------------------------------------------------
    // 模型
    // ---------------------------------------------------------

    public function models(Account $account): array
    {
        try {
            $items = $this->catalog($account);
            if ($items !== []) {
                return array_values(array_map(static fn (array $m): string => (string) $m['id'], $items));
            }
        } catch (\Throwable $e) {
            Logger::warn('Agent2API 拉取模型失败', ['key' => $this->key, 'error' => $e->getMessage()]);
        }

        // 兜底：配置里写死的模型清单（离线也能出目录）
        $configured = $this->options()['models'] ?? [];

        return is_array($configured) ? array_values(array_map('strval', $configured)) : [];
    }

    public function modelCatalog(Account $account): array
    {
        try {
            $items = $this->catalog($account);
        } catch (\Throwable $e) {
            Logger::warn('Agent2API 拉取模型目录失败', ['key' => $this->key, 'error' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $ownedBy = (string) ($item['owned_by'] ?? '');
            $lower   = strtolower($id);

            $out[] = [
                'model_id'       => $id,
                'display_name'   => (string) ($item['name'] ?? $item['display_name'] ?? $id),
                'provider_key'   => $this->key,
                // 网关不声明能力，按模型名做保守推断：名字里带 vision/vl/4o 才算多模态
                'multimodal'     => (bool) preg_match('/vision|vl\b|4o|omni/i', $lower),
                'reasoning'      => (bool) preg_match('/reason|think|r1\b|glm-5/i', $lower),
                'capabilities'   => ['chat', 'stream', 'tools'],
                'context_window' => (int) ($item['context_window'] ?? 0),
                'max_tokens'     => (int) ($item['max_tokens'] ?? 0),
                'meta'           => ['upstream_provider' => $ownedBy],
            ];
        }

        return $out;
    }

    /**
     * 网关模型目录（按 upstream_provider 过滤）
     *
     * @return list<array<string,mixed>>
     */
    private function catalog(Account $account): array
    {
        $items  = $this->client($account)->models();
        $filter = $this->upstreamProvider();

        if ($filter === '') {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn (array $m): bool => (string) ($m['owned_by'] ?? '') === $filter
        ));
    }

    // ---------------------------------------------------------
    // 额度
    // ---------------------------------------------------------

    public function credits(Account $account): CreditInfo
    {
        try {
            $client = $this->client($account);
            if (!$client->hasPanelCredential()) {
                return CreditInfo::unknown('未配置面板账号，额度信息不可读（模型调用不受影响）');
            }

            $usage   = $client->usage();
            $results = $usage['results'] ?? [];
            if (!is_array($results) || $results === []) {
                return CreditInfo::unknown('网关暂未上报额度');
            }

            $filter  = $this->upstreamProvider();
            $balance = 0.0;
            $packs   = [];
            $hit     = 0;

            foreach ($results as $row) {
                if (!is_array($row)) {
                    continue;
                }
                // 拆分模式只看本 provider 的账号；聚合模式全算
                if ($filter !== '' && !str_starts_with((string) ($row['id'] ?? ''), $filter . '-')) {
                    continue;
                }

                $hit++;
                $available = $row['usage']['available'] ?? null;
                if (is_numeric($available)) {
                    $balance += (float) $available;
                }

                foreach ((array) ($row['usage']['credits'] ?? []) as $credit) {
                    if (!is_array($credit)) {
                        continue;
                    }
                    $packs[] = [
                        'name'   => (string) ($credit['name'] ?? '额度'),
                        'group'  => (string) ($row['name'] ?? $filter),
                        'limit'  => 0.0,
                        'used'   => 0.0,
                        'remain' => (float) ($credit['remain'] ?? 0),
                    ];
                }
            }

            if ($hit === 0) {
                return CreditInfo::unknown('网关无可用账号');
            }

            return new CreditInfo(
                known:  true,
                balance: $balance,
                detail: sprintf('网关 %d 个账号可用，合计 %s', $hit, self::fmt($balance)),
                packs:  $packs,
                usage:  ['total' => 0.0, 'used' => 0.0, 'remain' => $balance],
            );
        } catch (\Throwable $e) {
            return CreditInfo::failure('读取额度失败：' . $e->getMessage());
        }
    }

    private static function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    // ---------------------------------------------------------
    // 扩展动作
    // ---------------------------------------------------------

    /**
     * 控制台「操作」下拉里可点的动作：
     *   health        网关连通性
     *   providers     插件合并后的 provider 目录（含两家插件各自贡献）
     *   accounts      账号池概览
     *   checkin       签到（传 id 签单个，不传签全部）
     *   refresh       刷新账号令牌
     *   auto-checkin  自动签到开关状态
     */
    public function action(Account $account, string $action, array $params = []): array
    {
        try {
            $client = $this->client($account);

            switch ($action) {
                case 'health':
                    $health = $client->health();
                    return [
                        'ok'      => (bool) $health['ok'],
                        'message' => $health['ok'] ? '网关在线' : ('网关异常，HTTP ' . $health['status']),
                        'data'    => $health['body'],
                    ];

                case 'providers':
                    $plugins = $client->plugins();
                    $packs   = [];
                    foreach ((array) ($plugins['packs'] ?? []) as $pack) {
                        if (!is_array($pack)) {
                            continue;
                        }
                        $packs[] = [
                            'id'        => (string) ($pack['id'] ?? ''),
                            'version'   => (string) ($pack['version'] ?? ''),
                            'enabled'   => (bool) ($pack['enabled'] ?? false),
                            'providers' => (array) ($pack['host']['providers'] ?? []),
                        ];
                    }
                    $hostProviders = (array) ($plugins['host']['providers'] ?? []);

                    return [
                        'ok'      => true,
                        'message' => sprintf('已装插件 %d 个，provider 合计 %d 个', count($packs), count($hostProviders)),
                        'data'    => [
                            'packs'     => $packs,
                            'providers' => $hostProviders,
                            'updates'   => $plugins['updates'] ?? [],
                        ],
                    ];

                case 'accounts':
                    $rows = $client->accounts();
                    $enabled = 0;
                    foreach ($rows as $row) {
                        if (is_array($row) && !empty($row['enabled'])) {
                            $enabled++;
                        }
                    }

                    return [
                        'ok'      => true,
                        'message' => sprintf('账号 %d 个，启用 %d 个', count($rows), $enabled),
                        'data'    => $rows,
                    ];

                case 'checkin':
                    $id   = (string) ($params['id'] ?? $params['accountId'] ?? '');
                    $data = $client->checkin($id !== '' ? $id : null);

                    return [
                        'ok'      => true,
                        'message' => $id !== '' ? '已提交单账号签到' : '已提交全部账号签到',
                        'data'    => $data,
                    ];

                case 'refresh':
                    $id = (string) ($params['id'] ?? $params['accountId'] ?? '');
                    if ($id === '') {
                        throw new RuntimeException('刷新令牌需要指定账号 id');
                    }

                    return [
                        'ok'      => true,
                        'message' => '已提交令牌刷新',
                        'data'    => $client->refreshAccount($id),
                    ];

                case 'auto-checkin':
                    $data = $client->autoCheckinStatus();
                    if (isset($params['enabled'])) {
                        $data = $client->panelPost('/auto-checkin', ['enabled' => (bool) $params['enabled']]);
                    }

                    return ['ok' => true, 'message' => '自动签到状态', 'data' => $data];

                default:
                    return ['ok' => false, 'message' => "Agent2API 不支持动作：{$action}", 'data' => null];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    /** 网关没有可续期的 token：Key 长期有效，面板令牌由客户端自动续签 */
    public function refreshToken(Account $account): array
    {
        return [
            'credential' => [],
            'message'    => 'Agent2API 网关 Key 长期有效，无需刷新',
        ];
    }

    // ---------------------------------------------------------
    // 错误分类
    // ---------------------------------------------------------

    public function parseError(int $status, string $body): ?array
    {
        $decoded = json_decode($body, true);
        $code    = is_array($decoded) ? (string) ($decoded['error']['code'] ?? $decoded['code'] ?? '') : '';

        // 网关自有错误码：语义与 HTTP 状态码不同，必须单独映射
        switch ($code) {
            case 'model_api_key_required':
                // 没配网关 Key，换号/重试都没用 —— 客户端配置问题
                return [
                    'code'    => 'CLIENT',
                    'message' => '网关未收到 Key：请在账号凭据里填写 Agent2API 的网关 Key（面板「网关 Key」页创建）',
                ];

            case 'panel_login_required':
                return ['code' => 'CLIENT', 'message' => '面板登录态缺失：请补充 panelUser / panelPassword'];

            case 'model_not_found':
                return ['code' => 'MODEL_UNAVAILABLE', 'message' => '网关没有这个模型，请先在网关注册对应 provider'];

            case 'refresh_token_expired':
                return ['code' => 'SESSION_DEAD', 'message' => '网关账号登录态过期，请在网关面板重新登录该账号'];
        }

        // 其余交给基类的通用分类（含 SSE 内层错误）
        return parent::parseError($status, $body);
    }

    // ---------------------------------------------------------
    // 登录（本项目用「粘贴凭据」，不做交互式登录）
    // ---------------------------------------------------------

    public function startLogin(array $params = []): LoginSession
    {
        return new LoginSession(
            sessionId:   '',
            providerKey: $this->key,
            loginType:   'API_KEY',
            state:       'failed',
            message:     'Agent2API 请用「粘贴凭据」添加：Base URL + 网关 Key（可选面板账号以启用额度/签到）',
        );
    }

    public function pollLogin(string $sessionId, array $params = []): LoginSession
    {
        return new LoginSession(
            sessionId:   $sessionId,
            providerKey: $this->key,
            loginType:   'API_KEY',
            state:       'failed',
            message:     'Agent2API 不支持交互式登录，请直接粘贴凭据',
        );
    }

    public function handleCallback(array $query): array
    {
        return ['ok' => false, 'message' => 'Agent2API 不支持回调登录'];
    }

    /**
     * 校验粘贴进来的凭据：真打一次 /health 与 /v1/models
     *
     * 只做「能不能连、Key 有没有配上」，不把 Key 回显出去。
     *
     * @param array<string,mixed> $credential
     * @return array{ok:bool, message:string, credential?:array<string,mixed>}
     */
    public function ingestCredential(array $credential): array
    {
        try {
            $client = Agent2ApiClient::fromCredential($credential, $this->http());

            $health = $client->health();
            if (!$health['ok']) {
                return ['ok' => false, 'message' => '连不上网关：HTTP ' . $health['status']];
            }

            $models = $client->models();
            $panel  = '';

            if ($client->hasPanelCredential()) {
                try {
                    $client->panelToken();
                    $panel = '，面板登录成功';
                } catch (\Throwable $e) {
                    $panel = '，面板登录失败（' . $e->getMessage() . '）';
                }
            }

            return [
                'ok'      => true,
                'message' => sprintf('网关在线，模型 %d 个%s', count($models), $panel),
                'credential' => $credential,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '凭据校验失败：' . $e->getMessage()];
        }
    }
}
