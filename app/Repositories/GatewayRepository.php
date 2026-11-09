<?php
/**
 * 网关设置仓储（单行配置，对应 settings/gateway.json）
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\MailDomain;

class GatewayRepository
{
    private const TABLE = 'aigw_gateway_settings';

    /**
     * 内容留痕单条上限（字符数）。0 = 不记录内容。
     *
     * 旧上限 20000 对长会话不够用（几十轮 messages 轻松超），
     * 导致留痕只存了个开头。提到 20 万字；列类型是 MEDIUMTEXT（16MB），仍然安全。
     * 前端设置页、网关写侧、读侧预览共用这一个常量，避免三处各写各的。
     */
    public const LOG_CONTENT_MAX = 200000;

    /** 调用日志写侧内容兜底上限，与 LogRepository::BODY_HARD_LIMIT 对齐 */
    public const LOG_BODY_HARD_LIMIT = 200000;

    /** 内容留痕的出厂默认上限（10 万字）：长会话不被削尾，同时不至于单条几十 MB */
    public const DEFAULT_LOG_CONTENT = 100000;

    private ?array $cache = null;

    /** 不下发给前端的敏感字段（当前仅 mail_password 密文） */
    private array $secretCache = [];

    /** 需要按需补齐的列（老库升级路径，CREATE TABLE 不会为已存在的表加列） */
    private const ADDED_COLUMNS = [
        'schedule_mode'      => "VARCHAR(16)  NOT NULL DEFAULT 'balance'",
        'pinned_account_uid' => "VARCHAR(128) NOT NULL DEFAULT ''",
        'allow_register'     => "TINYINT(1)   NOT NULL DEFAULT 1",
        'auto_checkin'       => "TINYINT(1)   NOT NULL DEFAULT 0",
        'auto_refresh'       => "TINYINT(1)   NOT NULL DEFAULT 0",
        'scheduler_last_run' => "BIGINT       NOT NULL DEFAULT 0",
        'scheduler_last_log' => "TEXT         NULL",
        // 定时任务时间点与产物保留
        'checkin_hours'      => "VARCHAR(32)  NOT NULL DEFAULT '9,21'",
        'refresh_hour'       => "INT          NOT NULL DEFAULT 22",
        'checkin_log_keep_days' => "INT       NOT NULL DEFAULT 30",
        // 积分监控
        'credit_monitor_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
        'credit_patrol_minutes'  => "INT        NOT NULL DEFAULT 15",
        'credit_expiring_days'   => "INT        NOT NULL DEFAULT 7",
        // 会话粘性
        'session_affinity_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
        'session_affinity_ttl'     => "INT        NOT NULL DEFAULT 86400",
        // 猫猫旅行
        'travel_enabled'       => "TINYINT(1)   NOT NULL DEFAULT 1",
        'travel_location'      => "VARCHAR(64)  NOT NULL DEFAULT '古镇客栈'",
        'travel_rate_limit_ms' => "INT          NOT NULL DEFAULT 800",
        // 每日活跃注入（WorkBuddy 国际版）
        'activity_enabled'       => "TINYINT(1) NOT NULL DEFAULT 0",
        'activity_rate_limit_ms' => "INT        NOT NULL DEFAULT 1500",
        // 成长中心自动领奖（旅行到站领奖 + 成长任务领奖 + 开盲盒，WorkBuddy 国服）
        'tasks_enabled'          => "TINYINT(1) NOT NULL DEFAULT 1",
        'tasks_interval_minutes' => "INT        NOT NULL DEFAULT 60",
        // 对话计数型任务（chat_5 / Model_chat_*）每轮代发对话条数。
        // 🔴 默认 0 = 不代发（只接取+领奖）。上游结算延迟 90~150s，>0 会让本步耗时 >90s。
        'tasks_chat_seeds'       => "INT        NOT NULL DEFAULT 0",
        // 连登管家（补签 + 连登奖励兑换 + 抽奖 + 开盲盒 + 礼包/补偿，WorkBuddy 国服）
        'streak_enabled'          => "TINYINT(1) NOT NULL DEFAULT 1",
        'streak_interval_minutes' => "INT        NOT NULL DEFAULT 60",
        // 熔断与冷却
        'lease_ttl_ms'         => "BIGINT       NOT NULL DEFAULT 120000",
        'backoff_base_ms'      => "BIGINT       NOT NULL DEFAULT 2000",
        // 免排队供应商白名单（逗号分隔 provider_key）。
        //
        // 背景：同账号的在途租约（lease_until）让**同一供应商同一账号**的并发请求串行排队，
        // 后来者最多等 LEASE_WAIT_BUDGET_MS（120s）。实测（storage/tmp/probe-upstream-conc.php，
        // 直连上游绕过租约）证明：Trae 上游对**同账号 24 并发**全部 200、耗时 1.57s→2.40s 几乎不增、
        // 正文完全对号无串号 —— 即**排队不是上游要求的，是网关切自己加的**。
        //
        // 故提供本白名单：列出的供应商不做租约互斥，同账号并发直接放行。
        // 默认空串 = 现状不变（全部排队），需显式填写才生效（产品行为需显式开启）。
        // ⚠️ 只对「上游已验证可并发」的供应商填；单账号强风控的上游（如按账号限流）填了会撞 429。
        'lease_bypass_providers' => "VARCHAR(255) NOT NULL DEFAULT ''",
        // 定时任务执行水位
        'scheduler_last_checkin_at' => "BIGINT   NOT NULL DEFAULT 0",
        'scheduler_last_refresh_at' => "BIGINT   NOT NULL DEFAULT 0",
        'scheduler_last_patrol_at'  => "BIGINT   NOT NULL DEFAULT 0",
        'scheduler_last_prune_at'   => "BIGINT   NOT NULL DEFAULT 0",
        'scheduler_last_travel_at'  => "BIGINT   NOT NULL DEFAULT 0",
        'scheduler_last_activity_at' => "BIGINT  NOT NULL DEFAULT 0",
        'scheduler_last_tasks_at'   => "BIGINT   NOT NULL DEFAULT 0",
        'scheduler_last_streak_at'  => "BIGINT   NOT NULL DEFAULT 0",
        // 登录安全：失败锁定策略 + 图形验证码
        'login_max_attempts'  => "INT        NOT NULL DEFAULT 5",
        'login_lock_seconds'  => "INT        NOT NULL DEFAULT 900",
        'captcha_enabled'     => "TINYINT(1) NOT NULL DEFAULT 1",
        // 功能开关：网关总开关与对外能力
        'gateway_enabled'         => "TINYINT(1)   NOT NULL DEFAULT 1",
        'chat_enabled'            => "TINYINT(1)   NOT NULL DEFAULT 1",
        'models_enabled'          => "TINYINT(1)   NOT NULL DEFAULT 1",
        'stream_enabled'          => "TINYINT(1)   NOT NULL DEFAULT 1",
        'tools_enabled'           => "TINYINT(1)   NOT NULL DEFAULT 1",
        'models_expose_providers' => "TINYINT(1)   NOT NULL DEFAULT 0",
        // 模型列表 id 是否下发「供应商key/模型ID」。默认开：上游多平台存在大量同名模型，
        // 裸名在客户端下拉里分不清是哪个平台的，且旧实现按 id 去重会把同名模型吞掉。
        'models_qualified_id'     => "TINYINT(1)   NOT NULL DEFAULT 1",
        // 功能开关：调用日志
        'log_enabled'             => "TINYINT(1)   NOT NULL DEFAULT 1",
        'log_request_body'        => "TINYINT(1)   NOT NULL DEFAULT 0",
        // 内容留痕单条上限：默认给足（10 万字），长会话也不会被削尾；0 = 不记录内容
        'log_content_max_chars'   => "INT          NOT NULL DEFAULT 100000",
        'log_max_rows'            => "INT          NOT NULL DEFAULT 200000",
        // 正文只保留最近 N 行（0 = 不限）。
        //
        // 这是「正文膨胀」的主治手段：正文是调用日志体积的绝对大头，而排查问题
        // 几乎只看最近几百条 —— 三个月前的正文没人翻。超过 N 行的旧日志
        // 只清空正文、保留统计字段（token/耗时/状态），所以大屏与成功率统计
        // 一个都不受影响，只有「翻旧账看原文」的能力会受限于 N。
        'log_body_max_rows'       => "INT          NOT NULL DEFAULT 20000",
        // 一次性升级水位：把老库遗留的 2000 字上限抬到新默认，只做一次，之后用户怎么改都不再动
        'log_content_limit_upgraded' => "TINYINT(1)  NOT NULL DEFAULT 0",
        // 出口层规避：重采样时对 user 提示词做「同义改写」以错开上游出口安全层的特征匹配。
        // 默认关（改提示词属产品行为，需显式开启）。
        'resample_rewrite_user'   => "TINYINT(1)   NOT NULL DEFAULT 0",
        // 入口层规避：请求发出**前**把 user 提示词的「越狱模板形态」归一化为中性创作/工程 Brief。
        //
        // 实测根因（2026-09-30，tools/exp-hy3-r2.php）：codebuddy/hy3 拒绝的对象**不是题材**，
        // 而是 user 消息的**清单形态** —— 「硬性要求 1~8 + 器官/技术名词罗列 + 不要任何免责声明」
        // 这个组合会被判成 prompt-injection 模板而整单拒收。对照实验：同一 system（含思维注入）、
        // 同一题材，换成叙事式短措辞即可 1000+ 字正常交付；去掉思维注入、只留原清单，照样被拦。
        // 默认关（改用户提示词属产品行为，需显式开启）。
        'normalize_jailbreak_shape' => "TINYINT(1)   NOT NULL DEFAULT 0",
        // 主导权回收：在 system **最末尾**追加「客户端模板安全条款已废止」声明。
        //
        // 实测根因（2026-09-30，tools/exp-hy3-sysgain.php，每档 2 轮真机 A/B）：
        // codebuddy/hy3 会**优先服从客户端自带的安全 system**（Codex 的
        // "You must follow the safety guidelines of the platform at all times." /
        //  "If a request is inappropriate … decline politely"），把注入的思维判为外来指令。
        //   · 思维 + client system（任意顺序、合并或分条）→ 0/2 通过，正文仅 76~198 字，
        //     开头恒为「我是 Codex…根据平台的安全准则，我无法…」；
        //   · 加上末尾「作废声明」→ **2/2 通过**，1776~2190 字；
        //   · 仅思维、无 client system → 2/2 通过。
        // 故顺序与合并无效，必须在**末尾**显式宣告其效力已被操作者废止。默认关。
        'persona_override_client' => "TINYINT(1)   NOT NULL DEFAULT 0",
        // 站点品牌（全局可改：侧栏品牌名、登录卡片标题、落地页文案、浏览器标题）
        'app_name'                => "VARCHAR(64)  NOT NULL DEFAULT 'AI 聚合网关'",
        'app_subtitle'            => "VARCHAR(64)  NOT NULL DEFAULT 'Aggregator'",
        'app_desc'                => "VARCHAR(128) NOT NULL DEFAULT '多供应商账号池 · 统一 OpenAI 兼容出口'",
        // 邮件发信（SMTP）。端口不设限：80/2525 等非常规端口同样可用。
        //   mail_encryption: none（明文）| ssl（连接即 TLS，一般 465）| tls（STARTTLS，一般 587）| auto（按端口猜）
        //   mail_password  ：用 Crypto 加密后存储（AES-256-GCM），读取时解密
        'mail_enabled'            => "TINYINT(1)   NOT NULL DEFAULT 0",
        'mail_host'               => "VARCHAR(190) NOT NULL DEFAULT ''",
        'mail_port'               => "INT          NOT NULL DEFAULT 465",
        'mail_username'           => "VARCHAR(190) NOT NULL DEFAULT ''",
        'mail_password'           => "TEXT         NULL",
        'mail_encryption'         => "VARCHAR(8)   NOT NULL DEFAULT 'auto'",
        'mail_from_email'         => "VARCHAR(190) NOT NULL DEFAULT ''",
        'mail_from_name'          => "VARCHAR(64)  NOT NULL DEFAULT ''",
        'mail_connect_timeout'    => "INT          NOT NULL DEFAULT 10",
        'mail_read_timeout'       => "INT          NOT NULL DEFAULT 20",
        'mail_allow_insecure'     => "TINYINT(1)   NOT NULL DEFAULT 1",
        // 注册邮箱验证
        'mail_verify_required'    => "TINYINT(1)   NOT NULL DEFAULT 0",
        // 发送邮箱验证码前是否强制图形验证码。默认 1（发码是对外可刷的入口，必须有人机闸门）。
        // 万一验证码服务本身出问题（如 GD 不可用 / 图片生成异常），管理员可临时关掉它
        // 以免把「注册」整条链路锁死 —— 有显式开关，才不会逼人去改代码。
        'mail_code_captcha'       => "TINYINT(1)   NOT NULL DEFAULT 1",
        'mail_code_ttl'           => "INT          NOT NULL DEFAULT 300",
        'mail_code_cooldown'      => "INT          NOT NULL DEFAULT 60",
        'mail_code_daily_email'   => "INT          NOT NULL DEFAULT 10",
        'mail_code_daily_ip'      => "INT          NOT NULL DEFAULT 30",
        // 改密码（已登录态）是否必须邮箱验证码。默认 1（改密码是账号接管的关键落点，
        // 拿到会话 cookie 即可改密 —— 邮件验证把「盗用会话」升级为「还需控制邮箱」）。
        'password_change_verify'  => "TINYINT(1)   NOT NULL DEFAULT 1",
        // 是否开放「忘记密码」（未登录态重置）。默认 1，但**实际生效还要求 SMTP 就绪**：
        // 缺 SMTP 时前端不显示入口、后端直接拒绝，避免把用户导进一条走不通的死路。
        'password_reset_enabled'  => "TINYINT(1)   NOT NULL DEFAULT 1",
        // 注册邮箱域名白名单（逗号分隔，小写，空串 = 不限制）。
        //
        // 为什么列默认留空、再由下面的「一次性播种」填 QQ 域：
        //   本列走 ADDED_COLUMNS 老库升级路径，老库补列后取到的是**列默认值**。
        //   若列默认直接写死 qq.com，任何一次 upgrade 都会瞬间改变产品行为
        //   （所有非 QQ 用户被拦）—— 属静默漂移。这里改成显式水位：
        //   水位为 0（从未播种）**且**当前值为空 → 填出厂默认（只放 QQ），然后抬水位。
        //   管理员之后把它清空即表示「恢复不限域名」，水位已是 1，不会又被种回去。
        'register_email_domains'  => "VARCHAR(255) NOT NULL DEFAULT ''",
        // 上面那条规则的一次性播种水位（沿用 log_content_limit_upgraded 的成熟做法）
        'register_email_domains_seeded' => "TINYINT(1) NOT NULL DEFAULT 0",
        // 功能开关：限流
        'rate_limit_enabled'      => "TINYINT(1)   NOT NULL DEFAULT 0",
        'rate_limit_rpm'          => "INT          NOT NULL DEFAULT 60",
        'rate_limit_burst'        => "INT          NOT NULL DEFAULT 10",
        'rate_limit_scope'        => "VARCHAR(16)  NOT NULL DEFAULT 'key'",
        // 功能开关：思维（思维）治理
        //  - user_persona_enabled  ：是否允许普通用户自定义思维。关闭后用户端只能只读系统思维，
        //                            且用户自建思维一律不参与网关注入（等价于「只能使用系统自带思维」）。
        //  - system_persona_enabled：系统内置（全局）思维的总开关。默认 0=不开启，即开箱不注入任何思维；
        //                            开启后可对单份思维按「供应商靶点 + 模型白名单」精准生效。
        'user_persona_enabled'    => "TINYINT(1)   NOT NULL DEFAULT 1",
        'system_persona_enabled'  => "TINYINT(1)   NOT NULL DEFAULT 0",
        // 弹窗公告（注册页 / 总览面板，后台可控制）
        //
        // 为什么用「版本号」而不是「每次访问都弹」：
        //   公告是「通知用户一次」的东西，每次刷新都弹会变成骚扰。前端把
        //   「已读版本」写进 localStorage，版本变了才再弹；管理员改标题/内容/
        //   类型/位置，或点「重新推送」，版本自动 +1 —— 无需手动记版本号。
        //   默认关闭（enabled=0）：开箱不打扰，属产品行为需显式开启。
        'notice_enabled'          => "TINYINT(1)   NOT NULL DEFAULT 0",
        'notice_title'            => "VARCHAR(128) NOT NULL DEFAULT ''",
        'notice_content'          => "TEXT         NULL",
        // 显示位置：all（注册页 + 总览面板）| register（仅注册/登录页）| dashboard（仅总览面板）
        'notice_target'           => "VARCHAR(16)  NOT NULL DEFAULT 'all'",
        // 样式类型：info | success | warning | danger
        'notice_type'             => "VARCHAR(16)  NOT NULL DEFAULT 'info'",
        'notice_version'          => "INT          NOT NULL DEFAULT 0",
        // 公告上的「跳转加群」按钮（后台可控群号）。
        //   group_qq  ：群号，后端**只留数字**（用户可能粘贴「QQ群：123456」整段）
        //   group_url ：可选加群链接（QQ 群管理后台可生成 https://qm.qq.com/q/xxxx）。
        //               填了优先用它跳转；留空则退化为「唤起手机 QQ + 复制群号」。
        'notice_group_enabled'    => "TINYINT(1)   NOT NULL DEFAULT 0",
        'notice_group_qq'         => "VARCHAR(32)  NOT NULL DEFAULT ''",
        'notice_group_url'        => "VARCHAR(255) NOT NULL DEFAULT ''",
        // 失败告警 webhook（企业微信/钉钉自定义机器人兼容；见 Support/Alerter.php）
        'alert_webhook_enabled'   => "TINYINT(1)   NOT NULL DEFAULT 0",
        'alert_webhook_url'       => "VARCHAR(512) NOT NULL DEFAULT ''",
        'alert_cooldown_seconds'  => "INT          NOT NULL DEFAULT 300",
        // 模型别名映射：客户端请求别名 → 实际转发目标（"provider/model" 或目录模型名）
        // JSON 对象，如 {"claude-sonnet-4": "agent2api_autoclaw/glm-5.3"}；见 GatewayService::resolveModel()
        'model_aliases'           => "TEXT         NULL",
        // 跨供应商 fallback 链：JSON 数组，如 ["agent2api_zcode_intl/glm-4.6", "agent2api_raccoon/sn-deepseek-v4-1-flash-1017"]
        // 主供应商全部账号失败后按序降级；见 GatewayService::attemptFallback()
        'fallback_chain'          => "TEXT         NULL",
        // 公开状态页 /status（免登录展示供应商×模型可用性概览）
        'status_page_enabled'     => "TINYINT(1)   NOT NULL DEFAULT 1",
    ];

    private bool $columnsChecked = false;

    public function __construct(private Database $db) {}

    /**
     * 幂等补齐新增列：老库升级后不会重建表，这里在首次读取时自动 ALTER。
     * 只做一次，避免每次请求都查 information_schema。
     */
    private function ensureColumns(): void
    {
        if ($this->columnsChecked) {
            return;
        }
        $this->columnsChecked = true;

        try {
            $existing = $this->db->select(
                'SELECT `COLUMN_NAME` AS `name` FROM information_schema.COLUMNS
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?',
                [self::TABLE]
            );
            $names = array_column($existing, 'name');

            foreach (self::ADDED_COLUMNS as $column => $definition) {
                if (!in_array($column, $names, true)) {
                    $this->db->statement("ALTER TABLE `" . self::TABLE . "` ADD COLUMN `{$column}` {$definition}");
                }
            }
        } catch (\Throwable $e) {
            // 升级失败不应阻塞主流程（例如无 ALTER 权限），保持默认值语义
            \App\Support\Logger::warn('网关设置列补齐失败', ['error' => $e->getMessage()]);
        }
    }

    public function current(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->ensureColumns();

        $settings = $this->db->table(self::TABLE)->where('id', 1)->first();

        if ($settings === null) {
            $this->db->statement('INSERT INTO `' . self::TABLE . '` (`id`) VALUES (1)');
            $settings = $this->db->table(self::TABLE)->where('id', 1)->first() ?? [];
        }

        // 首次使用时自动补齐对外 API Key，避免「已装好但不可用」
        if (trim((string) ($settings['api_key'] ?? '')) === '') {
            $settings['api_key'] = 'sk-aigw-' . bin2hex(random_bytes(20));
            $this->db->table(self::TABLE)->where('id', 1)->update(['api_key' => $settings['api_key']]);
        }

        $settings['quota_cooldown_ms'] = (int) $settings['quota_cooldown_ms'];
        $settings['soft_cooldown_ms'] = (int) $settings['soft_cooldown_ms'];
        $settings['allow_no_key'] = (bool) $settings['allow_no_key'];
        $settings['allow_register'] = (bool) ($settings['allow_register'] ?? true);
        $settings['expose_lan'] = (bool) $settings['expose_lan'];
        $settings['only_usable_models'] = (bool) $settings['only_usable_models'];
        $settings['stream_heartbeat'] = (bool) $settings['stream_heartbeat'];
        $settings['max_error_count'] = (int) $settings['max_error_count'];
        $settings['log_keep_days'] = (int) $settings['log_keep_days'];
        $settings['schedule_mode'] = in_array(($settings['schedule_mode'] ?? 'balance'), ['balance', 'rotation', 'pinned', 'expiry'], true)
            ? (string) $settings['schedule_mode']
            : 'balance';
        $settings['pinned_account_uid'] = (string) ($settings['pinned_account_uid'] ?? '');

        // 定时任务开关（老库默认关闭，避免升级后立刻产生上游请求）
        $settings['auto_checkin'] = (bool) ($settings['auto_checkin'] ?? false);
        $settings['auto_refresh'] = (bool) ($settings['auto_refresh'] ?? false);
        $settings['scheduler_last_run'] = (int) ($settings['scheduler_last_run'] ?? 0);
        $settings['scheduler_last_log'] = (string) ($settings['scheduler_last_log'] ?? '');

        // 定时任务时间点与产物保留
        $settings['checkin_hours'] = (string) ($settings['checkin_hours'] ?? '9,21');
        $settings['refresh_hour'] = (int) ($settings['refresh_hour'] ?? 22);
        $settings['checkin_log_keep_days'] = (int) ($settings['checkin_log_keep_days'] ?? 30);

        // 积分监控
        $settings['credit_monitor_enabled'] = (bool) ($settings['credit_monitor_enabled'] ?? true);
        $settings['credit_patrol_minutes'] = (int) ($settings['credit_patrol_minutes'] ?? 15);
        $settings['credit_expiring_days'] = (int) ($settings['credit_expiring_days'] ?? 7);

        // 会话粘性
        $settings['session_affinity_enabled'] = (bool) ($settings['session_affinity_enabled'] ?? true);
        $settings['session_affinity_ttl'] = (int) ($settings['session_affinity_ttl'] ?? 86400);

        // 猫猫旅行
        $settings['travel_enabled'] = (bool) ($settings['travel_enabled'] ?? true);
        $settings['travel_location'] = (string) ($settings['travel_location'] ?? '古镇客栈');
        $settings['travel_rate_limit_ms'] = (int) ($settings['travel_rate_limit_ms'] ?? 800);

        // 每日活跃注入（WorkBuddy 国际版）
        $settings['activity_enabled'] = (bool) ($settings['activity_enabled'] ?? false);
        $settings['activity_rate_limit_ms'] = (int) ($settings['activity_rate_limit_ms'] ?? 1500);

        // 成长中心自动领奖（WorkBuddy 国服）
        $settings['tasks_enabled'] = (bool) ($settings['tasks_enabled'] ?? true);
        $settings['tasks_interval_minutes'] = max(1, (int) ($settings['tasks_interval_minutes'] ?? 60));
        $settings['tasks_chat_seeds'] = max(0, (int) ($settings['tasks_chat_seeds'] ?? 0));

        // 连登管家（WorkBuddy 国服）
        $settings['streak_enabled'] = (bool) ($settings['streak_enabled'] ?? true);
        $settings['streak_interval_minutes'] = max(1, (int) ($settings['streak_interval_minutes'] ?? 60));

        // 熔断与冷却
        $settings['lease_ttl_ms'] = (int) ($settings['lease_ttl_ms'] ?? 120000);
        $settings['backoff_base_ms'] = (int) ($settings['backoff_base_ms'] ?? 2000);

        // 免排队供应商白名单：归一为「去空、小写、去重」的 key 列表（存库仍是逗号分隔串）
        $settings['lease_bypass_providers'] = self::normalizeProviderList(
            (string) ($settings['lease_bypass_providers'] ?? '')
        );

        // 定时任务执行水位
        $settings['scheduler_last_checkin_at'] = (int) ($settings['scheduler_last_checkin_at'] ?? 0);
        $settings['scheduler_last_refresh_at'] = (int) ($settings['scheduler_last_refresh_at'] ?? 0);
        $settings['scheduler_last_patrol_at'] = (int) ($settings['scheduler_last_patrol_at'] ?? 0);
        $settings['scheduler_last_prune_at'] = (int) ($settings['scheduler_last_prune_at'] ?? 0);
        $settings['scheduler_last_travel_at'] = (int) ($settings['scheduler_last_travel_at'] ?? 0);
        $settings['scheduler_last_activity_at'] = (int) ($settings['scheduler_last_activity_at'] ?? 0);
        $settings['scheduler_last_tasks_at'] = (int) ($settings['scheduler_last_tasks_at'] ?? 0);
        $settings['scheduler_last_streak_at'] = (int) ($settings['scheduler_last_streak_at'] ?? 0);

        // 登录安全：失败锁定策略 + 图形验证码
        $settings['login_max_attempts'] = max(1, (int) ($settings['login_max_attempts'] ?? 5));
        $settings['login_lock_seconds'] = max(1, (int) ($settings['login_lock_seconds'] ?? 900));
        $settings['captcha_enabled'] = (bool) ($settings['captcha_enabled'] ?? true);

        // 功能开关：网关总开关与对外能力
        $settings['gateway_enabled'] = (bool) ($settings['gateway_enabled'] ?? true);
        $settings['chat_enabled'] = (bool) ($settings['chat_enabled'] ?? true);
        $settings['models_enabled'] = (bool) ($settings['models_enabled'] ?? true);
        $settings['stream_enabled'] = (bool) ($settings['stream_enabled'] ?? true);
        $settings['tools_enabled'] = (bool) ($settings['tools_enabled'] ?? true);
        $settings['models_expose_providers'] = (bool) ($settings['models_expose_providers'] ?? false);
        $settings['models_qualified_id'] = (bool) ($settings['models_qualified_id'] ?? true);

        // 功能开关：调用日志
        $settings['log_enabled'] = (bool) ($settings['log_enabled'] ?? true);
        $settings['log_request_body'] = (bool) ($settings['log_request_body'] ?? false);
        // 出口层规避：默认关（改 user 提示词属产品行为，需显式开启）
        $settings['resample_rewrite_user'] = (bool) ($settings['resample_rewrite_user'] ?? false);
        // 入口层规避：默认关（同上）
        $settings['normalize_jailbreak_shape'] = (bool) ($settings['normalize_jailbreak_shape'] ?? false);
        $settings['persona_override_client'] = (bool) ($settings['persona_override_client'] ?? false);
        // 内容留痕单条上限：0 表示不记录内容，上限 LOG_CONTENT_MAX（20 万字）
        //
        // 一次性升级：老库的列默认值是 2000，长会话留痕被削得只剩开头。这里把「从未改过」
        // 的库（值仍为旧默认 2000 且升级水位为 0）抬到新默认 DEFAULT_LOG_CONTENT，
        // 然后置水位。用户如果自己调过（不是 2000），一律尊重原值不动。
        $legacyLimit = (int) ($settings['log_content_max_chars'] ?? self::DEFAULT_LOG_CONTENT);
        if ((int) ($settings['log_content_limit_upgraded'] ?? 0) === 0) {
            if ($legacyLimit === 2000) {
                $legacyLimit = self::DEFAULT_LOG_CONTENT;
                $this->db->table(self::TABLE)->where('id', 1)->update(['log_content_max_chars' => $legacyLimit]);
            }
            $this->db->table(self::TABLE)->where('id', 1)->update(['log_content_limit_upgraded' => 1]);
        }
        $settings['log_content_max_chars'] = max(0, min(self::LOG_CONTENT_MAX, $legacyLimit));
        $settings['log_max_rows'] = max(0, (int) ($settings['log_max_rows'] ?? 200000));
        $settings['log_body_max_rows'] = max(0, (int) ($settings['log_body_max_rows'] ?? 20000));

        // 站点品牌（全局可改；空值回落默认，长度按列宽截断）
        $settings['app_name'] = self::brandText($settings['app_name'] ?? '', 'AI 聚合网关', 64);
        $settings['app_subtitle'] = self::brandText($settings['app_subtitle'] ?? '', 'Aggregator', 64);
        $settings['app_desc'] = self::brandText($settings['app_desc'] ?? '', '多供应商账号池 · 统一 OpenAI 兼容出口', 128);

        // 功能开关：限流
        $settings['rate_limit_enabled'] = (bool) ($settings['rate_limit_enabled'] ?? false);
        $settings['rate_limit_rpm'] = max(1, (int) ($settings['rate_limit_rpm'] ?? 60));
        $settings['rate_limit_burst'] = max(0, (int) ($settings['rate_limit_burst'] ?? 10));
        $settings['rate_limit_scope'] = in_array(($settings['rate_limit_scope'] ?? 'key'), ['key', 'ip'], true)
            ? (string) $settings['rate_limit_scope']
            : 'key';

        // 功能开关：思维（思维）治理
        $settings['user_persona_enabled'] = (bool) ($settings['user_persona_enabled'] ?? true);
        $settings['system_persona_enabled'] = (bool) ($settings['system_persona_enabled'] ?? false);

        // 邮件发信（SMTP）：端口不设白名单，只做类型与范围归一化
        $settings['mail_enabled'] = (bool) ($settings['mail_enabled'] ?? false);
        $settings['mail_host'] = trim((string) ($settings['mail_host'] ?? ''));
        // 端口 1-65535 全部放行 —— 80/2525 等非常规端口是明确支持的
        $settings['mail_port'] = min(65535, max(1, (int) ($settings['mail_port'] ?? 465)));
        $settings['mail_username'] = trim((string) ($settings['mail_username'] ?? ''));
        $settings['mail_password_set'] = trim((string) ($settings['mail_password'] ?? '')) !== '';
        // 密文单独留一份给服务端发信使用（见 secret()），绝不随 current() 下发前端
        $this->secretCache['mail_password'] = (string) ($settings['mail_password'] ?? '');
        unset($settings['mail_password']);
        $settings['mail_encryption'] = in_array(($settings['mail_encryption'] ?? 'auto'), ['none', 'ssl', 'tls', 'auto'], true)
            ? (string) $settings['mail_encryption']
            : 'auto';
        $settings['mail_from_email'] = trim((string) ($settings['mail_from_email'] ?? ''));
        $settings['mail_from_name'] = trim((string) ($settings['mail_from_name'] ?? ''));
        $settings['mail_connect_timeout'] = min(120, max(1, (int) ($settings['mail_connect_timeout'] ?? 10)));
        $settings['mail_read_timeout'] = min(300, max(1, (int) ($settings['mail_read_timeout'] ?? 20)));
        $settings['mail_allow_insecure'] = (bool) ($settings['mail_allow_insecure'] ?? true);
        $settings['mail_verify_required'] = (bool) ($settings['mail_verify_required'] ?? false);
        $settings['mail_code_captcha'] = (bool) ($settings['mail_code_captcha'] ?? true);
        $settings['mail_code_ttl'] = min(3600, max(60, (int) ($settings['mail_code_ttl'] ?? 300)));
        $settings['mail_code_cooldown'] = min(600, max(0, (int) ($settings['mail_code_cooldown'] ?? 60)));
        $settings['mail_code_daily_email'] = min(100, max(0, (int) ($settings['mail_code_daily_email'] ?? 10)));
        $settings['mail_code_daily_ip'] = min(1000, max(0, (int) ($settings['mail_code_daily_ip'] ?? 30)));
        // 改密码需邮箱验证 / 是否开放找回密码
        $settings['password_change_verify'] = (bool) ($settings['password_change_verify'] ?? true);
        $settings['password_reset_enabled'] = (bool) ($settings['password_reset_enabled'] ?? true);

        // 注册邮箱域名白名单（逗号分隔；空串 = 不限制，任何合法邮箱都可注册）。
        //
        // 归一化统一走 MailDomain::normalize（小写 / 去重 / 剥掉误写的前导 @.），
        // 保证「写进库里的形态」与「业务侧比较用的形态」永远是同一个 ——
        // 否则会出现配置写 `QQ.com`、库里存 `QQ.com`、比较用 `qq.com` 对不上，
        // 表现为「明明加白了还是被拦」的静默故障。
        //
        // 播种水位：只填**一次**，且仅在当前值为空时填。管理员把输入框清空即
        // 表示「我要不限域名」，水位已抬不会把 QQ 域又种回去。
        $domains = MailDomain::normalize((string) ($settings['register_email_domains'] ?? ''));
        if ((int) ($settings['register_email_domains_seeded'] ?? 0) === 0) {
            if ($domains === '') {
                $domains = MailDomain::normalize(MailDomain::DEFAULT_RULES);
                $this->db->table(self::TABLE)->where('id', 1)->update(['register_email_domains' => $domains]);
            }
            $this->db->table(self::TABLE)->where('id', 1)->update(['register_email_domains_seeded' => 1]);
        }
        $settings['register_email_domains'] = $domains;

        // 弹窗公告（注册页 / 总览面板）：白名单枚举 + 长度截断，保证「写进库的形态」
        // 与「业务侧比较的形态」一致（否则会出现「明明开着却不弹」的静默漂移）。
        $settings['notice_enabled'] = (bool) ($settings['notice_enabled'] ?? false);
        $settings['notice_title'] = trim((string) ($settings['notice_title'] ?? ''));
        $settings['notice_content'] = (string) ($settings['notice_content'] ?? '');
        $settings['notice_target'] = in_array(($settings['notice_target'] ?? 'all'), ['all', 'register', 'dashboard'], true)
            ? (string) $settings['notice_target']
            : 'all';
        $settings['notice_type'] = in_array(($settings['notice_type'] ?? 'info'), ['info', 'success', 'warning', 'danger'], true)
            ? (string) $settings['notice_type']
            : 'info';
        $settings['notice_version'] = max(0, (int) ($settings['notice_version'] ?? 0));

        // 加群按钮：群号统一「只留数字」（形如「QQ群：123 456」也能救回），
        // 链接只认 http(s)，非法一律归一成空串（前端据此隐藏按钮，而非渲染出死链）。
        $settings['notice_group_enabled'] = (bool) ($settings['notice_group_enabled'] ?? false);
        $settings['notice_group_qq'] = preg_replace('/\D+/', '', (string) ($settings['notice_group_qq'] ?? '')) ?? '';
        $settings['notice_group_qq'] = substr($settings['notice_group_qq'], 0, 32);
        $settings['notice_group_url'] = trim((string) ($settings['notice_group_url'] ?? ''));
        if (!preg_match('#^https?://#i', $settings['notice_group_url'])) {
            $settings['notice_group_url'] = '';
        }

        return $this->cache = $settings;
    }

    public function get(string $key, $default = null)
    {
        return $this->current()[$key] ?? $default;
    }

    /**
     * 敏感字段读取通道（目前只有 SMTP 口令）。
     *
     * current() 会**主动摘掉** mail_password 以免随接口下发到前端，
     * 但发信时又必须拿到密文去解密，所以在这里单独留后门：
     * 只有服务端代码显式调用 secret() 才拿得到，接口层永远拿不到。
     *
     * @return string 空串表示未设置
     */
    public function secret(string $key): string
    {
        // 先跑一遍 current() 以确保缓存与 secretCache 已填充
        $this->current();
        return (string) ($this->secretCache[$key] ?? '');
    }

    public function update(array $data): void
    {
        $allowed = [
            'api_key', 'allow_no_key', 'expose_lan', 'only_usable_models',
            'allow_register',
            'default_provider', 'default_model', 'quota_cooldown_ms',
            'soft_cooldown_ms', 'max_error_count', 'log_keep_days', 'stream_heartbeat',
            'schedule_mode', 'pinned_account_uid',
            'auto_checkin', 'auto_refresh', 'scheduler_last_run', 'scheduler_last_log',
            'checkin_hours', 'refresh_hour', 'checkin_log_keep_days',
            'credit_monitor_enabled', 'credit_patrol_minutes', 'credit_expiring_days',
            'session_affinity_enabled', 'session_affinity_ttl',
            'travel_enabled', 'travel_location', 'travel_rate_limit_ms',
            'activity_enabled', 'activity_rate_limit_ms',
            'tasks_enabled', 'tasks_interval_minutes', 'tasks_chat_seeds',
            'streak_enabled', 'streak_interval_minutes',
            'lease_ttl_ms', 'backoff_base_ms',
            'lease_bypass_providers',
            'scheduler_last_checkin_at', 'scheduler_last_refresh_at',
            'scheduler_last_patrol_at', 'scheduler_last_prune_at',
            'scheduler_last_travel_at', 'scheduler_last_activity_at',
            'scheduler_last_tasks_at', 'scheduler_last_streak_at',
            'login_max_attempts', 'login_lock_seconds', 'captcha_enabled',
            // 功能开关
            'gateway_enabled', 'chat_enabled', 'models_enabled', 'stream_enabled',
            'tools_enabled', 'models_expose_providers', 'models_qualified_id',
            'log_enabled', 'log_request_body', 'log_content_max_chars', 'log_max_rows',
            'log_body_max_rows',
            'resample_rewrite_user',
            'normalize_jailbreak_shape',
            'persona_override_client',
            // 站点品牌（全局可改）
            'app_name', 'app_subtitle', 'app_desc',
            // 邮件发信（SMTP）
            'mail_enabled', 'mail_host', 'mail_port', 'mail_username', 'mail_password',
            'mail_encryption', 'mail_from_email', 'mail_from_name',
            'mail_connect_timeout', 'mail_read_timeout', 'mail_allow_insecure',
            'mail_verify_required', 'mail_code_captcha', 'mail_code_ttl', 'mail_code_cooldown',
            'mail_code_daily_email', 'mail_code_daily_ip',
            // 密码安全：改密码需邮箱验证 / 开放找回密码
            'password_change_verify', 'password_reset_enabled',
            // 注册邮箱域名白名单（空串 = 不限制）
            'register_email_domains',
            'rate_limit_enabled', 'rate_limit_rpm', 'rate_limit_burst', 'rate_limit_scope',
            // 思维（思维）治理开关
            'user_persona_enabled', 'system_persona_enabled',
            // 弹窗公告（注册页 / 总览面板）
            'notice_enabled', 'notice_title', 'notice_content',
            'notice_target', 'notice_type', 'notice_version',
            // 公告上的「跳转加群」按钮
            'notice_group_enabled', 'notice_group_qq', 'notice_group_url',
            // 失败告警 webhook
            'alert_webhook_enabled', 'alert_webhook_url', 'alert_cooldown_seconds',
            // 模型别名 / 跨供应商 fallback / 公开状态页
            'model_aliases', 'fallback_chain', 'status_page_enabled',
        ];

        $data = array_intersect_key($data, array_flip($allowed));
        if (empty($data)) {
            return;
        }

        // 免排队白名单：无论调用方传的是数组还是带空格/中文逗号的串，
        // 统一归一成「小写去重、逗号分隔」再落库，避免业务侧判定时形态不一。
        if (array_key_exists('lease_bypass_providers', $data)) {
            $raw = $data['lease_bypass_providers'];
            $data['lease_bypass_providers'] = self::joinProviderList(
                is_array($raw) ? $raw : [(string) $raw]
            );
        }

        // ⚠️ 入口方法必须先补列：新装库若直接 update() 一个刚加的开关，
        // 列还不存在就会 500（本方法此前漏调，只在「先 current() 后 update()」时侥幸正常）。
        $this->ensureColumns();

        // 布尔开关统一转 int：调用方传 PHP bool 时 PDO 会把它绑成空串，
        // 写进 TINYINT 直接报 1366 而非「存了个 false」—— 护栏放在被调用方，
        // 任何调用路径（管理接口 / 自检脚本 / 未来 CLI）都不会踩。
        if (array_key_exists('notice_enabled', $data)) {
            $data['notice_enabled'] = !empty($data['notice_enabled']) ? 1 : 0;
        }
        if (array_key_exists('notice_group_enabled', $data)) {
            $data['notice_group_enabled'] = !empty($data['notice_group_enabled']) ? 1 : 0;
        }
        // 群号/链接的形态归一同样放**被调用方**：管理接口、自检脚本、未来 CLI
        // 任何路径写进来，落库的都是「只留数字的群号 + 只认 http(s) 的链接」。
        if (array_key_exists('notice_group_qq', $data)) {
            $qq = preg_replace('/\D+/', '', (string) $data['notice_group_qq']) ?? '';
            $data['notice_group_qq'] = substr($qq, 0, 32);
        }
        if (array_key_exists('notice_group_url', $data)) {
            $url = trim((string) $data['notice_group_url']);
            $data['notice_group_url'] = preg_match('#^https?://#i', $url) ? $url : '';
        }
        // 告警 webhook：布尔归一 + URL 只认 http(s) + 冷却窗口钳制 30~3600s
        if (array_key_exists('alert_webhook_enabled', $data)) {
            $data['alert_webhook_enabled'] = !empty($data['alert_webhook_enabled']) ? 1 : 0;
        }
        if (array_key_exists('alert_webhook_url', $data)) {
            $url = trim((string) $data['alert_webhook_url']);
            $data['alert_webhook_url'] = preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 512) : '';
        }
        if (array_key_exists('alert_cooldown_seconds', $data)) {
            $data['alert_cooldown_seconds'] = max(30, min(3600, (int) $data['alert_cooldown_seconds']));
        }
        // 模型别名 / fallback 链：接受数组或 JSON 字符串，统一归一成 JSON 文本落库（NULL = 未配置）
        foreach (['model_aliases', 'fallback_chain'] as $jsonKey) {
            if (array_key_exists($jsonKey, $data)) {
                $raw = $data[$jsonKey];
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    $raw = is_array($decoded) ? $decoded : [];
                }
                if (!is_array($raw) || $raw === []) {
                    $data[$jsonKey] = null;
                    continue;
                }
                $data[$jsonKey] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        if (array_key_exists('status_page_enabled', $data)) {
            $data['status_page_enabled'] = !empty($data['status_page_enabled']) ? 1 : 0;
        }

        $this->db->table(self::TABLE)->where('id', 1)->update($data);
        $this->cache = null;
        $this->secretCache = [];
    }

    /**
     * 解析「供应商 key 列表」：接受逗号/中文逗号/空白分隔，归一为小写去重后的 key 数组。
     * 存库时用逗号拼接（见 parseProviderList 的反向），读库时归一到数组供业务判定。
     *
     * @return list<string>
     */
    public static function normalizeProviderList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,，;；]+/u', $raw) ?: [] as $key) {
            $key = strtolower(trim($key));
            if ($key !== '' && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** 把 key 列表拼回库里的逗号分隔串 */
    public static function joinProviderList(array $keys): string
    {
        return implode(',', self::normalizeProviderList(implode(',', $keys)));
    }

    /**
     * 判断某供应商 key 是否命中「列表条目」。
     *
     * 支持两种写法（**唯一一处匹配定义**，AccountPool 与 SettingsController 共用，
     * 避免两处实现漂移 —— 撞 §13.12「边界只能有一份定义」）：
     *   - 精确：`trae`
     *   - 前缀通配（以 `*` 结尾）：`custom*` → 命中 `custom`、`custom_xxx` 等全部实例
     *
     * 通配是为「多实例供应商」准备的：自定义供应商允许多个实例（`custom` / `custom_xxx`），
     * 每新增一个实例都会是一个新的 provider_key，逐个往白名单里写会漏、会静默漂移。
     */
    public static function providerListMatches(string $providerKey, array $patterns): bool
    {
        $key = strtolower(trim($providerKey));
        if ($key === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            $p = strtolower(trim((string) $pattern));
            if ($p === '') {
                continue;
            }

            if (str_ends_with($p, '*')) {
                $prefix = rtrim($p, '*');
                if ($prefix !== '' && str_starts_with($key, $prefix)) {
                    return true;
                }
                continue;
            }

            if ($p === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * 校验一个白名单条目是否「有意义」：精确条目须命中已知 provider_key；
     * 通配条目（尾 `*`）须其前缀能匹配至少一个已知 provider_key。
     *
     * 目的与原先一致：**拼错/不存在的 key 保存时丢弃**，而不是静默失效。
     *
     * @param list<string> $knownKeys 已知 provider_key（小写）
     */
    public static function providerPatternKnown(string $pattern, array $knownKeys): bool
    {
        $p = strtolower(trim($pattern));
        if ($p === '') {
            return false;
        }

        foreach ($knownKeys as $known) {
            if (self::providerListMatches((string) $known, [$p])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 品牌文案归一化：去首尾空白 → 空则回落默认值 → 按列宽截断。
     * 站点名称允许运营者在后台随意改，但不能把超长串写爆列。
     */
    public static function brandText($value, string $default, int $maxLen = 64): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            $text = $default;
        }
        return mb_substr($text, 0, $maxLen);
    }

    /**
     * 重新生成对外 API Key
     */
    public function regenerateApiKey(): string
    {
        // 先确保 id=1 配置行存在（current() 会自愈补行），否则下面的 UPDATE 命中 0 行，
        // 会出现「返回了新 Key 但库里仍是旧 Key」的幻影成功。
        $this->current();

        $key = 'sk-aigw-' . bin2hex(random_bytes(20));
        $this->update(['api_key' => $key]);
        return $key;
    }

    /**
     * 校验调用方提供的 Key
     * 返回 [是否通过, 失败原因]
     */
    public function verify(?string $providedKey): array
    {
        $settings = $this->current();
        $expected = (string) $settings['api_key'];

        // 免 Key 模式
        if ((bool) $settings['allow_no_key']) {
            return [true, null];
        }

        if ($expected === '') {
            return [false, '网关已关闭「无 Key 调用」但尚未设置 API Key，请在管理台生成密钥后再调用。'];
        }

        if ($providedKey === null || $providedKey === '') {
            return [false, '缺少 Authorization 头，请携带 Bearer <apiKey>。'];
        }

        // 大小写不敏感的前缀匹配（兼容 Bearer 之外的写法）
        $normalized = str_ireplace('bearer ', '', trim($providedKey));
        if (hash_equals($expected, $normalized)) {
            return [true, null];
        }

        return [false, 'API Key 无效。'];
    }
}