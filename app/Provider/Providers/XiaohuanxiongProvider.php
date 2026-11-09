<?php
/**
 * 商汤小浣熊（xiaohuanxiong.com）适配器 —— 网页端私有协议
 *
 * 参考实现：https://github.com/haoxinyu1/xiaohuanxiong2api（Go）
 *
 * 登录方式：WEBVIEW_CALLBACK —— 在 https://xiaohuanxiong.com/code/authorize
 * 完成授权后浏览器会跳到自定义协议 `office-raccoon://auth/callback?code=..&state=..`，
 * 把该链接整条粘贴回管理台即可（后端换码 → 落库双 Token）。
 *
 * 协议要点（与 OpenAI **不**同，必须信封装解包）：
 *   - LLM 网关   POST {llm}/chat/completions   SSE 帧 `data: {...}` + `data: [DONE]`
 *   - 模型目录   GET  {llm}/model_catalog      categories[].models[] 平铺
 *   - 刷新 Token POST {auth}/refresh           body {"refresh_token": "..."}，不带 Authorization
 *   - 授权码换证 POST {auth}/login_with_authorization_code   body {"authorization_code": "..."}
 *   - 积分余额   GET  {points}/balance
 *
 * 基址：
 *   llm    https://xiaohuanxiong.com/api/web/llm/v2
 *   auth   https://xiaohuanxiong.com/api/web/auth/v1
 *   points https://xiaohuanxiong.com/api/web/points/v1
 *
 * 信封：`{"code":0,"message":"...","data":{...}}`；**只有 code 存在且非 0 才算失败**，
 * code 缺省视为成功 —— 直接复用 parseOpenAiSse 的错误帧判定也依赖这一点
 * （SSE 正常帧不写 message，信封 code=0 属健康码）。
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
use App\Support\Logger;

class XiaohuanxiongProvider extends AbstractProvider
{
    public const ORIGIN = 'https://xiaohuanxiong.com';

    public const LLM_BASE    = self::ORIGIN . '/api/web/llm/v2';
    public const AUTH_BASE   = self::ORIGIN . '/api/web/auth/v1';
    public const POINTS_BASE = self::ORIGIN . '/api/web/points/v1';

    /** 授权页（desktop 模式回调 scheme 固定为 office-raccoon） */
    private const AUTHORIZE_PATH = '/code/authorize';

    /**
     * 手机模式回环回调地址。
     * 上游校验只放行 http://127.0.0.1|localhost|[::1]:<port>（必须是 http、必须带端口），
     * 该地址在手机上必然连不上，但浏览器地址栏会完整保留这条 URL，
     * 用户长按即可复制，粘回管理台照样能换码入库。
     */
    private const MOBILE_REDIRECT = 'http://127.0.0.1:18080/xiaohuanxiong/callback';

    private const USER_AGENT = 'xhx2api/0.1.0';

    /** 授权码失效的业务码 */
    private const CODE_AUTH_EXPIRED = 200035;

    /** 提前刷新窗口：到期前 300s 内才刷新，避免每轮请求都打一次上游 */
    private const REFRESH_MARGIN_MS = 300_000;

    /** state 有效期 */
    private const STATE_TTL_SECONDS = 600;

    private const DEFAULT_MODEL = 'sn-glm-5-3-flash';

    /**
     * 上游不可达时的兜底模型（**model_catalog 才是权威**，此表仅防界面空白）。
     * 值 = [显示名, 是否多模态, 是否推理]，与上游 tags 快照保持一致。
     */
    private const BUILTIN_MODELS = [
        'sn-sensenova-6-8-flash'      => ['SenseNova-6.8-Flash', true, true],
        'sn-sensenova-6-8-flash-lite' => ['SenseNova-6.8-Flash-Lite', true, true],
        'sn-glm-5-3'                  => ['GLM-5-3', true, true],
        'sn-kimi-k3'                  => ['Kimi-K3', true, true],
        'sn-glm-5-3-flash'            => ['GLM-5-3-Flash', false, true],
        'sn-deepseek-v4-1-flash'      => ['DeepSeek-V4.1-Flash', false, true],
    ];

    /**
     * 上游 tags 漏标、但实测确认会产出推理增量的模型。
     *
     * 实测方式：以「只输出两个字母：OK」这类零思考需求的提问逐个调用，
     * 观察 SSE 的 reasoning 增量与 usage.reasoning_tokens —— 这几个模型均非空，
     * 其中 SenseNova-6.8-Flash 会把预算全花在思考上（finish=length、正文空）。
     * 因此 reasoning 判定口径为：**tags 含 reasoning 或 命中本表**。
     */
    private const REASONING_CONFIRMED = [
        'sn-sensenova-6-8-flash'      => true,
        'sn-sensenova-6-8-flash-lite' => true,
        'sn-glm-5-3-flash'            => true,
    ];

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
        return 'xiaohuanxiong';
    }

    public function displayName(): string
    {
        return '商汤小浣熊';
    }

    public function loginType(): string
    {
        return 'WEBVIEW_CALLBACK';
    }

    public function hosts(): array
    {
        return $this->providerRepository->find($this->id())['hosts'] ?? ['xiaohuanxiong.com'];
    }

    // ---------------------------------------------------------
    // 凭据解析
    // ---------------------------------------------------------

    private function accessToken(Account $account): string
    {
        foreach (['access_token', 'accessToken', 'token'] as $key) {
            $value = trim((string) $account->cred($key, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function refreshTokenValue(Account $account): string
    {
        foreach (['refresh_token', 'refreshToken'] as $key) {
            $value = trim((string) $account->cred($key, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @return array<string,string> */
    private function authHeaders(Account $account, string $accept = 'application/json'): array
    {
        $token = $this->accessToken($account);
        if ($token === '') {
            throw new \RuntimeException('商汤小浣熊缺少 access_token，请先登录授权');
        }

        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => $accept,
            'Content-Type'  => 'application/json',
            'User-Agent'    => self::USER_AGENT,
        ];
    }

    /**
     * 模型目录归属的 provider_key：以账号自带值为权威（同 QoderProvider::providerKeyOf）。
     */
    private function providerKeyOf(Account $account): string
    {
        $key = trim($account->providerKey);

        return $key !== '' ? $key : $this->id();
    }

    /**
     * 解信封：`{code,message,data}`。
     *
     * @return array{ok:bool, data:array, message:string, code:?int}
     */
    private function unwrap(array $body, int $status): array
    {
        $code = $body['code'] ?? null;
        $hasCode = $code !== null;
        $codeInt = $hasCode && is_numeric($code) ? (int) $code : null;
        $message = trim((string) ($body['message'] ?? $body['msg'] ?? ''));
        $data = $body['data'] ?? null;
        $data = is_array($data) ? $data : [];

        $ok = $status >= 200 && $status < 300 && (!$hasCode || (int) $code === 0);

        if (!$ok && $message === '') {
            $message = 'HTTP ' . $status . ($codeInt !== null ? ' code=' . $codeInt : '');
        }

        return ['ok' => $ok, 'data' => $data, 'message' => $message, 'code' => $codeInt];
    }

    // ---------------------------------------------------------
    // 对话
    // ---------------------------------------------------------

    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        $url = self::LLM_BASE . '/chat/completions';

        $payload = $request->toUpstreamPayload([
            'stream' => true,
            'model'  => $request->model,
        ]);

        return $this->parseOpenAiSse(
            $url,
            $payload,
            $this->authHeaders($account, 'text/event-stream'),
            $account,
            $onDelta
        );
    }

    // ---------------------------------------------------------
    // 模型
    // ---------------------------------------------------------

    public function models(Account $account): array
    {
        $ids = array_column($this->fetchUpstreamModelList($account), 'model_id');
        if ($ids !== []) {
            return array_map('strval', $ids);
        }

        // 回退：上游不可达时用本地库（管理员可手工增删/开关）
        $models = [];
        foreach ($this->modelRepository->all($this->providerKeyOf($account)) as $model) {
            if (!empty($model['enabled'])) {
                $models[] = (string) $model['model_id'];
            }
        }

        if ($models !== []) {
            return $models;
        }

        return array_keys(self::BUILTIN_MODELS);
    }

    public function modelCatalog(Account $account): array
    {
        $upstream = $this->fetchUpstreamModelList($account);
        if ($upstream !== []) {
            return $upstream;
        }

        // 回退：本地目录命中的用本地，未命中的用内置兜底表补元数据
        $rows = $this->modelRepository->all($this->providerKeyOf($account));

        $enabled = [];
        foreach ($rows as $row) {
            if (!empty($row['enabled'])) {
                $enabled[(string) $row['model_id']] = true;
            }
        }

        $ids = array_keys($enabled) ?: array_keys(self::BUILTIN_MODELS);

        $out = [];
        foreach ($ids as $modelId) {
            $meta = self::BUILTIN_MODELS[$modelId] ?? null;
            $multimodal = (bool) ($meta[1] ?? false);
            $reasoning = (bool) ($meta[2] ?? false);

            $capabilities = ['stream'];
            if ($multimodal) {
                $capabilities[] = 'vision';
            }
            if ($reasoning) {
                $capabilities[] = 'reasoning';
            }

            $out[] = [
                'model_id'         => $modelId,
                'display_name'     => $meta[0] ?? $modelId,
                'rate'             => null,
                'rate_source'      => '',
                'fee_level'        => null,
                'entitlement_cost' => null,
                'context_window'   => null,
                'max_tokens'       => null,
                'multimodal'       => $multimodal,
                'reasoning'        => $reasoning,
                'capabilities'     => $capabilities,
            ];
        }

        return $out;
    }

    /**
     * 拉取上游模型目录：`GET {llm}/model_catalog`。
     *
     * 结构：`{code,message,data:{categories:[{type,models:[{name,model_name,description,
     * display_description,visible,context_window,max_tokens,billing_multiplier,
     * billing_effective_multiplier,params:{...}}]}]}}`。
     * 根上没有 `data` 时把根自身当 data；同名模型首次出现者胜。
     *
     * 任何失败一律返回空数组，由调用方回退本地目录。
     *
     * @return list<array<string,mixed>>
     */
    private function fetchUpstreamModelList(Account $account): array
    {
        try {
            $response = $this->http()
                ->withHeaders($this->authHeaders($account))
                ->timeout(10, 10)
                ->get(self::LLM_BASE . '/model_catalog');

            if (!$response->ok()) {
                Logger::warn("{$this->id()} 模型目录拉取失败", [
                    'uid'    => $account->uid,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $body = $response->json();
        } catch (\Throwable $e) {
            Logger::warn("{$this->id()} 模型目录拉取异常", [
                'uid'   => $account->uid,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $envelope = $this->unwrap($body, 200);
        if (!$envelope['ok']) {
            Logger::warn("{$this->id()} 模型目录返回异常", [
                'uid'     => $account->uid,
                'message' => $envelope['message'],
            ]);

            return [];
        }

        $data = $envelope['data'] !== [] ? $envelope['data'] : $body;
        $categories = $data['categories'] ?? $data['Categories'] ?? [];
        if (!is_array($categories)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($categories as $category) {
            $models = is_array($category) ? ($category['models'] ?? []) : [];
            foreach ((array) $models as $model) {
                $entry = $this->normalizeCatalogEntry(is_array($model) ? $model : []);
                if ($entry === null || isset($seen[$entry['model_id']])) {
                    continue;
                }
                $seen[$entry['model_id']] = true;
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * 目录单项归一化。上游字段：
     *   name（模型 id） > model_name 兜底，两者都空则丢弃
     *   description 即模型代号（如 GLM-5-3-Flash），display_description 是长说明文案
     *   visible 缺省 true；context_window / max_tokens 顶层优先、params 兜底
     *   tags 含 vision/vl/image → 多模态；含 reasoning → 推理模型
     *   reasoning 另叠加 REASONING_CONFIRMED（实测会推理但上游漏标的模型）
     *
     * @return array<string,mixed>|null
     */
    private function normalizeCatalogEntry(array $model): ?array
    {
        $name = trim((string) ($model['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($model['model_name'] ?? ''));
        }
        if ($name === '') {
            return null;
        }

        $codename = trim((string) ($model['description'] ?? ''));
        $displayDescription = trim((string) ($model['display_description'] ?? ''));

        $displayName = $codename !== ''
            ? mb_substr($codename, 0, 64)
            : ($displayDescription !== '' ? mb_substr($displayDescription, 0, 64) : $name);

        $params = is_array($model['params'] ?? null) ? $model['params'] : [];
        $contextWindow = $this->pickInt($model['context_window'] ?? null, $params['context_window'] ?? null);
        $maxTokens = $this->pickInt($model['max_tokens'] ?? null, $params['max_tokens'] ?? null);

        $rate = null;
        foreach (['billing_effective_multiplier', 'billing_multiplier'] as $key) {
            if (isset($model[$key]) && is_numeric($model[$key])) {
                $rate = (float) $model[$key];
                break;
            }
        }

        // 未标注 visible 的模型视为可见；tags 含 vision/vl/image 视为多模态，含 reasoning 视为推理
        $visible = !array_key_exists('visible', $model) || !empty($model['visible']);
        if (!$visible) {
            return null;
        }

        $tags = array_map('strval', (array) ($model['tags'] ?? []));
        $multimodal = false;
        $reasoning = isset(self::REASONING_CONFIRMED[$name]);
        foreach ($tags as $tag) {
            $low = strtolower($tag);
            if (str_contains($low, 'vision') || str_contains($low, 'vl') || str_contains($low, 'image')) {
                $multimodal = true;
            }
            if (str_contains($low, 'reasoning')) {
                $reasoning = true;
            }
        }

        $capabilities = ['stream'];
        if ($multimodal) {
            $capabilities[] = 'vision';
        }
        if ($reasoning) {
            $capabilities[] = 'reasoning';
        }

        return [
            'model_id'         => $name,
            'display_name'     => $displayName,
            'rate'             => $rate,
            'rate_source'      => $rate !== null ? 'count' : '',
            'fee_level'        => null,
            'entitlement_cost' => null,
            'context_window'   => $contextWindow,
            'max_tokens'       => $maxTokens,
            'multimodal'       => $multimodal,
            'reasoning'        => $reasoning,
            'capabilities'     => $capabilities,
        ];
    }

    private function pickInt(mixed $top, mixed $nested): ?int
    {
        foreach ([$top, $nested] as $value) {
            if ($value !== null && is_numeric($value) && (float) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    // ---------------------------------------------------------
    // 额度：GET {points}/balance
    // ---------------------------------------------------------

    public function credits(Account $account): CreditInfo
    {
        try {
            $response = $this->http()
                ->withHeaders($this->authHeaders($account))
                ->timeout(20, 10)
                ->get(self::POINTS_BASE . '/balance');

            $envelope = $this->unwrap($response->json(), $response->status());
            if (!$envelope['ok']) {
                return CreditInfo::failure($envelope['message']);
            }
        } catch (\Throwable $e) {
            return CreditInfo::failure($e->getMessage());
        }

        $data = $envelope['data'];
        $fields = [
            'available_points' => '可用积分',
            'daily_points'     => '每日额度',
            'monthly_points'   => '每月额度',
            'reward_points'    => '奖励积分',
            'topup_points'     => '充值积分',
        ];

        $packs = [];
        foreach ($fields as $key => $label) {
            $value = $data[$key] ?? null;
            if (!is_numeric($value)) {
                continue;
            }
            $packs[] = [
                'name'   => $label,
                'group'  => 'points',
                'limit'  => (float) $value,
                'used'   => 0.0,
                'remain' => (float) $value,
            ];
        }

        $available = isset($data['available_points']) && is_numeric($data['available_points'])
            ? (float) $data['available_points']
            : null;

        if ($available === null && $packs === []) {
            return CreditInfo::unknown('商汤小浣熊未返回积分明细');
        }

        $detail = implode('，', array_map(
            static fn (array $pack): string => $pack['name'] . ' ' . $pack['limit'],
            $packs
        ));

        return new CreditInfo(
            known: true,
            balance: $available,
            detail: $detail,
            packs: $packs,
        );
    }

    // ---------------------------------------------------------
    // 动作：连通性自检 / 同步模型
    // ---------------------------------------------------------

    public function action(Account $account, string $action, array $params = []): array
    {
        return match ($action) {
            'test', 'connectivity' => $this->actionTest($account),
            'sync_models'          => $this->actionSyncModels($account),
            default                => parent::action($account, $action, $params),
        };
    }

    private function actionTest(Account $account): array
    {
        try {
            $response = $this->http()
                ->withHeaders($this->authHeaders($account))
                ->timeout(10, 10)
                ->get(self::LLM_BASE . '/model_catalog');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '连通失败：' . $e->getMessage(), 'data' => null];
        }

        $status = $response->status();
        if (!$response->ok()) {
            $parsed = $this->parseError($status, $response->body());

            return [
                'ok'      => false,
                'message' => '连通失败（HTTP ' . $status . '）：' . ($parsed['message'] ?? $this->extractMessage($response->body())),
                'data'    => ['status' => $status, 'error_code' => $parsed['code'] ?? null],
            ];
        }

        $count = count($this->fetchUpstreamModelList($account));

        return [
            'ok'      => true,
            'message' => '连通正常，可用模型 ' . $count . ' 个',
            'data'    => ['status' => $status, 'model_count' => $count],
        ];
    }

    private function actionSyncModels(Account $account): array
    {
        $catalog = $this->modelCatalog($account);
        $result = $this->modelRepository->syncCatalog($this->providerKeyOf($account), $catalog);

        return [
            'ok'      => $catalog !== [],
            'message' => $catalog !== []
                ? sprintf('同步 %d 个模型，新增 %d 个，更新 %d 个', count($catalog), $result['created'], $result['updated'])
                : '未获取到模型',
            'data'    => $catalog,
        ];
    }

    // ---------------------------------------------------------
    // Token 刷新：POST {auth}/refresh（不带 Authorization）
    // ---------------------------------------------------------

    /**
     * 返回需要合并进 credential 的字段字典；空数组表示无需刷新。
     *
     * 注意：AccountPool::ensureFreshToken 每轮请求前都会调用本方法，因此这里必须
     * **自身判断是否临期**（到期前 REFRESH_MARGIN_MS 内才打上游），否则每个请求
     * 都要多一次刷新往返。
     *
     * 绝不能返回 {ok,message} 之类结构，否则会被写入 credential 造成污染。
     */
    public function refreshToken(Account $account): array
    {
        $refresh = $this->refreshTokenValue($account);
        if ($refresh === '') {
            return [];
        }

        $expiresAt = (int) $account->cred('expires_at', 0);
        if ($expiresAt > 0 && now_ms() + self::REFRESH_MARGIN_MS < $expiresAt) {
            return [];
        }

        try {
            $response = $this->http()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                    'User-Agent'   => self::USER_AGENT,
                ])
                ->timeout(30, 10)
                ->postJson(self::AUTH_BASE . '/refresh', ['refresh_token' => $refresh]);
        } catch (\Throwable $e) {
            Logger::warn("{$this->id()} 凭据刷新异常", ['uid' => $account->uid, 'error' => $e->getMessage()]);

            return [];
        }

        $status = $response->status();
        $envelope = $this->unwrap($response->json(), $status);

        if (!$envelope['ok']) {
            Logger::warn("{$this->id()} 凭据刷新失败", [
                'uid'     => $account->uid,
                'status'  => $status,
                'message' => $envelope['message'],
            ]);

            // 只有明确的鉴权失败才判会话失效；网络/上游抖动不应停用账号
            if ($status === 401 || $status === 403) {
                return [
                    '__session_dead' => true,
                    '__message'      => "{$this->displayName()} 登录态已失效，请重新授权",
                    '__relogin_url'  => self::ORIGIN,
                ];
            }

            return [];
        }

        $newToken = trim((string) ($envelope['data']['access_token'] ?? ''));
        if ($newToken === '') {
            Logger::warn("{$this->id()} 凭据刷新未返回 access_token", ['uid' => $account->uid]);

            return [];
        }

        // 上游返回空 refresh_token 表示未轮换，沿用旧值
        $newRefresh = trim((string) ($envelope['data']['refresh_token'] ?? ''));

        $patch = ['access_token' => $newToken];
        if ($newRefresh !== '') {
            $patch['refresh_token'] = $newRefresh;
        }

        $expiry = self::jwtExpiry($newToken);
        if ($expiry > 0) {
            $patch['expires_at'] = $expiry;
        }

        return $patch;
    }

    /**
     * 取 JWT 的 exp（毫秒）。不验签，仅用于判断临期。
     */
    private static function jwtExpiry(string $token): int
    {
        $token = trim($token);
        if (str_starts_with($token, 'Bearer ')) {
            $token = trim(substr($token, 7));
        }

        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return 0;
        }

        $payload = self::base64UrlDecode($parts[1]);
        if ($payload === '') {
            return 0;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return 0;
        }

        foreach (['exp', 'expires_at'] as $key) {
            if (!isset($decoded[$key]) || !is_numeric($decoded[$key])) {
                continue;
            }
            $seconds = (float) $decoded[$key];
            if ($seconds > 0) {
                // 秒级 exp 换算成毫秒；已是毫秒的大数直接使用
                return $seconds > 1e11 ? (int) $seconds : (int) ($seconds * 1000);
            }
        }

        return 0;
    }

    private static function base64UrlDecode(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        return $decoded === false ? '' : $decoded;
    }

    // ---------------------------------------------------------
    // 登录：授权页 → 自定义协议回调 → 换码落库
    // ---------------------------------------------------------

    public function startLogin(array $params = []): LoginSession
    {
        $sessionId = uuid();
        $state = bin2hex(random_bytes(16));
        $appName = trim((string) ($params['app_name'] ?? '')) ?: '办公小浣熊客户端';

        // 手机模式：省略 login_source（上游会按非 desktop 处理），并带上合法的回环 redirect，
        // 上游因此产出标准 http 回调 URL（?authorization_code=..&state=..），
        // 手机浏览器地址栏会保留该 URL，可长按复制后粘回管理台。
        $mobile = !empty($params['mobile']);
        $query = [
            'appname' => $appName,
            'state'   => $state,
        ];
        if ($mobile) {
            $query['redirect'] = self::MOBILE_REDIRECT;
        } else {
            $query['login_source'] = 'desktop';
        }

        $authUrl = self::ORIGIN . self::AUTHORIZE_PATH . '?' . http_build_query($query);

        $this->saveLoginSession($sessionId, [
            'state'      => $state,
            'created_at' => now_ms(),
        ]);

        $message = $mobile
            ? '请在手机上打开授权链接并用小浣熊账号登录授权。授权完成后页面会跳转到 '
                . self::MOBILE_REDIRECT . '?authorization_code=..&state=.. ，'
                . '手机打不开这个回环地址（提示无法连接）是正常的，'
                . '请长按地址栏复制那条完整链接，粘到下方「粘贴回调地址」提交即可完成。'
            : '请在电脑端浏览器（Chrome / Edge）完成小浣熊授权：授权后页面会跳到 '
                . 'office-raccoon://auth/callback?code=..&state=.. ，浏览器打不开该协议是正常的，'
                . '把地址栏里这条链接整条粘贴到下方「粘贴回调地址」提交即可。'
                . '手机端请改用「手机版授权」或「手工填 Token」。';

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'waiting',
            message: $message,
            authUrl: $authUrl,
            expiresInSeconds: self::STATE_TTL_SECONDS,
            extra: [
                'callback_scheme' => $mobile ? self::MOBILE_REDIRECT : 'office-raccoon://auth/callback',
                'mobile'          => $mobile,
            ]
        );
    }

    public function pollLogin(string $sessionId, array $params = []): LoginSession
    {
        $credential = $params['credential'] ?? null;
        if (is_string($credential) && $credential !== '') {
            $decoded = json_decode($credential, true);
            $credential = is_array($decoded) ? $decoded : null;
        }

        if (is_array($credential) && $credential !== []) {
            $result = $this->ingestCredential($credential);
            $state = !empty($result['ok']) ? 'success' : 'failed';

            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: $state,
                message: (string) ($result['message'] ?? ''),
                accountUid: (string) ($result['account_uid'] ?? ''),
                nickname: (string) ($result['nickname'] ?? ''),
                error: $state === 'failed' ? (string) ($result['message'] ?? 'import_failed') : null,
            );
        }

        // 授权页在本机浏览器完成，回调链接需用户粘贴回管理台，这里只维持等待态
        $session = $this->loadLoginSession($sessionId);
        if ($session !== null && $this->isSessionExpired($session)) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'expired',
                message: '授权链接已过期，请重新发起',
                error: 'state_expired',
            );
        }

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'waiting',
            message: '等待粘贴授权回调链接',
        );
    }

    /**
     * 回调入口。
     * desktop 模式：`office-raccoon://auth/callback?code=..&state=..`
     * 手机模式：`http://127.0.0.1:<port>/..?authorization_code=..&state=..`
     * （AccountController::parseCallbackUrl 已把自定义协议 URL / 完整 URL 解析成 query）
     */
    public function handleCallback(array $query): array
    {
        $code = trim((string) ($query['code'] ?? $query['authorization_code'] ?? ''));
        if ($code === '') {
            return ['ok' => false, 'message' => '回调地址里没有 code / authorization_code 参数'];
        }

        $state = trim((string) ($query['state'] ?? ''));
        $sessionId = trim((string) ($query['session_id'] ?? ''));
        if ($state !== '' && $sessionId !== '') {
            $session = $this->loadLoginSession($sessionId);
            $expected = (string) ($session['state'] ?? '');
            if ($expected !== '' && !hash_equals($expected, $state)) {
                return ['ok' => false, 'message' => 'state 校验失败，请重新发起授权'];
            }
        }

        return $this->exchangeCode($code);
    }

    /**
     * 一次性授权码换凭证：POST {auth}/login_with_authorization_code
     */
    private function exchangeCode(string $code): array
    {
        try {
            $response = $this->http()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                    'User-Agent'   => self::USER_AGENT,
                ])
                ->timeout(30, 10)
                ->postJson(self::AUTH_BASE . '/login_with_authorization_code', [
                    'authorization_code' => $code,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '换取授权凭证失败：' . $e->getMessage()];
        }

        $status = $response->status();
        $envelope = $this->unwrap($response->json(), $status);

        if ($envelope['code'] === self::CODE_AUTH_EXPIRED) {
            return ['ok' => false, 'message' => '授权码已失效，请重新发起授权'];
        }

        if (!$envelope['ok']) {
            return ['ok' => false, 'message' => '换取授权凭证失败：' . $envelope['message']];
        }

        return $this->ingestCredential($envelope['data']);
    }

    public function ingestCredential(array $credential): array
    {
        $access = '';
        foreach (['access_token', 'accessToken', 'token'] as $key) {
            $access = trim((string) ($credential[$key] ?? ''));
            if ($access !== '') {
                break;
            }
        }

        if ($access === '') {
            return ['ok' => false, 'message' => '小浣熊凭据缺少 access_token'];
        }

        $refresh = trim((string) ($credential['refresh_token'] ?? $credential['refreshToken'] ?? ''));

        $stored = ['access_token' => $access];
        if ($refresh !== '') {
            $stored['refresh_token'] = $refresh;
        }

        $expiresAt = (int) ($credential['expires_at'] ?? 0);
        if ($expiresAt <= 0) {
            $expiresAt = self::jwtExpiry($access);
        }
        if ($expiresAt > 0) {
            $stored['expires_at'] = $expiresAt;
        }

        $uid = trim((string) ($credential['user_id'] ?? $credential['uid'] ?? ''));
        if ($uid === '') {
            $uid = 'xhx_' . substr(hash('sha256', $access), 0, 16);
        }

        $orgName = trim((string) ($credential['office_org_name'] ?? ''));
        $orgRole = trim((string) ($credential['office_org_role'] ?? ''));
        $identity = $credential['office_identity'] ?? '';
        $identity = is_array($identity) ? '' : trim((string) $identity);

        $nickname = trim((string) ($credential['nickname'] ?? $credential['name'] ?? $identity));
        if ($nickname === '') {
            $nickname = $this->displayName();
        }

        // 合并既有 meta，避免重新授权时丢掉历史展示信息
        $existing = $this->accountRepository->findByUid($this->providerKeyOfForCredential($credential), $uid);
        $meta = $existing !== null ? $existing->meta : [];
        $meta = array_merge($meta, array_filter([
            'office_identity' => $identity,
            'org_name'        => $orgName,
            'org_role'        => $orgRole,
            'added_at'        => $meta['added_at'] ?? now_ms(),
        ], static fn ($v) => $v !== '' && $v !== null));

        $accountId = $this->accountRepository->upsertCredential(
            $this->id(),
            $uid,
            $stored,
            $meta,
            $nickname
        );

        return [
            'ok'          => true,
            'message'     => $this->displayName() . ' 账号已保存',
            'account_uid' => $uid,
            'nickname'    => $nickname,
            'account_id'  => $accountId,
        ];
    }

    /** 导入凭据时以请求里的 provider 标识为准，缺省用本适配器 id */
    private function providerKeyOfForCredential(array $credential): string
    {
        $key = trim((string) ($credential['provider_key'] ?? $credential['provider'] ?? ''));

        return $key !== '' ? $key : $this->id();
    }

    // ---------------------------------------------------------
    // 登录会话暂存（state 校验用）
    // ---------------------------------------------------------

    private function sessionDir(): string
    {
        $dir = storage_path('login');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private function saveLoginSession(string $sessionId, array $data): void
    {
        file_put_contents(
            $this->sessionDir() . '/' . $this->sessionFile($sessionId),
            json_encode($data, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function loadLoginSession(string $sessionId): ?array
    {
        $file = $this->sessionDir() . '/' . $this->sessionFile($sessionId);
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function sessionFile(string $sessionId): string
    {
        return 'xhx-' . preg_replace('/[^a-zA-Z0-9\-]/', '', $sessionId) . '.json';
    }

    private function isSessionExpired(array $session): bool
    {
        $createdAt = (int) ($session['created_at'] ?? 0);

        return $createdAt > 0 && now_ms() - $createdAt > self::STATE_TTL_SECONDS * 1000;
    }
}
