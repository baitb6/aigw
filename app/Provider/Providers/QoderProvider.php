<?php
/**
 * Qoder 适配器（阿里 Qoder 编程助手，参考 bzym2/QoderGateway 实现）
 *
 * 协议来源：https://github.com/bzym2/QoderGateway
 *   - 登录（方式一）：PKCE Device Flow（4 步）
 *            GET  {OPENAPI}/api/v1/deviceToken/poll?nonce=&verifier=&challenge_method=S256
 *            （404=等待授权，200=返回 dt- 访问令牌 / drt- 刷新令牌）
 *   - 登录（方式二）：粘贴 PAT / dt- 令牌，直接导入即可使用
 *   - 额度：  GET  {OPENAPI}/api/v2/quota/usage           （Bearer dt- 令牌）
 *            GET  {OPENAPI}/sash/api/v2/me/usage           （CLI 权威接口，含额度包，失败回退）
 *   - 刷新：  POST {OPENAPI}/api/v1/deviceToken/refresh    body {"refresh_token":"drt-..."}
 *            POST {OPENAPI}/api/v1/jobToken/refresh       body {"refresh_token":"jrt-..."}
 *   - 对话（新版，纯 Bearer，OpenAI 兼容）：
 *            POST {INFERENCE_V2}/model/v1/chat/completions
 *   - 对话（老版，COSY 签名 + 自定义编码）：
 *            POST {SSE}/algo/api/v2/service/pro/sse/agent_chat_generation
 *   - 兑换（老版，PAT → 身份）：POST {CENTER}/algo/api/v3/user/jobToken?Encode=1
 *   - 签到：  Qoder 无独立签到接口，「签到」语义等价于每日额度刷新
 *            （调用额度接口记录当日打卡；无可用额度时如实回执）
 *
 * 同一适配器按 provider_key 区分区域（ProviderManager::withKey 注入）：
 *   - 'qoder'      → 国内版（qoder.com.cn 生态，授权页 https://qoder.com.cn）
 *   - 'qoder_intl' → 国际 / 全球版（qoder.com 控制台，走 .sh 生态）
 *
 * 区域域名映射（两域生态相互独立，令牌不通用）：
 *   国内版：授权页 qoder.com.cn，鉴权/额度 openapi.qoder.com.cn，
 *           SSE 与兑换落在 api3/api2.qoder.com.cn（center.qoder.com.cn 无解析）。
 *   国际版：授权页 qoder.com，鉴权/额度 openapi.qoder.sh，新版对话 api2-v2.qoder.sh，
 *           老版 SSE api3.qoder.sh，兑换 center.qoder.sh。
 *   实证：国内版授权会话在 openapi.qoder.sh 轮询恒 404，在 openapi.qoder.com.cn 轮询 200。
 *   （参考文档 docs/qoder-protocol-research.md §2：国际版为 .sh，国内为 .com.cn）
 *
 * 说明：新版协议仅需 Bearer 令牌即可对话；老版协议需要完整 COSY 载荷
 * （RSA(PKCS1v15) 加密 temp_key → cosy_key；AES-128-CBC 加密身份 → info；
 * authorization = "Bearer COSY.<payload_b64>.<md5sig>"）。两代协议均在本适配器
 * 内实现，新版优先、失败可回退老版（由账号 meta.protocol 控制，默认 auto）。
 */

declare(strict_types=1);

namespace App\Provider\Providers;

use App\Provider\AbstractProvider;
use App\Provider\Dto\Account;
use App\Provider\Dto\ChatRequest;
use App\Provider\Dto\Chunk;
use App\Provider\Dto\CreditInfo;
use App\Provider\Dto\LoginSession;
use App\Provider\ErrorCategory;
use App\Repositories\AccountRepository;
use App\Repositories\ModelRepository;
use App\Repositories\ProviderRepository;
use App\Repositories\ProxyRepository;
use App\Support\HttpClient;
use App\Support\Logger;

class QoderProvider extends AbstractProvider
{
    private const KEY_CN = 'qoder';
    private const KEY_INTL = 'qoder_intl';

    /** 客户端 UA，与 Qoder 桌面端保持一致 */
    private const USER_AGENT = 'qoder/1.1.16';

    /**
     * 国内版域名（qoder.com.cn 生态，授权页 qoder.com.cn 签发的令牌仅在本域有效）。
     *
     * ⚠️ 2026-09 上游改版实测（这是本供应商长期 100% 失败的直接根因）：
     *   - `api2-v2.qoder.com.cn`（旧 CN_INFERENCE）**DNS 已永久撤除** → Could not resolve host；
     *   - `api3.qoder.com.cn`（旧 CN_SSE）仍存活，但证书是 `CN=*.qoder.sh`（SNI 错配），
     *     且**换回了 `{"code":"105","message":"Login expired"}`** —— 该入口对 dt- 令牌已死；
     *   - 真正在用的推理主机是 **`gateway.qoder.com.cn`**（证书 `CN=*.qoder.com.cn`，正确）。
     * 新网关只路由 `/algo/**`，其余路径回 ALB 的 503；且 `Encode=1` 要求**请求体先经
     * QoderEncoding 编码**（详见 chatLegacy / legacyHeaders 注释）。
     *
     * ⚠️ 2026-10-01 复核（`tools/probe-qoder-endpoints.php`，带真实 dt- 令牌逐端点实测）：
     * 国内版**根本不存在可用新版端点**，四个候选全线不可用：
     *   | 端点 | 结果 |
     *   |---|---|
     *   | `api2-v2.qoder.com.cn` | **NXDOMAIN**（DNS 永久撤除） |
     *   | `gateway.qoder.com.cn` | 该路径回 ALB **503** |
     *   | `api.qoder.com.cn`     | 该路径回 ALB **503** |
     *   | `api2.qoder.com.cn`    | **errno=60** `CN=*.qoder.sh` SNI 错配 |
     *   | `api3.qoder.com.cn`    | **errno=60** `CN=*.qoder.sh` SNI 错配 |
     * 对照：国际版 `api2-v2.qoder.sh` **存活**（401 unauthorized = 路由正常、仅鉴权层拦截）。
     * 故 `hasV2Inference()` 国内版恒 false，`chat()` 对 CN 直接走老版协议。
     */
    private const CN_OPENAPI   = 'https://openapi.qoder.com.cn';
    private const CN_INFERENCE = 'https://api2-v2.qoder.com.cn';
    private const CN_SSE       = 'https://gateway.qoder.com.cn';
    private const CN_CENTER    = 'https://api2.qoder.com.cn';
    private const CN_WEB       = 'https://qoder.com.cn';

    /** 国际版域名（全球 / 国际站，qoder.com 控制台，走 .sh 生态） */
    private const INTL_OPENAPI   = 'https://openapi.qoder.sh';
    private const INTL_INFERENCE = 'https://api2-v2.qoder.sh';
    private const INTL_SSE       = 'https://api2.qoder.sh';
    private const INTL_CENTER    = 'https://center.qoder.sh';
    private const INTL_WEB       = 'https://qoder.com';

    /** 授权页（device flow 选择账号）基址 */
    private const AUTH_WEB_CN   = 'https://qoder.com.cn';
    private const AUTH_WEB_INTL = 'https://qoder.com';

    /** Device Flow 客户端 ID（来自 QoderGateway 实测） */
    private const CLIENT_ID = 'e883ade2-e6e3-4d6d-adf7-f92ceff5fdcb';

    /** 授权轮询间隔 / 超时（毫秒） */
    private const POLL_INTERVAL_MS = 1000;
    private const POLL_TIMEOUT_MS  = 300000;

    /** 老版协议签名常量（COSY） */
    private const SIGN_APPCODE = 'cosy';
    private const SIGN_SECRET  = 'd2FyLCB3YXIgbmV2ZXIgY2hhbmdlcw==';
    private const SIGN_SEP     = '&';

    /** Qoder 客户端版本（老版 COSY 头 / 载荷中声明） */
    private const COSY_VERSION   = '0.1.43';
    private const COSY_CLIENT_ID = '5';
    private const COSY_CLIENT_IP = '169.254.198.161';

    /**
     * 老版协议服务端公钥（RSA PKCS1v15，用于加密 temp_key → cosy_key）。
     * 来源：QoderGateway/src/qoder2api/auth.py
     */
    private const SERVER_PUBKEY_PEM = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDA8iMH5c02LilrsERw9t6Pv5Nc
4k6Pz1EaDicBMpdpxKduSZu5OANqUq8er4GM95omAGIOPOh+Nx0spthYA2BqGz+l
6HRkPJ7S236FZz73In/KVuLnwI8JJ2CbuJap8kvheCCZpmAWpb/cPx/3Vr/J6I17
XcW+ML9FoCI6AOvOzwIDAQAB
-----END PUBLIC KEY-----
PEM;

    /** 老版协议 SSO 混淆解密密钥（base64 解码后循环 XOR） */
    private const OBFUSCATE_KEY = 'syJkkdK5Dxwd';

    private const DEFAULT_MODEL = 'auto';

    /**
     * 上游真实模型目录接口（COSY 签名，GET，签名时 body 取空串）。
     *
     * 返回 JSON 顶层按用途分组（chat / developer / assistant / inline / quest /
     * qwork / experts / qwake / app / byok_*），每组是一个模型对象数组，
     * 关键字段：
     *   key              → **真·模型名**，就是对话时要填进 `model_config.key` 的值
     *                      （qmodel_38max / qfmodel / gmodel / dmodel …，不是 display_name！）
     *   display_name     → 展示名（Qwen3.8-Max / Qwen3.8-Flash …）
     *   price_factor     → Credits 消耗倍率
     *   is_reasoning     → 是否推理模型
     *   is_vl            → 是否多模态（vision）
     *   max_input_tokens → 上下文窗口
     *   enable           → 是否对该账号开放
     *
     * ⚠️ 这是**唯一**能拿到 Qoder 真实模型表的入口；早前实现只读本地 DB/硬编码常量，
     * 于是用户在客户端看到的一直是一份过时的旧模型（qwen3-coder-plus / qwen3-max /
     * glm-4.6 / kimi-k2 …），既非国内表也非国际表（用户可见症状「模型还是错的，
     * 国内模型列表没有 gpt 模型 / Qoder 应该有 Qwen3.8-Flash 我们的却没有」）。
     */
    private const MODEL_LIST_PATH = '/algo/api/v2/model/list';

    /**
     * 国内版内置兜底表：key => [显示名, 上下文窗口, 多模态, 推理, 消耗倍率]
     *
     * 源：2026-09-30 实测 `GET gateway.qoder.com.cn/algo/api/v2/model/list` 的 `chat` 组。
     * 仅在拉上游失败时使用，正常路径由 fetchUpstreamModelList() 覆盖。
     */
    private const BUILTIN_MODELS_CN = [
        'auto'          => ['Auto', 200000, true, true, 0.5],
        'qmodel_38max'  => ['Qwen3.8-Max', 180000, true, true, 0.5],
        'qfmodel'       => ['Qwen3.8-Flash', 180000, true, true, 0.0],
        'qmodel_latest' => ['Qwen3.7-Max', 180000, true, true, 0.5],
        'qmodel'        => ['Qwen3.7-Plus', 180000, true, true, 0.1],
        'q37fmodel'     => ['Qwen3.7-Flash', 180000, true, true, 0.1],
        'dmodel'        => ['DeepSeek-V4-Pro', 180000, false, true, 0.5],
        'dfmodel'       => ['DeepSeek-Flash', 180000, false, false, 0.1],
        'gmodel'        => ['GLM-5.3', 180000, false, true, 0.8],
        'gfmodel'       => ['GLM-5.3-Flash', 180000, false, true, 0.1],
        'gm51model'     => ['GLM-5.2', 180000, false, true, 0.6],
        'kmodel_latest' => ['Kimi-K3', 180000, false, false, 1.4],
        'kmodel'        => ['Kimi-K2.8-Preview', 180000, false, true, 0.8],
        'mmodel'        => ['MiniMax-M2.7', 180000, false, false, 0.2],
    ];

    /**
     * 国际版内置兜底表：key => [显示名, 上下文窗口, 多模态, 推理, 消耗倍率]
     *
     * 源：2026-09-30 实测 `GET api2.qoder.sh/algo/api/v2/model/list` 的 `chat` 组。
     * 与国际版相比多出档位别名 ultimate/performance/efficient，MiniMax 为 M3。
     */
    private const BUILTIN_MODELS_INTL = [
        'auto'          => ['Auto', 200000, false, false, 0.5],
        'ultimate'      => ['Ultimate', 200000, false, true, 2.0],
        'performance'   => ['Performance', 200000, false, false, 1.1],
        'efficient'     => ['Efficient', 200000, false, false, 0.3],
        'qmodel_38max'  => ['Qwen3.8-Max', 180000, false, true, 0.5],
        'qfmodel'       => ['Qwen3.8-Flash', 180000, false, true, 0.0],
        'qmodel_latest' => ['Qwen3.7-Max', 180000, false, false, 0.5],
        'qmodel'        => ['Qwen3.7-Plus', 180000, false, false, 0.1],
        'kmodel_latest' => ['Kimi-K3', 180000, false, false, 1.4],
        'kmodel'        => ['Kimi-K2.8-Preview', 180000, false, false, 0.8],
        'gmodel'        => ['GLM-5.3', 180000, false, true, 0.8],
        'gfmodel'       => ['GLM-5.3-Flash', 180000, false, true, 0.1],
        'dmodel'        => ['DeepSeek-V4-Pro', 180000, false, true, 0.5],
        'dfmodel'       => ['DeepSeek-Flash', 180000, false, true, 0.1],
        'mmodel'        => ['MiniMax-M3', 180000, false, false, 0.2],
    ];

    /**
     * 国际版**专有的 v2 直通模型**：上游 `model/list` 的 chat 组不列出，但端点确有实现。
     *
     * 来源：2026-09-30 实测 `POST {INFERENCE}/model/v1/chat/completions`（v2 协议）逐个打通，
     * 4 个名字全部返回真实正文，且上游回执 `model` 字段与请求名一致 —— 其中
     * `gpt-5` 回执即 `"model":"gpt-5"`，模型自述「我是由 OpenAI 训练的 ChatGPT」，
     * 是货真价实的 GPT-5（Azure 通道）。
     *
     * 这正是用户历史反馈「模型还是错的，国内模型列表没有 gpt 模型」的另一半答案：
     * **国内版确无 GPT**（走 legacy，对未知模型名静默回退默认模型，不报错），
     * 但**国际版有真 gpt-5**，必须补进目录才拿得到。
     *
     * ⚠️ 绝不并入 BUILTIN_MODELS_CN：legacy 协议遇到未知名会**静默改派默认模型**
     * （实测 CN 传 gpt-5 也回「你好」，但那是别的模型在答），加了等于给用户一个
     * 「看着能用、实际换药」的假模型。
     *
     * key => [显示名, 上下文窗口, 多模态, 推理, 消耗倍率(null=上游未给)]
     */
    private const EXTRA_MODELS_INTL = [
        'gpt-5'      => ['GPT-5', 200000, false, false, null],
        'qwen-flash' => ['Qwen-Flash', 200000, false, false, null],
        'qwen-plus'  => ['Qwen-Plus', 200000, false, false, null],
        'qwen-turbo' => ['Qwen-Turbo', 200000, false, false, null],
    ];

    private const RATE_SOURCE = 'Qoder 官方倍率';

    protected string $providerKey = self::KEY_CN;

    public function __construct(
        ProxyRepository $proxyRepository,
        private AccountRepository $accountRepository,
        private ModelRepository $modelRepository,
        private ProviderRepository $providerRepository,
    ) {
        parent::__construct($proxyRepository);
    }

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
        return $this->isIntlProvider() ? 'Qoder 国际版' : 'Qoder';
    }

    public function loginType(): string
    {
        return 'DEVICE_CODE';
    }

    public function hosts(): array
    {
        $fallback = $this->isIntlProvider()
            ? ['openapi.qoder.sh', 'api2-v2.qoder.sh', 'qoder.com']
            : ['openapi.qoder.com.cn', 'api3.qoder.com.cn', 'qoder.com.cn'];

        return $this->providerRepository->find($this->providerKey)['hosts'] ?? $fallback;
    }

    private function isIntlProvider(): bool
    {
        return $this->providerKey === self::KEY_INTL;
    }

    // ---------------------------------------------------------
    // 域名解析（按区域）
    // ---------------------------------------------------------

    private function openapiBase(): string
    {
        return $this->isIntlProvider() ? self::INTL_OPENAPI : self::CN_OPENAPI;
    }

    private function inferenceBase(): string
    {
        return $this->isIntlProvider() ? self::INTL_INFERENCE : self::CN_INFERENCE;
    }

    /**
     * 本区域是否存在**可用**的新版 v2 推理端点。
     *
     * ⚠️ 国内版恒为 false —— 这不是"降级策略"，而是**事实**：
     * 四个候选端点全部实测不可用（NXDOMAIN / ALB 503 / SNI 错配），
     * 唯一在用的推理主机 `gateway.qoder.com.cn` **只服务老版 `/algo/**` 协议**。
     * 详见顶部 CN_* 常量注释与 `tools/probe-qoder-endpoints.php` 的实测输出。
     *
     * 早期 chat() 对 CN 也先试 v2，结果是**每个请求都白撞一次死主机**：
     *   - 244 次 `errno=6 Could not resolve host: api2-v2.qoder.com.cn`（09-30 起）；
     *   - 每次多一次 DNS 失败往返 + 一条 WARN 日志，再回退老版；
     *   - 用户侧症状：qoder 全部模型**延迟固定偏高**、日志噪声淹没真实错误。
     *
     * 国际版 v2 实测存活（`api2-v2.qoder.sh` 回 401 = 路由正常），保持启用。
     */
    private function hasV2Inference(): bool
    {
        return $this->isIntlProvider();
    }

    private function sseBase(): string
    {
        return $this->isIntlProvider() ? self::INTL_SSE : self::CN_SSE;
    }

    private function centerBase(): string
    {
        return $this->isIntlProvider() ? self::INTL_CENTER : self::CN_CENTER;
    }

    private function authWebBase(): string
    {
        return $this->isIntlProvider() ? self::AUTH_WEB_INTL : self::AUTH_WEB_CN;
    }

    // ---------------------------------------------------------
    // 凭据解析
    // ---------------------------------------------------------

    /** 访问令牌（dt- 前缀的 security_oauth_token） */
    private function accessToken(Account $account): string
    {
        return trim((string) $account->cred(
            'access_token',
            $account->cred('device_token', $account->cred('token', $account->cred('api_key', '')))
        ));
    }

    /** 刷新令牌（drt- 前缀，用于 deviceToken/refresh） */
    private function refreshTokenValue(Account $account): string
    {
        return trim((string) $account->cred(
            'refresh_token',
            $account->cred('refreshToken', $account->cred('device_refresh_token', ''))
        ));
    }

    /** jobToken 刷新令牌（jrt- 前缀，老版协议） */
    private function jobRefreshToken(Account $account): string
    {
        return trim((string) $account->cred('job_refresh_token', $account->cred('job_token', '')));
    }

    /** 统一的授权请求头 */
    private function authHeaders(Account $account, string $accept = 'application/json'): array
    {
        $token = $this->accessToken($account);
        if ($token === '') {
            throw new \RuntimeException($this->displayName() . ' 账号缺少访问令牌，请重新登录');
        }

        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => $accept,
            'Content-Type'  => 'application/json',
            'User-Agent'    => self::USER_AGENT,
        ];
    }

    // ---------------------------------------------------------
    // 对话
    // ---------------------------------------------------------

    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        $protocol = strtolower(trim((string) $account->meta('protocol', 'auto')));
        if ($protocol === 'legacy') {
            return $this->chatLegacy($account, $request, $onDelta);
        }

        // ⚠️ 本区域无可用 v2 端点时**直接走老版**，不要"试一次再回退"。
        // 国内版的 v2 端点已全线不可用（NXDOMAIN / 503 / SNI 错配，见 hasV2Inference 注释），
        // 试下去只会让**每个请求**都白撞一次死主机、多一条 WARN、白白增加延迟。
        // 显式 `protocol=v2` 时仍按用户意图尝试（失败抛出，便于排查），不静默改写。
        if (!$this->hasV2Inference() && $protocol !== 'v2') {
            return $this->chatLegacy($account, $request, $onDelta);
        }

        if ($protocol === 'v2' || $protocol === 'auto') {
            try {
                return $this->chatV2($account, $request, $onDelta);
            } catch (\Throwable $e) {
                // auto 模式下新版失败回退老版；v2 模式则直接抛出
                if ($protocol === 'v2') {
                    throw $e;
                }
                Logger::warn("{$this->id()} 新版对话失败，回退老版协议", [
                    'uid'   => $account->uid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->chatLegacy($account, $request, $onDelta);
    }

    /** 新版协议：POST {INFERENCE_V2}/model/v1/chat/completions（纯 Bearer，OpenAI 兼容） */
    private function chatV2(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        $url = rtrim($this->inferenceBase(), '/') . '/model/v1/chat/completions';

        $headers = $this->authHeaders($account, 'text/event-stream');
        $headers['X-Request-ID'] = uuid();
        $headers['X-Session-ID'] = $this->sessionId($account);

        $payload = $request->toUpstreamPayload([
            'stream'         => true,
            'stream_options' => ['include_usage' => true],
            'model'          => $request->model !== '' ? $request->model : self::DEFAULT_MODEL,
        ]);

        return $this->parseOpenAiSse($url, $payload, $headers, $account, $onDelta);
    }

    /**
     * 老版协议：POST {SSE}/algo/api/v2/service/pro/sse/agent_chat_generation
     * 需要完整 COSY 载荷：
     *   authorization = "Bearer COSY.<payload_b64>.<md5sig>"
     *   payload_b64   = base64(JSON{cosyVersion,ideVersion,info,requestId,version})
     *   info          = base64(AES-128-CBC(身份 JSON, temp_key))
     *   cosy_key      = base64(RSA-PKCS1v15(temp_key, 服务端公钥))
     *   md5sig        = md5("{payload_b64}\n{cosy_key}\n{cosy_date}\n{body}\n{path}")
     *
     * ⚠️ `Encode=1` 的语义是「**请求体整体经 QoderEncoding 编码**」，不是「响应编码」。
     * 早期实现发明文 JSON，上游 `AlgoServiceApiController.internalSseInvoke` 直接
     * 在 `EncryptUtil.decode → CustomBase64Util.decodeV1/decode0` 抛异常（HTTP 200 +
     * `event:error` + Java 堆栈），表现为「登录正常、对话永远失败」。必须：
     *   1. `body = encodeCustom(json)` 发出；
     *   2. **签名也用编码后的 body**（实测签明文 → `{"code":"101","message":"Signature invalid"}`）。
     *
     * 需要账号 meta 中具备完整身份字段（name/aid/uid/...）。仅凭单个 dt- 令牌
     * 无法构造身份时抛出异常，交由上游提示用户改用新版协议（meta.protocol=v2）。
     */
    private function chatLegacy(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        $url = rtrim($this->sseBase(), '/')
            . '/algo/api/v2/service/pro/sse/agent_chat_generation'
            . '?FetchKeys=llm_model_result&AgentId=agent_common&Encode=1';

        $plain = json_encode($this->buildLegacyBody($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($plain === false) {
            throw new \RuntimeException('Qoder 老版请求体序列化失败');
        }

        // Encode=1 → 请求体必须编码；签名与请求体保持同一字符串（见方法头注释）
        $body = $this->encodeCustom($plain);

        $headers = $this->legacyHeaders($account, $url, $body, 'text/event-stream');

        return $this->parseLegacySse($url, $body, $headers, $account, $onDelta);
    }

    /**
     * 组装老版协议请求体。
     *
     * ⚠️ 老版协议**选模型靠嵌套的 `model_config.key`，不是顶层 `model`**。
     * 早期实现把模型名塞进顶层 `model`，上游**完全忽略**该字段、一律走 auto
     * 路由（实测：传任何 model 值，回执里 `"model":"auto"`；传瞎编名也照回正文），
     * 于是「换模型」在客户端毫无效果 —— 这正是用户反馈「模型不对 / 没有这些模型」
     * 在对话侧的直接成因。
     *
     * 正确形态（参考 QoderGateway::bridge.template_base()）：
     *   model_config.key           = 真·模型名（qmodel_38max / qfmodel / gmodel …）
     *   model_config.display_name  = 展示名
     *   chat_context.extra.modelConfig.key = 同一个 key（上游在上下文里也读这份）
     */
    private function buildLegacyBody(ChatRequest $request): array
    {
        $model = $request->model !== '' ? $request->model : self::DEFAULT_MODEL;
        $meta  = $this->builtinMeta($model);

        $modelConfig = [
            'key'              => $model,
            'display_name'     => $meta['display_name'],
            'model'            => '',
            'format'           => 'openai',
            'is_vl'            => $meta['multimodal'],
            'is_reasoning'     => $meta['reasoning'],
            'api_key'          => '',
            'url'              => '',
            'source'           => 'system',
            'max_input_tokens' => $meta['context_window'] ?? 180000,
        ];

        return $request->toUpstreamPayload([
            'model'         => $model,
            'stream'        => true,
            'agent_id'      => 'agent_common',
            'session_type'  => 'qodercli',
            'chat_task'     => 'FREE_INPUT',
            'version'       => '3',
            'parameters'    => ['max_tokens' => 32768],
            // 上游真正读模型的地方
            'model_config'  => $modelConfig,
        ]) + [
            'chat_context' => [
                'chatPrompt' => '',
                'extra'      => [
                    'context'         => [],
                    'modelConfig'     => ['is_reasoning' => $meta['reasoning'], 'key' => $model],
                    'originalContent' => ['type' => 'text', 'text' => ''],
                ],
                'features'   => [],
                'imageUrls'  => null,
                'text'       => ['type' => 'text', 'text' => ''],
            ],
            'is_reply' => true,
            'is_retry' => false,
            'source'   => 1,
            'task_id'  => 'common',
        ];
    }

    /**
     * 取某模型的元数据（显示名/上下文/多模态/推理）。
     * 优先用当前区域内置兜底表；未收录的自定义模型回退为「原样显示」的中性元数据。
     *
     * @return array{display_name:string,context_window:?int,multimodal:bool,reasoning:bool}
     */
    private function builtinMeta(string $modelId): array
    {
        $meta = $this->builtinModels()[$modelId] ?? null;
        if ($meta === null) {
            return [
                'display_name'   => $modelId,
                'context_window' => null,
                'multimodal'     => false,
                'reasoning'      => false,
            ];
        }

        return [
            'display_name'   => (string) $meta[0],
            'context_window' => isset($meta[1]) ? (int) $meta[1] : null,
            'multimodal'     => (bool) ($meta[2] ?? false),
            'reasoning'      => (bool) ($meta[3] ?? false),
        ];
    }

    /**
     * 老版协议完整 COSY 请求头。
     *
     * @param string $body 已序列化的请求体（参与签名）
     */
    private function legacyHeaders(Account $account, string $url, string $body, string $accept): array
    {
        $identity = $this->legacyIdentity($account);
        $machine = $this->legacyMachine($account);

        // cosy-date 使用秒级时间戳（参考 bearer_headers: str(int(time.time()))）
        $date = (string) intdiv(now_ms(), 1000);

        $tempKey = substr(uuid(), 0, 16);
        $cosyKey = base64_encode($this->rsaEncrypt($tempKey));
        $info = base64_encode($this->aesCbcEncrypt(json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $tempKey));

        $payloadB64 = base64_encode(json_encode([
            'cosyVersion' => self::COSY_VERSION,
            'ideVersion'  => '',
            'info'        => $info,
            'requestId'   => uuid(),
            'version'     => 'v1',
        ], JSON_UNESCAPED_SLASHES));

        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/algo')) {
            $path = substr($path, strlen('/algo'));
        }

        $signature = $this->signRequest($payloadB64, $cosyKey, $date, $body, $path);

        $headers = [
            'cosy-data-policy'  => 'AGREE',
            'Content-Type'      => 'application/json',
            'cosy-machinetype'  => $machine['type'],
            'cosy-clienttype'   => self::COSY_CLIENT_ID,
            'cosy-date'         => $date,
            'cosy-user'         => (string) ($identity['uid'] ?? ''),
            'cosy-key'          => $cosyKey,
            'Accept'            => $accept,
            'cosy-clientip'     => self::COSY_CLIENT_IP,
            'Authorization'     => 'Bearer COSY.' . $payloadB64 . '.' . $signature,
            'cosy-version'      => self::COSY_VERSION,
            'cosy-machineid'    => $machine['id'],
            'cosy-machinetoken' => $machine['token'],
            'login-version'     => 'v2',
            'User-Agent'        => self::USER_AGENT,
        ];

        if ($accept === 'text/event-stream') {
            $headers['cache-control'] = 'no-cache';
        }

        return $headers;
    }

    /**
     * 从账号提取老版身份字段。优先取 meta.identity，其次由凭据拼接。
     *
     * @return array<string,string>
     */
    private function legacyIdentity(Account $account): array
    {
        $meta = $account->meta('identity', []);
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        $token = $this->accessToken($account);
        $identity = [
            'name'              => (string) ($meta['name'] ?? $account->meta('nickname', '')),
            'aid'               => (string) ($meta['aid'] ?? $account->uid),
            'uid'               => (string) ($meta['uid'] ?? $account->uid),
            'yx_uid'            => (string) ($meta['yx_uid'] ?? ''),
            'organization_id'   => (string) ($meta['organization_id'] ?? ''),
            'organization_name' => (string) ($meta['organization_name'] ?? ''),
            'user_type'         => (string) ($meta['user_type'] ?? 'personal_standard'),
            'security_oauth_token' => (string) ($meta['security_oauth_token'] ?? $token),
            'refresh_token'     => (string) ($meta['refresh_token'] ?? $this->refreshTokenValue($account)),
        ];

        if ($identity['security_oauth_token'] === '') {
            throw new \RuntimeException($this->displayName() . ' 老版协议需要完整身份信息，请改用新版协议（meta.protocol=v2）');
        }

        return $identity;
    }

    /** 老版设备标识三元组：machine_id / machine_token / machine_type（持久化于 meta） */
    private function legacyMachine(Account $account): array
    {
        $machine = $account->meta('machine', []);
        if (is_string($machine) && $machine !== '') {
            $decoded = json_decode($machine, true);
            $machine = is_array($decoded) ? $decoded : [];
        }

        if (!empty($machine['id']) && !empty($machine['token']) && !empty($machine['type'])) {
            return $machine;
        }

        $machine = [
            'id'    => $account->meta('machine_id', '') ?: uuid(),
            'token' => rtrim(strtr(base64_encode(substr(uuid() . uuid(), 0, 50)), '+/', '-_'), '='),
            'type'  => substr(bin2hex(random_bytes(9)), 0, 18),
        ];

        $this->accountRepository->patchMeta($account, ['machine' => $machine]);

        return $machine;
    }

    /** 老版请求签名：md5("{payload_b64}\n{cosy_key}\n{cosy_date}\n{body}\n{path}") */
    private function signRequest(string $payloadB64, string $cosyKey, string $date, string $body, string $path): string
    {
        return md5($payloadB64 . "\n" . $cosyKey . "\n" . $date . "\n" . $body . "\n" . $path);
    }

    /** RSA PKCS1v15 加密（temp_key → cosy_key） */
    private function rsaEncrypt(string $plain): string
    {
        $key = openssl_pkey_get_public(self::SERVER_PUBKEY_PEM);
        if ($key === false) {
            throw new \RuntimeException('Qoder 服务端公钥不可用');
        }

        $encrypted = '';
        if (!openssl_public_encrypt($plain, $encrypted, $key, OPENSSL_PKCS1_PADDING)) {
            throw new \RuntimeException('Qoder COSY 载荷 RSA 加密失败');
        }

        return $encrypted;
    }

    /** AES-128-CBC（PKCS7 填充，key==iv）加密身份 JSON → info */
    private function aesCbcEncrypt(string $plain, string $key): string
    {
        $key = substr($key . "\0", 0, 16);

        $encrypted = openssl_encrypt($plain, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $key);
        if ($encrypted === false) {
            throw new \RuntimeException('Qoder COSY 载荷 AES 加密失败');
        }

        return $encrypted;
    }

    /**
     * 老的 api* 子域是否属于「证书名错配」名单。
     *
     * 上游把 api1/api2/api3.qoder.com.cn 的流量接到了 .sh 生态的 LB 上，
     * 返回的证书是 `*.qoder.sh`，与本机请求的 SNI 不匹配。判定按**主机名后缀**
     * 白名单，而不是「无条件关校验」，避免以后上游修好证书还一直裸奔。
     */
    private function legacyEndpointNeedsSslRelax(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach (['api1.qoder.com.cn', 'api2.qoder.com.cn', 'api3.qoder.com.cn'] as $h) {
            if ($host === $h) {
                return true;
            }
        }

        return false;
    }

    /**
     * 解析老版协议事件流：兼容「直接 OpenAI chunk」与「{"body":"<json>"} 包装」两种形态。
     * 老版事件的正文在 body 字段内（字符串化的 JSON），需二次解析。
     */
    private function parseLegacySse(string $url, string $body, array $headers, Account $account, callable $onDelta): Chunk
    {
        $chunk = new Chunk(model: (string) $account->meta('model', ''));

        // ⚠️ 老版端点证书错配是**上游现状**、不是我们配错：
        // 实测 api1/api2/api3.qoder.com.cn 返回的是一张 `CN=*.qoder.sh` 证书
        // （这三个 IP 已并入 .sh 生态，.com.cn 的 api* 子域只是残留），
        // 于是任何对它们的请求都会以 `errno=60 SSL: no alternative certificate
        // subject name matches target host name` 失败 —— 这正是三家供应商全线
        // 报 NETWORK 的根因。api2-v2.qoder.com.cn（新版）DNS 已撤，老版 api3 是
        // **唯一仍存活的对话入口**，故此处仅对老版域名关闭证书校验名比对：
        // 传输本身仍是 TLS 加密，只是不再校验证书链覆盖的域名。
        // 作用域严格限定在本方法内，openapi（额度/换票，证书正确）不豁免。
        $client = $this->http()->withHeaders($headers)->timeout(HttpClient::STREAM_SAFE_TIMEOUT, 20);
        if ($this->legacyEndpointNeedsSslRelax($url)) {
            $client = $client->withoutSslVerify();
        }
        $info = $client->stream('POST', $url, $body, function (string $data) use (&$chunk, $onDelta) {
            if ($data === '[DONE]') {
                $chunk->completed = true;
                return false;
            }

            $decoded = json_decode($data, true);
            if (!is_array($decoded)) {
                return null;
            }

            if (isset($decoded['error'])) {
                throw new \RuntimeException((string) json_encode($decoded, JSON_UNESCAPED_UNICODE));
            }

            // ⚠️ 上游内部的 Java 异常也会以 HTTP 200 + `event:error` 投递（实测：
            // Encode=1 但请求体未编码时，AlgoServiceApiController.internalSseInvoke
            // 在 CustomBase64Util.decode0 抛异常，整段堆栈以 JSON 塞在 data 里）。
            // 不识别就会一路读到超时，把**协议错误**误报成传输层失败。
            if (isset($decoded['stackTrace']) || isset($decoded['traceId']) && isset($decoded['msgInfo'])) {
                $first = is_array($decoded['stackTrace'][0] ?? null) ? $decoded['stackTrace'][0] : [];
                $where = trim((string) ($first['className'] ?? '') . '.' . (string) ($first['methodName'] ?? ''), '.');
                throw new \RuntimeException(sprintf(
                    '%s 上游内部错误%s%s',
                    $this->id(),
                    (string) ($decoded['message'] ?? $decoded['msgInfo'] ?? ''),
                    $where !== '' ? "（{$where}）" : ''
                ));
            }

            // 老版包装：正文在 body 字段（字符串化 JSON），需二次解析
            if (isset($decoded['body']) && is_string($decoded['body'])) {
                if ($decoded['body'] === '[DONE]') {
                    $chunk->completed = true;
                    if ($chunk->finishReason === '') {
                        $chunk->finishReason = 'stop';
                    }
                    return false;
                }

                $inner = json_decode($decoded['body'], true);
                if (is_array($inner)) {
                    $decoded = $inner;
                }
            }

            // ⚠️ 包装帧里的**上游错误**必须就地抛出，不能只记在 raw 里往下走。
            // 实测老版端点会先回一帧 HTTP 200 + body=JSON 的错误信封，例如
            //   {"code":"101","message":"Signature invalid"}              身份没构造对
            //   {"code":"105","message":"Login expired"}                  身份令牌已失效
            // 随后连接不主动断（上游挂着等），早期实现会一路读到 `timeout(600, 20)`
            // 才超时，最后抛的却是「传输层超时」——把**认证问题**误报成网络问题，
            // 既拖慢一次请求 10 分钟，又把排查方向带偏。
            // 这里对齐新版分支的 `isset($decoded['error'])` 处置：见到 error/message
            // 且**尚未产出任何正文**时立刻抛，交给 parseError 分类。
            // 判据用 code+message 组合（message 为字符串且 code 存在），避免把
            // 正文里正常的 `message` 字段或增量帧误伤成错误。
            if ($chunk->content === '' && isset($decoded['code'], $decoded['message'])
                && is_string($decoded['message']) && $decoded['message'] !== ''
                && !isset($decoded['choices']) && !isset($decoded['output'])) {
                throw new \RuntimeException(sprintf(
                    '%s %s（上游 code=%s）',
                    $this->id(),
                    (string) $decoded['message'],
                    (string) $decoded['code']
                ));
            }

            $chunk->raw = $decoded;
            $chunk->id = $chunk->id ?: (string) ($decoded['id'] ?? '');

            $text = $this->extractLegacyDelta($decoded);
            if ($text !== '') {
                $chunk->content .= $text;
                if ($onDelta($text, $decoded) === false) {
                    return false;
                }
            }

            if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                $this->applyUsage($chunk, $decoded['usage']);
            }

            return null;
        });

        if (!empty($info['error'])) {
            Logger::warn("{$this->id()} 老版流式请求异常", $info);
        }

        // 传输层失败必须抛出（同 AbstractProvider::parseOpenAiSse 的处置）：
        // 早期只记日志、返回空 Chunk → 网关注解成「出口层抑制正文」→ 重采样到
        // CONTENT_BLOCKED 回 400，把 DNS/TLS 故障误报成「内容被拦截」。
        // 实测 api3.qoder.com.cn 存在 `errno=60 SSL: no alternative certificate
        // subject name matches target host name`，正是被这条吞掉的。
        // ⚠️ 排除主动中断（errno=23 由回调返回 false 触发，属预期）。
        if (!empty($info['errno']) && empty($info['aborted']) && $chunk->content === '') {
            throw new \RuntimeException(sprintf(
                '%s 老版请求失败（传输层）：%s',
                $this->id(),
                (string) $info['error']
            ));
        }

        if (!$chunk->completed && $chunk->finishReason === '' && $chunk->content !== '') {
            $chunk->finishReason = 'stop';
        }

        return $chunk;
    }

    /** 从老版事件中提取增量文本（兼容 llm_model_result / delta.content 等结构） */
    private function extractLegacyDelta(array $decoded): string
    {
        $candidates = [
            $decoded['llm_model_result'] ?? null,
            $decoded['content'] ?? null,
            $decoded['delta']['content'] ?? null,
            $decoded['choices'][0]['delta']['content'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function applyUsage(Chunk $chunk, array $usage): void
    {
        $chunk->promptTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? $chunk->promptTokens);
        $chunk->completionTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? $chunk->completionTokens);
        $chunk->totalTokens = (int) ($usage['total_tokens'] ?? 0);
        $this->applyCacheUsage($chunk, $usage);
    }

    // ---------------------------------------------------------
    // 模型
    // ---------------------------------------------------------

    public function models(Account $account): array
    {
        // 优先拉上游真实模型表（同一份数据也用于 modelCatalog，避免两次网络往返）
        $catalog = $this->fetchUpstreamModelList($account);
        if (!empty($catalog)) {
            return array_map(fn(array $m) => (string) $m['model_id'], $catalog);
        }

        // 上游不可达：退回本地目录（管理员可手工增删/开关）
        $models = [];
        foreach ($this->modelRepository->all($this->providerKeyOf($account)) as $model) {
            if (!empty($model['enabled'])) {
                $models[] = (string) $model['model_id'];
            }
        }

        if (!empty($models)) {
            return $models;
        }

        return array_keys($this->builtinModels());
    }

    /**
     * 模型目录归属的 provider_key。
     *
     * ⚠️ 以**账号自带的 provider_key** 为准，而不是 `$this->id()`。
     * 历史上 ProviderManager 让同区域两个 key 共享实例，`$this->id()` 会被后注入的
     * key 覆盖 → 国内账号读到 qoder_intl 的模型行（用户可见症状「模型全是国际版的」）。
     * 账号的 provider_key 是权威来源，注入状态异常时也不会读错表。
     */
    private function providerKeyOf(Account $account): string
    {
        $key = trim($account->providerKey);

        return $key !== '' ? $key : $this->id();
    }

    /** 按区域取内置兜底表 */
    private function builtinModels(): array
    {
        return $this->isIntlProvider() ? self::BUILTIN_MODELS_INTL : self::BUILTIN_MODELS_CN;
    }

    /**
     * 拉取上游真实模型表（`GET {SSE}/algo/api/v2/model/list`，COSY 签名）。
     *
     * 只取 `chat` 组（对话可选模型），过滤 `enable=false` 的行，按 return 顺序输出。
     * 拉取失败（网络/签名/无账号）返回空数组，由调用方回退本地目录 —— 绝不让
     * 一次上游抖动把整个模型列表清空。
     *
     * @return list<array<string,mixed>>
     */
    private function fetchUpstreamModelList(Account $account): array
    {
        $url = rtrim($this->sseBase(), '/') . self::MODEL_LIST_PATH;
        try {
            // GET 请求：签名时 body 取空串（上游按请求体内容算 md5，GET 无体）
            $headers = $this->legacyHeaders($account, $url, '', 'application/json');
            $client  = $this->http()->withHeaders($headers)->timeout(20, 10);
            if ($this->legacyEndpointNeedsSslRelax($url)) {
                $client = $client->withoutSslVerify();
            }
            $response = $client->get($url);
            if (!$response->ok()) {
                Logger::warn("{$this->id()} 模型列表拉取失败", [
                    'uid'    => $account->uid,
                    'status' => $response->status(),
                ]);
                return [];
            }
            $json = $response->json();
        } catch (\Throwable $e) {
            Logger::warn("{$this->id()} 模型列表拉取异常", [
                'uid'   => $account->uid,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $rows = $json['chat'] ?? null;
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            // 账号未开放的模型不下发
            if (array_key_exists('enable', $row) && $row['enable'] === false) {
                continue;
            }
            $seen[$key] = true;

            $display    = trim((string) ($row['display_name'] ?? '')) ?: $key;
            $reasoning  = !empty($row['is_reasoning']);
            $multimodal = !empty($row['is_vl']);
            $ctx        = isset($row['max_input_tokens']) && is_numeric($row['max_input_tokens'])
                ? (int) $row['max_input_tokens']
                : null;
            // price_factor 即 Credits 倍率；0 表示限时免费，仍如实采集
            $rate = isset($row['price_factor']) && is_numeric($row['price_factor'])
                ? (float) $row['price_factor']
                : null;

            $capabilities = ['stream'];
            if ($multimodal) {
                $capabilities[] = 'vision';
            }
            if ($reasoning) {
                $capabilities[] = 'reasoning';
            }

            $out[] = [
                'model_id'         => $key,
                'display_name'     => $display,
                'rate'             => $rate,
                'rate_source'      => $rate !== null ? self::RATE_SOURCE : '',
                'fee_level'        => null,
                'entitlement_cost' => null,
                'context_window'   => $ctx,
                'max_tokens'       => null,
                'multimodal'       => $multimodal,
                'reasoning'        => $reasoning,
                'capabilities'     => $capabilities,
            ];
        }

        return $out;
    }

    /**
     * 国际版专有直通模型的元数据行（上游 model/list 未列出、但端点真实可用）。
     *
     * @return list<array<string,mixed>>
     */
    private function extraIntlModels(): array
    {
        if (!$this->isIntlProvider()) {
            return [];
        }

        $out = [];
        foreach (self::EXTRA_MODELS_INTL as $key => [$display, $ctx, $multimodal, $reasoning, $rate]) {
            $capabilities = ['stream'];
            if ($multimodal) {
                $capabilities[] = 'vision';
            }
            if ($reasoning) {
                $capabilities[] = 'reasoning';
            }

            $out[] = [
                'model_id'         => $key,
                'display_name'     => $display,
                'rate'             => $rate,
                'rate_source'      => $rate !== null ? self::RATE_SOURCE : '',
                'fee_level'        => null,
                'entitlement_cost' => null,
                'context_window'   => $ctx,
                'max_tokens'       => null,
                'multimodal'       => $multimodal,
                'reasoning'        => $reasoning,
                'capabilities'     => $capabilities,
            ];
        }

        return $out;
    }

    public function modelCatalog(Account $account): array
    {
        // 上游优先：拿到真·模型表（key 才是要传进 model_config.key 的值）
        $upstream = $this->fetchUpstreamModelList($account);
        if (!empty($upstream)) {
            // 国际版再把「上游 list 未列、端点真实可用」的直通模型并进来，按 key 去重
            $seen = [];
            foreach ($upstream as $row) {
                $seen[(string) $row['model_id']] = true;
            }
            foreach ($this->extraIntlModels() as $row) {
                if (!isset($seen[(string) $row['model_id']])) {
                    $upstream[] = $row;
                }
            }

            return $upstream;
        }

        // 回退：本地目录命中的用本地，未命中的用区域内置兜底表补元数据
        $builtin = $this->builtinModels();
        $rows = $this->modelRepository->all($this->providerKeyOf($account));

        $enabled = [];
        foreach ($rows as $row) {
            if (!empty($row['enabled'])) {
                $enabled[(string) $row['model_id']] = true;
            }
        }

        $ids = array_keys($enabled) ?: array_keys($builtin);

        // 兜底路径同样补上国际版专有直通模型（本地库可能还没这行）
        foreach ($this->extraIntlModels() as $row) {
            $mid = (string) $row['model_id'];
            if (!in_array($mid, $ids, true)) {
                $ids[] = $mid;
            }
        }

        $out = [];
        foreach ($ids as $modelId) {
            // 元数据查找顺序：内置兜底表 → 国际版专有直通表 → 无（纯出一行 id）
            $meta = $builtin[$modelId] ?? self::EXTRA_MODELS_INTL[$modelId] ?? null;
            $display = $meta[0] ?? $modelId;
            $multimodal = (bool) ($meta[2] ?? false);
            $reasoning = (bool) ($meta[3] ?? false);
            $rate = isset($meta[4]) ? (float) $meta[4] : null;

            $capabilities = ['stream'];
            if ($multimodal) {
                $capabilities[] = 'vision';
            }
            if ($reasoning) {
                $capabilities[] = 'reasoning';
            }

            $out[] = [
                'model_id'         => $modelId,
                'display_name'     => $display,
                'rate'             => $rate,
                'rate_source'      => $rate !== null ? self::RATE_SOURCE : '',
                'fee_level'        => null,
                'entitlement_cost' => null,
                'context_window'   => $meta[1] ?? null,
                'max_tokens'       => null,
                'multimodal'       => $multimodal,
                'reasoning'        => $reasoning,
                'capabilities'     => $capabilities,
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------
    // 额度：GET {OPENAPI}/api/v2/quota/usage
    //       GET {OPENAPI}/sash/api/v2/me/usage（CLI 实际使用的权威接口，含额度包）
    //       国内 / 国际版共用同一路径，仅域名不同。
    // ---------------------------------------------------------

    public function credits(Account $account): CreditInfo
    {
        $paths = ['/api/v2/quota/usage', '/sash/api/v2/me/usage'];
        $lastInfo = null;
        $lastError = null;

        foreach ($paths as $path) {
            try {
                $response = $this->http()
                    ->withHeaders($this->authHeaders($account))
                    ->timeout(20, 10)
                    ->get(rtrim($this->openapiBase(), '/') . $path);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                continue;
            }

            if (!$response->ok()) {
                $lastError = '上游返回 HTTP ' . $response->status();
                continue;
            }

            $info = $this->parseQuota($this->unwrapQuotaPayload($response->json()));

            if ($info->known) {
                return $info;
            }

            $lastInfo  = $info;
            $lastError = $info->error ?? ($info->detail !== '' ? $info->detail : $lastError);
        }

        return $lastInfo ?? CreditInfo::failure($lastError ?? '未获取到额度信息');
    }

    /**
     * 拆解额度响应信封：data / qoderUsage 可能逐层内嵌。
     * 例如国际版返回 {"displayMode":"qoder","qoderUsage":{...userQuota...}}。
     */
    private function unwrapQuotaPayload(array $data): array
    {
        foreach (['data', 'qoderUsage'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $data = $data[$key];
            }
        }

        return $data;
    }

    /**
     * 解析额度响应。兼容常见信封：
     *  - total_quota / used_quota / remaining_quota
     *  - quota.limit / quota.used / quota.remaining
     *  - 各模型分桶列表
     */
    private function parseQuota(array $payload): CreditInfo
    {
        $limit  = $this->firstNumeric($payload, ['total_quota', 'total', 'limit', 'quota_limit']);
        $used   = $this->firstNumeric($payload, ['used_quota', 'used', 'usage']);
        $remain = $this->firstNumeric($payload, ['remaining_quota', 'remaining', 'remain', 'left']);

        $quota = is_array($payload['quota'] ?? null) ? $payload['quota'] : [];
        if (is_array($payload['userQuota'] ?? null)) {
            $quota = array_merge($quota, $payload['userQuota']);
        }
        if ($limit === null) {
            $limit = $this->firstNumeric($quota, ['limit', 'total', 'total_quota']);
        }
        if ($used === null) {
            $used = $this->firstNumeric($quota, ['used', 'used_quota']);
        }
        if ($remain === null) {
            $remain = $this->firstNumeric($quota, ['remaining', 'remain', 'left', 'remaining_quota']);
        }

        $packs = $this->parseQuotaPacks($payload);

        if ($remain === null && $limit !== null && $used !== null) {
            $remain = max(0.0, $limit - $used);
        }

        // 逐模型分桶可汇总出总额度
        if ($remain === null && $limit === null && $packs !== []) {
            foreach ($packs as $pack) {
                $limit = ($limit ?? 0.0) + $pack['limit'];
                $used = ($used ?? 0.0) + $pack['used'];
                $remain = ($remain ?? 0.0) + $pack['remain'];
            }
        }

        if ($limit === null && $used === null && $remain === null && $packs === []) {
            return CreditInfo::unknown('Qoder 未返回额度字段');
        }

        $detail = sprintf(
            '%s 额度：剩余 %s / 共 %s',
            $this->displayName(),
            $remain === null ? '-' : number_format($remain, 0),
            $limit === null ? '-' : number_format($limit, 0)
        );

        $expireAt = $this->parseExpireAt($payload);

        return new CreditInfo(
            known: $remain !== null,
            balance: $remain,
            detail: $detail,
            packs: $packs,
            usage: [
                'total'  => (float) ($limit ?? 0),
                'used'   => (float) ($used ?? 0),
                'remain' => (float) ($remain ?? 0),
            ],
            expireAt: $expireAt,
        );
    }

    /** 逐模型额度分桶 → packs；无法识别返回空数组 */
    private function parseQuotaPacks(array $payload): array
    {
        $list = null;
        foreach (['details', 'models', 'items', 'quotas', 'buckets'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && array_is_list($payload[$key])) {
                $list = $payload[$key];
                break;
            }
        }

        if (!is_array($list)) {
            return [];
        }

        $packs = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $limit  = $this->firstNumeric($item, ['limit', 'total', 'total_quota', 'quota']);
            $used   = $this->firstNumeric($item, ['used', 'used_quota']);
            $remain = $this->firstNumeric($item, ['remaining', 'remain', 'left', 'remaining_quota']);
            if ($remain === null && $limit !== null && $used !== null) {
                $remain = max(0.0, $limit - $used);
            }
            if ($limit === null && $used === null && $remain === null) {
                continue;
            }

            $name = (string) ($item['model'] ?? ($item['model_id'] ?? ($item['name'] ?? 'Qoder')));
            $packs[] = [
                'name'   => $name,
                'group'  => (string) ($item['group'] ?? $item['bucket'] ?? 'qoder'),
                'limit'  => (float) ($limit ?? 0),
                'used'   => (float) ($used ?? 0),
                'remain' => (float) ($remain ?? 0),
            ];
        }

        return $packs;
    }

    private function firstNumeric(array $source, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && is_numeric($source[$key])) {
                return (float) $source[$key];
            }
        }

        return null;
    }

    /** 归一化到期时间为毫秒（支持秒/毫秒时间戳、ISO8601 字符串） */
    private function parseExpireAt(array $payload): int
    {
        $raw = $payload['expire_at']
            ?? $payload['expires_at']
            ?? $payload['expired_at']
            ?? $payload['expire_time']
            ?? $payload['expiresAt']
            ?? null;

        if ($raw === null || $raw === '') {
            return 0;
        }

        if (is_numeric($raw)) {
            $n = (float) $raw;
            if ($n > 1e12) {
                return (int) $n;
            }
            if ($n > 1e9) {
                return (int) ($n * 1000);
            }
            return 0;
        }

        if (is_string($raw)) {
            $ts = strtotime($raw);
            if ($ts !== false && $ts > 0) {
                return $ts * 1000;
            }
        }

        return 0;
    }

    // ---------------------------------------------------------
    // 动作：测试 / 签到（每日额度刷新）
    // ---------------------------------------------------------

    public function action(Account $account, string $action, array $params = []): array
    {
        return match ($action) {
            'test'        => $this->actionTest($account),
            'checkin'     => $this->actionCheckin($account),
            'sync_models' => $this->actionSyncModels($account),
            default       => ['ok' => false, 'message' => "{$this->displayName()} 不支持动作：{$action}", 'data' => null],
        };
    }

    private function actionTest(Account $account): array
    {
        try {
            $response = $this->http()
                ->withHeaders($this->authHeaders($account))
                ->timeout(20, 10)
                ->get(rtrim($this->openapiBase(), '/') . '/api/v2/quota/usage');
        } catch (\Throwable $e) {
            $parsed = $this->parseError(0, $e->getMessage());
            return [
                'ok'      => false,
                'message' => $parsed['message'] ?? ('自检失败：' . $e->getMessage()),
                'data'    => ['error_code' => $parsed['code'] ?? ErrorCategory::UPSTREAM],
            ];
        }

        if (!$response->ok()) {
            $parsed = $this->parseError($response->status(), $response->body());
            return [
                'ok'      => false,
                'message' => $parsed['message'] ?? ('连通失败（HTTP ' . $response->status() . '）'),
                'data'    => ['status' => $response->status(), 'error_code' => $parsed['code'] ?? ErrorCategory::UPSTREAM],
            ];
        }

        $info = $this->parseQuota($this->unwrapQuotaPayload($response->json()));

        return [
            'ok'      => true,
            'message' => '连通正常',
            'data'    => [
                'status'  => $response->status(),
                'credits' => $info->balance,
                'detail'  => $info->detail,
            ],
            'credits' => $info->balance,
        ];
    }

    /**
     * 签到：Qoder 无独立签到接口，等价于「每日额度领取/刷新」。
     * 调用额度接口确认凭据有效并回写当次额度，视为当日打卡成功。
     */
    private function actionCheckin(Account $account): array
    {
        if ($account->isCheckedInToday()) {
            return [
                'ok'      => true,
                'message' => '今日已签到',
                'data'    => ['checked_in' => true, 'credits' => $account->creditsKnown ? $account->credits : null],
                'credits' => $account->creditsKnown ? $account->credits : null,
            ];
        }

        $info = $this->credits($account);

        if ($info->error !== null) {
            return [
                'ok'      => false,
                'message' => '签到失败：' . $info->error,
                'data'    => ['error_code' => ErrorCategory::UPSTREAM],
            ];
        }

        if (!$info->known) {
            return [
                'ok'      => false,
                'message' => '签到失败：' . ($info->detail !== '' ? $info->detail : '未获取到额度信息'),
                'data'    => ['error_code' => ErrorCategory::UPSTREAM],
            ];
        }

        $balance = (float) ($info->balance ?? 0);

        // Qoder 无独立签到接口，仅刷新额度；无可用额度时如实回执，避免误报「签到成功」
        if ($balance <= 0) {
            return [
                'ok'      => true,
                'message' => '额度已刷新：当前账号暂无可领取额度（Qoder 无独立签到接口）',
                'data'    => ['checked_in' => true, 'detail' => $info->detail, 'usage' => $info->usage],
                'credits' => $info->balance,
            ];
        }

        return [
            'ok'      => true,
            'message' => '签到成功（额度已刷新）',
            'data'    => ['checked_in' => true, 'detail' => $info->detail, 'usage' => $info->usage],
            'credits' => $info->balance,
        ];
    }

    private function actionSyncModels(Account $account): array
    {
        $catalog = $this->modelCatalog($account);
        $result = $this->modelRepository->syncCatalog($this->id(), $catalog);

        return [
            'ok'      => !empty($catalog),
            'message' => $catalog
                ? sprintf('同步 %d 个模型，新增 %d 个，更新 %d 个', count($catalog), $result['created'], $result['updated'])
                : '未获取到模型',
            'data'    => $catalog,
        ];
    }

    // ---------------------------------------------------------
    // 凭据刷新：drt- / jrt- 刷新令牌
    // ---------------------------------------------------------

    public function refreshToken(Account $account): array
    {
        $refresh = $this->refreshTokenValue($account);

        if ($refresh !== '') {
            $path = '/api/v1/deviceToken/refresh';
            $key  = 'device_token';
        } else {
            $refresh = $this->jobRefreshToken($account);
            $path = '/api/v1/jobToken/refresh';
            $key  = 'token';
        }

        if ($refresh === '') {
            return [];
        }

        try {
            $response = $this->http()
                ->withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent'   => self::USER_AGENT,
                ])
                ->timeout(20, 10)
                ->postJson(rtrim($this->openapiBase(), '/') . $path, ['refresh_token' => $refresh]);
        } catch (\Throwable $e) {
            Logger::warn("{$this->id()} 凭据刷新异常", ['uid' => $account->uid, 'error' => $e->getMessage()]);
            return [];
        }

        if (!$response->ok()) {
            Logger::warn("{$this->id()} 凭据刷新返回异常状态", ['uid' => $account->uid, 'status' => $response->status()]);
            return [];
        }

        $data = $response->json();
        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;

        $newToken = trim((string) ($payload[$key] ?? ($payload['access_token'] ?? '')));
        $newRefresh = trim((string) ($payload['refresh_token'] ?? ''));

        if ($newToken === '') {
            return [];
        }

        return array_filter([
            'access_token'  => $newToken,
            'refresh_token' => $newRefresh !== '' ? $newRefresh : null,
        ], static fn ($v) => $v !== null);
    }

    // ---------------------------------------------------------
    // 登录：PKCE Device Flow
    // ---------------------------------------------------------

    public function startLogin(array $params = []): LoginSession
    {
        $sessionId = uuid();

        $verifier = $this->generateVerifier();
        $challenge = $this->base64UrlEncode(hash('sha256', $verifier, true));
        $nonce = uuid();
        $machineId = $this->generateMachineId();

        $authUrl = $this->authWebBase() . '/device/selectAccounts?' . http_build_query([
            'challenge'        => $challenge,
            'challenge_method' => 'S256',
            'nonce'            => $nonce,
            'machine_id'       => $machineId,
            'client_id'        => self::CLIENT_ID,
        ]);

        $this->saveLoginSession($sessionId, [
            'state'      => 'waiting',
            'provider'   => $this->id(),
            'verifier'   => $verifier,
            'challenge'  => $challenge,
            'nonce'      => $nonce,
            'machine_id' => $machineId,
            'auth_url'   => $authUrl,
            'created_at' => now_ms(),
        ]);

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'waiting',
            message: '请在浏览器中打开授权链接完成登录',
            authUrl: $authUrl,
            intervalSeconds: (int) (self::POLL_INTERVAL_MS / 1000),
            expiresInSeconds: (int) (self::POLL_TIMEOUT_MS / 1000),
            extra: ['nonce' => $nonce, 'machine_id' => $machineId],
        );
    }

    public function pollLogin(string $sessionId, array $params = []): LoginSession
    {
        $session = $this->loadLoginSession($sessionId);

        if (!is_array($session)) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'failed',
                message: '登录会话不存在或已过期，请重新发起',
                error: 'session_missing'
            );
        }

        $nonce = (string) ($session['nonce'] ?? '');
        $verifier = (string) ($session['verifier'] ?? '');

        if ($nonce === '' || $verifier === '') {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'failed',
                message: '登录会话缺少必要参数，请重新发起',
                error: 'session_invalid'
            );
        }

        $createdAt = (int) ($session['created_at'] ?? 0);
        if ($createdAt > 0 && now_ms() - $createdAt > self::POLL_TIMEOUT_MS) {
            $this->updateLoginSession($sessionId, ['state' => 'expired']);
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'expired',
                message: '授权已超时，请重新发起登录',
                error: 'expired'
            );
        }

        try {
            $response = $this->http()
                ->withHeaders([
                    'Accept'     => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ])
                ->timeout(20, 10)
                ->get(rtrim($this->openapiBase(), '/') . '/api/v1/deviceToken/poll', [
                    'nonce'            => $nonce,
                    'verifier'         => $verifier,
                    'challenge_method' => 'S256',
                ]);
        } catch (\Throwable $e) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'waiting',
                message: '轮询异常，稍后重试：' . $e->getMessage(),
            );
        }

        // 404 表示尚未完成授权，继续等待
        if ($response->status() === 404) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'waiting',
                message: '等待授权中',
            );
        }

        if (!$response->ok()) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'waiting',
                message: '等待授权中（HTTP ' . $response->status() . '）',
            );
        }

        $data = $response->json();
        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;

        $token = trim((string) ($payload['token'] ?? ($payload['device_token'] ?? '')));
        if ($token === '') {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'waiting',
                message: '等待授权中',
            );
        }

        $uid = trim((string) ($payload['user_id'] ?? ($payload['uid'] ?? '')));
        if ($uid === '') {
            $uid = 'qd_' . substr(hash('sha256', $token), 0, 16);
        }

        $result = $this->ingestCredential([
            'access_token'        => $token,
            'refresh_token'       => (string) ($payload['refresh_token'] ?? ''),
            'user_id'             => $uid,
            'expires_at'          => (string) ($payload['expires_at'] ?? ''),
            'refresh_expires_at'  => (string) ($payload['refresh_token_expires_at'] ?? ''),
            'machine_id'          => (string) ($session['machine_id'] ?? ''),
        ]);

        if (empty($result['ok'])) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'failed',
                message: (string) ($result['message'] ?? '导入凭据失败'),
                error: 'ingest_failed'
            );
        }

        $this->updateLoginSession($sessionId, [
            'state'       => 'success',
            'account_uid' => (string) ($result['account_uid'] ?? ''),
            'finished_at' => now_ms(),
        ]);

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'success',
            message: '登录成功',
            accountUid: (string) ($result['account_uid'] ?? ''),
            nickname: (string) ($result['nickname'] ?? ''),
        );
    }

    /**
     * PAT 兑换：POST {CENTER}/algo/api/v3/user/jobToken?Encode=1
     * 用个人令牌（PAT / personalToken）换取 dt- 访问令牌 + drt- 刷新令牌 + 完整身份。
     * 参考 QoderGateway/src/qoder2api/auth.py::exchange_job_token。
     *
     * @return array{ok:bool,message?:string,access_token?:string,refresh_token?:string,user_id?:string,nickname?:string,user_type?:string,machine?:array}
     */
    public function exchangePat(string $pat): array
    {
        $pat = trim($pat);
        if ($pat === '') {
            return ['ok' => false, 'message' => 'PAT 不能为空'];
        }

        $machine = [
            'id'    => uuid(),
            'token' => rtrim(strtr(base64_encode(substr(uuid() . uuid(), 0, 50)), '+/', '-_'), '='),
            'type'  => substr(bin2hex(random_bytes(9)), 0, 18),
        ];

        $inner = [
            'personalToken'      => $pat,
            'securityOauthToken' => '',
            'refreshToken'       => '',
            'needRefresh'        => false,
            'authInfo'           => new \stdClass(),
        ];

        $body = $this->encodeCustom(json_encode([
            'payload'       => json_encode($inner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'encodeVersion' => '1',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $url = rtrim($this->centerBase(), '/') . '/algo/api/v3/user/jobToken?Encode=1';

        try {
            $response = $this->http()
                ->withHeaders([
                    'cosy-machinetoken' => $machine['token'],
                    'cosy-machinetype'  => $machine['type'],
                    'login-version'     => 'v2',
                    'appcode'           => self::SIGN_APPCODE,
                    'Accept'            => 'application/json',
                    'cosy-version'      => self::COSY_VERSION,
                    'cosy-clienttype'   => self::COSY_CLIENT_ID,
                    'date'              => $date,
                    'signature'         => $this->signDate($date),
                    'Content-Type'      => 'application/json',
                    'cosy-machineid'    => $machine['id'],
                    'User-Agent'        => self::USER_AGENT,
                ])
                ->timeout(20, 10)
                ->postRaw($url, $body);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'PAT 兑换异常：' . $e->getMessage()];
        }

        if (!$response->ok()) {
            return ['ok' => false, 'message' => 'PAT 兑换失败（HTTP ' . $response->status() . '）：' . $response->snippet()];
        }

        $data = $response->json();
        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;

        $token = trim((string) ($payload['securityOauthToken'] ?? ($payload['security_oauth_token'] ?? $payload['token'] ?? '')));
        $refresh = trim((string) ($payload['refreshToken'] ?? ($payload['refresh_token'] ?? '')));

        if ($token === '' && $refresh === '') {
            return ['ok' => false, 'message' => 'PAT 兑换未返回令牌'];
        }

        return [
            'ok'            => true,
            'access_token'  => $token,
            'refresh_token' => $refresh,
            'user_id'       => trim((string) ($payload['id'] ?? ($payload['uid'] ?? ''))),
            'nickname'      => trim((string) ($payload['name'] ?? '')),
            'user_type'     => trim((string) ($payload['userType'] ?? ($payload['user_type'] ?? ''))),
            'machine'       => $machine,
        ];
    }

    /**
     * 回调兜底：Qoder 为服务端轮询流程，不产生浏览器回调。
     * 支持两种手动导入：
     *   - 直接粘贴 dt-/drt- 令牌
     *   - 粘贴 PAT（不以 dt-/drt-/jrt- 开头时按 PAT 兑换）
     */
    public function handleCallback(array $query): array
    {
        $token = trim((string) ($query['access_token'] ?? $query['token'] ?? ''));
        if ($token === '') {
            return ['ok' => false, 'message' => 'Qoder 使用设备码轮询登录，无需回调；如需手动导入请提供 access_token / PAT'];
        }

        if (!$this->looksLikeQoderToken($token)) {
            return $this->ingestCredential(['personal_token' => $token]);
        }

        return $this->ingestCredential([
            'access_token'  => $token,
            'refresh_token' => (string) ($query['refresh_token'] ?? ''),
            'user_id'       => (string) ($query['user_id'] ?? ''),
        ]);
    }

    /** 判断是否为 Qoder 原生令牌（dt-/drt-/jrt- 前缀） */
    private function looksLikeQoderToken(string $token): bool
    {
        foreach (['dt-', 'drt-', 'jrt-'] as $prefix) {
            if (str_starts_with($token, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function ingestCredential(array $credential): array
    {
        // 若提供的是 PAT / personalToken，先兑换为 dt-/drt- 令牌
        $pat = trim((string) ($credential['personal_token'] ?? $credential['personalToken'] ?? $credential['pat'] ?? ''));
        if ($pat === '') {
            $maybe = trim((string) ($credential['access_token'] ?? $credential['token'] ?? ''));
            if ($maybe !== '' && !$this->looksLikeQoderToken($maybe)) {
                $pat = $maybe;
            }
        }

        if ($pat !== '') {
            $exchanged = $this->exchangePat($pat);
            if (empty($exchanged['ok'])) {
                return ['ok' => false, 'message' => (string) ($exchanged['message'] ?? 'PAT 兑换失败')];
            }

            $credential['access_token']  = (string) ($exchanged['access_token'] ?? '');
            $credential['refresh_token'] = (string) ($exchanged['refresh_token'] ?? '');
            $credential['user_id']       = (string) ($exchanged['user_id'] ?? ($credential['user_id'] ?? ''));
            $credential['nickname']      = (string) ($exchanged['nickname'] ?? ($credential['nickname'] ?? ''));
            $credential['user_type']     = (string) ($exchanged['user_type'] ?? '');
            $credential['machine']       = $exchanged['machine'] ?? [];
        }

        $token = trim((string) ($credential['access_token'] ?? $credential['accessToken'] ?? $credential['token'] ?? $credential['device_token'] ?? ''));
        $apiKey = trim((string) ($credential['api_key'] ?? $credential['apiKey'] ?? ''));

        $effective = $token !== '' ? $token : $apiKey;
        if ($effective === '') {
            return ['ok' => false, 'message' => 'Qoder 凭据缺少 access_token / api_key'];
        }

        $uid = trim((string) ($credential['user_id'] ?? $credential['uid'] ?? ''));
        if ($uid === '') {
            $uid = 'qd_' . substr(hash('sha256', $effective), 0, 16);
        }

        $nickname = (string) ($credential['nickname'] ?? $credential['name'] ?? '');

        $stored = ['access_token' => $effective];
        $refresh = trim((string) ($credential['refresh_token'] ?? ''));
        if ($refresh !== '') {
            $stored['refresh_token'] = $refresh;
        }
        if ($apiKey !== '') {
            $stored['api_key'] = $apiKey;
        }

        // upsertCredential 会整体覆盖 meta，这里先合并既有 meta 再补充本次字段
        $existing = $this->accountRepository->findByUid($this->id(), $uid);
        $meta = is_array($existing?->meta ?? null) ? $existing->meta : [];

        $meta = array_merge($meta, array_filter([
            'machine_id' => (string) ($credential['machine_id'] ?? ''),
            'avatar'     => (string) ($credential['avatar'] ?? ''),
            'expires_at' => (string) ($credential['expires_at'] ?? ''),
        ], static fn ($v) => $v !== ''));

        $machine = $credential['machine'] ?? [];
        if (is_array($machine) && $machine !== []) {
            $meta['machine'] = $machine;
            if (($meta['machine_id'] ?? '') === '') {
                $meta['machine_id'] = (string) ($machine['id'] ?? '');
            }
        }
        if (!empty($credential['user_type'])) {
            $meta['user_type'] = (string) $credential['user_type'];
        }

        $accountId = $this->accountRepository->upsertCredential($this->id(), $uid, $stored, $meta, $nickname);

        return [
            'ok'          => true,
            'message'     => $this->displayName() . ' 账号已保存',
            'account_uid' => $uid,
            'nickname'    => $nickname,
            'account_id'  => $accountId,
        ];
    }

    // ---------------------------------------------------------
    // 签名 / 编码 / 随机串辅助
    // ---------------------------------------------------------

    /** 老版 COSY 签名：md5("{appcode}&{secret}&{RFC1123 GMT date}") */
    private function signDate(string $date): string
    {
        return md5(self::SIGN_APPCODE . self::SIGN_SEP . self::SIGN_SECRET . self::SIGN_SEP . $date);
    }

    /**
     * Qoder 自定义编码（QoderEncoding）：标准 base64 → 三段重排 → 字符表映射。
     * 供老版协议请求体编码使用（Encode=1）。注意填充符 `=` 映射为 `$`。
     */
    public function encodeCustom(string $input): string
    {
        $standard = base64_encode($input);
        $len = strlen($standard);
        $split = intdiv($len, 3);

        $reordered = substr($standard, -$split) . substr($standard, $split, $len - 2 * $split) . substr($standard, 0, $split);

        $std = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';
        $custom = '_doRTgHZBKcGVjlvpC,@aFSx#DPuNJme&i*MzLOEn)sUrthbf%Y^w.(kIQyXqWA!$';

        return strtr($reordered, $std, $custom);
    }

    /** 生成 PKCE code_verifier（43~128 字符，允许字符集 66 字符） */
    private function generateVerifier(): string
    {
        $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
        $out = '';
        $max = strlen($charset) - 1;
        for ($i = 0; $i < 64; $i++) {
            $out .= $charset[random_int(0, $max)];
        }

        return $out;
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** 稳定的机器标识：优先取账号 meta，其次生成一次并持久化 */
    private function machineId(Account $account): string
    {
        $existing = trim((string) $account->meta('machine_id', ''));
        if ($existing !== '') {
            return $existing;
        }

        $generated = $this->generateMachineId();
        $this->accountRepository->patchMeta($account, ['machine_id' => $generated]);

        return $generated;
    }

    /** 稳定的会话标识：优先取账号 meta，其次生成一次并持久化 */
    private function sessionId(Account $account): string
    {
        $existing = trim((string) $account->meta('session_id', ''));
        if ($existing !== '') {
            return $existing;
        }

        $generated = uuid();
        $this->accountRepository->patchMeta($account, ['session_id' => $generated]);

        return $generated;
    }

    private function generateMachineId(): string
    {
        return uuid();
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
}