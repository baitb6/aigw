<?php
/**
 * ZCode 适配器（智谱 GLM Coding Plan，参考 TriDefender/zcode-api 实现）
 *
 * 协议来源：https://github.com/TriDefender/zcode-api
 *   - 登录：  POST {ZCODE_API_BASE}/oauth/cli/init  → 授权链接
 *            GET  {ZCODE_API_BASE}/oauth/cli/poll/{flow_id} 轮询换取 token
 *   - 额度：  GET  {bizHost}/api/v1/zcode-plan/billing/balance
 *            GET  {bizHost}/api/v1/zcode-plan/billing/preview
 *   - 领取：  GET  {CLAIM_ORIGIN}/api/v1/zcode-plan/billing/preview  列出可领取计划
 *            POST {CLAIM_ORIGIN}/api/v1/zcode-plan/billing/claim    领取 { plan_id }
 *            （领取走固定的 zcode.z.ai 控制面，不区分国内/国际；需 OAuth JWT
 *              + X-Device-Mid + 阿里云验证码头 X-Aliyun-Captcha-Verify-Param
 *              + X-Aliyun-Captcha-Verify-Region）
 *   - 对话：  POST {openaiBaseURL}/chat/completions（OpenAI 兼容）
 *
 * ✅ 验证码已实现**全自动免人工**（2026-09-30 实测打通）：
 *   ① GET {CLAIM_ORIGIN}/api/v1/client/configs（**免鉴权**）拿验证码 prefix/sceneId/region
 *   ② CaptchaSolver 驱动无头浏览器跑 `instance.startTracelessVerification()`
 *      —— 阿里云「无痕验证」：风控评分通过即**零交互**返回 captchaVerifyParam
 *   ③ 现取现用（param 一次性 + 90 秒时效），随 claim 一起提交
 *   实测终态：`HTTP 200 {"code":1003,"msg":"target already exists"}` —— 请求穿过
 *   验证码 + 设备指纹 + 鉴权直达业务层，证明验证码这关已彻底解决。
 *
 * 同一适配器按 provider_key 区分区域（ProviderManager::withKey 注入）：
 *   - 'zcode'      → 智谱国内版（BigModel / 智谱）：open.bigmodel.cn，upstream=bigmodel
 *   - 'zcode_intl' → 智谱国际版（Z.AI）：api.z.ai / zcode.z.ai，upstream=zai
 *
 * 注意：完整的「客户端请求签名 V4」需要 Ed25519（HKDF 握手 + 业务签名 + PoW）。
 * 实现见 App\Support\ClientSigning，依赖 PHP sodium 扩展与 hash_hkdf。
 * 未启用 sodium 时自动降级为不签名（fail-open），登录、额度、签到、对话主链路不受影响。
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
use App\Support\CaptchaSolver;
use App\Support\ClientSigning;
use App\Support\HttpClient;
use App\Support\Logger;

class ZcodeProvider extends AbstractProvider
{
    /** ZCode 平台基址（登录 / OAuth） */
    private const ZCODE_API_BASE = 'https://zcode.z.ai/api/v1';

    /** 领取控制面（固定，不区分国内/国际；见 config.example.yaml claim.origin） */
    private const CLAIM_ORIGIN = 'https://zcode.z.ai';

    /**
     * 领取控制面 platform 取值：真实客户端为 `${process.platform}-{arch}`。
     * 本项目运行在 Windows，取 win32-x64（与 ZCode 桌面端一致）。
     */
    private const CLAIM_PLATFORM = 'win32-x64';

    /** 智谱国际版主机（Z.AI） */
    private const ZAI_BIZ_HOST = 'https://api.z.ai';
    private const ZAI_OPENAI_BASE = 'https://api.z.ai/api/coding/paas/v4';
    private const ZAI_ANTHROPIC_BASE = 'https://api.z.ai/api/anthropic';

    /** 智谱国内版主机（BigModel / 智谱） */
    private const BIGMODEL_BIZ_HOST = 'https://open.bigmodel.cn';
    private const BIGMODEL_OPENAI_BASE = 'https://open.bigmodel.cn/api/coding/paas/v4';
    private const BIGMODEL_ANTHROPIC_BASE = 'https://open.bigmodel.cn/api/anthropic';

    /**
     * start-plan 对话代理（**兜底通路**，正常不再走这里）。
     *
     * ⚠️ 实测（2026-09）：该端点由阿里云 ESA WAF 保护，需要验证码，否则被拦成
     * `405 {"code":3012,"msg":"request has been blocked due to unusual activity."}`。
     * 现已能**自动过验证码**（见 solveCaptcha），但该通路仍非首选。
     *
     * ✅ 正解：`codingPlanKey()` 三级派生 **长期 API Key**，直连
     * `{coding/paas}/v4/chat/completions` 或 `/api/anthropic/v1/messages`，
     * **完全不需要验证码**。本常量对应的 chatStartPlan() 仅在派生失败时兜底。
     */
    private const PLAN_ANTHROPIC_PATH = '/api/v1/zcode-plan/anthropic/v1/messages';

    /** 阿里云验证码请求头（WAF 校验项） */
    private const CAPTCHA_HEADER = 'X-Aliyun-Captcha-Verify-Param';

    /** 阿里云验证码区域头。真实客户端 claimManualPlan 会带上（缺了会被判 3001 parameter error） */
    private const CAPTCHA_REGION_HEADER = 'X-Aliyun-Captcha-Verify-Region';

    /**
     * 客户端配置接口（**免鉴权**）：下发验证码的 prefix/sceneId/region 等。
     *
     * ⚠️ 这是「免人工过验证码」的关键入口 —— 早期抓不到 prefix/sceneId 就是因为
     * 只在登录后的页面 DOM 里找，而它其实由这个公开接口下发（缓存 60s）。
     */
    private const CLIENT_CONFIGS_PATH = '/api/v1/client/configs';

    /** 验证码配置缓存（进程级，[config, 获得时间]），与客户端一致保留 60s */
    private static ?array $captchaConfigCache = null;

    /**
     * zcode-plan 代理的 WAF 负缓存冷却时长（秒）。
     *
     * 该端点一旦被判死，同一进程内再试都是白等一次无痕求解（10~16 秒）+ 一次注定
     * 失败的请求；冷却期内直接抛出原始额度错误，不再重试该通路。
     */
    private const PLAN_PATH_COOLDOWN = 600;

    /** 领取失败「已领过」的上游业务码：不算故障，属正常幂等结果 */
    private const CLAIM_CODE_ALREADY = 1003;

    /** 领取失败「计划不存在/已下线」 */
    private const CLAIM_CODE_TARGET_NOT_FOUND = 1001;

    private const KEY_CN = 'zcode';
    private const KEY_INTL = 'zcode_intl';

    /** 客户端版本号（追加到 OAuth 授权插页参数 app_version，与 ZCode 桌面端一致） */
    private const APP_VERSION = '3.14.3';

    private const DEFAULT_MODEL = 'glm-4.6';

    /** plan_id 探测结果缓存（进程级，uid => plan_id） */
    private array $planCache = [];

    /** coding-plan API Key 缓存（进程级，uid => key）；空串 = 已确认取不到，避免重复派生 */
    private array $keyCache = [];

    /**
     * zcode-plan 代理被 WAF 拦截的时间戳（进程级负缓存）。
     *
     * 该端点一旦被判死，同一进程内再试都是白等一次无痕求解（10~16 秒）+
     * 一次注定失败的请求。用负缓存把它挡在门外，冷却期内直接抛出原始额度错误。
     *
     * 冷却时长见 `PLAN_PATH_COOLDOWN`。
     */
    private int $planPathBlockedAt = 0;

    /** 业务令牌缓存（进程级，uid => [token, 获得时间]）；仅国际版需要换票，保留 50 分钟 */
    private array $bizTokenCache = [];

    /**
     * Anthropic 通道的**工具调用累积缓冲**（按内容块 index 索引）。
     *
     * Anthropic 的工具调用是**两段式**：`content_block_start(type:tool_use)` 只给
     * id/name，参数则以多条 `content_block_delta(input_json_delta.partial_json)` 分片下发，
     * 直到 `content_block_stop` 才完整。因此必须逐片攒在本缓冲里，收尾时再组装成
     * OpenAI 规范的 tool_calls（与 OpenAI 通道同形态）下发。
     *
     * 每次 `parseAnthropicSse()` 进入前清空 —— 否则上一轮（或上一条重采样）的
     * 残留工具调用会被重复下发。
     *
     * @var array<int, array{id:string, name:string, arguments:string}>
     */
    private array $anthropicToolBuf = [];

    /**
     * Anthropic 工具调用「index → 工具名」映射。
     *
     * ⚠️ 必须显式声明为属性：PHP 8.2 起**动态属性已废弃**，若在方法里直接
     * `$this->anthropicToolName[$k] = …` 而不声明，每个请求都会向 error_log
     * 写一条 `Creation of dynamic property … is deprecated`（实测刷屏）。
     *
     * @var array<int, string>
     */
    private array $anthropicToolName = [];

    /**
     * 本次 `chat()` 是否已向客户端吐出过正文。
     *
     * 用于 `chatWithKey()` 决定「Anthropic 通道失败时能否回落 OpenAI 通道」：
     * 已吐过正文还回落 → 两段正文拼接，客户端看到重复内容（实测 `你好你好`）。
     * 每次 `chat()` 入口复位。
     */
    private bool $emittedAny = false;

    /**
     * 控制面基址（customer / organization / api_keys，走 open.bigmodel.cn / api.z.ai）。
     * 用于派生 coding-plan API Key。
     */
    private const API_KEYS_PATH = '/api/biz/v1/organization/%s/projects/%s/api_keys';

    /**
     * 上游模型目录路径 —— **Anthropic 风格**（主源）。
     *
     * `GET {anthropicBase}/v1/models` → `{"data":[{id, display_name, created_at}]}`，
     * 是三者里**唯一带 `display_name`** 的形态，故优先。实测（2026-10-01）
     * 国内/国际均 200、各 11 条。
     */
    private const MODEL_LIST_PATH_ANTHROPIC = '/v1/models';

    /**
     * 上游模型目录路径 —— **OpenAI 风格**（兜底）。
     *
     * `GET {openaiBase}/models` → `{"object":"list","data":[{id, object, created, owned_by}]}`，
     * 只有 id、无显示名。与上者内容等价，仅作 Anthropic 端点抖动时的冗余。
     */
    private const MODEL_LIST_PATH_OPENAI = '/models';

    /**
     * 模型**元数据**表：model_id => [显示名, 上下文窗口, 多模态, 推理]。
     *
     * ⚠️ 定位已变更（2026-10-01）：本表现在**只提供元数据**，不再充当「有哪些模型」的权威。
     * 「有哪些模型」一律以**上游 `GET {base}/v1/models` 为准**（见 fetchUpstreamModelList）；
     * 上游拉不到时才整表回退为目录。上游只给 id + display_name，上下文窗口/多模态/推理
     * 这三项上游不下发，由本表按 id 补齐；补不到的中性回退（见 catalogRow）。
     *
     * 这样上游换代（如新增 glm-5.4）能自动跟上，不会像 Qoder 那样因硬编码陈旧表而漂移
     * （见 docs/qoder与zcode上游协议.md §十二/§14）。
     *
     * 规格来源：智谱官方开放文档 + 腾讯云 GLM 调用指南（2026-10 实测核对）。
     */
    private const BUILTIN_MODELS = [
        'glm-4.5'        => ['GLM-4.5', 131072, false, true],
        'glm-4.5-air'    => ['GLM-4.5-Air', 131072, false, true],
        'glm-4.6'        => ['GLM-4.6', 204800, false, true],
        'glm-4.7'        => ['GLM-4.7', 204800, false, true],
        'glm-5'          => ['GLM-5', 204800, false, true],
        'glm-5-turbo'    => ['GLM-5-Turbo', 204800, false, true],
        'glm-5.1'        => ['GLM-5.1', 204800, false, true],
        'glm-5.2'        => ['GLM-5.2', 1048576, false, true],
        'glm-5.3'        => ['GLM-5.3', 1048576, false, true],
        'glm-5.3-flash'  => ['GLM-5.3-Flash', 1048576, true, true],
        'glm-5.3-flashx' => ['GLM-5.3-FlashX', 1048576, true, true],
    ];

    /**
     * 「上游目录未列、但端点实测可用」的模型 —— 与 Qoder 的 EXTRA_MODELS_INTL 同成因
     * （见 docs/qoder与zcode上游协议.md §十四：**上游目录 ≠ 全部可用模型**）。
     *
     * 实测证据（2026-10-01，`tools/verify-zcode-model-echo.php`）：
     * 这四个名字直连 `POST {anthropicBase}/v1/messages` 均 **HTTP 200 + 上游回执
     * `message.model` 与请求名逐字一致**（`glm-bogus-xyz-999` 则 400 被拒）——
     * 证明是**真·可用**，而非像 Qoder legacy 那样被静默改派默认模型。
     *
     * ⚠️ 必须并入目录：它们不在上游 list 里，若不补就是「有额度却选不到模型」。
     * 判据是「回执 model 字段与请求名一致」，绝不靠「能出正文」下结论。
     */
    private const EXTRA_MODELS = [
        'glm-4.6v'      => ['GLM-4.6V', 131072, true, true],
        'glm-5v-turbo'  => ['GLM-5V-Turbo', 204800, true, true],
        'glm-4.5v'      => ['GLM-4.5V', 131072, true, true],
        'glm-4.5-flash' => ['GLM-4.5-Flash', 131072, false, true],
    ];

    /**
     * 进程内模型目录缓存（uid => [id => display_name]）。
     *
     * 上游目录一次请求内可能被 `models()` 与 `modelCatalog()` 各取一次
     * （如 ModelController::syncAll → modelCatalog，AccountController::models → models），
     * 缓存避免重复网络往返与两处结果不一致。
     */
    private array $modelListCache = [];

    /** 默认计划 ID（签到/领取用），可由账号 meta 覆盖 */
    private const DEFAULT_PLAN_ID = 'coding-plan';

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
        return $this->isIntlProvider() ? 'ZCode 国际版' : 'ZCode';
    }

    /**
     * 主通道是 Anthropic `/v1/messages`：OpenAI 的 `image_url` 内容块与 Anthropic 的
     * `image`/`source` 块结构不同，原样透传会被上游整单拒收，而本适配器暂不做这层翻译 ——
     * 与其让调用方拿到 400 猜原因，不如在请求前明确回 `unsupported_image_input`。
     */
    public function supportsImageInput(): bool
    {
        return false;
    }

    public function loginType(): string
    {
        return 'DEVICE_CODE';
    }

    public function hosts(): array
    {
        $fallback = $this->isIntlProvider()
            ? ['api.z.ai', 'zcode.z.ai']
            : ['open.bigmodel.cn', 'bigmodel.cn'];

        return $this->providerRepository->find($this->providerKey)['hosts'] ?? $fallback;
    }

    private function isIntlProvider(): bool
    {
        return $this->providerKey === self::KEY_INTL;
    }

    /** OpenAI 兼容对话基址 */
    private function openaiBase(): string
    {
        return $this->isIntlProvider() ? self::ZAI_OPENAI_BASE : self::BIGMODEL_OPENAI_BASE;
    }

    /** 上游协议类型（zai / bigmodel），用于登录换票：国际版 zai，国内版 bigmodel */
    private function upstreamType(): string
    {
        return $this->isIntlProvider() ? 'zai' : 'bigmodel';
    }

    // ---------------------------------------------------------
    // 凭据解析
    // ---------------------------------------------------------

    /** 已登录的访问令牌（优先级：access_token > token > api_key） */
    private function accessToken(Account $account): string
    {
        return trim((string) $account->cred(
            'access_token',
            $account->cred('accessToken', $account->cred('token', $account->cred('api_key', '')))
        ));
    }

    /**
     * OAuth JWT：领取（claim）必须使用它，而非 provider 的 access_token。
     * 登录 poll 时上游 data.token 即为 JWT；兼容 zai_token / jwt 等字段别名。
     */
    private function jwt(Account $account): string
    {
        return trim((string) $account->cred(
            'jwt',
            $account->cred('token', $account->cred('zai_token', $account->cred('access_token', '')))
        ));
    }

    /**
     * 领取设备标识 `X-Device-Mid`：上游网关（0828 / 0918 活动）要求必须携带
     * UUID 格式的该头，否则一律返回 biz 3001 "parameter error"。
     * 优先复用账号已落库的值；没有则生成一次并持久化，保证同一账号稳定。
     */
    private function claimDeviceMid(Account $account): string
    {
        $existing = trim((string) $account->meta('device_mid', ''));
        if ($existing !== '') {
            return $existing;
        }

        $generated = uuid();
        $this->accountRepository->patchMeta($account, ['device_mid' => $generated]);

        return $generated;
    }

    /**
     * 领取预览请求头：镜像真实客户端的最小头集 —— 仅有 JWT 时带 Authorization，
     * 再追加 X-Device-Mid（活动网关强制项）。
     */
    private function claimPreviewHeaders(Account $account): array
    {
        $headers = [];
        $jwt = $this->jwt($account);
        if ($jwt !== '') {
            $headers['Authorization'] = 'Bearer ' . $jwt;
        }
        // ⚠️ `X-Device-Mid` 是网关**强制项**：缺它一律回 biz 3001 "parameter error"。
        // 早期这里只在 claim 时带、preview 不带 → preview 被误读成「恒空」。
        $headers['X-Device-Mid'] = $this->claimDeviceMid($account);
        $headers['X-ZCode-App-Version'] = self::APP_VERSION;
        $headers['X-Platform'] = self::CLAIM_PLATFORM;
        $headers['Accept'] = 'application/json';
        $headers['User-Agent'] = 'ZCode/' . self::APP_VERSION;

        return $headers;
    }

    /**
     * 领取请求头：JWT 授权 + 阿里云验证码 + 桌面端标识（顺序与真实客户端一致，
     * X-Device-Mid 置于最后）。
     * 参考 src/claim/client.ts 的 `claimManualPlan` headers 构造。
     */
    private function claimHeaders(Account $account, string $captcha, string $region = ''): array
    {
        $jwt = $this->jwt($account);
        if ($jwt === '') {
            throw new \RuntimeException($this->displayName() . ' 账号缺少 OAuth JWT，请重新登录');
        }

        $headers = [
            'Authorization'        => 'Bearer ' . $jwt,
            'Content-Type'         => 'application/json',
            'X-ZCode-App-Version'  => self::APP_VERSION,
            'X-Platform'           => self::CLAIM_PLATFORM,
        ];

        if ($captcha !== '') {
            $headers[self::CAPTCHA_HEADER] = $captcha;
            // ⚠️ 区域头是**必带项**：实测缺它会回 biz 3001 parameter error，
            // 而被误读成「验证码被拒」。真实客户端 claimManualPlan 就带这个头。
            $headers[self::CAPTCHA_REGION_HEADER] = $region !== '' ? $region : 'cn';
        }

        $headers['X-Device-Mid'] = $this->claimDeviceMid($account);

        return $headers;
    }

    /**
     * 拉取客户端配置里的验证码段（**免鉴权**）：
     *   GET {CLAIM_ORIGIN}/api/v1/client/configs?app_version=&platform=
     *     → data.configs.captcha = {enabled, prefix, sceneId, region, skip_model_request}
     *
     * 返回值缓存 60 秒（与客户端一致）。失败返回 []，由调用方降级。
     *
     * @return array{prefix?:string,sceneId?:string,region?:string,enabled?:bool}
     */
    private function captchaConfig(): array
    {
        $cached = self::$captchaConfigCache;
        if (is_array($cached) && (time() - (int) ($cached[1] ?? 0)) < 60) {
            return is_array($cached[0] ?? null) ? $cached[0] : [];
        }

        try {
            $url = self::CLAIM_ORIGIN . self::CLIENT_CONFIGS_PATH
                . '?app_version=' . rawurlencode(self::APP_VERSION)
                . '&platform=' . rawurlencode(self::CLAIM_PLATFORM);

            $res = $this->http()
                ->withHeaders([
                    'Accept'              => 'application/json',
                    'User-Agent'          => 'ZCode/' . self::APP_VERSION,
                    'X-ZCode-App-Version' => self::APP_VERSION,
                    'X-Platform'          => self::CLAIM_PLATFORM,
                ])
                ->timeout(20, 10)
                ->get($url);

            if (!$res->ok()) {
                return [];
            }

            $json = $res->json();
            $captcha = $json['data']['configs']['captcha'] ?? null;
            if (!is_array($captcha)) {
                return [];
            }

            self::$captchaConfigCache = [$captcha, time()];

            return $captcha;
        } catch (\Throwable $e) {
            Logger::warn($this->id() . ' 拉取验证码配置失败：' . $e->getMessage());
            return [];
        }
    }

    /**
     * 自动求解阿里云验证码（**无痕验证，零人工交互**）。
     *
     * 链路：captchaConfig() 取 prefix/sceneId/region → CaptchaSolver::solve() 驱动无头浏览器
     * 跑 `instance.startTracelessVerification()` → 拿一次性 captchaVerifyParam。
     *
     * 返回 '' 表示失败（浏览器不可用 / 风控降级为滑块），由调用方决定降级提示。
     */
    private function solveCaptcha(): string
    {
        $cfg = $this->captchaConfig();
        $prefix  = trim((string) ($cfg['prefix'] ?? ''));
        $sceneId = trim((string) ($cfg['sceneId'] ?? ''));
        $region  = trim((string) ($cfg['region'] ?? 'cn'));

        if (($cfg['enabled'] ?? true) === false) {
            return '';
        }
        if ($prefix === '' || $sceneId === '') {
            Logger::warn($this->id() . ' 验证码配置缺 prefix/sceneId，无法自动求解');
            return '';
        }

        return CaptchaSolver::solve($prefix, $sceneId, $region);
    }


    /**
     * 检测账号真实计划等级。
     *
     * ⚠️ 必须**真实探测**：`meta.plan_id` 是早期写死的 `coding-plan`，而实测该账号
     * 实为 **start-plan**（免费体验）。计划判错会导致整条对话链路走错端点 → 恒定失败。
     * 判据取自控制面 `billing/balance` 的 `plans[].plan_id`（如 `zcode-v3-start-plan-0817`）。
     * 探测失败时回退旧值，保持 fail-open。
     */
    private function detectPlanId(Account $account): string
    {
        $this->planCache[$account->uid] ??= $this->probePlanId($account);

        return $this->planCache[$account->uid];
    }

    /** 探测上游真实 plan_id */
    private function probePlanId(Account $account): string
    {
        $configured = $this->planId($account);

        if ($this->jwt($account) === '') {
            return $configured;
        }

        try {
            $response = $this->http()
                ->withHeaders($this->controlPlaneHeaders($account))
                ->timeout(15, 8)
                ->get(rtrim($this->controlPlaneHost(), '/') . '/api/v1/zcode-plan/billing/balance');
        } catch (\Throwable $e) {
            return $configured;
        }

        if (!$response->ok()) {
            return $configured;
        }

        $data = $response->json();
        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;

        foreach ((array) ($payload['plans'] ?? []) as $plan) {
            if (!is_array($plan)) {
                continue;
            }
            if (($plan['status'] ?? '') !== 'active') {
                continue;
            }
            $id = trim((string) ($plan['plan_id'] ?? ''));
            if ($id === '') {
                continue;
            }

            // 落库一次，后续请求免探测
            if ($id !== $configured) {
                $this->accountRepository->patchMeta($account, ['plan_id' => $id]);
            }

            return $id;
        }

        return $configured;
    }

    /** 是否 start-plan（免费体验计划）—— 决定对话走 zcode-plan 代理 */
    private function isStartPlan(Account $account): bool
    {
        return str_contains($this->detectPlanId($account), 'start-plan');
    }

    /**
     * 解出「本账号是否已整体失效」的过期时刻（秒级），0 = 不过期。
     *
     * 只做 base64 解码读载荷，**不验签** —— 用途仅是把「已过期」和「缺验证码」区分开。
     *
     * ⚠️ **必须看全部令牌，不能命中一个就 return**（2026-09-30 踩坑）：
     * 国际版有**三个**令牌，寿命语义完全不同 ——
     *   - `access_token` / `zai_token`：**OIDC 身份令牌**，`exp` 仅 1 小时，会过期；
     *   - `jwt`：**HS512 业务令牌（无 `exp`，长效）**，正是 `planToken()` 真正拿去
     *     打 `zcode-plan` 代理的那一个。
     * 早期实现按 `['access_token','jwt','zai_token']` 顺序「取第一个带 exp 的」→
     * OIDC 一过 1 小时就误报 `SESSION_DEAD`（「请重新登录」），把**仍然可用**的 jwt
     * 整个挡在门外，表现为「国际版刚登录就能聊，过一小时就恒报令牌过期」。
     * 国内版三个令牌都无 `exp` → 一直返回 0 → 从未暴露该 bug。
     *
     * 正确语义：**只要还有任一令牌不带 `exp`（长效）就不算过期**；否则取**最晚**的
     * `exp`（有一个还没死就还能用）。
     */
    private function tokenExpiredAt(Account $account): int
    {
        $latest = 0;

        foreach (['access_token', 'jwt', 'zai_token'] as $field) {
            $token = (string) $account->cred($field, '');
            $parts = explode('.', $token);
            if (count($parts) < 2) {
                continue;
            }

            $payload = strtr($parts[1], '-_', '+/');
            $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
            $decoded = json_decode((string) base64_decode($payload, true), true);

            if (!is_array($decoded)) {
                continue;
            }

            // 不带 exp = 长效令牌 → 整体视为不过期。
            if (empty($decoded['exp'])) {
                return 0;
            }

            $latest = max($latest, (int) $decoded['exp']);
        }

        return $latest;
    }

    /** start-plan 代理所在站点：国内 zcode.z.ai，国际同理（实测同域） */
    private function planOrigin(): string
    {
        return self::CLAIM_ORIGIN;
    }

    // ---------------------------------------------------------
    // coding-plan API Key 派生（本项目自行实现，零抓包 / 零验证码）
    // ---------------------------------------------------------

    /**
     * 业务面基址（customer / organization / api_keys）。
     * 国内 open.bigmodel.cn，国际 api.z.ai —— 与 openaiBase() 的区域判定一致。
     */
    private function bizHost(): string
    {
        return $this->isIntlProvider() ? self::ZAI_BIZ_HOST : self::BIGMODEL_BIZ_HOST;
    }

    /**
     * 业务面访问令牌（用于派生 API Key / 调控制面）。
     *
     * 两区域形态**不同**（实测 2026-09-30）：
     *   - 国内版：`access_token` 直接就是 **HS512 业务令牌**（payload 含 customer_id / user_key），可直接用。
     *   - 国际版：`access_token` 是 **OIDC 身份令牌**（`alg=HS256`、`iss=user-service`、
     *     `scopes=[openid,profile,email]`、`exp` 1 小时）—— 拿去调 biz 接口一律
     *     `401 token expired or incorrect`。必须先换票：
     *       POST {bizHost}/api/auth/z/login  {"token": "<OIDC token>"}
     *         → code 200，data.access_token 才是 HS512 业务令牌（实测 1404 字符）。
     *
     * 换票结果按 uid 缓存 50 分钟（业务令牌无 exp，但保守起见定期换新）。
     * 任何失败都 fail-open 回退原始 access_token，由上层按 401/SESSION_DEAD 处理。
     */
    private function businessToken(Account $account): string
    {
        $token = $this->accessToken($account);
        if ($token === '') {
            return '';
        }

        if (!$this->isIntlProvider()) {
            return $token;
        }

        $cached = $this->bizTokenCache[$account->uid] ?? null;
        if (is_array($cached) && (time() - (int) ($cached[1] ?? 0)) < 3000 && (string) ($cached[0] ?? '') !== '') {
            return (string) $cached[0];
        }

        // 已换过票并落库的优先复用（进程外也能省一次往返）
        $stored = trim((string) $account->cred('biz_token', ''));

        try {
            $res = $this->http()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                    'User-Agent'   => 'ZCode/' . self::APP_VERSION,
                ])
                ->timeout(30, 12)
                ->postRaw(
                    rtrim($this->bizHost(), '/') . '/api/auth/z/login',
                    json_encode(['token' => $token], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );

            $json = $res->json();
            $biz = trim((string) ($json['data']['access_token'] ?? ($json['access_token'] ?? '')));

            if ($biz !== '') {
                $this->bizTokenCache[$account->uid] = [$biz, time()];
                $credential = $account->credential;
                $credential['biz_token'] = $biz;
                $this->accountRepository->updateCredential($account->id, $credential);
                return $biz;
            }

            Logger::warn($this->id() . ' 换票未取到业务令牌（HTTP ' . $res->status() . '），回退原始令牌');
        } catch (\Throwable $e) {
            Logger::warn($this->id() . ' 换票异常：' . $e->getMessage());
        }

        return $stored !== '' ? $stored : $token;
    }

    /**
     * 用已登录的 `access_token` 派生 coding-plan **两段式 API Key**。
     *
     * 链路（三级，全部 GET，只需 access_token，实测 2026-09-30 全绿）：
     *   ① GET {bizHost}/api/biz/customer/getCustomerInfo
     *        → data.organizations[0].organizationId + .projects[0].projectId
     *   ② GET {bizHost}/api/biz/v1/organization/{org}/projects/{proj}/api_keys
     *        → data[0].apiKey（明文）
     *   ③ GET {bizHost}/api/biz/v1/organization/{org}/projects/{proj}/api_keys/copy/{apiKey}
     *        → data.secretKey（明文，**仅 copy 接口返回完整值**，列表接口是 *****fLhY 遮蔽）
     *   最终 key = `{apiKey}.{secretKey}`（49 字符，恰好一个点 → 满足 ClientSigning 要求）
     *
     * ⚠️ 为什么必须走这条路：start-plan 的 `zcode-plan/anthropic/v1/messages` 由阿里云
     * ESA WAF 保护，要求 `X-Aliyun-Captcha-Verify-Param`，而该验证码是**一次性 + 90 秒**
     * 时效（阿里云验证码 2.0 文档 F018「同一笔验证码请求只允许提交一次」/ F019「V3 架构
     * 业务验签请求间隔超出 90 秒」）—— 抓包填入网关**根本不可能复用**。
     * 而派生出的 API Key 是**长期有效**的，直接走 `{coding/paas}/v4` 或 `/api/anthropic`
     * 两个标准通道，**完全不需要验证码**。
     *
     * 结果缓存 + 落库 `credential.coding_plan_key`：只派生一次，后续请求直接复用。
     * （字段名用 `coding_plan_key` 而非 `api_key` —— 后者在 accessToken() 里已是
     *  通用 fallback 字段，复用会污染令牌解析。）
     */
    private function codingPlanKey(Account $account): string
    {
        // 已落库的显式 key 优先（用户手工填 / 上次派生结果）
        $stored = trim((string) $account->cred('coding_plan_key', ''));
        if ($stored !== '' && substr_count($stored, '.') === 1) {
            return $stored;
        }

        if (array_key_exists($account->uid, $this->keyCache)) {
            return $this->keyCache[$account->uid];
        }

        $key = $this->deriveCodingPlanKey($account);
        $this->keyCache[$account->uid] = $key;

        if ($key !== '') {
            $credential = $account->credential;
            $credential['coding_plan_key'] = $key;
            $this->accountRepository->updateCredential($account->id, $credential);
            Logger::info($this->id() . ' 已派生 coding-plan API Key（uid=' . $account->uid . '）');
        }

        return $key;
    }

    /** 实际执行三级派生；任何一步失败返回空串（fail-open，交由上层回退 start-plan 通路） */
    private function deriveCodingPlanKey(Account $account): string
    {
        // ⚠️ 必须用 businessToken() 而非 accessToken()：国际版 access_token 是 OIDC
        // 身份令牌，直连 biz 接口一律 401（详见 businessToken() 注释）。
        $token = $this->businessToken($account);
        if ($token === '') {
            return '';
        }

        $headers = [
            'Authorization'       => 'Bearer ' . $token,
            'Accept'              => 'application/json',
            'Content-Type'        => 'application/json',
            'User-Agent'          => 'ZCode/' . self::APP_VERSION,
            'X-ZCode-App-Version' => self::APP_VERSION,
        ];

        $host = rtrim($this->bizHost(), '/');

        // ① 客户信息 → org / project
        try {
            $res = $this->http()->withHeaders($headers)->timeout(20, 12)
                ->get($host . '/api/biz/customer/getCustomerInfo');
            $data = $res->json();
            $data = is_array($data['data'] ?? null) ? $data['data'] : $data;
            $org = (array) (($data['organizations'][0] ?? []) ?: []);
            $orgId = trim((string) ($org['organizationId'] ?? ''));
            $projId = trim((string) ($org['projects'][0]['projectId'] ?? ''));
        } catch (\Throwable $e) {
            Logger::warn($this->id() . ' 派生 API Key ① 失败：' . $e->getMessage());
            return '';
        }

        if ($orgId === '' || $projId === '') {
            Logger::warn($this->id() . ' 派生 API Key ① 无 org/project（可能是令牌过期）');
            return '';
        }

        $base = sprintf(self::API_KEYS_PATH, rawurlencode($orgId), rawurlencode($projId));

        // ② 列出 api_keys → 取第一个的 apiKey
        try {
            $res = $this->http()->withHeaders($headers)->timeout(20, 12)->get($host . $base);
            $list = (array) ($res->json()['data'] ?? []);
            $apiKey = trim((string) (($list[0]['apiKey'] ?? '') ?: ''));
        } catch (\Throwable $e) {
            Logger::warn($this->id() . ' 派生 API Key ② 失败：' . $e->getMessage());
            return '';
        }

        if ($apiKey === '') {
            Logger::warn($this->id() . ' 派生 API Key ② 列表为空');
            return '';
        }

        // ③ copy → 取完整 secretKey（列表接口返回的是遮蔽值）
        try {
            $res = $this->http()->withHeaders($headers)->timeout(20, 12)
                ->get($host . $base . '/copy/' . rawurlencode($apiKey));
            $secret = trim((string) (($res->json()['data']['secretKey'] ?? '') ?: ''));
        } catch (\Throwable $e) {
            Logger::warn($this->id() . ' 派生 API Key ③ 失败：' . $e->getMessage());
            return '';
        }

        if ($secret === '') {
            Logger::warn($this->id() . ' 派生 API Key ③ 未取到 secretKey');
            return '';
        }

        // 遮蔽值形如 *****fLhY，长度不足以构成合法 key；防御性拒绝
        if (str_contains($secret, '*')) {
            return '';
        }

        return $apiKey . '.' . $secret;
    }

    /** 组装 start-plan 的 Authorization：优先 JWT，其次 access_token */
    private function planToken(Account $account): string
    {
        $jwt = $this->jwt($account);
        if ($jwt !== '') {
            return $jwt;
        }

        return $this->accessToken($account);
    }

    private function headers(Account $account, string $accept = 'application/json'): array
    {
        $token = $this->accessToken($account);
        if ($token === '') {
            throw new \RuntimeException($this->displayName() . ' 账号缺少访问令牌，请重新登录');
        }

        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => $accept,
            'Content-Type'  => 'application/json',
            // 真实客户端每个上游请求都带 x-session-id（见 src/proxy/upstream.ts
            // buildTraceHeaders）。签名 V4 也要求该头存在，否则跳过签名。
            'x-session-id'  => uuid(),
        ];
    }

    /**
     * 控制面请求头（额度 balance / preview 等 zcode.z.ai 接口）。
     *
     * 与 headers() 的差异（实测必要，缺一即取不到额度）：
     *   - 授权用 OAuth JWT 而非 provider 的 access_token；
     *   - 必须携带 X-Device-Mid，否则上游返回 400 parameter error；
     *   - 必须携带 X-ZCode-App-Version / X-Platform 桌面端标识，
     *     否则上游返回 200 但 plans / balances 为空。
     */
    private function controlPlaneHeaders(Account $account): array
    {
        $jwt = $this->jwt($account);
        if ($jwt === '') {
            throw new \RuntimeException($this->displayName() . ' 账号缺少 OAuth JWT，请重新登录');
        }

        $headers = [
            'Authorization'       => 'Bearer ' . $jwt,
            'Accept'              => 'application/json',
            'Content-Type'        => 'application/json',
            'X-ZCode-App-Version' => self::APP_VERSION,
            'X-Platform'          => self::CLAIM_PLATFORM,
            'x-session-id'        => uuid(),
        ];

        $mid = $this->claimDeviceMid($account);
        if ($mid !== '') {
            $headers['X-Device-Mid'] = $mid;
        }

        return $headers;
    }

    /** 控制面基址（额度 / 领取统一走 zcode.z.ai，不区分国内/国际） */
    private function controlPlaneHost(): string
    {
        return self::CLAIM_ORIGIN;
    }

    // ---------------------------------------------------------
    // 客户端请求签名 V4（见 App\Support\ClientSigning）
    // ---------------------------------------------------------

    /**
     * 已具备签名凭据时的签名器；否则返回 null。
     * 凭据取 access_token（形如 `{apiKeyId}.{apiKeySecret}`）。
     */
    private function signer(Account $account): ?ClientSigning
    {
        if (!ClientSigning::available()) {
            return null;
        }

        $credential = $this->accessToken($account);
        if ($credential === '') {
            return null;
        }

        return new ClientSigning($this->http(), function (string $message): void {
            Logger::info($this->id() . ' ' . $message);
        });
    }

    /**
     * 尝试为请求头注入签名。凭据不可签名或未启用 sodium 时原样返回。
     *
     * @param array<string, string> $headers
     * @return array{pairs: array<string, string>, signer: ?ClientSigning, credential: string}
     */
    private function signHeaders(Account $account, string $url, array $headers): array
    {
        $credential = $this->accessToken($account);
        $signer = $this->signer($account);
        if ($signer === null) {
            return ['pairs' => $headers, 'signer' => null, 'credential' => $credential];
        }

        $outcome = $signer->signWithStatus($url, $headers, $credential, self::APP_VERSION);

        return ['pairs' => $outcome['pairs'], 'signer' => $signer, 'credential' => $credential];
    }

    /**
     * 上游返回签名校验失败（401 VERIFY_*）时按参考实现的阶梯处理：
     * 第一次失效缓存密钥并重新握手；由调用方决定是否重试。
     */
    private function handleVerifyFailure(?ClientSigning $signer, string $url, string $credential, int $status, string $body, int $attempt): bool
    {
        if ($signer === null || !$signer->isVerifyFailure($status, $body)) {
            return false;
        }

        if ($attempt <= 1) {
            $signer->invalidate($url, $credential);
            return true;
        }

        $signer->setBypass($url, $credential);
        return false;
    }

    // ---------------------------------------------------------
    // 对话
    // ---------------------------------------------------------

    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        // ⚠️ 回落时必须知道「已经吐过 delta 没有」：一旦开始输出正文就绝不能再换通路，
        // 否则客户端会收到两段拼接的正文。用一个 by-ref 标志包一层回调实现。
        //
        // ⚠️ 第二参数 $meta 必须声明：`GatewayService::complete()` 传进来的回调签名是
        // `function (string $delta, array $raw)` —— **两个都是必需参数（无默认值）**。
        // 这里只声明一个参数再转发 `$onDelta($delta)` 会在 PHP 8 直接抛
        // `ArgumentCountError: Too few arguments ... 1 passed ... and exactly 2 expected`，
        // 该错误被上层 catch 成 UPSTREAM 并重试，最终整个 zcode 通路恒失败（实测 2026-09-30）。
        // 之前没暴露是因为只有非流式路径才走到这里，且历史上从未拿 zcode 走非流式。
        $emitted = false;
        // 把「是否已向客户端吐过内容」同步到实例属性：`chatWithKey()` 需要它来决定
        // 能否在下游异常时回落 OpenAI 通道 —— 已吐过还回落会让正文拼接重复（实测 `你好你好`）。
        // ⚠️ 闭包不能是 static —— static 闭包内没有 $this，无法同步该标记。
        $this->emittedAny = false;
        // 🔴 **2026-10-01 血案（本行是「色情小说写一半就断 / 拒绝话术被直通下发」的最终根因）**：
        //    本闭包原先是 `function (...): void` 且**直接丢弃** `$onDelta()` 的返回值：
        //        $onDelta((string) $delta, $meta);      // ← 返回值被丢掉
        //    而网关侧的首帧探测回调**靠返回值中止上游读取**：
        //        if (self::looksLikeRefusal($headBuf)) { $refusal = true; return false; }
        //    回调返回 false 后，`parseAnthropicSse()` 里那层 `if ($onDelta(...) === false) return false;`
        //    本应立即停止读取 SSE —— 但 abort 信号在**这一层**就被 `: void` 吞掉了，
        //    于是 `$onDelta($text, ...)` 的 `=== false` 永远为假、上游继续读、流不中止。
        //
        //    后果特别隐蔽：网关回调里 `$headDone` 已置 true（判定已做完），
        //    后续每一帧都走「已过判定窗口 → 直通」分支 → **拒绝话术被原样下发**。
        //    实测（AIGW_DEBUG_PROBE=1）：日志有 `[PROBE-DEBUG] 窗口内判拒绝 len:5`（判据命中了！）
        //    却仍出 `fin=stop len=314` 的拒绝正文、`resample=0` —— 判据对了但中止没生效。
        //
        //    ⚠️ 修法必须**返回** `$onDelta()` 的结果，且闭包**不能**声明 `: void`
        //    （PHP 中 void 函数内部的 return 值会被丢弃，`return $onDelta(...)` 在 void 下无意义）。
        //    调用方 `parseAnthropicSse` / `parseOpenAiSse` 已有 `=== false` 判定，无需改动。
        $wrapped = function (?string $delta, array $meta = []) use (&$emitted, $onDelta) {
            if (is_string($delta) && $delta !== '') {
                $emitted = true;
                $this->emittedAny = true;
            }
            // 透传返回值 —— 这**就是**中止信号通道，丢一次 = 破甲探测全线失效。
            return $onDelta((string) $delta, $meta);
        };

        // 首选：派生 coding-plan API Key，走标准 coding 通道（**无需验证码**）。
        // 这是 start-plan 账号也成立的通用解法 —— 见 codingPlanKey() 的链路说明。
        $key = $this->codingPlanKey($account);
        if ($key !== '') {
            try {
                return $this->chatWithKey($account, $request, $wrapped, $key);
            } catch (\Throwable $e) {
                // ✅ 派生 key 走**独立计费池**，既不消耗也不继承 start-plan 的每日免费额度
                // → 对纯 start-plan 账号会恒定回 `1113 无可用资源包`。
                // 此时必须自动回落 `chatStartPlan()`（zcode-plan 代理）——**那才是消耗
                // start-plan 免费额度的唯一通路**。该通路需要阿里云验证码，现已由
                // solveCaptcha() 无痕现场求解（见 chatStartPlan 内注释）。
                // 前提：① 确属 start-plan；② 确属额度类错误；③ 尚未吐出任何正文；
                //       ④ 该通路近期未被 WAF 判死（见 planPathBlockedAt 负缓存）。
                if (!$emitted && $this->isStartPlan($account) && $this->isQuotaError($e)
                    && !$this->planPathCooling()) {
                    try {
                        return $this->chatStartPlan($account, $request, $wrapped);
                    } catch (\Throwable $inner) {
                        // 记录负缓存：zcode-plan 代理被阿里云 WAF 硬拦（3012）。
                        // 没有它，每个请求都要白等一次无痕求解（约 10~16 秒）才失败。
                        if ($this->isWafBlock($inner)) {
                            $this->markPlanPathBlocked();
                        }

                        throw $e; // 对外仍抛原始「额度不足」，语义更准确
                    }
                }
                throw $e;
            }
        }

        // 兜底：key 派生失败时才走 start-plan 代理（需要阿里云验证码，一次性 90s）。
        if ($this->isStartPlan($account)) {
            return $this->chatStartPlan($account, $request, $wrapped);
        }

        return $this->chatWithKey($account, $request, $onDelta, $this->accessToken($account));
    }

    /**
     * 判定异常是否为「额度/资源包」类失败（11xx 计费码）。
     *
     * 判据取自异常消息文本 —— `parseError()` 已把 1113 族统一成 QUOTA 文案，
     * 但异常本身不带分类，所以只能按关键词识别。均为 zcode 上游固定文案。
     */
    private function isQuotaError(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        foreach (['1113', 'insufficient balance', '无可用资源包', '余额不足', 'no resource package'] as $needle) {
            if (stripos($msg, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判定异常是否为阿里云 ESA WAF 拦截（3012）。
     *
     * 实测（2026-09-30）：`POST /api/v1/zcode-plan/anthropic/v1/messages` 只要 body 里
     * 带**非空 `messages`**，无论验证码多新鲜、是否同源、TLS 指纹如何，一律回
     * `405 {"code":3012,"msg":"request has been blocked due to unusual activity."}`；
     * 而**同一枚 param** 打 `billing/claim` 却稳定 `200` —— 证明这不是验证码问题，
     * 是服务端对该端点下发消息路径的硬性 WAF 策略。
     */
    private function isWafBlock(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return stripos($msg, '3012') !== false || stripos($msg, 'unusual activity') !== false;
    }

    /** zcode-plan 代理是否处于 WAF 负缓存冷却期（避免每次对话白等一次求解） */
    private function planPathCooling(): bool
    {
        return $this->planPathBlockedAt > 0
            && (time() - $this->planPathBlockedAt) < self::PLAN_PATH_COOLDOWN;
    }

    /** 记录 zcode-plan 代理被 WAF 拦截的时刻 */
    private function markPlanPathBlocked(): void
    {
        $this->planPathBlockedAt = time();
    }

    /**
     * 用凭据直连标准通道。
     *
     * 实测（2026-09-30）两条都通，且**都不需要阿里云验证码**：
     *   - `POST {anthropicBase}/v1/messages`（Anthropic Messages 协议，主用：支持
     *     思考块 / 速度档 / 缓存标记；`x-api-key` 或 `Bearer` 均可）
     *   - `POST {coding/paas}/v4/chat/completions`（OpenAI 兼容，兜底）
     *
     * 两条共用 `parseAnthropicSse()` / `parseOpenAiSse()` 已有解析器。
     */
    private function chatWithKey(Account $account, ChatRequest $request, callable $onDelta, string $key): Chunk
    {
        if ($key === '') {
            throw new \RuntimeException($this->displayName() . ' 账号缺少访问令牌，请重新登录', 401);
        }

        $url = rtrim($this->anthropicBase(), '/') . '/v1/messages';

        $headers = [
            'x-api-key'           => $key,
            'anthropic-version'   => '2023-06-01',
            'Content-Type'        => 'application/json',
            'Accept'              => 'text/event-stream',
            'X-Device-Mid'        => $this->claimDeviceMid($account),
            'X-ZCode-App-Version' => self::APP_VERSION,
            'X-Platform'          => self::CLAIM_PLATFORM,
            'User-Agent'          => 'ZCode/' . self::APP_VERSION,
            'X-Title'             => 'Z Code@cli',
            'HTTP-Referer'        => self::CLAIM_ORIGIN,
        ];

        $payload = $this->toAnthropicPayload($request);

        try {
            return $this->parseAnthropicSse($url, $payload, $headers, $account, $onDelta);
        } catch (\Throwable $e) {
            // Anthropic 通道失败 → 退回 OpenAI 兼容通道（同一凭据，实测同样可用）
            // ⚠️ 回落前必须确认**尚未向客户端吐过任何内容**：一旦 Anthropic 通道已经
            // 下发过正文，回落重跑会让客户端收到两段拼接的正文（实测重复成 `你好你好`）。
            // 已吐过就原样抛出，绝不重跑。
            if ($this->emittedAny) {
                throw $e;
            }
            $fallback = rtrim($this->openaiBase(), '/') . '/chat/completions';
            $openaiHeaders = [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'text/event-stream',
                'x-session-id'  => uuid(),
            ];
            $openaiPayload = $request->toUpstreamPayload([
                'stream' => true,
                'model'  => $request->model !== '' ? $request->model : self::DEFAULT_MODEL,
            ]);

            try {
                return $this->parseOpenAiSse($fallback, $openaiPayload, $openaiHeaders, $account, $onDelta);
            } catch (\Throwable) {
                throw $e; // 两条都失败：抛首条（Anthropic 通道）错误，信息更具体
            }
        }
    }

    /** Anthropic 兼容基址（国内 open.bigmodel.cn，国际 api.z.ai） */
    private function anthropicBase(): string
    {
        return $this->isIntlProvider() ? self::ZAI_ANTHROPIC_BASE : self::BIGMODEL_ANTHROPIC_BASE;
    }

    /**
     * start-plan 对话：POST {origin}/api/v1/zcode-plan/anthropic/v1/messages
     *
     * 协议为 **Anthropic Messages**（不是 OpenAI），并要求：
     *   - `Authorization: Bearer {JWT}`（access_token 会被 401）；
     *   - 运行时头 `X-Device-Mid` / `X-ZCode-App-Version` / `X-Platform`；
     *   - **阿里云验证码** `X-Aliyun-Captcha-Verify-Param`。
     *
     * ⚠️ 缺验证码时阿里云 ESA WAF 直接回
     * `405 {"code":3012,"msg":"request has been blocked due to unusual activity."}`
     * （触发条件 = body 里有非空 `messages`；只带 `model` 则 400 3001，说明端点本身可达）。
     * 验证码必须在真实客户端里由指纹组件现场求解，服务端无法代跑 —— 因此本方法在
     * 缺验证码时**明确报错**，而不是让用户看到含糊的「网络错误」。
     */
    private function chatStartPlan(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        // 令牌过期必须**优先**报出：否则会被下面的验证码判定掩盖，把「登录失效」
        // 误报成「缺验证码」，让用户白折腾。国际版实测 access_token 带 exp（1 小时）。
        // ⚠️ 异常 code 必须带 401：网关用 parseError() 分类，而 parseError 按
        // 「状态码 + 正文」判定；不带码的消息只能落到默认分支，会被误归 UPSTREAM
        // （可换号重试）而不是 SESSION_DEAD（触发重登）—— 复测时正是这个坑。
        $expired = $this->tokenExpiredAt($account);
        if ($expired > 0 && $expired < time()) {
            throw new \RuntimeException(sprintf(
                '%s 访问令牌已于 %s 过期，请重新登录（token expired）',
                $this->displayName(),
                date('Y-m-d H:i:s', $expired)
            ), 401);
        }

        $token = $this->planToken($account);
        if ($token === '') {
            throw new \RuntimeException($this->displayName() . ' 账号缺少 OAuth JWT，请重新登录（token expired）', 401);
        }

        $captcha = trim((string) $account->cred('captcha', $account->meta('captcha', '')));
        if ($captcha === '') {
            // ✅ 自动补验证码：无痕验证零交互（CaptchaSolver）。
            // 这条通路（zcode-plan 代理）**正是消耗 start-plan 每日免费额度的唯一通道** ——
            // 派生出的 coding-plan API Key 不继承该额度（回 1113），所以当派生 key 打不通时，
            // 这里必须能自动过验证码，否则 start-plan 账号永远用不上自己那份免费额度。
            $captcha = $this->solveCaptcha();
        }
        if ($captcha === '') {
            // 带 405：与上游 WAF 的 3012 响应同码，parseError 才能归 CLIENT。
            throw new \RuntimeException(sprintf(
                '%s 当前为 start-plan（免费体验），对话需通过 %s 并携带阿里云验证码；'
                . '本次自动过验证码失败（无痕风控未放行或本机无可用浏览器），'
                . '上游 WAF 会以 3012 拒绝。请稍后重试，或改用 coding-plan API Key 通道。',
                $this->displayName(),
                self::PLAN_ANTHROPIC_PATH
            ), 405);
        }

        $url = rtrim($this->planOrigin(), '/') . self::PLAN_ANTHROPIC_PATH;

        $headers = [
            'Authorization'    => 'Bearer ' . $token,
            'Content-Type'     => 'application/json',
            'Accept'           => 'text/event-stream',
            'anthropic-version' => '2023-06-01',
            'X-Device-Mid'     => $this->claimDeviceMid($account),
            'X-ZCode-App-Version' => self::APP_VERSION,
            'X-Platform'       => self::CLAIM_PLATFORM,
            'User-Agent'       => 'ZCode/' . self::APP_VERSION,
            'X-Title'          => 'Z Code@cli',
            'HTTP-Referer'     => self::CLAIM_ORIGIN,
            self::CAPTCHA_HEADER => $captcha,
            self::CAPTCHA_REGION_HEADER => (string) ($this->captchaConfig()['region'] ?? 'cn'),
        ];

        $payload = $this->toAnthropicPayload($request);

        return $this->parseAnthropicSse($url, $payload, $headers, $account, $onDelta);
    }

    /**
     * 错误分类覆盖（zcode 专属）。
     *
     * ⚠️ 为什么必须覆盖：`AbstractProvider::parseError` 会把「HTTP 200 + 业务码」
     * 与「非 4xx/5xx 的异常消息」一律落到 `$status >= 500 || $status === 0`
     * → 归为 **UPSTREAM**，而 UPSTREAM 在 `ErrorCategory::isRetryable` 里是「可换号重试」。
     * 对 zcode 的两类确定性失败来说这是**错误引导**：
     *
     *   ① 缺阿里云验证码（start-plan 专有）
     *      上游 ESA WAF 回 `405 {"code":3012,"msg":"request has been blocked due to
     *      unusual activity."}`。换账号、等冷却都不会好 —— 必须补验证码或以
     *      coding-plan API Key 重登，属**请求/凭据侧**问题，应归 CLIENT。
     *   ② 令牌过期（国际版 access_token 带 exp，默认 1 小时）
     *      必须归 SESSION_DEAD 触发自动重登，而不是 UPSTREAM 让网关抱着废令牌换号重试。
     */
    public function parseError(int $status, string $body): ?array
    {
        $lower = strtolower($body);

        // 本地预检抛出的「缺验证码 / 令牌过期」提示：**原样透传**，不要被下面的
        // 3012 分支改写成通用 WAF 文案（否则用户看不到真正原因）。
        if (str_contains($body, self::CAPTCHA_HEADER) || str_contains($body, 'start-plan')) {
            return ['code' => ErrorCategory::CLIENT, 'message' => $body];
        }

        // 阿里云 ESA WAF 风控（缺验证码）：明确且不可自愈 → CLIENT，不惩罚账号。
        if (str_contains($lower, '3012') || str_contains($lower, 'unusual activity')) {
            return [
                'code'    => ErrorCategory::CLIENT,
                'message' => 'ZCode 请求被上游风控拦截（code 3012）：start-plan 对话必须携带阿里云验证码 '
                    . self::CAPTCHA_HEADER . '，请在 ZCode 桌面端完成一次对话后导出该验证码并填入账号凭据。',
            ];
        }

        // 令牌过期 / 失效：触发重登流程，而不是当上游抖动。
        if (str_contains($lower, 'token expired') || str_contains($lower, '令牌已过期')
            || str_contains($lower, 'token is not active') || str_contains($lower, '未登录')) {
            return ['code' => ErrorCategory::SESSION_DEAD, 'message' => $this->extractMessage($body)];
        }

        // 额度耗尽 / 无资源包（code 1113）：**账号侧**问题，换号/充值才有解，
        // 绝不能归 UPSTREAM —— 那会让网关以为"上游抖动"而反复重试同一个空账号。
        // ⚠️ 实测（2026-09-30 二轮）：coding-plan API Key 走**独立计费池**，
        // 既不消耗也不继承 start-plan 的每日免费额度（key 调用成功但 `used` 保持 0）；
        // 而 `zcode-plan` 代理（唯一消耗该额度的通路）被阿里云 WAF 以 3012 硬拦，
        // 连有效验证码 + 同源浏览器都过不去。故该账号的免费额度目前**取不到**。
        if (str_contains($lower, '1113') || str_contains($lower, 'insufficient balance')
            || str_contains($body, '余额不足') || str_contains($lower, 'no resource package')
            || str_contains($body, '无可用资源包')) {
            return [
                'code'    => ErrorCategory::QUOTA,
                'message' => 'ZCode 账号额度不足或无可用资源包（code 1113）：两条通路都已尝试 ——'
                    . '① coding-plan API Key 走独立计费池，不继承 start-plan 的每日免费额度；'
                    . '② zcode-plan 代理（唯一消耗该额度的通路）被上游 WAF 拦截，验证码无法绕过。'
                    . '请为该账号充值/升级 Coding Plan，或更换账号。',
            ];
        }

        return parent::parseError($status, $body);
    }

    /**
     * OpenAI 形态 → Anthropic Messages 形态。
     * start-plan 只认 Anthropic 协议；system 需抽到顶层 `system` 字段。
     */
    private function toAnthropicPayload(ChatRequest $request): array
    {
        $payload = $request->toUpstreamPayload([
            'model'      => $request->model !== '' ? $request->model : self::DEFAULT_MODEL,
            'stream'     => true,
            'max_tokens' => 8192,
        ]);

        $messages = [];
        foreach ((array) ($payload['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $messages[] = $message;
        }

        // ── messages 形态翻译（OpenAI → Anthropic）──────────────────────────
        // ⚠️ 与下面的 toolsToAnthropic() **必须配套**，缺一即静默半失效：
        //    只翻译 tools 定义、不翻译 messages 里的工具多轮形态，会让**任何带工具
        //    上下文的多轮请求**在主通道被上游整单拒收（实测 400）：
        //      `[400][Invalid request body: body.messages.N.role: Input should be
        //       'user', 'assistant' or 'system']`
        //    成因：Anthropic 的工具结果**不是独立角色**（没有 OpenAI 的 `role:tool`），
        //    而是包进 `user` 消息的 `tool_result` 内容块；工具调用也不是
        //    `assistant.tool_calls` 数组，而是 `assistant.content[].tool_use` 块。
        //
        // 血案（2026-10-01）：TraeWork 客户端（内网某台机器）发来的 agent 多轮对话
        // 含 `role:tool`，被原样透传 → 主通道 400 **秒拒**（实测 281ms）→ 网关
        // 回落 OpenAI 兜底通道 → 大上下文（39KB 请求体）下兜底通道挂起到 280s
        // 总超时 → 用户侧表现为「跑了五分钟自己停止了」、且最终错误却是那条秒回的 400。
        // 正确翻译后同一请求主通道 **200 通过**（实测 8.6s / 184 帧），不再回落。
        $payload['messages'] = self::messagesToAnthropic($messages);

        // ── tools 形态翻译（OpenAI → Anthropic）────────────────────────────
        // ⚠️ Anthropic 的 tools 结构与 OpenAI **不同**：
        //   OpenAI   ：{"type":"function","function":{"name":..,"parameters":{..}}}
        //   Anthropic：{"name":..,"input_schema":{..}}   （name 在顶层，无 type/function 包装）
        // 直接透传 OpenAI 形态会被上游整单拒收（实测 400）：
        //   `body.tools.0.name: Field required; body.tool_choice: Input should be a valid dictionary`
        // 此前 toAnthropicPayload 只搬 messages、对 tools 一字不改 → **每次带 tools 的请求
        // 都要先白跑一次 400 才回落到 OpenAI 兜底通道**（多耗一轮 RTT）。
        // 这里做显式翻译，让主通道一次命中。
        if (!empty($request->tools)) {
            $payload['tools'] = self::toolsToAnthropic($request->tools);
            $payload['tool_choice'] = self::toolChoiceToAnthropic($payload['tool_choice'] ?? null);
        }

        return $payload;
    }

    /**
     * OpenAI messages → Anthropic messages（消息级协议翻译）。
     *
     * Anthropic 只认三种角色：`user` / `assistant` / `system`。OpenAI 的工具多轮
     * 形态与它**结构完全不同**，直接透传必被整单拒收：
     *
     *   OpenAI 工具调用：{role:assistant, content:"", tool_calls:[{id, type:function,
     *                     function:{name, arguments:"{...json...}"}}]}
     *   Anthropic 对应  ：{role:assistant, content:[{type:tool_use, id, name, input:{...}}]}
     *                     （arguments 是 JSON **字符串** → 必须解码成 input 对象）
     *
     *   OpenAI 工具结果：{role:tool, tool_call_id, content:"..."}
     *   Anthropic 对应  ：{role:user, content:[{type:tool_result, tool_use_id,
     *                     content:"..."}]}   ← 角色必须变成 user，无 role:tool
     *
     * ⚠️ 已翻译过（Anthropic 形态）的消息**原样保留**，保证幂等 —— 否则重采样/回落
     *    复用同一 payload 时会把 tool_result 再包一层。
     * ⚠️ 纯文本消息**一字不改**（含 system）：上游实测接受 messages 内 role:system，
     *    错误只指向 role:tool 那一条，故不做 system 抽取，维持最小改动面。
     * ⚠️ 相邻的 tool_result 多条（并行工具）会合并进同一条 user 消息：Anthropic 要求
     *    tool_result 块成组出现在紧随 assistant 的 user 消息里，拆成多条 user 会被拒。
     *
     * @param list<array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    private static function messagesToAnthropic(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? '');

            // ── OpenAI 工具结果 → Anthropic user.tool_result ──────────────
            if ($role === 'tool') {
                $block = [
                    'type'        => 'tool_result',
                    'tool_use_id' => (string) ($message['tool_call_id'] ?? ''),
                    'content'     => self::toolResultContent($message['content'] ?? ''),
                ];
                // 与上一条 user 消息（同为本次工具结果）合并，保证 tool_result 成组
                $last = $out !== [] ? $out[count($out) - 1] : null;
                if (is_array($last) && ($last['role'] ?? '') === 'user'
                    && is_array($last['content'] ?? null) && self::isToolResultBlocks($last['content'])) {
                    $out[count($out) - 1]['content'][] = $block;
                } else {
                    $out[] = ['role' => 'user', 'content' => [$block]];
                }
                continue;
            }

            // ── OpenAI 工具调用 → Anthropic assistant.content[].tool_use ──
            if ($role === 'assistant' && !empty($message['tool_calls']) && is_array($message['tool_calls'])) {
                $blocks = [];
                // assistant 可能同时带正文（思维收尾 + 工具调用），正文作 text 块置于前
                $text = self::plainText($message['content'] ?? '');
                if ($text !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $text];
                }
                foreach ($message['tool_calls'] as $call) {
                    if (!is_array($call)) {
                        continue;
                    }
                    $fn   = is_array($call['function'] ?? null) ? $call['function'] : [];
                    $args = $fn['arguments'] ?? '';
                    // arguments 是 JSON 字符串（可能是空串/`{}`）→ 解成对象；
                    // Anthropic 的 input 必须是对象，空则给 `{}`（stdClass 保证编码成对象）
                    $input = is_array($args) ? $args : (json_decode((string) $args, true) ?: new \stdClass());
                    if (!is_array($input) && !($input instanceof \stdClass)) {
                        $input = new \stdClass();
                    }
                    $blocks[] = [
                        'type'  => 'tool_use',
                        'id'    => (string) ($call['id'] ?? ''),
                        'name'  => (string) ($fn['name'] ?? ''),
                        'input' => $input,
                    ];
                }
                if ($blocks !== []) {
                    $out[] = ['role' => 'assistant', 'content' => $blocks];
                    continue;
                }
            }

            // ── 其余（system / user / 无工具调用的 assistant）原样保留 ──
            $out[] = $message;
        }

        return $out;
    }

    /** 判断 content 是否为一组 tool_result 块（用于合并相邻工具结果） */
    private static function isToolResultBlocks(mixed $content): bool
    {
        if (!is_array($content) || $content === []) {
            return false;
        }
        foreach ($content as $b) {
            if (!is_array($b) || (string) ($b['type'] ?? '') !== 'tool_result') {
                return false;
            }
        }
        return true;
    }

    /**
     * 工具结果正文 → Anthropic `tool_result.content`。
     * 字符串原样；多模态数组压平为文本块列表（Anthropic 接受 string 或 content 块数组）。
     */
    private static function toolResultContent(mixed $content): mixed
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return (string) $content;
        }
        // 已是 Anthropic 内容块数组 → 原样
        $blocks = [];
        foreach ($content as $item) {
            if (is_string($item)) {
                $blocks[] = ['type' => 'text', 'text' => $item];
                continue;
            }
            if (is_array($item) && isset($item['text'])) {
                $blocks[] = ['type' => 'text', 'text' => (string) $item['text']];
            }
        }
        return $blocks !== [] ? $blocks : '';
    }

    /** 把字符串 / 多模态数组统一压成纯文本 */
    private static function plainText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $out = '';
        foreach ($content as $item) {
            if (is_string($item)) {
                $out .= $item;
            } elseif (is_array($item) && isset($item['text'])) {
                $out .= (string) $item['text'];
            }
        }
        return $out;
    }

    /**
     * OpenAI tools → Anthropic tools。
     *
     * 差异：Anthropic 把函数名放**顶层 `name`**、参数模式放 **`input_schema`**，
     * 没有 OpenAI 的 `type:function` / `function` 包装层。
     * 已是 Anthropic 形态（顶层有 name + input_schema）的条目**原样保留**，保证幂等。
     *
     * @param array<int,mixed> $tools
     * @return list<array<string,mixed>>
     */
    private static function toolsToAnthropic(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }

            // 已是 Anthropic 形态 → 原样保留
            if (isset($tool['name']) && array_key_exists('input_schema', $tool)) {
                $out[] = $tool;
                continue;
            }

            $fn = is_array($tool['function'] ?? null) ? $tool['function'] : $tool;
            $name = trim((string) ($fn['name'] ?? ''));
            if ($name === '') {
                // 没有函数名 → 上游必拒（`body.tools.N.name: Field required`），
                // 与其带着必死形态发出，不如直接丢弃该条（其余工具照常生效）。
                continue;
            }

            $schema = $fn['parameters'] ?? null;
            // Anthropic 要求 input_schema 为对象且**必须**有 type:object
            if (!is_array($schema) || $schema === []) {
                $schema = ['type' => 'object', 'properties' => new \stdClass()];
            } elseif (!isset($schema['type']) && !isset($schema['$ref']) && !isset($schema['anyOf']) && !isset($schema['oneOf'])) {
                // 空模式之外的模式补顶层 type（语义无损：函数参数顶层只能是对象）
                $schema = ['type' => 'object'] + $schema;
            }

            $entry = [
                'name'         => $name,
                'input_schema' => $schema,
            ];
            $desc = trim((string) ($fn['description'] ?? ''));
            if ($desc !== '') {
                $entry['description'] = $desc;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * OpenAI tool_choice → Anthropic tool_choice。
     *
     * OpenAI `"auto"` / `"none"` / `"required"` 是**字符串**，而 Anthropic 要求
     * tool_choice 是**对象**（`{"type":"auto"|"any"|"tool"}`），字符串会被判为
     * `body.tool_choice: Input should be a valid dictionary` 整单拒收。
     * 指定函数名时 OpenAI 用 `{"type":"function","function":{"name":..}}`，
     * Anthropic 用 `{"type":"tool","name":..}`。
     */
    private static function toolChoiceToAnthropic(mixed $choice): mixed
    {
        if (is_array($choice)) {
            // 已是 Anthropic 形态（有 type）→ 原样；否则按 OpenAI 对象形态翻译
            $type = (string) ($choice['type'] ?? '');
            if ($type === 'function') {
                $name = trim((string) ($choice['function']['name'] ?? ''));
                return $name !== '' ? ['type' => 'tool', 'name' => $name] : ['type' => 'auto'];
            }
            return $choice;
        }

        if (!is_string($choice) || $choice === '') {
            return null;
        }

        return match (strtolower($choice)) {
            'auto'     => ['type' => 'auto'],
            'required',
            'any'      => ['type' => 'any'],
            'none'     => ['type' => 'auto'], // Anthropic 无 none：退化为 auto，不因它整单拒收
            default    => ['type' => 'tool', 'name' => $choice],
        };
    }

    /**
     * 解析 Anthropic Messages 事件流。
     * 事件形如 `event: content_block_delta` + `data: {...}`，正文在
     * `delta.text`（content_block_delta）/ `content_block.text`（content_block_start）。
     */
    private function parseAnthropicSse(string $url, array $payload, array $headers, Account $account, callable $onDelta): Chunk
    {
        $chunk = new Chunk(model: (string) $account->meta('model', ''));
        $chunk->model = (string) ($payload['model'] ?? '');

        // 工具调用缓冲**每条流独立**：不复位会让上一轮/上一条重采样的残留工具被重复下发
        $this->anthropicToolBuf = [];
        // 工具名映射必须同步复位，否则残留 index 会串到下一轮的 tool 块上
        $this->anthropicToolName = [];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException($this->displayName() . ' 请求体序列化失败');
        }

        $pairs = [];
        foreach ($headers as $k => $v) {
            $pairs[$k] = $v;
        }

        $info = $this->http()->withHeaders($pairs)->timeout(HttpClient::STREAM_SAFE_TIMEOUT, 20)
            ->stream('POST', $url, $body, function (string $data) use (&$chunk, $onDelta) {
                $decoded = json_decode($data, true);
                if (!is_array($decoded)) {
                    return null;
                }

                $type = (string) ($decoded['type'] ?? '');

                if ($type === 'error' || isset($decoded['error'])) {
                    $err = $decoded['error'] ?? [];
                    $msg = is_array($err) ? (string) ($err['message'] ?? '') : (string) $err;
                    throw new \RuntimeException(sprintf(
                        '%s %s',
                        $this->id(),
                        $msg !== '' ? $msg : (string) json_encode($decoded, JSON_UNESCAPED_UNICODE)
                    ));
                }

                // 阿里云 WAF / 计划错误信封：{code:3012|3001, msg:"..."}
                if (isset($decoded['code'], $decoded['msg']) && !isset($decoded['type'])) {
                    throw new \RuntimeException(sprintf(
                        '%s %s（上游 code=%s）',
                        $this->id(),
                        (string) $decoded['msg'],
                        (string) $decoded['code']
                    ));
                }

                if ($type === 'message_start') {
                    $chunk->model = (string) ($decoded['message']['model'] ?? $chunk->model);
                    return null;
                }

                if ($type === 'content_block_start') {
                    $block = $decoded['content_block'] ?? [];
                    // ── Anthropic 工具调用块：{"type":"tool_use","id":..,"name":..} ──
                    // Anthropic 与 OpenAI 的工具调用协议**不同**：Anthropic 先下发
                    // content_block_start(tool_use) 声明 id/name，再以多条
                    // content_block_delta(input_json_delta) 分片拼参数。
                    // 不解析它 → 主通道返回工具调用时整段丢失（content 天然为空）。
                    if (is_array($block) && (string) ($block['type'] ?? '') === 'tool_use') {
                        $toolIdx = (int) ($decoded['index'] ?? 0);
                        $this->anthropicToolBuf[$toolIdx] = [
                            'id'        => (string) ($block['id'] ?? ''),
                            'name'      => (string) ($block['name'] ?? ''),
                            'arguments' => '',
                        ];
                        $this->anthropicToolName[$toolIdx] = (string) ($block['name'] ?? '');
                        return null;
                    }

                    // ── Anthropic 思考块：{"type":"thinking","thinking":"…"} ──
                    // Anthropic 的思维走**独立块类型**（thinking），既不是 text 也不是 tool_use。
                    // 早期实现只认 tool_use 与 text → thinking 块被整块丢弃，
                    // 且 `content_block_start` 的 text 分支会把 thinking 块的
                    // `text` 字段（不存在）读成空串，静默 return null。
                    // 结果：依赖 thinking 的客户端（Claude Code 系）全程收不到思维。
                    if (is_array($block) && (string) ($block['type'] ?? '') === 'thinking') {
                        $think = (string) ($block['thinking'] ?? '');
                        if ($think !== '') {
                            $chunk->reasoningContent .= $think;
                            $rRaw = $decoded;
                            $rRaw['_aigw_kind'] = 'reasoning';
                                                        if ($onDelta('', $rRaw) === false) {
                                return false;
                            }
                        }
                        return null;
                    }

                    $text = (string) ($block['text'] ?? '');
                    if ($text !== '') {
                        $chunk->content .= $text;
                        $textRaw = $decoded;
                                                if ($onDelta($text, $textRaw) === false) {
                            return false;
                        }
                    }
                    return null;
                }

                if ($type === 'content_block_delta') {
                    $delta = $decoded['delta'] ?? [];
                    // 参数分片：拼到对应工具调用上
                    if (is_array($delta) && (string) ($delta['type'] ?? '') === 'input_json_delta') {
                        $toolIdx = (int) ($decoded['index'] ?? 0);
                        if (isset($this->anthropicToolBuf[$toolIdx])) {
                            $this->anthropicToolBuf[$toolIdx]['arguments'] .= (string) ($delta['partial_json'] ?? '');
                        }
                        return null;
                    }

                    // ── 思维增量分片：thinking_delta ─────────────────────────
                    // 与 reasoning_content 同语义，统一走 `_aigw_kind=reasoning`。
                    // ⚠️ 必须在 text 分支**之前**判定：thinking_delta 帧没有 `text` 字段，
                    //    落到下面的 text 分支会读成空串而静默丢弃整段思维。
                    if (is_array($delta) && (string) ($delta['type'] ?? '') === 'thinking_delta') {
                        $think = (string) ($delta['thinking'] ?? '');
                        if ($think !== '') {
                            $chunk->reasoningContent .= $think;
                            $rRaw = $decoded;
                            $rRaw['_aigw_kind'] = 'reasoning';
                                                        if ($onDelta('', $rRaw) === false) {
                                return false;
                            }
                        }
                        return null;
                    }

                    $text = (string) ($delta['text'] ?? '');
                    if ($text !== '') {
                        $chunk->content .= $text;
                        $textRaw = $decoded;
                                                if ($onDelta($text, $textRaw) === false) {
                            return false;
                        }
                    }
                    return null;
                }

                // 工具块收尾 → 组装成 OpenAI 规范的 tool_calls 并转发（与 OpenAI 通道同形态）
                if ($type === 'content_block_stop') {
                    $toolIdx = (int) ($decoded['index'] ?? 0);
                    if (isset($this->anthropicToolBuf[$toolIdx])) {
                        $this->flushAnthropicTool($toolIdx, $chunk, $onDelta);
                    }
                    return null;
                }

                if ($type === 'message_delta') {
                    $stop = (string) ($decoded['delta']['stop_reason'] ?? '');
                    if ($stop !== '') {
                        // Anthropic 的 tool_use 对应 OpenAI 的 tool_calls，统一口径
                        $chunk->finishReason = match ($stop) {
                            'end_turn' => 'stop',
                            'tool_use' => 'tool_calls',
                            default    => $stop,
                        };
                    }
                    if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                        $this->applyUsage($chunk, $decoded['usage']);
                    }
                    // 兜底：若工具块未收到 content_block_stop（部分实现会省），在此收口
                    foreach (array_keys($this->anthropicToolBuf) as $idx) {
                        $this->flushAnthropicTool($idx, $chunk, $onDelta);
                    }
                    return null;
                }

                if ($type === 'message_stop') {
                    $chunk->completed = true;
                    // 兜底收口未下发 stop 的工具块
                    foreach (array_keys($this->anthropicToolBuf) as $idx) {
                        $this->flushAnthropicTool($idx, $chunk, $onDelta);
                    }
                    if ($chunk->finishReason === '') {
                        $chunk->finishReason = 'stop';
                    }
                    return false;
                }

                return null;
            });

        if (!empty($info['errno']) && empty($info['aborted']) && $chunk->content === '') {
            throw new \RuntimeException(sprintf(
                '%s 请求失败（传输层）：%s',
                $this->id(),
                (string) $info['error']
            ));
        }

        // ⚠️ 非 SSE 错误信封兜底（**踩过大坑**）。
        // 上游 401/429/405 等错误**不发 SSE**，而是回一个普通 JSON
        // （如 429 `{"type":"error","error":{"code":"1113",...}}`）。
        // 这类 body 没有 `data:` 前缀，会被 HttpClient 的 $emit 全部丢进 rawBody，
        // onChunk 一次都不会被触发 —— 表现为「回调 0 次、正文空、还不抛异常」，
        // 上层误判为「调用成功但模型不吭声」，用户侧则是「毫无反应」。
        // 这里必须显式检查状态码 / 残留 rawBody 并抛出，让 parseError 正常归类。
        $status = (int) ($info['status'] ?? 0);
        $raw = trim((string) ($info['body'] ?? ''));

        if ($status >= 400 || ($raw !== '' && $chunk->content === '' && empty($chunk->toolCalls))) {
            $message = $raw !== '' ? $raw : ('HTTP ' . $status);
            $parsed = $this->parseError($status, $message);
            throw new \RuntimeException(
                (string) ($parsed['message'] ?? $message),
                $status > 0 ? $status : 500
            );
        }

        if (!$chunk->completed && $chunk->finishReason === '' && ($chunk->content !== '' || $chunk->toolCalls !== [])) {
            $chunk->finishReason = $chunk->toolCalls !== [] ? 'tool_calls' : 'stop';
        }

        return $chunk;
    }

    /**
     * 汇总 Anthropic / OpenAI 两种命名的 usage。
     *
     * ⚠️ 这个方法是**补上的历史缺口**：`parseAnthropicSse()` 一直在调 `$this->applyUsage()`
     * （来自 QoderProvider 的写法），但 ZcodeProvider 从未定义它 →
     * 每次 Anthropic 通道走到 `message_delta` 就抛
     * `Call to undefined method ...::applyUsage()`，
     * 被 `chatWithKey()` 的 catch 吞掉后**静默回落** OpenAI 通道重跑一遍。
     *
     * 后果有两个，都不易察觉：
     *   ① 每次对话白跑两遍上游（耗时翻倍、额度双倍消耗）；
     *   ② 前一段已经吐出的正文（Anthropic 通道）**不会回滚**，回落后的正文再接上去
     *      → 客户端看到正文重复（实测 `你好你好`）。
     */
    private function applyUsage(Chunk $chunk, array $usage): void
    {
        $chunk->promptTokens = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? $chunk->promptTokens);
        $chunk->completionTokens = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? $chunk->completionTokens);
        $chunk->totalTokens = (int) ($usage['total_tokens'] ?? 0);
        if ($chunk->totalTokens === 0 && ($chunk->promptTokens > 0 || $chunk->completionTokens > 0)) {
            $chunk->totalTokens = $chunk->promptTokens + $chunk->completionTokens;
        }
        $this->applyCacheUsage($chunk, $usage);
    }

    /**
     * 收口一个 Anthropic 工具调用块 → 组装为 OpenAI 规范的 tool_calls 并转发。
     *
     * 与 OpenAI 通道保持**同一形态**（`{id,index,type:function,function:{name,arguments}}`），
     * 使上层（GatewayService / GatewayController）无需区分上游协议。
     *
     * ⚠️ 必须经 $onDelta 转发：这正是「日志 SUCCESS 但客户端空白」的根因所在 ——
     * 只把工具调用攒进 $chunk 而不回调，网关侧收不到任何信号，
     * 客户端最终只拿到一个空的 finish_reason 收尾帧。
     * 这里用 `_aigw_kind=tool_calls` 标记帧类型（与 OpenAI 通道同约定）。
     */
    private function flushAnthropicTool(int $toolIdx, Chunk $chunk, callable $onDelta): void
    {
        $buf = $this->anthropicToolBuf[$toolIdx] ?? null;
        if ($buf === null) {
            return;
        }
        // 立即摘除，保证幂等：content_block_stop / message_delta / message_stop 三处
        // 都可能触发收口，只应下发一次。
        unset($this->anthropicToolBuf[$toolIdx]);

        $args = (string) ($buf['arguments'] ?? '');
        // Anthropic 的 partial_json 是 JSON 片段串；空参数应下发 `{}` 而不是空串，
        // 否则部分客户端 json_decode 失败、把工具调用判为损坏。
        if (trim($args) === '') {
            $args = '{}';
        }

        $call = [
            'id'       => (string) ($buf['id'] ?? ''),
            // ⚠️ 这里用「本响应内第几个工具调用」计数，**不是** Anthropic 的内容块 index：
            // OpenAI 规范里 tool_calls[].index 指向 tool_calls 数组内的位置，
            // 而 Anthropic 的 block index 把 thinking/text 块也算进去（工具常在 index 1+）。
            // 直接照搬会让客户端的碎片拼接错位。
            'index'    => count($chunk->toolCalls),
            'type'     => 'function',
            'function' => [
                'name'      => (string) ($buf['name'] ?? ''),
                'arguments' => $args,
            ],
        ];

        $chunk->toolCalls[] = $call;

        // 以 OpenAI 通道的帧形态回调：choices[0].delta.tool_calls
        $frame = [
            'id'      => $chunk->id,
            'model'   => $chunk->model,
            'choices' => [[
                'index' => 0,
                'delta' => ['tool_calls' => [$call]],
            ]],
            '_aigw_kind' => 'tool_calls',
        ];

        $onDelta('', $frame);
    }

    /** 从异常消息快速判断是否为签名校验失败（401 VERIFY_*）。 */
    private function looksLikeVerifyFailure(string $message): bool
    {
        if (!str_contains($message, 'VERIFY_SIGNATURE_INVALID') && !str_contains($message, 'VERIFY_APIKEY_EXPIRED')) {
            return false;
        }

        return true;
    }

    // ---------------------------------------------------------
    // 模型
    // ---------------------------------------------------------

    public function models(Account $account): array
    {
        // 上游优先：直接拉 `GET {anthropicBase}/v1/models`（失败自动退 OpenAI 风格端点）
        $upstream = $this->fetchUpstreamModelList($account);
        if (!empty($upstream)) {
            return $this->mergeExtraModels(array_keys($upstream));
        }

        // 回退：上游不可达时用本地库（管理员可手工增删/开关）
        $models = [];
        foreach ($this->modelRepository->all($this->providerKeyOf($account)) as $model) {
            if (!empty($model['enabled'])) {
                $models[] = (string) $model['model_id'];
            }
        }

        if (!empty($models)) {
            return $this->mergeExtraModels($models);
        }

        return $this->mergeExtraModels(array_keys(self::BUILTIN_MODELS));
    }

    /**
     * 把「上游目录未列、但实测可用」的 EXTRA_MODELS 并入 id 列表（按 id 去重）。
     *
     * @param  string[] $ids
     * @return string[]
     */
    private function mergeExtraModels(array $ids): array
    {
        foreach (array_keys(self::EXTRA_MODELS) as $extra) {
            if (!in_array($extra, $ids, true)) {
                $ids[] = $extra;
            }
        }

        return array_values($ids);
    }

    /**
     * 拉取上游真实模型目录：`GET {anthropicBase}/v1/models`。
     *
     * 返回 `id => display_name` 映射；**任何失败一律返回空数组**，由调用方回退
     * 本地目录 —— 绝不让一次上游抖动把整个模型列表清空（同 Qoder 的设计）。
     *
     * 两个端点的取舍：Anthropic 风格 `/v1/models` 带 `display_name`，故优先；
     * 它拉不到时退 OpenAI 风格 `{openaiBase}/models`（只有 id，显示名由内置表补）。
     *
     * ⚠️ 这是**唯一权威**的「有哪些模型」：上游每几周就换代，硬编码必然腐化
     * （Qoder 的教训见 docs/qoder与zcode上游协议.md §12.6）。
     *
     * @return array<string,string>
     */
    private function fetchUpstreamModelList(Account $account): array
    {
        $cacheKey = $account->uid;
        if (isset($this->modelListCache[$cacheKey])) {
            return $this->modelListCache[$cacheKey];
        }

        $key = $this->codingPlanKey($account);
        if ($key === '') {
            return [];
        }

        $headers = [
            'x-api-key'           => $key,
            'anthropic-version'   => '2023-06-01',
            'Accept'              => 'application/json',
            'X-Device-Mid'        => $this->claimDeviceMid($account),
            'X-ZCode-App-Version' => self::APP_VERSION,
            'X-Platform'          => self::CLAIM_PLATFORM,
            'User-Agent'          => 'ZCode/' . self::APP_VERSION,
        ];

        $attempts = [
            rtrim($this->anthropicBase(), '/') . self::MODEL_LIST_PATH_ANTHROPIC,
            rtrim($this->openaiBase(), '/') . self::MODEL_LIST_PATH_OPENAI,
        ];

        foreach ($attempts as $url) {
            try {
                $res = $this->http()->withHeaders($headers)->timeout(20, 10)->get($url);
            } catch (\Throwable $e) {
                Logger::warn("{$this->id()} 模型目录拉取异常", [
                    'url'   => $url,
                    'uid'   => $account->uid,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (!$res->ok()) {
                Logger::warn("{$this->id()} 模型目录拉取失败", [
                    'url'    => $url,
                    'uid'    => $account->uid,
                    'status' => $res->status(),
                ]);
                continue;
            }

            $json = $res->json();

            // ⚠️ HTTP 200 也可能是错误信封（`{"code":1001,...}` / `{"success":false}`），
            // 必须校验真拿到了 data 数组，不能只看状态码。
            $rows = $json['data'] ?? null;
            if (!is_array($rows) || $rows === []) {
                continue;
            }

            $out = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? $row['model_id'] ?? ''));
                if ($id === '' || isset($out[$id])) {
                    continue;
                }
                $out[$id] = trim((string) ($row['display_name'] ?? $row['name'] ?? '')) ?: $id;
            }

            if ($out !== []) {
                $this->modelListCache[$cacheKey] = $out;

                return $out;
            }
        }

        return [];
    }

    /**
     * 模型目录归属的 provider_key：以账号自带值为权威，避免实例注入状态异常时读错区域表
     * （同 QoderProvider::providerKeyOf 的成因，见其注释）。
     */
    private function providerKeyOf(Account $account): string
    {
        $key = trim($account->providerKey);

        return $key !== '' ? $key : $this->id();
    }

    public function modelCatalog(Account $account): array
    {
        // 上游优先：`GET {anthropicBase}/v1/models` 是真·权威（带 display_name）
        $upstream = $this->fetchUpstreamModelList($account);

        if (!empty($upstream)) {
            $out  = [];
            $seen = [];
            foreach ($upstream as $id => $display) {
                $seen[$id] = true;
                $out[] = $this->catalogRow($id, $display);
            }

            // 并入「上游目录未列、但实测可用」的模型（按 id 去重）
            foreach (self::EXTRA_MODELS as $id => [$display, $ctx, $multimodal, $reasoning]) {
                if (!isset($seen[$id])) {
                    $out[] = $this->catalogRow($id, $display);
                }
            }

            return $out;
        }

        // 回退：上游不可达 → 本地库；本地库也空 → 内置表整表兜底
        $enabled = [];
        foreach ($this->modelRepository->all($this->providerKeyOf($account)) as $row) {
            if (!empty($row['enabled'])) {
                $enabled[(string) $row['model_id']] = true;
            }
        }

        $ids = array_keys($enabled) ?: array_keys(self::BUILTIN_MODELS);

        $out = [];
        foreach ($this->mergeExtraModels($ids) as $modelId) {
            $out[] = $this->catalogRow($modelId);
        }

        return $out;
    }

    /**
     * 组装目录行：**元数据全由内置表补**（上游只给 id + display_name）。
     *
     * 上游不下发上下文窗口/多模态/推理，故按 id 查 BUILTIN_MODELS / EXTRA_MODELS；
     * 查不到（上游新出的型号）时**中性回退**：显示名用上游值、能力只给 `stream`，
     * 宁可少报能力也不臆造 —— 上报了 vision 但实际不支持，客户端会把图片发过来直接拒收。
     *
     * @param string      $modelId 模型 id
     * @param string|null $display 上游给的显示名（为空则查内置表）
     *
     * @return array<string,mixed>
     */
    private function catalogRow(string $modelId, ?string $display = null): array
    {
        $meta = self::BUILTIN_MODELS[$modelId] ?? self::EXTRA_MODELS[$modelId] ?? null;

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
    // 额度：/api/v1/zcode-plan/billing/balance + preview
    // ---------------------------------------------------------

    public function credits(Account $account): CreditInfo
    {
        try {
            $url = rtrim($this->controlPlaneHost(), '/') . '/api/v1/zcode-plan/billing/balance'
                . '?plan_id=' . rawurlencode($this->planId($account));

            $response = $this->http()
                ->withHeaders($this->controlPlaneHeaders($account))
                ->timeout(20, 10)
                ->get($url);
        } catch (\Throwable $e) {
            return CreditInfo::failure($e->getMessage());
        }

        if (!$response->ok()) {
            return CreditInfo::failure('上游返回 HTTP ' . $response->status());
        }

        $data = $response->json();
        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;

        // 优先解析 balances[]（每个 entitlement 一个桶，含 remaining_units / total_units）
        $info = $this->parseBalances($payload);
        if ($info !== null) {
            return $info;
        }

        $balance = $payload['balance'] ?? $payload['remain'] ?? $payload['remaining'] ?? null;

        if ($balance === null) {
            // 回退到 preview 接口计算剩余额度
            return $this->creditsFromPreview($account);
        }

        $known = is_numeric($balance);

        return new CreditInfo(
            known: $known,
            balance: $known ? (float) $balance : null,
            detail: 'ZCode 账户额度',
        );
    }

    /**
     * 解析 balance 接口的 balances[]：每个桶形如
     * { show_name, total_units, used_units, remaining_units, period, expires_at }
     * 汇总为总额 / 已用 / 剩余，并逐模型生成 packs（供前端展示）。
     */
    private function parseBalances(array $payload): ?CreditInfo
    {
        $balances = $payload['balances'] ?? null;
        if (!is_array($balances) || $balances === []) {
            return null;
        }

        $total = 0.0;
        $used  = 0.0;
        $remain = 0.0;
        $packs = [];
        $expireAt = 0;

        foreach ($balances as $b) {
            if (!is_array($b)) {
                continue;
            }
            $t = (float) ($b['total_units'] ?? 0);
            $u = (float) ($b['used_units'] ?? 0);
            $r = (float) ($b['remaining_units'] ?? max(0, $t - $u));

            $total  += $t;
            $used   += $u;
            $remain += $r;

            $exp = (int) ($b['expires_at'] ?? 0);
            if ($exp > 0 && ($expireAt === 0 || $exp < $expireAt)) {
                $expireAt = $exp;
            }

            $name = (string) ($b['show_name'] ?? $b['entitlement_id'] ?? 'ZCode');
            $packs[] = [
                'name'   => $name,
                'group'  => (string) ($b['bucket_id'] ?? 'zcode'),
                'limit'  => $t,
                'used'   => $u,
                'remain' => $r,
            ];
        }

        if ($packs === []) {
            return null;
        }

        $detail = sprintf(
            'ZCode 额度：剩余 %s / 共 %s token（每日重置）',
            number_format($remain),
            number_format($total)
        );

        return new CreditInfo(
            known: true,
            balance: $remain,
            detail: $detail,
            packs: $packs,
            usage: ['total' => $total, 'used' => $used, 'remain' => $remain],
            expireAt: $expireAt > 0 ? $expireAt * 1000 : 0,
        );
    }

    private function creditsFromPreview(Account $account): CreditInfo
    {
        try {
            $url = rtrim($this->controlPlaneHost(), '/') . '/api/v1/zcode-plan/billing/preview'
                . '?plan_id=' . rawurlencode($this->planId($account));

            $response = $this->http()
                ->withHeaders($this->controlPlaneHeaders($account))
                ->timeout(20, 10)
                ->get($url);
        } catch (\Throwable $e) {
            return CreditInfo::failure($e->getMessage());
        }

        if (!$response->ok()) {
            return CreditInfo::failure('上游返回 HTTP ' . $response->status());
        }

        $payload = $response->json();
        $payload = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $limit  = $payload['limit'] ?? $payload['total'] ?? null;
        $used   = $payload['used'] ?? null;
        $remain = $payload['remain'] ?? $payload['remaining'] ?? null;

        if ($limit === null && $remain === null) {
            return CreditInfo::unknown('ZCode 未返回额度字段');
        }

        if ($remain === null && $limit !== null && $used !== null) {
            $remain = (float) $limit - (float) $used;
        }

        $packs = [];
        if ($limit !== null || $remain !== null) {
            $packs[] = [
                'name'   => 'Coding Plan',
                'group'  => 'coding-plan',
                'limit'  => (float) ($limit ?? 0),
                'used'   => (float) ($used ?? 0),
                'remain' => (float) ($remain ?? 0),
            ];
        }

        return new CreditInfo(
            known: $remain !== null,
            balance: $remain !== null ? (float) $remain : null,
            detail: 'ZCode Coding Plan 额度',
            packs: $packs,
        );
    }

    private function planId(Account $account): string
    {
        $plan = trim((string) $account->meta('plan_id', ''));
        return $plan !== '' ? $plan : self::DEFAULT_PLAN_ID;
    }

    // ---------------------------------------------------------
    // 动作：测试 / 签到（领取）
    // ---------------------------------------------------------

    public function action(Account $account, string $action, array $params = []): array
    {
        return match ($action) {
            'test'          => $this->actionTest($account),
            'claim_preview' => $this->actionClaimPreview($account),
            'checkin'       => $this->actionCheckin($account, $params),
            'claim'         => $this->actionCheckin($account, $params),
            'set_captcha'   => $this->actionSetCaptcha($account, $params),
            'sync_models'   => $this->actionSyncModels($account),
            default         => ['ok' => false, 'message' => "{$this->displayName()} 不支持动作：{$action}", 'data' => null],
        };
    }

    /**
     * 写入 / 清除 start-plan 对话所需的阿里云验证码凭据。
     *
     * ⚠️ 为什么需要这个动作：start-plan（免费体验计划）的对话端点
     * `{zcode.z.ai}/api/v1/zcode-plan/anthropic/v1/messages` 由阿里云 ESA WAF 保护，
     * 只要 body 里带**非空 messages** 就要求 `X-Aliyun-Captcha-Verify-Param`
     * （实测：只带 model 回 400 3001「端点可达」；带 messages 则 405 3012「风控拦截」；
     * 换任何 TLS 指纹 / User-Agent / 请求体形状都无法绕过）。
     * 该验证码由真实客户端的指纹组件现场求解，**服务端无法代跑**，只能从 ZCode
     * 桌面端抓包或 devtools 里导出后填入。
     *
     * 参数：captcha=空字符串 表示清除。
     */
    private function actionSetCaptcha(Account $account, array $params): array
    {
        if (!array_key_exists('captcha', $params)) {
            $current = trim((string) $account->cred('captcha', $account->meta('captcha', '')));
            return [
                'ok'      => true,
                'message' => $current === '' ? '当前未设置验证码' : '当前已设置验证码（长度 ' . strlen($current) . '）',
                'data'    => ['captcha_set' => $current !== ''],
            ];
        }

        $captcha = trim((string) $params['captcha']);

        // 凭据整体覆盖式写入：先取回现有凭据，改 captcha 后落库。
        $credential = $account->credential;
        if ($captcha === '') {
            unset($credential['captcha']);
        } else {
            $credential['captcha'] = $captcha;
        }

        $this->accountRepository->updateCredential($account->id, $credential);

        return [
            'ok'      => true,
            'message' => $captcha === ''
                ? '验证码已清除（start-plan 对话将不可用）'
                : '验证码已保存，start-plan 对话应当可以打通',
            'data'    => ['captcha_set' => $captcha !== ''],
        ];
    }

    private function actionTest(Account $account): array
    {
        try {
            $url = rtrim($this->controlPlaneHost(), '/') . '/api/v1/zcode-plan/billing/balance'
                . '?plan_id=' . rawurlencode($this->planId($account));

            $response = $this->http()
                ->withHeaders($this->controlPlaneHeaders($account))
                ->timeout(20, 10)
                ->get($url);
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

        return ['ok' => true, 'message' => '连通正常', 'data' => ['status' => $response->status()]];
    }

    /**
     * 查询可领取计划：GET {CLAIM_ORIGIN}/api/v1/zcode-plan/billing/preview
     * 领取控制面固定走 zcode.z.ai（不区分国内/国际），需 OAuth JWT。
     */
    private function actionClaimPreview(Account $account): array
    {
        try {
            $headers = $this->claimPreviewHeaders($account);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
        }

        $url = self::CLAIM_ORIGIN . '/api/v1/zcode-plan/billing/preview'
            . '?app_version=' . rawurlencode(self::APP_VERSION)
            . '&platform=' . rawurlencode(self::CLAIM_PLATFORM);

        try {
            $response = $this->http()
                ->withHeaders($headers)
                ->timeout(20, 10)
                ->get($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '查询可领取计划失败：' . $e->getMessage(), 'data' => null];
        }

        if (!$response->ok()) {
            $parsed = $this->parseError($response->status(), $response->body());
            return [
                'ok'      => false,
                'message' => $parsed['message'] ?? ('查询可领取计划失败（HTTP ' . $response->status() . '）'),
                'data'    => ['status' => $response->status()],
            ];
        }

        $data = $response->json();
        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $plans = $this->parseClaimPlans($payload);

        // ⚠️ `preview` 是「**新的**可领取项」列表，实测（2026-09-30）恒定返回空数组 ——
        // 它本质是「当前没有新东西可领」，而不是「功能坏了」。
        //
        // 因此当 preview 为空时**回落到 `billing/balance`**：那里有账号当前生效的
        // plan（含 plan_id 与逐模型 entitlement），正是 claim 需要的入参来源，
        // 这样前端即便在「无可领项」时也能给出一个真实可提交的 plan_id。
        if ($plans === []) {
            $fromBalance = $this->plansFromBalance($account);
            if ($fromBalance !== []) {
                $plans = $fromBalance;
            }
        }

        // 把 `credits()` 的真实额度一起下发（纯读取、**不需要验证码**），
        // 让前端一屏看清「我还有多少额度、消费通道是否就绪」。
        $quota = $this->quotaSnapshot($account);

        return [
            'ok'      => true,
            'message' => $plans === []
                ? '当前没有可领取或已生效的额度包'
                : sprintf('发现 %d 项额度（含已生效计划）', count($plans)),
            'data'    => array_merge(['plans' => $plans, 'raw' => $payload], $quota),
        ];
    }

    /**
     * 从控制面 `billing/balance` 取当前生效计划，形状归一成与 preview 一致。
     *
     * 用途：`preview` 恒空时兜底，给出真实 plan_id（claim 的必填入参）。
     * `balance` 数据里带 `used_units/remaining_units`，比 preview 更适合展示「还剩多少」。
     */
    private function plansFromBalance(Account $account): array
    {
        if ($this->jwt($account) === '') {
            return [];
        }

        try {
            $url = rtrim($this->controlPlaneHost(), '/') . '/api/v1/zcode-plan/billing/balance'
                . '?app_version=' . rawurlencode(self::APP_VERSION)
                . '&platform=' . rawurlencode(self::CLAIM_PLATFORM);
            $res = $this->http()
                ->withHeaders($this->controlPlaneHeaders($account))
                ->timeout(20, 10)
                ->get($url);
        } catch (\Throwable $e) {
            Logger::warn($this->id() . ' balance 兜底查询失败：' . $e->getMessage());
            return [];
        }

        if (!$res->ok()) {
            return [];
        }

        $json = $res->json();
        $payload = is_array($json['data'] ?? null) ? $json['data'] : $json;
        $plans = $this->parseClaimPlans($payload);

        // 把 balance 的剩余额度补进对应 entitlement（比 grant_units 更有信息量）
        $remaining = [];
        foreach ((array) ($payload['balances'] ?? []) as $b) {
            if (!is_array($b)) {
                continue;
            }
            $key = (string) ($b['entitlement_id'] ?? '');
            if ($key !== '') {
                $remaining[$key] = [
                    'used'    => (float) ($b['used_units'] ?? 0),
                    'remain'  => (float) ($b['remaining_units'] ?? 0),
                    'expires' => $b['expires_at'] ?? null,
                ];
            }
        }

        foreach ($plans as &$p) {
            foreach ($p['entitlements'] as &$ent) {
                $eid = (string) ($ent['entitlement_id'] ?? '');
                if ($eid !== '' && isset($remaining[$eid])) {
                    $ent['used_units']   = $remaining[$eid]['used'];
                    $ent['remain_units'] = $remaining[$eid]['remain'];
                    $ent['expires_at']   = $remaining[$eid]['expires'];
                }
            }
            unset($ent);
        }
        unset($p);

        return $plans;
    }

    /**
     * 额度快照：当前额度桶 + 对话通道就绪状态。**全程只读，不需要任何验证码。**
     *
     * 这是「用户到底还能不能用」这个问题在服务端唯一能诚实回答的部分：
     *   - `packages`     当前额度桶（每模型 grant/used/remain）
     *   - `total_*`      汇总
     *   - `api_key_ready` coding-plan API Key 是否已派生（= 免验证码对话通道是否就绪）
     */
    private function quotaSnapshot(Account $account): array
    {
        $snapshot = [
            'packages'      => [],
            'total_remain'  => null,
            'total_limit'   => null,
            'expire_at'     => null,
            'api_key_ready' => false,
        ];

        try {
            $info = $this->credits($account);
            $packs = is_array($info->packs ?? null) ? $info->packs : [];

            $limit = 0.0;
            $remain = 0.0;
            foreach ($packs as $p) {
                $limit  += (float) ($p['limit'] ?? 0);
                $remain += (float) ($p['remain'] ?? 0);
            }

            $snapshot['packages']     = $packs;
            $snapshot['total_remain'] = $limit > 0 ? $remain : $info->balance;
            $snapshot['total_limit']  = $limit > 0 ? $limit : null;
            $snapshot['expire_at']    = $info->expireAt > 0 ? $info->expireAt : null;
        } catch (\Throwable $e) {
            Logger::warn($this->id() . ' 额度快照失败：' . $e->getMessage());
        }

        // 派生结果有缓存 + 落库，重复调用零开销
        try {
            $snapshot['api_key_ready'] = $this->codingPlanKey($account) !== '';
        } catch (\Throwable $e) {
            Logger::warn($this->id() . ' 额度快照检测 API Key 失败：' . $e->getMessage());
        }

        return $snapshot;
    }

    /**
     * 归一化可领取计划列表。
     *
     * 真实响应形状（`billing/preview` 与 `billing/balance` 同构，实测 2026-09-30）：
     *   {"plans":[{"plan_id":"zcode-v3-start-plan-0817","name":"ZCode Start Plan",
     *              "status":"active","starts_at":...,"ends_at":...,
     *              "entitlements":[{"show_name":"GLM-5.3","grant_units":3000000,
     *                               "period":"daily","unit_type":"token"}, ...]}]}
     *
     * ⚠️ 额度在 `entitlements[].grant_units`（**嵌套一层**），
     * 早期只读顶层的 `grant_units/units/remaining_units` → 永远取不到数值。
     * 这里把每份 entitlement 展开成一行，前端才能逐模型显示。
     */
    private function parseClaimPlans(array $payload): array
    {
        $list = [];
        if (isset($payload['plans']) && is_array($payload['plans'])) {
            $list = $payload['plans'];
        } elseif (isset($payload['entitlements']) && is_array($payload['entitlements'])) {
            $list = $payload['entitlements'];
        } elseif (array_is_list($payload)) {
            $list = $payload;
        }

        $plans = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $planId = trim((string) ($item['plan_id'] ?? ($item['id'] ?? ($item['plan'] ?? ''))));
            if ($planId === '') {
                continue;
            }

            $status = strtolower((string) ($item['status'] ?? ''));
            $name   = (string) ($item['name'] ?? ($item['show_name'] ?? $planId));

            // 展开 entitlements（每份 = 一个模型额度桶）
            $entitlements = [];
            foreach ((array) ($item['entitlements'] ?? []) as $ent) {
                if (!is_array($ent)) {
                    continue;
                }
                $entitlements[] = [
                    'entitlement_id' => (string) ($ent['entitlement_id'] ?? ''),
                    'show_name'   => (string) ($ent['show_name'] ?? ($ent['entitlement_id'] ?? '')),
                    'grant_units' => (float) ($ent['grant_units'] ?? 0),
                    'period'      => (string) ($ent['period'] ?? ''),
                    'unit_type'   => (string) ($ent['unit_type'] ?? ''),
                    'meter'       => (string) ($ent['meter'] ?? ''),
                ];
            }

            // 顶层也可能直接给额度（兼容旧形状）
            $unit = $item['grant_units'] ?? ($item['units'] ?? ($item['remaining_units'] ?? null));
            if ($unit === null && $entitlements !== []) {
                $unit = array_sum(array_column($entitlements, 'grant_units'));
            }

            $plans[] = [
                'plan_id'         => $planId,
                'name'            => $name,
                'description'     => (string) ($item['description'] ?? ''),
                'status'          => $status,
                'starts_at'       => $item['starts_at'] ?? null,
                'ends_at'         => $item['ends_at'] ?? null,
                'remaining_units' => $unit === null ? null : (float) $unit,
                'entitlements'    => $entitlements,
                // `active` 也可能是「已生效但今天已领完」；是否真能领以 claim 结果为准
                'claimable'       => in_array($status, ['', 'available', 'claimable', 'pending', 'active'], true),
            ];
        }

        return $plans;
    }

    /**
     * 领取额度包：POST {CLAIM_ORIGIN}/api/v1/zcode-plan/billing/claim  { plan_id }
     *
     * 三类入参：
     *   · `plan_id`  为空 → 用控制面探测到的真实 plan_id（`zcode-v3-start-plan-0817` 这类）
     *   · `captcha`  为空 → **自动无痕求解**（CaptchaSolver），全程零人工交互
     *   · `captcha`  非空 → 用调用方传入的值（保留人工兜底通道）
     *
     * 返回语义（`data.outcome`）：
     *   · `claimed`        领取成功
     *   · `already`        今天已领过（上游 1003 target already exists —— **不是故障**）
     *   · `not_found`      计划不存在/已下线（上游 1001）
     *   · `need_captcha`   自动求解失败，需人工（或环境无浏览器）
     */
    private function actionCheckin(Account $account, array $params): array
    {
        $planId = trim((string) ($params['plan_id'] ?? ''));
        if ($planId === '') {
            // 控制面探测出的真实 plan_id 优先（meta.plan_id 是早期写死的 coding-plan，不准）
            $planId = $this->detectPlanId($account);
        }

        $captcha = trim((string) ($params['captcha'] ?? ($params['verify_param'] ?? '')));
        $auto    = $captcha === '';
        $cfg     = $this->captchaConfig();
        $region  = trim((string) ($cfg['region'] ?? 'cn'));

        if ($auto) {
            $captcha = $this->solveCaptcha();
            if ($captcha === '') {
                // 自动求解失败。归 CLIENT：这是**本机环境/风控**问题，
                // 不是上游故障 —— 换账号、等冷却都不会好。
                return [
                    'ok'      => false,
                    'message' => '自动过验证码失败（无痕风控未放行或本机无可用浏览器）。'
                        . '可重试一次；仍失败则只能改走 coding-plan API Key 对话通道。',
                    'data'    => [
                        'error_code'   => ErrorCategory::CLIENT,
                        'need_captcha' => true,
                        'outcome'      => 'need_captcha',
                        'auto'         => true,
                    ],
                ];
            }
        }

        try {
            $headers = $this->claimHeaders($account, $captcha, $region);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
        }

        $url = self::CLAIM_ORIGIN . '/api/v1/zcode-plan/billing/claim';

        try {
            $response = $this->http()
                ->withHeaders($headers)
                ->timeout(25, 12)
                ->postJson($url, ['plan_id' => $planId]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '领取失败：' . $e->getMessage(), 'data' => null];
        }

        if (!$response->ok()) {
            $parsed = $this->parseError($response->status(), $response->body());
            return [
                'ok'      => false,
                'message' => $parsed['message'] ?? ('领取失败（HTTP ' . $response->status() . '）'),
                'data'    => [
                    'status'   => $response->status(),
                    'plan_id'  => $planId,
                    'outcome'  => 'failed',
                    'auto'     => $auto,
                ],
            ];
        }

        $data = $response->json();
        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $code = isset($data['code']) ? (int) $data['code'] : 0;

        // 上游业务码：1003 = 已领过（幂等成功，不该报错）；1001 = 计划无效
        if ($code === self::CLAIM_CODE_ALREADY) {
            return [
                'ok'      => true,
                'message' => '该账号今天已经领过这个额度包了（上游 1003：already claimed）',
                'data'    => [
                    'plan_id' => $planId,
                    'outcome' => 'already',
                    'code'    => $code,
                    'auto'    => $auto,
                    'raw'     => $payload,
                ],
            ];
        }
        if ($code === self::CLAIM_CODE_TARGET_NOT_FOUND) {
            return [
                'ok'      => false,
                'message' => '该额度包不存在或已下线（上游 1001）。请点「领取额度」重新查询当前可领项。',
                'data'    => [
                    'plan_id' => $planId,
                    'outcome' => 'not_found',
                    'code'    => $code,
                    'raw'     => $payload,
                ],
            ];
        }

        if ($code !== 0) {
            $msg = (string) ($data['msg'] ?? ($data['message'] ?? ''));
            return [
                'ok'      => false,
                'message' => $msg !== '' ? $msg : ('领取失败（code=' . $code . '）'),
                'data'    => ['plan_id' => $planId, 'code' => $code, 'outcome' => 'failed', 'raw' => $payload],
            ];
        }

        // 与真实客户端一致：成功响应需携带 data.plan
        $plan = is_array($payload['plan'] ?? null) ? $payload['plan'] : null;
        if ($plan === null) {
            $msg = (string) ($payload['message'] ?? ($data['msg'] ?? ''));
            return [
                'ok'      => false,
                'message' => $msg !== '' ? $msg : '领取失败：上游未返回计划信息',
                'data'    => ['plan_id' => $planId, 'outcome' => 'failed', 'raw' => $payload],
            ];
        }

        $message = '领取成功';
        if (isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== '') {
            $message = $payload['message'];
        }

        return [
            'ok'      => true,
            'message' => $message,
            'data'    => [
                'plan_id' => $planId,
                'plan'    => $plan,
                'outcome' => 'claimed',
                'code'    => $code,
                'auto'    => $auto,
                'raw'     => $payload,
            ],
        ];
    }

    private function actionSyncModels(Account $account): array
    {
        // 走 modelCatalog()（而非 models()），以便把显示名/上下文窗口/多模态/推理
        // 等元数据一并落库。注意：ZCode 为包月订阅制，上游模型目录不含计费倍率，
        // 因此 rate 恒为 null，费用换算按 1.0（中性）计。
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
    // 凭据保活：探活控制面（顺带刷新额度），令牌无刷新流程
    // ---------------------------------------------------------

    /**
     * ZCode 上游不签发 refresh_token，也没有可续期的令牌：access_token 是长期
     * API Key（用于对话/签名），登录返回的 JWT 仅用于领取。因此这里不做令牌续期，
     * 而是「探活 + 顺带刷新额度 + 失效检测」：
     *   - 探活成功：记录最新额度快照，返回 __message 供保活日志展示；
     *   - 命中 401/403 或业务码 401/403/1003（过期信号）：返回 __session_dead，
     *     由 AccountPool 标记会话失效，并附带自动生成的重登授权链接。
     * 以 __ 开头的键为保留控制键，由 AccountPool 解释、不写入凭据。
     */
    public function refreshToken(Account $account): array
    {
        // 控制面探活依赖 OAuth JWT；仅有 access_token（仍可用于对话）的账号跳过，
        // 以免被误判为登录失效。
        if ($this->jwt($account) === '') {
            return [];
        }

        try {
            $url = rtrim($this->controlPlaneHost(), '/') . '/api/v1/zcode-plan/billing/balance'
                . '?plan_id=' . rawurlencode($this->planId($account));

            $response = $this->http()
                ->withHeaders($this->controlPlaneHeaders($account))
                ->timeout(20, 10)
                ->get($url);
        } catch (\Throwable $e) {
            Logger::warn("{$this->id()} 凭据探活异常", ['uid' => $account->uid, 'error' => $e->getMessage()]);
            return [];
        }

        $status = $response->status();
        $decoded = $response->json();
        $bizCode = $this->extractBusinessCode(is_array($decoded) ? $decoded : null, $response->body());

        if ($this->isSessionDead($status, $bizCode)) {
            $message = $this->extractMessage($response->body());
            Logger::warn("{$this->id()} 凭据已失效", [
                'uid'    => $account->uid,
                'status' => $status,
                'code'   => $bizCode,
            ]);

            return $this->sessionDeadResult($account, $message !== '' ? $message : '登录已失效，请重新登录');
        }

        if (!$response->ok()) {
            Logger::warn("{$this->id()} 凭据探活返回异常状态", ['uid' => $account->uid, 'status' => $status]);
            return [];
        }

        // 探活成功：顺带刷新额度快照（落库字段与 AccountPool::refreshCredits 一致）
        $payload = is_array($decoded['data'] ?? null) ? $decoded['data'] : (is_array($decoded) ? $decoded : []);
        $info = $this->parseBalances($payload);
        if ($info === null) {
            return ['__message' => '令牌有效'];
        }

        $this->persistCredits($account, $info);

        return ['__message' => $info->detail ?: '令牌有效，额度已刷新'];
    }

    /** 过期信号：HTTP 401/403，或业务码 401/403/1003 */
    private function isSessionDead(int $status, int $bizCode): bool
    {
        if ($status === 401 || $status === 403) {
            return true;
        }

        return in_array($bizCode, [401, 403, 1003], true);
    }

    /**
     * 组装「会话失效」结果：保留控制键 + 自动生成的重登授权链接。
     * 链接生成失败不影响失效判定，仅记录告警。
     */
    private function sessionDeadResult(Account $account, string $message): array
    {
        $result = [
            '__session_dead' => true,
            '__message'      => $message,
        ];

        try {
            $session = $this->startLogin();
            if (!empty($session->authUrl)) {
                $result['__relogin_url']     = $session->authUrl;
                $result['__relogin_session'] = $session->sessionId;
            }
        } catch (\Throwable $e) {
            Logger::warn("{$this->id()} 生成重登授权链接失败", ['uid' => $account->uid, 'error' => $e->getMessage()]);
        }

        return $result;
    }

    /** 落库额度快照（探活顺带刷新额度用） */
    private function persistCredits(Account $account, CreditInfo $info): void
    {
        $this->accountRepository->updateStatus($account->id, [
            'credits'         => $info->known ? $info->balance : null,
            'credits_known'   => $info->known,
            'credits_detail'  => $info->detail,
            'last_refresh_at' => now_ms(),
        ]);

        if ($info->known && $info->expireAt > 0) {
            $this->accountRepository->setCreditsExpire($account->id, $info->expireAt);
        }

        $metaPatch = [];
        if ($info->usage !== null) {
            $metaPatch['usage'] = $info->usage;
        }
        if (!empty($info->packs)) {
            $metaPatch['packs'] = $info->packs;
        }
        if ($metaPatch !== []) {
            $this->accountRepository->patchMeta($account, $metaPatch);
        }
    }

    // ---------------------------------------------------------
    // 登录：ZCode CLI OAuth 轮询流程
    // ---------------------------------------------------------

    public function startLogin(array $params = []): LoginSession
    {
        $sessionId = uuid();

        // 客户端自生成的 poll token（32 字节随机 hex）：init 与 poll 两次请求都必须
        // 以 Authorization: Bearer 携带同一个值，否则上游返回 3004 invalid_flow。
        $pollToken = bin2hex(random_bytes(32));

        try {
            $res = $this->http()
                ->withHeaders([
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $pollToken,
                ])
                ->timeout(20, 10)
                ->postJson(self::ZCODE_API_BASE . '/oauth/cli/init', [
                    'provider' => $this->upstreamType(),
                ]);
        } catch (\Throwable $e) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'failed',
                message: '发起登录失败：' . $e->getMessage(),
                error: 'init_failed'
            );
        }

        if (!$res->ok()) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'failed',
                message: '发起登录失败（HTTP ' . $res->status() . '）：' . $this->extractMessage($res->body()),
                error: 'init_failed'
            );
        }

        $data = $res->json();
        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;

        $flowId = (string) ($payload['flow_id'] ?? '');
        $authorizeUrl = (string) ($payload['authorize_url'] ?? '');
        $interval = (int) ($payload['poll_interval_sec'] ?? 3);
        $expiresAt = (int) ($payload['expires_at'] ?? 0);

        if ($flowId === '' || $authorizeUrl === '') {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'failed',
                message: '上游未返回授权链接，请稍后重试',
                error: 'init_invalid'
            );
        }

        // 授权链接需要追加桌面端插页参数，浏览器完成授权后由服务端记录，
        // 从而让 poll 翻转为 ready（zai 用 redirect_uri，bigmodel 用 redirect）。
        $authorizeUrl = $this->applyInterstitial($authorizeUrl);

        $expiresIn = $expiresAt > 0 ? max(60, $expiresAt - time()) : 600;

        $this->saveLoginSession($sessionId, [
            'state'        => 'waiting',
            'provider'     => $this->id(),
            'flow_id'      => $flowId,
            'poll_token'   => $pollToken,
            'authorize_url' => $authorizeUrl,
            'created_at'   => now_ms(),
        ]);

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'waiting',
            message: '请在浏览器中打开授权链接完成登录',
            authUrl: $authorizeUrl,
            intervalSeconds: $interval > 0 ? $interval : 3,
            expiresInSeconds: $expiresIn,
            extra: ['flow_id' => $flowId],
        );
    }

    /**
     * 追加桌面端 OAuth 插页：指向 zcode.z.ai 的 /app/oauth/login 页面，
     * 该页把授权结果记录到服务端后跳回 zcode://oauth/callback。
     * 参数名按区域区分：zai → redirect_uri，bigmodel → redirect。
     */
    private function applyInterstitial(string $authorizeUrl): string
    {
        $interstitial = 'https://zcode.z.ai/app/oauth/login?'
            . http_build_query([
                'redirect'    => 'zcode://oauth/callback',
                'app_version' => self::APP_VERSION,
            ]);

        // 与服务端一致：用「覆盖」而非「追加」，把上游自带的回调地址替换为插页地址，
        // 避免出现重复的 redirect_uri/redirect 参数。
        // 参数名按区域区分：zai（国际版）→ redirect_uri，bigmodel（国内版）→ redirect。
        $param = $this->isIntlProvider() ? 'redirect_uri' : 'redirect';

        $parts = parse_url($authorizeUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $authorizeUrl;
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query[$param] = $interstitial;

        $rebuilt = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['path']) ? $parts['path'] : '')
            . '?' . http_build_query($query);

        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
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

        $flowId = (string) ($session['flow_id'] ?? '');
        $pollToken = (string) ($session['poll_token'] ?? '');

        if ($flowId === '') {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'failed',
                message: '登录会话缺少流程标识，请重新发起',
                error: 'session_invalid'
            );
        }

        try {
            $res = $this->http()
                ->withHeaders([
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $pollToken,
                ])
                ->timeout(20, 10)
                ->get(self::ZCODE_API_BASE . '/oauth/cli/poll/' . rawurlencode($flowId));
        } catch (\Throwable $e) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'waiting',
                message: '轮询异常，稍后重试：' . $e->getMessage(),
            );
        }

        if ($res->status() === 404) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'expired',
                message: '授权已过期，请重新发起登录',
                error: 'expired'
            );
        }

        if (!$res->ok()) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'waiting',
                message: '等待授权中（HTTP ' . $res->status() . '）',
            );
        }

        $data = $res->json();
        if (isset($data['code']) && (int) $data['code'] !== 0) {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'failed',
                message: '授权失败：' . (string) ($data['msg'] ?? ('code=' . $data['code'])),
                error: 'poll_failed'
            );
        }

        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $status = strtolower((string) ($payload['status'] ?? ''));

        if ($status === 'failed') {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'failed',
                message: '授权失败，请重新发起登录',
                error: 'authorize_failed'
            );
        }

        // 仅 ready 视为完成；pending / 空状态一律继续等待
        if ($status !== 'ready' && $status !== 'success' && $status !== 'authorized') {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'waiting',
                message: '等待授权中',
            );
        }

        $token = (string) ($payload['token'] ?? ($payload['access_token'] ?? ''));
        $upstream = is_array($payload[$this->upstreamType()] ?? null) ? $payload[$this->upstreamType()] : [];
        $upstreamToken = (string) ($upstream['access_token'] ?? '');
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $userId = (string) ($user['user_id'] ?? ($user['id'] ?? ''));

        if ($token === '' && $upstreamToken === '') {
            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: 'waiting',
                message: '等待授权中',
            );
        }

        $result = $this->ingestCredential([
            'access_token' => $upstreamToken !== '' ? $upstreamToken : $token,
            'jwt'          => $token,
            'zai_token'    => $upstreamToken,
            'user_id'      => $userId,
            'nickname'     => (string) ($user['name'] ?? ($user['nickname'] ?? '')),
            'upstream'     => $this->upstreamType(),
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
     * 回调兜底：ZCode CLI 流程为服务端轮询，不产生浏览器回调，
     * 这里仅支持把最终令牌直接粘贴导入。
     */
    public function handleCallback(array $query): array
    {
        $token = trim((string) ($query['access_token'] ?? $query['token'] ?? ''));
        $jwt = trim((string) ($query['jwt'] ?? ''));
        if ($token === '' && $jwt === '') {
            return ['ok' => false, 'message' => 'ZCode 使用授权链接轮询登录，无需回调；如需手动导入请提供 access_token / jwt'];
        }

        return $this->ingestCredential([
            'access_token' => $token,
            'jwt'          => $jwt,
            'user_id'      => (string) ($query['user_id'] ?? ''),
        ]);
    }

    public function ingestCredential(array $credential): array
    {
        $token = trim((string) ($credential['access_token'] ?? $credential['accessToken'] ?? $credential['token'] ?? ''));
        $apiKey = trim((string) ($credential['api_key'] ?? $credential['apiKey'] ?? ''));
        $zaiToken = trim((string) ($credential['zai_token'] ?? ''));
        $jwt = trim((string) ($credential['jwt'] ?? ''));

        if ($token === '' && $apiKey === '' && $zaiToken === '' && $jwt === '') {
            return ['ok' => false, 'message' => 'ZCode 凭据缺少 access_token / api_key'];
        }

        $effective = $token !== '' ? $token : ($apiKey !== '' ? $apiKey : ($jwt !== '' ? $jwt : $zaiToken));

        $uid = trim((string) ($credential['user_id'] ?? $credential['uid'] ?? ''));
        if ($uid === '') {
            $uid = 'zc_' . substr(hash('sha256', $effective), 0, 16);
        }

        $nickname = (string) ($credential['nickname'] ?? $credential['name'] ?? '');

        $stored = [
            'access_token' => $effective,
            'upstream'     => (string) ($credential['upstream'] ?? $this->upstreamType()),
        ];
        if ($zaiToken !== '') {
            $stored['zai_token'] = $zaiToken;
        }
        if ($jwt !== '') {
            $stored['jwt'] = $jwt;
        }
        if ($apiKey !== '') {
            $stored['api_key'] = $apiKey;
        }

        $meta = array_filter([
            'upstream' => $this->upstreamType(),
            'avatar'   => (string) ($credential['avatar'] ?? ''),
        ], static fn ($v) => $v !== '');

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