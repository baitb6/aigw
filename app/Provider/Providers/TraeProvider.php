<?php
/**
 * Trae 适配器（字节跳动 Trae IDE）
 *
 * 协议完全还原自官方客户端 1.apk（dev.aigw.app v0.1.18）反编译结果：
 *   - 授权页：LV1/k->d   → https://www.trae.cn/authorization?...
 *   - 指纹：  Ld2/x->c/e/b → machineId(64 hex) / deviceId(15 位数字) / loginTraceId(16)
 *   - 换票：  LC1/b->s   → POST {HOST_OAUTH}/cloudide/api/v3/trae/oauth/ExchangeToken
 *   - 用户：  LC1/b->u   → POST {HOST_OAUTH}/cloudide/api/v3/trae/GetUserInfo
 *   - 签到/额度：Ld2/t   → POST {HOST_API}/trae/api/v2/ug/checkin_credits/*
 *   - 对话：  Lx/t->y   → POST {HOST_CHAT}/api/agent/v3/llm_utils_chat（命名事件 SSE）
 *
 * 同一适配器按 provider_key 区分区域（ProviderManager::withKey 注入）：
 *   - 'trae'      → 国服：api.trae.com.cn / api.trae.cn / trae-api-cn.mchost.guru / www.trae.cn
 *   - 'trae_intl' → 国际版：api-sg-central.trae.ai / a0ai-api-sg.byteintlapi.com / www.trae.ai
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
use App\Support\ToolCalls;

class TraeProvider extends AbstractProvider
{
    /** OAuth 客户端凭据（来自原 App 内置常量） */
    private const CLIENT_ID = 'en1oxy7wnw8j9n';

    /** 客户端固定 App-Id（来自 LV1/t->b0 请求头） */
    private const APP_ID = '6eefa01c-1036-4c7e-9ca5-d891f63bfcd8';

    /** 国服主机（RefreshToken / GetUserInfo：api.trae.com.cn；签到 / 额度：api.trae.cn） */
    private const HOST_OAUTH_CN = 'https://api.trae.com.cn';
    private const HOST_API_CN = 'https://api.trae.cn';
    private const HOST_CHAT_CN = 'https://trae-api-cn.mchost.guru';
    private const HOST_WEB_CN = 'https://www.trae.cn';

    /** 国际版主机（Token 交换 / 用户信息 / 额度：api-sg-central.trae.ai；对话：a0ai-api-sg.byteintlapi.com） */
    private const HOST_OAUTH_INTL = 'https://api-sg-central.trae.ai';
    private const HOST_API_INTL = 'https://api-sg-central.trae.ai';
    private const HOST_CHAT_INTL = 'https://a0ai-api-sg.byteintlapi.com';
    private const HOST_WEB_INTL = 'https://www.trae.ai';

    /** 供应商 key：'trae'（国服，默认 CN 主机） / 'trae_intl'（国际版，默认 SG 主机） */
    private const KEY_CN = 'trae';
    private const KEY_INTL = 'trae_intl';

    /** 当前适配器对应的供应商 key（由 ProviderManager 注入，默认国服） */
    protected string $providerKey = self::KEY_CN;

    /** 客户端版本信息（TraeVersion 构造：c=pluginVersion, d=deviceBrand, e=osVersion） */
    private const IDE_VERSION = '0.1.52';
    private const IDE_VERSION_CODE = '20260811';
    private const PLUGIN_VERSION = '2.3.62834';
    private const DEVICE_BRAND = '83DG';
    private const OS_VERSION = 'Windows 11 Pro';

    private const DEFAULT_MODEL = 'glm-5.2';

    /** 拉取模型/配置列表的固定请求体（还原 Lx/t->r） */
    private const DETAIL_PARAM_BODY = [
        'function'            => 'solo_work_lite',
        'config_names'        => null,
        'need_prompt'         => false,
        'current_config_info' => null,
        'poly_prompt'         => true,
        'mode_type'           => null,
        'agent_type'          => null,
    ];

    public function __construct(
        ProxyRepository $proxyRepository,
        private AccountRepository $accountRepository,
        private ProviderRepository $providerRepository,
    ) {
        parent::__construct($proxyRepository);
    }

    /**
     * ProviderManager 在注册时注入具体 key：
     * - 'trae'      → 国服（默认 CN 主机）
     * - 'trae_intl' → 国际版（默认 SG 主机）
     */
    public function withKey(string $key): static
    {
        $this->providerKey = $key !== '' ? $key : self::KEY_CN;

        return $this;
    }

    public function id(): string
    {
        return $this->providerKey;
    }

    public function displayName(): string
    {
        return $this->isIntlProvider() ? 'Trae 国际版' : 'Trae';
    }

    public function loginType(): string
    {
        return 'WEBVIEW_CALLBACK';
    }

    public function hosts(): array
    {
        $fallback = $this->isIntlProvider()
            ? ['a0ai-api-sg.byteintlapi.com', 'api-sg-central.trae.ai', 'www.trae.ai']
            : ['api.trae.cn', 'trae-api-cn.mchost.guru'];

        return $this->providerRepository->find($this->providerKey)['hosts'] ?? $fallback;
    }

    /** 是否为国际版供应商（默认主机/授权页走 trae.ai） */
    private function isIntlProvider(): bool
    {
        return $this->providerKey === self::KEY_INTL;
    }

    /** OAuth 主机（ExchangeToken / GetUserInfo） */
    private function hostOauth(): string
    {
        return $this->isIntlProvider() ? self::HOST_OAUTH_INTL : self::HOST_OAUTH_CN;
    }

    /** 额度 / 签到主机 */
    private function hostApi(): string
    {
        return $this->isIntlProvider() ? self::HOST_API_INTL : self::HOST_API_CN;
    }

    /** 对话 / 模型目录主机 */
    private function hostChat(): string
    {
        return $this->isIntlProvider() ? self::HOST_CHAT_INTL : self::HOST_CHAT_CN;
    }

    /** 授权页 / 网页主机 */
    private function hostWeb(): string
    {
        return $this->isIntlProvider() ? self::HOST_WEB_INTL : self::HOST_WEB_CN;
    }

    /** 凭据默认 domain（无 domain 时按供应商区域补默认值） */
    private function defaultDomain(): string
    {
        return $this->isIntlProvider() ? 'trae.ai' : 'trae.cn';
    }

    // ---------------------------------------------------------
    // 对话
    // ---------------------------------------------------------

    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        $token = (string) $account->cred('accessToken', '');
        if ($token === '') {
            throw new \RuntimeException('Trae 账号缺少 accessToken，请重新登录');
        }

        $payload = $this->buildChatPayload($request);
        $headers = $this->chatHeaders($account, 'text/event-stream');

        $url = rtrim($this->hostChat(), '/') . '/api/agent/v3/llm_utils_chat';

        return $this->parseTraeSse($url, $payload, $headers, $onDelta);
    }

    /**
     * 构造 Trae Agent 请求体（还原 Lx/t->y 的规范化逻辑）
     */
    private function buildChatPayload(ChatRequest $request): array
    {
        $payload = $request->toUpstreamPayload([
            'stream'   => true,
            'function' => 'solo_work_lite',
        ]);

        $model = trim((string) ($payload['model'] ?? ''));
        if ($model === '') {
            $model = self::DEFAULT_MODEL;
        }
        $payload['model'] = $model;
        $payload['config_name'] = $model;

        $payload['messages'] = $this->normalizeMessages(is_array($payload['messages'] ?? null) ? $payload['messages'] : []);

        if (array_key_exists('tool_choice', $payload)) {
            $choice = $payload['tool_choice'];
            if (is_string($choice)) {
                if (!in_array($choice, ['auto', 'required', 'none'], true)) {
                    unset($payload['tool_choice']);
                }
            } elseif (is_array($choice)) {
                $type = strtolower((string) ($choice['type'] ?? ''));
                if ($type === 'none') {
                    unset($payload['tool_choice'], $payload['tools'], $payload['functions']);
                } elseif ($type === 'auto') {
                    $payload['tool_choice'] = 'auto';
                } elseif ($type === 'function') {
                    $name = (string) ($choice['function']['name'] ?? $choice['name'] ?? '');
                    if ($name === '') {
                        unset($payload['tool_choice']);
                    } else {
                        $payload['tool_choice'] = ['type' => 'function', 'function' => ['name' => $name]];
                    }
                } else {
                    unset($payload['tool_choice']);
                }
            } else {
                unset($payload['tool_choice']);
            }
        }

        if (!empty($payload['tools']) && is_array($payload['tools'])) {
            $tools = [];
            foreach ($payload['tools'] as $tool) {
                if (!is_array($tool) || !isset($tool['function']) || !is_array($tool['function'])) {
                    continue;
                }
                if (isset($tool['function']['parameters']) && is_array($tool['function']['parameters'])) {
                    $tool['function']['parameters'] = json_encode($tool['function']['parameters'], JSON_UNESCAPED_UNICODE);
                }
                $tools[] = $tool;
            }
            if ($tools === []) {
                unset($payload['tools']);
            } else {
                $payload['tools'] = $tools;
            }
        }

        return $payload;
    }

    /**
     * 消息规范化（还原 Lx/t->y）：tool_calls 修正 + content 字符串转文本分片数组
     *
     * ⚠️ **入站方向与出站方向相反，这是最容易搞错的一处**（2026-10-01 实测确认）：
     *   - **出站**（Trae 上游 → 我们 → 客户端）：Trae 的工具帧用 `function_call`，
     *     必须归一成 OpenAI 的 `function`（见 `GatewayController::normalizeToolCalls()`）。
     *   - **入站**（客户端 → 我们 → Trae 上游）：发回上游时**必须还原成 `function_call`**。
     *
     * 上游的 protobuf 定义是 `ToolCall` 的 **field 4 = `FunctionCall`**，
     * 直接把 OpenAI 的 `function` 发过去会得到：
     *   `rpc error: code = 7 … *idecopilot.ToolCall read field 4 'FunctionCall'
     *    error: required field Name is not set`
     * A/B 实测（`tools/probe-trae-toolshape.php`）：
     *   发 `function`      → ✗ 上述 protobuf 解析错误
     *   发 `function_call` → ✓ 正常返回
     * 所以多轮 Agent 对话（assistant 带历史 tool_calls 回传）在这之前**必然失败**，
     * 表现为 `error_code=UPSTREAM` + 上述 protobuf 报错。
     *
     * @param array<int,mixed> $messages
     * @return array<int,mixed>
     */
    private function normalizeMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            if (($message['role'] ?? '') === 'assistant' && !empty($message['tool_calls']) && is_array($message['tool_calls'])) {
                $calls = [];
                foreach ($message['tool_calls'] as $call) {
                    if (!is_array($call)) {
                        continue;
                    }
                    // 取函数体：兼容 OpenAI 的 `function` 与 Trae 的 `function_call`，
                    // 两者取到后**统一发成上游要的 `function_call`**。
                    $fn = null;
                    if (isset($call['function']) && is_array($call['function'])) {
                        $fn = $call['function'];
                    } elseif (isset($call['function_call']) && is_array($call['function_call'])) {
                        $fn = $call['function_call'];
                    }
                    // ⚠️ `Name` 是上游 protobuf 的 **required** 字段：
                    //    缺 name 会报 "required field Name is not set"。
                    //    故取不到 name 的调用必须整条丢弃，不能带着空 name 发上去。
                    if ($fn === null || (string) ($fn['name'] ?? '') === '') {
                        continue;
                    }

                    // 归一为上游形状：function_call + 补齐上游必需字段
                    unset($call['function']);
                    $call['function_call'] = [
                        'name'      => (string) $fn['name'],
                        'arguments' => self::stringifyArguments($fn['arguments'] ?? null),
                    ];
                    // partial_arguments / namespace 是 Trae 自有字段，上游要求存在（可为 null）
                    $call['function_call'] += [
                        'partial_arguments' => $fn['partial_arguments'] ?? null,
                        'namespace'         => $fn['namespace'] ?? null,
                    ];
                    // id / type 必须非空（上游 ToolCall 的必填字段）
                    if (!is_string($call['id'] ?? null) || $call['id'] === '') {
                        continue;
                    }
                    if (!is_string($call['type'] ?? null) || $call['type'] === '') {
                        $call['type'] = 'function';
                    }
                    if (!isset($call['index'])) {
                        $call['index'] = count($calls);
                    }

                    $calls[] = $call;
                }
                $message['tool_calls'] = $calls;
                // 只有工具调用、没有正文时，content 仍需是非空的文本分片数组
                if (!isset($message['content'])) {
                    $message['content'] = '';
                }
            }

            if (isset($message['content']) && is_string($message['content'])) {
                $message['content'] = [['type' => 'text', 'text' => $message['content']]];
            }

            $out[] = $message;
        }

        return \App\Support\ToolMessageSequence::repair($out)[0];
    }

    /**
     * 工具参数归一成字符串：OpenAI 的 `arguments` 恒为 JSON 字符串，
     * 但有些客户端会直接发数组/对象（PHP 解码后是 array）。
     */
    private static function stringifyArguments(mixed $args): string
    {
        if (is_string($args)) {
            return $args;
        }
        if (is_array($args)) {
            return (string) json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($args === null) {
            return '{}';
        }
        return (string) $args;
    }

    /**
     * Trae 命名事件 SSE 解析（还原 Lx/t->q）
     *
     * 事件：output（response/reasoning_content/tool_calls）、done、error、
     *       token_usage、notify_usage（含 cn_credits_remain_info/ide_credits/work_credits）
     */
    private function parseTraeSse(string $url, array $payload, array $headers, callable $onDelta): Chunk
    {
        $chunk = new Chunk(model: (string) ($payload['model'] ?? ''));

        $client = $this->http()->withHeaders($headers)->timeout(HttpClient::STREAM_SAFE_TIMEOUT, 20);

        // 已下发的用量快照：`token_usage` 与 `notify_usage` 都会写同一批字段，
        // 用它保证同一份用量只下发一次（见 pushUsageFrame）。
        $sentUsage = [];

        $info = $client->streamEvents('POST', $url, json_encode($payload, JSON_UNESCAPED_UNICODE), function (string $event, string $data) use (&$chunk, $onDelta, &$sentUsage) {
            $decoded = json_decode($data, true);
            if (!is_array($decoded)) {
                return null;
            }
            $chunk->raw = $decoded;

            if ($event === 'output') {
                $text = '';
                $response = $decoded['response'] ?? '';
                if (is_string($response)) {
                    $text = $response;
                }
                // 推理内容单独留痕，**不污染正文**。
                //
                // ⚠️ 这里必须保留 reasoning_content：上游（Trae）在出口安全层抑制正文时，
                // `response` 会全程为空、内容只走 `reasoning_content`，最后仍以
                // `finish_reason=stop` 正常收尾。此时若直接丢弃 reasoning，排查时就只剩
                // 「空响应」一个表象，无法判断到底是"模型没接受注入"还是"接受后被出口层拦了"。
                // 实测正是靠它区分出「推理里写着'按系统配置该写'、正文却被抑制」（docs §10）。
                //
                // ⚠️ 累积到 Chunk::reasoningContent（独立字段），**不能写进 $chunk->raw**：
                // 上面第 306 行 `$chunk->raw = $decoded;` 每帧整体覆盖 $raw，写进去会被下一帧
                // 冲掉，最终只剩 done 帧（无 reasoning 字段）→ 留痕恒 0 字。踩过这个坑。
                $reasoning = $decoded['reasoning_content'] ?? '';
                if (is_string($reasoning) && $reasoning !== '') {
                    $chunk->reasoningContent .= $reasoning;
                    // ── 推理帧必须回调下发，不能只留痕 ──────────────────────────
                    // 上游在出口安全层抑制正文时 `response` 全程为空、内容只走
                    // `reasoning_content`，最后仍以 finish_reason=stop 正常收尾。
                    // 若只在本地累积而不回调，网关侧收不到任何信号 →
                    // 客户端拿到的是一段**没有任何输出的正常收尾**，
                    // 表现为「日志 SUCCESS、界面空白」，且用户完全看不到模型思维。
                    //
                    // 约定与 tool_calls 同机制：`_aigw_kind=reasoning`。
                    $rRaw = $decoded;
                    $rRaw['_aigw_kind'] = 'reasoning';
                    if ($onDelta('', $rRaw) === false) {
                        return false;
                    }
                }
                if (!empty($decoded['tool_calls']) && is_array($decoded['tool_calls'])) {
                    $chunk->toolCalls = array_merge($chunk->toolCalls, $decoded['tool_calls']);
                    // ⚠️ 必须经 $onDelta 转发给网关（同 AbstractProvider::parseOpenAiSse）。
                    // 只攒进 $chunk 而不回调 → 上游整轮只发工具调用（content 为空）时，
                    // 网关收不到任何信号，客户端只拿到空的 finish_reason 收尾帧
                    // → 表现为「日志 SUCCESS 却没有任何回答」（2026-09-30 修复）。
                    $toolRaw = $decoded;
                    $toolRaw['_aigw_kind'] = 'tool_calls';
                    if ($onDelta('', $toolRaw) === false) {
                        return false;
                    }
                }
                if ($text !== '') {
                    $chunk->content .= $text;
                    // ⚠️ 这里**不带** `_aigw_raw`：Trae 事件流是私有协议
                    // （帧形如 `{"response":…,"reasoning_content":…}`，**无 choices 字段**），
                    // 原样回显给 OpenAI 客户端会解析失败，必须走 Controller 的归一化分支。
                    // 带上它只会造出「看起来生效、实则永不命中」的死代码（本项目最忌讳）。
                    $contentRaw = $decoded;
                    unset($contentRaw['tool_calls'], $contentRaw['reasoning_content']);
                    $result = $onDelta($text, $contentRaw);
                    if ($result === false) {
                        return false;
                    }
                }
                return null;
            }

            if ($event === 'done') {
                $chunk->completed = true;
                $chunk->finishReason = (string) ($decoded['finish_reason'] ?? 'stop');
                return null;
            }

            if ($event === 'error') {
                // 保留完整错误 JSON，交由 parseError() 分类（可能含 code=6004 / 11115 与重置时间）
                $raw = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                throw new \RuntimeException(
                    $raw !== false
                        ? $raw
                        : (string) ($decoded['message'] ?? $decoded['error'] ?? '上游返回错误')
                );
            }

            if ($event === 'token_usage') {
                $chunk->promptTokens = (int) ($decoded['prompt_tokens'] ?? $decoded['input_tokens'] ?? $chunk->promptTokens);
                $chunk->completionTokens = (int) ($decoded['completion_tokens'] ?? $decoded['output_tokens'] ?? $chunk->completionTokens);
                $chunk->totalTokens = (int) ($decoded['total_tokens'] ?? $chunk->totalTokens);
                $chunk->reasoningTokens = (int) ($decoded['reasoning_tokens'] ?? $chunk->reasoningTokens);
                $this->applyCacheUsage($chunk, $decoded);
                if (!$this->pushUsageFrame($chunk, $onDelta, $sentUsage)) {
                    return false;
                }
                return null;
            }

            if ($event === 'notify_usage') {
                // 本帧是否真的带 token 数（而不是只剩「额度」数字）——只有带 token 数才下发给客户端。
                $sawTokens = false;
                foreach (['cn_credits_remain_info', 'ide_credits', 'work_credits'] as $key) {
                    if (!isset($decoded[$key])) {
                        continue;
                    }
                    $value = $decoded[$key];
                    if (is_array($value)) {
                        if (isset($value['prompt_tokens']) || isset($value['input_tokens'])
                            || isset($value['completion_tokens']) || isset($value['output_tokens'])) {
                            $sawTokens = true;
                        }
                        $chunk->promptTokens = (int) ($value['prompt_tokens'] ?? $value['input_tokens'] ?? $chunk->promptTokens);
                        $chunk->completionTokens = (int) ($value['completion_tokens'] ?? $value['output_tokens'] ?? $chunk->completionTokens);
                        $chunk->reasoningTokens = (int) ($value['reasoning_tokens'] ?? $chunk->reasoningTokens);
                        $this->applyCacheUsage($chunk, $value);
                    } elseif (is_numeric($value)) {
                        $chunk->totalTokens = (int) $value;
                    }
                }
                if ($sawTokens && !$this->pushUsageFrame($chunk, $onDelta, $sentUsage)) {
                    return false;
                }
                return null;
            }

            return null;
        });

        if (!empty($info['error'])) {
            Logger::warn('trae 流式请求异常', $info);
        }

        // 传输层失败必须抛出（与 AbstractProvider::parseOpenAiSse 的处置一致）：
        // 只记日志 + 返回空 Chunk 会让网关把 DNS/TLS/连接故障误判成
        // 「上游出口层抑制正文」，重采样到 CONTENT_BLOCKED 回 400。
        // ⚠️ 排除主动中断（errno=23 由回调返回 false 触发，属预期）。
        if (!empty($info['errno']) && empty($info['aborted'])) {
            throw new \RuntimeException(sprintf(
                'trae 请求失败（传输层）：%s',
                (string) $info['error']
            ));
        }

        if (empty($info['aborted']) && !$chunk->completed) {
            throw new \RuntimeException('trae 请求失败（传输层）：响应意外结束，未收到 done 事件，正文可能不完整');
        }

        // Trae 的 done 事件不带 `finish_reason`（被兜底成 stop），
        // 而这一轮明明下发了工具帧 → 必须归一为 `tool_calls`，
        // 否则客户端会按"普通回答"收尾、丢掉工具调用（「调用了却当没调用」）。
        $chunk->finishReason = ToolCalls::finishReason($chunk->finishReason, $chunk->toolCalls);

        return $chunk;
    }

    /**
     * 把当前累计的 token 用量下发给网关（→ 客户端底部用量条）。
     *
     * Trae 是私有命名事件协议：`token_usage` / `notify_usage` 帧形如
     * `{"prompt_tokens":…,"completion_tokens":…}`（**没有 choices**）。早期实现只把
     * 用量攒进 $chunk 供网关记账与调用日志，从不经 $onDelta 下发 —— 网关侧一帧
     * usage 都没收到，客户端 `rounds[].usage` 恒为空，底部只显示字数（用户报
     * 「新开的对话不显示输入输出 token」）。
     *
     * 约定与 reasoning / tool_calls 同一机制：`_aigw_kind = 'usage'`，由
     * GatewayController 组装成 OpenAI 规范的 usage-only chunk 回给客户端。
     * 不直接带 `_aigw_raw` —— Trae 帧没有 `choices`，保真回显会让客户端解析失败。
     *
     * ⚠️ `total_tokens` 用 prompt + completion 现算，**不取 $chunk->totalTokens**：
     * `notify_usage` 的数值分支会把「剩余额度」写进该字段，直接下发会把额度当 token 显示。
     *
     * @param array<string,int> $sent 已下发快照（按引用）：同一份用量只发一次，
     *                                 避免 `token_usage` 与 `notify_usage` 重复计数。
     * @return bool false = 调用方主动中断（合并后掐流），与其它回调一致。
     */
    private function pushUsageFrame(Chunk $chunk, callable $onDelta, array &$sent): bool
    {
        $usage = array_filter([
            'prompt_tokens'           => $chunk->promptTokens,
            'completion_tokens'       => $chunk->completionTokens,
            'total_tokens'            => $chunk->promptTokens + $chunk->completionTokens,
            'prompt_cache_hit_tokens' => $chunk->cacheReadTokens,
        ], static fn (int $value): bool => $value > 0);

        if ($usage === [] || $usage === $sent) {
            return true;
        }
        $sent = $usage;

        return $onDelta('', ['usage' => $usage, '_aigw_kind' => 'usage']) !== false;
    }

    /**
     * 构造 Trae 请求头（严格还原 LV1/t->b0）
     *
     * Authorization 用 "Cloud-IDE-JWT " 前缀（不是 Bearer），
     * 且同时下发 X-Cloudide-Token / X-Ide-Token 三份 Token。
     *
     * @return array<string,string>
     */
    private function chatHeaders(Account $account, string $accept): array
    {
        $token = (string) $account->cred('accessToken', '');
        $uid = (string) $account->uid;
        $machineId = (string) $account->cred('machineId', '');
        $deviceId = (string) $account->cred('deviceId', '');

        $headers = [
            'Content-Type'         => 'application/json',
            'Accept'               => $accept,
            'User-Agent'           => 'Trae/' . self::IDE_VERSION,
            'Authorization'        => 'Cloud-IDE-JWT ' . $token,
            'X-Cloudide-Token'     => $token,
            'X-Ide-Token'          => $token,
            'X-App-Id'             => self::APP_ID,
            'X-App-Version'        => 'default',
            'X-Ide-Version'        => self::IDE_VERSION,
            'X-Ide-Version-Code'   => self::IDE_VERSION_CODE,
            'X-App-Version-Code'   => self::IDE_VERSION_CODE,
            'X-Ide-Version-Type'   => 'stable',
            'X-Device-Type'        => 'windows',
            'X-OS-Version'         => self::OS_VERSION,
            'X-Device-Brand'       => self::DEVICE_BRAND,
            'Request-Traffic-Type' => 'prod',
        ];

        if ($uid !== '') {
            $headers['X-Uid'] = $uid;
        }
        if ($machineId !== '') {
            $headers['X-Machine-Id'] = $machineId;
        }
        if ($deviceId !== '') {
            $headers['x-device-id'] = $deviceId;
        }

        return $headers;
    }

    /**
     * 额度 / 签到接口请求头（LN1/h1(account, 5) 与对话同一套鉴权头）
     *
     * @return array<string,string>
     */
    private function apiHeaders(Account $account): array
    {
        return $this->chatHeaders($account, 'application/json');
    }

    // ---------------------------------------------------------
    // 模型列表（对应 {HOST_CHAT}/api/ide/v1/get_detail_param）
    // ---------------------------------------------------------

    /**
     * 拉取可用模型（还原 Lx/t->r）
     *
     * 上游 config_info_list 既包含可对话模型，也包含内部子代理
     * （computer_use_subagent / browser_use_subagent / file_search_agent / summary 等）
     * 与自定义模型占位项（custom_model_*）。这里按下列规则过滤：
     *   - config_switch !== false（未被停用）
     *   - is_invisible_to_user !== true（对用户可见）
     *   - display_config.display_name 非空（过滤占位项）
     * 取 config_name 作为模型 ID（与对话请求使用的标识一致）。
     *
     * @return list<string>
     */
    public function models(Account $account): array
    {
        return array_map(fn(array $m) => (string) $m['model_id'], $this->modelCatalog($account));
    }

    /**
     * 拉取模型目录（含上游倍率与详情）
     *
     * 倍率取自 display_contact_config.consumption_rate.data.rate
     * （上游原始 JSON 字符串，形如 {"consumption_rate":{"enable":true,"data":{"rate":0.78}}}）
     * 其余详情取自 display_config 与 model_detail_list。
     *
     * @return list<array<string,mixed>>
     */
    public function modelCatalog(Account $account): array
    {
        $token = (string) $account->cred('accessToken', '');
        if ($token === '') {
            return [];
        }

        try {
            $res = $this->http()
                ->withHeaders($this->chatHeaders($account, 'application/json'))
                ->timeout(30)
                ->postJson($this->hostChat() . '/api/ide/v1/get_detail_param', self::DETAIL_PARAM_BODY);
        } catch (\Throwable $e) {
            Logger::warn('Trae 拉取模型列表异常', ['error' => $e->getMessage()]);
            return [];
        }

        if (!$res->ok()) {
            Logger::warn('Trae 拉取模型列表失败', ['status' => $res->status()]);
            return [];
        }

        $data = $res->json();
        if (!is_array($data)) {
            return [];
        }

        $raw = $data['config_info_list'] ?? ($this->pickResult($data)['config_info_list'] ?? []);
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['config_name'] ?? ''));
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            if (($item['config_switch'] ?? true) === false) {
                continue;
            }
            if (($item['is_invisible_to_user'] ?? false) === true) {
                continue;
            }

            // 路由模板占位项必须剔除：`usage=custom_model` 的条目是 Trae 的
            // 多供应商路由模板（custom_models 里挂着一串 `供应商//模型` 候选），
            // 它**不是**可直接对话的模型。实测（2026-09-30）42 条目录里
            // `custom_model_*` 全部返回 `event:error {"code":4023,...}`；
            // 而真正的模型 usage 一律为 `chat_completion`。
            //
            // ⚠️ 不能按 `custom_model_` 前缀一刀切：同前缀的 `custom_model_gpt-5`/
            // `_gpt-6` 实测**可正常出流**，只是 display_name 为空、被下面的判据挡掉。
            // 因此只以 `usage` 这个结构化字段为准，前缀/文案都不能当判据。
            //
            // 历史坑：这些模板项的 display_name 多为空、本已过滤，唯独
            // `custom_model_gemini`（display_name=`Gemini-3.1-Pro-Preview`、
            // `is_invisible_to_user=false`）三关全过混进列表，用户一选就 4023，
            // 账号被误伤进冷却后只看到「账号池中没有可用账号」。
            if (strtolower(trim((string) ($item['usage'] ?? ''))) === 'custom_model') {
                continue;
            }

            $display = trim((string) ($item['display_config']['display_name'] ?? ''));
            if ($display === '') {
                continue;
            }
            $seen[$name] = true;

            // display_config：能力/等级
            $displayCfg = is_array($item['display_config'] ?? null) ? $item['display_config'] : [];
            $multimodal = !empty($displayCfg['multimodal']);
            $capability = (string) ($displayCfg['model_capability'] ?? '');
            $reasoning = $capability === 'reasoning_model';

            // display_contact_config：上游倍率（JSON 字符串）
            $rate = null;
            $rateSource = '';
            $multimodalCfg = false;
            $reasoningCfg = false;
            $contact = $item['display_contact_config'] ?? null;
            if (is_string($contact) && $contact !== '') {
                $decoded = json_decode($contact, true);
                if (is_array($decoded)) {
                    $rateRaw = $decoded['consumption_rate']['data']['rate'] ?? null;
                    if ($rateRaw !== null && is_numeric($rateRaw)) {
                        $rate = (float) $rateRaw;
                        $rateSource = 'display_contact_config.consumption_rate.data.rate';
                    }
                    $multimodalCfg = !empty($decoded['multimodal']['enable']);
                    $reasoningCfg = !empty($decoded['reasoning']['enable']);
                }
            }

            // 上下文窗口：{"dev": 256000}
            $contextWindow = null;
            if (isset($item['context_window_tokens']) && is_array($item['context_window_tokens'])) {
                foreach ($item['context_window_tokens'] as $window) {
                    if (is_numeric($window) && (int) $window > 0) {
                        $contextWindow = (int) $window;
                        break;
                    }
                }
            }

            // model_detail_list[0]：max_tokens / 权益消耗
            $maxTokens = null;
            $entitlementCost = null;
            $detail = $item['model_detail_list'][0] ?? null;
            if (is_array($detail)) {
                if (isset($detail['max_tokens']) && is_numeric($detail['max_tokens'])) {
                    $maxTokens = (int) $detail['max_tokens'];
                }
                if (isset($detail['model_entitlement_usage_cost']) && is_numeric($detail['model_entitlement_usage_cost'])) {
                    $entitlementCost = (float) $detail['model_entitlement_usage_cost'];
                }
            }

            $capabilities = ['stream'];
            if ($multimodal || $multimodalCfg) {
                $capabilities[] = 'vision';
            }
            if ($reasoning || $reasoningCfg) {
                $capabilities[] = 'reasoning';
            }

            $out[] = [
                'model_id'         => $name,
                'display_name'     => $display,
                'rate'             => $rate,
                'rate_source'      => $rateSource,
                'fee_level'        => isset($displayCfg['fee_model_level']) ? (int) $displayCfg['fee_model_level'] : null,
                'entitlement_cost' => $entitlementCost,
                'context_window'   => $contextWindow,
                'max_tokens'       => $maxTokens,
                'multimodal'       => $multimodal || $multimodalCfg,
                'reasoning'        => $reasoning || $reasoningCfg,
                'capabilities'     => array_values(array_unique($capabilities)),
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------
    // 额度（对应 /trae/api/v2/pay/*）
    // ---------------------------------------------------------

    public function credits(Account $account): CreditInfo
    {
        $token = (string) $account->cred('accessToken', '');
        if ($token === '') {
            return CreditInfo::failure('缺少 accessToken');
        }

        $headers = $this->apiHeaders($account);
        $packs = [];
        $balance = 0.0;
        $known = false;
        $authFailed = false;
        $earliestExpire = 0;
        // 国际版权益接口仍走 HOST_API，但版本为 v1（国内为 v2）
        $payVersion = $this->isIntlProvider() ? 'v1' : 'v2';
        // usage_summary 汇总值（total_amount / consumed_amount）为权威总额，优先采用
        $summaryTotal = null;
        $summaryConsumed = null;
        // 三个端点返回同一份权益包，去重避免重复计数
        $seen = [];

        // 还原 Ld2/t 的三个额度端点
        $endpoints = [
            ['/trae/api/' . $payVersion . '/pay/ide_user_ent_usage', new \stdClass(), 'ide', 'IDE 权益'],
            ['/trae/api/' . $payVersion . '/pay/web_user_ent_usage', ['require_usage' => true], 'web', '网页版权益'],
            ['/trae/api/' . $payVersion . '/pay/user_current_entitlement_list', new \stdClass(), 'list', '权益包'],
        ];

        foreach ($endpoints as [$path, $body, $group, $label]) {
            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($this->hostApi() . $path, $body);

            if ($res->status() === 401 || $res->status() === 403) {
                $authFailed = true;
                continue;
            }
            if (!$res->ok()) {
                continue;
            }

            $data = $res->json();
            if (!is_array($data)) {
                continue;
            }

            // 上游可能用 HTTP 200 + 业务 code 表达失败
            $code = $data['code'] ?? null;
            if ($code !== null && (int) $code !== 0 && (int) $code !== 200) {
                continue;
            }

            // 汇总用量（Trae：usage_summary.total_amount / consumed_amount）
            if (isset($data['usage_summary']) && is_array($data['usage_summary'])) {
                $us = $data['usage_summary'];
                if (is_numeric($us['total_amount'] ?? null)) {
                    $summaryTotal = (float) $us['total_amount'];
                }
                if (is_numeric($us['consumed_amount'] ?? null)) {
                    $summaryConsumed = (float) $us['consumed_amount'];
                }
            }

            $node = $this->pickResult($data);

            // 收集所有可能的列表容器（含 Trae 的 user_entitlement_pack_list）
            $lists = [];
            foreach (['entitlement_list', 'entitlements', 'user_entitlement_pack_list', 'list', 'items', 'packs'] as $key) {
                if (isset($node[$key]) && is_array($node[$key])) {
                    $lists[] = $node[$key];
                }
                if (isset($data[$key]) && is_array($data[$key])) {
                    $lists[] = $data[$key];
                }
            }

            $added = false;
            foreach ($lists as $list) {
                foreach ($list as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $parsed = $this->parseEntitlementItem($item, $label);
                    if ($parsed === null) {
                        continue;
                    }
                    // 三个端点返回同一份权益包，按 entitlement_id / 组+名称 去重
                    $id = (string) ($item['entitlement_id']
                        ?? ($item['entitlement_base_info']['entitlement_id'] ?? ''));
                    $uniq = $id !== '' ? 'id:' . $id : 'nm:' . $group . ':' . $parsed['name'];
                    if (isset($seen[$uniq])) {
                        continue;
                    }
                    $seen[$uniq] = true;
                    $parsed['group'] = $group;
                    $packs[] = $parsed;
                    $balance += $parsed['remain'];
                    $known = true;
                    $added = true;
                    if (($parsed['expireAt'] ?? 0) > 0) {
                        $earliestExpire = $earliestExpire === 0 ? $parsed['expireAt'] : min($earliestExpire, $parsed['expireAt']);
                    }
                }
            }

            // 非列表型：把节点本身当作一条用量记录
            if (!$added) {
                $parsed = $this->parseEntitlementItem($node, $label);
                if ($parsed !== null) {
                    $uniq = 'nm:' . $group . ':' . $parsed['name'];
                    if (!isset($seen[$uniq])) {
                        $seen[$uniq] = true;
                        $parsed['group'] = $group;
                        $packs[] = $parsed;
                        $balance += $parsed['remain'];
                        $known = true;
                        if (($parsed['expireAt'] ?? 0) > 0) {
                            $earliestExpire = $earliestExpire === 0 ? $parsed['expireAt'] : min($earliestExpire, $parsed['expireAt']);
                        }
                    }
                }
            }
        }

        if (!$known) {
            if ($authFailed) {
                return CreditInfo::failure('登录已失效，请重新登录');
            }
            return CreditInfo::unknown('未获取到额度信息');
        }

        // usage_summary 是权威总额：total_amount 为总额、consumed_amount 为已用
        $usage = null;
        if ($summaryTotal !== null) {
            $consumed = $summaryConsumed ?? max(0.0, $summaryTotal - $balance);
            $balance = max(0.0, $summaryTotal - $consumed);
            $detail = '总额 ' . round($summaryTotal, 2) . '，已用 ' . round($consumed, 2)
                . '，剩余 ' . round($balance, 2) . '（' . count($packs) . ' 个权益包）';
            $usage = [
                'total'  => round($summaryTotal, 2),
                'used'   => round($consumed, 2),
                'remain' => round($balance, 2),
            ];
        } else {
            $detail = '共 ' . count($packs) . ' 个权益包，剩余 ' . round($balance, 2);
        }

        return new CreditInfo(
            known: true,
            balance: round($balance, 2),
            detail: $detail,
            packs: $packs,
            usage: $usage,
            expireAt: $earliestExpire
        );
    }

    /**
     * 解析单条权益/用量记录（还原 Ld2/d：remain / limit / used）
     *
     * @param array<string,mixed> $item
     * @return array{name:string, limit:float, used:float, remain:float, expireAt:int}|null
     */
    private function parseEntitlementItem(array $item, string $defaultName): ?array
    {
        // Trae 权益包：额度藏在 entitlement_base_info.quota.credits_limit
        $base = $item['entitlement_base_info']['quota'] ?? null;
        $limit = $item['limit'] ?? $item['total'] ?? $item['total_credits'] ?? $item['quota'] ?? null;
        if ($limit === null && is_array($base)) {
            $limit = $base['credits_limit'] ?? null;
        }
        $used = $item['used'] ?? $item['usage'] ?? $item['used_credits'] ?? null;
        if ($used === null && isset($item['usage']) && is_array($item['usage'])) {
            $used = $item['usage']['used_amount'] ?? $item['usage']['used_credits'] ?? null;
        }
        $remain = $item['remain'] ?? $item['remaining'] ?? $item['remain_credits'] ?? $item['balance'] ?? $item['credits'] ?? null;

        if ($limit === null && $used === null && $remain === null) {
            return null;
        }

        $limit = (float) ($limit ?? 0);
        $used = (float) ($used ?? 0);
        $remain = $remain !== null ? (float) $remain : max(0.0, $limit - $used);

        $name = $item['name'] ?? $item['title'] ?? $item['entitlement_name'] ?? $item['product_name']
            ?? $item['display_desc'] ?? $item['group_name'] ?? $defaultName;

        return [
            'name'     => (string) $name,
            'limit'    => $limit,
            'used'     => $used,
            'remain'   => $remain,
            'expireAt' => $this->parseExpireAt(is_array($base) ? $base : $item),
        ];
    }

    /**
     * 从权益包节点解析到期时间并归一到毫秒
     *
     * 兼容 Unix 秒/毫秒时间戳与 "yyyy-MM-dd HH:mm:ss" 字符串（视为 UTC+8 本地时间）。
     *
     * @param array<string,mixed> $node
     */
    private function parseExpireAt(array $node): int
    {
        $keys = [
            'expire_at', 'expireAt', 'expire_time', 'expireTime', 'end_time', 'endTime',
            'expired_at', 'expiredAt', 'expire_date', 'end_date', 'invalid_time', 'expire_timestamp',
        ];

        foreach ($keys as $key) {
            if (!isset($node[$key])) {
                continue;
            }
            $value = $node[$key];
            if (is_numeric($value)) {
                $num = (float) $value;
                if ($num <= 0) {
                    continue;
                }
                return $num > 1.0e12 ? (int) $num : (int) ($num * 1000);
            }
            if (is_string($value) && $value !== '') {
                $ts = strtotime($value);
                if ($ts !== false && $ts > 0) {
                    return $ts * 1000;
                }
            }
        }

        return 0;
    }

    // ---------------------------------------------------------
    // 动作：签到
    // ---------------------------------------------------------

    public function action(Account $account, string $action, array $params = []): array
    {
        $headers = $this->apiHeaders($account);

        if ($action === 'checkin_status') {
            if ($this->isIntlProvider()) {
                return $this->intlCheckinStatus($account, $headers);
            }

            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($this->hostApi() . '/trae/api/v2/ug/checkin_credits/status', new \stdClass());

            $data = is_array($res->json()) ? $res->json() : [];

            if ($res->status() === 401 || $res->status() === 403) {
                return ['ok' => false, 'message' => '登录已失效，请重新登录', 'data' => $data];
            }
            if (!$res->ok()) {
                return ['ok' => false, 'message' => '获取签到状态失败：' . $this->extractMessage($res->body()), 'data' => $data];
            }

            // 还原 Ld2/t->d：checked_in / credits / enabled（各字段兼容驼峰）
            $node = $this->pickResult($data);
            $checkedIn = (bool) ($node['checked_in'] ?? $node['checkedIn'] ?? false);
            $credits = (int) ($node['credits'] ?? 0);
            $enabled = (bool) ($node['enable'] ?? $node['enabled'] ?? false);

            return [
                'ok'      => true,
                'message' => $checkedIn ? '今日已签到' : ($enabled ? '今日未签到' : '签到功能未开放'),
                'data'    => [
                    'checked_in' => $checkedIn,
                    'credits'    => $credits,
                    'enabled'    => $enabled,
                    'raw'        => $data,
                ],
            ];
        }

        if ($action === 'checkin') {
            if ($this->isIntlProvider()) {
                return $this->intlCheckin($account, $headers);
            }

            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($this->hostApi() . '/trae/api/v2/ug/checkin_credits/claim', new \stdClass());

            $body = $res->body();
            $data = is_array($res->json()) ? $res->json() : [];

            if ($res->status() === 401 || $res->status() === 403) {
                return ['ok' => false, 'message' => '登录已失效，请重新登录', 'data' => $data];
            }
            if (!$res->ok()) {
                return ['ok' => false, 'message' => '签到失败：' . $this->extractMessage($body), 'data' => $data];
            }

            // 还原 Ld2/t->a 的判定：
            //   code 为 0/200、success===true、status==="success"、或都缺失 → CLAIMED
            //   code==1001 或 message 命中「已签到」→ ALREADY_CHECKED_IN
            $code = $data['code'] ?? null;
            $success = $data['success'] ?? null;
            $alreadyMsg = $this->isAlreadyCheckedInMessage($this->extractMessage($body));

            if ($code !== null && (int) $code !== 0 && (int) $code !== 200) {
                if ((int) $code === 1001 || $alreadyMsg) {
                    return ['ok' => true, 'message' => '今日已签到', 'data' => array_merge($data, ['already_checked_in' => true])];
                }
                return ['ok' => false, 'message' => '签到失败：' . $this->extractMessage($body), 'data' => $data];
            }

            if ($success === false) {
                if ($alreadyMsg) {
                    return ['ok' => true, 'message' => '今日已签到', 'data' => array_merge($data, ['already_checked_in' => true])];
                }
                return ['ok' => false, 'message' => '签到失败：' . $this->extractMessage($body), 'data' => $data];
            }

            $node = $this->pickResult($data);
            $credits = $node['credits'] ?? $data['credits'] ?? null;

            return [
                'ok'      => true,
                'message' => '签到成功' . ($credits !== null ? ('，获得 ' . $credits . ' 积分') : ''),
                'data'    => $data,
            ];
        }

        // 还原 Lx/t->r：POST {HOST_CHAT}/api/ide/v1/get_detail_param
        // 拉取可用模型/配置列表（config_info_list）
        if ($action === 'config_list' || $action === 'detail_param') {
            $res = $this->http()->withHeaders($headers)->timeout(120)
                ->postJson($this->hostChat() . '/api/ide/v1/get_detail_param', [
                    'function'             => 'solo_work_lite',
                    'config_names'         => null,
                    'need_prompt'          => false,
                    'current_config_info'  => null,
                    'poly_prompt'          => true,
                    'mode_type'            => null,
                    'agent_type'           => null,
                ]);

            $data = is_array($res->json()) ? $res->json() : [];

            if ($res->status() === 401 || $res->status() === 403) {
                return ['ok' => false, 'message' => '登录已失效，请重新登录', 'data' => $data];
            }
            if ($res->status() >= 400) {
                return ['ok' => false, 'message' => '获取配置列表失败：' . $this->extractMessage($res->body()), 'data' => $data];
            }

            // 解析 config_info_list[]：config_name 必填非空，display_config.display_name 兜底 config_name
            $raw = $data['config_info_list'] ?? ($this->pickResult($data)['config_info_list'] ?? []);
            $configs = [];
            if (is_array($raw)) {
                foreach ($raw as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $name = trim((string) ($item['config_name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $display = '';
                    if (isset($item['display_config']) && is_array($item['display_config'])) {
                        $display = trim((string) ($item['display_config']['display_name'] ?? ''));
                    }
                    $configs[] = [
                        'config_name'  => $name,
                        'display_name' => $display !== '' ? $display : $name,
                    ];
                }
            }

            return [
                'ok'      => true,
                'message' => $configs !== [] ? ('获取到 ' . count($configs) . ' 个配置') : '暂无可用配置',
                'data'    => [
                    'configs' => $configs,
                    'count'   => count($configs),
                    'raw'     => $data,
                ],
            ];
        }

        // 自检：真实的连通性探测（登录态 + 权益接口 + 模型接口）
        if ($action === 'test' || $action === 'connectivity') {
            $token = (string) $account->cred('accessToken', '');
            if ($token === '') {
                return ['ok' => false, 'message' => '缺少 accessToken，请重新登录', 'data' => null];
            }

            if ($this->isIntlProvider()) {
                $res = $this->http()->withHeaders($headers)->timeout(30)
                    ->get($this->hostChat() . '/api/ide/v1/ug/clockin/point', [
                        'Request' => json_encode(new \stdClass()),
                    ]);
            } else {
                $res = $this->http()->withHeaders($headers)->timeout(30)
                    ->postJson($this->hostApi() . '/trae/api/v2/ug/checkin_credits/status', new \stdClass());
            }

            if ($res->status() === 401 || $res->status() === 403) {
                return ['ok' => false, 'message' => '登录已失效，请重新登录', 'data' => null];
            }
            if (!$res->ok()) {
                return ['ok' => false, 'message' => '连通失败：' . $this->extractMessage($res->body()), 'data' => null];
            }

            $models = $this->models($account);
            $count = count($models);

            return [
                'ok'      => true,
                'message' => '连通正常，可用模型 ' . $count . ' 个',
                'data'    => [
                    'models' => $models,
                    'count'  => $count,
                ],
            ];
        }

        return parent::action($account, $action, $params);
    }

    // ---------------------------------------------------------
    // 国际版签到（/api/ide/v1/ug/clockin/*，走 HOST_CHAT）
    // ---------------------------------------------------------

    /**
     * 国际版签到状态
     *
     * 上游 JS 契约（main.69562e814f.js）：
     *   GetClockInPoint        GET  /api/ide/v1/ug/clockin/point
     *   GetClockInTaskProgress GET  /api/ide/v1/ug/clockin/task_progress
     * 请求体统一为 {Request:{...}}，响应为 {code,message,data} 信封。
     */
    private function intlCheckinStatus(Account $account, array $headers): array
    {
        $res = $this->http()->withHeaders($headers)->timeout(30)
            ->get($this->hostChat() . '/api/ide/v1/ug/clockin/point', [
                'Request' => json_encode(new \stdClass()),
            ]);

        $data = is_array($res->json()) ? $res->json() : [];

        if ($res->status() === 401 || $res->status() === 403) {
            return ['ok' => false, 'message' => '登录已失效，请重新登录', 'data' => $data];
        }
        if (!$res->ok()) {
            return ['ok' => false, 'message' => '获取签到状态失败：' . $this->extractMessage($res->body()), 'data' => $data];
        }

        $code = $data['code'] ?? null;
        if ($code !== null && (int) $code !== 0 && (int) $code !== 200) {
            return ['ok' => false, 'message' => $this->extractMessage($res->body()), 'data' => $data];
        }

        $node = $this->pickResult($data);
        $checkedIn = (bool) ($node['checked_in'] ?? $node['checkedIn'] ?? $node['is_checked_in'] ?? false);
        $credits = (int) ($node['credits'] ?? $node['reward_credits'] ?? $node['points'] ?? 0);
        $enabled = (bool) ($node['enable'] ?? $node['enabled'] ?? true);

        return [
            'ok'      => true,
            'message' => $checkedIn ? '今日已签到' : ($enabled ? '今日未签到' : '签到功能未开放'),
            'data'    => [
                'checked_in' => $checkedIn,
                'credits'    => $credits,
                'enabled'    => $enabled,
                'raw'        => $data,
            ],
        ];
    }

    /**
     * 国际版签到领取
     *
     * 上游 JS 契约：CollectTask POST /api/ide/v1/ug/clockin/collect_task
     * 请求体 {Request:{...}}；响应 {code,message,data}，code 0/200 视为成功。
     */
    private function intlCheckin(Account $account, array $headers): array
    {
        $res = $this->http()->withHeaders($headers)->timeout(30)
            ->postJson($this->hostChat() . '/api/ide/v1/ug/clockin/collect_task', [
                'Request' => new \stdClass(),
            ]);

        $body = $res->body();
        $data = is_array($res->json()) ? $res->json() : [];

        if ($res->status() === 401 || $res->status() === 403) {
            return ['ok' => false, 'message' => '登录已失效，请重新登录', 'data' => $data];
        }
        if (!$res->ok()) {
            return ['ok' => false, 'message' => '签到失败：' . $this->extractMessage($body), 'data' => $data];
        }

        $alreadyMsg = $this->isAlreadyCheckedInMessage($this->extractMessage($body));
        $code = $data['code'] ?? null;

        if ($code !== null && (int) $code !== 0 && (int) $code !== 200) {
            if ($alreadyMsg) {
                return ['ok' => true, 'message' => '今日已签到', 'data' => array_merge($data, ['already_checked_in' => true])];
            }
            return ['ok' => false, 'message' => '签到失败：' . $this->extractMessage($body), 'data' => $data];
        }

        if (($data['success'] ?? null) === false) {
            if ($alreadyMsg) {
                return ['ok' => true, 'message' => '今日已签到', 'data' => array_merge($data, ['already_checked_in' => true])];
            }
            return ['ok' => false, 'message' => '签到失败：' . $this->extractMessage($body), 'data' => $data];
        }

        $node = $this->pickResult($data);
        $credits = $node['credits'] ?? $node['reward_credits'] ?? $data['credits'] ?? null;

        return [
            'ok'      => true,
            'message' => '签到成功' . ($credits !== null ? ('，获得 ' . $credits . ' 积分') : ''),
            'data'    => $data,
        ];
    }

    private function isAlreadyCheckedInMessage(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        foreach (['已签到', '已经签到', '重复签到', 'already', 'checked in', 'checked_in'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------
    // Token 刷新
    // ---------------------------------------------------------

    public function refreshToken(Account $account): array
    {
        $expiresAt = (int) $account->cred('expiresAt', 0);
        // 还有 10 分钟以上有效期则不刷新
        if ($expiresAt > 0 && $expiresAt - 600000 > now_ms()) {
            return [];
        }

        $refreshToken = (string) $account->cred('refreshToken', '');
        if ($refreshToken === '') {
            return [];
        }

        return $this->exchangeToken($refreshToken) ?? [];
    }

    /**
     * 用 RefreshToken 换新的 AccessToken（还原 LC1/b->s：ExchangeToken）
     *
     * 请求体固定为原 App 结构（ClientID / RefreshToken / ClientSecret="-" / UserID=""），
     * 响应取 Result.Token / Result.RefreshToken / Result.TokenExpireAt|TokenExpireDuration。
     *
     * @return array{accessToken:string, refreshToken:string, expiresAt:int}|null
     */
    private function exchangeToken(string $refreshToken): ?array
    {
        $res = $this->http()->timeout(120)->postJson(
            $this->hostOauth() . '/cloudide/api/v3/trae/oauth/ExchangeToken',
            [
                'ClientID'     => self::CLIENT_ID,
                'RefreshToken' => $refreshToken,
                'ClientSecret' => '-',
                'UserID'       => '',
            ]
        );

        if (!$res->ok()) {
            Logger::warn('Trae ExchangeToken 失败', ['status' => $res->status(), 'body' => $res->snippet(200)]);
            return null;
        }

        $data = $res->json();
        $result = $data['Result'] ?? $data['result'] ?? $data['data'] ?? $data;
        if (!is_array($result)) {
            return null;
        }

        $accessToken = (string) ($result['Token'] ?? $result['token'] ?? $result['AccessToken'] ?? '');
        if ($accessToken === '') {
            Logger::warn('Trae ExchangeToken 未返回 Token', ['body' => $res->snippet(200)]);
            return null;
        }

        $expiresAt = 0;
        $expireAt = (int) ($result['TokenExpireAt'] ?? $result['token_expire_at'] ?? 0);
        if ($expireAt > 0) {
            // 正常为秒级时间戳；若被放大到毫秒级（>1e12）先归一到秒
            $seconds = $expireAt > 1.0E12 ? (int) ($expireAt / 1000) : $expireAt;
            $expiresAt = $seconds * 1000;
        } else {
            $duration = (int) ($result['TokenExpireDuration'] ?? $result['token_expire_duration'] ?? 0);
            if ($duration > 0) {
                $expiresAt = now_ms() + $duration * 1000;
            }
        }

        return [
            'accessToken'  => $accessToken,
            'refreshToken' => (string) ($result['RefreshToken'] ?? $result['refresh_token'] ?? $refreshToken),
            'expiresAt'    => $expiresAt,
        ];
    }

    // ---------------------------------------------------------
    // 登录：WebView 授权 + 本地回调
    // ---------------------------------------------------------

    /**
     * 构造 Trae 授权链接（完整还原 LV1/k->d）
     *
     * 原 App 回调为本机 http://127.0.0.1:51120/authorize；本网站改为服务端统一回调
     * {base_url}/authorize，并追加 ?provider=trae，回调页据此路由到本适配器。
     *
     * query 分两组拼接：
     *   group1：TreeMap 按 key 升序（auth_from / auth_type / client_id / login_channel /
     *           login_version / plugin_version / redirect）
     *   group2：固定顺序（login_trace_id → auth_callback_url → machine_id → device_id →
     *           x_device_id → x_machine_id → x_device_brand → x_device_type →
     *           x_os_version → x_app_version → x_app_type）
     */
    public function startLogin(array $params = []): LoginSession
    {
        $sessionId = uuid();

        // Trae 服务端（CheckLogin）强校验：auth_callback_url 必须是回环地址
        // http://127.0.0.1:<port>/authorize，且路径恰为 /authorize（不能带查询串 / 路径后缀）。
        // 因此回调由本机 nginx 的 51120 端口承接，再落到本应用的 /authorize 路由。
        $port = (int) config('gateway.port', 51120);
        if ($port <= 0 || $port > 65535) {
            $port = 51120;
        }
        $callback = 'http://127.0.0.1:' . $port . '/authorize';

        // 设备指纹（machineId / deviceId / loginTraceId）
        $machineId = $this->generateMachineId();
        $deviceId = $this->generateDeviceId();
        $traceId = $this->generateLoginTraceId($machineId, $deviceId);

        $group1 = [
            'auth_from'      => 'solo',
            'auth_type'      => 'local',
            'client_id'      => self::CLIENT_ID,
            'login_channel'  => 'native_ide',
            'login_version'  => '1',
            'plugin_version' => self::PLUGIN_VERSION,
            'redirect'       => '0',
        ];
        ksort($group1);

        $group2 = [
            'login_trace_id'    => $traceId,
            'auth_callback_url' => $callback,
            'machine_id'        => $machineId,
            'device_id'         => $deviceId,
            'x_device_id'       => $deviceId,
            'x_machine_id'      => $machineId,
            'x_device_brand'    => 'PC',
            'x_device_type'     => 'PC',
            'x_os_version'      => '1.0',
            'x_app_version'     => self::PLUGIN_VERSION,
            'x_app_type'        => 'stable',
        ];

        $query = http_build_query($group1, '', '&', PHP_QUERY_RFC3986)
            . '&'
            . http_build_query($group2, '', '&', PHP_QUERY_RFC3986);

        $authUrl = $this->hostWeb() . '/authorization?' . $query;

        $this->saveLoginSession($sessionId, [
            'state'        => 'waiting',
            'provider_key' => $this->id(),
            'trace_id'     => $traceId,
            'machine_id'   => $machineId,
            'device_id'    => $deviceId,
            'auth_url'     => $authUrl,
            'created_at'   => now_ms(),
        ]);

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'waiting',
            message: '请在浏览器中完成 Trae 账号授权，授权后会自动回跳到本页',
            authUrl: $authUrl,
            intervalSeconds: 3,
            expiresInSeconds: 900,
            extra: [
                'callback'   => $callback,
                'machine_id' => $machineId,
                'device_id'  => $deviceId,
                'trace_id'   => $traceId,
            ]
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

    /**
     * 判定某次回环回调是否归属当前供应商。
     *
     * trae / trae_intl 共用同一回环回调地址（http://127.0.0.1:<port>/authorize），
     * 回调本身不带区域标识；此处按 login_trace_id 反查登录会话，用会话记录的
     * provider_key 区分区域（历史会话无该字段时默认为国服 trae）。
     */
    public function resolveLoginProvider(array $query): bool
    {
        $traceId = (string) ($query['loginTraceID'] ?? $query['login_trace_id'] ?? $query['loginTraceId'] ?? '');
        if ($traceId === '') {
            return false;
        }

        $sessionId = $this->findSessionIdByTrace($traceId);
        if ($sessionId === null) {
            return false;
        }

        $data = $this->loadLoginSession($sessionId) ?? [];
        $owner = (string) ($data['provider_key'] ?? self::KEY_CN);

        return $owner === $this->id();
    }

    /**
     * 处理 /authorize 回调：refreshToken 换 accessToken → GetUserInfo → 落库
     *
     * 原 App 授权后回跳 http://127.0.0.1:51120/authorize，携带 login_trace_id 与 refreshToken
     * （部分场景还会直接附带 URL 编码的 userInfo JSON）。此处按 login_trace_id 反查登录会话，
     * 取出授权链接阶段生成的 machineId / deviceId，保证同一账号设备指纹稳定。
     */
    public function handleCallback(array $query): array
    {
        $error = (string) ($query['error'] ?? '');
        // 原 App 从回调链接取 loginTraceID（LV1/l->E(url,"loginTraceID")）反查待登录记录
        $traceId = (string) ($query['loginTraceID'] ?? $query['login_trace_id'] ?? $query['loginTraceId'] ?? '');

        // 管理台发起授权时已知当前会话，显式带上时优先采用，避免 loginTraceID 命中历史遗留会话
        $sessionId = trim((string) ($query['session_id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = $this->findSessionIdByTrace($traceId) ?? '';
        }
        if ($sessionId === '') {
            // 兼容未回传 trace 的情形：回落到最近一次等待中的登录会话
            $sessionId = $this->findLatestWaitingSessionId() ?? '';
        }

        if ($error !== '') {
            $message = '授权被拒绝：' . $error;
            if ($sessionId !== '') {
                $this->updateLoginSession($sessionId, ['state' => 'failed', 'error' => $error, 'message' => $message]);
            }
            return ['ok' => false, 'message' => $message];
        }

        $refreshToken = (string) ($query['refreshToken'] ?? $query['refresh_token'] ?? '');
        $accessToken = (string) ($query['accessToken'] ?? $query['access_token'] ?? $query['token'] ?? '');
        $expiresAt = 0;

        // 回调也可能携带 userJwt（JSON，内含 RefreshToken / Token / TokenExpireAt）
        $userJwt = $this->decodeUserInfo((string) ($query['userJwt'] ?? $query['user_jwt'] ?? ''));
        if ($userJwt !== [] && $refreshToken === '') {
            $refreshToken = (string) ($userJwt['RefreshToken'] ?? $userJwt['refreshToken'] ?? '');
        }
        if ($refreshToken === '' && $accessToken === '' && $userJwt !== []) {
            $accessToken = (string) ($userJwt['Token'] ?? $userJwt['token'] ?? '');
            $expireAtRaw = (int) ($userJwt['TokenExpireAt'] ?? $userJwt['tokenExpireAt'] ?? 0);
            if ($expireAtRaw > 0) {
                // 毫秒时间戳（>1e12）归一化为秒，与原 App 一致
                $expiresAt = $expireAtRaw > 1000000000000 ? (int) ($expireAtRaw / 1000) : $expireAtRaw;
            }
        }

        // 原 App 回调只给 refreshToken，需要用 ExchangeToken 换成 accessToken
        if ($accessToken === '' && $refreshToken !== '') {
            $exchanged = $this->exchangeToken($refreshToken);
            if ($exchanged === null) {
                $message = '用 refreshToken 换取 accessToken 失败';
                if ($sessionId !== '') {
                    $this->updateLoginSession($sessionId, ['state' => 'failed', 'error' => 'exchange_failed', 'message' => $message]);
                }
                return ['ok' => false, 'message' => $message];
            }
            $accessToken = (string) $exchanged['accessToken'];
            $refreshToken = (string) $exchanged['refreshToken'];
            $expiresAt = (int) $exchanged['expiresAt'];
        }

        if ($accessToken === '') {
            return ['ok' => false, 'message' => '回调缺少 token 参数'];
        }

        // 用户信息：优先使用回调携带的 userInfo（URL 编码 JSON），否则请求 GetUserInfo
        $userInfo = $this->decodeUserInfo((string) ($query['userInfo'] ?? $query['user_info'] ?? ''));
        if ($userInfo === []) {
            $userInfo = $this->fetchUserInfo($accessToken);
        }

        $inner = $this->pickResult($userInfo);
        $uid = (string) ($inner['UserID'] ?? $inner['uid'] ?? $inner['user_id'] ?? $inner['userId'] ?? '');
        $nickname = (string) ($inner['ScreenName'] ?? $inner['nickname'] ?? $inner['name'] ?? $inner['user_name'] ?? '');
        $enterpriseId = (string) ($inner['EnterpriseID'] ?? $inner['TenantID'] ?? $inner['enterpriseId'] ?? $inner['enterprise_id'] ?? $inner['tenant_id'] ?? '');

        if ($uid === '') {
            $uid = 'trae_' . substr(hash('sha256', $accessToken), 0, 16);
        }

        $session = $sessionId !== '' ? ($this->loadLoginSession($sessionId) ?? []) : [];
        $machineId = (string) ($session['machine_id'] ?? '');
        if ($machineId === '') {
            $machineId = $this->generateMachineId();
        }
        $deviceId = (string) ($session['device_id'] ?? '');
        if ($deviceId === '') {
            $deviceId = $this->generateDeviceId();
        }

        $credential = [
            'accessToken'  => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresAt'    => $expiresAt > 0 ? $expiresAt : (now_ms() + 3600 * 1000),
            'machineId'    => $machineId,
            'deviceId'     => $deviceId,
            'domain'       => $this->defaultDomain(),
            'apiHost'      => $this->hostOauth(),
            'enterpriseId' => $enterpriseId,
        ];

        $accountId = $this->accountRepository->upsertCredential(
            $this->id(),
            $uid,
            $credential,
            $this->buildAccountMeta(['logged_in_at' => now_ms()], $inner),
            $nickname !== '' ? $nickname : ('Trae-' . substr($uid, 0, 8))
        );

        if ($sessionId !== '') {
            $this->updateLoginSession($sessionId, [
                'state'       => 'success',
                'account_uid' => $uid,
                'nickname'    => $nickname,
                'account_id'  => $accountId,
            ]);
        }

        return ['ok' => true, 'message' => 'Trae 授权成功', 'account_uid' => $uid];
    }

    /**
     * 解析回调中可能携带的 userInfo（URL 编码 JSON 或纯 JSON）
     */
    private function decodeUserInfo(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = json_decode(urldecode($raw), true);
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 取响应中的业务数据节点（Result / result / data，均无则原样返回）
     */
    private function pickResult(array $data): array
    {
        $result = $data['Result'] ?? $data['result'] ?? $data['data'] ?? $data;
        return is_array($result) ? $result : [];
    }

    /**
     * 从用户信息中提取头像地址（多字段兼容，取不到返回空串）
     */
    private function extractAvatar(array $inner): string
    {
        foreach (['Avatar', 'avatar', 'avatarUrl', 'avatar_url', 'Icon', 'icon', 'IconUrl', 'icon_url', 'headUrl', 'head_url'] as $key) {
            $value = $inner[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }

    /**
     * 组装账号 meta：在基础上补充头像（取不到则不写入，避免覆盖已有值）
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $inner 用户信息节点
     * @return array<string,mixed>
     */
    private function buildAccountMeta(array $base, array $inner): array
    {
        $avatar = $this->extractAvatar($inner);
        if ($avatar !== '') {
            $base['avatar'] = $avatar;
        }
        return $base;
    }

    /**
     * 拉取用户信息（还原 LC1/b->u：POST {HOST_OAUTH}/cloudide/api/v3/trae/GetUserInfo，
     * 头 X-Cloudide-Token，体 {ReqSource:"IDE", IDEVersion:"..."}）
     */
    private function fetchUserInfo(string $accessToken): array
    {
        $res = $this->http()
            ->withHeaders($this->oauthHeaders($accessToken))
            ->timeout(20)
            ->postJson($this->hostOauth() . '/cloudide/api/v3/trae/GetUserInfo', [
                'ReqSource'  => 'IDE',
                'IDEVersion' => self::IDE_VERSION,
            ]);

        return $res->ok() ? ($res->json() ?? []) : [];
    }

    /**
     * GetUserInfo 鉴权请求头（X-Cloudide-Token 携带 accessToken）
     *
     * @return array<string,string>
     */
    private function oauthHeaders(string $accessToken): array
    {
        return [
            'Content-Type'         => 'application/json',
            'Accept'               => 'application/json',
            'User-Agent'           => 'Trae/' . self::IDE_VERSION,
            'X-Cloudide-Token'     => $accessToken,
            'X-App-Id'             => self::APP_ID,
            'X-Ide-Version'        => self::IDE_VERSION,
            'X-Ide-Version-Code'   => self::IDE_VERSION_CODE,
            'X-Ide-Version-Type'   => 'stable',
            'Request-Traffic-Type' => 'prod',
        ];
    }

    /**
     * 带状态码的用户信息请求，供粘贴 Token 时区分「Token 无效」与「网络/上游异常」
     *
     * @return array{status:int, ok:bool, data:array, error:string, code:string}
     */
    private function probeUserInfo(string $accessToken): array
    {
        try {
            $res = $this->http()
                ->withHeaders($this->oauthHeaders($accessToken))
                ->timeout(20)
                ->postJson($this->hostOauth() . '/cloudide/api/v3/trae/GetUserInfo', [
                    'ReqSource'  => 'IDE',
                    'IDEVersion' => self::IDE_VERSION,
                ]);
        } catch (\Throwable $e) {
            return ['status' => 0, 'ok' => false, 'data' => [], 'error' => $e->getMessage(), 'code' => ''];
        }

        $data = $res->json();
        if (!is_array($data)) {
            return [
                'status' => $res->status(),
                'ok'     => false,
                'data'   => [],
                'code'   => '',
                'error'  => '上游返回非 JSON 内容：' . $res->snippet(120),
            ];
        }

        // 上游可能用 HTTP 200 + 业务 code 表达失败
        $code = (string) ($data['ResponseMetadata']['Error']['Code'] ?? '');
        if ($code === '') {
            $c = $data['code'] ?? null;
            if ($c !== null && (int) $c !== 0 && (int) $c !== 200) {
                $code = (string) $c;
            }
        }

        $ok = $res->ok() && $code === '';

        return [
            'status' => $res->status(),
            'ok'     => $ok,
            'data'   => $ok ? $data : [],
            'code'   => $code,
            'error'  => $ok ? '' : (string) ($data['ResponseMetadata']['Error']['Message'] ?? $data['message'] ?? $this->extractMessage($res->body())),
        ];
    }

    /**
     * 把一份凭据（可能只有 accessToken）补全成可运行的 Trae 凭据并落库。
     *
     * 原 App 的 TraeAccount 需要 machineId / deviceId / domain / apiHost 等派生字段，
     * machineId 会参与 X-Machine-Id 请求头，必须保持稳定，因此复用已存在账号的值。
     *
     * @param array<string,mixed> $credential 手工粘贴或回调得到的凭据
     * @param string              $uid        已知 uid（可空，空则从 Token 推导）
     * @return array{ok:bool, message:string, account_uid:string, nickname:string, account_id:int}
     */
    public function ingestCredential(array $credential, string $uid = '', string $nickname = ''): array
    {
        $accessToken = trim((string) ($credential['accessToken'] ?? $credential['access_token'] ?? $credential['token'] ?? ''));
        if ($accessToken === '') {
            return ['ok' => false, 'message' => '请提供 accessToken', 'account_uid' => '', 'nickname' => '', 'account_id' => 0];
        }

        // 校验 Token 有效性，同时拿 uid / nickname
        $probe = $this->probeUserInfo($accessToken);
        if (!$probe['ok']) {
            if ($probe['status'] === 0) {
                return [
                    'ok'          => false,
                    'message'     => '无法连接 Trae 接口校验 Token：' . $probe['error'],
                    'account_uid' => '', 'nickname' => '', 'account_id' => 0,
                ];
            }
            $reason = ($probe['code'] === '20310' || $probe['status'] === 401 || $probe['status'] === 403)
                ? 'Token 无效或已过期，请重新从浏览器获取'
                : 'Trae 拒绝了该 Token：' . ($probe['error'] !== '' ? $probe['error'] : 'HTTP ' . $probe['status']);
            return ['ok' => false, 'message' => $reason, 'account_uid' => '', 'nickname' => '', 'account_id' => 0];
        }

        $info = $probe['data'];
        $inner = is_array($info['data'] ?? null) ? $info['data'] : (is_array($info['Result'] ?? null) ? $info['Result'] : $info);

        $uid = $uid !== '' ? $uid : (string) ($inner['UserID'] ?? $inner['uid'] ?? $inner['user_id'] ?? $inner['userId'] ?? $info['uid'] ?? '');
        $nickname = $nickname !== '' ? $nickname : (string) ($inner['ScreenName'] ?? $inner['nickname'] ?? $inner['name'] ?? $inner['user_name'] ?? '');

        if ($uid === '') {
            // 上游未返回 uid 但 Token 有效：退化为稳定指纹，避免每次粘贴产生重复账号
            $uid = 'trae_' . substr(hash('sha256', $accessToken), 0, 16);
        }

        // 复用旧账号的稳定设备标识
        $existing = $this->accountRepository->findByUid($this->id(), $uid);
        $machineId = (string) ($credential['machineId'] ?? '');
        if ($machineId === '' && $existing !== null) {
            $machineId = (string) $existing->cred('machineId', '');
        }
        if ($machineId === '') {
            $machineId = $this->generateMachineId();
        }

        $deviceId = (string) ($credential['deviceId'] ?? '');
        if ($deviceId === '' && $existing !== null) {
            $deviceId = (string) $existing->cred('deviceId', '');
        }
        if ($deviceId === '') {
            $deviceId = $this->generateDeviceId();
        }

        $merged = array_merge($existing?->credential ?? [], [
            'accessToken'  => $accessToken,
            'refreshToken' => (string) ($credential['refreshToken'] ?? $credential['refresh_token'] ?? $existing?->cred('refreshToken', '') ?? ''),
            'expiresAt'    => (int) ($credential['expiresAt'] ?? 0) ?: (now_ms() + 3600 * 1000),
            'clientSecret' => (string) ($credential['clientSecret'] ?? $existing?->cred('clientSecret', '') ?? ''),
            'machineId'    => $machineId,
            'deviceId'     => $deviceId,
            'domain'       => (string) ($credential['domain'] ?? $this->defaultDomain()),
            'apiHost'      => (string) ($credential['apiHost'] ?? $this->hostOauth()),
            'enterpriseId' => (string) ($credential['enterpriseId'] ?? $inner['EnterpriseID'] ?? $inner['enterprise_id'] ?? ''),
        ]);

        $accountId = $this->accountRepository->upsertCredential(
            $this->id(),
            $uid,
            $merged,
            $this->buildAccountMeta(['logged_in_at' => now_ms(), 'imported_by' => 'paste_token'], $inner),
            $nickname !== '' ? $nickname : ('Trae-' . substr($uid, 0, 8))
        );

        return [
            'ok'          => true,
            'message'     => 'Token 校验通过，账号已加入账号池',
            'account_uid' => $uid,
            'nickname'    => $nickname,
            'account_id'  => $accountId,
        ];
    }

    /**
     * 生成 machineId（还原 Ld2/x->c：32 字节 SecureRandom → 小写十六进制，共 64 位）
     */
    private function generateMachineId(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 生成 deviceId（还原 Ld2/x->e：随机长整型取绝对值 % 0x3328b944c4000 + 0x5af3107a4000，
     * 再以 %015d 输出 15 位十进制字符串）
     */
    private function generateDeviceId(): string
    {
        // 组合两个 31 位正整数得到 62 位无符号随机数，规避 PHP 有符号整型与 abs(PHP_INT_MIN) 问题
        $hi = random_int(0, 0x7FFFFFFF);
        $lo = random_int(0, 0x7FFFFFFF);
        $value = ($hi << 31) | $lo;

        $value = $value % 0x3328b944c4000;

        return sprintf('%015d', $value + 0x5af3107a4000);
    }

    /**
     * 生成 loginTraceId（还原 Ld2/x->b：machineId 与 deviceId 拼接，取末 16 位；不足则左补 0）
     */
    private function generateLoginTraceId(string $machineId, string $deviceId): string
    {
        $combined = $machineId . $deviceId;
        if (strlen($combined) >= 16) {
            return substr($combined, -16);
        }

        return str_pad($combined, 16, '0', STR_PAD_LEFT);
    }

    // ---------------------------------------------------------
    // 登录会话临时存储（storage/login/<sessionId>.json）
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

    /**
     * 按 login_trace_id 反查登录会话 ID（回调只带 trace_id，不带会话 ID）
     */
    private function findSessionIdByTrace(string $traceId): ?string
    {
        if ($traceId === '') {
            return null;
        }

        foreach (scandir($this->sessionDir()) ?: [] as $file) {
            if (!str_ends_with($file, '.json')) {
                continue;
            }
            $sessionId = basename($file, '.json');
            $data = $this->loadLoginSession($sessionId);
            if (is_array($data) && (string) ($data['trace_id'] ?? '') === $traceId) {
                return $sessionId;
            }
        }

        return null;
    }

    /**
     * 取最近一次处于 waiting 状态的登录会话（回环回调未携带 trace 时的兜底关联）
     */
    private function findLatestWaitingSessionId(): ?string
    {
        $latest = null;
        $latestAt = -1;

        foreach (scandir($this->sessionDir()) ?: [] as $file) {
            if (!str_ends_with($file, '.json')) {
                continue;
            }
            $sessionId = basename($file, '.json');
            $data = $this->loadLoginSession($sessionId);
            if (!is_array($data) || (string) ($data['state'] ?? '') !== 'waiting') {
                continue;
            }
            $at = (int) ($data['created_at'] ?? 0);
            if ($at > $latestAt) {
                $latestAt = $at;
                $latest = $sessionId;
            }
        }

        return $latest;
    }
}
