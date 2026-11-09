<?php
/**
 * WorkBuddy / CodeBuddy 适配器（腾讯 copilot）
 * 登录方式：DEVICE_CODE —— 后端申请 state，用户在浏览器完成授权，后端轮询换取 token
 * 主机：copilot.tencent.com / codebuddy.cn / workbuddy.ai
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
use App\Repositories\ProviderRepository;
use App\Repositories\ProxyRepository;
use App\Support\Logger;

class CodeBuddyProvider extends AbstractProvider
{
    /** 默认（CN）主机；global 账号改走 workbuddy.ai（对应 La2/b->q / ->D） */
    private const HOST_CN = 'https://copilot.tencent.com';
    private const HOST_GLOBAL_API = 'https://www.workbuddy.ai';
    private const HOST_GLOBAL_WEB = 'https://www.workbuddy.ai';
    private const HOST_CN_WEB = 'https://www.codebuddy.cn';

    /** 计费产品码（对应原 App 常量） */
    private const PRODUCT_CODE = 'p_tcaca';

    private const PLATFORM = 'CLI';
    private const REFRESH_SOURCE = 'workbuddy';

    /** 上游 CLI 版本号（对应 La2/b->s 的 User-Agent） */
    private const CLI_VERSION = '2.63.2';

    /** 登录/授权接口使用的客户端版本号 */
    private const VERSION = '0.1.0';

    /** 刷新阈值（秒）：剩余有效期低于该值时不再刷新（对应 La2/b->c 的阈值参数） */
    private const REFRESH_THRESHOLD_SECONDS = 300;

    /** 上游业务码：授权尚未完成 */
    private const CODE_LOGIN_PENDING = 11217;

    /**
     * 客户端同源**产品配置目录**：`GET {host}/v3/config`。
     *
     * 官方 WorkBuddy 国际版 Windows 客户端就是从这里取模型表 —— 证据（2026-10-04 本机实机）：
     *   · 安装目录 `D:\workbuddyai\resources\app.asar` 内 `main/server.js` 的
     *     `listAvailableModels()` 只调 `/v2/enterprises/personal/models`（我们原先唯一的数据源）；
     *   · 而客户端**缓存下来的产品配置** `%USERPROFILE%\.workbuddy-ai\cache\acc-product-config-v3.json`
     *     里 `models[]` 有 29 项，含 `deepseek-v4.1-flash` / `deepseek-v4.1-flash-sg` /
     *     `gpt-6-astra` / `kimi-k2.8-preview` / `glm-5.3-flash` 等；
     *   · 客户端日志（`.workbuddy-ai\logs\2026-10-04\*.log`）里那次取配置就是
     *     `https://www.workbuddy.ai/v3/config`，且会话日志里 `sendPrompt … "modelId":"deepseek-v4.1-flash"`。
     *
     * 结论：**「有哪些模型」的权威在国际版不是 `/v2|/console/enterprises/personal/models`，
     * 而是 `/v3/config` 的 `data.models[]`** —— 两者 id 并不一致（v3 独有
     * `deepseek-v4.1-flash`、`gpt-6-astra`…；v2 独有 `gpt-5.3-codex`、`auto`…），
     * 故 modelCatalog() 取**并集**，详见其文档注释。
     */
    private const CLIENT_CONFIG_PATH = '/v3/config';

    /**
     * 桌面客户端版本号：`/v3/config` 的返回**随客户端指纹变化**。
     *
     * 实测（2026-10-04，同一账号同一 token，仅换请求头）：
     *   · `User-Agent: CLI/2.63.2 CodeBuddy/2.63.2`（本适配器其余请求一直用的指纹）→ **22 项**；
     *   · `User-Agent: WorkBuddy/5.6.2 …` + `X-IDE-Name/X-IDE-Version` → **29 项**，
     *     多出 `grok-4.7` / `gpt-6-sol` / `gpt-6-luna` / `gemini-3.8-flash` / `hy4-preview-f`
     *     与两个生成类条目（`gpt-image-2.5-sunburst` / `seedance-2.5`），
     *     与官方客户端缓存 `acc-product-config-v3.json` 的 29 项**逐字一致**。
     *   · 这 5 个多出来的模型全部实测可用（CLI 通道 `POST /v2/chat/completions` HTTP 200、
     *     回执 `model` 与请求名一致、`tools` 回 `tool_calls`）。
     *
     * 故**只有「国际版实例 + 这一个只读配置请求」**用桌面指纹（其余 chat/额度/签到一律保持
     * CLI 指纹，不变），目的就是「客户端 UI 上能看到哪些模型，我们目录里就有哪些」。
     * 桌面头拿不到时自动回退 CLI 头（见 catalogFromClientConfig），不会因为指纹被拒而丢目录。
     *
     * 版本号：随官方客户端发布对齐（2026-10 官方已到 5.8.4）。桌面指纹只影响
     * /v3/config 的返回条目数，与 chat/额度通道无关，故按官方最新版本跟进。
     *
     * ⚠️ 为什么**国服不**用桌面指纹：国服同一端点按桌面指纹返回的 37 项里混着
     * **IDE 内部模型**（`codewise-completions` / `codewise-rewrite` / `codewise-nes-*` /
     * `codewise-jump` / `completion-gf` / `default-1.1` / `default-1.2` / `hunyuan-3b` /
     * `hunyuan-7b-dense` / `deepseek-v3-0324-taco-completion` …）—— 那是补全/NES 通道用的，
     * 塞进对话网关目录只会给用户一堆点了就废的条目。国际版的桌面目录不含这类条目
     * （29 项全是对话/档位模型 + 2 个生成模型），故只有国际版走桌面指纹。
     */
    private const DESKTOP_APP_VERSION = '5.8.4';

    // ---------------------------------------------------------
    // 国际版「网页通道」（console/as agent 会话 + ACP over HTTP+SSE）
    // ---------------------------------------------------------

    /**
     * 网页版 app 的「对话」不是 `chat/completions`，而是 `console/as` 下的 **agent 会话**：
     *   建会话 → 取沙箱 session（link/token）→ ACP over streamable HTTP + SSE
     *   （initialize / session/load / session/set_model / session/prompt）→ 跑到 completed。
     *
     * 上游「每日活跃」奖励认的是**跑完的 agent 会话**：
     *   · 桌面身分发 chat/completions **不计数**；
     *   · 只建会话、不接沙箱同样不计数（会话永远停在 CREATING）。
     * 因此国际版打卡必须在桌面端对话之外，再走一次这条网页通道并跑到 completed。
     *
     * 该链路只用 `Authorization: Bearer <accessToken>` 与 `X-User-Id` 两个凭据头，
     * 没有桌面端的 X-IDE-* 指纹，网关手里同一份账号凭据可以直接用。
     */
    private const WEB_CONVERSATIONS_PATH = '/console/as/conversations/';

    /** 网页通道 User-Agent（浏览器形态，非 CLI 指纹） */
    private const WEB_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0';

    /** 网页通道每日活跃对话使用的提示词 */
    private const WEB_DAILY_PROMPT = 'Hi';

    /** ACP 协议版本与客户端能力声明（与网页端一致：不接管文件系统与终端） */
    private const WEB_ACP_PROTOCOL_VERSION = 1;

    /** 网页通道单轮最长等待（秒） */
    private const WEB_TURN_TIMEOUT = 90;

    /** 网页通道轮询会话状态的间隔（秒） */
    private const WEB_TURN_POLL_INTERVAL = 3;

    /** 供应商 key：'codebuddy'（国服，默认 CN 主机） / 'codebuddy_intl'（国际版，默认 global 主机） */
    private const KEY_CN = 'codebuddy';
    private const KEY_INTL = 'codebuddy_intl';

    /** 当前适配器对应的供应商 key（由 ProviderManager 注入，默认国服） */
    protected string $providerKey = self::KEY_CN;

    public function __construct(
        ProxyRepository $proxyRepository,
        private AccountRepository $accountRepository,
        private ProviderRepository $providerRepository,
    ) {
        parent::__construct($proxyRepository);
    }

    /**
     * ProviderManager 在注册时注入具体 key：
     * - 'codebuddy'      → 国服（默认 CN 主机）
     * - 'codebuddy_intl' → 国际版（默认 global 主机）
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
        return $this->providerKey === self::KEY_INTL ? 'WorkBuddy 国际版' : 'WorkBuddy';
    }

    public function loginType(): string
    {
        return 'DEVICE_CODE';
    }

    public function hosts(): array
    {
        return $this->providerRepository->find($this->providerKey)['hosts']
            ?? ['copilot.tencent.com', 'codebuddy.cn', 'workbuddy.ai'];
    }

    /** 是否为国际版供应商（默认主机/授权页走 workbuddy.ai） */
    private function isIntlProvider(): bool
    {
        return $this->providerKey === self::KEY_INTL;
    }

    /** 国际版默认域名（供无 domain 的凭据默认认为海外账号） */
    private const DEFAULT_INTL_DOMAIN = 'workbuddy.ai';

    // ---------------------------------------------------------
    // 主机解析（还原 La2/b->q / ->D）
    // ---------------------------------------------------------

    /** 域名为 global（workbuddy.ai）时返回 true（还原 La2/b->D） */
    private function isGlobal(Account $account): bool
    {
        return $this->hostForDomain((string) $account->cred('domain', '')) === self::HOST_GLOBAL_API;
    }

    /**
     * 判断域名是否属于海外（workbuddy.ai）。
     * 国际版供应商在域名为空时默认按海外处理（授权页/默认主机走 workbuddy.ai）。
     */
    private function isGlobalDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        if ($domain !== '' && (str_contains($domain, '.workbuddy.ai') || $domain === 'workbuddy.ai')) {
            return true;
        }

        return $domain === '' && $this->isIntlProvider();
    }

    /**
     * 按域名解析 API 主机：global → www.workbuddy.ai，否则 copilot.tencent.com（还原 La2/b->D + ->q）
     */
    private function hostForDomain(string $domain): string
    {
        return $this->isGlobalDomain($domain) ? self::HOST_GLOBAL_API : self::HOST_CN;
    }

    /** API 主机：global → www.workbuddy.ai，否则 copilot.tencent.com（还原 La2/b->q） */
    private function hostChat(Account $account): string
    {
        return $this->hostForDomain((string) $account->cred('domain', ''));
    }

    /** 计费/网页主机：global → www.workbuddy.ai，否则 www.codebuddy.cn（还原 La2/b->m / ->d） */
    private function hostWeb(Account $account): string
    {
        return $this->isGlobal($account) ? self::HOST_GLOBAL_WEB : self::HOST_CN_WEB;
    }

    /** 授权/登录主机：国际版默认 workbuddy.ai，国服 copilot.tencent.com */
    private function hostLogin(): string
    {
        return $this->isIntlProvider() ? self::HOST_GLOBAL_API : self::HOST_CN;
    }

    // ---------------------------------------------------------
    // 请求头构造（对应原 App 的 s() / r() / C() / p() 四套）
    // ---------------------------------------------------------

    /**
     * 基础头：还原 La2/b->s
     *
     * Content-Type / Accept / X-Requested-With / User-Agent（CLI/2.63.2 CodeBuddy/2.63.2）
     * 以及按域名解析出的 Origin / Referer。
     */
    private function baseHostHeaders(Account $account): array
    {
        $origin = $this->hostWeb($account);

        return [
            'Content-Type'     => 'application/json',
            'Accept'           => 'application/json, text/plain, */*',
            'X-Requested-With' => 'XMLHttpRequest',
            'User-Agent'       => 'CLI/' . self::CLI_VERSION . ' CodeBuddy/' . self::CLI_VERSION,
            'Origin'           => $origin,
            'Referer'          => $origin . '/',
        ];
    }

    /**
     * 业务头：还原 La2/b->r（chat / models）
     *
     * s() + Authorization + 按需携带的 X-User-Id / X-Enterprise-Id / X-Domain + X-Product "SaaS"。
     * 注意：r() 不携带 X-Tenant-Id。
     */
    private function bizHeaders(Account $account): array
    {
        $headers = $this->baseHostHeaders($account);
        $headers['Authorization'] = 'Bearer ' . (string) $account->cred('accessToken', '');
        $headers['X-Product'] = 'SaaS';

        $uid = (string) $account->cred('uid', $account->uid);
        if ($uid !== '') {
            $headers['X-User-Id'] = $uid;
        }

        $enterpriseId = (string) $account->cred('enterpriseId', '');
        if ($enterpriseId !== '') {
            $headers['X-Enterprise-Id'] = $enterpriseId;
        }

        $domain = (string) $account->cred('domain', '');
        if ($domain !== '') {
            $headers['X-Domain'] = $domain;
        }

        return $headers;
    }

    /**
     * 刷新头：还原 La2/b->C
     *
     * s() + X-Refresh-Token + 按需携带的 X-Enterprise-Id + X-Auth-Refresh-Source "workbuddy"。
     */
    private function refreshHeaders(Account $account): array
    {
        $headers = $this->baseHostHeaders($account);
        $headers['X-Refresh-Token'] = (string) $account->cred('refreshToken', '');
        $headers['X-Auth-Refresh-Source'] = self::REFRESH_SOURCE;

        $enterpriseId = (string) $account->cred('enterpriseId', '');
        if ($enterpriseId !== '') {
            $headers['X-Enterprise-Id'] = $enterpriseId;
        }

        return $headers;
    }

    /**
     * 登录/授权接口头（auth/state、auth/token）
     *
     * 还原 La2/b->A / ->x：上游对这两个接口传入的是空 Map（Lj2/v->d，只读空表），
     * 底层 y() 仅额外固定 Accept-Encoding: identity，不携带任何自定义头。
     * 这里仅补上 POST 必需的 Content-Type。
     */
    private function loginHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
    }

    /**
     * 客户端会话头族：还原官方客户端（WorkBuddy 桌面 / CLI 会话）的出站身份与链路追踪头
     *
     * 蓝本：tools/probe-chat-session-headers.php 的 `client` 模式（本机实机抓包还原
     * `buildComputerUseVlmGatewayHeaders + applyGatewayHeaders`）。
     *
     * 上游在 2026-10 对出站 chat 新增**抽样渠道校验**：请求不带 `X-Conversation-ID`
     * 时直接拒单 `HTTP 400 11-128 conversation id missing`。国际版受影响最明显，
     * 国服的对话计数型任务（chat_5 等）同样只在带会话头时才被计入。
     *
     * 两类标识的区别（关键）：
     *   · **会话级**（`X-Conversation-ID` / `acp-connection-id`）：**账号维度稳定派生**
     *     —— 同一账号每次出站都来自同一「虚拟会话」，不随机漂移、不同账号互不关联，
     *     与参考实现的 `derive_id` 口径一致。持久化在账号 meta 里，重启不丢。
     *   · **请求级**（`X-Conversation-Request-ID` / `X-Conversation-Message-ID` /
     *     `X-Request-ID` / trace 族）：**每请求新生成**，用于上游链路追踪与去重。
     *
     * 只**追加**头，不改动 User-Agent / Origin / Referer 等既有出站指纹。
     *
     * @return array<string,string>
     */
    private function sessionHeaders(Account $account): array
    {
        $traceId = bin2hex(random_bytes(16));
        $spanId = bin2hex(random_bytes(8));

        return [
            // 桌面身份（客户端用它区分 IDE / CLI 通道，会话头校验要求同族）
            'X-IDE-Type'                    => 'WorkBuddy',
            'X-IDE-Name'                    => 'WorkBuddy',
            'X-IDE-Version'                 => self::DESKTOP_APP_VERSION,
            'X-Private-Data'                => 'true',
            // 会话级（账号维度稳定派生）
            'X-Conversation-ID'             => $this->conversationId($account),
            'acp-connection-id'             => $this->connectionId($account),
            // 请求级（每请求新生成）
            'X-Conversation-Request-ID'     => uuid(),
            'X-Conversation-Message-ID'     => uuid(),
            'X-Root-Request-ID'             => uuid(),
            'X-Request-ID'                  => uuid(),
            'X-Agent-Intent'                => 'craft',
            'X-Agent-Purpose'               => 'conversation',
            'x-codebuddy-request'           => '1',
            // 链路追踪（W3C traceparent + B3 双格式，与官方客户端一致）
            'traceparent'                   => "00-{$traceId}-{$spanId}-01",
            'X-Trace-ID'                    => $traceId,
            'X-B3-TraceId'                  => $traceId,
            'X-B3-SpanId'                   => $spanId,
            'X-B3-Sampled'                  => '1',
        ];
    }

    /**
     * 稳定的会话标识：优先取账号 meta，其次生成一次并持久化（账号维度隔离）。
     *
     * 与 QoderProvider::sessionId() 同范式：同一账号不漂移，不同账号互不关联，
     * 避免上游把多个账号的出站会话关联到同一「设备」。
     */
    private function conversationId(Account $account): string
    {
        return $this->stableMetaId($account, 'conversation_id');
    }

    /** 稳定的连接标识（`acp-connection-id`）：账号维度稳定派生，口径同 conversationId */
    private function connectionId(Account $account): string
    {
        return $this->stableMetaId($account, 'acp_connection_id');
    }

    /**
     * 账号维度稳定标识的通用派生：meta 命中即复用，否则生成一次并落库。
     */
    private function stableMetaId(Account $account, string $metaKey): string
    {
        $existing = trim((string) $account->meta($metaKey, ''));

        if ($existing !== '') {
            return $existing;
        }

        $generated = uuid();
        $this->accountRepository->patchMeta($account, [$metaKey => $generated]);

        return $generated;
    }

    // ---------------------------------------------------------
    // 错误分类（上游结构性校验必须先于内容审核判定）
    // ---------------------------------------------------------

    /**
     * WorkBuddy / CodeBuddy 专属错误分类
     *
     * 上游对「请求体结构不合规」的拒单带 `displayMsg.en`，正文写着
     * `The request was blocked by security policy. …` —— 这个字面量会被父类
     * `isContentBlocked()` 的 `'blocked by'` 判据抢走，归成 CONTENT_BLOCKED
     * （isRequestFault=true，文案是「请调整提示词后重试」）。
     *
     * 但下列错误**与内容无关**，改提示词永远修不好，必须独占分类：
     *
     *   · `11128`（HTTP 400）渠道指纹校验失败 —— `msg` 固定为 `request illegal`，
     *     `displayTips` 指向「渠道校验失败 / 不是官方客户端」。早期该码的 `msg`
     *     是 `first message is not system prompt`（见 2026-10-08 的 call_log），
     *     2026-10 起文案已改为渠道校验，首条 system 不再是判据。
     *   · `11140`（HTTP 403）账号级风控 —— `msg` 同样是 `request illegal`，
     *     `displayMsg` 为「账号存在异常行为，已被限制使用」。实测与会话 ID、UA
     *     均无关，换全新会话 ID 仍 403；网页 ACP 通道握手正常但 agent 运行时
     *     同样吐 403。父类会把它当 `403 → SESSION_DEAD`（提示重新登录），而
     *     token 实际有效，重登无效，故必须归 RISK_CONTROL（可换号、冷却本账号）。
     *   · `11150` the reasoning effort value is not supported —— 请求体
     *     `reasoning_effort` 传了非法值（有效：low/medium/high/none；`off` 会报错）。
     */
    public function parseError(int $status, string $body): ?array
    {
        $decoded = json_decode($body, true);
        $bizCode = $this->extractBusinessCode(is_array($decoded) ? $decoded : null, $body);

        // 文本兜底：上游偶以纯文本下发业务码（无 JSON 信封）
        if ($bizCode === 0 && preg_match('/\b(11128|11140|11150)\b/', $body, $m)) {
            $bizCode = (int) $m[1];
        }

        if ($bizCode === 11128) {
            return [
                // 2026-11-09 实测复盘：payload 完全合法（含官方 IDE 形状：大 system + 30 工具 +
                // stream + thinking + temperature）也会被上游**抽样**拦 11-128，同一请求重发即通
                // ——该码是「抽样渠道校验」的随机拒单，与请求参数无关（旧文案误导用户去改 tools）。
                // 归 UPSTREAM（可换号重试 + 可走跨供应商 fallback），不再归 CLIENT（会让
                // fallback 链失效，用户直接看到报错）。
                'code'    => ErrorCategory::UPSTREAM,
                'message' => '上游渠道抽样校验拦截（code 11-128 request illegal）：同一请求为合法客户端形状、与账号状态无关，网关已自动换号/降级重试',
            ];
        }

        if ($bizCode === 11140) {
            return [
                'code'    => ErrorCategory::RISK_CONTROL,
                'message' => 'code 11140 账号已被上游限制使用，非登录失效、非内容违规，请更换账号或按上游提示申诉',
            ];
        }

        if ($bizCode === 11150) {
            return [
                'code'    => ErrorCategory::CLIENT,
                'message' => '不支持的 reasoning_effort 值（有效值：low/medium/high/none，code 11150）',
            ];
        }

        return parent::parseError($status, $body);
    }

    // ---------------------------------------------------------
    // 对话（/v2/chat/completions）
    // ---------------------------------------------------------

    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        try {
            return $this->chatOnce($account, $request, $onDelta);
        } catch (\RuntimeException $e) {
            // 11-128 request illegal = 上游抽样渠道校验的随机拒单（2026-11-09 复盘：合法
            // IDE 形状也会被拦，同一请求重发即通）。在此原地重发一次，把抽样拦在适配器层，
            // 不惊动账号池与 fallback —— 用户级租户下 candidates 只有 1 个账号，换号无处可换。
            // conversation id missing / first message is not system 是确定性结构错误，不重试。
            if (str_contains($e->getMessage(), '11-128') && str_contains($e->getMessage(), 'request illegal')) {
                return $this->chatOnce($account, $request, $onDelta);
            }
            throw $e;
        }
    }

    private function chatOnce(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        $token = (string) $account->cred('accessToken', '');
        if ($token === '') {
            throw new \RuntimeException('WorkBuddy 账号缺少 accessToken，请重新登录');
        }

        $url = $this->hostChat($account) . '/v2/chat/completions';
        $headers = $this->bizHeaders($account);

        // ── 会话头族：上游对出站 chat 做抽样渠道校验 ───────────────────────────
        // 缺失会话标识时直接 400 `11-128 conversation id missing`（2026-10 上游新增）。
        // 另有实测（tools/probe-chat-session-headers.php）：**国服**的对话计数型任务
        // （chat_5 / Model_chat_GLM5.2 / RichMeow_Chat）只有带上客户端会话头才会被上游计入，
        // 仅带 CLI 头（gw 模式）不计入 —— 故国服与国际版都补，双区域共用同一套派生内核。
        // 注意只**追加**头，不改 User-Agent 等既有 CLI 指纹（国服出站指纹有意保留）。
        $headers = array_merge($headers, $this->sessionHeaders($account));

        $payload = $request->toUpstreamPayload([
            'stream' => true,
            'model'  => $request->model,
        ]);

        // ── 思考（深度思考）开关：上游靠**请求体的 `reasoning_effort` 是否存在**决定是否思考 ──
        //
        // 实测（2026-10-05，国服 copilot.tencent.com/v2/chat/completions，模型 deepseek-v4.1-flash）：
        //   · 不带该字段                → `reasoning_content` 全程为空、usage.reasoning_tokens=0（不思考）；
        //   · 带 low/medium/high/none  → 正常吐 `reasoning_content`、reasoning_tokens>0（思考）；
        //   · 带 off / 非法值           → HTTP 400 `code:11150 the reasoning effort value is not supported`。
        //   另：请求头 `X-Agent-Intent: craft`、body `thinking` / `enable_thinking` 均**无效**。
        //
        // 网关此前从不传该字段 → 该通道永远不思考（用户报障「workbuddy 的 deepseek-v4.1-flash 没有思考模式」）。
        // 兼容口径：
        //   · 客户端已自带 `reasoning_effort`（经 extra 透传）→ **原样尊重**，网关不覆盖；
        //   · 客户端带 `thinking: true`（App「深度思考」按钮的约定字段）→ 补默认值 `medium`；
        //   · 两者都没有 → 不传（保持既有行为，不悄悄增加 token 开销）。
        if (!array_key_exists('reasoning_effort', $payload) && !empty($request->extra['thinking'])) {
            $payload['reasoning_effort'] = 'medium';
        }

        // ── 上游硬校验：首条消息必须是 system（仅国际版）───────────────────────
        // 国际版 www.workbuddy.ai 对请求体的这条校验是**硬拒单**：
        //   HTTP 400 {"code":11128,"msg":"first message is not system prompt",
        //             "displayMsg":{"en":"The request was blocked by security policy. …"}}
        // 实测（2026-10-08，同账号同模型逐条对照）：
        //   · `[user]`              → 400 / code=11128；
        //   · `[system(""), user]`  → 200（system 正文允许为空，只要角色在）；
        //   · 国服 copilot.tencent.com **无**此校验，`[user]` 同样 200。
        //
        // ⚠️ 该 displayMsg 里带 "blocked by security policy"，会被 parseError 的
        //    isContentBlocked() 判据吃掉 → 归成 CONTENT_BLOCKED，用户看到的是
        //    「请求内容被上游安全策略拦截，请调整提示词后重试」——**把"消息结构不合规"
        //    说成"内容违规"，用户去反复改提示词永远修不好**（本次报障即如此：
        //    call_log 里 `[user] [图片] 图片背景是什么颜色` 连续三次 400/11128）。
        //
        // 网关此前只有当思维/模板恰好注入了 system 时才碰巧合规；一旦该请求没有
        // 注入 system（如靶点未启用思维），首条就是 user → 必被拒。
        // 修法：国际版出站前若首条不是 system，补一条空 system 占位 ——
        // 不改动任何既有消息的内容与顺序，只把角色序列修成上游要求的形态。
        if ($this->isGlobal($account)) {
            $payload['messages'] = $this->withLeadingSystem((array) ($payload['messages'] ?? []));
        }

        return $this->parseOpenAiSse($url, $payload, $headers, $account, $onDelta);
    }

    /**
     * 保证 messages[0].role === 'system'（国际版上游硬校验）。
     *
     * 首条已是 system 时原样返回；否则在最前补一条**空正文** system
     * （实测空正文即可通过校验，无需编造提示词污染对话语义）。
     *
     * @param  array<int,mixed> $messages
     * @return array<int,mixed>
     */
    private function withLeadingSystem(array $messages): array
    {
        if ($messages === [] || (string) ($messages[0]['role'] ?? '') === 'system') {
            return $messages;
        }

        array_unshift($messages, ['role' => 'system', 'content' => '']);

        return $messages;
    }

    // ---------------------------------------------------------
    // 模型（/console/enterprises/personal/models）
    // ---------------------------------------------------------

    public function models(Account $account): array
    {
        return array_map(fn(array $m) => (string) $m['model_id'], $this->modelCatalog($account));
    }

    /**
     * 模型目录 = **客户端同源产品配置（/v3/config）∪ 正式模型接口（enterprises/personal/models）**。
     *
     * 为什么要并集（2026-10-04 实机核对，两者都不是对方的子集）：
     *   · 国际版 `/v3/config` 22 项里，`deepseek-v4.1-flash` / `deepseek-v4.1-flash-sg` /
     *     `gpt-6-astra` / `kimi-k2.8-preview` / `glm-5.3-flash` **在** v2 正式接口里**没有**；
     *   · 反过来 v2 22→18 项里的 `gpt-5.3-codex` **不在** v3 目录里；
     *   · 国服同理（v3 32 项独有 `minimax-m2.7` / `glm-5.0-turbo`，v2 31 项独有 `auto` /
     *     `hunyuan-2.0-thinking`）。
     * 只取一边 = 必然「客户端能选、我们没有」（本次用户报障就是这一类）。
     *
     * 顺序（同 id 时以谁为准）：**v3/config 在前** —— 它的 ctx / maxTokens / credits
     * 就是官方客户端 UI 上显示的那份数值（如 `hy4-preview` 在 v3 是 1M ctx，在 v2 只有 200k）。
     *
     * 两个来源都拉不到时返回 `[]`（「目录未知」的诚实表达），绝不用任何硬编码表冒充目录。
     *
     * @return list<array<string,mixed>>
     */
    public function modelCatalog(Account $account): array
    {
        $catalog = $this->catalogFromClientConfig($account);

        $seen = [];
        foreach ($catalog as $row) {
            $seen[(string) ($row['model_id'] ?? '')] = true;
        }
        foreach ($this->catalogFromEnterpriseModels($account) as $row) {
            $id = (string) ($row['model_id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $catalog[] = $row;
        }

        return $catalog;
    }

    /**
     * 客户端同源目录：`GET {host}/v3/config` → `data.models[]`（对象数组，含 credits 倍率）。
     *
     * 条目字段与 v2 正式接口**形状不同**（这是本次踩到的坑）：
     *   · `name`（显示名）/ `maxInputTokens` / `maxOutputTokens` / `credits` —— 同 v2；
     *   · **没有** `contextWindow.defaultLength` 包装，也没有 `id` 之外的 model_id 字段；
     *   · 含**图片/视频生成**条目（国际版：`gpt-image-2.5-sunburst` / `seedance-2.5`）——
     *     不是对话模型，选进 chat 只会失败，按 v2 同源规则（`tags` 含 `text-to-image`）
     *     外加 `videoGeneration` 视频档判定剔除。
     *
     * 任何失败（404 / 非 JSON / 业务码非 0）一律返回 `[]`，由调用方并上 v2 目录 ——
     * 不让一次上游抖动把整个模型列表清空。
     *
     * @return list<array<string,mixed>>
     */
    private function catalogFromClientConfig(Account $account): array
    {
        // 桌面指纹只给国际版：国服同一端点按桌面指纹会混入 IDE 内部模型（见 DESKTOP_APP_VERSION 注释）
        $headerSets = $this->isIntlProvider()
            ? [$this->clientConfigHeaders($account), $this->bizHeaders($account)]
            : [$this->bizHeaders($account)];

        $node = null;
        foreach ($headerSets as $headers) {
            $res = $this->http()->withHeaders($headers)->timeout(20)
                ->get($this->hostChat($account) . self::CLIENT_CONFIG_PATH);

            if (!$res->ok()) {
                continue;
            }

            $decoded = $res->json();
            if (!is_array($decoded)) {
                continue;
            }
            if (isset($decoded['code']) && is_numeric($decoded['code']) && (int) $decoded['code'] !== 0) {
                continue;
            }

            $candidate = $decoded['data'] ?? null;
            if (is_array($candidate) && !empty($candidate['models'])) {
                $node = $candidate;
                break;
            }
        }

        if ($node === null) {
            return [];
        }

        $out = [];
        foreach ((array) ($node['models'] ?? []) as $model) {
            $row = is_array($model) ? $this->clientConfigRow($model) : null;
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * 桌面客户端指纹（仅用于只读的 `/v3/config`）：`s()` + Authorization + 业务头，
     * 再把 `User-Agent` 换成桌面客户端的形状并补 `X-IDE-Name/X-IDE-Version/X-Private-Data`。
     *
     * 桌面客户端构造方式（真机 asar `main/daemon-bootstrap.js`）：
     *   `X-IDE-Name: WorkBuddy` / `X-IDE-Version: <appVersion>` / `X-Private-Data: true` /
     *   `User-Agent: WorkBuddy/<appVersion> WorkBuddy/<appVersion> CLI/<cliVersion>`。
     *
     * ⚠️ 只有这一个请求用桌面指纹；chat / 额度 / 签到 / 成长中心一律仍是 CLI 指纹，
     *    避免动到已经稳定的那条路。
     */
    private function clientConfigHeaders(Account $account): array
    {
        $headers = $this->bizHeaders($account);
        $headers['User-Agent']     = 'WorkBuddy/' . self::DESKTOP_APP_VERSION . ' WorkBuddy/' . self::DESKTOP_APP_VERSION . ' CLI/' . self::CLI_VERSION;
        $headers['X-IDE-Name']     = 'WorkBuddy';
        $headers['X-IDE-Version']  = self::DESKTOP_APP_VERSION;
        $headers['X-Private-Data'] = 'true';

        return $headers;
    }

    /**
     * `/v3/config` 的单条模型 → 目录行；非对话模型（图片/视频生成）返回 null。
     *
     * @param  array<string,mixed> $model
     * @return array<string,mixed>|null
     */
    private function clientConfigRow(array $model): ?array
    {
        $id = trim((string) ($model['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        $tags = (array) ($model['tags'] ?? []);
        // 非对话模型剔除：`text-to-image` 与 v2 目录同源；`videoGeneration` 是 v3 的视频档
        // （`seedance-2.5`，tags 为 text-to-video / image-to-video，不含 text-to-image）。
        // ⚠️ 不扩大到 `image-to-image`：国服 v2 目录本来就外露 `hunyuan-image-alpha-edit`，
        //    擅自剔除会让库内既有条目变成「上游已无」的残留行。
        if (in_array('text-to-image', $tags, true) || isset($model['videoGeneration'])) {
            return null;
        }

        $ctx = is_numeric($model['maxInputTokens'] ?? null) ? (int) $model['maxInputTokens'] : null;
        $maxTokens = is_numeric($model['maxOutputTokens'] ?? null) ? (int) $model['maxOutputTokens'] : null;
        $multimodal = !empty($model['supportsImages']);
        $reasoning = !empty($model['supportsReasoning']) || !empty($model['onlyReasoning']);
        $rate = $this->parseCredits($model['credits'] ?? null);

        $capabilities = ['stream'];
        if ($multimodal) {
            $capabilities[] = 'vision';
        }
        if ($reasoning) {
            $capabilities[] = 'reasoning';
        }
        if (!empty($model['supportsToolCall'])) {
            $capabilities[] = 'tool';
        }

        return [
            'model_id'         => $id,
            'display_name'     => trim((string) ($model['name'] ?? '')) !== '' ? trim((string) $model['name']) : $id,
            'rate'             => $rate,
            'rate_source'      => $rate !== null ? 'credits' : '',
            'fee_level'        => null,
            'entitlement_cost' => null,
            'context_window'   => $ctx,
            'max_tokens'       => $maxTokens,
            'multimodal'       => $multimodal,
            'reasoning'        => $reasoning,
            'capabilities'     => array_values(array_unique($capabilities)),
        ];
    }

    /**
     * 正式模型接口：`/v2|/console/enterprises/personal/models`（含上游倍率与详情）
     *
     * 主数据源 data.models[]（对象数组）：
     *   - credits 形如 "x0.79" / "x2.20 credits" / "x0.00 credits"，数值即积分倍率
     *   - name / maxInputTokens / maxOutputTokens / contextWindow.defaultLength / supportsImages
     * 若 data.models[] 缺失，则回退解析 agents[cli].models[]（仅字符串 ID，无倍率）。
     *
     * @return list<array<string,mixed>>
     */
    private function catalogFromEnterpriseModels(Account $account): array
    {
        // 国际版模型目录走 /v2/enterprises/personal/models，国服走 /console/enterprises/personal/models；
        // 首选路径失败（如国际版对 /console 返回 500）时自动回退另一路径。
        $paths = $this->isGlobal($account)
            ? ['/v2/enterprises/personal/models', '/console/enterprises/personal/models']
            : ['/console/enterprises/personal/models', '/v2/enterprises/personal/models'];

        $data = null;
        foreach ($paths as $path) {
            $res = $this->http()->withHeaders($this->bizHeaders($account))->timeout(20)
                ->get($this->hostChat($account) . $path);

            if (!$res->ok()) {
                continue;
            }

            $decoded = $res->json();
            if (!is_array($decoded)) {
                continue;
            }

            // 还原 La2/b->w：先校验 code == 0
            if (isset($decoded['code']) && is_numeric($decoded['code']) && (int) $decoded['code'] !== 0) {
                continue;
            }

            $data = $decoded;
            break;
        }

        if (!is_array($data)) {
            return [];
        }

        $node = $data['data'] ?? [];
        if (!is_array($node)) {
            return [];
        }

        $out = [];
        $seen = [];

        // 主数据源：data.models[]（对象，含 credits 倍率）
        foreach ((array) ($node['models'] ?? []) as $model) {
            if (!is_array($model)) {
                continue;
            }
            $id = trim((string) ($model['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $name = trim((string) ($model['name'] ?? ''));
            $rate = $this->parseCredits($model['credits'] ?? null);

            $ctx = null;
            if (isset($model['contextWindow']['defaultLength']) && is_numeric($model['contextWindow']['defaultLength'])) {
                $ctx = (int) $model['contextWindow']['defaultLength'];
            } elseif (isset($model['maxInputTokens']) && is_numeric($model['maxInputTokens'])) {
                $ctx = (int) $model['maxInputTokens'];
            }

            $maxTokens = null;
            if (isset($model['maxOutputTokens']) && is_numeric($model['maxOutputTokens'])) {
                $maxTokens = (int) $model['maxOutputTokens'];
            }

            $multimodal = !empty($model['supportsImages']);
            $reasoning = !empty($model['supportsReasoning']) || !empty($model['onlyReasoning']);
            $isImage = !empty($model['tags'])
                && in_array('text-to-image', (array) $model['tags'], true);

            $capabilities = ['stream'];
            if ($multimodal) {
                $capabilities[] = 'vision';
            }
            if ($reasoning) {
                $capabilities[] = 'reasoning';
            }
            if (!empty($model['supportsToolCall'])) {
                $capabilities[] = 'tool';
            }
            if ($isImage) {
                $capabilities[] = 'image';
            }

            $out[] = [
                'model_id'         => $id,
                'display_name'     => $name !== '' ? $name : $id,
                'rate'             => $rate,
                'rate_source'      => $rate !== null ? 'credits' : '',
                'fee_level'        => null,
                'entitlement_cost' => null,
                'context_window'   => $ctx,
                'max_tokens'       => $maxTokens,
                'multimodal'       => $multimodal,
                'reasoning'        => $reasoning,
                'capabilities'     => array_values(array_unique($capabilities)),
            ];
        }

        if ($out !== []) {
            return $out;
        }

        // 回退：agents[cli].models[] 与顶层 models[]（仅字符串 ID，无倍率）
        $ids = [];
        foreach ((array) ($node['agents'] ?? []) as $agent) {
            if (!is_array($agent)) {
                continue;
            }
            if ((string) ($agent['name'] ?? '') !== 'cli') {
                continue;
            }
            foreach ((array) ($agent['models'] ?? []) as $model) {
                if (is_string($model) && $model !== '') {
                    $ids[$model] = true;
                }
            }
        }
        foreach ((array) ($node['models'] ?? []) as $model) {
            if (is_string($model) && $model !== '') {
                $ids[$model] = true;
            }
        }

        $fallback = [];
        foreach (array_keys($ids) as $id) {
            $fallback[] = [
                'model_id'         => $id,
                'display_name'     => $id,
                'rate'             => null,
                'rate_source'      => '',
                'fee_level'        => null,
                'entitlement_cost' => null,
                'context_window'   => null,
                'max_tokens'       => null,
                'multimodal'       => false,
                'reasoning'        => false,
                'capabilities'     => ['stream'],
            ];
        }

        return $fallback;
    }

    /** 解析 credits 字段倍率，形如 "x0.79" / "x2.20 credits" / "x0.00 credits"；解析不到返回 null */
    private function parseCredits(mixed $credits): ?float
    {
        if (!is_string($credits) || $credits === '') {
            return null;
        }
        if (preg_match('/x\s*([0-9]+(?:\.[0-9]+)?)/i', $credits, $m) === 1) {
            $rate = (float) $m[1];
            return $rate >= 0 ? $rate : null;
        }
        return null;
    }

    // ---------------------------------------------------------
    // 额度（/v2/billing/meter/get-user-resource）
    // ---------------------------------------------------------

    public function credits(Account $account): CreditInfo
    {
        $url = $this->hostWeb($account) . '/v2/billing/meter/get-user-resource';

        try {
            $res = $this->http()->withHeaders($this->meterHeaders($account))->timeout(20)
                ->postJson($url, $this->creditsBody());
        } catch (\Throwable $e) {
            return CreditInfo::failure('额度查询失败：' . $e->getMessage());
        }

        if ($res->status() === 401 || $res->status() === 403) {
            return CreditInfo::failure('登录已失效，请重新登录');
        }

        if (!$res->ok()) {
            return CreditInfo::failure('额度查询失败：上游 HTTP ' . $res->status());
        }

        $data = $res->json();
        if (!is_array($data) || (int) ($data['code'] ?? -1) !== 0) {
            return CreditInfo::failure('额度查询失败：' . $this->extractMessage($res->body()));
        }

        // 还原 La2/b->m：Response.Data.Accounts[] 逐条转成额度包
        $accounts = $data['data']['Response']['Data']['Accounts'] ?? null;
        if (!is_array($accounts)) {
            return CreditInfo::failure('额度查询失败：响应缺少 Accounts');
        }

        $packs = [];
        $balance = 0.0;
        $earliestExpire = 0;

        foreach ($accounts as $acc) {
            if (!is_array($acc)) {
                continue;
            }

            $cycleSize = (int) ($acc['CycleCapacitySize'] ?? 0);
            $capacitySize = (int) ($acc['CapacitySize'] ?? 0);

            $name = (string) ($acc['PackageName'] ?? '');
            if ($name === '') {
                $name = '额度包';
            }

            // CycleCapacitySize > 0 视为周期额度，否则为容量包
            $isCycle = $cycleSize > 0;
            $type = $isCycle ? '周期额度' : '容量包';
            $size = $isCycle ? $cycleSize : $capacitySize;
            $used = (int) ($acc[$isCycle ? 'CycleCapacityUsed' : 'CapacityUsed'] ?? 0);
            $remain = (int) ($acc[$isCycle ? 'CycleCapacityRemain' : 'CapacityRemain'] ?? 0);

            $packs[] = [
                'name'   => $name,
                'group'  => $type,
                'limit'  => (float) $size,
                'used'   => (float) $used,
                'remain' => (float) $remain,
            ];

            $balance += $remain;

            $expireAt = $this->parsePackageExpireAt($acc);
            if ($expireAt > 0) {
                $earliestExpire = $earliestExpire === 0 ? $expireAt : min($earliestExpire, $expireAt);
            }
        }

        $known = $packs !== [];
        $balance = round($balance, 2);

        return new CreditInfo(
            known: $known,
            balance: $known ? $balance : null,
            detail: $known ? '周期剩余 ' . $balance : '未获取到额度信息',
            packs: $packs,
            expireAt: $earliestExpire
        );
    }

    /**
     * 解析额度包到期时间（PackageEndTime 等），归一到毫秒
     *
     * 兼容 Unix 秒/毫秒时间戳与 "yyyy-MM-dd HH:mm:ss" 字符串（视为本地时间）。
     *
     * @param array<string,mixed> $node
     */
    private function parsePackageExpireAt(array $node): int
    {
        $keys = [
            'PackageEndTime', 'package_end_time', 'EndTime', 'end_time', 'endTime',
            'ExpireTime', 'expire_time', 'expireAt', 'expire_at', 'ExpiredTime', 'InvalidTime',
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

    /**
     * 计费查询请求体：还原 La2/b->F
     *
     * PageNumber=1、PageSize=100、ProductCode="p_tcaca"、Status=[0,3]，
     * PackageEndTimeRangeBegin/End 用 "yyyy-MM-dd HH:mm:ss"（US）格式化，
     * 结束时间取 now + 3185136000000ms（即 0x2e598e65c00，近似无上界）。
     */
    private function creditsBody(): array
    {
        $nowMs = now_ms();

        return [
            'PageNumber'               => 1,
            'PageSize'                 => 100,
            'ProductCode'              => self::PRODUCT_CODE,
            'Status'                   => [0, 3],
            'PackageEndTimeRangeBegin' => date('Y-m-d H:i:s', (int) floor($nowMs / 1000)),
            'PackageEndTimeRangeEnd'   => date('Y-m-d H:i:s', (int) floor(($nowMs + 3185136000000) / 1000)),
        ];
    }

    // ---------------------------------------------------------
    // 动作：每日活跃注入（复用 /v2/chat/completions）
    // ---------------------------------------------------------

    /**
     * 每日活跃注入：向 /v2/chat/completions 发一条最小对话
     *
     * 上游「每日活跃」判定依赖当天有一次真实对话，用户手动签到无法触发，
     * 因此这里用最小载荷（单条 "hi"）发起一次流式对话，命中活跃判定后由上游自动发放积分。
     * 是否「今天已注入过」由 AccountPool + ActivityLogRepository 保障，本方法不做幂等。
     *
     * ⚠️ 国际版（workbuddy.ai）：桌面身分发 chat/completions **不计入**官方「每日活跃」，
     * 认的是**跑完的网页 agent 会话**（见 injectActivityWeb）。故国际版在桌面腿之外
     * **再走一次网页通道并跑到 completed**；国服只需桌面腿。
     *
     * @param string $model 指定模型；为空时自动挑选最轻量可用模型
     */
    private function injectActivity(Account $account, string $model = ''): array
    {
        $token = (string) $account->cred('accessToken', '');
        if ($token === '') {
            return ['ok' => false, 'message' => '缺少 accessToken，请重新登录', 'data' => null];
        }

        $model = trim($model);
        if ($model === '') {
            $model = $this->pickActivityModel($account);
        }
        if ($model === '') {
            return ['ok' => false, 'message' => '未找到可用模型，无法注入每日活跃', 'data' => null];
        }

        $request = new ChatRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => 'hi']],
            stream: true,
        );

        try {
            $chunk = $this->chat($account, $request, static function (string $text, array $event): bool {
                // 收到任意内容即证明对话已建立，主动结束读取，避免无谓消耗
                return false;
            });
        } catch (\Throwable $e) {
            // 国际版：桌面腿不计数，失败不致命，交由网页腿决定成败
            if ($this->isGlobal($account)) {
                $chunk = null;
            } else {
                return ['ok' => false, 'message' => '活跃注入失败：' . $e->getMessage(), 'data' => null];
            }
        }

        // 国际版：桌面腿之外**再走一次网页通道**（官方只认跑完的 agent 会话），以网页腿为准
        if ($this->isGlobal($account)) {
            $web = $this->injectActivityWeb($account, $model, self::WEB_DAILY_PROMPT);
            $web['data'] = is_array($web['data'] ?? null) ? $web['data'] : [];
            $web['data']['model'] = $model;

            return $web;
        }

        return [
            'ok'      => true,
            'message' => '每日活跃已注入（模型 ' . $model . '）',
            'data'    => ['model' => $model, 'finish' => $chunk->finishReason],
        ];
    }

    /**
     * 挑选用于活跃注入的轻量模型：
     * 1) 供应商配置的 default_model（若存在）
     * 2) 模型目录中倍率最低且非文生图模型
     * 3) 模型目录第一个
     * 找不到时返回 ''。
     */
    private function pickActivityModel(Account $account): string
    {
        $configured = trim((string) ($this->providerRepository->find($this->providerKey)['default_model'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $catalog = $this->modelCatalog($account);
        $best = null;
        $bestRate = null;

        foreach ($catalog as $item) {
            $id = trim((string) ($item['model_id'] ?? ''));
            if ($id === '') {
                continue;
            }

            // 跳过文生图模型
            if (in_array('image', (array) ($item['capabilities'] ?? []), true)) {
                continue;
            }

            if ($best === null) {
                $best = $id;
            }

            $rate = $item['rate'] ?? null;
            if (is_numeric($rate)) {
                $rate = (float) $rate;
                if ($bestRate === null || $rate < $bestRate) {
                    $bestRate = $rate;
                    $best = $id;
                }
            }
        }

        return $best ?? '';
    }

    // ---------------------------------------------------------
    // 国际版网页通道：console/as agent 会话 + ACP over HTTP+SSE
    // ---------------------------------------------------------

    /**
     * 网页通道请求头（浏览器形态）。
     *
     * 只用 `Authorization: Bearer <accessToken>` + `X-User-Id` 两个凭据头，
     * 不带桌面端的 X-IDE-* 指纹 —— 网关手里同一份账号凭据可直接走这条链路。
     */
    private function webHeaders(Account $account): array
    {
        $origin = $this->hostWeb($account);
        $global = $this->isGlobal($account);

        $headers = [
            'Authorization'    => 'Bearer ' . (string) $account->cred('accessToken', ''),
            'Accept'           => 'application/json, text/plain, */*',
            'Accept-Language'  => $global ? 'en-US,en;q=0.9' : 'zh-CN,zh;q=0.9',
            'Content-Type'     => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
            'User-Agent'       => self::WEB_USER_AGENT,
            'Origin'           => $origin,
            'Referer'          => $origin . '/app',
            'x-client-platform' => 'web',
            'X-CodeBuddy-Request' => '1',
            'X-Request-ID'     => uuid(),
        ];

        $uid = (string) $account->cred('uid', $account->uid);
        if ($uid !== '') {
            $headers['X-User-Id'] = $uid;
        }

        return $headers;
    }

    /**
     * 建网页 agent 会话：POST {hostWeb}/console/as/conversations/
     *
     * body 形状与网页端一致：{prompt, model, conversationOrigin:"workbuddy-app", plugins:[...]}。
     * 返回 ['ok'=>bool, 'id'=>conversationId, 'message'=>失败原因]。
     */
    private function webCreateConversation(Account $account, string $model, string $prompt): array
    {
        $url  = $this->hostWeb($account) . self::WEB_CONVERSATIONS_PATH;
        $body = [
            'prompt'             => $prompt,
            'model'              => $model,
            'conversationOrigin' => 'workbuddy-app',
            'plugins'            => [['name' => 'weixinpay', 'marketplace' => 'codebuddy-builtin']],
        ];

        try {
            $res = $this->http()->withHeaders($this->webHeaders($account))->timeout(30)
                ->postJson($url, $body);
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => '', 'message' => '建会话异常：' . $e->getMessage()];
        }

        $data = is_array($res->json()) ? $res->json() : [];
        $code = $data['code'] ?? null;
        $id   = (string) (($data['data']['id'] ?? '') ?: '');

        if (($code !== null && (int) $code !== 0) || $id === '') {
            return [
                'ok'      => false,
                'id'      => '',
                'message' => '建会话失败（HTTP ' . $res->status() . '）：' . $res->snippet(200),
            ];
        }

        return ['ok' => true, 'id' => $id, 'message' => ''];
    }

    /**
     * 读取网页会话节点（GET {hostWeb}/console/as/conversations/{id}{suffix}），失败返回 []。
     *
     * @return array<string,mixed>
     */
    private function webConversationGet(Account $account, string $conversation, string $suffix = ''): array
    {
        $url = $this->hostWeb($account) . self::WEB_CONVERSATIONS_PATH
            . rawurlencode($conversation) . $suffix;

        try {
            $res = $this->http()->withHeaders($this->webHeaders($account))->timeout(20)->get($url);
        } catch (\Throwable $e) {
            return [];
        }

        if (!$res->ok()) {
            return [];
        }

        $data = $res->json();
        $node = is_array($data) && isset($data['data']) && is_array($data['data']) ? $data['data'] : [];

        return $node;
    }

    /** 读取网页会话状态（小写）：completed / failed / error / creating …；未知返回 '' */
    private function webConversationStatus(Account $account, string $conversation): string
    {
        $node = $this->webConversationGet($account, $conversation);

        return strtolower(trim((string) ($node['status'] ?? '')));
    }

    /**
     * ACP over streamable HTTP + SSE 单次 JSON-RPC 请求。
     *
     * POST 到会话沙箱 link，带 `Acp-Connection-Id`；期望 2xx。
     *
     * @return array{ok:bool,status:int,body:string}
     */
    private function webAcpRequest(string $link, string $token, string $connId, string $method, array $params, int $id): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json, text/event-stream',
            'Content-Type'  => 'application/json',
            'User-Agent'    => self::WEB_USER_AGENT,
        ];
        if ($connId !== '') {
            $headers['Acp-Connection-Id'] = $connId;
        }

        $rpc = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];

        try {
            $res = $this->http()->withHeaders($headers)->timeout(20)->postJson($link, $rpc);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'body' => $e->getMessage()];
        }

        $code = $res->status();

        return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'body' => $res->snippet(200)];
    }

    /**
     * 跑完一轮网页 agent 会话（ACP over HTTP+SSE）。
     *
     * 顺序与网页端一致：
     *   SSE 建通道（取 Acp-Connection-Id）→ initialize → session/load →
     *   session/set_model（可失败，不致命）→ session/prompt → 轮询会话状态至 completed。
     *
     * PHP 单线程下无法像参考实现那样另开读取线程，故把 ACP 请求序列与状态轮询
     * 都放在 SSE 回调内：拿到 Acp-Connection-Id 的响应头时发请求序列，之后每
     * WEB_TURN_POLL_INTERVAL 秒查一次会话状态，completed/failed 即主动断开。
     *
     * @param callable():string $pollStatus 返回会话状态字符串
     * @return array{ok:bool,status:string,chunks:int,message:string}
     */
    private function webAcpTurn(
        Account $account,
        string $link,
        string $token,
        string $sessionId,
        string $cwd,
        string $prompt,
        string $model,
        callable $pollStatus
    ): array {
        $started  = now_ms();
        $connId   = '';
        $sent     = false;
        $buffer   = '';
        $chunks   = 0;
        $updates  = 0;
        $seen     = '';
        $error    = '';
        $lastPoll = $started;

        // ACP 请求序列：拿到连接 id 后发一次；initialize 未成功则允许在下一段字节重试。
        $sequence = function () use (&$sent, &$connId, &$error, $link, $token, $sessionId, $cwd, $prompt, $model): void {
            if ($sent || $connId === '') {
                return;
            }

            $caps = ['fs' => ['readTextFile' => false, 'writeTextFile' => false], 'terminal' => false];

            $r = $this->webAcpRequest($link, $token, $connId, 'initialize', [
                'protocolVersion'    => self::WEB_ACP_PROTOCOL_VERSION,
                'clientCapabilities' => $caps,
            ], 1);
            if (!$r['ok']) {
                $error = 'ACP initialize 失败（HTTP ' . $r['status'] . '）';

                return;
            }

            $sent = true;

            $r = $this->webAcpRequest($link, $token, $connId, 'session/load', [
                'sessionId'  => $sessionId,
                'cwd'        => $cwd !== '' ? $cwd : '/workspace',
                'mcpServers' => [],
            ], 2);
            if (!$r['ok']) {
                $error = 'ACP session/load 失败（HTTP ' . $r['status'] . '）';

                return;
            }

            // 不致命：失败则本轮按会话默认模型跑（网页端默认 Auto，会按付费模型计费）
            if ($model !== '') {
                $this->webAcpRequest($link, $token, $connId, 'session/set_model', [
                    'sessionId' => $sessionId,
                    'modelId'   => $model,
                ], 3);
            }

            $r = $this->webAcpRequest($link, $token, $connId, 'session/prompt', [
                'sessionId' => $sessionId,
                'prompt'    => [['type' => 'text', 'text' => $prompt]],
            ], 4);
            if (!$r['ok']) {
                $error = 'ACP session/prompt 失败（HTTP ' . $r['status'] . '）';
            }
        };

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $link,
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => self::WEB_TURN_TIMEOUT,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME  => self::WEB_TURN_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/event-stream',
                'Authorization: Bearer ' . $token,
                'User-Agent: ' . self::WEB_USER_AGENT,
                'Cache-Control: no-cache',
            ],
            // 响应头回调：抓 Acp-Connection-Id（拿到即发 ACP 请求序列）
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$connId, $sequence): int {
                $trimmed = trim($line);
                if ($connId === '' && stripos($trimmed, 'acp-connection-id:') === 0) {
                    $connId = trim(substr($trimmed, strlen('acp-connection-id:')));
                    $sequence();
                }

                return strlen($line);
            },
            // SSE 正文回调：解析 data: 行、统计输出段、定期轮询会话状态
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (
                &$buffer, &$chunks, &$updates, &$seen, &$lastPoll, $sequence, $pollStatus
            ): int {
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $pos), "\r");
                    $buffer = substr($buffer, $pos + 1);

                    if ($line === '' || str_starts_with($line, ':')) {
                        continue;
                    }

                    if (str_starts_with($line, 'data:')) {
                        $payload = ltrim(substr($line, 5));
                        if ($payload !== '' && $payload !== '[DONE]') {
                            $obj = json_decode($payload, true);
                            if (is_array($obj) && ($obj['method'] ?? '') === 'session/update') {
                                $updates++;
                                $update = $obj['params']['update'] ?? [];
                                if (is_array($update) && ($update['sessionUpdate'] ?? '') === 'agent_message_chunk') {
                                    $chunks++;
                                }
                            }
                        }
                    }
                }

                $now = now_ms();
                if ($now - $lastPoll >= self::WEB_TURN_POLL_INTERVAL * 1000) {
                    $lastPoll = $now;
                    $seen = (string) $pollStatus();
                    if (in_array($seen, ['completed', 'failed', 'error'], true)) {
                        return 0; // 主动断开 SSE
                    }
                }

                return strlen($chunk);
            },
        ]);

        $proxy = $this->http()->proxy();
        if ($proxy !== null && $proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }

        curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 收尾：若从未拿到状态（SSE 静默/被超时掐断），补查一次
        if ($seen === '' || !in_array($seen, ['completed', 'failed', 'error'], true)) {
            $seen = (string) $pollStatus();
        }

        $elapsed = now_ms() - $started;

        if ($seen === 'completed' && $error === '') {
            return [
                'ok'      => true,
                'status'  => $seen,
                'chunks'  => $chunks,
                'message' => sprintf('网页通道会话跑完：%d 段输出，%d ms', $chunks, $elapsed),
            ];
        }

        if ($error === '') {
            $error = sprintf(
                '网页通道会话未跑完（status=%s，HTTP %d，errno=%d，%d 段输出）',
                $seen !== '' ? $seen : 'unknown',
                $status,
                $errno,
                $chunks
            );
        }

        return ['ok' => false, 'status' => $seen, 'chunks' => $chunks, 'message' => $error];
    }

    /**
     * 国际版每日活跃「网页腿」：建会话 → 取沙箱 session → 跑完一轮 ACP 会话。
     *
     * 计入官方「每日活跃」的只有这条腿（桌面 chat/completions 不计数、
     * 只建会话不接沙箱同样不计数）。
     */
    private function injectActivityWeb(Account $account, string $model, string $prompt): array
    {
        $token = (string) $account->cred('accessToken', '');
        if ($token === '') {
            return ['ok' => false, 'message' => '缺少 accessToken，请重新登录', 'data' => null];
        }

        $created = $this->webCreateConversation($account, $model, $prompt);
        if (!$created['ok']) {
            return ['ok' => false, 'message' => $created['message'], 'data' => null];
        }

        $conversation = $created['id'];

        $session   = $this->webConversationGet($account, $conversation, '/session');
        $link      = (string) ($session['link'] ?? $session['endpoint'] ?? '');
        $stoken    = (string) ($session['token'] ?? '');
        $sessionId = (string) ($session['sessionId'] ?? $session['session_id'] ?? $conversation);
        $cwd       = (string) ($session['cwd'] ?? '/workspace');

        if ($link === '' || $stoken === '') {
            return [
                'ok'      => false,
                'message' => '沙箱未就绪（没有 link/token）',
                'data'    => ['conversation' => $conversation],
            ];
        }

        $pollStatus = fn(): string => $this->webConversationStatus($account, $conversation);

        $turn = $this->webAcpTurn($account, $link, $stoken, $sessionId, $cwd, $prompt, $model, $pollStatus);

        return [
            'ok'      => $turn['ok'],
            'message' => $turn['message'],
            'data'    => [
                'conversation' => $conversation,
                'status'       => $turn['status'],
                'chunks'       => $turn['chunks'],
            ],
        ];
    }

    // ---------------------------------------------------------
    // 动作：签到（还原 La2/b->e 的 checkin 分支）
    // ---------------------------------------------------------

    public function action(Account $account, string $action, array $params = []): array
    {
        if ($action === 'checkin') {
            return $this->checkin($account);
        }

        if ($action === 'tasks') {
            return $this->tasks($account, (int) ($params['seeds'] ?? 0));
        }

        // 连登管家：补签 → 兑换连登奖励 → 抽奖 → 开盲盒 → 一次性礼包/补偿（国服成长域）
        if ($action === 'streak') {
            return $this->streak($account);
        }

        // 猫猫旅行（成长域）：查档案 → 无猫则同意协议+领养，有猫则按状态派出/领奖
        if ($action === 'travel') {
            $location = trim((string) ($params['location'] ?? '古镇客栈'));

            return $this->travel($account, $location !== '' ? $location : '古镇客栈');
        }

        // 成长中心「代做」：网关替用户把客户端该做的事做掉（automation_1 建自动化任务、
        // 对话计数型发真实对话），随后立刻领奖。耗时长（含 90~150s 结算等待），
        // 由调用方走异步 worker 路径，不要挂在 HTTP 请求里同步跑。
        if ($action === 'drive_task') {
            return $this->growthAction($account, 'drive_task', $params);
        }

        // 每日活跃注入：发一条最小对话，触发上游「当日活跃」判定（供每日发积分）
        if ($action === 'activity') {
            return $this->injectActivity($account, (string) ($params['model'] ?? ''));
        }

        // 自检：用计费接口做真实连通性探测（登录态无效会 401/403，额度缺失说明 token 不完整）
        if ($action === 'test' || $action === 'connectivity') {
            $token = (string) $account->cred('accessToken', '');
            if ($token === '') {
                return ['ok' => false, 'message' => '缺少 accessToken，请重新登录', 'data' => null];
            }

            $credit = $this->credits($account);
            if (!$credit->known) {
                return [
                    'ok'      => false,
                    'message' => $credit->error ?: ($credit->detail !== '' ? $credit->detail : '连通失败'),
                    'data'    => null,
                ];
            }

            $models = $this->models($account);

            return [
                'ok'      => true,
                'message' => '连通正常，可用模型 ' . count($models) . ' 个',
                'data'    => [
                    'models'  => $models,
                    'count'   => count($models),
                    'balance' => $credit->balance,
                    'packs'   => $credit->packs,
                ],
            ];
        }

        return parent::action($account, $action, $params);
    }

    /**
     * 签到/计费请求头（还原 La2/b->p）
     *
     * Authorization: Bearer <token>、Accept/Content-Type、X-Requested-With、
     * User-Agent "CLI/2.63.2 CodeBuddy/2.63.2"、Origin/Referer（按域名解析）、
     * X-Product "SaaS"，以及按需携带的 X-User-Id / X-Enterprise-Id + X-Tenant-Id / X-Domain。
     */
    private function meterHeaders(Account $account): array
    {
        $origin = $this->domainHost($account, 'https://www.workbuddy.ai', 'https://www.codebuddy.cn');

        $headers = [
            'Authorization'    => 'Bearer ' . (string) $account->cred('accessToken', ''),
            'Accept'           => 'application/json',
            'Content-Type'     => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
            'User-Agent'       => 'CLI/' . self::CLI_VERSION . ' CodeBuddy/' . self::CLI_VERSION,
            'Origin'           => $origin,
            'Referer'          => $origin . '/',
            'X-Product'        => 'SaaS',
        ];

        $uid = (string) $account->cred('uid', $account->uid);
        if ($uid !== '') {
            $headers['X-User-Id'] = $uid;
        }

        $enterpriseId = (string) $account->cred('enterpriseId', '');
        if ($enterpriseId !== '') {
            $headers['X-Enterprise-Id'] = $enterpriseId;
            $headers['X-Tenant-Id'] = $enterpriseId;
        }

        $domain = (string) $account->cred('domain', '');
        if ($domain !== '') {
            $headers['X-Domain'] = $domain;
        }

        return $headers;
    }

    /**
     * 查询签到活动状态：POST {host}/v2/billing/meter/checkin-activity-status
     *
     * 返回 data 节点（含 active / today_checked_in / streak_days / daily_credit 等）；失败返回 null。
     *
     * @return array<string,mixed>|null
     */
    private function checkinActivityStatus(string $host, array $headers): ?array
    {
        try {
            $res = $this->http()->withHeaders($headers)->timeout(20)
                ->postJson($host . '/v2/billing/meter/checkin-activity-status', new \stdClass());
        } catch (\Throwable $e) {
            return null;
        }

        if (!$res->ok()) {
            return null;
        }

        $data = $res->json();
        if (!is_array($data) || (int) ($data['code'] ?? -1) !== 0) {
            return null;
        }

        $node = $data['data'] ?? null;

        return is_array($node) ? $node : null;
    }

    /**
     * 每日签到：POST {domainHost}/v2/billing/meter/daily-checkin，body 固定为 "{}"
     *
     * 还原 La2/b->e：
     *   - 主机按域名解析：global → www.workbuddy.ai，否则 www.codebuddy.cn
     *   - 2xx 时读取 code：0 → 签到成功；否则取 message，
     *     命中「已签到 / already」→ 今日已签到，否则「签到失败（code=N）」
     *   - 非 2xx → 「签到失败（HTTP N）」拼接响应体
     */
    private function checkin(Account $account): array
    {
        $host = $this->domainHost($account, 'https://www.workbuddy.ai', 'https://www.codebuddy.cn');
        $headers = $this->meterHeaders($account);

        // 国际版：签到活动常未开启（每日活跃奖励由上游自动发放），先查活动状态避免误报 HTTP 错误
        if ($this->isGlobalDomain((string) $account->cred('domain', ''))) {
            $status = $this->checkinActivityStatus($host, $headers);
            if (is_array($status)) {
                if (!empty($status['today_checked_in'])) {
                    return ['ok' => true, 'message' => '今日已签到', 'data' => $status];
                }
                if (array_key_exists('active', $status) && empty($status['active'])) {
                    return [
                        'ok'      => true,
                        'message' => '国际版签到活动未开启（每日活跃奖励自动发放，无需手动签到）',
                        'data'    => $status,
                    ];
                }
            }
        }

        $url = $host . '/v2/billing/meter/daily-checkin';

        try {
            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($url, new \stdClass());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage() !== '' ? $e->getMessage() : '签到失败', 'data' => null];
        }

        $body = $res->body();
        $data = is_array($res->json()) ? $res->json() : [];

        if ($res->status() === 401 || $res->status() === 403) {
            return ['ok' => false, 'message' => '登录已失效，请重新登录', 'data' => $data];
        }

        if (!$res->ok()) {
            $message = $this->extractMessage($body);
            // 「已签到」为幂等成功（上游曾用 HTTP 400 + code 10001 表示今日已签到）
            if ($this->isAlreadyCheckedInMessage($message)) {
                return ['ok' => true, 'message' => '今日已签到', 'data' => $data];
            }
            // 「活动未开启 / 已过期」视为非账号故障，返回提示而非 HTTP 错误
            if ($this->isCheckinUnavailableMessage($message)) {
                return ['ok' => true, 'message' => $message, 'data' => $data];
            }
            // 还原 La2/b->e：'签到失败（HTTP N）：' + LV1/l->d(body)
            return [
                'ok'      => false,
                'message' => '签到失败（HTTP ' . $res->status() . '）：' . $message,
                'data'    => $data,
            ];
        }

        $code = isset($data['code']) && is_numeric($data['code']) ? (int) $data['code'] : -1;

        if ($code === 0) {
            return ['ok' => true, 'message' => '签到成功', 'data' => $data];
        }

        $message = $this->extractMessage($body);

        if ($this->isAlreadyCheckedInMessage($message)) {
            return ['ok' => true, 'message' => '今日已签到', 'data' => $data];
        }

        if ($message === '') {
            $message = '签到失败（code=' . $code . '）';
        }

        return ['ok' => false, 'message' => $message, 'data' => $data];
    }

    /**
     * 判断上游提示是否表示「今日已签到」（还原 La2/b->e：含「已签到」或「already」）
     */
    private function isAlreadyCheckedInMessage(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        foreach (['已签到', 'already'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断上游提示是否表示「签到活动未开启 / 已过期」（视为非账号故障，友好提示）
     */
    private function isCheckinUnavailableMessage(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        foreach (['未开启', '已过期', '未开放', 'not open', 'expired'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------
    // 动作：成长中心（还原 La2/b->e 的 tasks 分支）
    // ---------------------------------------------------------

    /**
     * 成长中心自动领奖：还原 La2/b->e 的 tasks 分支
     *
     * 主机取 q()（global → www.workbuddy.ai，否则 copilot.tencent.com），
     * 拼接 "/v2/activity/growth" 作为根路径，头取 p()（计费头）。
     * 依次执行：任务接取（accept）→ Buddy 旅行（status/claim/config/depart）
     * → 任务列表领奖（/tasks + /tasks/{code}/claim）→ Buddy 盲盒（/buddy/quota + /buddy/open），
     * 任一段抛错都被吞掉并继续下一段；
     * 汇总结果为「；」分隔的字符串，无任何可领取内容时返回「成长中心没有可领取的内容」。
     *
     * ⚠️ **任务完成分三类**（2026-10-02 客户端逆向 + 受控实测确认）：
     *   上游只有「接取（accept）」与「领奖（claim）」两个**成长域**写接口，
     *   但**其他域名下存在可代做的写接口**（见第 3 类）。
     *   `accept_status` 五态：not_accepted → accepted → in_progress → completed → claimed。
     *
     *   1. **对话计数型**（`chat_5` 累计聊天、`Model_chat_<X>` 指定模型对话）：
     *      **可自动化** —— 向 `/v2/chat/completions` 发真实对话即计数。
     *      🔴 **结算极慢：90~150 秒**（实测 4 次对话后 +90s 才 +1、+150s 才满 5），
     *      故 `$seeds>0` 时发完种子对话**先 sleep 等结算，再回头领奖**。
     *   2. **客户端功能型 · 有 REST 写接口**（**可自动化**，2026-10-02 实证）：
     *      `automation_1`（设置自动化任务）→ `POST /v2/as/scheduler/tasks`
     *      （**201 Created**；必填 `name` + `cronExpr`(6 段) + `agentConfig{prompt,agentType}`）
     *      → 任务 `0/1 → completed` → `POST /v2/activity/growth/tasks/automation_1/claim`
     *      → **+100 积分 + 5 能量 + 徽章**（实测已走通完整闭环）。
     *      清理用 `DELETE /v2/as/scheduler/tasks/{id}`。
     *   3. **客户端功能型 · 无写接口**（**做不了**）：`create_canvas` / `Library_read` /
     *      `Expert_lighthouse` / `Buddy_App` / `skill_1` / `expert_5` / `template_5` /
     *      `Hp_Appearance` / `wb_wechat_oa_subscribe_task` / `playbook_prompt`。
     *      实测各候选写接口**全 404**（`/v2/as/canvas`、`/v2/expert/summon`、`/v2/skill/install`、
     *      `/v2/template/list` 等）；专家数据走 **COS 静态 manifest**（非计数接口）；
     *      计数判据 100% 在服务端，客户端 app.asar 内**搜不到任何 task_code**。
     *
     *   本方法能做的：接取 + 驱动对话计数型 + **代做有接口的功能型任务** + 自动领奖。
     *
     * @param int $seeds 为对话计数型任务补发的对话条数（0 = 只接取/领奖，不发对话）
     */
    private function tasks(Account $account, int $seeds = 0): array
    {
        $base = $this->hostChat($account) . '/v2/activity/growth';
        $headers = $this->meterHeaders($account);
        $results = [];

        // 0) 接取任务：把 not_accepted 推进为 accepted（官方前端点击「去完成」时会先做这一步）
        $accepted = 0;
        try {
            $accepted = $this->acceptTasks($base, $headers);
        } catch (\Throwable $e) {
        }

        // 0.5) 驱动「对话计数型」任务：发真实对话让 chat_5 / Model_chat_* 计数
        if ($seeds > 0) {
            try {
                $seeded = $this->seedChatTasks($account, $base, $headers, $seeds);

                if ($seeded['sent'] > 0) {
                    // 🔴 上游结算 90~150s，等待后才可能读到新的 accept_status
                    sleep(min(150, max(90, $seeded['settle'])));
                }

                if ($seeded['done'] !== []) {
                    $results[] = '已驱动成长任务：' . implode('、', $seeded['done']);
                }
            } catch (\Throwable $e) {
            }
        }

        // 0.6) 代做「有 REST 写接口」的功能型任务（目前仅 automation_1）
        //     与 seedChatTasks 不同：这类任务**不需要长结算**（实测创建后即 completed），
        //     所以放在领奖前、紧邻执行即可。
        try {
            $driven = $this->driveFeatureTasks($account, $base, $headers);

            if ($driven !== []) {
                $results[] = '已代做成长任务：' . implode('、', $driven);
            }
        } catch (\Throwable $e) {
        }

        try {
            $this->collectTravelRewards($base, $headers, $results);
        } catch (\Throwable $e) {
        }

        try {
            $this->collectTaskRewards($base, $headers, $results);
        } catch (\Throwable $e) {
        }

        try {
            $this->collectBuddyBox($base, $headers, $results);
        } catch (\Throwable $e) {
        }

        // 接取结果并入汇报：让「任务已被接取、但还没做」这件事在日志里可见，
        // 避免用户把「接取了」误当成「做完了」而以为功能无效。
        if ($accepted > 0) {
            array_unshift($results, '已接取 ' . $accepted . ' 个成长任务（需在客户端完成后才能领奖）');
        }

        if ($results === []) {
            return ['ok' => true, 'message' => '成长中心没有可领取的内容', 'data' => ['items' => []]];
        }

        return ['ok' => true, 'message' => implode('；', $results), 'data' => ['items' => $results]];
    }

    /**
     * 连登管家闭环（国服成长域）：一次调用把「可做的事」按优先级全部做掉。
     *
     * 顺序（每一步都以实时快照为判据，无内容则跳过）：
     *   1) 补签：`makeup_cards.balance > 0` 且 `streak.makeup_dates` 非空 → 对最早的可补日期 `use_makeup`
     *   2) 兑换连登奖励：`redemption_status.tier_{7d,14d,28d}_status === 'available'` → 依次 `redeem`
     *   3) 抽奖：`lottery.balance > 0` → `lottery_draw`
     *   4) 开盲盒：`box.affordable > 0` → `open_box`
     *   5) 一次性礼包 / 活动补偿：`claim_gift` / `claim_compensation`（已领过上游返回失败，静默跳过）
     *
     * 全部复用 `growthAction()` 的现成动作；快照强制刷新（绕过 45s 缓存），保证判据实时。
     *
     * @return array{ok:bool,message:string,data:array<string,mixed>}
     */
    private function streak(Account $account): array
    {
        $snapshot = $this->growthSnapshot($account, true);

        if (!($snapshot['ok'] ?? false)) {
            return [
                'ok'      => false,
                'message' => (string) ($snapshot['message'] ?? '成长域不可达'),
                'data'    => null,
            ];
        }

        $data    = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        $results = [];

        // 1) 补签：有补签卡 + 有可补日期 → 对最早一天使用
        $cards = (int) ($data['makeup_cards']['balance'] ?? 0);
        $dates = $data['streak']['makeup_dates'] ?? [];
        if ($cards > 0 && is_array($dates) && $dates !== []) {
            $date = (string) reset($dates);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $r = $this->growthAction($account, 'use_makeup', ['target_date' => $date]);

                if ($r['ok'] ?? false) {
                    $results[] = '已补签 ' . $date;
                }
            }
        }

        // 2) 兑换连登奖励：按 7d → 14d → 28d 顺序兑换所有 available 档位
        $status = [
            '7d'  => (string) ($data['redemption']['tier_7d_status'] ?? ''),
            '14d' => (string) ($data['redemption']['tier_14d_status'] ?? ''),
            '28d' => (string) ($data['redemption']['tier_28d_status'] ?? ''),
        ];
        foreach (['7d', '14d', '28d'] as $tier) {
            if ($status[$tier] !== 'available') {
                continue;
            }

            $r = $this->growthAction($account, 'redeem', ['tier' => $tier]);

            if ($r['ok'] ?? false) {
                $msg = (string) ($r['message'] ?? '');
                $results[] = $msg !== '' ? $msg : ('已兑换 ' . $tier . ' 连登奖励');
            }
        }

        // 3) 抽奖：有抽奖次数就抽
        if ((int) ($data['lottery']['balance'] ?? 0) > 0) {
            $r = $this->growthAction($account, 'lottery_draw', []);

            if ($r['ok'] ?? false) {
                $msg = (string) ($r['message'] ?? '');
                $results[] = $msg !== '' ? $msg : '已抽奖';
            }
        }

        // 4) 开盲盒：有可开次数就开
        if ((int) ($data['box']['affordable'] ?? 0) > 0) {
            $r = $this->growthAction($account, 'open_box', []);

            if ($r['ok'] ?? false) {
                $msg = (string) ($r['message'] ?? '');
                $results[] = $msg !== '' ? $msg : '已开盲盒';
            }
        }

        // 5) 一次性礼包 / 活动补偿（已领过上游返回失败，静默跳过，不污染汇报）
        foreach (['claim_gift', 'claim_compensation'] as $act) {
            $r = $this->growthAction($account, $act, []);

            if ($r['ok'] ?? false) {
                $msg = (string) ($r['message'] ?? '');
                if ($msg !== '') {
                    $results[] = $msg;
                }
            }
        }

        if ($results === []) {
            return ['ok' => true, 'message' => '连登管家没有可领取的内容', 'data' => ['items' => []]];
        }

        return ['ok' => true, 'message' => implode('；', $results), 'data' => ['items' => $results]];
    }

    /**
     * 接取成长任务：POST /tasks/accept，body {"task_codes":[...]}
     *
     * 路径口径（2026-10-02 对照网页版官方前端 `growthSpace-*.js` 确认）：
     *   `POST /activity/growth/tasks/accept  {task_codes: string[]}`
     *   注意**不带 `/v2` 前缀**（查询类 `/v2/activity/growth/tasks` 带；写类 accept/claim 不带）。
     *   返回 `{"code":0,"data":{"results":[{"task_code":..,"status":"accepted|already_accepted"}]}}`。
     *
     * 语义：把 `not_accepted` 推进为 `accepted`。这是官方前端点击「去完成」时的第一步，
     * 也是 claim 的前置之一。**但它不会让任务变成 `completed`** —— 完成仍需用户在客户端
     * 真实使用对应功能（详见 `tasks()` 的类注释）。
     *
     * 只接取「可接取且未领取」的任务：跳过 locked、跳过 accepted/in_progress（已接）、
     * 跳过 completed（可直接领奖）、跳过 claimed（已领）。
     *
     * @return int 本次新接取的任务数（already_accepted 不计）
     */
    private function acceptTasks(string $base, array $headers): int
    {
        $res = $this->http()->withHeaders($headers)->timeout(30)->get($base . '/tasks');

        if (!$res->ok()) {
            return 0;
        }

        $node = $this->growthNode($res->body());

        if ($node === null || !isset($node['tasks']) || !is_array($node['tasks'])) {
            return 0;
        }

        $codes = [];

        foreach ($node['tasks'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($this->jsonBool($item, 'locked')) {
                continue;
            }

            $status = $this->jsonString($item, ['accept_status']);

            // 仅接取「完全未开始」的任务；其余状态无需再接
            if ($status !== 'not_accepted') {
                continue;
            }

            $code = $this->jsonString($item, ['task_code']);

            if ($code !== '') {
                $codes[] = $code;
            }
        }

        if ($codes === []) {
            return 0;
        }

        // 官方前端按数组一次性提交；这里分批（每批 8 个）以降低单请求体积与风控压力
        $accepted = 0;

        foreach (array_chunk($codes, 8) as $chunk) {
            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($base . '/tasks/accept', ['task_codes' => $chunk]);

            if (!$res->ok()) {
                continue;
            }

            $node = $this->growthNode($res->body());

            foreach ((array) ($node['results'] ?? []) as $r) {
                if (is_array($r) && ($r['status'] ?? '') === 'accepted') {
                    $accepted++;
                }
            }
        }

        return $accepted;
    }

    /**
     * 驱动「对话计数型」成长任务：向 /v2/chat/completions 发真实对话让任务计数
     *
     * 适用任务（`task_type=single` 但计数由服务端按对话次数累加）：
     *   - `chat_5`            ：「和 AI 聊天 5 次」，任意模型每次对话计 1
     *   - `Model_chat_<X>`    ：「体验某模型」，需用**该模型**对话一次
     *
     * 🔴 **实测结论（2026-10-02，账号 #234）**：
     *   - 通过网关/裸 API 发的对话**确实会**计入 `chat_5`（实测 4 连发后 90s 计到 +1、
     *     150s 达 5/5 并成功 claim +100）；此前误判「不计入」是因为**只等了 4~30 秒**。
     *   - 上游结算延迟 **90~150 秒**，必须等，见 `tasks()` 里的 `sleep`。
     *   - `Model_chat_GLM5.2` 实测连发 8+ 次 glm-5.2 仍不计数 —— 该任务的完成判据不在
     *     `/v2/chat/completions` 的模型字段上（疑需客户端会话上下文），暂不驱动。
     *
     * 策略：读取 `/tasks`，对「accepted / in_progress 且未完成」的对话计数型任务，
     * 按剩余差额补发对话；`Model_chat_*` 只有在模型名能映射到真实可用模型时才发。
     * 全程吞异常 —— 发对话失败不应影响后续领奖。
     *
     * @return array{sent:int,settle:int,done:list<string>}
     */
    private function seedChatTasks(Account $account, string $base, array $headers, int $budget): array
    {
        $out = ['sent' => 0, 'settle' => 0, 'done' => []];

        if ($budget <= 0) {
            return $out;
        }

        $res = $this->http()->withHeaders($headers)->timeout(30)->get($base . '/tasks');

        if (!$res->ok()) {
            return $out;
        }

        $node = $this->growthNode($res->body());

        if ($node === null || !isset($node['tasks']) || !is_array($node['tasks'])) {
            return $out;
        }

        // 可用模型集合：用于判断 Model_chat_<X> 的任务模型是否真实可发
        $available = [];

        try {
            foreach ($this->models($account) as $id) {
                $available[strtolower((string) $id)] = true;
            }
        } catch (\Throwable $e) {
        }

        $spent = 0;

        foreach ($node['tasks'] as $item) {
            if (!is_array($item) || $this->jsonBool($item, 'locked')) {
                continue;
            }

            $code   = $this->jsonString($item, ['task_code']);
            $status = $this->jsonString($item, ['accept_status']);

            // 只驱动「已接取且尚未完成」的任务
            if (!in_array($status, ['accepted', 'in_progress'], true)) {
                continue;
            }

            $model = '';
            $need  = 1;

            if ($code === 'chat_5') {
                // 累计型：target - current = 还差几次
                $progress = $item['progress'] ?? null;
                $current  = is_array($progress) ? (int) ($progress['current'] ?? 0) : 0;
                $target   = is_array($progress) ? (int) ($progress['target'] ?? 5) : 5;
                $need     = max(1, $target - $current);
                $model    = $this->pickActivityModel($account);
            } elseif (str_starts_with($code, 'Model_chat_')) {
                // 指定模型：task_code 里 <X> 是展示名（如 GLM5.2），需归一化为真实模型 id
                $candidate = strtolower(str_replace('Model_chat_', '', $code));
                $candidate = str_replace('.', '-', $candidate);   // GLM5.2 → glm5-2

                foreach (array_keys($available) as $id) {
                    if (str_replace(['-', '.'], '', $id) === str_replace(['-', '.'], '', $candidate)) {
                        $model = (string) $id;
                        break;
                    }
                }

                // ⚠️ GLM-5.2 实测不计数，跳过以免白烧额度；其余模型先按 1 次尝试
                if ($model === '' || str_contains($model, 'glm-5.2')) {
                    continue;
                }

                $need = 1;
            } else {
                continue;   // 非对话计数型任务，网关无 API 可驱动
            }

            if ($model === '') {
                continue;
            }

            $remaining = $budget - $spent;

            if ($remaining <= 0) {
                break;
            }

            $send = min($need, $remaining);

            for ($i = 0; $i < $send; $i++) {
                try {
                    $this->injectSeedChat($account, $model, $i);
                    $spent++;
                    $out['sent']++;
                    // 对话间隔：既降风控压力，也让上游有散点会话可计
                    usleep(700000);
                } catch (\Throwable $e) {
                }
            }

            $out['done'][] = $code;

            if ($spent >= $budget) {
                break;
            }
        }

        // 只要发过对话就要等结算；发得越多越可能触发多条任务，等待窗口给足
        $out['settle'] = $out['sent'] > 0 ? 120 : 0;

        return $out;
    }

    /**
     * 代做「有 REST 写接口」的功能型任务（2026-10-02 实证）
     *
     * 🔴 **推翻上轮结论**：上轮判「客户端功能型任务全部做不了」是**错的** ——
     *   当时只试了成长域（`/v2/activity/growth/*`）的接口，而真正的写接口
     *   挂在**另一个域**（`/v2/as/scheduler/tasks`）。
     *
     * **已实证可代做**（账号 #234，2026-10-02）：
     *   `automation_1`「设置自动化任务」：
     *     ① POST `/v2/as/scheduler/tasks` → **201 Created**
     *        必填：`name` + `cronExpr`（**6 段**，如 `0 0 9 * * *`）+ `agentConfig{prompt,agentType}`
     *     ② 任务从 `0/1` → `completed`
     *     ③ POST `/v2/activity/growth/tasks/automation_1/claim` → **+100 积分 + 5 能量 + 徽章**
     *   实测后已清理（DELETE `/v2/as/scheduler/tasks/{id}`），任务终态 `claimed`。
     *
     * **不可代做**（各候选写接口实测全 404）：
     *   `create_canvas` / `Library_read` / `Expert_lighthouse` / `Buddy_App` /
     *   `skill_1` / `expert_5` / `template_5` / `Hp_Appearance` /
     *   `wb_wechat_oa_subscribe_task` / `playbook_prompt`。
     *   根因：专家/技能/模板数据走 **COS 静态 manifest**（`ExpertManifestCache`），
     *   不是可计数的 REST 接口；画布的创建全在客户端本地。
     *
     * @return list<string> 已代做的 task_code
     */
    private function driveFeatureTasks(Account $account, string $base, array $headers): array
    {
        $done = [];

        try {
            $node = $this->taskNode($base, $headers);

            if ($node === null) {
                return $done;
            }

            foreach ($node['tasks'] as $item) {
                if (!is_array($item) || $this->jsonBool($item, 'locked')) {
                    continue;
                }

                $code   = $this->jsonString($item, ['task_code']);
                $status = $this->jsonString($item, ['accept_status']);

                if (!in_array($status, ['accepted', 'in_progress'], true)) {
                    continue;
                }

                if ($code === 'automation_1' && $this->createAutomationTask($account)) {
                    $done[] = $code;
                }
            }
        } catch (\Throwable $e) {
            // 代做失败不影响接取/领奖
        }

        return $done;
    }

    /**
     * 创建一条自动化任务（`automation_1` 的达成动作）
     *
     * 端点：`POST /v2/as/scheduler/tasks`（注意**不在** `/v2/activity/growth` 下）
     * 必填字段由服务端 `CreateTaskRequest` 校验给出：
     *   - `cronExpr`：6 段 cron（客户端注释「6 段 cron 无法无损转 iCal RRULE 子集」可证）
     *   - `agentConfig.prompt` / `agentType`：任务体
     * 返回 `{data:{id,taskKey,status,ownerType,agentType},success:true}`（HTTP **201**）。
     *
     * 创建后**立即删除**：成长任务只计「是否创建过」，留垃圾任务会污染用户自动化列表。
     */
    private function createAutomationTask(Account $account): bool
    {
        $created = false;
        $taskId  = null;

        try {
            $res = $this->http()->withHeaders($this->meterHeaders($account))->timeout(30)->postJson(
                $this->hostChat($account) . '/v2/as/scheduler/tasks',
                [
                    'name'          => '成长任务-自动创建',
                    'description'   => '由网关代做成长任务时自动创建，随后删除',
                    'frequencyType' => 'once',
                    'cronExpr'      => '0 0 9 * * *',
                    'timezone'      => 'Asia/Shanghai',
                    'agentConfig'   => ['prompt' => '成长任务占位', 'agentType' => 'cli'],
                    'status'        => 0,
                ]
            );

            if ($res->status() >= 200 && $res->status() < 300) {
                $created = true;
                // 该接口**不是**成长域的 {code,data} 信封，而是扁平 {data:{id},success:true}
                // → 必须用 decodeJsonNode（不剥信封）后再取 data.id，用 growthNode 会取错
                $node   = $this->decodeJsonNode($res->body());
                $taskId = is_array($node) ? ($node['data']['id'] ?? null) : null;
            }
        } catch (\Throwable $e) {
        }

        // 无论创建结果，都不把垃圾任务留在用户列表里
        if ($taskId !== null) {
            try {
                $this->http()->withHeaders($this->meterHeaders($account))->timeout(20)
                    ->delete($this->hostChat($account) . '/v2/as/scheduler/tasks/' . rawurlencode((string) $taskId));
            } catch (\Throwable $e) {
            }
        }

        return $created;
    }

    /**
     * 读成长任务列表并剥信封，返回 `{tasks:[...]}` 或 null
     *
     * 供 `driveFeatureTasks()` 等需要「先看当前任务态再决定动作」的场景复用，
     * 避免每处都重复写 `get + ok + growthNode + 字段校验` 四步。
     */
    private function taskNode(string $base, array $headers): ?array
    {
        try {
            $res = $this->http()->withHeaders($headers)->timeout(30)->get($base . '/tasks');

            if (!$res->ok()) {
                return null;
            }

            $node = $this->growthNode($res->body());

            if ($node === null || !isset($node['tasks']) || !is_array($node['tasks'])) {
                return null;
            }

            return $node;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 发一条种子对话（供 `seedChatTasks` 计数用）
     *
     * 走 `bizHeaders()` 的正式对话头（与用户真实调用同源），确保被算作真实对话。
     */
    private function injectSeedChat(Account $account, string $model, int $seq): void
    {
        $request = new ChatRequest(
            model: $model,
            messages: [['role' => 'user', 'content' => '请用一句话简单回复：这是第 ' . ($seq + 1) . ' 条测试消息。']],
            stream: true,
        );

        $this->chat($account, $request, static function (string $text, array $event): bool {
            // 收到任意内容即证明对话已建立，主动结束读取
            return false;
        });
    }

    /**
     * Buddy 旅行领奖：GET /buddy/travel/status → claim / config / depart（还原 La2/b->e）
     *
     * - state=="arrived"：POST /buddy/travel/claim {record_id}，
     *   reward_credit>0 → 「旅行礼物 +N」，否则「已领旅行礼物」
     * - state=="idle" 且未达每日上限：GET /buddy/travel/config 取首个 locations.id，
     *   POST /buddy/travel/depart {location_id}；返回 location.name 为空 → 「已派 Buddy 出发」，
     *   否则「派 Buddy 去{name}」
     */
    private function collectTravelRewards(string $base, array $headers, array &$results): void
    {
        $res = $this->http()->withHeaders($headers)->timeout(30)->get($base . '/buddy/travel/status');

        if (!$res->ok()) {
            return;
        }

        $node = $this->growthNode($res->body());

        if ($node === null) {
            return;
        }

        $state = $this->jsonString($node, ['state']);
        $dailyLimitReached = $this->jsonBool($node, 'daily_limit_reached');

        if ($state === 'arrived') {
            $recordId = $this->jsonString($node, ['record_id']);

            if ($recordId === '') {
                return;
            }

            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($base . '/buddy/travel/claim', ['record_id' => (int) $recordId]);

            if (!$res->ok()) {
                return;
            }

            $claimNode = $this->growthNode($res->body());
            $reward = $claimNode !== null ? $this->jsonLong($claimNode, ['reward_credit']) : 0;

            $results[] = $reward > 0 ? '旅行礼物 +' . $reward : '已领旅行礼物';

            return;
        }

        if ($state !== 'idle' || $dailyLimitReached) {
            return;
        }

        $res = $this->http()->withHeaders($headers)->timeout(30)->get($base . '/buddy/travel/config');

        if (!$res->ok()) {
            return;
        }

        $configNode = $this->growthNode($res->body());

        if ($configNode === null || empty($configNode['locations']) || !is_array($configNode['locations'])) {
            return;
        }

        $first = $configNode['locations'][0] ?? null;

        if (!is_array($first)) {
            return;
        }

        $locationId = $this->jsonString($first, ['id']);

        if ($locationId === '') {
            return;
        }

        $res = $this->http()->withHeaders($headers)->timeout(30)
            ->postJson($base . '/buddy/travel/depart', ['location_id' => (int) $locationId]);

        if (!$res->ok()) {
            return;
        }

        $departNode = $this->growthNode($res->body());
        $name = '';

        if ($departNode !== null && isset($departNode['location']) && is_array($departNode['location'])) {
            $name = $this->jsonString($departNode['location'], ['name']);
        }

        $results[] = $name === '' ? '已派 Buddy 出发' : '派 Buddy 去' . $name;
    }

    /**
     * 成长任务领奖：GET /tasks 遍历 → POST /tasks/{task_code}/claim（还原 La2/b->e）
     *
     * accept_status 语义：not_accepted（未完成/不可领）、completed（已达成可领取）、claimed（已领取）；
     * 跳过 locked==true、accept_status=="claimed"，仅当 accept_status=="completed" 时发领取请求；
     * task_code 为空则跳过；claim 成功（HTTP 2xx 且 code==0）后 title 缺省回退 task_code，
     * data.already_claimed==true → 「已领取任务「title」」，
     * credit>0 → 「领任务奖「title」+credit」，否则「领取任务「title」」。
     */
    private function collectTaskRewards(string $base, array $headers, array &$results): void
    {
        $res = $this->http()->withHeaders($headers)->timeout(30)->get($base . '/tasks');

        if (!$res->ok()) {
            return;
        }

        $node = $this->growthNode($res->body());

        if ($node === null || !isset($node['tasks']) || !is_array($node['tasks'])) {
            return;
        }

        foreach ($node['tasks'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($this->jsonBool($item, 'locked')) {
                continue;
            }

            $acceptStatus = $this->jsonString($item, ['accept_status']);

            // 仅 accept_status=="completed" 才可领取；claimed / not_accepted 均跳过
            if ($acceptStatus !== 'completed') {
                continue;
            }

            $taskCode = $this->jsonString($item, ['task_code']);

            if ($taskCode === '') {
                continue;
            }

            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($base . '/tasks/' . rawurlencode($taskCode) . '/claim', new \stdClass());

            if (!$res->ok()) {
                continue;
            }

            // claim 响应同为信封 {code,data}：先解信封校验业务码，再取 data 节点读 already_claimed/credit
            $claimEnvelope = $this->decodeJsonNode($res->body());

            if ($claimEnvelope !== null && isset($claimEnvelope['code'])
                && is_numeric($claimEnvelope['code']) && (int) $claimEnvelope['code'] !== 0) {
                continue;
            }

            $claimNode = $this->growthNode($res->body());

            $title = $this->jsonString($item, ['title']);

            if ($title === '') {
                $title = $taskCode;
            }

            $already = $claimNode !== null && $this->jsonBool($claimNode, 'already_claimed');

            if ($already) {
                $results[] = '已领取任务「' . $title . '」';
                continue;
            }

            $credit = $claimNode !== null ? $this->jsonLong($claimNode, ['credit']) : 0;

            $results[] = $credit > 0
                ? '领任务奖「' . $title . '」+' . $credit
                : '领取任务「' . $title . '」';
        }
    }

    /**
     * Buddy 盲盒：GET /buddy/quota → POST /buddy/open（body "{}"）（还原 La2/b->e）
     *
     * ⚠️ 上游 /buddy/quota 的真实字段语义（2026-10-01 实测）：
     *   `affordable`     = **当前可开盒次数**（= floor(balance / cost_per_open)，受 max_open_count 上限约束）
     *   `balance`        = 积分余额（**不是**开盒次数！）
     *   `cost_per_open`  = 每次开盒消耗积分（通常 10）
     *   `max_open_count` = 单次可开上限（通常 5）
     * 旧实现只认 {remaining,quota,count,balance,left}，拿不到 affordable 便回落到 `balance`，
     * 于是「积分够但不够开一次」的账号（balance=8 < cost_per_open=10）会被误判为可开，
     * 白白发一次 open 被上游拒绝 → 静默 return → 管理台显示「没有可领取的内容」。
     * 因此 affordable 必须优先，且缺失时才回落到 balance/cost_per_open 自行折算。
     *
     * 返回 credit 取 {credit,reward_credit} 中首个 >0 的值，>0 → 「开盲盒 +N」否则「已开盲盒」。
     */
    private function collectBuddyBox(string $base, array $headers, array &$results): void
    {
        $res = $this->http()->withHeaders($headers)->timeout(30)->get($base . '/buddy/quota');

        if (!$res->ok()) {
            return;
        }

        $node = $this->growthNode($res->body());

        if ($node === null) {
            return;
        }

        // affordable 优先（上游权威的「可开次数」）；缺失时用 balance / cost_per_open 折算
        $remaining = $this->jsonLong($node, ['affordable']);

        if ($remaining <= 0) {
            $balance = $this->jsonLong($node, ['balance']);
            $cost    = $this->jsonLong($node, ['cost_per_open']);

            if ($balance > 0 && $cost > 0) {
                $remaining = intdiv($balance, $cost);
            }
        }

        if ($remaining <= 0) {
            return;
        }

        $res = $this->http()->withHeaders($headers)->timeout(30)
            ->postJson($base . '/buddy/open', new \stdClass());

        if (!$res->ok()) {
            return;
        }

        $openNode = $this->growthNode($res->body());
        $credit = $openNode !== null ? $this->jsonLong($openNode, ['credit', 'reward_credit']) : 0;

        $results[] = $credit > 0 ? '开盲盒 +' . $credit : '已开盲盒';
    }

    // ---------------------------------------------------------
    // 动作：猫猫旅行（成长域 buddy 接口）
    // ---------------------------------------------------------

    /**
     * 猫猫旅行状态机（对应参考实现的 travel.go / v1.6.19 口径）
     *
     * 完整流程（每次调用一个账号都按当前状态推进一格）：
     *  1. GET  /buddy/info        仅用于**探测成长域根路径**（不据其判定「有没有猫」）
     *  2. GET  /buddy/travel/status
     *          arrived   → POST /buddy/travel/claim {record_id} 领奖
     *          traveling → 跳过（等待到站）
     *          idle      → GET /buddy/travel/config 解析地点 id（固定「古镇客栈」）
     *                      → POST /buddy/travel/depart {location_id}
     *                        · 成功 → state=traveling
     *                        · HTTP 400 且含「no active buddy」→ 确实没猫：
     *                          走 adoptBuddy() 领养，成功后再**重试一次 depart**
     *
     * ⚠️ 判据口径（v1.6.19 修正，本适配器曾误判）：
     *   `/buddy/travel/status` 里的 `buddy_id` 是**当前旅行实例 id**，猫在家（idle）
     *   时**恒为 0**；`/buddy/info` 的 `buddy` 字段也可能为空对象。故**不能**据
     *   「buddy 为空 / buddy_id==0」判断「没有猫」—— 那会把正常在家的账号误判成
     *   需要领养，或直接挡住派出。**唯一权威判据是 depart 返回的 400 `no active buddy`**：
     *   先直接派，确实缺 Buddy 才去领养并重试一次。
     *
     * 返回统一结构：['ok'=>bool, 'message'=>string, 'data'=>['state'=>..., 'location'=>..., 'reward'=>...]]
     * 其中 state ∈ adopted|blocked|traveling|arrived|idle，供 AccountPool 记入 aigw_travel_log。
     *
     * 主机沿用 tasks()：hostChat（global → www.workbuddy.ai，否则 copilot.tencent.com）。
     * 基础路径存在两种逆向口径：现有 tasks() 用 /v2/activity/growth，
     * 而 travel.go 实测为 /activity/growth（不带 /v2）；这里两种都探测，取 /buddy/info 可用者。
     */
    public function travel(Account $account, string $location = '古镇客栈'): array
    {
        $headers = $this->meterHeaders($account);
        $host = $this->hostChat($account);

        // 依次探测两种基础路径，命中第一个 /buddy/info 返回 2xx 的作为成长域根
        $candidates = [$host . '/v2/activity/growth', $host . '/activity/growth'];
        $lastError = null;

        foreach ($candidates as $base) {
            try {
                $probe = $this->http()->withHeaders($headers)->timeout(30)->get($base . '/buddy/info');
            } catch (\Throwable $e) {
                $lastError = ['ok' => false, 'message' => $e->getMessage(), 'data' => ['state' => '']];
                continue;
            }

            if (!$probe->ok()) {
                continue;
            }

            try {
                return $this->runTravel($base, $headers, $location);
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => $e->getMessage(), 'data' => ['state' => '']];
            }
        }

        return $lastError ?? [
            'ok'      => false,
            'message' => '成长域不可达（/v2 与无前缀均失败）',
            'data'    => ['state' => ''],
        ];
    }

    /**
     * 领养第一只猫：同意协议（幂等）+ POST /buddy/first
     *
     * - HTTP 400 且响应含「first_buddy task not completed yet」→ 门槛未达，state=blocked，当日不重试
     * - 其他非 2xx → 领养失败
     * - 2xx → 领养成功，state=adopted（上游送 300 分）
     */
    private function adoptBuddy(string $base, array $headers): array
    {
        // 同意协议为幂等操作，失败不影响后续领养尝试
        try {
            $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($base . '/buddy/agreement', ['agree' => true]);
        } catch (\Throwable $e) {
        }

        try {
            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($base . '/buddy/first', new \stdClass());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'data' => ['state' => '']];
        }

        $body = $res->body();

        if ($res->status() === 400
            && stripos($body, 'first_buddy task not completed yet') !== false) {
            return [
                'ok'      => true,
                'message' => '尚未满足领养门槛（first_buddy 任务未完成），今日不再重试',
                'data'    => ['state' => 'blocked'],
            ];
        }

        if (!$res->ok()) {
            return [
                'ok'      => false,
                'message' => $this->extractMessage($body) ?: ('领养失败（HTTP ' . $res->status() . '）'),
                'data'    => ['state' => ''],
            ];
        }

        return [
            'ok'      => true,
            'message' => '已同意协议并领养第一只猫',
            'data'    => ['state' => 'adopted', 'reward' => 300],
        ];
    }

    /**
     * 旅行状态推进：status → claim / depart（还原 travel.go 的状态机）
     */
    private function runTravel(string $base, array $headers, string $location): array
    {
        $res = $this->http()->withHeaders($headers)->timeout(30)->get($base . '/buddy/travel/status');

        if (!$res->ok()) {
            return ['ok' => false, 'message' => '查询旅行状态失败（HTTP ' . $res->status() . '）', 'data' => ['state' => '']];
        }

        $node = $this->growthNode($res->body());

        if ($node === null) {
            return ['ok' => true, 'message' => '旅行状态为空', 'data' => ['state' => '']];
        }

        $state = $this->jsonString($node, ['state']);

        if ($state === 'arrived') {
            $recordId = $this->jsonString($node, ['record_id']);

            if ($recordId === '') {
                return ['ok' => false, 'message' => '猫猫已到站但缺少 record_id', 'data' => ['state' => 'arrived']];
            }

            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($base . '/buddy/travel/claim', ['record_id' => $recordId]);

            if (!$res->ok()) {
                return ['ok' => false, 'message' => '领取旅行奖励失败（HTTP ' . $res->status() . '）', 'data' => ['state' => 'arrived']];
            }

            $claimNode = $this->growthNode($res->body());
            $reward = $claimNode !== null ? $this->jsonLong($claimNode, ['reward_credit']) : 0;

            return [
                'ok'      => true,
                'message' => $reward > 0 ? '旅行礼物 +' . $reward : '已领旅行礼物',
                'data'    => ['state' => 'arrived', 'reward' => $reward],
            ];
        }

        if ($state === 'traveling') {
            return ['ok' => true, 'message' => '猫猫在途，等待到站', 'data' => ['state' => 'traveling']];
        }

        if ($state !== 'idle') {
            return ['ok' => true, 'message' => '暂无旅行状态（' . $state . '）', 'data' => ['state' => $state]];
        }

        if ($this->jsonBool($node, 'daily_limit_reached')) {
            return ['ok' => true, 'message' => '今日已派出过，等待到站领奖', 'data' => ['state' => 'idle']];
        }

        $locationId = $this->resolveLocationId($base, $headers, $location);

        if ($locationId <= 0) {
            return ['ok' => false, 'message' => '未能获取可用旅行地点', 'data' => ['state' => 'idle']];
        }

        $res = $this->http()->withHeaders($headers)->timeout(30)
            ->postJson($base . '/buddy/travel/depart', ['location_id' => (int) $locationId]);

        // ── 「没有猫」的唯一权威判据：depart 返回 400 `no active buddy` ──────────
        // （v1.6.19 口径：不能用 /buddy/info 的 buddy 空对象或 status 的 buddy_id==0
        //   判断，后者在猫在家时恒为 0，会把正常账号误挡。先直接派，被拒了才领养。）
        if ($res->status() === 400 && stripos($res->body(), 'no active buddy') !== false) {
            $adopted = $this->adoptBuddy($base, $headers);

            if (!($adopted['ok'] ?? false)) {
                return $adopted;
            }

            // 领养成功（state=adopted）或门槛未达（state=blocked）—— 门槛未达时不再重试
            if ((string) ($adopted['data']['state'] ?? '') !== 'adopted') {
                return $adopted;
            }

            // 领养成功后重试一次 depart
            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($base . '/buddy/travel/depart', ['location_id' => (int) $locationId]);
        }

        if (!$res->ok()) {
            $message = $this->extractMessage($res->body());

            return [
                'ok'      => false,
                'message' => $message !== '' ? $message : ('派出旅行失败（HTTP ' . $res->status() . '）'),
                'data'    => ['state' => 'idle'],
            ];
        }

        $departNode = $this->growthNode($res->body());
        $name = '';

        if ($departNode !== null && isset($departNode['location']) && is_array($departNode['location'])) {
            $name = $this->jsonString($departNode['location'], ['name']);
        }

        if ($name === '') {
            $name = $location;
        }

        return [
            'ok'      => true,
            'message' => '派猫猫去' . $name . '旅行',
            'data'    => ['state' => 'traveling', 'location' => $name],
        ];
    }

    /**
     * 解析旅行地点 id：GET /buddy/travel/config
     *
     * 优先返回与 $preferredName 同名的地点 id（配置项「古镇客栈」），
     * 找不到同名时回退首个有效地点 id；均无有效 id 时返回 0。
     */
    private function resolveLocationId(string $base, array $headers, string $preferredName): int
    {
        $res = $this->http()->withHeaders($headers)->timeout(30)->get($base . '/buddy/travel/config');

        if (!$res->ok()) {
            return 0;
        }

        $node = $this->growthNode($res->body());

        if ($node === null || empty($node['locations']) || !is_array($node['locations'])) {
            return 0;
        }

        $fallback = 0;

        foreach ($node['locations'] as $loc) {
            if (!is_array($loc)) {
                continue;
            }

            $id = (int) $this->jsonLong($loc, ['id']);

            if ($id <= 0) {
                continue;
            }

            if ($fallback === 0) {
                $fallback = $id;
            }

            if ($preferredName !== '' && $this->jsonString($loc, ['name']) === $preferredName) {
                return $id;
            }
        }

        return $fallback;
    }

    /**
     * 成长域响应解节点：兼容「裸数据」与「信封 {code,data}」两种返回，
     * 若含数值 code 且 data 为数组则取 data，否则原样返回。
     */
    private function growthNode(string $body): ?array
    {
        $node = $this->decodeJsonNode($body);

        if ($node === null) {
            return null;
        }

        if (array_key_exists('code', $node) && is_numeric($node['code'])
            && isset($node['data']) && is_array($node['data'])) {
            return $node['data'];
        }

        return $node;
    }

    /**
     * 解析 JSON 响应体为对象节点（还原 LV1/l->e），失败返回 null
     */
    private function decodeJsonNode(string $body): ?array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 读取字符串：还原 LV1/l->s，按候选键取首个「非空字符串」值，否则返回 ""
     *
     * ⚠️ 上游大量标识型字段用 JSON **数值** 承载（如 /buddy/travel/status 的
     * `record_id`、`/buddy/travel/config` 的 `locations[].id`），只认 `is_string`
     * 会让这些字段恒被判空 —— 表现为「猫猫已到站但缺少 record_id」而永远领不了奖、
     * 或「未能获取可用旅行地点」而永远派不出去（**静默失效，日志无异常**）。
     * 因此这里同时接受整数/浮点数并按原样转字符串。
     *
     * ⚠️ 数值 `0` 一律视为「无」并跳过（与 jsonLong 的 >0 语义对称）：
     * 上游在无记录时返回 `"record_id":0`、`"buddy_id":0`，把 0 当有效值会让
     * 调用方误判「已到站且有记录」并带着 record_id=0 去领奖。
     */
    private function jsonString(array $node, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $node)) {
                continue;
            }

            $value = $node[$key];

            if (is_string($value) && $value !== '') {
                return $value;
            }

            // JSON 数值型标识（整数/浮点）→ 转字符串；0 视为「无」，布尔/null 不在此列
            if ((is_int($value) || is_float($value)) && (int) $value !== 0) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * 读取长整型：还原 LV1/l->r，按候选键取首个「>0」的数值，否则返回 0
     */
    private function jsonLong(array $node, array $keys): int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $node)) {
                continue;
            }

            $value = $node[$key];

            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return 0;
    }

    /**
     * 读取布尔：还原 LD1/o->b，缺省/不可解析时返回 false
     */
    private function jsonBool(array $node, string $key): bool
    {
        if (!array_key_exists($key, $node)) {
            return false;
        }

        $value = $node[$key];

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes'], true);
        }

        return false;
    }

    /**
     * 解析账号域名指向：global → $globalHost，否则 $cnHost（还原 La2/b->D + 主机选择）
     */
    private function domainHost(Account $account, string $globalHost, string $cnHost): string
    {
        return $this->isGlobalDomain((string) $account->cred('domain', '')) ? $globalHost : $cnHost;
    }

    // ---------------------------------------------------------
    // Token 刷新（/v2/plugin/auth/token/refresh）
    // ---------------------------------------------------------

    /**
     * Token 刷新：GET {hostChat}/v2/plugin/auth/token/refresh（还原 La2/b->c）
     *
     * - 阈值：expiresAt>0 且剩余有效期大于 REFRESH_THRESHOLD_SECONDS 秒时跳过（返回 []）
     * - 必须携带 refreshToken，否则跳过
     * - 2xx 时取 data 节点：accessToken 必填；refreshToken/domain 缺省时回退旧值；
     *   expiresAt = now + expiresIn 秒
     */
    public function refreshToken(Account $account): array
    {
        $expiresAt = (int) $account->cred('expiresAt', 0);
        if ($expiresAt > 0 && ($expiresAt - now_ms()) > self::REFRESH_THRESHOLD_SECONDS * 1000) {
            return [];
        }

        $refreshToken = (string) $account->cred('refreshToken', '');
        if ($refreshToken === '') {
            return [];
        }

        // 还原 La2/b->c：GET（请求体为 null），主机由 q() 按域名解析
        $res = $this->http()->withHeaders($this->refreshHeaders($account))->timeout(30)
            ->get($this->hostChat($account) . '/v2/plugin/auth/token/refresh');

        if (!$res->ok()) {
            Logger::warn('WorkBuddy 刷新 Token 失败', ['uid' => $account->uid, 'status' => $res->status()]);
            return [];
        }

        $data = $res->json();
        $node = is_array($data) ? ($data['data'] ?? $data) : [];
        if (!is_array($node)) {
            return [];
        }

        $token = (string) ($node['accessToken'] ?? $node['access_token'] ?? '');
        if ($token === '') {
            return [];
        }

        return [
            'accessToken'  => $token,
            'refreshToken' => (string) ($node['refreshToken'] ?? $node['refresh_token'] ?? $refreshToken),
            'expiresAt'    => now_ms() + ((int) ($node['expiresIn'] ?? $node['expires_in'] ?? 3600)) * 1000,
            'domain'       => (string) ($node['domain'] ?? $account->cred('domain', '')),
        ];
    }

    // ---------------------------------------------------------
    // 凭据导入（粘贴桌面客户端登录 JSON）
    // ---------------------------------------------------------

    /**
     * 导入登录凭据：粘贴 WorkBuddy / CodeBuddy 桌面客户端的登录态 JSON。
     *
     * 兼容两种形状：
     *   ① 客户端原始结构（auth.json）：{"account":{uid,nickname,enterpriseId},
     *      "auth":{accessToken,refreshToken,expiresAt,domain,realm}}
     *   ② 扁平结构：{accessToken, refreshToken, domain, expiresAt, uid, nickname, enterpriseId}
     *
     * 入库前会用该 token 打一次 models 接口校验登录态：401/403 直接拒绝（避免过期
     * 凭据静默入池后一直失败），网络不通也明确报错。
     *
     * @return array{ok:bool,message:string,account_uid:string,nickname:string,account_id:int}
     */
    public function ingestCredential(array $credential): array
    {
        $account = is_array($credential['account'] ?? null) ? $credential['account'] : [];
        $auth = is_array($credential['auth'] ?? null) ? $credential['auth'] : [];

        $pick = static function (array $sources, array $keys): string {
            foreach ($sources as $source) {
                foreach ($keys as $key) {
                    if (isset($source[$key]) && is_scalar($source[$key]) && (string) $source[$key] !== '') {
                        return (string) $source[$key];
                    }
                }
            }

            return '';
        };

        $sources = [$auth, $credential, $account];

        $accessToken = $pick($sources, ['accessToken', 'access_token', 'token']);
        if ($accessToken === '') {
            return [
                'ok'          => false,
                'message'     => '登录 JSON 里没有 accessToken，请从桌面客户端登录态文件整段复制',
                'account_uid' => '', 'nickname' => '', 'account_id' => 0,
            ];
        }

        $refreshToken = $pick($sources, ['refreshToken', 'refresh_token']);
        $domain = strtolower($pick($sources, ['domain']));
        $uid = $pick($sources, ['uid', 'userId', 'user_id']);
        $nickname = $pick($sources, ['nickname', 'nickName', 'name', 'userName']);
        $enterpriseId = $pick($sources, ['enterpriseId', 'enterprise_id']);
        $expiresAt = (int) $pick($sources, ['expiresAt', 'expires_at']);
        $realm = strtolower($pick($sources, ['realm']));

        // 域名为空时按 realm 归位：cn 视为国服（走默认 CN 主机），其它视为国际版
        if ($domain === '' && $realm !== '' && $realm !== 'cn') {
            $domain = self::DEFAULT_INTL_DOMAIN;
        }

        // 校验登录态：上游给 401/403 说明 token 已过期，不应静默入库
        $host = $this->hostForDomain($domain);
        $probe = $this->probeProfile($accessToken, $host);
        $status = (int) ($probe['status'] ?? 0);

        if ($status === 0) {
            return [
                'ok'          => false,
                'message'     => '无法连接 ' . $host . ' 校验登录态，请稍后重试',
                'account_uid' => '', 'nickname' => '', 'account_id' => 0,
            ];
        }
        if ($status === 401 || $status === 403) {
            return [
                'ok'          => false,
                'message'     => '登录态已失效（HTTP ' . $status . '），请重新从桌面客户端复制最新的登录 JSON',
                'account_uid' => '', 'nickname' => '', 'account_id' => 0,
            ];
        }

        $result = $this->persistToken($accessToken, [
            'refreshToken' => $refreshToken,
            'domain'       => $domain !== '' ? $domain : (string) ($probe['domain'] ?? ''),
            'uid'          => $uid !== '' ? $uid : (string) ($probe['uid'] ?? ''),
            'name'         => $nickname !== '' ? $nickname : (string) ($probe['nickname'] ?? ''),
            'enterpriseId' => $enterpriseId !== '' ? $enterpriseId : (string) ($probe['enterpriseId'] ?? ''),
            'avatar'       => (string) ($probe['avatar'] ?? ''),
            'expiresAt'    => $expiresAt,
        ]);

        $result['message'] = $this->displayName() . ' 登录凭据已导入';

        return $result;
    }

    // ---------------------------------------------------------
    // 登录：设备授权
    // ---------------------------------------------------------

    public function startLogin(array $params = []): LoginSession
    {
        $sessionId = uuid();

        // 1) 申请授权 state（还原 La2/b->d 的设备授权分支：POST，body 固定 "{}"，无自定义头）
        $res = $this->http()->withHeaders($this->loginHeaders())->timeout(20)
            ->postJson(
                $this->hostLogin() . '/v2/plugin/auth/state?platform=' . self::PLATFORM,
                new \stdClass()
            );

        if (!$res->ok()) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'failed',
                message: '申请授权 state 失败：' . $this->extractMessage($res->body()),
                error: 'state_request_failed'
            );
        }

        $data = $res->json();
        $node = $data['data'] ?? $data;
        $state = (string) ($node['state'] ?? $node['authState'] ?? '');

        if ($state === '') {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'failed',
                message: '上游未返回 state',
                error: 'state_missing'
            );
        }

        $authUrl = (string) ($node['authUrl'] ?? $node['auth_url'] ?? '');
        if ($authUrl === '') {
            $authUrl = $this->hostLogin() . '/login?platform=' . self::PLATFORM . '&state=' . urlencode($state);
        }

        $this->saveLoginSession($sessionId, [
            'state'      => $state,
            'auth_url'   => $authUrl,
            'created_at' => now_ms(),
        ]);

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'waiting',
            message: '请在浏览器中打开授权链接并完成登录，本页会自动检测',
            authUrl: $authUrl,
            userCode: $state,
            intervalSeconds: 3,
            expiresInSeconds: 900,
            extra: ['poll_url' => (string) config('gateway.base_url')]
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

        // 允许前端把平台侧回调带来的 token 直接回填
        $accessToken = trim((string) ($params['access_token'] ?? ''));
        if ($accessToken !== '') {
            $result = $this->persistToken($accessToken, [
                'refreshToken' => (string) ($params['refresh_token'] ?? ''),
                'uid'          => (string) ($params['uid'] ?? ''),
            ]);
            $this->updateLoginSession($sessionId, array_merge(['state' => $result['ok'] ? 'success' : 'failed'], $result));
        } else {
            // 设备码流程：用户可能已在浏览器完成授权，主动向上游用同一 state 换取令牌
            $this->pullUpstreamToken($sessionId, (string) ($session['state'] ?? ''));
        }

        $session = $this->loadLoginSession($sessionId) ?? $session;
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
            authUrl: (string) ($session['auth_url'] ?? ''),
            userCode: (string) ($session['state'] ?? '')
        );
    }

    /**
     * 用 state 向上游换取令牌（设备码流程的第二步，还原 LN1/B->f）
     *
     * GET https://copilot.tencent.com/v2/plugin/auth/token?state=<urlencode(state)>，无自定义头；
     * 2xx 时取 data 节点（要求 code==0）：
     *   - code==11217 → 授权尚未完成，继续等待（不写会话状态）
     *   - code!=0     → 记录异常日志
     *   - code==0     → 按 La2/b->t 映射凭据并落库
     */
    private function pullUpstreamToken(string $sessionId, string $state): void
    {
        if ($state === '') {
            return;
        }

        try {
            $res = $this->http()->withHeaders($this->loginHeaders())->timeout(20)
                ->get($this->hostLogin() . '/v2/plugin/auth/token?state=' . urlencode($state));
        } catch (\Throwable $e) {
            Logger::warn('WorkBuddy 轮询令牌异常', ['error' => $e->getMessage()]);
            return;
        }

        if (!$res->ok()) {
            return;
        }

        $data = $res->json();
        if (!is_array($data)) {
            return;
        }

        // 还原 LN1/B->f：上游用 code 表达状态
        $code = (int) ($data['code'] ?? 0);
        if ($code !== 0) {
            if ($code !== self::CODE_LOGIN_PENDING) {
                Logger::warn('WorkBuddy 轮询令牌返回异常状态', ['code' => $code, 'msg' => (string) ($data['msg'] ?? '')]);
            }
            return;
        }

        $node = $data['data'] ?? null;
        if (!is_array($node)) {
            return;
        }

        $creds = $this->mapCredentials($node);
        $token = (string) ($creds['accessToken'] ?? '');
        if ($token === '') {
            return;
        }

        $result = $this->persistToken($token, $creds);

        $this->updateLoginSession($sessionId, array_merge(
            ['state' => $result['ok'] ? 'success' : 'failed'],
            $result
        ));
    }

    /**
     * 凭据字段映射（还原 La2/b->t）
     *
     * @return array{accessToken:string,refreshToken:string,expiresAt:string,expiresIn:string,domain:string,uid:string,enterpriseId:string,name:string}
     */
    private function mapCredentials(array $node): array
    {
        $pick = static function (array $source, array $keys): string {
            foreach ($keys as $key) {
                if (isset($source[$key]) && is_scalar($source[$key]) && (string) $source[$key] !== '') {
                    return (string) $source[$key];
                }
            }
            return '';
        };

        $accessToken = $pick($node, ['accessToken', 'access_token']);

        // 还原 La2/b->t：uid / enterpriseId 缺失时回退解析 accessToken(JWT) 声明
        $uid = $pick($node, ['uid', 'userId', 'user_id']);
        if ($uid === '' && $accessToken !== '') {
            $uid = $this->decodeJwtClaim($accessToken, ['user_id', 'userId', 'uid', 'sub']);
        }

        $enterpriseId = $pick($node, ['enterpriseId', 'enterprise_id', 'entId', 'tenantId', 'tenant_id']);
        if ($enterpriseId === '' && $accessToken !== '') {
            $enterpriseId = $this->decodeJwtClaim($accessToken, ['tenant_id', 'tenantId', 'enterprise_id', 'enterpriseId']);
        }

        return [
            'accessToken'  => $accessToken,
            'refreshToken' => $pick($node, ['refreshToken', 'refresh_token']),
            'expiresAt'    => $pick($node, ['expiresAt', 'expires_at']),
            'expiresIn'    => $pick($node, ['expiresIn', 'expires_in']),
            'domain'       => $pick($node, ['domain']),
            'uid'          => $uid,
            'enterpriseId' => $enterpriseId,
            'name'         => $pick($node, ['nickname', 'nickName', 'name']),
        ];
    }

    /**
     * 从 accessToken(JWT) 的 payload 中提取声明（还原 LV1/l->x）
     */
    private function decodeJwtClaim(string $token, array $keys): string
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return '';
        }

        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);

        $json = base64_decode($payload);
        if ($json === false) {
            return '';
        }

        $claims = json_decode($json, true);
        if (!is_array($claims)) {
            return '';
        }

        foreach ($keys as $key) {
            if (isset($claims[$key]) && is_scalar($claims[$key]) && (string) $claims[$key] !== '') {
                return (string) $claims[$key];
            }
        }

        return '';
    }

    /**
     * 第三方回调（state + token）落库
     */
    public function handleCallback(array $query): array
    {
        $state = trim((string) ($query['state'] ?? ''));
        $accessToken = trim((string) ($query['access_token'] ?? $query['token'] ?? ''));

        if ($accessToken === '') {
            return ['ok' => false, 'message' => '回调缺少 access_token'];
        }

        $result = $this->persistToken($accessToken, [
            'refreshToken' => (string) ($query['refresh_token'] ?? ''),
            'uid'          => (string) ($query['uid'] ?? ''),
        ]);

        if ($state !== '') {
            $this->updateLoginSession($state, array_merge([
                'state' => $result['ok'] ? 'success' : 'failed',
            ], $result));
        }

        return $result;
    }

    private function persistToken(string $accessToken, array $extra): array
    {
        $uid = trim((string) ($extra['uid'] ?? ''));
        $nickname = trim((string) ($extra['name'] ?? ''));
        $domain = trim((string) ($extra['domain'] ?? ''));
        $enterpriseId = trim((string) ($extra['enterpriseId'] ?? ''));
        $avatar = trim((string) ($extra['avatar'] ?? ''));
        $refreshToken = (string) ($extra['refreshToken'] ?? '');
        $expiresAt = (int) ($extra['expiresAt'] ?? 0);
        $expiresIn = (int) ($extra['expiresIn'] ?? 0);

        // 单位归一到毫秒：上游 expiresAt 为「秒」（还原 La2/b->t 以 long 秒读取），
        // 网站内部统一使用毫秒（now_ms），避免刷新阈值比较量纲错乱。
        if ($expiresAt > 0) {
            if ($expiresAt < 100000000000) {
                $expiresAt *= 1000;
            }
        } else {
            $expiresAt = now_ms() + ($expiresIn > 0 ? $expiresIn : 3600) * 1000;
        }

        // 凭据缺失时探测用户信息补全（models 接口：q(domain) + 业务头）
        if ($uid === '' || $nickname === '' || $domain === '') {
            $primaryHost = $this->hostForDomain($domain);
            $profile = $this->probeProfile($accessToken, $primaryHost);

            // 海外账号若令牌响应未带 domain，主探测会落到 CN 主机而取不到域名，
            // 这里再对另一套主机探测一次，避免海外账号被误判成国内。
            if ((string) ($profile['domain'] ?? '') === '') {
                $altHost = $primaryHost === self::HOST_GLOBAL_API ? self::HOST_CN : self::HOST_GLOBAL_API;
                $alt = $this->probeProfile($accessToken, $altHost);
                foreach ($alt as $k => $v) {
                    if ((string) $v !== '' && (string) ($profile[$k] ?? '') === '') {
                        $profile[$k] = $v;
                    }
                }
            }

            $uid = $uid ?: (string) ($profile['uid'] ?? '');
            $nickname = $nickname ?: (string) ($profile['nickname'] ?? '');
            $enterpriseId = $enterpriseId ?: (string) ($profile['enterpriseId'] ?? '');
            $domain = $domain ?: (string) ($profile['domain'] ?? '');
            $avatar = $avatar !== '' ? $avatar : (string) ($profile['avatar'] ?? '');
        }

        // 国际版：上游仍未返回域名时，默认视为海外账号（否则后续请求会落到 CN 主机）
        if ($domain === '' && $this->isIntlProvider()) {
            $domain = self::DEFAULT_INTL_DOMAIN;
        }

        if ($uid === '') {
            $uid = 'wb_' . substr(hash('sha256', $accessToken), 0, 16);
        }

        $accountId = $this->accountRepository->upsertCredential(
            $this->id(),
            $uid,
            [
                'accessToken'  => $accessToken,
                'refreshToken' => $refreshToken,
                'expiresAt'    => $expiresAt,
                'uid'          => $uid,
                'domain'       => $domain,
                'enterpriseId' => $enterpriseId,
            ],
            $avatar !== '' ? ['logged_in_at' => now_ms(), 'avatar' => $avatar] : ['logged_in_at' => now_ms()],
            $nickname !== '' ? $nickname : ('WorkBuddy-' . substr($uid, 0, 8))
        );

        return [
            'ok'          => true,
            'message'     => 'WorkBuddy 授权成功',
            'account_uid' => $uid,
            'nickname'    => $nickname,
            'account_id'  => $accountId,
        ];
    }

    /**
     * 探测用户信息补全凭据（models 接口：q(domain) + 业务头）
     *
     * 单一主机探测，返回 uid / nickname / enterpriseId / domain / avatar / status，
     * 供 persistToken 在 CN / global 两套主机间择优补全。
     * status 为上游 HTTP 状态码（0 表示网络异常），供凭据导入判断登录态是否有效。
     *
     * @return array{uid:string,nickname:string,enterpriseId:string,domain:string,avatar:string,status:int}
     */
    private function probeProfile(string $accessToken, string $host): array
    {
        $empty = ['uid' => '', 'nickname' => '', 'enterpriseId' => '', 'domain' => '', 'avatar' => '', 'status' => 0];

        try {
            $probe = $this->http()->withHeaders($this->loginHeaders() + [
                'Authorization' => 'Bearer ' . $accessToken,
            ])->timeout(20)->get($host . '/console/enterprises/personal/models');
        } catch (\Throwable $e) {
            return $empty;
        }

        if (!$probe->ok()) {
            return ['status' => $probe->status()] + $empty;
        }

        $data = $probe->json();
        $node = is_array($data) ? ($data['data'] ?? $data) : [];
        if (!is_array($node)) {
            return ['status' => $probe->status()] + $empty;
        }

        return [
            'uid'          => (string) ($node['userId'] ?? $node['uid'] ?? ''),
            'nickname'     => (string) ($node['userName'] ?? $node['nickname'] ?? $node['nickName'] ?? ''),
            'enterpriseId' => (string) ($node['enterpriseId'] ?? ''),
            'domain'       => (string) ($node['domain'] ?? ''),
            'avatar'       => (string) ($node['avatar'] ?? $node['avatarUrl'] ?? $node['icon'] ?? $node['headUrl'] ?? ''),
            'status'       => $probe->status(),
        ];
    }

    // ---------------------------------------------------------
    // 成长中心：可视化快照 + 单项执行（供管理台「成长中心」页面）
    // ---------------------------------------------------------

    /**
     * 成长中心只读快照：连登 / 补签卡 / 抽奖次数 / 猫档案 / 旅行状态 /
     * 任务清单 / 盲盒可开次数，一次调用全部读完。
     *
     * 设计要点：
     *   - **只读**：不 accept / 不 claim / 不 depart，只供页面渲染「能点什么」；
     *   - 子请求失败一律静默降级为空值（页面显示部分数据好过整页报错）；
     *   - 🔴 7 个端点**并行**拉取（curl_multi）：串行时单账号要 7 次往返（3~5 秒），
     *     账号一多页面就「一直在转圈」；并行后单账号耗时 ≈ 最慢那一个请求，
     *     与端点个数无关（实测 4.0s → 约 1s）；
     *   - 结果落**短 TTL 文件缓存**（见 GROWTH_CACHE_TTL），刷新页面/多人同看不再重复打上游。
     *
     * @param bool $force 忽略缓存，强制重新拉取（页面上「刷新」按钮用）
     * @return array{ok:bool,message:string,data:array<string,mixed>}
     */
    public function growthSnapshot(Account $account, bool $force = false): array
    {
        $cacheKey = 'growth-' . $account->id . '-' . substr(md5($account->uid), 0, 8);

        if (!$force) {
            $cached = $this->growthCacheGet($cacheKey);
            if ($cached !== null) {
                $cached['data']['cached'] = true;

                return $cached;
            }
        }

        $headers = $this->meterHeaders($account);
        $host    = $this->hostChat($account);

        // 一次并行读全部端点；路径口径以带 /v2 的为准（实测两端点都可用）
        $paths = [
            'info'    => '/buddy/info',
            'streak'  => '/streak',
            'lottery' => '/lottery/chances',
            'travel'  => '/buddy/travel/status',
            'config'  => '/buddy/travel/config',
            'tasks'   => '/tasks',
            'quota'   => '/buddy/quota',
        ];

        $base = $host . '/v2/activity/growth';
        $raw  = $this->growthGetMany($base, $headers, $paths);

        // /buddy/info 拿不到 → 换无前缀 base 再并行试一轮（少数部署只认无前缀口径）
        if (($raw['info'] ?? null) === null) {
            $altBase = $host . '/activity/growth';
            $alt     = $this->growthGetMany($altBase, $headers, $paths);

            if (($alt['info'] ?? null) !== null) {
                $raw  = $alt;
                $base = $altBase;
            }
        }

        if (($raw['info'] ?? null) === null) {
            return ['ok' => false, 'message' => '成长域不可达（/v2 与无前缀均失败）', 'data' => []];
        }

        $out = [
            'base'          => $base,
            'streak'        => null,
            'makeup_cards'  => null,
            'redemption'    => null,
            'lottery'       => null,
            'buddy'         => null,
            'travel'        => null,
            'travel_config' => [],
            'tasks'         => [],
            'box'           => null,
        ];

        // 连登 / 补签卡 / 各档兑换状态（同一响应体）
        $streakBody = $raw['streak'];
        if ($streakBody !== null) {
            $out['streak']       = $streakBody['streak'] ?? null;
            $out['makeup_cards'] = $streakBody['makeup_cards'] ?? null;
            $out['redemption']   = $streakBody['redemption_status'] ?? null;
        }

        if ($raw['lottery'] !== null) {
            $out['lottery'] = $raw['lottery'];
        }

        $info = $raw['info'];
        $buddy = $info['buddy'] ?? null;
        $out['buddy'] = (is_array($buddy) && $buddy !== []) ? $buddy : null;

        if ($raw['travel'] !== null) {
            $out['travel'] = $raw['travel'];
        }

        $config = $raw['config'];
        if ($config !== null && isset($config['locations']) && is_array($config['locations'])) {
            $out['travel_config'] = $config['locations'];
        }

        // 任务清单：归一化为前端友好的扁平结构
        $taskNode = $raw['tasks'];
        if ($taskNode !== null && isset($taskNode['tasks']) && is_array($taskNode['tasks'])) {
            foreach ($taskNode['tasks'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $code = $this->jsonString($item, ['task_code']);
                if ($code === '') {
                    continue;
                }

                $progress = $item['progress'] ?? null;

                $out['tasks'][] = [
                    'task_code'     => $code,
                    'title'         => $this->jsonString($item, ['title']),
                    'description'   => $this->jsonString($item, ['description']),
                    'task_desc'     => $this->jsonString($item, ['task_desc']),
                    'accept_status' => $this->jsonString($item, ['accept_status']),
                    'locked'        => $this->jsonBool($item, 'locked'),
                    'reward_credit' => $this->jsonLong($item, ['reward_credit']),
                    'reward_energy' => $this->jsonLong($item, ['reward_energy']),
                    'tag'           => $this->jsonString($item, ['tag']),
                    'jump_url'      => $this->jsonString($item, ['jump_url']),
                    'icon_url'      => $this->jsonString($item, ['icon_url']),
                    'progress'      => is_array($progress) ? [
                        'current' => (int) ($progress['current'] ?? 0),
                        'target'  => (int) ($progress['target'] ?? 0),
                    ] : null,
                ];
            }
        }

        if ($raw['quota'] !== null) {
            $out['box'] = $raw['quota'];
        }

        $result = ['ok' => true, 'message' => '成长中心快照已读取', 'data' => $out];

        $this->growthCachePut($cacheKey, $result);

        return $result;
    }

    /**
     * 成长域 GET 并解信封：成功返回 data 节点，失败/异常返回 null（只读场景静默降级）。
     *
     * @return array<string,mixed>|null
     */
    private function growthGet(string $base, array $headers, string $path): ?array
    {
        try {
            $res = $this->http()->withHeaders($headers)->timeout(20)->get($base . $path);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$res->ok()) {
            return null;
        }

        return $this->growthNode($res->body());
    }

    /**
     * 快照缓存 TTL（秒）。
     *
     * 取 45s 的理由：成长中心的各项数据（连登/任务/盲盒）本来就是分钟级变化的，
     * 45 秒的陈旧完全可以接受；而它足以挡住「刷新页面、切页再回来、多人同看」
     * 这三类重复打上游的行为 —— 之前每切一次页就是 7×N 个上游请求。
     */
    private const GROWTH_CACHE_TTL = 45;

    /**
     * **并行**拉取成长域多个只读端点（curl_multi）。
     *
     * 为什么必须并行：单账号要读 7 个端点，串行 = 7 次完整往返（实测约 4 秒）；
     * 账号一多就是 7×N 次串行，全部压在一个 HTTP 请求里 → 页面一直转圈。
     * 并行后单账号耗时 ≈ 最慢的那一个请求，与端点个数无关。
     *
     * 选项刻意与 `HttpClient::send()` 对齐（超时/SSL/不跟随重定向/代理），
     * 代理从当前 HttpClient 实例读取，保证不绕过既有的代理与排除名单。
     *
     * @param array<string,string> $paths key => 路径
     * @return array<string,array<string,mixed>|null> key => 解信封后的 data（失败为 null）
     */
    private function growthGetMany(string $base, array $headers, array $paths): array
    {
        $out = array_fill_keys(array_keys($paths), null);

        // 极端环境没有 curl_multi：退回串行，功能不丢（只是慢）
        if (!function_exists('curl_multi_init')) {
            foreach ($paths as $key => $path) {
                $out[$key] = $this->growthGet($base, $headers, $path);
            }

            return $out;
        }

        $proxy = $this->http()->proxy();

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($paths as $key => $path) {
            $ch = curl_init();

            $opts = [
                CURLOPT_URL            => $base . $path,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPGET        => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER     => $headerLines,
            ];

            if ($proxy !== null && $proxy !== '') {
                $opts[CURLOPT_PROXY] = $proxy;
            }

            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $running = null;

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                // 让出 CPU，等有 socket 可读再继续（1s 上限，避免空转）
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($code >= 200 && $code < 300 && is_string($body) && $body !== '') {
                $out[$key] = $this->growthNode($body);
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        return $out;
    }

    /** 读快照缓存（未命中/过期返回 null） */
    private function growthCacheGet(string $key): ?array
    {
        $file = $this->growthCacheFile($key);

        if (!is_file($file)) {
            return null;
        }

        $mtime = @filemtime($file);

        if ($mtime === false || (time() - $mtime) > self::GROWTH_CACHE_TTL) {
            return null;
        }

        $raw = @file_get_contents($file);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** 写快照缓存（失败静默：缓存不可写不该影响主流程） */
    private function growthCachePut(string $key, array $value): void
    {
        @file_put_contents(
            $this->growthCacheFile($key),
            json_encode($value, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        $this->growthCachePrune();
    }

    /**
     * 顺手清理过期缓存文件。
     *
     * 缓存键含账号 UID，重新登录换号会留下旧键的文件；不清理就会随
     * 「账号数 × 重新登录次数」无限堆积。只在目录文件数超过阈值时才扫一次，
     * 平时几乎零成本。
     */
    private function growthCachePrune(): void
    {
        $dir = storage_path('tmp/growth');

        if (!is_dir($dir)) {
            return;
        }

        $files = @scandir($dir);

        if (!is_array($files) || count($files) <= 64) {
            return;
        }

        $cutoff = time() - self::GROWTH_CACHE_TTL * 20;

        foreach ($files as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;
            $mtime = @filemtime($path);

            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($path);
            }
        }
    }

    /** 快照缓存文件路径（storage/tmp/growth/，随 storage/tmp 一起被清理） */
    private function growthCacheFile(string $key): string
    {
        $dir = storage_path('tmp/growth');

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '', $key) . '.json';
    }

    /**
     * 成长中心单项执行：把「一个具体动作」跑一次并返回可读结果。
     *
     * 与 `growthSnapshot()` 配套 —— 页面先快照列出「能点什么」，
     * 用户点某一项时只跑这一项，不再像旧的 tasks-all 那样整池盲批量。
     *
     * 支持动作（与页面按钮一一对应）：
     *   - `accept_task`        接取某个成长任务（task_code）
     *   - `claim_task`         领取某个成长任务奖励（task_code）
     *   - `travel`             推进猫猫旅行一格（无猫则领养；有猫则按状态派出/领奖）
     *   - `open_box`           开一次盲盒
     *   - `redeem`             兑换连登奖励（tier：7d/14d/28d）
     *   - `lottery_draw`       抽一次奖
     *   - `use_makeup`         对指定日期使用补签卡（target_date：YYYY-MM-DD）
     *   - `claim_gift`         领新手礼包
     *   - `claim_compensation` 领活动补偿
     *
     * @param array<string,mixed> $params
     * @return array{ok:bool,message:string,data:mixed}
     */
    public function growthAction(Account $account, string $action, array $params = []): array
    {
        $headers = $this->meterHeaders($account);
        $host    = $this->hostChat($account);

        $base = null;
        foreach ([$host . '/v2/activity/growth', $host . '/activity/growth'] as $candidate) {
            try {
                $probe = $this->http()->withHeaders($headers)->timeout(20)->get($candidate . '/buddy/info');
            } catch (\Throwable $e) {
                continue;
            }

            if ($probe->ok()) {
                $base = $candidate;
                break;
            }
        }

        if ($base === null) {
            return ['ok' => false, 'message' => '成长域不可达，请检查该账号登录态', 'data' => null];
        }

        switch ($action) {
            case 'accept_task':
                return $this->growthAcceptTask($base, $headers, (string) ($params['task_code'] ?? ''));

            case 'claim_task':
                return $this->growthClaimTask($base, $headers, (string) ($params['task_code'] ?? ''));

            case 'drive_task':
                return $this->growthDriveTask($account, $base, $headers, (string) ($params['task_code'] ?? ''));

            case 'travel':
                $location = trim((string) ($params['location'] ?? ''));
                return $this->travel($account, $location !== '' ? $location : '古镇客栈');

            case 'open_box':
                return $this->growthOpenBox($base, $headers);

            case 'redeem':
                return $this->growthRedeem($base, $headers, (string) ($params['tier'] ?? ''));

            case 'lottery_draw':
                return $this->growthLotteryDraw($base, $headers);

            case 'use_makeup':
                return $this->growthUseMakeup($base, $headers, (string) ($params['target_date'] ?? ''));

            case 'claim_gift':
                return $this->growthClaimBilling($account, 'claim-gift');

            case 'claim_compensation':
                return $this->growthClaimBilling($account, 'claim-compensation');
        }

        return ['ok' => false, 'message' => '未知动作：' . $action, 'data' => null];
    }

    /** 接取单个成长任务（POST /tasks/accept，body {task_codes:[code]}） */
    private function growthAcceptTask(string $base, array $headers, string $code): array
    {
        if ($code === '') {
            return ['ok' => false, 'message' => '缺少 task_code', 'data' => null];
        }

        try {
            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($base . '/tasks/accept', ['task_codes' => [$code]]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
        }

        $envelope = $this->decodeJsonNode($res->body());

        if ($envelope !== null && isset($envelope['code']) && is_numeric($envelope['code']) && (int) $envelope['code'] !== 0) {
            return ['ok' => false, 'message' => (string) ($envelope['msg'] ?? '接取被拒绝'), 'data' => null];
        }

        if (!$res->ok()) {
            return ['ok' => false, 'message' => $this->extractMessage($res->body()) ?: ('接取失败（HTTP ' . $res->status() . '）'), 'data' => null];
        }

        $node = $this->growthNode($res->body());
        $status = '';

        foreach ((array) ($node['results'] ?? []) as $r) {
            if (is_array($r) && (string) ($r['task_code'] ?? '') === $code) {
                $status = (string) ($r['status'] ?? '');
                break;
            }
        }

        return [
            'ok'      => true,
            'message' => $status === 'already_accepted' ? '该任务此前已接取' : '已接取任务',
            'data'    => ['task_code' => $code, 'status' => $status],
        ];
    }

    /** 领取单个成长任务奖励（POST /tasks/{code}/claim，无 body） */
    private function growthClaimTask(string $base, array $headers, string $code): array
    {
        if ($code === '') {
            return ['ok' => false, 'message' => '缺少 task_code', 'data' => null];
        }

        try {
            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($base . '/tasks/' . rawurlencode($code) . '/claim', new \stdClass());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
        }

        $envelope = $this->decodeJsonNode($res->body());
        $msg = $this->extractMessage($res->body());

        if ($envelope !== null && isset($envelope['code']) && is_numeric($envelope['code']) && (int) $envelope['code'] !== 0) {
            return ['ok' => false, 'message' => $msg !== '' ? $msg : '领取被拒绝（可能尚未完成或已领取）', 'data' => null];
        }

        if (!$res->ok()) {
            return ['ok' => false, 'message' => $msg !== '' ? $msg : ('领取失败（HTTP ' . $res->status() . '）'), 'data' => null];
        }

        $node = $this->growthNode($res->body());

        if ($node !== null && $this->jsonBool($node, 'already_claimed')) {
            return ['ok' => true, 'message' => '该任务奖励此前已领取', 'data' => $node];
        }

        $credit = $node !== null ? $this->jsonLong($node, ['credit']) : 0;
        $energy = $node !== null ? $this->jsonLong($node, ['energy']) : 0;

        $parts = [];
        if ($credit > 0) {
            $parts[] = '+' . $credit . ' 积分';
        }
        if ($energy > 0) {
            $parts[] = '+' . $energy . ' 能量';
        }

        return [
            'ok'      => true,
            'message' => $parts === [] ? '已领取任务奖励' : ('已领取任务奖励（' . implode('，', $parts) . '）'),
            'data'    => $node,
        ];
    }

    /**
     * 成长中心「代做」：网关替用户把客户端该做的事做掉，随后立刻领奖。
     *
     * 能代做的只有两类（2026-10-02 客户端逆向 + 受控实测确认）：
     *   1. `automation_1`「设置自动化任务」—— 写接口在**另一个域**
     *      （`POST /v2/as/scheduler/tasks`，见 createAutomationTask）。创建后任务
     *      立即变 `completed`，可马上 claim（+100 积分 + 5 能量）。**秒级完成**。
     *   2. 对话计数型（`chat_5`、「体验某模型」）—— 向 `/v2/chat/completions`
     *      发**真实对话**即计数。🔴 上游结算慢：实测 **90~150 秒**才把
     *      `accept_status` 推到 `completed`，所以这里发完对话要**等结算再领奖**。
     *      这也是本动作必须走异步 worker 的原因（绝不能挂在 HTTP 请求里）。
     *
     * 其余任务（`create_canvas` / `Library_read` / `Expert_lighthouse` / `Buddy_App` /
     * `skill_1` / `expert_5` / `template_5` / `Hp_Appearance` / `playbook_prompt` /
     * `wb_wechat_oa_subscribe_task` 等）计数判据全在服务端、客户端 app.asar 内搜不到
     * task_code，各候选写接口实测全 404 —— **做不了**，如实回报而不是假装成功。
     *
     * @return array{ok:bool,message:string,data:mixed}
     */
    private function growthDriveTask(Account $account, string $base, array $headers, string $code): array
    {
        if ($code === '') {
            return ['ok' => false, 'message' => '缺少 task_code', 'data' => null];
        }

        // 读当前任务态：已完成直接领、已领取直接回报、未接取先接
        $node = $this->taskNode($base, $headers);

        if ($node === null) {
            return ['ok' => false, 'message' => '读取任务列表失败，无法代做', 'data' => null];
        }

        $item = null;

        foreach ($node['tasks'] as $t) {
            if (is_array($t) && (string) ($t['task_code'] ?? '') === $code) {
                $item = $t;
                break;
            }
        }

        if ($item === null) {
            return ['ok' => false, 'message' => '任务不存在或未下发：' . $code, 'data' => null];
        }

        if ($this->jsonBool($item, 'locked')) {
            return ['ok' => false, 'message' => '该任务未开放', 'data' => null];
        }

        $status = (string) ($item['accept_status'] ?? '');

        // 已领过：不必再动
        if ($status === 'claimed') {
            return ['ok' => true, 'message' => '该任务奖励此前已领取', 'data' => ['task_code' => $code, 'status' => $status]];
        }

        // 已完成未领：代做的全部工作就是点一下领取
        if ($status === 'completed') {
            return $this->growthClaimTask($base, $headers, $code);
        }

        // 未接取：先接取（官方前端点「去完成」的第一步）
        if ($status === 'not_accepted') {
            $accepted = $this->growthAcceptTask($base, $headers, $code);

            if (!($accepted['ok'] ?? false)) {
                return $accepted;
            }
        }

        // ── 分类代做 ────────────────────────────────────────────────

        // 1) automation_1：建一条自动化任务（创建即计为完成），随即删除，然后领奖
        if ($code === 'automation_1') {
            if (!$this->createAutomationTask($account)) {
                return ['ok' => false, 'message' => '代做失败：创建自动化任务未成功', 'data' => null];
            }

            $claim = $this->growthClaimTask($base, $headers, $code);

            if (!($claim['ok'] ?? false)) {
                return [
                    'ok'      => false,
                    'message' => '已代做（创建了自动化任务），但领取奖励未成功：' . (string) ($claim['message'] ?? ''),
                    'data'    => null,
                ];
            }

            return [
                'ok'      => true,
                'message' => '已代做并领取：' . (string) $claim['message'],
                'data'    => $claim['data'] ?? null,
            ];
        }

        // 2) 对话计数型：发真实对话 → 等上游结算 → 领奖
        if ($code === 'chat_5' || str_starts_with($code, 'Model_chat_')) {
            $model = $this->resolveTaskModel($account, $code);

            if ($model === '') {
                return [
                    'ok'      => false,
                    'message' => $code === 'Model_chat_GLM5.2'
                        ? '该任务实测不计数（上游判据不在 /v2/chat/completions 的模型字段上），无法代做'
                        : '未找到该任务要求的可用模型，无法代做',
                    'data'    => null,
                ];
            }

            $progress = $item['progress'] ?? null;
            $current  = is_array($progress) ? (int) ($progress['current'] ?? 0) : 0;
            $target   = is_array($progress) ? (int) ($progress['target'] ?? 1) : 1;
            $need     = max(1, $target - $current);

            if ($need > 10) {
                $need = 10;   // 兜底上限，避免异常 target 打出几十条对话
            }

            $sent = 0;

            for ($i = 0; $i < $need; $i++) {
                try {
                    $this->injectSeedChat($account, $model, $i);
                    $sent++;
                    usleep(700000);   // 降风控压力，并让上游有散点会话可计
                } catch (\Throwable $e) {
                    // 单条失败不中断，继续把剩余条数发完
                }
            }

            if ($sent === 0) {
                return ['ok' => false, 'message' => '代做失败：一条对话都没能发出', 'data' => null];
            }

            // 🔴 上游结算延迟 90~150s，等够再领；实现在 worker（CLI）里跑，不受请求超时约束
            sleep(150);

            $claim = $this->growthClaimTask($base, $headers, $code);

            if (!($claim['ok'] ?? false)) {
                return [
                    'ok'      => false,
                    'message' => '已发出 ' . $sent . ' 条对话，但结算后领取未成功（' . (string) ($claim['message'] ?? '')
                        . '）。可稍后再点「领取奖励」。',
                    'data'    => null,
                ];
            }

            return [
                'ok'      => true,
                'message' => '已代做（发出 ' . $sent . ' 条对话）并领取：' . (string) $claim['message'],
                'data'    => $claim['data'] ?? null,
            ];
        }

        // 其余任务：如实回报做不了
        return [
            'ok'      => false,
            'message' => '该任务的完成判据在客户端，上游没有可调用的写接口，网关无法代做',
            'data'    => null,
        ];
    }

    /**
     * 解析某对话计数型任务该用哪个模型发对话。
     *
     * - `chat_5`            ：任意可用模型即可（取活跃注入用的默认模型）
     * - `Model_chat_<X>`    ：task_code 里的 `<X>` 是**展示名**（如 GLM5.2），
     *                          需与账号真实可用模型做「去分隔符后」的模糊匹配
     *
     * 返回 '' 表示无法映射（或该模型实测不计数，如 glm-5.2）。
     */
    private function resolveTaskModel(Account $account, string $code): string
    {
        if ($code === 'chat_5') {
            return $this->pickActivityModel($account);
        }

        if (!str_starts_with($code, 'Model_chat_')) {
            return '';
        }

        $candidate = strtolower(str_replace('Model_chat_', '', $code));
        $candidate = str_replace('.', '-', $candidate);

        $available = [];

        try {
            foreach ($this->models($account) as $id) {
                $available[] = (string) $id;
            }
        } catch (\Throwable $e) {
            return '';
        }

        foreach ($available as $id) {
            if (str_replace(['-', '.'], '', $id) === str_replace(['-', '.'], '', $candidate)) {
                // GLM-5.2 实测连发 8+ 次仍不计数，别白烧额度
                return str_contains($id, 'glm-5.2') ? '' : $id;
            }
        }

        return '';
    }

    /** 开一次盲盒（POST /buddy/open） */
    private function growthOpenBox(string $base, array $headers): array
    {
        try {
            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($base . '/buddy/open', new \stdClass());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
        }

        if (!$res->ok()) {
            return ['ok' => false, 'message' => $this->extractMessage($res->body()) ?: ('开盲盒失败（HTTP ' . $res->status() . '）'), 'data' => null];
        }

        $node = $this->growthNode($res->body());
        $credit = $node !== null ? $this->jsonLong($node, ['credit', 'reward_credit']) : 0;

        return [
            'ok'      => true,
            'message' => $credit > 0 ? ('开盲盒 +' . $credit . ' 积分') : '已开盲盒',
            'data'    => $node,
        ];
    }

    /** 兑换连登奖励（POST /redeem，body {tier, client_token}） */
    private function growthRedeem(string $base, array $headers, string $tier): array
    {
        if (!in_array($tier, ['7d', '14d', '28d'], true)) {
            return ['ok' => false, 'message' => '档位无效（应为 7d / 14d / 28d）', 'data' => null];
        }

        $token = 'redeem-' . bin2hex(random_bytes(16));

        try {
            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($base . '/redeem', ['tier' => $tier, 'client_token' => $token]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
        }

        $msg = $this->extractMessage($res->body());
        $envelope = $this->decodeJsonNode($res->body());

        if ($envelope !== null && isset($envelope['code']) && is_numeric($envelope['code']) && (int) $envelope['code'] !== 0) {
            // 409 duplicate = 本月已领；403 = 天数不足。都属「正常态」，提示语原样回给页面。
            return ['ok' => false, 'message' => $msg !== '' ? $msg : '兑换被拒绝', 'data' => null];
        }

        if (!$res->ok()) {
            return ['ok' => false, 'message' => $msg !== '' ? $msg : ('兑换失败（HTTP ' . $res->status() . '）'), 'data' => null];
        }

        $node = $this->growthNode($res->body());
        $credit = $node !== null ? $this->jsonLong($node, ['credit_granted']) : 0;
        $energy = $node !== null ? $this->jsonLong($node, ['energy_granted']) : 0;
        $cards  = $node !== null ? $this->jsonLong($node, ['cards_granted']) : 0;
        $chance = $node !== null ? $this->jsonLong($node, ['chances_granted']) : 0;

        $parts = [];
        if ($credit > 0) {
            $parts[] = '+' . $credit . ' 积分';
        }
        if ($energy > 0) {
            $parts[] = '+' . $energy . ' 能量';
        }
        if ($cards > 0) {
            $parts[] = '+' . $cards . ' 补签卡';
        }
        if ($chance > 0) {
            $parts[] = '+' . $chance . ' 抽奖次数';
        }

        return [
            'ok'      => true,
            'message' => $parts === [] ? ('已兑换 ' . $tier . ' 连登奖励') : ('已兑换 ' . $tier . ' 连登奖励（' . implode('，', $parts) . '）'),
            'data'    => $node,
        ];
    }

    /** 抽一次奖（POST /lottery/draw，body {client_token}） */
    private function growthLotteryDraw(string $base, array $headers): array
    {
        $token = 'draw-' . bin2hex(random_bytes(16));

        try {
            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($base . '/lottery/draw', ['client_token' => $token]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
        }

        $msg = $this->extractMessage($res->body());
        $envelope = $this->decodeJsonNode($res->body());

        if ($envelope !== null && isset($envelope['code']) && is_numeric($envelope['code']) && (int) $envelope['code'] !== 0) {
            return ['ok' => false, 'message' => $msg !== '' ? $msg : '抽奖被拒绝（可能没有抽奖次数）', 'data' => null];
        }

        if (!$res->ok()) {
            return ['ok' => false, 'message' => $msg !== '' ? $msg : ('抽奖失败（HTTP ' . $res->status() . '）'), 'data' => null];
        }

        $node = $this->growthNode($res->body());
        $name = $node !== null ? $this->jsonString($node, ['prize_name']) : '';
        $credit = $node !== null ? $this->jsonLong($node, ['credit_amount']) : 0;

        $text = $name !== '' ? $name : ($credit > 0 ? ('积分 +' . $credit) : '抽奖完成');

        return ['ok' => true, 'message' => '抽奖结果：' . $text, 'data' => $node];
    }

    /** 对指定日期使用补签卡（POST /makeup-cards/use，body {target_date}） */
    private function growthUseMakeup(string $base, array $headers, string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['ok' => false, 'message' => '补签日期格式应为 YYYY-MM-DD', 'data' => null];
        }

        try {
            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($base . '/makeup-cards/use', ['target_date' => $date]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
        }

        $msg = $this->extractMessage($res->body());
        $envelope = $this->decodeJsonNode($res->body());

        if ($envelope !== null && isset($envelope['code']) && is_numeric($envelope['code']) && (int) $envelope['code'] !== 0) {
            return ['ok' => false, 'message' => $msg !== '' ? $msg : '补签被拒绝（无补签卡或该日无需补签）', 'data' => null];
        }

        if (!$res->ok()) {
            return ['ok' => false, 'message' => $msg !== '' ? $msg : ('补签失败（HTTP ' . $res->status() . '）'), 'data' => null];
        }

        return ['ok' => true, 'message' => '已对 ' . $date . ' 使用补签卡', 'data' => $this->growthNode($res->body())];
    }

    /**
     * 领新手礼包 / 活动补偿（billing 域）
     *
     * ⚠️ 路径**不带 `/v2`**（实测 `www.codebuddy.cn/v2/billing/meter/claim-gift` → 404，
     *    `.../billing/meter/claim-gift` → 命中业务码 10001「已领取过」）。
     *    这是本文件里唯一不带 /v2 的 billing 端点族（growth 域各端点则两个 base 都通）。
     *
     * @param string $slug claim-gift | claim-compensation
     */
    private function growthClaimBilling(Account $account, string $slug): array
    {
        $headers = $this->meterHeaders($account);
        // billing 域固定走网页主机（global → www.workbuddy.ai，否则 www.codebuddy.cn）
        $host    = $this->hostWeb($account);

        $paths = [
            'claim-gift'         => '/billing/meter/claim-gift',
            'claim-compensation' => '/billing/meter/claim-compensation',
        ];

        if (!isset($paths[$slug])) {
            return ['ok' => false, 'message' => '未知领取类型', 'data' => null];
        }

        try {
            $res = $this->http()->withHeaders($headers)->timeout(30)
                ->postJson($host . $paths[$slug], new \stdClass());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
        }

        $msg = $this->extractMessage($res->body());
        $envelope = $this->decodeJsonNode($res->body());

        if ($envelope !== null && isset($envelope['code']) && is_numeric($envelope['code']) && (int) $envelope['code'] !== 0) {
            return ['ok' => false, 'message' => $msg !== '' ? $msg : '领取被拒绝（可能已领过或无可领内容）', 'data' => null];
        }

        if (!$res->ok()) {
            return ['ok' => false, 'message' => $msg !== '' ? $msg : ('领取失败（HTTP ' . $res->status() . '）'), 'data' => null];
        }

        $node = $this->growthNode($res->body());
        $credit = $node !== null ? $this->jsonLong($node, ['credit']) : 0;
        $label = $slug === 'claim-gift' ? '新手礼包' : '活动补偿';

        return [
            'ok'      => true,
            'message' => $credit > 0 ? ('已领取' . $label . ' +' . $credit . ' 积分') : ('已领取' . $label),
            'data'    => $node,
        ];
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
