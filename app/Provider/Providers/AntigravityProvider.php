<?php
/**
 * Antigravity 适配器（Google Cloud Code / Gemini 内部端点）
 * 登录方式：OAUTH_LOOPBACK —— Google OAuth 授权码换取 token
 * 主机：cloudcode-pa.googleapis.com / oauth2.googleapis.com
 *
 * 注意：上游为 Gemini 风格响应（candidates[].content.parts[].text），
 *       此处单独解析并转换为 OpenAI 风格增量。
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
use App\Repositories\ProviderRepository;
use App\Repositories\ProxyRepository;
use App\Support\HttpClient;
use App\Support\Logger;

class AntigravityProvider extends AbstractProvider
{
    private const HOST_API = 'https://cloudcode-pa.googleapis.com';
    private const HOST_OAUTH = 'https://oauth2.googleapis.com';
    private const HOST_USERINFO = 'https://www.googleapis.com';

    /**
     * Google OAuth 客户端凭据：仅从环境变量读取。
     * 本仓库不内置任何客户端 ID / 密钥，未配置时给出明确提示。
     */
    private static function clientId(): string
    {
        $value = (string) env('AIGW_ANTIGRAVITY_CLIENT_ID', '');
        if ($value === '') {
            throw new \RuntimeException('未配置 AIGW_ANTIGRAVITY_CLIENT_ID，请在 .env 中填写 Google OAuth 客户端 ID');
        }
        return $value;
    }

    private static function clientSecret(): string
    {
        $value = (string) env('AIGW_ANTIGRAVITY_CLIENT_SECRET', '');
        if ($value === '') {
            throw new \RuntimeException('未配置 AIGW_ANTIGRAVITY_CLIENT_SECRET，请在 .env 中填写 Google OAuth 客户端密钥');
        }
        return $value;
    }

    /**
     * 桌面客户端在 Google 侧登记的回环回调地址（还原 LV1/k->d / LZ1/c->q）。
     * 该地址在 Google 控制台不可随意变更，必须与授权链接、token 交换两处完全一致，
     * 否则上游返回 redirect_uri_mismatch。
     */
    private const DEFAULT_REDIRECT_URI = 'http://localhost:51121/oauth-callback';

    private static function redirectUri(): string
    {
        return (string) env('AIGW_ANTIGRAVITY_REDIRECT_URI', self::DEFAULT_REDIRECT_URI);
    }

    private const SCOPES = 'https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/cloud-platform https://www.googleapis.com/auth/cclog https://www.googleapis.com/auth/experimentsandconfigs';

    private const UA = 'antigravity/1.15.8 windows/amd64';

    /** 还原 LZ1/c->r 固定请求头 */
    private const API_CLIENT = 'google-cloud-sdk vscode_cloudshelleditor/0.1';
    private const CLIENT_METADATA = '{"ideType":"ANTIGRAVITY","platform":"MACOS","pluginType":"GEMINI"}';

    /**
     * Antigravity 通用请求头（严格还原 LZ1/c->r 的 setRequestProperty 序列）
     *
     * @return array<string,string>
     */
    private function baseHeaders(): array
    {
        return [
            'Accept-Encoding'   => 'identity',
            'Accept'            => 'text/event-stream, application/json',
            'User-Agent'        => self::UA,
            'X-Goog-Api-Client' => self::API_CLIENT,
            'Client-Metadata'   => self::CLIENT_METADATA,
        ];
    }

    /**
     * 上游模型目录端点 —— Cloud Code Assist 的 `fetchAvailableModels`。
     *
     * `POST {HOST_API}/v1internal:fetchAvailableModels`，body `{"project":<id>}`，
     * 返回 `{"models":{ "<id>": {displayName, model, isInternal, quotaInfo:{remainingFraction, resetTime}} }}`。
     *
     * ⚠️ 与 ZCode/Qoder 同成因（见 docs/qoder与zcode上游协议.md §十五）：
     * 上游目录才是「有哪些模型」的权威，硬编码常量只能作兜底元数据。
     * 上游会夹带内部模型（`isInternal=true`、`displayName` 为空、`chat_20706` 之类占位），
     * 必须过滤，否则客户端会看到一堆不可选条目。
     */
    private const MODEL_LIST_PATH = '/v1internal:fetchAvailableModels';

    /**
     * 兜底模型表 —— **仅在拉上游失败时使用**（权威已改为上游 `fetchAvailableModels`）。
     *
     * 结构：model_id => [显示名, 上下文窗口, 多模态, 推理]。
     * 与 ZCode 的 BUILTIN_MODELS 定位一致：上游目录只给 id + displayName，
     * 上下文窗口等元数据上游不下发，由本表按 id 补齐；补不到的中性回退（见 catalogRow）。
     */
    private const BUILTIN_MODELS = [
        'gemini-3-pro'           => ['Gemini 3 Pro', 1048576, true, true],
        'gemini-3-pro-high'      => ['Gemini 3 Pro (High)', 1048576, true, true],
        'gemini-3-pro-low'       => ['Gemini 3 Pro (Low)', 1048576, true, true],
        'gemini-3-pro-image'     => ['Gemini 3 Pro Image', 1048576, true, false],
        'gemini-3-flash'         => ['Gemini 3 Flash', 1048576, true, true],
        'gemini-2.5-pro'         => ['Gemini 2.5 Pro', 1048576, true, true],
        'gemini-2.5-flash'       => ['Gemini 2.5 Flash', 1048576, true, true],
        'gemini-2.0-flash'       => ['Gemini 2.0 Flash', 1048576, true, false],
        'claude-opus-4-5-thinking' => ['Claude Opus 4.5 (Thinking)', 204800, true, true],
        'claude-opus-4-5'        => ['Claude Opus 4.5', 204800, true, true],
    ];

    /**
     * 上游 `fetchAvailableModels` 会下发的**非用户可见**模型特征（上游标注 + 历史黑名单）。
     * 命中即剔除，避免客户端选到内部/占位模型（对齐 openusage 插件的三层过滤）。
     */
    private const INTERNAL_MODEL_PATTERNS = [
        '/^chat_\d+$/i',                 // chat_20706 之类内部客服模型
        '/^tab_[a-z0-9_]+$/i',           // tab_flash_lite_preview 等补全模型
        '/preview$/i',                   // 预览占位
        '/^gemini-1\./i',                // Gemini 1.x 已下线
        '/^gemini-2\.0-flash-exp/i',     // 实验占位
    ];

    /** 进程内模型目录缓存（uid => [id => displayName]） */
    private array $modelListCache = [];

    public function __construct(
        ProxyRepository $proxyRepository,
        private AccountRepository $accountRepository,
        private ProviderRepository $providerRepository,
    ) {
        parent::__construct($proxyRepository);
    }

    public function id(): string
    {
        return 'antigravity';
    }

    public function displayName(): string
    {
        return 'Antigravity';
    }

    /**
     * Gemini 通道把内容经 flattenContent() 压成纯文本，图片块会被丢弃 ——
     * 与其静默丢图，不如让调用方在请求前拿到明确报错。
     */
    public function supportsImageInput(): bool
    {
        return false;
    }

    public function loginType(): string
    {
        return 'OAUTH_LOOPBACK';
    }

    public function hosts(): array
    {
        return $this->providerRepository->find('antigravity')['hosts']
            ?? ['cloudcode-pa.googleapis.com', 'oauth2.googleapis.com'];
    }

    // ---------------------------------------------------------
    // 对话：Gemini 风格 streamGenerateContent
    // ---------------------------------------------------------

    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        $token = (string) $account->cred('accessToken', '');
        if ($token === '') {
            throw new \RuntimeException('Antigravity 账号缺少 accessToken，请重新登录');
        }

        $url = self::HOST_API . '/v1internal:streamGenerateContent?alt=sse';

        $headers = $this->baseHeaders() + [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ];

        $payload = $this->buildGeminiPayload($account, $request);

        $chunk = new Chunk(model: $request->model);

        $client = $this->http()->withHeaders($headers)->timeout(HttpClient::STREAM_SAFE_TIMEOUT, 20);

        $info = $client->stream('POST', $url, json_encode($payload, JSON_UNESCAPED_UNICODE), function (string $data) use (&$chunk, $onDelta) {
            if ($data === '[DONE]') {
                $chunk->completed = true;
                return false;
            }

            $decoded = json_decode($data, true);
            if (!is_array($decoded)) {
                return null;
            }

            // 上游 SSE 外层包裹 response，缺失时回退原始对象（还原 LV1/g;->l）
            if (isset($decoded['response']) && is_array($decoded['response'])) {
                $decoded = $decoded['response'];
            }

            $chunk->raw = $decoded;

            $candidates = $decoded['candidates'] ?? [];
            foreach ((array) $candidates as $candidate) {
                $parts = $candidate['content']['parts'] ?? [];
                foreach ((array) $parts as $part) {
                    $text = $part['text'] ?? '';
                    if ($text === '' || !is_string($text)) {
                        continue;
                    }
                    // 思考内容单独记录，不污染正文
                    // ⚠️ 累积到 Chunk::reasoningContent（独立字段），不能写进 $chunk->raw：
                    // 上面 `$chunk->raw = $decoded;` 每帧整体覆盖 $raw，写进去会被下一帧冲掉。
                    if (!empty($part['thought'])) {
                        $chunk->reasoningContent .= $text;
                        // 思考分片同样下发（保真）：此前只留痕不回调 →
                        // 纯思考阶段客户端零帧可选，表现为白屏。
                        $rRaw = $decoded;
                        $rRaw['_aigw_kind'] = 'reasoning';
                        if ($onDelta('', $rRaw) === false) {
                            return false;
                        }
                        continue;
                    }

                    $chunk->content .= $text;
                    // ⚠️ 不带 `_aigw_raw`：Gemini 形态（`candidates[].content.parts[]`）
                    // 无 `choices` 字段，原样回显给 OpenAI 客户端会解析失败，
                    // 必须走 Controller 的归一化分支（详见其 `$rawLine` 判据注释）。
                    if ($onDelta($text, $decoded) === false) {
                        return false;
                    }
                }

                $finish = (string) ($candidate['finishReason'] ?? '');
                if ($finish !== '' && $finish !== 'FINISH_REASON_UNSPECIFIED') {
                    $chunk->finishReason = self::mapFinishReason($finish);
                    $chunk->completed = true;
                }
            }

            // usageMetadata
            $usage = $decoded['usageMetadata'] ?? null;
            if (is_array($usage)) {
                $chunk->promptTokens = (int) ($usage['promptTokenCount'] ?? $chunk->promptTokens);
                $chunk->completionTokens = (int) ($usage['candidatesTokenCount'] ?? $chunk->completionTokens);
                $chunk->totalTokens = (int) ($usage['totalTokenCount'] ?? 0);
            }

            return null;
        });

        if (!empty($info['error'])) {
            Logger::warn('Antigravity 流式请求异常', $info);
        }

        // 传输层失败必须抛出，不能降级成「空正文」（否则被网关注解成出口层抑制）。
        // ⚠️ 排除主动中断（errno=23 由回调返回 false 触发，属预期）。
        if (!empty($info['errno']) && empty($info['aborted']) && $chunk->content === '') {
            throw new \RuntimeException(sprintf(
                'Antigravity 请求失败（传输层）：%s',
                (string) $info['error']
            ));
        }

        if (!$chunk->completed && $chunk->finishReason === '' && $chunk->content !== '') {
            $chunk->finishReason = 'stop';
        }

        return $chunk;
    }

    /**
     * Gemini finishReason → OpenAI finish_reason（还原 LV1/g;->l 分支）
     */
    private static function mapFinishReason(string $reason): string
    {
        return match ($reason) {
            'MAX_TOKENS'                                                      => 'length',
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT'         => 'content_filter',
            default                                                           => 'stop',
        };
    }

    /**
     * OpenAI 消息 → Antigravity 请求体。
     *
     * 与 APK LZ1/c;->b 对齐：外层为包装结构
     *   { project, model, userAgent:"antigravity", requestType:"agent", requestId:"agent-<uuid24>", request:{...} }
     * 内层 request 为 Gemini 风格：
     *   contents / systemInstruction / generationConfig / tools(functionDeclarations)
     * system、developer 角色统一归入 systemInstruction。
     */
    private function buildGeminiPayload(Account $account, ChatRequest $request): array
    {
        $contents = [];
        $systemTexts = [];

        foreach ($request->messages as $message) {
            $role = (string) ($message['role'] ?? 'user');

            $content = $message['content'] ?? '';
            $text = is_array($content) ? $request->flattenContent($content) : (string) $content;
            if ($text === '') {
                continue;
            }

            // 上游将 system / developer 提取到 systemInstruction
            if ($role === 'system' || $role === 'developer') {
                $systemTexts[] = $text;
                continue;
            }

            $contents[] = [
                'role'  => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $text]],
            ];
        }

        $inner = ['contents' => $contents];

        $system = trim(implode("\n\n", array_filter($systemTexts, static fn ($t) => trim((string) $t) !== '')));
        if ($system !== '') {
            $inner['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $generationConfig = [];
        if ($request->maxTokens !== null) {
            $generationConfig['maxOutputTokens'] = (int) $request->maxTokens;
        }
        if ($request->temperature !== null) {
            $generationConfig['temperature'] = $request->temperature;
        }
        if ($request->topP !== null) {
            $generationConfig['topP'] = $request->topP;
        }
        $stop = $request->extra['stop'] ?? null;
        if ($stop !== null && $stop !== '' && $stop !== []) {
            $generationConfig['stopSequences'] = is_array($stop) ? array_values($stop) : [(string) $stop];
        }
        if (!empty($generationConfig)) {
            $inner['generationConfig'] = $generationConfig;
        }

        $tools = $this->buildFunctionDeclarations($request);
        if (!empty($tools)) {
            $inner['tools'] = $tools;
        }

        $requestId = 'agent-' . substr(str_replace('-', '', uuid()), 0, 24);

        return [
            'project'     => (string) $account->cred('projectId', ''),
            'model'       => $request->model,
            'userAgent'   => 'antigravity',
            'requestType' => 'agent',
            'requestId'   => $requestId,
            'request'     => $inner,
        ];
    }

    /**
     * OpenAI tools → Gemini functionDeclarations（仅保留函数型工具）
     */
    private function buildFunctionDeclarations(ChatRequest $request): array
    {
        $declarations = [];
        foreach ($request->tools as $tool) {
            $fn = $tool['function'] ?? $tool;
            if (!is_array($fn)) {
                continue;
            }
            $name = (string) ($fn['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $decl = ['name' => $name];
            if (!empty($fn['description'])) {
                $decl['description'] = (string) $fn['description'];
            }
            $params = $fn['parameters'] ?? null;
            if (is_array($params) && !empty($params)) {
                $decl['parameters'] = $params;
            }
            $declarations[] = $decl;
        }

        if (empty($declarations)) {
            return [];
        }

        return [['functionDeclarations' => $declarations]];
    }

    // ---------------------------------------------------------
    // 模型
    // ---------------------------------------------------------

    public function models(Account $account): array
    {
        // 上游优先：`POST /v1internal:fetchAvailableModels` 是「有哪些模型」的权威
        $upstream = $this->fetchUpstreamModelList($account);
        if (!empty($upstream)) {
            return array_keys($upstream);
        }

        // 回退：上游不可达（无凭据 / 网络）时用内置表，保证面板非空
        return array_keys(self::BUILTIN_MODELS);
    }

    /**
     * 拉取上游真实模型目录：`POST {HOST_API}/v1internal:fetchAvailableModels`。
     *
     * 返回 `id => displayName`；**任何失败一律返回空数组**，由调用方回退内置表 ——
     * 绝不让一次上游抖动把整个模型列表清空（同 ZCode/Qoder 设计）。
     *
     * @return array<string,string>
     */
    private function fetchUpstreamModelList(Account $account): array
    {
        $cacheKey = $account->uid;
        if (isset($this->modelListCache[$cacheKey])) {
            return $this->modelListCache[$cacheKey];
        }

        $token = (string) $account->cred('accessToken', '');
        if ($token === '') {
            return [];
        }

        try {
            $res = $this->http()
                ->withHeaders($this->baseHeaders() + [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ])
                ->timeout(30, 10)
                ->postJson(self::HOST_API . self::MODEL_LIST_PATH, [
                    'project' => (string) $account->cred('projectId', ''),
                ]);
        } catch (\Throwable $e) {
            Logger::warn('Antigravity 模型目录拉取异常', ['uid' => $account->uid, 'error' => $e->getMessage()]);
            return [];
        }

        if (!$res->ok()) {
            Logger::warn('Antigravity 模型目录拉取失败', ['uid' => $account->uid, 'status' => $res->status()]);
            return [];
        }

        $json = $res->json();
        $rows = is_array($json) ? ($json['models'] ?? null) : null;

        // ⚠️ HTTP 200 也可能是错误信封；必须校验真拿到了 models 映射，不能只看状态码。
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $out = [];
        foreach ($rows as $id => $meta) {
            $id = trim((string) $id);
            if ($id === '' || isset($out[$id])) {
                continue;
            }

            $meta = is_array($meta) ? $meta : [];

            // 过滤非用户可见模型：上游 isInternal 标记 + displayName 为空 + 黑名单占位
            if (!empty($meta['isInternal'])) {
                continue;
            }
            $display = trim((string) ($meta['displayName'] ?? $meta['display_name'] ?? ''));
            if ($display === '') {
                continue;
            }
            if ($this->isInternalModelId($id)) {
                continue;
            }

            $out[$id] = $display;
        }

        if ($out !== []) {
            $this->modelListCache[$cacheKey] = $out;
        }

        return $out;
    }

    /** 上游目录里的内部/占位模型 id 判定（对齐 openusage 插件的黑名单层） */
    private function isInternalModelId(string $id): bool
    {
        foreach (self::INTERNAL_MODEL_PATTERNS as $pattern) {
            if (preg_match($pattern, $id) === 1) {
                return true;
            }
        }

        return false;
    }

    public function modelCatalog(Account $account): array
    {
        // 上游优先：`fetchAvailableModels` 带 displayName，是真·权威
        $upstream = $this->fetchUpstreamModelList($account);

        if (!empty($upstream)) {
            $out = [];
            foreach ($upstream as $id => $display) {
                $out[] = $this->catalogRow($id, $display);
            }

            return $out;
        }

        // 回退：上游不可达 → 内置表
        $out = [];
        foreach (array_keys(self::BUILTIN_MODELS) as $modelId) {
            $out[] = $this->catalogRow($modelId);
        }

        return $out;
    }

    /**
     * 组装目录行：元数据按 id 查内置表补齐（上游只给 id + displayName）。
     *
     * 查不到（上游新出的型号）时**中性回退**：显示名用上游值、能力只给 `stream`、
     * `context_window=null`。宁可少报能力也不臆造 —— 上报了 vision 但实际不支持，
     * 客户端会把图片发过来直接拒收。
     *
     * @return array<string,mixed>
     */
    private function catalogRow(string $modelId, ?string $display = null): array
    {
        $meta = self::BUILTIN_MODELS[$modelId] ?? null;

        $display    = $display !== null && $display !== '' ? $display : (string) ($meta[0] ?? $modelId);
        $ctx        = $meta[1] ?? null;
        $multimodal = (bool) ($meta[2] ?? false);
        $reasoning  = (bool) ($meta[3] ?? false);

        $capabilities = ['stream'];
        if ($multimodal) {
            $capabilities[] = 'vision';
        }
        if ($reasoning) {
            $capabilities[] = 'reasoning';
        }

        return [
            'model_id'         => $modelId,
            'display_name'     => $display,
            'rate'             => null,
            'rate_source'      => '',
            'fee_level'        => null,
            'entitlement_cost' => null,
            'context_window'   => $ctx,
            'max_tokens'       => null,
            'multimodal'       => $multimodal,
            'reasoning'        => $reasoning,
            'capabilities'     => $capabilities,
        ];
    }

    // ---------------------------------------------------------
    // 额度：Antigravity 无公开额度接口，解析 403/配额错误即可
    // ---------------------------------------------------------

    public function credits(Account $account): CreditInfo
    {
        $expiresAt = (int) $account->cred('expiresAt', 0);
        if ($expiresAt > 0 && $expiresAt < now_ms()) {
            return CreditInfo::failure('凭据已过期，请刷新或重新登录');
        }

        return new CreditInfo(
            known: true,
            balance: null,
            detail: 'Antigravity 由 Google 账号配额控制，无独立额度接口'
        );
    }

    // ---------------------------------------------------------
    // 动作：连通性自检
    // ---------------------------------------------------------

    public function action(Account $account, string $action, array $params = []): array
    {
        if ($action === 'test' || $action === 'connectivity') {
            $token = (string) $account->cred('accessToken', '');
            if ($token === '') {
                return ['ok' => false, 'message' => '缺少 accessToken，请重新登录', 'data' => null];
            }

            $expiresAt = (int) $account->cred('expiresAt', 0);
            if ($expiresAt > 0 && $expiresAt < now_ms()) {
                return ['ok' => false, 'message' => '凭据已过期，请重新登录', 'data' => null];
            }

            try {
                $res = $this->http()
                    ->withHeaders($this->baseHeaders() + ['Authorization' => 'Bearer ' . $token])
                    ->timeout(20)
                    ->get(self::HOST_USERINFO . '/oauth2/v2/userinfo?alt=json');
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => '连通失败：' . $e->getMessage(), 'data' => null];
            }

            if ($res->status() === 401 || $res->status() === 403) {
                return ['ok' => false, 'message' => '登录已失效，请重新登录', 'data' => null];
            }
            if (!$res->ok()) {
                return ['ok' => false, 'message' => '连通失败（HTTP ' . $res->status() . '）', 'data' => null];
            }

            $info = is_array($res->json()) ? $res->json() : [];
            $email = (string) ($info['email'] ?? '');
            $models = $this->models($account);

            return [
                'ok'      => true,
                'message' => '连通正常' . ($email !== '' ? '（' . $email . '）' : '') . '，可用模型 ' . count($models) . ' 个',
                'data'    => ['models' => $models, 'count' => count($models), 'email' => $email],
            ];
        }

        return parent::action($account, $action, $params);
    }

    // ---------------------------------------------------------
    // Token 刷新（Google OAuth）
    // ---------------------------------------------------------

    public function refreshToken(Account $account): array
    {
        $expiresAt = (int) $account->cred('expiresAt', 0);
        if ($expiresAt > 0 && $expiresAt - 600000 > now_ms()) {
            return [];
        }

        $refreshToken = (string) $account->cred('refreshToken', '');
        if ($refreshToken === '') {
            return [];
        }

        $res = $this->http()->withHeaders($this->baseHeaders())->timeout(30)->postForm(self::HOST_OAUTH . '/token', [
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        if (!$res->ok()) {
            Logger::warn('Antigravity 刷新 Token 失败', ['uid' => $account->uid, 'status' => $res->status()]);
            return [];
        }

        $data = $res->json();
        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            return [];
        }

        return [
            'accessToken'  => $token,
            'refreshToken' => (string) ($data['refresh_token'] ?? $refreshToken),
            'expiresAt'    => now_ms() + ((int) ($data['expires_in'] ?? 3600)) * 1000,
        ];
    }

    // ---------------------------------------------------------
    // 登录：Google OAuth 授权码
    // ---------------------------------------------------------

    /**
     * 构造 Google 授权链接（完整还原 LV1/k->d）
     *
     * 你自行申请的 Google OAuth 客户端必须是「桌面应用」类型，
     * 并在 Google Cloud Console 登记回环回调 http://localhost:51121/oauth-callback，其它 redirect_uri 会
     * 报 redirect_uri_mismatch。因此这里必须原样使用该回环地址：由本机 51121 端口的回调桥接
     * 承接后转发到本应用 /authorize（携带 ?provider=antigravity），再落到本适配器。
     */
    public function startLogin(array $params = []): LoginSession
    {
        $sessionId = uuid();
        $callback = self::redirectUri();

        $this->saveLoginSession($sessionId, ['created_at' => now_ms()]);

        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => self::clientId(),
            'redirect_uri'  => $callback,
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            // 带上 provider 前缀，回调页才能从 state 认出是哪个供应商
            'state'         => $this->id() . ':' . $sessionId,
        ]);

        // 落盘保存授权链接：轮询时回显，避免前端轮询把已生成的链接覆盖掉
        $this->updateLoginSession($sessionId, ['auth_url' => $authUrl]);

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'waiting',
            message: '请在浏览器中完成 Google 账号授权，授权后会自动回跳',
            authUrl: $authUrl,
            intervalSeconds: 3,
            expiresInSeconds: 900,
            extra: ['callback' => $callback]
        );
    }

    public function pollLogin(string $sessionId, array $params = []): LoginSession
    {
        $session = $this->loadLoginSession($sessionId);

        if ($session === null) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'expired',
                message: '授权会话已过期，请重新发起登录',
                error: 'session_expired'
            );
        }

        $state = (string) ($session['state'] ?? 'pending');

        if ($state === 'success') {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'success',
                message: '授权成功，账号已加入账号池',
                accountUid: (string) ($session['account_uid'] ?? ''),
                nickname: (string) ($session['nickname'] ?? '')
            );
        }

        if ($state === 'failed') {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'failed',
                message: (string) ($session['message'] ?? '授权失败'),
                error: (string) ($session['error'] ?? 'login_failed')
            );
        }

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'waiting',
            message: '等待授权完成…',
            authUrl: (string) ($session['auth_url'] ?? '')
        );
    }

    public function handleCallback(array $query): array
    {
        $code = (string) ($query['code'] ?? '');
        // 授权链接里的 state 带 provider 前缀（antigravity:xxx），这里还原成会话 ID
        $state = (string) ($query['state'] ?? '');
        if (str_starts_with($state, $this->id() . ':')) {
            $state = substr($state, strlen($this->id()) + 1);
        }
        $error = (string) ($query['error'] ?? '');

        if ($error !== '') {
            $message = '授权被拒绝：' . $error;
            if ($state !== '') {
                $this->updateLoginSession($state, ['state' => 'failed', 'error' => $error, 'message' => $message]);
            }
            return ['ok' => false, 'message' => $message];
        }

        if ($code === '') {
            return ['ok' => false, 'message' => '回调缺少 code 参数'];
        }

        // state 必须对应一个存在且未被消费的登录会话，否则视为非法/重放回调。
        $session = $state !== '' ? $this->loadLoginSession($state) : null;
        if ($session === null) {
            return ['ok' => false, 'message' => '授权会话不存在或已失效，请重新发起登录'];
        }
        if (($session['state'] ?? '') === 'consumed') {
            return ['ok' => false, 'message' => '该授权回调已被使用，请重新发起登录'];
        }

        // 立即标记为已消费，防止同一 code 被反复提交。
        $this->updateLoginSession($state, ['state' => 'consumed']);

        // code 换 token 时 redirect_uri 必须与授权链接完全一致（回环地址），否则上游同样拒绝。
        $res = $this->http()->withHeaders($this->baseHeaders())->timeout(30)->postForm(self::HOST_OAUTH . '/token', [
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'code'          => $code,
            'redirect_uri'  => self::redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);

        if (!$res->ok()) {
            $message = 'Token 交换失败：' . $this->extractMessage($res->body());
            if ($state !== '') {
                $this->updateLoginSession($state, ['state' => 'failed', 'error' => 'exchange_failed', 'message' => $message]);
            }
            return ['ok' => false, 'message' => $message];
        }

        $token = $res->json();
        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            return ['ok' => false, 'message' => '上游未返回 access_token'];
        }

        // 拉取用户邮箱（还原 LZ1/c->q：oauth2/v2/userinfo?alt=json）
        $profile = $this->http()
            ->withHeaders($this->baseHeaders() + ['Authorization' => 'Bearer ' . $accessToken])
            ->timeout(20)
            ->get(self::HOST_USERINFO . '/oauth2/v2/userinfo?alt=json');

        $email = '';
        $avatar = '';
        if ($profile->ok()) {
            $info = $profile->json();
            $email = (string) ($info['email'] ?? '');
            $avatar = (string) ($info['picture'] ?? '');
        }

        $uid = $email !== '' ? $email : ('ag_' . substr(hash('sha256', $accessToken), 0, 16));

        // 还原 LZ1/c->q：调用 loadCodeAssist 获取 projectId
        $projectId = $this->loadProjectId($accessToken);

        $accountId = $this->accountRepository->upsertCredential(
            $this->id(),
            $uid,
            [
                'accessToken'  => $accessToken,
                'refreshToken' => (string) ($token['refresh_token'] ?? ''),
                'expiresAt'    => now_ms() + ((int) ($token['expires_in'] ?? 3600)) * 1000,
                'email'        => $email,
                'projectId'    => $projectId,
            ],
            $avatar !== '' ? ['logged_in_at' => now_ms(), 'avatar' => $avatar] : ['logged_in_at' => now_ms()],
            $email !== '' ? $email : ('Antigravity-' . substr($uid, 0, 8))
        );

        if ($state !== '') {
            $this->updateLoginSession($state, [
                'state'       => 'success',
                'account_uid' => $uid,
                'nickname'    => $email,
                'account_id'  => $accountId,
            ]);
        }

        return ['ok' => true, 'message' => 'Antigravity 授权成功', 'account_uid' => $uid];
    }

    // ---------------------------------------------------------
    // loadCodeAssist：还原 LZ1/c->q，取 cloudaicompanionProject / project / projectId
    // ---------------------------------------------------------

    private function loadProjectId(string $accessToken): string
    {
        if ($accessToken === '') {
            return '';
        }

        $res = $this->http()
            ->withHeaders($this->baseHeaders() + [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ])
            ->timeout(60)
            ->postJson(self::HOST_API . '/v1internal:loadCodeAssist', [
                'metadata' => [
                    'ideType'    => 'IDE_UNSPECIFIED',
                    'platform'   => 'PLATFORM_UNSPECIFIED',
                    'pluginType' => 'GEMINI',
                ],
            ]);

        if (!$res->ok()) {
            Logger::warn('Antigravity loadCodeAssist 失败', ['status' => $res->status()]);
            return '';
        }

        $data = $res->json();
        if (!is_array($data)) {
            return '';
        }

        // 依次尝试 cloudaicompanionProject / project / projectId
        foreach (['cloudaicompanionProject', 'project', 'projectId'] as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_array($value) && isset($value['id']) && is_string($value['id'])) {
                return (string) $value['id'];
            }
        }

        return '';
    }

    // ---------------------------------------------------------
    // 错误分类：Gemini 配额错误特征
    // ---------------------------------------------------------

    public function parseError(int $status, string $body): ?array
    {
        $lower = strtolower($body);

        if (str_contains($lower, 'resource_exhausted') || str_contains($lower, 'quota')) {
            return ['code' => \App\Provider\ErrorCategory::QUOTA, 'message' => $this->extractMessage($body)];
        }
        if (str_contains($lower, 'permission_denied') || str_contains($lower, 'unauthenticated')) {
            return ['code' => \App\Provider\ErrorCategory::SESSION_DEAD, 'message' => $this->extractMessage($body)];
        }

        return parent::parseError($status, $body);
    }

    // ---------------------------------------------------------
    // 登录会话临时存储
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
            $this->sessionDir() . '/' . $sessionId . '.json',
            json_encode($data, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function loadLoginSession(string $sessionId): ?array
    {
        $file = $this->sessionDir() . '/' . preg_replace('/[^a-zA-Z0-9\-]/', '', $sessionId) . '.json';
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function updateLoginSession(string $sessionId, array $patch): void
    {
        $current = $this->loadLoginSession($sessionId) ?? [];
        $this->saveLoginSession($sessionId, array_merge($current, $patch));
    }
}