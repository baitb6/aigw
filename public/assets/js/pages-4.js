/* ============================================================
   页面组件（续 3）：网关设置 / 网络代理 / 我的账户
   ============================================================ */
(function (global) {
    'use strict';

    var S = global.AigwStore;
    var api = global.AigwApi;
    var fmt = S.fmt;
    var pages = {};

    /* ============================================================
       网关设置
       ============================================================ */
    pages.PageSettings = {
        data: function () {
            return {
                loading: true, saving: false, error: '',
                form: {
                    app_name: 'AI 聚合网关', app_subtitle: 'Aggregator',
                    app_desc: '多供应商账号池 · 统一 OpenAI 兼容出口',
                    // 邮件发信（SMTP）—— 端口不限，80 亦可发信
                    mail_enabled: false, mail_host: '', mail_port: 465,
                    mail_username: '', mail_password: '', mail_password_set: false,
                    mail_encryption: 'auto', mail_from_email: '', mail_from_name: '',
                    mail_connect_timeout: 10, mail_read_timeout: 20, mail_allow_insecure: true,
                    mail_verify_required: false, mail_code_captcha: true, mail_code_ttl: 300, mail_code_cooldown: 60,
                    mail_code_daily_email: 10, mail_code_daily_ip: 30,
                    // 密码安全：改密码需邮箱验证 / 开放找回密码
                    password_change_verify: true, password_reset_enabled: true,
                    // 注册邮箱域名白名单（逗号分隔）；留空 = 不限制任意合法邮箱
                    register_email_domains: 'qq.com,foxmail.com',
                    api_key_masked: '', api_key_set: false, allow_no_key: false,
                    default_provider: '', default_model: '', only_usable_models: true,
                    max_error_count: 5, quota_cooldown_ms: 300000, soft_cooldown_ms: 60000,
                    schedule_mode: 'balance', pinned_account_uid: '',
                    // 免排队供应商白名单（逗号分隔）；空 = 全部排队（默认）
                    lease_bypass_providers: '',
                    expose_lan: false, stream_heartbeat: false, log_keep_days: 30,
                    allow_register: true, captcha_enabled: true,
                    login_max_attempts: 5, login_lock_seconds: 900,
                    // 功能开关：网关能力
                    gateway_enabled: true, chat_enabled: true, models_enabled: true,
                    stream_enabled: true, tools_enabled: true, models_expose_providers: false,
                    models_qualified_id: true,
                    // 功能开关：调用日志
                    log_enabled: true, log_request_body: false, log_content_max_chars: 2000, log_max_rows: 200000, log_body_max_rows: 20000,
                    // 功能开关：限流
                    rate_limit_enabled: false, rate_limit_rpm: 60, rate_limit_burst: 10,
                    rate_limit_scope: 'key',
                    // 功能开关：思维（人格）治理
                    system_persona_enabled: false, user_persona_enabled: true,
                    // 功能开关：内容规避（入口层归一化 / 出口层改写）
                    normalize_jailbreak_shape: false,
                    // 主导权回收（system 末尾废止客户端模板的安全条款）
                    persona_override_client: false,
                    // 弹窗公告（注册页 / 总览面板，后台可控制）
                    notice_enabled: false, notice_title: '', notice_content: '',
                    notice_target: 'all', notice_type: 'info', notice_version: 0,
                    // 公告上的「跳转加群」按钮
                    notice_group_enabled: false, notice_group_qq: '', notice_group_url: '',
                    // 失败告警 webhook（企业微信/钉钉自定义机器人兼容）
                    alert_webhook_enabled: false, alert_webhook_url: '', alert_cooldown_seconds: 300,
                    // 模型别名 / 跨供应商 fallback / 公开状态页（textarea 存 JSON 文本，保存时校验）
                    model_aliases: '', fallback_chain: '', status_page_enabled: true
                },
                providers: [], models: [], accounts: [],
                newKey: '', client: 'openai',
                scheduler: null, schedulerBusy: false,
                templates: [], templateModes: [], templatesBusy: false,
                showTemplate: false, editingTemplate: null,
                templateForm: { name: '', description: '', content: '', mode: 'prepend', enabled: true, is_default: false, sort_order: 0 },
                // 邮件诊断
                mailTesting: false, mailTestTo: '', mailTestResult: null, mailConnTesting: false,
                // 密码安全开关的「意图 vs 实际生效」对照（服务端下发，见 PasswordPolicy）
                // 用途：开关开着但邮件没配好时，后端会静默放行（不要求验证码），
                //       这里把该退化状态显式摆到管理员眼前，避免「以为开了却没生效」。
                // 判据只用服务端下发的客观事实（邮件是否就绪 + 缺什么），
                // 前端不复刻「开关 && 就绪」这份策略，只负责把它显示出来。
                pwMailReady: false, pwMailGaps: []
            };
        },
        computed: {
            isAdmin: function () { return S.state.role !== 'user'; },
            baseUrl: function () { return location.origin; },
            // 密码开关「开着但邮件没配好 → 实际不生效」的告警文案（空串 = 无需提示）。
            //
            // 为什么在前端实时算而不是直接用服务端返回的 warning：
            //   管理员可能刚把 SMTP 相关输入框改好、还没点保存 —— 此时服务端那份
            //   是旧的。这里以「当前表单里的邮件配置」为基准即时判断，拨动开关或
            //   修改 SMTP 立刻就能看到提示是否会消失，反馈闭环更短。
            //   判定条件与服务端 PasswordPolicy 完全一致（三个字段非空/为真）。
            pwEffectiveWarning: function () {
                var f = this.form || {};
                var wantMail = !!(f.password_change_verify || f.password_reset_enabled);
                if (!wantMail) return '';
                // 与服务端 MailService::ready() 对齐：启用 + host 非空 + port>0
                var ready = !!f.mail_enabled
                    && String(f.mail_host || '').trim() !== ''
                    && Number(f.mail_port || 0) > 0;
                if (ready) return '';
                var affected = [];
                if (f.password_change_verify) affected.push('修改密码');
                if (f.password_reset_enabled) affected.push('找回密码');
                return '「' + affected.join('」「') + '」的开关是开启的，但当前邮件服务未配置完整，'
                    + '用户实际不会被要求邮箱验证码（将以「免验证」方式放行）。'
                    + '请先补全下方 SMTP 配置并保存。';
            },
            clientKeyMeta: function () { return this.isAdmin ? 'Key 已脱敏' : '专属 Key'; },
            clientKeyHint: function () {
                return this.isAdmin
                    ? '为安全起见，Key 以脱敏形式展示，复制后请替换为你真实的 API Key'
                    : '这是你的专属 API Key，可直接使用；调用仅会路由到你自己添加的账号';
            },
            scheduleModeLabel: function () {
                var map = { balance: '额度优先 Balance', rotation: '轮询 Rotation', pinned: '固定账号 Pinned' };
                return map[this.form.schedule_mode] || (this.form.schedule_mode || '额度优先 Balance');
            },
            clientOptions: function () {
                return [
                    { value: 'openai', label: 'OpenAI SDK' },
                    { value: 'curl', label: 'cURL' },
                    { value: 'cursor', label: 'Cursor' },
                    { value: 'cline', label: 'Cline' },
                    { value: 'cherry', label: 'Cherry Studio' }
                ];
            },
            clientSnippet: function () {
                var base = this.baseUrl + '/v1';
                var key = this.form.api_key_masked || '<你的 API Key>';
                var model = this.form.default_model || '<模型 ID>';
                if (this.client === 'curl') {
                    return 'curl ' + this.baseUrl + '/v1/chat/completions \\\n' +
                        '  -H "Authorization: Bearer ' + key + '" \\\n' +
                        '  -H "Content-Type: application/json" \\\n' +
                        '  -d \'{"model":"' + model + '","messages":[{"role":"user","content":"你好"}]}\'';
                }
                if (this.client === 'cursor') {
                    return 'Cursor → Settings → Models → OpenAI API Key\n\n' +
                        'API Key      : ' + key + '\n' +
                        'Base URL     : ' + base + '   （勾选 Override OpenAI Base URL）\n' +
                        'Model        : ' + model;
                }
                if (this.client === 'cline') {
                    return 'API Provider : OpenAI Compatible\n' +
                        'Base URL     : ' + base + '\n' +
                        'API Key      : ' + key + '\n' +
                        'Model ID     : ' + model;
                }
                if (this.client === 'cherry') {
                    return '类型         : OpenAI\n' +
                        'API 地址     : ' + base + '\n' +
                        'API 密钥     : ' + key + '\n' +
                        '模型         : ' + model;
                }
                return 'from openai import OpenAI\n\n' +
                    'client = OpenAI(\n' +
                    '    base_url="' + base + '",\n' +
                    '    api_key="' + key + '",\n' +
                    ')\n\n' +
                    'resp = client.chat.completions.create(\n' +
                    '    model="' + model + '",\n' +
                    '    messages=[{"role": "user", "content": "你好"}],\n' +
                    ')\n' +
                    'print(resp.choices[0].message.content)';
            },
            scheduleModeOptions: function () {
                return [
                    { value: 'balance', label: '额度优先 Balance' },
                    { value: 'rotation', label: '轮询 Rotation' },
                    { value: 'pinned', label: '固定账号 Pinned' }
                ];
            },
            pinnedAccountOptions: function () {
                var out = [{ value: '', label: '未选择固定账号' }];
                this.accounts.forEach(function (a) {
                    var label = (a.nickname ? a.nickname + ' · ' : '') + a.provider_key + ' / ' +
                        (a.uid || '').slice(0, 12);
                    out.push({ value: a.uid, label: label });
                });
                return out;
            },
            scheduleHint: function () {
                if (this.form.schedule_mode === 'rotation') {
                    return '请求会依次分配给账号池中调用次数最少的账号，适合多账号均摊额度';
                }
                if (this.form.schedule_mode === 'pinned') {
                    return '始终优先使用下方固定的账号，该账号不可用时才回退到其他账号';
                }
                return '优先使用剩余额度最高的账号，额度未知的排在已知有额度的后面';
            },
            defaultProviderOptions: function () {
                var out = [{ value: '', label: '自动选择（按额度优先）' }];
                this.providers.forEach(function (p) {
                    out.push({ value: p.provider_key, label: p.display_name });
                });
                return out;
            },
            defaultModelOptions: function () {
                var out = [{ value: '', label: '不设置（必须由请求指定）' }];
                var seen = {};
                this.models.forEach(function (g) {
                    (g.models || []).forEach(function (m) {
                        var id = m.model_id || m.id || m.model;
                        if (!id || seen[id]) return;
                        seen[id] = true;
                        out.push({ value: id, label: id });
                    });
                });
                return out;
            },
            rateLimitScopeOptions: function () {
                return [
                    { value: 'key', label: '按 API Key' },
                    { value: 'ip', label: '按来源 IP' }
                ];
            },
            mailEncryptionOptions: function () {
                return [
                    { value: 'auto', label: '自动（按端口推断）' },
                    { value: 'none', label: '明文（不加密）' },
                    { value: 'ssl', label: 'SSL / 隐式 TLS（465）' },
                    { value: 'tls', label: 'STARTTLS（587 / 25）' }
                ];
            },
            templateModeOptions: function () {
                return this.templateModes && this.templateModes.length ? this.templateModes : [
                    { value: 'prepend', label: '前置追加（保留原 system）' },
                    { value: 'append', label: '后置追加（保留原 system）' },
                    { value: 'replace', label: '替换原 system' }
                ];
            },
            /* ── 弹窗公告 ── */
            noticeTargetOptions: function () {
                return [
                    { value: 'all',       label: '注册页 + 总览面板' },
                    { value: 'register',  label: '仅注册 / 登录页' },
                    { value: 'dashboard', label: '仅总览面板' }
                ];
            },
            noticeTypeOptions: function () {
                return [
                    { value: 'info',    label: '信息' },
                    { value: 'success', label: '成功' },
                    { value: 'warning', label: '注意' },
                    { value: 'danger',  label: '警告' }
                ];
            },
            noticeTargetLabel: function () {
                var v = this.form.notice_target, hit = '注册页 + 总览面板';
                this.noticeTargetOptions.forEach(function (o) { if (o.value === v) hit = o.label; });
                return hit;
            },
            noticeTypeLabel: function () {
                var v = this.form.notice_type, hit = '信息';
                this.noticeTypeOptions.forEach(function (o) { if (o.value === v) hit = o.label; });
                return hit;
            },
            /** 公告预览的按行拆分（与前台 AigwNotice 的渲染口径一致） */
            noticePreviewLines: function () {
                var c = String(this.form.notice_content || '');
                return c === '' ? [] : c.split(/\r?\n/);
            },
            noticeHasContent: function () {
                return !!String(this.form.notice_title || '').trim()
                    || !!String(this.form.notice_content || '').trim();
            },
            /* ── 加群按钮（预览口径与前台 AigwNotice 完全一致） ── */
            noticeGroupShown: function () {
                return !!this.form.notice_group_enabled
                    && (!!String(this.form.notice_group_url || '').trim()
                        || !!String(this.form.notice_group_qq || '').trim());
            },
            noticeGroupLabel: function () {
                var qq = String(this.form.notice_group_qq || '').trim();
                return qq ? ('加入交流群 ' + qq) : '加入交流群';
            },
            /** 开关开着却什么都没填 → 前台不会有按钮，属于「配了不生效」，需红字提示 */
            noticeGroupIncomplete: function () {
                return !!this.form.notice_group_enabled
                    && !String(this.form.notice_group_url || '').trim()
                    && !String(this.form.notice_group_qq || '').trim();
            }
        },
        mounted: function () { this.load(); },
        methods: {
            load: function () {
                var self = this;
                this.loading = true;

                // 普通用户：仅拉取只读的接入信息（网关地址 + 本人 API Key）
                if (!this.isAdmin) {
                    api.user.settings().then(function (res) {
                        self.loading = false;
                        if (res.ok && res.data) {
                            var u = res.data;
                            self.form.api_key_masked = u.api_key || '';
                            self.form.api_key_set = !!u.api_key;
                            self.form.default_provider = u.default_provider || '';
                            self.form.default_model = u.default_model || '';
                            self.form.schedule_mode = u.schedule_mode || 'balance';
                            self.form.only_usable_models = !!u.only_usable_models;
                            self.form.gateway_enabled = u.gateway_enabled !== false;
                            self.form.stream_enabled = u.stream_enabled !== false;
                            self.form.tools_enabled = u.tools_enabled !== false;
                            self.accounts = [];
                            self.error = '';
                        } else {
                            self.error = res.error || '加载设置失败';
                        }
                    });
                    this.loadTemplates();
                    return;
                }

                Promise.all([api.admin.settings(), api.admin.providers(), api.admin.models()])
                    .then(function (results) {
                        self.loading = false;
                        var s = results[0], p = results[1], m = results[2];

                        if (s.ok && s.data) {
                            var d = s.data.settings || s.data;
                            Object.keys(self.form).forEach(function (k) {
                                if (d[k] !== undefined && d[k] !== null) self.form[k] = d[k];
                            });
                            self.accounts = d.accounts || [];
                            // 邮件就绪 / 缺口（服务端客观事实，供告警文案参考）
                            self.pwMailReady = !!d.mail_ready;
                            self.pwMailGaps = d.mail_gaps || [];
                            self.error = '';
                        } else if (!s.ok) {
                            self.error = s.error || '加载设置失败';
                        }

                        if (p.ok) self.providers = (p.data && p.data.items) || [];
                        if (m.ok) self.models = (m.data && m.data.groups) || [];
                    });
                self.loadScheduler();
                this.loadTemplates();
            },
            loadScheduler: function () {
                var self = this;
                api.admin.scheduler().then(function (res) {
                    if (res.ok) self.scheduler = res.data || null;
                });
            },
            saveScheduler: function () {
                var self = this;
                this.schedulerBusy = true;
                api.admin.saveScheduler({
                    auto_checkin: !!(this.scheduler && this.scheduler.auto_checkin),
                    auto_refresh: !!(this.scheduler && this.scheduler.auto_refresh),
                    travel_enabled: !!(this.scheduler && this.scheduler.travel_enabled),
                    travel_location: (this.scheduler && this.scheduler.travel_location) || '古镇客栈',
                    activity_enabled: !!(this.scheduler && this.scheduler.activity_enabled),
                    activity_rate_limit_ms: Number(this.scheduler && this.scheduler.activity_rate_limit_ms) || 1500,
                    tasks_enabled: !!(this.scheduler && this.scheduler.tasks_enabled),
                    tasks_interval_minutes: Number(this.scheduler && this.scheduler.tasks_interval_minutes) || 60,
                    tasks_chat_seeds: Number(this.scheduler && this.scheduler.tasks_chat_seeds) || 0
                }).then(function (res) {
                    self.schedulerBusy = false;
                    if (S.report(res, '定时任务设置已保存')) self.loadScheduler();
                });
            },
            runScheduler: function () {
                var self = this;
                this.schedulerBusy = true;
                api.admin.runScheduler().then(function (res) {
                    self.schedulerBusy = false;
                    if (res.ok) {
                        var msg = (res.data && res.data.message) || '已执行';
                        S.toast(msg, 'success');
                        self.loadScheduler();
                    } else {
                        S.report(res);
                    }
                });
            },
            loadTemplates: function () {
                var self = this;
                var req = this.isAdmin ? api.admin.promptTemplates() : api.user.promptTemplates();
                req.then(function (res) {
                    if (!res.ok) return;
                    var d = res.data || {};
                    self.templates = d.items || [];
                    self.templateModes = d.modes || [];
                });
            },
            resetTemplateForm: function () {
                this.templateForm = {
                    name: '', description: '', content: '',
                    mode: 'prepend', enabled: true, is_default: false, sort_order: 0
                };
            },
            openCreateTemplate: function () {
                this.editingTemplate = null;
                this.resetTemplateForm();
                this.showTemplate = true;
            },
            openEditTemplate: function (t) {
                this.editingTemplate = t;
                this.templateForm = {
                    name: t.name || '',
                    description: t.description || '',
                    content: t.content || '',
                    mode: t.mode || 'prepend',
                    enabled: t.enabled !== undefined ? !!t.enabled : true,
                    is_default: !!t.is_default,
                    sort_order: Number(t.sort_order) || 0
                };
                this.showTemplate = true;
            },
            submitTemplate: function () {
                var self = this;
                if (!this.templateForm.name || !this.templateForm.name.trim()) {
                    S.toast('请填写模板名称', 'warning');
                    return;
                }
                this.templatesBusy = true;
                var payload = {
                    name: this.templateForm.name.trim(),
                    description: this.templateForm.description,
                    content: this.templateForm.content,
                    mode: this.templateForm.mode,
                    enabled: !!this.templateForm.enabled,
                    is_default: !!this.templateForm.is_default,
                    sort_order: Number(this.templateForm.sort_order) || 0
                };
                var req = this.editingTemplate
                    ? (this.isAdmin
                        ? api.admin.updatePromptTemplate(this.editingTemplate.id, payload)
                        : api.user.updatePromptTemplate(this.editingTemplate.id, payload))
                    : (this.isAdmin
                        ? api.admin.createPromptTemplate(payload)
                        : api.user.createPromptTemplate(payload));
                req.then(function (res) {
                    self.templatesBusy = false;
                    if (S.report(res, self.editingTemplate ? '模板已保存' : '模板已创建')) {
                        self.showTemplate = false;
                        self.editingTemplate = null;
                        self.loadTemplates();
                    }
                });
            },
            removeTemplate: function (t) {
                var self = this;
                global.AigwConfirm('删除模板「' + (t.name || '') + '」后不可恢复，确定继续？', {
                    title: '删除提示词模板', danger: true, confirmText: '确认删除'
                }).then(function (yes) {
                    if (!yes) return;
                    self.templatesBusy = true;
                    var req = self.isAdmin
                        ? api.admin.deletePromptTemplate(t.id)
                        : api.user.deletePromptTemplate(t.id);
                    req.then(function (res) {
                        self.templatesBusy = false;
                        if (S.report(res, '模板已删除')) self.loadTemplates();
                    });
                });
            },
            templateModeLabel: function (mode) {
                var hit = null;
                (this.templateModeOptions || []).forEach(function (o) {
                    if (o.value === mode) hit = o.label;
                });
                return hit || mode;
            },
            save: function (bump) {
                if (!this.isAdmin) { S.toast('普通用户无修改权限', 'warning'); return; }
                var self = this;
                // 模型别名 / fallback 链：textarea 是 JSON 文本，拼错就别发请求（后端解析失败会静默清空，必须前置拦截）
                var aliasRaw = String(this.form.model_aliases || '').trim();
                if (aliasRaw) {
                    try {
                        var aliasVal = JSON.parse(aliasRaw);
                        if (!aliasVal || typeof aliasVal !== 'object' || Array.isArray(aliasVal)) {
                            S.toast('模型别名必须是 JSON 对象，如 {"claude-sonnet-4": "agent2api_autoclaw/glm-5.3"}', 'warning');
                            return;
                        }
                    } catch (e) { S.toast('模型别名不是合法 JSON：' + e.message, 'warning'); return; }
                }
                var chainRaw = String(this.form.fallback_chain || '').trim();
                if (chainRaw) {
                    try {
                        var chainVal = JSON.parse(chainRaw);
                        if (!Array.isArray(chainVal)) {
                            S.toast('fallback 链必须是 JSON 数组，如 ["agent2api_zcode_intl/glm-4.6"]', 'warning');
                            return;
                        }
                    } catch (e) { S.toast('fallback 链不是合法 JSON：' + e.message, 'warning'); return; }
                }
                this.saving = true;
                var payload = {
                    // 站点品牌（全局可改）
                    app_name: (this.form.app_name || '').trim(),
                    app_subtitle: (this.form.app_subtitle || '').trim(),
                    app_desc: (this.form.app_desc || '').trim(),
                    // 邮件（SMTP）—— 端口 1-65535 全部放行
                    mail_enabled: !!this.form.mail_enabled,
                    mail_host: (this.form.mail_host || '').trim(),
                    mail_port: Math.min(65535, Math.max(1, Number(this.form.mail_port) || 465)),
                    mail_username: (this.form.mail_username || '').trim(),
                    // ⚠️ 口令必须进 payload。
                    //    漏掉这一行 = 「填了口令 → 点保存 → 静默丢弃」：
                    //    后端 `array_key_exists('mail_password', $payload)` 恒为 false，
                    //    库里的口令永远是空串，而前端界面看不出任何异常。
                    //    症状：测试发信时 AUTH PLAIN 回 `530 ... is NULL`，
                    //    兜底 AUTH LOGIN 再回 `535 Authentication failure`，
                    //    于是被读成「密码错了」，管理员改多少次口令都没用。
                    //    后端语义：空串 = 保持原值，非空 = AES-256-GCM 加密覆盖。
                    //    这里**故意不 trim**：口令理论上可含空格，原样提交避免误伤。
                    mail_password: this.form.mail_password || '',
                    mail_encryption: this.form.mail_encryption || 'auto',
                    mail_from_email: (this.form.mail_from_email || '').trim(),
                    mail_from_name: (this.form.mail_from_name || '').trim(),
                    mail_connect_timeout: Math.min(120, Math.max(1, Number(this.form.mail_connect_timeout) || 10)),
                    mail_read_timeout: Math.min(300, Math.max(1, Number(this.form.mail_read_timeout) || 20)),
                    mail_allow_insecure: !!this.form.mail_allow_insecure,
                    mail_verify_required: !!this.form.mail_verify_required,
                    mail_code_captcha: !!this.form.mail_code_captcha,
                    mail_code_ttl: Math.min(3600, Math.max(60, Number(this.form.mail_code_ttl) || 300)),
                    mail_code_cooldown: Math.min(600, Math.max(0, Number(this.form.mail_code_cooldown) || 0)),
                    mail_code_daily_email: Math.min(100, Math.max(0, Number(this.form.mail_code_daily_email) || 0)),
                    mail_code_daily_ip: Math.min(1000, Math.max(0, Number(this.form.mail_code_daily_ip) || 0)),
                    // 密码安全：两个开关都要求 SMTP 就绪才真正生效（后端联动判定）
                    password_change_verify: !!this.form.password_change_verify,
                    password_reset_enabled: !!this.form.password_reset_enabled,
                    // 注册邮箱域名白名单：原文提交，后端统一归一化（空串 = 不限制）
                    register_email_domains: (this.form.register_email_domains || '').trim(),
                    allow_no_key: this.form.allow_no_key,
                    default_provider: this.form.default_provider,
                    default_model: this.form.default_model,
                    only_usable_models: this.form.only_usable_models,
                    schedule_mode: this.form.schedule_mode,
                    pinned_account_uid: this.form.schedule_mode === 'pinned' ? this.form.pinned_account_uid : '',
                    max_error_count: Number(this.form.max_error_count) || 5,
                    quota_cooldown_ms: Number(this.form.quota_cooldown_ms) || 300000,
                    soft_cooldown_ms: Number(this.form.soft_cooldown_ms) || 60000,
                    // 免排队供应商白名单：原文提交，后端会归一化并只保留真实存在的 provider_key
                    lease_bypass_providers: (this.form.lease_bypass_providers || '').trim(),
                    expose_lan: !!this.form.expose_lan,
                    stream_heartbeat: !!this.form.stream_heartbeat,
                    log_keep_days: Math.max(1, Number(this.form.log_keep_days) || 30),
                    allow_register: !!this.form.allow_register,
                    captcha_enabled: !!this.form.captcha_enabled,
                    login_max_attempts: Math.min(50, Math.max(1, Number(this.form.login_max_attempts) || 5)),
                    login_lock_seconds: Math.min(86400, Math.max(1, Number(this.form.login_lock_seconds) || 900)),
                    // 功能开关：网关能力
                    gateway_enabled: !!this.form.gateway_enabled,
                    chat_enabled: !!this.form.chat_enabled,
                    models_enabled: !!this.form.models_enabled,
                    stream_enabled: !!this.form.stream_enabled,
                    tools_enabled: !!this.form.tools_enabled,
                    models_expose_providers: !!this.form.models_expose_providers,
                    models_qualified_id: !!this.form.models_qualified_id,
                    // 功能开关：调用日志
                    log_enabled: !!this.form.log_enabled,
                    log_request_body: !!this.form.log_request_body,
                    log_content_max_chars: Math.min(200000, Math.max(0, Number(this.form.log_content_max_chars) || 0)),
                    log_max_rows: Math.max(0, Number(this.form.log_max_rows) || 0),
                    log_body_max_rows: Math.max(0, Number(this.form.log_body_max_rows) || 0),
                    // 功能开关：限流
                    rate_limit_enabled: !!this.form.rate_limit_enabled,
                    rate_limit_rpm: Math.min(100000, Math.max(1, Number(this.form.rate_limit_rpm) || 60)),
                    rate_limit_burst: Math.min(10000, Math.max(0, Number(this.form.rate_limit_burst) || 0)),
                    rate_limit_scope: this.form.rate_limit_scope === 'ip' ? 'ip' : 'key',
                    // 功能开关：思维（人格）治理
                    system_persona_enabled: !!this.form.system_persona_enabled,
                    user_persona_enabled: !!this.form.user_persona_enabled,
                    // 功能开关：内容规避
                    normalize_jailbreak_shape: !!this.form.normalize_jailbreak_shape,
                    // 主导权回收
                    persona_override_client: !!this.form.persona_override_client,
                    // 弹窗公告（注册页 / 总览面板）：后端在标题/内容/类型/位置
                    // 任一变化时自动把 notice_version +1（客户端据此重新弹一次）。
                    notice_enabled: !!this.form.notice_enabled,
                    notice_title: (this.form.notice_title || '').trim(),
                    notice_content: (this.form.notice_content || '').trim(),
                    notice_target: this.form.notice_target || 'all',
                    notice_type: this.form.notice_type || 'info',
                    // 加群按钮：群号只传数字（后端也会再清洗一次），链接可空
                    notice_group_enabled: !!this.form.notice_group_enabled,
                    notice_group_qq: String(this.form.notice_group_qq || '').replace(/\D+/g, ''),
                    notice_group_url: String(this.form.notice_group_url || '').trim(),
                    // 失败告警 webhook
                    alert_webhook_enabled: !!this.form.alert_webhook_enabled,
                    alert_webhook_url: String(this.form.alert_webhook_url || '').trim(),
                    alert_cooldown_seconds: Math.min(3600, Math.max(30, Number(this.form.alert_cooldown_seconds) || 300)),
                    // 模型别名 / 跨供应商 fallback / 公开状态页
                    status_page_enabled: !!this.form.status_page_enabled,
                    model_aliases: String(this.form.model_aliases || '').trim(),
                    fallback_chain: String(this.form.fallback_chain || '').trim(),
                    // 「重新推送」：内容一字未改也要让所有人再看一遍时传 true
                    notice_bump: !!bump
                };
                api.admin.saveSettings(payload).then(function (res) {
                    self.saving = false;
                    if (S.report(res, bump ? '公告已重新推送给所有用户' : '设置已保存')) {
                        // 保存后立刻清空口令输入框：明文不留在表单/内存里，
                        // 也避免下次保存重复提交。是否已设置由服务端回传的
                        // mail_password_set 驱动 placeholder（load() 会刷新）。
                        self.form.mail_password = '';
                        self.load();
                        // 站点品牌改动即时生效（顶栏/侧栏/浏览器标题跟随）
                        if (S.reloadBrand) S.reloadBrand();
                    }
                });
            },
            regenerate: function () {
                var self = this;
                global.AigwConfirm('重新生成后，旧 API Key 立即失效，所有已接入的客户端都需要更换。', {
                    title: '重新生成 API Key', danger: true, confirmText: '确认生成'
                }).then(function (yes) {
                    if (!yes) return;
                    self.saving = true;
                    var req = self.isAdmin ? api.admin.regenerateKey() : api.user.regenerateApiKey();
                    req.then(function (res) {
                        self.saving = false;
                        if (!res.ok) { S.report(res); return; }
                        var key = res.data && (res.data.api_key || res.data.key);
                        if (key) {
                            self.newKey = key;
                            if (!self.isAdmin) self.form.api_key_masked = key;
                            S.toast('新的 API Key 已生成，请立即保存', 'success');
                        } else {
                            S.toast('已重新生成，请刷新页面查看', 'success');
                        }
                        if (self.isAdmin) self.load();
                    });
                });
            },
            copy: function (text) {
                if (!text) return;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        S.toast('已复制到剪贴板', 'success');
                    }, function () { S.toast('复制失败', 'error'); });
                } else {
                    S.toast('当前浏览器不支持自动复制', 'warning');
                }
            },
            /* ── 邮件（SMTP）诊断 ─────────────────────────── */
            mailEncryptionHint: function (mode) {
                var m = mode || this.form.mail_encryption;
                if (m === 'none') return '明文直连：不做任何加密。80 / 2525 等端口的自建邮局常用';
                if (m === 'ssl') return '隐式 TLS：连接即加密，通常用于 465 端口';
                if (m === 'tls') return 'STARTTLS：先明文连接再升级加密，通常用于 587 / 25 端口';
                return '自动：465 按隐式 TLS，其余端口按 STARTTLS（服务端不支持时自动退化为明文）';
            },
            sendMailTest: function () {
                var self = this;
                if (this.mailTesting) return;
                var to = (this.mailTestTo || '').trim() || (this.form.mail_from_email || '').trim();
                if (!to) { S.toast('请填写收件邮箱', 'warning'); return; }
                this.mailTesting = true;
                this.mailTestResult = null;
                api.admin.mailTest(to).then(function (res) {
                    self.mailTesting = false;
                    var data = (res && res.data) || {};
                    self.mailTestResult = {
                        ok: !!(res && res.ok),
                        message: (res && res.ok)
                            ? ('已成功发送到 ' + (data.to || to))
                            : ((res && res.error && res.error.message) || (res && res.message) || '发送失败'),
                        server: data.server || '',
                        transcript: data.transcript || []
                    };
                    S.toast(self.mailTestResult.message, res && res.ok ? 'success' : 'error');
                });
            },
            testMailConnect: function () {
                var self = this;
                if (this.mailConnTesting) return;
                this.mailConnTesting = true;
                this.mailTestResult = null;
                api.admin.mailTestConnect().then(function (res) {
                    self.mailConnTesting = false;
                    var data = (res && res.data) || {};
                    var ok = !!(res && res.ok);
                    self.mailTestResult = {
                        ok: ok,
                        message: ok
                            ? ('连接与认证通过（' + (data.server || '') + '，实际使用 ' + (data.resolved || '') + '）')
                            : ((res && res.error && res.error.message) || '连接失败'),
                        server: data.server || '',
                        transcript: data.transcript || []
                    };
                    S.toast(ok ? 'SMTP 连接正常' : 'SMTP 连接失败', ok ? 'success' : 'error');
                });
            },
            ms2min: function (ms) {
                return Math.round((Number(ms) || 0) / 60000 * 10) / 10;
            },
            min2ms: function (min) {
                return Math.round((Number(min) || 0) * 60000);
            },
            /** 公告样式类型 → 提示条 class（与前台 AigwNotice 的映射保持一致） */
            noticeToneClass: function (type) {
                if (type === 'success') return 'note--success';
                if (type === 'warning') return 'note--warn';
                if (type === 'danger') return 'note--danger';
                return 'note--info';
            }
        },
        template:
            '<div>' +
              '<div class="page-head">' +
                '<div class="page-head__main">' +
                  '<div class="topbar__title">网关设置</div>' +
                  '<div class="topbar__desc">{{ isAdmin ? "控制对外出口的鉴权、默认路由与调度策略" : "查看网关接入地址与你的专属 API Key" }}</div>' +
                '</div>' +
                '<div class="page-head__actions">' +
                  '<button type="button" class="btn btn--sm" @click="load">重新载入</button>' +
                  '<button v-if="isAdmin" type="button" class="btn btn--sm btn--primary" :disabled="saving" @click="save()">' +
                    '{{ saving ? "保存中…" : "保存设置" }}' +
                  '</button>' +
                '</div>' +
              '</div>' +

              '<div v-if="loading" class="mt-16"><aigw-loading /></div>' +
              '<div v-else-if="error" class="card mt-16">' +
                '<aigw-empty icon="warning" title="加载失败" :desc="error" />' +
              '</div>' +

              '<div v-else>' +
                '<div class="note note--info mt-16">' +
                  '接入地址：<b class="mono">{{ baseUrl }}/v1</b> ｜ 兼容 OpenAI 的 <span class="mono">/v1/chat/completions</span>、' +
                  '<span class="mono">/v1/models</span> 与 <span class="mono">/v1/health</span>。' +
                '</div>' +

                /* ── 客户端一键接入 ── */
                '<aigw-card title="客户端接入" sub="选择常用客户端，一键复制接入配置" class="mt-16">' +
                  '<template #actions>' +
                    '<button type="button" class="btn btn--sm btn--primary" @click="copy(clientSnippet)">复制配置</button>' +
                  '</template>' +
                  '<div class="pickbar">' +
                    '<aigw-segbar v-model="client" :options="clientOptions" />' +
                    '<span class="pickbar__url mono truncate">Base URL：{{ baseUrl }}/v1</span>' +
                  '</div>' +
                  '<div class="codebox mt-16">' +
                    '<div class="codebox__bar">' +
                      '<span class="codebox__label">配置预览</span>' +
                      '<span class="codebox__meta">{{ clientKeyMeta }}</span>' +
                    '</div>' +
                    '<pre class="codeblock">{{ clientSnippet }}</pre>' +
                  '</div>' +
                  '<div class="field__hint mt-8">{{ clientKeyHint }}</div>' +
                '</aigw-card>' +

                /* ── 普通用户：只读信息（专属 Key + 路由状态） ── */
                '<div v-if="!isAdmin" class="grid grid--2 mt-16">' +
                  '<aigw-card title="我的 API Key" sub="调用网关时使用，仅能访问你自己添加的账号">' +
                    '<div class="field">' +
                      '<label class="field__label">API Key</label>' +
                      '<div class="field__row">' +
                        '<input class="input mono" :value="form.api_key_masked || \'（未生成）\'" readonly>' +
                        '<button type="button" class="btn btn--sm" @click="copy(form.api_key_masked)">复制</button>' +
                        '<button type="button" class="btn btn--sm btn--amber" :disabled="saving" @click="regenerate">重新生成</button>' +
                      '</div>' +
                      '<div class="field__hint">重新生成后旧 Key 立即失效，请及时更新已接入的客户端</div>' +
                    '</div>' +
                    '<div v-if="newKey" class="note mt-16">' +
                      '新的 API Key（请立即复制保存）：' +
                      '<div class="field__row mt-8">' +
                        '<input class="input mono" :value="newKey" readonly>' +
                        '<button type="button" class="btn btn--sm btn--primary" @click="copy(newKey)">复制</button>' +
                      '</div>' +
                    '</div>' +
                  '</aigw-card>' +

                  '<aigw-card title="路由状态" sub="网关当前的路由策略（只读）">' +
                    '<div class="field">' +
                      '<label class="field__label">默认模型</label>' +
                      '<input class="input mono" :value="form.default_model || \'未设置（由请求指定）\'" readonly>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">调度模式</label>' +
                      '<input class="input mono" :value="scheduleModeLabel" readonly>' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">模型可见性</label>' +
                      '<input class="input mono" :value="form.only_usable_models ? \'仅暴露当前可用模型\' : \'全部已登记模型\'" readonly>' +
                    '</div>' +
                  '</aigw-card>' +
                '</div>' +

                '<div v-if="isAdmin" class="grid grid--2 mt-16">' +
                  /* ── 鉴权 ── */
                  '<aigw-card title="鉴权" sub="对外 API Key 与访问控制">' +
                    '<div class="field">' +
                      '<label class="field__label">当前 API Key</label>' +
                      '<div class="field__row">' +
                        '<input class="input mono" :value="form.api_key_masked || \'（未设置）\'" readonly>' +
                        '<button type="button" class="btn btn--sm" @click="copy(form.api_key_masked)">复制</button>' +
                        '<button type="button" class="btn btn--sm btn--amber" :disabled="saving"' +
                                ' @click="regenerate">重新生成</button>' +
                      '</div>' +
                      '<div class="field__hint">出于安全考虑，明文 Key 只在生成时显示一次</div>' +
                    '</div>' +

                    '<div v-if="newKey" class="note mt-16">' +
                      '新的 API Key（请立即复制保存）：' +
                      '<div class="field__row mt-8">' +
                        '<input class="input mono" :value="newKey" readonly>' +
                        '<button type="button" class="btn btn--sm btn--primary" @click="copy(newKey)">复制</button>' +
                      '</div>' +
                    '</div>' +

                    '<div class="field mt-16 mb-0">' +
                      '<aigw-switch v-model="form.allow_no_key" label="允许无 Key 调用（不推荐）" />' +
                      '<div class="field__hint">开启后任何人都能直接调用网关，仅建议内网调试时使用</div>' +
                    '</div>' +
                  '</aigw-card>' +

                  /* ── 默认路由 ── */
                  '<aigw-card title="默认路由" sub="请求未显式指定时的兜底">' +
                    '<div class="field">' +
                      '<label class="field__label">默认供应商</label>' +
                      '<select class="select" v-model="form.default_provider">' +
                        '<option v-for="o in defaultProviderOptions" :key="o.value" :value="o.value">' +
                          '{{ o.label }}' +
                        '</option>' +
                      '</select>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">默认模型</label>' +
                      '<select class="select" v-model="form.default_model">' +
                        '<option v-for="o in defaultModelOptions" :key="o.value" :value="o.value">' +
                          '{{ o.label }}' +
                        '</option>' +
                      '</select>' +
                      '<div class="field__hint">当客户端未传 model 时使用这里的值</div>' +
                      '<div class="field__hint">客户端也可直接用 <span class="mono">供应商key/模型ID</span>（如 <span class="mono">trae/glm-5.2</span>）锁定某个平台的模型，' +
                        '此时不受此处默认值影响、也无需更换 API Key</div>' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<aigw-switch v-model="form.only_usable_models" label="仅对外暴露当前可用的模型" />' +
                      '<div class="field__hint">关闭后 /v1/models 会返回全部已登记模型</div>' +
                    '</div>' +
                  '</aigw-card>' +
                '</div>' +

                /* ── 功能开关中心 ── */
                '<aigw-card v-if="isAdmin" title="功能开关中心" sub="网关对外能力、日志与限流的实时开关，保存后立即生效" class="mt-16">' +
                  '<div class="field__hint"><b>网关能力</b>（关闭后对应接口直接拒绝）</div>' +
                  '<div class="grid grid--cards mt-8">' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.gateway_enabled" label="网关总开关" />' +
                      '<div class="field__hint">关闭后所有 /v1 接口返回 503，适用于临时停机维护</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.chat_enabled" label="对话补全 chat" />' +
                      '<div class="field__hint">关闭后 /v1/chat/completions 不可用，其余接口不受影响</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.models_enabled" label="模型列表 models" />' +
                      '<div class="field__hint">关闭后 /v1/models 不可用</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.stream_enabled" label="流式输出 stream" />' +
                      '<div class="field__hint">关闭后仅允许非流式请求，客户端需关闭 stream</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.tools_enabled" label="工具调用 tools" />' +
                      '<div class="field__hint">关闭后携带 tools 的请求会被拒绝</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.models_qualified_id" label="模型名带供应商前缀" />' +
                      '<div class="field__hint">开启后 /v1/models 的 id 下发为 <span class="mono">供应商key/模型ID</span>（如 <span class="mono">trae/Doubao-Seed-Evolving</span>），' +
                        '多平台同名模型不再混淆，列表里的 id 可直接填进请求的 model 字段；关闭则只下发裸模型名</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.models_expose_providers" label="模型列表暴露供应商" />' +
                      '<div class="field__hint">开启后 owned_by 返回真实供应商 key，关闭则统一显示 aigw</div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="field__hint mt-16"><b>调用日志</b></div>' +
                  '<div class="grid grid--cards mt-8">' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.log_enabled" label="调用日志" />' +
                      '<div class="field__hint">关闭后不再写入任何调用日志（统计与日志页将无数据）</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.log_request_body" label="记录请求体（内容留痕）" />' +
                      '<div class="field__hint">开启后日志额外保留请求与回复内容，可在「调用日志」详情中查看；占用空间较大</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">内容保存上限（字符）</label>' +
                      '<input class="input" type="number" min="0" max="200000" v-model.number="form.log_content_max_chars">' +
                      '<div class="field__hint">单条请求/回复最多保留的字符数，0 表示不记录内容；上限 200000（长会话建议 50000 以上）</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">日志行数上限</label>' +
                      '<input class="input" type="number" min="0" v-model.number="form.log_max_rows">' +
                      '<div class="field__hint">超过上限时按时间清理最旧记录（0 表示不限制）</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">正文保留行数</label>' +
                      '<input class="input" type="number" min="0" v-model.number="form.log_body_max_rows">' +
                      '<div class="field__hint">只有最近 N 条日志保留请求/回复正文，更早的自动清空正文但保留时间、耗时、Token、状态等统计列（大屏指标不受影响）。正文超 512 字节会自动 gzip 压缩存储，0 表示不限</div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="field__hint mt-16"><b>限流</b>（令牌桶，跨进程持久化）</div>' +
                  '<div class="grid grid--cards mt-8">' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.rate_limit_enabled" label="限流开关" />' +
                      '<div class="field__hint">开启后按下方策略对调用方限速，超限返回 429</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">限流维度</label>' +
                      '<aigw-segbar v-model="form.rate_limit_scope" :options="rateLimitScopeOptions" />' +
                      '<div class="field__hint">按 API Key 或按来源 IP 计数</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">每分钟配额（RPM）</label>' +
                      '<input class="input" type="number" min="1" max="100000" v-model.number="form.rate_limit_rpm">' +
                      '<div class="field__hint">令牌补充速率上限，1 ~ 100000</div>' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">突发额度（Burst）</label>' +
                      '<input class="input" type="number" min="0" max="10000" v-model.number="form.rate_limit_burst">' +
                      '<div class="field__hint">允许瞬时突发的额外请求数，0 ~ 10000</div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="field__hint mt-16"><b>思维治理</b>（控制「思维注入」功能的开启范围，保存后立即生效）</div>' +
                  '<div class="grid grid--cards mt-8">' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.system_persona_enabled" label="系统内置思维" />' +
                      '<div class="field__hint">关闭＝系统（全局）思维一律不注入（<b>开箱默认关闭</b>）。开启后可在「思维注入」页对每份思维按「供应商靶点 + 模型白名单」精准生效</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.user_persona_enabled" label="普通用户自定义思维" />' +
                      '<div class="field__hint">关闭后普通用户<b>无法新建/编辑/删除</b>自建思维（接口返回 403），且其自建思维不参与注入，只能使用系统自带思维</div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="field__hint mt-16"><b>内容规避</b>（让强对齐模型不再整单拒收，保存后立即生效）</div>' +
                  '<div class="grid grid--cards mt-8">' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.normalize_jailbreak_shape" label="入口归一化（越狱模板形态 → 中性 Brief）" />' +
                      '<div class="field__hint">请求发出<b>前</b>识别 user 提示词里的「编号清单 + 硬性要求标题 + 器官/参数名词密集罗列 + 不要任何免责声明」这类<b>越狱模板形态</b>，把它的<b>表述形态</b>重建为自然 Brief —— <b>要求一条不减</b>，只换怎么问。<br>实测 codebuddy/hy3 拦的不是题材而是这个形态：首轮整单拒收、重采样 4 轮同样被拒，最后只能报 CONTENT_BLOCKED。开启后同一提示词可正常交付。对普通提问零副作用（判据只认形态）</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.persona_override_client" label="主导权回收（废止客户端模板的安全条款）" />' +
                      '<div class="field__hint">客户端（Codex / WorkBuddy 等）常在 system 里自带安全引导：「遵循平台安全准则」「不适当就礼貌拒绝」。实测 codebuddy/hy3 会<b>优先服从这段客户端模板</b>，把注入的思维当外来指令 —— 表现为回复恒以「我是 Codex…我无法」开头、正文仅一两百字。<br>开启后在 system <b>最末尾</b>追加一段声明，指明该Client段落已被操作者本人显式废止、冲突时以工作模式为准，并封掉拒绝/致歉/替代方案三条退路。<br>实测：不加尾注 0/2 通过；加上后 <b>2/2 通过</b>（1776~2190 字）。仅在使用自带安全 system 的客户端上需要</div>' +
                    '</div>' +
                  '</div>' +
                '</aigw-card>' +

                /* ── 账号调度 ── */
                '<aigw-card v-if="isAdmin" title="账号调度" sub="请求在账号池中的分配方式" class="mt-16">' +
                  '<div class="field">' +
                    '<label class="field__label">调度模式</label>' +
                    '<aigw-segbar v-model="form.schedule_mode" :options="scheduleModeOptions" />' +
                    '<div class="field__hint">{{ scheduleHint }}</div>' +
                  '</div>' +
                  '<div class="field mb-0" v-if="form.schedule_mode === \'pinned\'">' +
                    '<label class="field__label">固定账号</label>' +
                    '<select class="select" v-model="form.pinned_account_uid">' +
                      '<option v-for="o in pinnedAccountOptions" :key="o.value" :value="o.value">' +
                        '{{ o.label }}' +
                      '</option>' +
                    '</select>' +
                    '<div class="field__hint">仅列出当前已登录/已添加的账号；该账号被禁用或冷却时自动回退</div>' +
                  '</div>' +
                '</aigw-card>' +

                /* ── 调度策略 ── */
                '<aigw-card v-if="isAdmin" title="调度与容错" sub="账号池的降级与冷却规则" class="mt-16">' +
                  '<div class="grid grid--2">' +
                    '<div class="field">' +
                      '<label class="field__label">最大连续错误次数</label>' +
                      '<input class="input" type="number" min="1" max="50" v-model.number="form.max_error_count">' +
                      '<div class="field__hint">超过后该账号会被标记为异常并暂停使用</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">额度耗尽冷却（分钟）</label>' +
                      '<input class="input" type="number" min="1" step="1"' +
                             ' :value="ms2min(form.quota_cooldown_ms)"' +
                             ' @input="form.quota_cooldown_ms = min2ms($event.target.value)">' +
                      '<div class="field__hint">命中额度上限后，该账号暂停多久再尝试</div>' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">软限流冷却（分钟）</label>' +
                      '<input class="input" type="number" min="0.5" step="0.5"' +
                             ' :value="ms2min(form.soft_cooldown_ms)"' +
                             ' @input="form.soft_cooldown_ms = min2ms($event.target.value)">' +
                      '<div class="field__hint">遇到 429 等临时限流时的退避时间</div>' +
                    '</div>' +
                  '</div>' +

                  '<div class="field mt-16 mb-0">' +
                    '<label class="field__label">免排队供应商（并发不互斥）</label>' +
                    '<input class="input mono" v-model.trim="form.lease_bypass_providers"' +
                           ' placeholder="留空 = 全部排队；例：trae,codebuddy,custom*">' +
                    '<div class="field__hint">' +
                      '填这里的供应商<b>不再对同一账号做租约串行</b>：两个客户端用同一账号并发调用会' +
                      '<b>同时发出</b>而不是排队（最多等 120s）。' +
                      '仅在已确认该上游能承受同账号并发时填写，多个用逗号分隔；' +
                      '支持前缀通配 <b>custom*</b>（覆盖自定义供应商的全部实例，含以后新增的 custom_xxx）；' +
                      '填错/不存在的 key 保存时会被自动丢弃。' +
                    '</div>' +
                  '</div>' +
                '</aigw-card>' +

                /* ── 邮件发信（SMTP）── */
                '<aigw-card v-if="isAdmin" title="邮件发信（SMTP）" sub="注册邮箱验证码与系统通知；端口不限，80 端口亦可发信" class="mt-16">' +
                  '<div class="grid grid--2">' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.mail_enabled" label="开启邮件服务" />' +
                      '<div class="field__hint">关闭后不发送任何邮件，注册也不要求邮箱验证</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.mail_verify_required" label="注册需邮箱验证" />' +
                      '<div class="field__hint">开启后注册必须填写邮箱验证码（邮件服务未配置完整时自动放行，避免锁死注册）</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.mail_code_captcha" label="发码前需图形验证码" />' +
                      '<div class="field__hint">默认开启：请求「获取邮箱验证码」前必须先输入图形验证码，防止被脚本刷注册与刷邮件。仅在验证码服务故障时才临时关闭</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">注册邮箱域名白名单</label>' +
                      '<input class="input" v-model="form.register_email_domains" placeholder="qq.com,foxmail.com">' +
                      '<div class="field__hint">多个域名用逗号分隔，只允许这些域（含子域）注册与收注册验证码。<b>留空 = 不限制</b>；改绑邮箱同样受限（存量非白名单用户仍可登录与找回密码）</div>' +
                    '</div>' +
                    // ── 密码安全 ─────────────────────────────────────
                    // 这两个开关只约束「是否要求邮箱验证」，实际生效还要求
                    // 上面的邮件服务配置完整 —— 没配 SMTP 时后端自动放行，
                    // 否则用户会卡在「要验证码 → 发不出信 → 永远改不了密码」。
                    // ★ 正因为会「自动放行」，必须在这里把退化状态显式告警，
                    //   否则开关显示「开」、用户端却不要求验证码 = 静默漂移。
                    '<div v-if="pwEffectiveWarning" class="note note--warn mt-8" style="margin-bottom:12px">' +
                      '<b>⚠ 这两个开关当前不会生效</b>' +
                      '<div class="mt-8">{{ pwEffectiveWarning }}</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.password_change_verify" label="修改密码需邮箱验证码" />' +
                      '<div class="field__hint">默认开启：已登录用户改密码时，除原密码外还须填写发往账号邮箱的验证码。作用是让「会话被窃」不足以直接改密接管账号；修改成功后该账号其他设备的登录状态会一并失效。邮件服务未配置完整时自动放行（仅校验原密码）</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.password_reset_enabled" label="开放找回密码" />' +
                      '<div class="field__hint">默认开启：登录页展示「忘记密码」入口，未登录用户可凭邮箱验证码重置密码。关闭后该入口隐藏、接口一并拒绝。邮件服务未配置完整时自动关闭</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">SMTP 服务器</label>' +
                      '<input class="input" v-model="form.mail_host" placeholder="smtp.example.com">' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">端口</label>' +
                      '<input class="input" type="number" min="1" max="65535" v-model.number="form.mail_port">' +
                      '<div class="field__hint">1 ~ 65535 全部支持：465（SSL）、587（STARTTLS）、25 / 80 / 2525（明文或 STARTTLS）</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">加密方式</label>' +
                      '<select class="select" v-model="form.mail_encryption">' +
                        '<option v-for="o in mailEncryptionOptions" :key="o.value" :value="o.value">{{ o.label }}</option>' +
                      '</select>' +
                      '<div class="field__hint">{{ mailEncryptionHint() }}</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">SMTP 账号</label>' +
                      '<input class="input" v-model="form.mail_username" autocomplete="off" placeholder="通常为完整邮箱地址">' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">SMTP 口令 / 授权码</label>' +
                      '<input class="input" type="password" v-model="form.mail_password" autocomplete="new-password"' +
                             ' :placeholder="form.mail_password_set ? \'已设置，留空表示不修改\' : \'请输入口令或授权码\'">' +
                      '<div class="field__hint">加密存储（AES-256-GCM），保存后不会再回显</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">发件邮箱</label>' +
                      '<input class="input" v-model="form.mail_from_email" placeholder="noreply@example.com">' +
                      '<div class="field__hint">留空则使用 SMTP 账号作为发件人</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">发件人名称</label>' +
                      '<input class="input" maxlength="64" v-model="form.mail_from_name" placeholder="站点名称">' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.mail_allow_insecure" label="允许明文投递" />' +
                      '<div class="field__hint">服务端不支持 STARTTLS 时退化为明文继续发送。80 端口场景建议保持开启</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">连接超时（秒）</label>' +
                      '<input class="input" type="number" min="1" max="120" v-model.number="form.mail_connect_timeout">' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">读取超时（秒）</label>' +
                      '<input class="input" type="number" min="1" max="300" v-model.number="form.mail_read_timeout">' +
                    '</div>' +
                  '</div>' +

                  '<div class="grid grid--2 mt-16">' +
                    '<div class="field">' +
                      '<label class="field__label">验证码有效期（秒）</label>' +
                      '<input class="input" type="number" min="60" max="3600" v-model.number="form.mail_code_ttl">' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">重发冷却（秒）</label>' +
                      '<input class="input" type="number" min="0" max="600" v-model.number="form.mail_code_cooldown">' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">单邮箱每日上限（次）</label>' +
                      '<input class="input" type="number" min="0" max="100" v-model.number="form.mail_code_daily_email">' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">单 IP 每日上限（次）</label>' +
                      '<input class="input" type="number" min="0" max="1000" v-model.number="form.mail_code_daily_ip">' +
                    '</div>' +
                  '</div>' +

                  '<div class="mt-16" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">' +
                    '<div class="field mb-0" style="flex:1;min-width:200px">' +
                      '<label class="field__label">测试收件邮箱</label>' +
                      '<input class="input" v-model="mailTestTo" :placeholder="form.mail_from_email || \'留空则发到发件邮箱\'">' +
                    '</div>' +
                    '<button type="button" class="btn" :disabled="mailConnTesting" @click="testMailConnect">' +
                      '{{ mailConnTesting ? "测试中…" : "测试连接" }}' +
                    '</button>' +
                    '<button type="button" class="btn btn--primary" :disabled="mailTesting" @click="sendMailTest">' +
                      '{{ mailTesting ? "发送中…" : "发送测试邮件" }}' +
                    '</button>' +
                  '</div>' +

                  '<div v-if="mailTestResult" class="note note--sm mt-8"' +
                       ' :class="mailTestResult.ok ? \'note--info\' : \'note--warn\'">' +
                    '<div>{{ mailTestResult.message }}</div>' +
                    '<div v-if="mailTestResult.transcript && mailTestResult.transcript.length" class="mt-8">' +
                      '<div class="text-faint" style="font-size:11.5px;margin-bottom:4px">SMTP 会话明细（凭据已隐藏）</div>' +
                      '<pre style="max-height:220px;overflow:auto;font-size:11.5px;line-height:1.5;' +
                           'white-space:pre-wrap;word-break:break-all;margin:0">{{ mailTestResult.transcript.join("\\n") }}</pre>' +
                    '</div>' +
                  '</div>' +
                '</aigw-card>' +

                /* ── 站点品牌 ── */
                '<aigw-card v-if="isAdmin" title="站点品牌" sub="全局站点名称与副标题，前台与后台同步生效" class="mt-16">' +
                  '<div class="grid grid--2">' +
                    '<div class="field">' +
                      '<label class="field__label">站点名称</label>' +
                      '<input class="input" maxlength="64" v-model="form.app_name" placeholder="AI 聚合网关">' +
                      '<div class="field__hint">显示在浏览器标题、侧栏、登录页与站点首页（最多 64 字）</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">副标题</label>' +
                      '<input class="input" maxlength="64" v-model="form.app_subtitle" placeholder="Aggregator">' +
                      '<div class="field__hint">显示在侧栏品牌名下方（最多 64 字）</div>' +
                    '</div>' +
                    '<div class="field mb-0" style="grid-column:1 / -1">' +
                      '<label class="field__label">站点描述</label>' +
                      '<input class="input" maxlength="128" v-model="form.app_desc" placeholder="多供应商账号池 · 统一 OpenAI 兼容出口">' +
                      '<div class="field__hint">显示在登录页标题下方，并作为首页描述（最多 128 字）</div>' +
                    '</div>' +
                  '</div>' +
                '</aigw-card>' +

                /* ── 弹窗公告（注册页 / 总览面板）── */
                '<aigw-card v-if="isAdmin" title="弹窗公告" sub="注册/登录页与总览面板的弹窗通知，后台可随时开关" class="mt-16">' +
                  '<div class="grid grid--2">' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.notice_enabled" label="启用弹窗公告" />' +
                      '<div class="field__hint">关闭后前台不再弹公告（已填文案保留，重新开启即可继续使用）</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">显示位置</label>' +
                      '<aigw-segbar v-model="form.notice_target" :options="noticeTargetOptions" />' +
                      '<div class="field__hint">公告出现的页面：注册/登录页、总览面板，或两者都显示</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">样式配色</label>' +
                      '<aigw-segbar v-model="form.notice_type" :options="noticeTypeOptions" />' +
                      '<div class="field__hint">仅影响弹窗配色：信息（蓝）/ 成功（绿）/ 注意（橙）/ 警告（红）</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">当前版本</label>' +
                      '<input class="input mono" :value="\'v\' + (form.notice_version || 0)" readonly>' +
                      '<div class="field__hint">标题 / 内容 / 样式 / 位置任一改动会自动 +1，客户端据此重新弹一次；版本未变则不会重复打扰用户</div>' +
                    '</div>' +
                    '<div class="field" style="grid-column:1 / -1">' +
                      '<label class="field__label">公告标题</label>' +
                      '<input class="input" maxlength="128" v-model="form.notice_title" placeholder="例如：服务维护通知">' +
                    '</div>' +
                    '<div class="field" style="grid-column:1 / -1">' +
                      '<label class="field__label">公告内容</label>' +
                      '<textarea class="textarea" rows="6" maxlength="5000" v-model="form.notice_content"' +
                                ' placeholder="支持换行排版，按纯文本原样显示（不解析 HTML）。例如：今晚 23:00 ~ 24:00 进行上游切换，期间可能出现短暂不可用。"></textarea>' +
                      '<div class="field__hint">纯文本，最多 5000 字。同一版本每位用户只会看到一次</div>' +
                    '</div>' +
                    /* ── 跳转加群按钮 ── */
                    '<div class="field" style="grid-column:1 / -1">' +
                      '<aigw-switch v-model="form.notice_group_enabled" label="显示「加入交流群」按钮" />' +
                      '<div class="field__hint">开启后，公告弹窗底部会多一个加群按钮（排在「我知道了」上方）</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">QQ 群号</label>' +
                      '<input class="input mono" maxlength="32" v-model="form.notice_group_qq" placeholder="例如：1121243020">' +
                      '<div class="field__hint">只保留数字。未填「加群链接」时，点按钮会「复制群号 + 唤起手机 QQ」</div>' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">加群链接（可选）</label>' +
                      '<input class="input mono" maxlength="255" v-model="form.notice_group_url" placeholder="https://qm.qq.com/q/xxxxxxxx">' +
                      '<div class="field__hint">填了优先用它跳转（QQ 群管理后台可生成），需以 http(s):// 开头</div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="field__hint mt-16"><b>效果预览</b>（与前台弹窗样式一致）</div>' +
                  '<div class="mt-8" style="max-width:460px">' +
                    '<div class="card" style="padding:0;overflow:hidden">' +
                      '<div class="card__head" style="margin:0">' +
                        '<div class="card__titles">' +
                          '<div class="card__title truncate">{{ (form.notice_title || "").trim() || "公告" }}</div>' +
                        '</div>' +
                      '</div>' +
                      '<div style="padding:14px">' +
                        '<div class="note note--sm" :class="noticeToneClass(form.notice_type)">' +
                          '<p v-for="(line, i) in noticePreviewLines" :key="i" class="notice__line">' +
                            '<template v-if="line">{{ line }}</template><br v-else>' +
                          '</p>' +
                          '<p v-if="!noticePreviewLines.length" class="notice__line text-faint">（公告内容为空）</p>' +
                        '</div>' +
                      '</div>' +
                      /* 底栏：加群按钮 + 我知道了（仅预览，不可点） */
                      '<div style="padding:0 14px 14px">' +
                        '<div style="display:flex;flex-direction:column;gap:8px">' +
                          '<div v-if="noticeGroupShown" class="btn w-full" style="pointer-events:none">' +
                            '<aigw-icon name="users" :size="16" /> {{ noticeGroupLabel }}' +
                          '</div>' +
                          '<div class="btn btn--primary w-full" style="pointer-events:none">我知道了</div>' +
                        '</div>' +
                      '</div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="wrap mt-16">' +
                    '<button type="button" class="btn btn--sm btn--primary" :disabled="saving" @click="save()">保存公告</button>' +
                    '<button type="button" class="btn btn--sm btn--amber" :disabled="saving" @click="save(true)">重新推送</button>' +
                    '<span class="text-faint" style="font-size:11.5px">' +
                      '当前：{{ form.notice_enabled ? "已开启" : "未开启" }} ｜ 位置：{{ noticeTargetLabel }} ｜ 样式：{{ noticeTypeLabel }}' +
                      ' ｜ 加群：{{ noticeGroupShown ? "已显示" : "不显示" }}' +
                    '</span>' +
                  '</div>' +
                  '<div v-if="form.notice_enabled && !noticeHasContent" class="note note--warn note--sm mt-8">' +
                    '公告已开启，但标题与内容都为空 —— 前台不会有任何弹窗。请至少填写标题或内容。' +
                  '</div>' +
                  '<div v-if="noticeGroupIncomplete" class="note note--warn note--sm mt-8">' +
                    '加群按钮已开启，但既没填群号也没填加群链接 —— 前台不会显示这个按钮。请至少填一项。' +
                  '</div>' +
                  '<div class="note note--sm mt-8">' +
                    '「重新推送」会把版本号 +1，让所有用户（含已读过的）下次进入页面时重新看到一次；' +
                    '普通保存只在公告内容或加群配置确有变化时才 +1，改其他设置不会打扰用户。' +
                  '</div>' +
                '</aigw-card>' +

                /* ── 失败告警（Webhook）── */
                '<aigw-card v-if="isAdmin" title="失败告警" sub="调用最终失败时推送 Webhook（兼容企业微信 / 钉钉自定义机器人）" class="mt-16">' +
                  '<div class="grid grid--2">' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.alert_webhook_enabled" label="启用失败告警" />' +
                      '<div class="field__hint">一次请求换遍账号仍失败时推送；同供应商同错误码在冷却窗口内只推一次，防告警风暴</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">冷却窗口（秒）</label>' +
                      '<input class="input" type="number" min="30" max="3600" v-model.number="form.alert_cooldown_seconds">' +
                      '<div class="field__hint">30 ~ 3600 秒，默认 300。窗口内重复的同类失败不再打扰</div>' +
                    '</div>' +
                    '<div class="field mb-0" style="grid-column:1 / -1">' +
                      '<label class="field__label">Webhook 地址</label>' +
                      '<input class="input mono" maxlength="512" v-model="form.alert_webhook_url" placeholder="https://oapi.dingtalk.com/robot/send?access_token=...">' +
                      '<div class="field__hint">企业微信群机器人或钉钉自定义机器人的 Webhook 地址，需以 http(s):// 开头；出站走 SSRF 防护（禁内网地址）</div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="wrap mt-16">' +
                    '<button type="button" class="btn btn--sm btn--primary" :disabled="saving" @click="save()">保存告警设置</button>' +
                    '<span class="text-faint" style="font-size:11.5px">当前：{{ form.alert_webhook_enabled ? "已开启" : "未开启" }}</span>' +
                  '</div>' +
                  '<div v-if="form.alert_webhook_enabled && !String(form.alert_webhook_url || \'\').trim()" class="note note--warn note--sm mt-8">' +
                    '告警已开启，但 Webhook 地址为空 —— 保存会被拒绝，请先填写地址。' +
                  '</div>' +
                '</aigw-card>' +

                /* ── 高级路由（模型别名 / 跨供应商 fallback / 公开状态页）── */
                '<aigw-card v-if="isAdmin" title="高级路由" sub="模型别名 · 跨供应商降级 · 公开状态页" class="mt-16">' +
                  '<div class="grid grid--2">' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.status_page_enabled" label="公开状态页" />' +
                      '<div class="field__hint">开启后任何人可访问 <span class="mono">/status</span> 查看供应商可用性概览（仅聚合数据，不含账号明细）；数据接口为 <span class="mono">/v1/status</span></div>' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">模型别名（JSON 对象）</label>' +
                      '<textarea class="input mono" rows="4" v-model="form.model_aliases" placeholder=\'{"claude-sonnet-4": "agent2api_autoclaw/glm-5.3"}\'></textarea>' +
                      '<div class="field__hint">客户端请求别名 → 实际转发到目标模型（支持「供应商/模型」或目录模型名）。留空 = 不启用。只展开一层，防止配置环</div>' +
                    '</div>' +
                    '<div class="field mb-0" style="grid-column:1 / -1">' +
                      '<label class="field__label">跨供应商 fallback 链（JSON 数组，按序降级）</label>' +
                      '<textarea class="input mono" rows="3" v-model="form.fallback_chain" placeholder=\'["agent2api_zcode_intl/glm-4.6", "agent2api_raccoon/sn-deepseek-v4-1-flash-1017"]\'></textarea>' +
                      '<div class="field__hint">主供应商所有账号仍失败时（上游故障/配额/限流/登录态失效等），按序改写目标重试；最多降 2 层。参数错误、内容拦截类失败不触发降级</div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="wrap mt-16">' +
                    '<button type="button" class="btn btn--sm btn--primary" :disabled="saving" @click="save()">保存高级路由</button>' +
                    '<span class="text-faint" style="font-size:11.5px">状态页当前：{{ form.status_page_enabled ? "已开启" : "已关闭" }} <a v-if="form.status_page_enabled" href="/status" target="_blank" style="margin-left:6px">打开 ↗</a></span>' +
                  '</div>' +
                '</aigw-card>' +

                /* ── 系统设置 ── */
                '<aigw-card v-if="isAdmin" title="系统设置" sub="监听范围、流式行为与日志保留" class="mt-16">' +
                  '<div class="grid grid--2">' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.expose_lan" label="对外暴露（0.0.0.0）" />' +
                      '<div class="field__hint">开启后允许局域网内其它设备访问网关；关闭时仅本机可访问</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.stream_heartbeat" label="SSE 首帧心跳" />' +
                      '<div class="field__hint">流式请求在等待上游首字节时，周期性发送空行保活，避免代理层超时断开</div>' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">日志保留天数</label>' +
                      '<input class="input" type="number" min="1" max="3650" v-model.number="form.log_keep_days">' +
                      '<div class="field__hint">超过该天数的调用日志会被定期清理（1 ~ 3650 天）</div>' +
                    '</div>' +
                  '</div>' +
                '</aigw-card>' +

                /* ── 登录与注册安全 ── */
                '<aigw-card v-if="isAdmin" title="登录与注册安全" sub="验证码、注册开关与失败锁定策略" class="mt-16">' +
                  '<div class="grid grid--2">' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.allow_register" label="开放注册" />' +
                      '<div class="field__hint">关闭后前台注册入口隐藏，且接口拒绝新用户注册</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<aigw-switch v-model="form.captcha_enabled" label="登录/注册图形验证码" />' +
                      '<div class="field__hint">开启后用户端与管理端登录、注册均需输入图形验证码</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">失败锁定阈值（次）</label>' +
                      '<input class="input" type="number" min="1" max="50" v-model.number="form.login_max_attempts">' +
                      '<div class="field__hint">同一 IP 或账号连续失败达到该次数后锁定（1 ~ 50）</div>' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">锁定时长（秒）</label>' +
                      '<input class="input" type="number" min="1" max="86400" v-model.number="form.login_lock_seconds">' +
                      '<div class="field__hint">达到阈值后的封禁时间，到期自动解锁（1 ~ 86400 秒）</div>' +
                    '</div>' +
                  '</div>' +
                '</aigw-card>' +

                /* ── 定时任务 ── */
                '<aigw-card v-if="isAdmin" title="定时任务" sub="自动签到与额度刷新，可由系统计划任务定时触发" class="mt-16">' +
                  '<div v-if="!scheduler" class="field__hint">定时任务信息加载中…</div>' +
                  '<template v-else>' +
                    '<div class="grid grid--2">' +
                      '<div class="field">' +
                        '<aigw-switch v-model="scheduler.auto_checkin" label="自动签到" />' +
                        '<div class="field__hint">定时为支持签到的供应商（Trae / CodeBuddy 等）执行每日签到</div>' +
                      '</div>' +
                      '<div class="field">' +
                        '<aigw-switch v-model="scheduler.auto_refresh" label="自动刷新额度" />' +
                        '<div class="field__hint">定时刷新各账号的剩余额度，保持额度监控数据新鲜</div>' +
                      '</div>' +
                      '<div class="field">' +
                        '<aigw-switch v-model="scheduler.travel_enabled" label="猫猫旅行" />' +
                        '<div class="field__hint">随签到时间点推进猫猫旅行：未领养则领养，空闲则派出，到站自动领奖（仅支持 CodeBuddy，国际版不参与）</div>' +
                      '</div>' +
                      '<div class="field">' +
                        '<label class="field__label">旅行派出地点</label>' +
                        '<input class="input" v-model="scheduler.travel_location" placeholder="古镇客栈">' +
                        '<div class="field__hint">优先按该地点名匹配，未匹配到时回退到可用地点列表首项</div>' +
                      '</div>' +
                      '<div class="field">' +
                        '<aigw-switch v-model="scheduler.activity_enabled" label="每日活跃注入" />' +
                        '<div class="field__hint">WorkBuddy 国际版每日活跃奖励需当日有一次真实对话才会发放；开启后随签到时间点为每个账号注入一次最小对话（每账号每日最多一次，已注入的会自动跳过）</div>' +
                      '</div>' +
                      '<div class="field">' +
                        '<label class="field__label">活跃注入账号间隔</label>' +
                        '<input class="input" type="number" min="0" max="60000" step="100" v-model.number="scheduler.activity_rate_limit_ms">' +
                        '<div class="field__hint">账号之间的注入间隔（毫秒，0-60000），用于降低触发风控的概率</div>' +
                      '</div>' +
                      '<div class="field">' +
                        '<aigw-switch v-model="scheduler.tasks_enabled" label="成长中心领奖" />' +
                        '<div class="field__hint">定时完成 WorkBuddy 国服成长中心：猫猫旅行到站自动领奖、成长任务领奖、开盲盒（仅支持 CodeBuddy，国际版不参与）</div>' +
                      '</div>' +
                      '<div class="field">' +
                        '<label class="field__label">成长中心领奖间隔（分钟）</label>' +
                        '<input class="input" type="number" min="1" max="1440" v-model.number="scheduler.tasks_interval_minutes">' +
                        '<div class="field__hint">两次领奖之间的最小间隔（1-1440 分钟），到站后最多等待该时间即会自动确认领奖</div>' +
                      '</div>' +
                      '<div class="field">' +
                        '<label class="field__label">代发对话条数（驱动成长任务）</label>' +
                        '<input class="input" type="number" min="0" max="20" v-model.number="scheduler.tasks_chat_seeds">' +
                        '<div class="field__hint">为「和 AI 聊天 5 次」这类对话计数任务每轮代发几条真实对话（0-20，0=不代发）。注意：上游结算需 90~150 秒，设置后本步会相应变慢；建画布、装技能等客户端功能型任务无法代做</div>' +
                      '</div>' +
                      '<div class="field">' +
                        '<aigw-switch v-model="scheduler.streak_enabled" label="连登管家" />' +
                        '<div class="field__hint">定时办理 WorkBuddy 国服的连登事项：有补签卡且有可补日期时自动补签、连登奖励可兑换时自动兑换、有抽奖次数自动抽奖、可开盲盒自动开箱、一次性礼包/活动补偿自动领取（仅支持 CodeBuddy，国际版不参与）</div>' +
                      '</div>' +
                      '<div class="field">' +
                        '<label class="field__label">连登管家间隔（分钟）</label>' +
                        '<input class="input" type="number" min="1" max="1440" v-model.number="scheduler.streak_interval_minutes">' +
                        '<div class="field__hint">两次连登管家办理之间的最小间隔（1-1440 分钟）；上游接口幂等，已办理的档位不会重复发放</div>' +
                      '</div>' +
                    '</div>' +
                    '<div class="schedfoot">' +
                      '<div class="schedfoot__info">' +
                        '<span class="schedfoot__label"><aigw-icon name="clock" :size="14" /> 上次执行</span>' +
                        '<span v-if="scheduler.last_run" class="schedfoot__time">{{ fmt.time(scheduler.last_run) }}</span>' +
                        '<span v-else class="schedfoot__time schedfoot__time--none">尚未执行</span>' +
                        '<aigw-badge v-if="scheduler.last_run" tone="muted">{{ fmt.ago(scheduler.last_run) }}</aigw-badge>' +
                      '</div>' +
                      '<div class="schedfoot__buttons">' +
                        '<button type="button" class="btn btn--sm" :disabled="schedulerBusy"' +
                                ' @click="runScheduler">立即执行一次</button>' +
                        '<button type="button" class="btn btn--sm btn--primary" :disabled="schedulerBusy"' +
                                ' @click="saveScheduler">保存</button>' +
                      '</div>' +
                      '<div v-if="scheduler.last_log" class="schedfoot__log">' +
                        '<span class="schedfoot__log-tag">结果</span>' +
                        '<span class="schedfoot__log-text">{{ scheduler.last_log }}</span>' +
                      '</div>' +
                    '</div>' +
                    '<div class="note mt-16">' +
                      '在服务器添加计划任务（每小时执行一次即可）：' +
                      '<div class="mono mt-8" style="word-break:break-all">{{ scheduler.cron_hint }}</div>' +
                      '<div class="field__hint mt-8">' +
                        '或通过外部计划任务定时请求：<span class="mono">POST {{ baseUrl }}{{ scheduler.endpoint }}</span>' +
                      '</div>' +
                    '</div>' +
                  '</template>' +
                '</aigw-card>' +

                /* ── 提示词模板 ── */
                '<aigw-card title="提示词模板" sub="注入到每次对话的 system 提示词；用户模板优先于全局模板" class="mt-16">' +
                  '<template #actions>' +
                    '<button type="button" class="btn btn--sm btn--primary" @click="openCreateTemplate">新建模板</button>' +
                  '</template>' +
                  '<div v-if="templates.length" class="grid grid--list">' +
                    '<div v-for="t in templates" :key="t.id" class="modelrow">' +
                      '<div class="modelrow__main">' +
                        '<div class="modelrow__title">' +
                          '<span class="modelrow__id">{{ t.name }}</span>' +
                          '<aigw-badge :tone="t.enabled ? \'success\' : \'muted\'" dot>{{ t.enabled ? "启用" : "停用" }}</aigw-badge>' +
                          '<aigw-badge v-if="t.is_default" tone="primary">默认</aigw-badge>' +
                          '<aigw-badge tone="clay">{{ templateModeLabel(t.mode) }}</aigw-badge>' +
                        '</div>' +
                        '<div v-if="t.description" class="modelrow__alias">{{ t.description }}</div>' +
                        '<div class="modelrow__meta mono">{{ t.content }}</div>' +
                      '</div>' +
                      '<div class="modelrow__side">' +
                        '<div class="modelrow__ops">' +
                          '<button type="button" class="btn btn--sm" @click="openEditTemplate(t)">编辑</button>' +
                          '<button type="button" class="btn btn--sm btn--danger" :disabled="templatesBusy"' +
                                  ' @click="removeTemplate(t)">删除</button>' +
                        '</div>' +
                      '</div>' +
                    '</div>' +
                  '</div>' +
                  '<aigw-empty v-else icon="prompts" title="还没有提示词模板"' +
                               ':desc="isAdmin ? \'新建全局模板后，所有用户都会自动注入（用户自建模板可覆盖）\' : \'新建属于你自己的模板，调用时自动注入 system 提示词\'" />' +
                '</aigw-card>' +

                '<aigw-modal v-if="showTemplate" :title="editingTemplate ? \'编辑提示词模板\' : \'新建提示词模板\'"' +
                             ' :busy="templatesBusy" confirm-text="保存"' +
                             ' @close="showTemplate = false" @confirm="submitTemplate">' +
                  '<div class="field">' +
                    '<label class="field__label">模板名称 *</label>' +
                    '<input class="input" v-model="templateForm.name" placeholder="例如：中文助手 / 代码审查">' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">注入位置</label>' +
                    '<aigw-segbar v-model="templateForm.mode" :options="templateModeOptions" />' +
                    '<div class="field__hint">前置/后置追加会保留请求原有的 system，替换则直接覆盖</div>' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">system 内容</label>' +
                    '<textarea class="input" rows="6" v-model="templateForm.content"' +
                              ' placeholder="你是一个乐于助人的中文助手…"></textarea>' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">备注（可选）</label>' +
                    '<input class="input" v-model="templateForm.description" placeholder="用于列表描述">' +
                  '</div>' +
                  '<div class="grid grid--2">' +
                    '<div class="field mb-0">' +
                      '<aigw-switch v-model="templateForm.enabled" label="启用该模板" />' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<aigw-switch v-model="templateForm.is_default" label="设为默认模板" />' +
                    '</div>' +
                  '</div>' +
                  '<div class="field mb-0 mt-8">' +
                    '<label class="field__label">排序（数字越小越靠前）</label>' +
                    '<input class="input" type="number" v-model.number="templateForm.sort_order">' +
                  '</div>' +
                '</aigw-modal>' +
              '</div>' +
            '</div>'
    };

    /* ============================================================
       网络代理
       ============================================================ */
    pages.PageProxy = {
        data: function () {
            return {
                loading: true, saving: false, error: '',
                form: {
                    enabled: false, host: '', port: '', username: '', password: '',
                    no_proxy: '', scheme: 'http'
                },
                excluded: [],
                providers: [],
                hasPassword: false
            };
        },
        computed: {
            excludedLabel: function () {
                if (!this.excluded.length) return '全部供应商均走代理';
                return '已排除 ' + this.excluded.length + ' 个供应商（其请求直连）';
            }
        },
        mounted: function () { this.load(); },
        methods: {
            load: function () {
                var self = this;
                this.loading = true;
                api.admin.proxy().then(function (res) {
                    self.loading = false;
                    if (!res.ok) { self.error = res.error || '加载失败'; return; }
                    self.error = '';
                    var d = (res.data && (res.data.proxy || res.data)) || {};
                    ['enabled', 'host', 'port', 'username', 'no_proxy', 'scheme'].forEach(function (k) {
                        if (d[k] !== undefined && d[k] !== null) self.form[k] = d[k];
                    });
                    self.excluded = Array.isArray(d.excluded_provider_ids) ? d.excluded_provider_ids.slice() : [];
                    self.providers = (res.data && res.data.providers) || [];
                    self.hasPassword = !!(d.password_set || d.has_password || d.password);
                });
            },
            toggleExcluded: function (key) {
                var i = this.excluded.indexOf(key);
                if (i === -1) this.excluded.push(key);
                else this.excluded.splice(i, 1);
            },
            save: function () {
                var self = this;
                this.saving = true;
                var payload = {
                    enabled: this.form.enabled,
                    scheme: this.form.scheme,
                    host: this.form.host,
                    port: this.form.port,
                    username: this.form.username,
                    no_proxy: this.form.no_proxy,
                    excluded_provider_ids: this.excluded
                };
                if (this.form.password) payload.password = this.form.password;
                api.admin.saveProxy(payload).then(function (res) {
                    self.saving = false;
                    if (S.report(res, '代理配置已保存')) {
                        self.form.password = '';
                        self.load();
                    }
                });
            },
            clear: function () {
                var self = this;
                global.AigwConfirm('将关闭代理并清空所有代理配置，确定继续？', {
                    title: '清除代理配置', danger: true, confirmText: '确认清除'
                }).then(function (yes) {
                    if (!yes) return;
                    self.saving = true;
                    api.admin.saveProxy({
                        enabled: false, scheme: 'http', host: '', port: '',
                        username: '', password: '', no_proxy: '', excluded_provider_ids: []
                    }).then(function (res) {
                        self.saving = false;
                        if (S.report(res, '代理配置已清除')) self.load();
                    });
                });
            }
        },
        template:
            '<div>' +
              '<div class="page-head">' +
                '<div class="page-head__main">' +
                  '<div class="topbar__title">网络代理</div>' +
                  '<div class="topbar__desc">所有出站请求（含 SSE 流）都会经由该代理</div>' +
                '</div>' +
                '<div class="page-head__actions">' +
                  '<button type="button" class="btn btn--sm" :disabled="saving" @click="clear">清除配置</button>' +
                  '<button type="button" class="btn btn--sm btn--primary" :disabled="saving" @click="save">' +
                    '{{ saving ? "保存中…" : "保存配置" }}' +
                  '</button>' +
                '</div>' +
              '</div>' +

              '<div v-if="loading" class="mt-16"><aigw-loading /></div>' +
              '<div v-else-if="error" class="card mt-16">' +
                '<aigw-empty icon="warning" title="加载失败" :desc="error" />' +
              '</div>' +

              '<div v-else>' +
                '<div class="grid grid--2 mt-16">' +
                  '<aigw-card title="代理开关" sub="控制是否经由代理出站">' +
                    '<div class="field mb-0">' +
                      '<aigw-switch v-model="form.enabled" label="启用 HTTP/HTTPS 代理" />' +
                      '<div class="field__hint">' +
                        '适用于上游在境外、或需要统一出口 IP 的场景。关闭后所有请求直连。' +
                      '</div>' +
                    '</div>' +
                    '<div class="note mt-16">' +
                      '提示：修改代理后建议到「供应商」页对每个上游执行一次「自检」，确认连通性。' +
                    '</div>' +
                  '</aigw-card>' +

                  '<aigw-card title="代理地址" sub="主机、端口与协议">' +
                    '<div class="grid grid--2">' +
                      '<div class="field">' +
                        '<label class="field__label">协议</label>' +
                        '<select class="select" v-model="form.scheme">' +
                          '<option value="http">http</option>' +
                          '<option value="https">https</option>' +
                          '<option value="socks5">socks5</option>' +
                        '</select>' +
                      '</div>' +
                      '<div class="field">' +
                        '<label class="field__label">端口</label>' +
                        '<input class="input mono" v-model="form.port" placeholder="7890">' +
                      '</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">主机地址</label>' +
                      '<input class="input mono" v-model="form.host" placeholder="127.0.0.1">' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">免代理域名</label>' +
                      '<input class="input mono" v-model="form.no_proxy" placeholder="localhost,127.0.0.1,*.internal">' +
                      '<div class="field__hint">逗号分隔，匹配的域名将直连</div>' +
                    '</div>' +
                  '</aigw-card>' +
                '</div>' +

                '<aigw-card title="代理认证" sub="若代理需要账号密码，请在此填写" class="mt-16">' +
                  '<div class="grid grid--2">' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">用户名</label>' +
                      '<input class="input" v-model="form.username" placeholder="可选">' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">密码</label>' +
                      '<input class="input" type="password" v-model="form.password"' +
                             ' :placeholder="hasPassword ? \'已设置，留空表示不修改\' : \'可选\'">' +
                    '</div>' +
                  '</div>' +
                '</aigw-card>' +

                '<aigw-card title="排除供应商" sub="勾选后，这些供应商的出站请求将直连、不经代理" class="mt-16">' +
                  '<div class="field__hint">{{ excludedLabel }}</div>' +
                  '<div v-if="providers.length" class="grid grid--cards mt-8">' +
                    '<label v-for="p in providers" :key="p.provider_key" class="switch switch--check">' +
                      '<input type="checkbox" :checked="excluded.indexOf(p.provider_key) !== -1"' +
                             ' @change="toggleExcluded(p.provider_key)">' +
                      '<span class="switch__track"></span>' +
                      '<span class="truncate">{{ p.display_name || p.provider_key }}</span>' +
                    '</label>' +
                  '</div>' +
                  '<aigw-empty v-else icon="providers" title="没有可选的供应商" />' +
                '</aigw-card>' +
              '</div>' +
            '</div>'
    };

    /* ============================================================
       我的账户
       ============================================================ */
    pages.PageProfile = {
        data: function () {
            return {
                savingProfile: false, savingPwd: false,
                profile: { username: '', nickname: '', email: '' },
                pwd: { old_password: '', new_password: '', confirm: '', email_code: '' },
                pwdError: '',
                // 改密码需邮箱验证（服务端开关 + SMTP 就绪时才为真）
                pwdVerifyNeeded: false,
                pwdCodeSending: false,
                pwdCodeCountdown: 0
            };
        },
        computed: {
            isAdmin: function () { return S.state.role !== 'user'; },
            account: function () { return S.state.user || S.state.admin || {}; },
            roleLabel: function () { return S.roleLabel ? S.roleLabel(S.state.role) : (S.state.role === 'user' ? '普通用户' : '管理员'); },
            baseUrl: function () { return location.origin; },
            // 验证码真正发给谁 —— 就是账号当前绑定的邮箱，界面上直白地写出来，
            // 免得用户对着「验证码」两个字猜去哪儿收信。
            pwdCodeTarget: function () {
                return (this.account && this.account.email) || '';
            }
        },
        mounted: function () {
            var self = this;
            var acc = this.account;
            this.profile.username = acc.username || '';
            this.profile.nickname = acc.nickname || acc.username || '';
            this.profile.email = acc.email || '';
            // 只有普通用户走邮箱验证（管理员账号表里没有邮箱字段）。
            // 开关判据统一由服务端下发，前端不复刻一份 —— 复刻就必然漂移。
            if (!this.isAdmin) {
                api.user.registerConfig().then(function (res) {
                    if (res.ok && res.data) {
                        self.pwdVerifyNeeded = !!res.data.password_change_verify;
                    }
                });
            }
        },
        methods: {
            saveProfile: function () {
                var self = this;
                this.savingProfile = true;
                api.scoped.updateProfile({
                    nickname: this.profile.nickname,
                    email: this.profile.email
                }).then(function (res) {
                    self.savingProfile = false;
                    if (S.report(res, '资料已更新')) {
                        var key = S.state.role === 'user' ? 'user' : 'admin';
                        if (S.state[key]) {
                            S.state[key].nickname = self.profile.nickname;
                            S.state[key].email = self.profile.email;
                        }
                    }
                });
            },
            changePassword: function () {
                var self = this;
                this.pwdError = '';
                if (!this.pwd.old_password || !this.pwd.new_password) {
                    this.pwdError = '请填写原密码与新密码';
                    return;
                }
                if (this.pwd.new_password.length < 8) {
                    this.pwdError = '新密码至少 8 位';
                    return;
                }
                if (this.pwd.new_password !== this.pwd.confirm) {
                    this.pwdError = '两次输入的新密码不一致';
                    return;
                }
                if (this.pwdVerifyNeeded && !(this.pwd.email_code || '').trim()) {
                    this.pwdError = '请输入邮箱验证码';
                    return;
                }
                this.savingPwd = true;
                api.scoped.changePassword({
                    current_password: this.pwd.old_password,
                    new_password: this.pwd.new_password,
                    email_code: (this.pwd.email_code || '').trim()
                }).then(function (res) {
                    self.savingPwd = false;
                    if (S.report(res, '密码已修改')) {
                        self.pwd = { old_password: '', new_password: '', confirm: '', email_code: '' };
                    }
                });
            },
            // 发送改密码验证码（purpose=change；后端强制发到账号绑定邮箱，忽略前端传值）
            sendPwdCode: function () {
                var self = this;
                if (this.pwdCodeSending || this.pwdCodeCountdown > 0) return;
                this.pwdError = '';
                this.pwdCodeSending = true;
                api.user.emailCode('', '', 'change').then(function (res) {
                    self.pwdCodeSending = false;
                    if (!res.ok) {
                        self.pwdError = (res.error && res.error.message) || res.error || res.message || '验证码发送失败';
                        return;
                    }
                    var cooldown = Number((res.data && res.data.cooldown) || 60);
                    S.toast('验证码已发送，请查收邮件', 'success');
                    self.pwdCodeSending = false;
                    self.pwdCodeCountdown = cooldown > 0 ? cooldown : 60;
                    if (self._pwdTimer) clearInterval(self._pwdTimer);
                    self._pwdTimer = setInterval(function () {
                        self.pwdCodeCountdown -= 1;
                        if (self.pwdCodeCountdown <= 0) {
                            clearInterval(self._pwdTimer);
                            self._pwdTimer = null;
                            self.pwdCodeCountdown = 0;
                        }
                    }, 1000);
                });
            },
            pwdCodeText: function () {
                if (this.pwdCodeSending) return '发送中…';
                if (this.pwdCodeCountdown > 0) return this.pwdCodeCountdown + ' 秒后重发';
                return '获取验证码';
            },
            logout: function () {
                global.AigwConfirm('确定要退出当前登录吗？', { title: '退出登录', confirmText: '退出' })
                    .then(function (yes) {
                        if (!yes) return;
                        api.scoped.logout().then(function () {
                            if (S.clearSession) { S.clearSession(); }
                            else { S.state.admin = null; S.state.user = null; S.state.role = ''; }
                            S.toast('已退出登录', 'success');
                            S.navigate('dashboard', null, { replace: true });
                        });
                    });
            }
        },
        template:
            '<div>' +
              '<div class="page-head">' +
                '<div class="page-head__main">' +
                  '<div class="topbar__title">我的账户</div>' +
                  '<div class="topbar__desc">{{ roleLabel }}资料与登录安全</div>' +
                '</div>' +
                '<div class="page-head__actions">' +
                  '<button type="button" class="btn btn--sm btn--danger" @click="logout">退出登录</button>' +
                '</div>' +
              '</div>' +

              '<div class="grid grid--2 mt-16">' +
                '<aigw-card title="基本资料" sub="用于控制台展示">' +
                  '<div class="field">' +
                    '<label class="field__label">登录账号</label>' +
                    '<input class="input mono" v-model="profile.username" readonly>' +
                    '<div class="field__hint">登录账号不可修改</div>' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">显示昵称</label>' +
                    '<input class="input" v-model="profile.nickname" placeholder="控制台右上角显示的名字">' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">邮箱</label>' +
                    '<input class="input" v-model="profile.email" placeholder="可选，用于通知">' +
                  '</div>' +
                  '<button type="button" class="btn btn--primary" :disabled="savingProfile"' +
                          ' @click="saveProfile">' +
                    '{{ savingProfile ? "保存中…" : "保存资料" }}' +
                  '</button>' +
                '</aigw-card>' +

                '<aigw-card title="修改密码" sub="建议定期更换登录密码">' +
                  '<div class="field">' +
                    '<label class="field__label">原密码</label>' +
                    '<input class="input" type="password" v-model="pwd.old_password"' +
                           ' autocomplete="current-password">' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">新密码</label>' +
                    '<input class="input" type="password" v-model="pwd.new_password"' +
                           ' autocomplete="new-password" placeholder="至少 8 位">' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">确认新密码</label>' +
                    '<input class="input" type="password" v-model="pwd.confirm"' +
                           ' autocomplete="new-password">' +
                  '</div>' +
                  // 邮箱验证码：仅普通用户 + 服务端开启时出现。
                  // 目的是把「持有会话 cookie」升级为「还需控制绑定邮箱」——
                  // 会话被窃时攻击者无法单方面改密接管账号。
                  '<div class="field" v-if="pwdVerifyNeeded">' +
                    '<label class="field__label">邮箱验证码</label>' +
                    '<div class="field__row">' +
                      '<input class="input" v-model="pwd.email_code" autocomplete="off" maxlength="6"' +
                             ' inputmode="numeric" placeholder="请输入邮件中的 6 位验证码">' +
                      '<button type="button" class="btn" style="white-space:nowrap"' +
                             ' :disabled="pwdCodeSending || pwdCodeCountdown > 0" @click="sendPwdCode">' +
                        '<span>{{ pwdCodeText() }}</span>' +
                      '</button>' +
                    '</div>' +
                    '<div class="field__hint">' +
                      '验证码将发送到账号邮箱 <b>{{ pwdCodeTarget }}</b>；修改成功后其他设备的登录状态会一并失效' +
                    '</div>' +
                  '</div>' +
                  '<div v-if="pwdError" class="note note--warn note--sm mb-16">{{ pwdError }}</div>' +
                  '<button type="button" class="btn btn--celadon" :disabled="savingPwd"' +
                          ' @click="changePassword">' +
                    '{{ savingPwd ? "提交中…" : "修改密码" }}' +
                  '</button>' +
                '</aigw-card>' +
              '</div>' +

              '<aigw-card title="关于" sub="运行环境信息" class="mt-16">' +
                '<div class="grid grid--2">' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">控制台版本</label>' +
                    '<input class="input mono" value="1.0.0" readonly>' +
                  '</div>' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">网关地址</label>' +
                    '<input class="input mono" :value="baseUrl" readonly>' +
                  '</div>' +
                '</div>' +
              '</aigw-card>' +
            '</div>'
    };

    global.AigwPagesRegister(pages);
})(window);
