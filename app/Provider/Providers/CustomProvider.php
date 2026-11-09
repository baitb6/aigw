<?php
/**
 * 自定义供应商适配器（OpenAI 兼容端点）
 * 登录方式：API_KEY —— 手工录入 Base URL 与 API Key，真实可跑
 *
 * 配置来源（二选一，账号级优先）：
 *   1) aigw_provider.options = { base_url, api_keys: [], protocol, models: [] }
 *   2) aigw_account.credential = { baseUrl, apiKey }
 */

declare(strict_types=1);

namespace App\Provider\Providers;

use App\Provider\AbstractProvider;
use App\Provider\Dto\Account;
use App\Provider\Dto\ChatRequest;
use App\Provider\Dto\Chunk;
use App\Provider\Dto\CreditInfo;
use App\Provider\Dto\LoginSession;
use App\Repositories\AccountRepository;
use App\Repositories\ModelRepository;
use App\Repositories\ProviderRepository;
use App\Repositories\ProxyRepository;
use App\Support\HttpClient;
use App\Support\Logger;
use App\Support\UrlGuard;

class CustomProvider extends AbstractProvider
{
    public function __construct(
        ProxyRepository $proxyRepository,
        private AccountRepository $accountRepository,
        private ModelRepository $modelRepository,
        private ProviderRepository $providerRepository,
    ) {
        parent::__construct($proxyRepository);
    }

    public function id(): string
    {
        return 'custom';
    }

    public function displayName(): string
    {
        return '自定义供应商';
    }

    public function loginType(): string
    {
        return 'API_KEY';
    }

    public function hosts(): array
    {
        return [];
    }

    /**
     * 出站请求客户端：**强制开启 SSRF 守卫**。
     *
     * 自定义供应商的 base_url 完全由用户填写（审计报告 SEC-01），
     * 是全站唯一「用户可控出站地址」，因此这里不能像内置供应商那样直接用
     * 父类的 http()，必须显式带上守卫：协议白名单 + 内网拒绝 + DNS 锁定。
     */
    public function http(): HttpClient
    {
        return parent::http()->withOutboundGuard();
    }

    // ---------------------------------------------------------
    // 配置解析
    // ---------------------------------------------------------

    /** 取该账号归属的供应商配置（支持 custom_xxx 多实例） */
    private function options(Account $account): array
    {
        $config = $this->providerRepository->find($account->providerKey);
        $options = $config['options'] ?? [];

        // 账号级 baseUrl 覆盖实例级配置
        $accountBaseUrl = trim((string) $account->cred('baseUrl', ''));
        if ($accountBaseUrl !== '') {
            $options['base_url'] = $accountBaseUrl;
        }

        return $options;
    }

    private function baseUrl(Account $account): string
    {
        $options = $this->options($account);
        $base = trim((string) ($options['base_url'] ?? $options['baseUrl'] ?? ''));

        if ($base === '') {
            throw new \RuntimeException('自定义供应商未配置 Base URL，请在供应商设置中填写');
        }

        $base = rtrim($base, '/');

        // SSRF 防线（审计 SEC-01）：在真正发起请求前校验 scheme / 内网地址。
        // 这里再校验一次，是为了兜住「历史已落库的恶意 base_url」与
        // 「入库后配置被改写」两种绕过 store 入口的情形。
        UrlGuard::assertOutbound($base);

        return $base;
    }

    private function apiKey(Account $account): string
    {
        // 账号级 apiKey 优先
        $key = trim((string) $account->cred('apiKey', ''));
        if ($key !== '') {
            return $key;
        }

        $options = $this->options($account);
        $keys = $options['api_keys'] ?? $options['apiKeys'] ?? [];
        if (is_string($keys) && $keys !== '') {
            $keys = preg_split('/[\s,\n]+/', $keys) ?: [];
        }
        if (is_array($keys) && !empty($keys)) {
            return trim((string) reset($keys));
        }

        throw new \RuntimeException('自定义供应商缺少 API Key，请先添加账号');
    }

    /**
     * 路径规范化：补齐 /chat/completions、/models
     * base_url 允许写成 https://host/v1 或 https://host
     */
    private function endpoint(Account $account, string $path): string
    {
        $base = $this->baseUrl($account);

        // 已带目标路径则直接使用
        if (str_ends_with($base, $path)) {
            return $base;
        }

        // 已含 /chat/completions 但请求 /models 等场景做替换
        if (preg_match('#/chat/completions$#', $base) && $path !== '/chat/completions') {
            $base = substr($base, 0, -strlen('/chat/completions'));
        }

        // 未以 /v1 结尾时补齐版本段（OpenAI 兼容惯例）
        if (!preg_match('#/v\d+$#', $base)) {
            $base .= '/v1';
        }

        return $base . $path;
    }

    private function headers(Account $account): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey($account),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
    }

    // ---------------------------------------------------------
    // 对话
    // ---------------------------------------------------------

    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        $url = $this->endpoint($account, '/chat/completions');
        $headers = array_merge($this->headers($account), ['Accept' => 'text/event-stream']);

        $payload = $request->toUpstreamPayload([
            'stream' => true,
            'model'  => $request->model,
        ]);

        return $this->parseOpenAiSse($url, $payload, $headers, $account, $onDelta);
    }

    // ---------------------------------------------------------
    // 模型
    // ---------------------------------------------------------

    public function models(Account $account): array
    {
        $configured = $this->options($account)['models'] ?? [];
        $fallback = (is_array($configured) && !empty($configured))
            ? array_values(array_map('strval', $configured))
            : [];

        $url = '';
        $reason = '未知原因';

        try {
            $url = $this->endpoint($account, '/models');
            $res = $this->http()->withHeaders($this->headers($account))->timeout(20)->get($url);

            if ($res->ok()) {
                $data = $res->json();
                $items = $data['data'] ?? $data['models'] ?? [];
                $ids = [];
                foreach ((array) $items as $item) {
                    if (is_string($item)) {
                        $ids[] = $item;
                    } elseif (is_array($item) && isset($item['id'])) {
                        $ids[] = (string) $item['id'];
                    }
                }
                if (!empty($ids)) {
                    return $ids;
                }
                // HTTP 200 但没有模型条目：上游目录确实是空的
                $reason = '上游 /models 返回 200，但列表为空';
            } else {
                $reason = sprintf(
                    '上游返回 HTTP %d：%s',
                    $res->status(),
                    mb_substr(trim((string) $res->body()), 0, 300)
                );
            }
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
        }

        Logger::warn('自定义供应商拉取模型失败', [
            'uid'      => $account->uid,
            'url'      => $url,
            'error'    => $reason,
            'fallback' => count($fallback),
        ]);

        // 回退到配置中手工填写的模型（用户显式给了清单就尊重它，不因为上游抽风而空白）
        if (!empty($fallback)) {
            return $fallback;
        }

        // 🔴 没有手工回退清单时，**必须把真实原因抛出去**，不能静默返回 []。
        //    血案（2026-10-01）：HttpClient::send() 里 array_merge 冲掉了 CURLOPT 整数键，
        //    导致本方法必然抛错、又被这里 catch 吞成一条 WARN，前端只看到
        //    「上游未返回任何模型」——把**网关自身的故障**伪装成**上游没有模型**，
        //    排查方向被彻底带偏。抛出去后 ModelController::runSync 会返回
        //    「拉取模型失败：<真实原因>」，用户第一眼就能看到问题在哪一层。
        throw new \RuntimeException('拉取模型失败：' . $reason . ($url !== '' ? '（' . $url . '）' : ''));
    }

    // ---------------------------------------------------------
    // 额度：OpenAI 兼容端点无统一额度接口，返回配置提示
    // ---------------------------------------------------------

    public function credits(Account $account): CreditInfo
    {
        // 部分中转站提供 /dashboard/billing/credit_grants
        try {
            $base = $this->baseUrl($account);
            $root = preg_replace('#/v\d+$#', '', $base) ?: $base;

            $res = $this->http()->withHeaders($this->headers($account))->timeout(15)
                ->get($root . '/dashboard/billing/credit_grants');

            if ($res->ok()) {
                $data = $res->json();
                $total = $data['total_available'] ?? $data['total_granted'] ?? null;
                if ($total !== null && is_numeric($total)) {
                    return new CreditInfo(
                        known: true,
                        balance: round((float) $total, 4),
                        detail: '剩余额度 ' . round((float) $total, 4),
                        packs: [[
                            'name'   => '账户额度',
                            'group'  => 'billing',
                            'limit'  => (float) ($data['total_granted'] ?? $total),
                            'used'   => (float) ($data['total_used'] ?? 0),
                            'remain' => (float) $total,
                        ]]
                    );
                }
            }
        } catch (\Throwable) {
            // 忽略：多数自定义端点不提供额度接口
        }

        return CreditInfo::unknown('自定义供应商不提供额度查询接口');
    }

    // ---------------------------------------------------------
    // 动作：连通性自检
    // ---------------------------------------------------------

    public function action(Account $account, string $action, array $params = []): array
    {
        if ($action === 'test' || $action === 'connectivity') {
            try {
                $url = $this->endpoint($account, '/models');
                $res = $this->http()->withHeaders($this->headers($account))->timeout(20)->get($url);

                if ($res->ok()) {
                    $count = count($this->models($account));
                    return [
                        'ok'      => true,
                        'message' => "连通正常，可用模型 {$count} 个",
                        'data'    => ['url' => $url, 'status' => $res->status()],
                    ];
                }

                return [
                    'ok'      => false,
                    'message' => '连通失败（HTTP ' . $res->status() . '）：' . $this->extractMessage($res->body()),
                    'data'    => ['url' => $url, 'status' => $res->status()],
                ];
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => '连通失败：' . $e->getMessage(), 'data' => null];
            }
        }

        if ($action === 'sync_models') {
            // models() 现在会把真实失败原因抛出（不再静默返回 []），
            // 这条动作是「自检按钮」的落点，必须转成结构化结果而不是 500。
            try {
                $models = $this->models($account);
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
            }

            $created = $this->modelRepository->sync($account->providerKey, $models);
            return [
                'ok'      => !empty($models),
                'message' => $models ? sprintf('同步 %d 个模型，新增 %d 个', count($models), $created) : '未获取到模型',
                'data'    => $models,
            ];
        }

        return parent::action($account, $action, $params);
    }

    // ---------------------------------------------------------
    // Token：API Key 模式无需刷新
    // ---------------------------------------------------------

    public function refreshToken(Account $account): array
    {
        return [];
    }

    // ---------------------------------------------------------
    // 登录：手工录入 API Key
    // ---------------------------------------------------------

    public function startLogin(array $params = []): LoginSession
    {
        return new LoginSession(
            sessionId: uuid(),
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'pending',
            message: '请填写 Base URL 与 API Key，保存后立即生效',
            extra: ['need_api_key' => true]
        );
    }

    /**
     * API_KEY 模式：直接从参数落库（无需轮询）
     */
    public function pollLogin(string $sessionId, array $params = []): LoginSession
    {
        $providerKey = trim((string) ($params['provider_key'] ?? 'custom'));
        $baseUrl = trim((string) ($params['base_url'] ?? ''));
        $apiKey = trim((string) ($params['api_key'] ?? ''));
        $nickname = trim((string) ($params['nickname'] ?? ''));

        if ($baseUrl === '' || $apiKey === '') {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $providerKey,
                loginType: $this->loginType(),
                state: 'failed',
                message: 'Base URL 与 API Key 均为必填',
                error: 'missing_fields'
            );
        }

        // SSRF 防线（审计 SEC-01）：入库前即拒绝非 http(s) 与内网/保留地址，
        // 给用户明确报错，而不是等到真正发请求时才失败。
        try {
            UrlGuard::assertOutbound($baseUrl);
        } catch (\RuntimeException $e) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $providerKey,
                loginType: $this->loginType(),
                state: 'failed',
                message: 'Base URL 不被允许：' . $e->getMessage(),
                error: 'invalid_base_url'
            );
        }

        $uid = 'key_' . substr(hash('sha256', $baseUrl . '|' . $apiKey), 0, 16);

        $accountId = $this->accountRepository->upsertCredential(
            $providerKey,
            $uid,
            ['baseUrl' => $baseUrl, 'apiKey' => $apiKey],
            ['base_url' => $baseUrl, 'added_at' => now_ms()],
            $nickname !== '' ? $nickname : (parse_url($baseUrl, PHP_URL_HOST) ?: $uid)
        );

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $providerKey,
            loginType: $this->loginType(),
            state: 'success',
            message: 'API Key 账号已保存',
            accountUid: $uid,
            nickname: $nickname
        );
    }
}