<?php
/**
 * 管理台 · 网关设置与网络代理
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\GatewayRepository;
use App\Repositories\ModelRepository;
use App\Repositories\ProxyRepository;
use App\Repositories\ProviderRepository;
use App\Repositories\UserRepository;
use App\Middleware\UserAuthMiddleware;
use App\Support\Audit;
use App\Support\Crypto;
use App\Support\Logger;
use App\Support\MailDomain;
use App\Support\PasswordPolicy;

class SettingsController
{
    public function __construct(
        private GatewayRepository $gatewayRepository,
        private ProxyRepository $proxyRepository,
        private ProviderRepository $providerRepository,
        private ModelRepository $modelRepository,
        private AccountRepository $accountRepository,
        private UserRepository $userRepository,
        private PasswordPolicy $passwordPolicy,
    ) {}

    /**
     * GET /api/user/settings —— 普通用户可读的网关接入信息
     *
     * 仅暴露「接入网关所必需」的字段，不下发任何管理项与密钥明文：
     *   - base_url    ：网关地址
     *   - api_key     ：当前登录用户自己的对外密钥（属主数据，非管理端密钥）
     *   - default_model / schedule_mode / only_usable_models：只读的默认路由状态
     */
    public function userShow(Request $request): Response
    {
        $session = UserAuthMiddleware::current() ?? [];
        $uid = (int) ($session['id'] ?? 0);
        $user = $uid > 0 ? $this->userRepository->find($uid) : null;
        if ($user === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '账号不存在']], 404);
        }

        $settings = $this->gatewayRepository->current();

        return Response::json([
            'ok'   => true,
            'data' => [
                'base_url'           => (string) config('gateway.base_url', 'http://ai.com'),
                'api_key'            => (string) ($user['api_key'] ?? ''),
                'default_model'      => $settings['default_model'] ?? '',
                'default_provider'   => $settings['default_provider'] ?? '',
                'schedule_mode'      => $settings['schedule_mode'] ?? 'balance',
                'only_usable_models' => (bool) ($settings['only_usable_models'] ?? true),
                // 只读的能力开关（管理员配置），便于用户端提示当前可用能力
                'gateway_enabled'    => (bool) ($settings['gateway_enabled'] ?? true),
                'stream_enabled'     => (bool) ($settings['stream_enabled'] ?? true),
                'tools_enabled'      => (bool) ($settings['tools_enabled'] ?? true),
            ],
        ]);
    }

    /** GET /api/admin/settings —— 读取全部设置（含敏感项） */
    public function show(Request $request): Response
    {
        $settings = $this->gatewayRepository->current();
        // 密码安全开关的「意图 vs 实际生效」对照（单一真源，见 PasswordPolicy）
        $pwPolicy = $this->passwordPolicy->snapshot();

        return Response::json([
            'ok'   => true,
            'data' => [
                'api_key_masked'      => mask_secret((string) $settings['api_key'], 10, 4),
                'allow_no_key'        => (bool) $settings['allow_no_key'],
                'expose_lan'          => (bool) $settings['expose_lan'],
                'only_usable_models'  => (bool) $settings['only_usable_models'],
                'allow_register'      => (bool) ($settings['allow_register'] ?? true),
                'captcha_enabled'     => (bool) ($settings['captcha_enabled'] ?? true),
                'login_max_attempts'  => (int) ($settings['login_max_attempts'] ?? 5),
                'login_lock_seconds'  => (int) ($settings['login_lock_seconds'] ?? 900),
                'default_provider'    => (string) $settings['default_provider'],
                'default_model'       => (string) $settings['default_model'],
                'quota_cooldown_ms'   => (int) $settings['quota_cooldown_ms'],
                'soft_cooldown_ms'    => (int) $settings['soft_cooldown_ms'],
                'max_error_count'     => (int) $settings['max_error_count'],
                'log_keep_days'       => (int) $settings['log_keep_days'],
                'stream_heartbeat'    => (bool) $settings['stream_heartbeat'],
                'schedule_mode'       => (string) $settings['schedule_mode'],
                'pinned_account_uid'  => (string) $settings['pinned_account_uid'],
                // 免排队供应商白名单：数组形态下发给前端（前端以逗号串编辑）
                'lease_bypass_providers' => implode(',', (array) ($settings['lease_bypass_providers'] ?? [])),
                // 功能开关：网关总开关与对外能力
                'gateway_enabled'         => (bool) ($settings['gateway_enabled'] ?? true),
                'chat_enabled'            => (bool) ($settings['chat_enabled'] ?? true),
                'models_enabled'          => (bool) ($settings['models_enabled'] ?? true),
                'stream_enabled'          => (bool) ($settings['stream_enabled'] ?? true),
                'tools_enabled'           => (bool) ($settings['tools_enabled'] ?? true),
                'models_expose_providers' => (bool) ($settings['models_expose_providers'] ?? false),
                'models_qualified_id'     => (bool) ($settings['models_qualified_id'] ?? true),
                // 功能开关：调用日志
                'log_enabled'             => (bool) ($settings['log_enabled'] ?? true),
                'log_request_body'        => (bool) ($settings['log_request_body'] ?? false),
                'resample_rewrite_user'   => (bool) ($settings['resample_rewrite_user'] ?? false),
                'normalize_jailbreak_shape' => (bool) ($settings['normalize_jailbreak_shape'] ?? false),
                'persona_override_client' => (bool) ($settings['persona_override_client'] ?? false),
                'log_content_max_chars'   => (int) ($settings['log_content_max_chars'] ?? \App\Repositories\GatewayRepository::DEFAULT_LOG_CONTENT),
                'log_body_max_rows'       => (int) ($settings['log_body_max_rows'] ?? 20000),
                'log_max_rows'            => (int) ($settings['log_max_rows'] ?? 200000),
                // 站点品牌（全局可改）
                'app_name'                => (string) ($settings['app_name'] ?? 'AI 聚合网关'),
                'app_subtitle'            => (string) ($settings['app_subtitle'] ?? 'Aggregator'),
                'app_desc'                => (string) ($settings['app_desc'] ?? ''),
                // 邮件发信（SMTP）—— 端口不限，80 端口亦可；口令只回传「是否已设置」
                'mail_enabled'            => (bool) ($settings['mail_enabled'] ?? false),
                'mail_host'               => (string) ($settings['mail_host'] ?? ''),
                'mail_port'               => (int) ($settings['mail_port'] ?? 465),
                'mail_username'           => (string) ($settings['mail_username'] ?? ''),
                'mail_password_set'       => !empty($settings['mail_password_set']),
                'mail_encryption'         => (string) ($settings['mail_encryption'] ?? 'auto'),
                'mail_from_email'         => (string) ($settings['mail_from_email'] ?? ''),
                'mail_from_name'          => (string) ($settings['mail_from_name'] ?? ''),
                'mail_connect_timeout'    => (int) ($settings['mail_connect_timeout'] ?? 10),
                'mail_read_timeout'       => (int) ($settings['mail_read_timeout'] ?? 20),
                'mail_allow_insecure'     => (bool) ($settings['mail_allow_insecure'] ?? true),
                'mail_verify_required'    => (bool) ($settings['mail_verify_required'] ?? false),
                'mail_code_captcha'       => (bool) ($settings['mail_code_captcha'] ?? true),
                'mail_code_ttl'           => (int) ($settings['mail_code_ttl'] ?? 300),
                'mail_code_cooldown'      => (int) ($settings['mail_code_cooldown'] ?? 60),
                'mail_code_daily_email'   => (int) ($settings['mail_code_daily_email'] ?? 10),
                'mail_code_daily_ip'      => (int) ($settings['mail_code_daily_ip'] ?? 30),
                // 密码安全：改密码需邮箱验证 / 开放找回密码
                //
                // ⚠️ 这里同时下发「开关值」与「实际是否生效」两种语义，缺一不可：
                //    开关值    = 数据库里的意图（管理员看到的开关位置）
                //    *_effective = 叠加 SMTP 就绪后的**真实行为**
                //    两者不一致时（开关开着、邮件没配）后端会静默放行 —— 这正是
                //    最容易骗过管理员的静默漂移，故额外下发 warning 文案显式告警。
                'password_change_verify'  => (bool) ($settings['password_change_verify'] ?? true),
                'password_reset_enabled'  => (bool) ($settings['password_reset_enabled'] ?? true),
                'password_change_verify_effective' => $pwPolicy['password_change_verify_effective'],
                'password_reset_enabled_effective' => $pwPolicy['password_reset_enabled_effective'],
                'password_verify_warning' => $pwPolicy['password_verify_warning'],
                'mail_ready'              => $pwPolicy['mail_ready'],
                'mail_gaps'               => $pwPolicy['mail_gaps'],
                // 注册邮箱域名白名单（逗号分隔；空串 = 不限域名）
                'register_email_domains'  => (string) ($settings['register_email_domains'] ?? ''),
                // 功能开关：限流
                'rate_limit_enabled'      => (bool) ($settings['rate_limit_enabled'] ?? false),
                'rate_limit_rpm'          => (int) ($settings['rate_limit_rpm'] ?? 60),
                'rate_limit_burst'        => (int) ($settings['rate_limit_burst'] ?? 10),
                'rate_limit_scope'        => (string) ($settings['rate_limit_scope'] ?? 'key'),
                // 功能开关：思维（思维）治理
                'user_persona_enabled'    => (bool) ($settings['user_persona_enabled'] ?? true),
                'system_persona_enabled'  => (bool) ($settings['system_persona_enabled'] ?? false),
                // 弹窗公告（注册页 / 总览面板）
                'notice_enabled'          => (bool) ($settings['notice_enabled'] ?? false),
                'notice_title'            => (string) ($settings['notice_title'] ?? ''),
                'notice_content'          => (string) ($settings['notice_content'] ?? ''),
                'notice_target'           => (string) ($settings['notice_target'] ?? 'all'),
                'notice_type'             => (string) ($settings['notice_type'] ?? 'info'),
                'notice_version'          => (int) ($settings['notice_version'] ?? 0),
                // 公告上的「跳转加群」按钮
                'notice_group_enabled'    => (bool) ($settings['notice_group_enabled'] ?? false),
                'notice_group_qq'         => (string) ($settings['notice_group_qq'] ?? ''),
                'notice_group_url'        => (string) ($settings['notice_group_url'] ?? ''),
                // 失败告警 webhook（企业微信/钉钉自定义机器人兼容）
                'alert_webhook_enabled'   => (bool) ($settings['alert_webhook_enabled'] ?? false),
                'alert_webhook_url'       => (string) ($settings['alert_webhook_url'] ?? ''),
                'alert_cooldown_seconds'  => (int) ($settings['alert_cooldown_seconds'] ?? 300),
                'base_url'            => (string) config('gateway.base_url', 'http://ai.com'),
                'providers'           => array_values(array_map(
                    fn($p) => ['provider_key' => $p['provider_key'], 'display_name' => $p['display_name'], 'enabled' => $p['enabled']],
                    $this->providerRepository->all()
                )),
                'accounts'            => array_values(array_map(
                    fn($a) => [
                        'uid'          => $a->uid,
                        'provider_key' => $a->providerKey,
                        'nickname'     => $a->nickname,
                    ],
                    $this->accountRepository->all()
                )),
            ],
        ]);
    }

    /** POST /api/admin/settings */
    public function update(Request $request): Response
    {
        $payload = $request->json();
        $data = [];

        $boolKeys = [
            'allow_no_key', 'expose_lan', 'only_usable_models', 'stream_heartbeat', 'allow_register', 'captcha_enabled',
            // 功能开关
            'gateway_enabled', 'chat_enabled', 'models_enabled', 'stream_enabled',
            'tools_enabled', 'models_expose_providers', 'models_qualified_id',
            'log_enabled', 'log_request_body',
            // 出口层规避（重采样时改写 user 提示词）
            'resample_rewrite_user',
            // 入口层规避（请求发出前归一化 user 提示词的「越狱模板形态」）
            'normalize_jailbreak_shape',
            // 主导权回收（system 末尾追加「客户端模板安全条款已废止」声明）
            'persona_override_client',
            'rate_limit_enabled',
            // 思维（思维）治理
            'user_persona_enabled', 'system_persona_enabled',
            // 邮件
            'mail_enabled', 'mail_allow_insecure', 'mail_verify_required', 'mail_code_captcha',
            // 密码安全：改密码需邮箱验证 / 开放找回密码
            'password_change_verify', 'password_reset_enabled',
            // 弹窗公告总开关（注册页 / 总览面板）
            'notice_enabled',
            // 公告上的「跳转加群」按钮开关
            'notice_group_enabled',
            // 失败告警 webhook 开关
            'alert_webhook_enabled',
            // 公开状态页开关（/status 与 /v1/status）
            'status_page_enabled',
        ];
        foreach ($boolKeys as $boolKey) {
            if (array_key_exists($boolKey, $payload)) {
                $data[$boolKey] = !empty($payload[$boolKey]) ? 1 : 0;
            }
        }

        foreach (['quota_cooldown_ms', 'soft_cooldown_ms', 'max_error_count', 'log_keep_days', 'log_max_rows', 'log_body_max_rows'] as $intKey) {
            if (array_key_exists($intKey, $payload)) {
                $data[$intKey] = max(0, (int) $payload[$intKey]);
            }
        }

        // 内容留痕单条上限：0（不记录内容）~ LOG_CONTENT_MAX（默认 20 万字）
        if (array_key_exists('log_content_max_chars', $payload)) {
            $data['log_content_max_chars'] = max(0, min(
                \App\Repositories\GatewayRepository::LOG_CONTENT_MAX,
                (int) $payload['log_content_max_chars']
            ));
        }

        // 站点品牌：客户端常显示，按列宽截断；留空则回落默认值
        foreach ([
            'app_name'     => ['AI 聚合网关', 64],
            'app_subtitle' => ['Aggregator', 64],
            'app_desc'     => ['多供应商账号池 · 统一 OpenAI 兼容出口', 128],
        ] as $brandKey => $brandMeta) {
            if (array_key_exists($brandKey, $payload)) {
                $data[$brandKey] = GatewayRepository::brandText(
                    $payload[$brandKey],
                    $brandMeta[0],
                    $brandMeta[1]
                );
            }
        }

        /* ── 邮件发信（SMTP）─────────────────────────────────
           端口不做白名单：80 / 2525 / 465 / 587 / 25 都可直接用。
           口令单独处理：非空才覆盖，存 AES-256-GCM 密文；传空串表示「保持不变」。
           -------------------------------------------------- */
        foreach ([
            'mail_host'      => 190,
            'mail_username'  => 190,
            'mail_from_email' => 190,
            'mail_from_name' => 64,
        ] as $mailKey => $maxLen) {
            if (array_key_exists($mailKey, $payload)) {
                $data[$mailKey] = mb_substr(trim((string) $payload[$mailKey]), 0, $maxLen);
            }
        }
        if (array_key_exists('mail_port', $payload)) {
            // 端口合法范围 1-65535 全部放行
            $data['mail_port'] = min(65535, max(1, (int) $payload['mail_port']));
        }
        if (array_key_exists('mail_encryption', $payload)) {
            $enc = strtolower(trim((string) $payload['mail_encryption']));
            if (!in_array($enc, ['none', 'ssl', 'tls', 'auto'], true)) {
                return Response::json(['ok' => false, 'error' => ['message' => '未知的加密方式：' . $enc]], 400);
            }
            $data['mail_encryption'] = $enc;
        }
        foreach ([
            'mail_connect_timeout' => [1, 120],
            'mail_read_timeout'    => [1, 300],
            'mail_code_ttl'        => [60, 3600],
            'mail_code_cooldown'   => [0, 600],
            'mail_code_daily_email' => [0, 100],
            'mail_code_daily_ip'   => [0, 1000],
        ] as $intKey => $range) {
            if (array_key_exists($intKey, $payload)) {
                $data[$intKey] = max($range[0], min($range[1], (int) $payload[$intKey]));
            }
        }
        // 注册邮箱域名白名单：空串 = 不限域名（显式清空即恢复开放注册）。
        //
        // ⚠️ 必须区分「真的空」与「写错了」：
        //   管理员填了 `qq` / `abc` / 误粘一段文字，归一化后都会变成空串，
        //   而空串语义是「不限制」—— 于是「本意是要限制、结果反而全放开」。
        //   这是最危险的静默漂移（限制失效且无任何报错），故此处显式拒绝。
        if (array_key_exists('register_email_domains', $payload)) {
            $rawDomains = trim((string) $payload['register_email_domains']);
            $normalized = MailDomain::normalize($rawDomains);
            if ($rawDomains !== '' && $normalized === '') {
                return Response::json([
                    'ok'    => false,
                    'error' => ['message' => '邮箱域名格式不正确，请填写形如 qq.com 的域名（多个用逗号分隔）；留空表示不限制'],
                ], 400);
            }
            $data['register_email_domains'] = $normalized;
        }

        // SMTP 口令：留空 = 不改；非空 = 加密覆盖
        if (array_key_exists('mail_password', $payload)) {
            $plain = (string) $payload['mail_password'];
            if ($plain !== '') {
                try {
                    $data['mail_password'] = Crypto::encrypt($plain);
                } catch (\Throwable $e) {
                    Logger::warn('SMTP 口令加密失败', ['error' => $e->getMessage()]);
                    return Response::json(['ok' => false, 'error' => ['message' => 'SMTP 口令加密失败，请检查 AIGW_APP_KEY 配置']], 500);
                }
            }
        }

        // 限流参数：RPM 1-100000，突发额度 0-10000
        if (array_key_exists('rate_limit_rpm', $payload)) {
            $data['rate_limit_rpm'] = max(1, min(100000, (int) $payload['rate_limit_rpm']));
        }
        if (array_key_exists('rate_limit_burst', $payload)) {
            $data['rate_limit_burst'] = max(0, min(10000, (int) $payload['rate_limit_burst']));
        }
        if (array_key_exists('rate_limit_scope', $payload)) {
            $scope = trim((string) $payload['rate_limit_scope']);
            if (!in_array($scope, ['key', 'ip'], true)) {
                return Response::json(['ok' => false, 'error' => ['message' => '未知的限流维度：' . $scope]], 400);
            }
            $data['rate_limit_scope'] = $scope;
        }

        /* ── 弹窗公告（注册页 / 总览面板）───────────────────────
           正文用 TEXT 存（支持多行），这里按字符数截断，避免把列写爆。
           公告是纯文本，前端按行渲染并对文本转义、不解析 HTML，故不存在 XSS 面。
           显示位置与样式走白名单枚举，拼错直接 400（而不是静默写进库、
           前端识别不了 → 「填了却没弹」的静默漂移）。
           -------------------------------------------------- */
        if (array_key_exists('notice_title', $payload)) {
            $data['notice_title'] = mb_substr(trim((string) $payload['notice_title']), 0, 128);
        }
        if (array_key_exists('notice_content', $payload)) {
            // 只裁首尾空白，保留中间换行（公告常按行排版）
            $data['notice_content'] = mb_substr(trim((string) $payload['notice_content']), 0, 5000);
        }
        if (array_key_exists('notice_target', $payload)) {
            $target = trim((string) $payload['notice_target']);
            if (!in_array($target, ['all', 'register', 'dashboard'], true)) {
                return Response::json(['ok' => false, 'error' => ['message' => '未知的公告显示位置：' . $target]], 400);
            }
            $data['notice_target'] = $target;
        }
        if (array_key_exists('notice_type', $payload)) {
            $type = trim((string) $payload['notice_type']);
            if (!in_array($type, ['info', 'success', 'warning', 'danger'], true)) {
                return Response::json(['ok' => false, 'error' => ['message' => '未知的公告样式：' . $type]], 400);
            }
            $data['notice_type'] = $type;
        }

        /* 加群按钮：群号只留数字（用户常整段粘「QQ群：123456」），链接只认 http(s)。
           链接若不是 http(s) 开头，直接 400 —— 否则会静默存成空串，
           表现为「后台填了链接，前台按钮却走了群号分支」的静默漂移。 */
        if (array_key_exists('notice_group_qq', $payload)) {
            $qq = preg_replace('/\D+/', '', (string) $payload['notice_group_qq']) ?? '';
            $data['notice_group_qq'] = substr($qq, 0, 32);
        }
        if (array_key_exists('notice_group_url', $payload)) {
            $url = trim((string) $payload['notice_group_url']);
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                return Response::json([
                    'ok' => false,
                    'error' => ['message' => '加群链接需以 http:// 或 https:// 开头：' . $url],
                ], 400);
            }
            $data['notice_group_url'] = mb_substr($url, 0, 255);
        }

        // 告警 webhook：URL 只认 http(s)（口径同加群链接），冷却窗口 30~3600s。
        // 开启时必须已填 URL，否则「以为开了却没生效」的静默漂移。
        if (array_key_exists('alert_webhook_url', $payload)) {
            $url = trim((string) $payload['alert_webhook_url']);
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                return Response::json([
                    'ok' => false,
                    'error' => ['message' => '告警 Webhook 地址需以 http:// 或 https:// 开头'],
                ], 400);
            }
            $data['alert_webhook_url'] = mb_substr($url, 0, 512);
        }
        if (array_key_exists('alert_cooldown_seconds', $payload)) {
            $data['alert_cooldown_seconds'] = max(30, min(3600, (int) $payload['alert_cooldown_seconds']));
        }
        $alertEnabled = array_key_exists('alert_webhook_enabled', $payload)
            ? !empty($payload['alert_webhook_enabled'])
            : (bool) ($this->gatewayRepository->get('alert_webhook_enabled', false));
        $alertUrl = $data['alert_webhook_url'] ?? (string) $this->gatewayRepository->get('alert_webhook_url', '');
        if ($alertEnabled && trim($alertUrl) === '') {
            return Response::json([
                'ok' => false,
                'error' => ['message' => '开启失败告警前请先填写 Webhook 地址'],
            ], 400);
        }

        // 公告版本号：标题/内容/类型/位置**任一变化**即自动 +1，客户端据此重新弹一次；
        // 另可显式传 notice_bump=1 强制重推（内容一字未改也想让所有人再看一遍）。
        // 只在真的有变化时才抬水位 —— 否则「保存别的设置」也会让公告重复弹给所有人。
        //
        // 加群按钮的三项也计入：老用户已读过上一版公告，若加群配置改了不抬版本，
        // 按钮永远不会对他们出现 —— 这正是「配了却不生效」的静默漂移。
        $noticeKeys = [
            'notice_title', 'notice_content', 'notice_target', 'notice_type',
            'notice_group_enabled', 'notice_group_qq', 'notice_group_url',
        ];
        $noticeTouched = false;
        foreach ($noticeKeys as $noticeKey) {
            if (array_key_exists($noticeKey, $payload)) { $noticeTouched = true; break; }
        }
        if ($noticeTouched || !empty($payload['notice_bump'])) {
            $currentSettings = $this->gatewayRepository->current();
            $noticeChanged = !empty($payload['notice_bump']);
            if (!$noticeChanged) {
                // 形态归一后再比：布尔开关在 payload 里是 int 0/1、在 current() 里是 bool，
                // 直接 (string) 会得到 '0' vs '' —— 关着也被判成「变了」而白白抬版本。
                $norm = static fn($v): string => is_bool($v) ? ($v ? '1' : '0') : (string) $v;
                foreach ($noticeKeys as $noticeKey) {
                    if (!array_key_exists($noticeKey, $payload)) continue;
                    if ($norm($data[$noticeKey]) !== $norm($currentSettings[$noticeKey] ?? '')) {
                        $noticeChanged = true;
                        break;
                    }
                }
            }
            if ($noticeChanged) {
                $data['notice_version'] = (int) ($currentSettings['notice_version'] ?? 0) + 1;
            }
        }

        // 登录失败锁定策略：最大尝试次数 1-50，锁定时长 1-86400 秒
        if (array_key_exists('login_max_attempts', $payload)) {
            $data['login_max_attempts'] = max(1, min(50, (int) $payload['login_max_attempts']));
        }
        if (array_key_exists('login_lock_seconds', $payload)) {
            $data['login_lock_seconds'] = max(1, min(86400, (int) $payload['login_lock_seconds']));
        }

        if (array_key_exists('default_provider', $payload)) {
            $key = trim((string) $payload['default_provider']);
            if ($key !== '' && !$this->providerRepository->exists($key)) {
                return Response::json(['ok' => false, 'error' => ['message' => "供应商 {$key} 不存在"]], 400);
            }
            $data['default_provider'] = $key;
        }

        if (array_key_exists('default_model', $payload)) {
            $defaultModel = trim((string) $payload['default_model']);

            // 非空时必须落在模型目录里，避免把默认模型写成不存在的字符串导致网关默认路由失败。
            if ($defaultModel !== '') {
                $known = array_column($this->modelRepository->all(), 'model_id');
                if ($known && !in_array($defaultModel, $known, true)) {
                    return Response::json([
                        'ok'    => false,
                        'error' => ['message' => '默认模型不在模型目录中：' . $defaultModel],
                    ], 400);
                }
            }

            $data['default_model'] = $defaultModel;
        }

        if (array_key_exists('schedule_mode', $payload)) {
            $mode = trim((string) $payload['schedule_mode']);
            if (!in_array($mode, ['balance', 'rotation', 'pinned', 'expiry'], true)) {
                return Response::json(['ok' => false, 'error' => ['message' => '未知的调度模式：' . $mode]], 400);
            }
            $data['schedule_mode'] = $mode;
        }

        if (array_key_exists('pinned_account_uid', $payload)) {
            $uid = trim((string) $payload['pinned_account_uid']);

            // 非空时必须是一个存在的账号，避免 pinned 模式指向不存在的 uid 导致无账号可用
            if ($uid !== '' && $this->accountRepository->findByUidByAny($uid) === null) {
                return Response::json(['ok' => false, 'error' => ['message' => '固定账号不存在：' . $uid]], 400);
            }
            $data['pinned_account_uid'] = $uid;
        }

        // 免排队供应商白名单：接受逗号串或数组；只保留「有意义」的条目，
        // 拼错/不存在的 key 直接丢弃（防拼写错误导致「填了却没生效」的静默失效）。
        //
        // 支持前缀通配：`custom*` 覆盖 `custom` 与全部 `custom_xxx` 多实例。
        // 通配条目要求其前缀至少命中一个已知 provider_key，否则同样丢弃。
        if (array_key_exists('lease_bypass_providers', $payload)) {
            $raw = $payload['lease_bypass_providers'];
            $keys = GatewayRepository::normalizeProviderList(
                is_array($raw) ? implode(',', $raw) : (string) $raw
            );
            $known = array_column($this->providerRepository->all(), 'provider_key');
            $known = array_map(static fn($k) => strtolower((string) $k), $known);
            $keys = array_values(array_filter(
                $keys,
                static fn($k) => GatewayRepository::providerPatternKnown((string) $k, $known)
            ));
            $data['lease_bypass_providers'] = GatewayRepository::joinProviderList($keys);
        }

        // 明文 Key 只在显式传入且非脱敏串时更新
        if (array_key_exists('api_key', $payload)) {
            $key = trim((string) $payload['api_key']);
            if ($key !== '' && !str_contains($key, '********')) {
                $data['api_key'] = $key;
            }
        }

        // 模型别名 / 跨供应商 fallback 链：接受数组或 JSON 字符串，形态归一在 GatewayRepository::update 里做
        foreach (['model_aliases', 'fallback_chain'] as $jsonKey) {
            if (array_key_exists($jsonKey, $payload)) {
                $data[$jsonKey] = $payload[$jsonKey];
            }
        }

        if (empty($data)) {
            return Response::json(['ok' => false, 'error' => ['message' => '没有需要更新的字段']], 400);
        }

        $this->gatewayRepository->update($data);

        // 变更明细：凭证类只留前缀，避免审计表里出现明文密钥
        $safe = $data;
        if (isset($safe['api_key'])) {
            $safe['api_key'] = substr((string) $safe['api_key'], 0, 6) . '********';
        }

        Audit::ok('settings.update', [
            'target_type' => 'settings',
            'summary'     => '修改网关设置（' . count($data) . ' 项）：' . implode('、', array_slice(array_keys($data), 0, 8)),
            'detail'      => ['values' => $safe],
        ]);

        return Response::json(['ok' => true, 'message' => '设置已保存']);
    }

    /** POST /api/admin/settings/regenerate-key */
    public function regenerateApiKey(Request $request): Response
    {
        $key = $this->gatewayRepository->regenerateApiKey();
        Logger::info('对外 API Key 已重新生成');

        Audit::ok('settings.regenerate_key', [
            'target_type' => 'settings',
            'summary'     => '重新生成网关对外 API Key',
            'detail'      => ['key_head' => substr($key, 0, 8)],
        ]);

        return Response::json([
            'ok'      => true,
            'message' => '已生成新的 API Key，请及时更新客户端配置',
            'data'    => ['api_key' => $key],
        ]);
    }

    // ---------------------------------------------------------
    // 网络代理
    // ---------------------------------------------------------

    /** GET /api/admin/proxy */
    public function proxy(Request $request): Response
    {
        return Response::json([
            'ok'   => true,
            'data' => array_merge($this->proxyRepository->toSafeArray(), [
                'providers' => array_values(array_map(
                    fn($p) => ['provider_key' => $p['provider_key'], 'display_name' => $p['display_name']],
                    $this->providerRepository->all()
                )),
            ]),
        ]);
    }

    /** POST /api/admin/proxy */
    public function updateProxy(Request $request): Response
    {
        $payload = $request->json();
        $data = [];

        if (array_key_exists('enabled', $payload)) {
            $data['enabled'] = !empty($payload['enabled']) ? 1 : 0;
        }
        if (array_key_exists('host', $payload)) {
            $data['host'] = trim((string) $payload['host']);
        }
        if (array_key_exists('port', $payload)) {
            $data['port'] = (int) $payload['port'];
        }
        if (array_key_exists('username', $payload)) {
            $data['username'] = trim((string) $payload['username']);
        }
        if (array_key_exists('password', $payload)) {
            $password = (string) $payload['password'];
            if (!str_contains($password, '********')) {
                $data['password'] = $password;
            }
        }
        if (array_key_exists('excluded_provider_ids', $payload)) {
            $data['excluded_provider_ids'] = array_values((array) $payload['excluded_provider_ids']);
        }

        if (empty($data)) {
            return Response::json(['ok' => false, 'error' => ['message' => '没有需要更新的字段']], 400);
        }

        if (!empty($data['enabled'])) {
            $host = $data['host'] ?? $this->proxyRepository->current()['host'];
            $port = $data['port'] ?? $this->proxyRepository->current()['port'];
            if ($host === '' || $port <= 0) {
                return Response::json(['ok' => false, 'error' => ['message' => '启用代理时必须填写主机与端口']], 400);
            }
        }

        $this->proxyRepository->update($data);

        $safe = $data;
        if (isset($safe['password'])) {
            $safe['password'] = '********';
        }

        Audit::ok('proxy.update', [
            'target_type' => 'proxy',
            'summary'     => '修改网络代理设置：' . implode('、', array_keys($data)),
            'detail'      => ['values' => $safe],
        ]);

        return Response::json(['ok' => true, 'message' => '代理设置已保存']);
    }
}