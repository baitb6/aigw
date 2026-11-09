/* ============================================================
   页面组件（续）：供应商 / 账号池
   ============================================================ */
(function (global) {
    'use strict';

    var S = global.AigwStore;
    var api = global.AigwApi;
    var fmt = S.fmt;
    var pages = {};

    /* ============================================================
       账号级操作（共享 mixin）
       —— 增删改 / 刷新额度 / 签到等自检 / 领取额度 / 详情 / 模型
       统一走 api.scoped：普通用户仅作用于「本人账号」，管理员作用于全部账号；
       归属隔离由后端 Tenant 作用域 + findOrFail 兜底，前端无需再区分。
       ============================================================ */
    var accountActions = {
        data: function () {
            return {
                showDetail: false, detail: null,
                showModels: false, modelList: [], modelTitle: '',
                showClaim: false, claimAccount: null, claimPlans: [], claimBusy: false,
                claimForm: { plan_id: '', captcha: '' }, claimResult: null, claimProgress: ''
            };
        },
        methods: {
            toggleAccount: function (a) {
                var self = this;
                this.busy = true;
                api.scoped.toggleAccount(a.id).then(function (res) {
                    self.busy = false;
                    if (S.report(res)) self.load();
                });
            },
            refreshAccount: function (a) {
                var self = this;
                this.busy = true;
                api.scoped.refreshAccount(a.id).then(function (res) {
                    self.busy = false;
                    S.report(res);
                    self.load();
                });
            },
            clearCooldown: function (a) {
                var self = this;
                this.busy = true;
                api.scoped.clearCooldown(a.id).then(function (res) {
                    self.busy = false;
                    if (S.report(res)) self.load();
                });
            },
            removeAccount: function (a) {
                var self = this;
                global.AigwConfirm('确定删除账号「' + a.nickname + '」？该操作不可恢复。', {
                    title: '删除账号', danger: true, confirmText: '确认删除'
                }).then(function (yes) {
                    if (!yes) return;
                    self.busy = true;
                    api.scoped.deleteAccount(a.id).then(function (res) {
                        self.busy = false;
                        if (S.report(res)) self.load();
                    });
                });
            },
            openDetail: function (a) {
                var self = this;
                api.scoped.account(a.id).then(function (res) {
                    if (!res.ok) { S.report(res); return; }
                    self.detail = res.data;
                    self.showDetail = true;
                });
            },
            openModels: function (a) {
                var self = this;
                self.busy = true;
                api.scoped.accountModels(a.id).then(function (res) {
                    self.busy = false;
                    if (!res.ok) { S.report(res); return; }
                    self.modelList = (res.data && res.data.models) || [];
                    self.modelTitle = a.nickname;
                    self.showModels = true;
                });
            },
            runAccountAction: function (a, payload) {
                if (payload.dialog === 'claim') { this.openClaim(a); return; }
                var self = this;
                // 参数名由动作自带（默认 code）；allowEmpty 允许「留空」作为有效值（如清除）。
                var params = {};
                if (payload.value !== '' && payload.value !== undefined && payload.value !== null) {
                    params[payload.param || 'code'] = payload.value;
                } else if (payload.allowEmpty) {
                    params[payload.param || 'code'] = '';
                }
                this.busy = true;
                api.scoped.accountAction(a.id, payload.action, params).then(function (res) {
                    self.busy = false;
                    S.report(res);
                    if (res.ok && payload.action !== 'checkin_status' && payload.action !== 'onboarding_tasks') {
                        self.load();
                    }
                });
            },
            openClaim: function (a) {
                var self = this;
                this.claimAccount = a;
                this.claimPlans = [];
                this.claimForm = { plan_id: '', captcha: '' };
                this.claimResult = null;
                this.claimQuota = null;
                this.showClaimForm = false;
                this.claimBusy = true;
                this.showClaim = true;
                api.scoped.accountAction(a.id, 'claim_preview', {}).then(function (res) {
                    self.claimBusy = false;
                    var data = (res && res.data) || {};
                    self.claimPlans = data.plans || [];
                    // 额度快照是 claim_preview 一起下发的（只读，不需要验证码）
                    self.claimQuota = (data.packages || data.total_remain != null) ? {
                        packages: data.packages || [],
                        total_remain: data.total_remain,
                        total_limit: data.total_limit,
                        expire_at: data.expire_at,
                        api_key_ready: !!data.api_key_ready
                    } : null;
                    var first = self.claimPlans.filter(function (p) { return p.claimable; })[0] || self.claimPlans[0];
                    if (first) self.claimForm.plan_id = first.plan_id;
                    // 没有可领项时不再显红字报错——空 = 「暂时没有新的可领」，属正常
                    if (!res.ok) self.claimResult = { ok: false, message: res.message || '额度查询失败' };
                });
            },
            submitClaim: function () {
                var self = this;
                if (!this.claimAccount) return;
                if (!this.claimForm.plan_id) { S.toast('请选择要领取的额度', 'warning'); return; }
                this.claimBusy = true;
                this.claimResult = null;
                this.claimProgress = '正在提交任务…';
                // 一键领取：验证码由服务端**自动无痕求解**（阿里云 2.0 V3 的
                // startTracelessVerification()，风控评分通过即零交互返回 param），
                // 所以这里默认不带 captcha。用户在兜底输入框里手动粘了值才带上。
                var payload = { plan_id: this.claimForm.plan_id };
                var manual = (this.claimForm.captcha || '').trim();
                if (manual) payload.captcha = manual;
                api.scoped.accountAction(this.claimAccount.id, 'claim', payload).then(function (res) {
                    var data = (res && res.data) || {};
                    // 服务端已把长耗时动作异步化：立即返回 task_id，真实领取在后台跑。
                    // 原因：领额度要驱动浏览器过无痕验证，9~75s，会撞 CDN 回源超时（524）。
                    if (data.async && data.task_id) {
                        self.claimProgress = (data.reused ? '已有任务在执行，等待结果…' : '任务已提交，正在后台领取…');
                        self.pollClaimTask(data.task_id);
                        return;
                    }
                    // 兼容同步路径（老版本后端 / 未异步化的供应商）
                    self.claimBusy = false;
                    self.claimProgress = '';
                    self.claimResult = {
                        ok: !!res.ok,
                        message: res.message || '',
                        outcome: data.outcome || ''
                    };
                    S.report(res);
                    if (res.ok) {
                        self.load();
                        self.openClaim(self.claimAccount);
                    }
                });
            },
            /**
             * 轮询异步领取任务结果。
             * 后台要跑「拉配置 + 无痕验证 + claim」，通常 10~60 秒；
             * 前端每 2 秒问一次。
             *
             * ⚠️ 2026-10-02 线上「卡在正在执行中、看不到成功也没报错」故障：
             *   ① 轮询请求**没有 .catch**，一旦接口报错（404/500/网络），
             *      `.then` 不执行 → 界面永远停在「正在执行中」，且**不报任何错**；
             *   ② 上限 180s 太长，而后端 PENDING 判定是 60s。
             *   现在：catch 兜底 + 连续失败计数 + 对齐后端阈值。
             */
            pollClaimTask: function (taskId) {
                var self = this;
                var tries = 0;
                var maxTries = 60;        // 60 × 2s = 120s（后端 PENDING 60s / RUNNING 300s 兜底）
                var errStreak = 0;        // 连续请求失败次数
                var maxErrStreak = 5;     // 连续 5 次（约 10s）拿不到结果就收口

                function stop(msg) {
                    clearInterval(timer);
                    self.claimBusy = false;
                    self.claimProgress = '';
                    self.claimResult = { ok: false, message: msg };
                }

                var timer = setInterval(function () {
                    tries++;
                    if (tries > maxTries) {
                        stop('领取超时未返回结果，请稍后在「签到日志」确认，或重试。');
                        return;
                    }

                    api.scoped.accountTask(taskId).then(function (res) {
                        // 请求层即使 HTTP 错误也会 resolve，这里统一判 ok
                        if (!res || !res.ok) {
                            errStreak++;
                            var em = (res && (res.message || res.error)) || '查询任务状态失败';
                            if (errStreak >= maxErrStreak) {
                                stop('无法查询领取结果：' + em + '（请检查后台任务是否启动，见 storage/logs/action-worker.log）');
                                return;
                            }
                            self.claimProgress = '查询任务状态失败，正在重试…';
                            return;
                        }
                        errStreak = 0;

                        var d = (res && res.data) || {};
                        if (!d.done) {
                            var sec = Math.round((d.elapsed_ms || 0) / 1000);
                            self.claimProgress = sec < 15
                                ? '正在过无痕验证（第 ' + sec + ' 秒）…'
                                : '正在领取额度（第 ' + sec + ' 秒）…';
                            return;
                        }

                        clearInterval(timer);
                        self.claimBusy = false;
                        self.claimProgress = '';
                        var result = d.result || {};
                        self.claimResult = {
                            ok: !!d.ok,
                            message: d.message || (d.ok ? '领取成功' : '领取失败'),
                            outcome: result.outcome || ''
                        };
                        S.report({ ok: !!d.ok, message: d.message || '' });
                        if (d.ok) {
                            self.load();
                            self.openClaim(self.claimAccount);
                        }
                    }).catch(function (e) {
                        // 双保险：请求层已有 catch，这里再兜一层，绝不让异常静默吞掉
                        errStreak++;
                        if (errStreak >= maxErrStreak) {
                            stop('无法查询领取结果：' + (e && e.message ? e.message : '网络异常')
                                + '（请稍后在「签到日志」确认，或重试）');
                        }
                    });
                }, 2000);
            },
            claimOutcomeLabel: function (o) {
                return ({
                    claimed: '领取成功',
                    already: '今日已领',
                    not_found: '已下线',
                    need_captcha: '需人工验证',
                    failed: '失败'
                })[o] || '';
            },
            openClaimSite: function () { window.open('https://zcode.z.ai', '_blank'); },
            claimStatusLabel: function (status) {
                return { SUCCESS: '领取成功', ALREADY: '已领取', FAILED: '失败', SKIPPED: '跳过' }[status] || (status || '—');
            },
            claimStatusTone: function (status) {
                return { SUCCESS: 'success', ALREADY: 'muted', FAILED: 'danger', SKIPPED: 'warning' }[status] || 'muted';
            },
            modelLabel: function (m) { return m.id || m.model || m.name || String(m); },
            modelSub: function (m) { return m.name || m.display_name || ''; },
            coolingLabel: function (a) {
                var sec = Number(a && a.cooling_remain);
                if (!sec || sec <= 0) return '无';
                return fmt.countdown(sec);
            },
            // textarea 不支持插值，统一走 :value 绑定
            previewText: function (obj) {
                try { return JSON.stringify(obj || {}, null, 2); } catch (e) { return String(obj); }
            }
        }
    };

    /* ============================================================
       供应商页（按供应商分组展示其下账号）
       ============================================================ */
    pages.PageProviders = {
        mixins: [accountActions],
        data: function () {
            return {
                loading: true, busy: false, items: [], error: '',
                accounts: [], accountsLoading: false,
                // 每个供应商的账号区折叠状态：只记「已展开」的 provider_key（**默认折叠**）
                expandedProviders: {},
                showCreate: false, saving: false,
                form: { display_name: '', provider_key: '', alias: '', base_url: '', api_keys: '', models: '' },
                showEdit: false, editing: null,
                editForm: { display_name: '', default_model: '', alias: '', base_url: '', api_keys: '', models: '' },
                showLogin: false, loginProvider: null, loginType: '',
                loginForm: { phone: '', code: '', api_key: '', base_url: '', access_token: '', refresh_token: '', cookie: '', ms_token: '', device_id: '', web_id: '', callback_url: '', login_json: '' },
                loginSession: null, loginPolling: false, loginMessage: '', codeSent: false
            };
        },
        mounted: function () { this.load(); },
        computed: {
            isAdmin: function () { return S.state.role !== 'user'; },
            // 账号按供应商归组：账号列表一次性拉取，前端直接分组，避免每个供应商各发一次请求
            accountsByProvider: function () {
                var map = {};
                (this.accounts || []).forEach(function (a) {
                    var k = a.provider_key || '';
                    if (!map[k]) map[k] = [];
                    map[k].push(a);
                });
                return map;
            },
            totalAccounts: function () { return (this.accounts || []).length; },
            /**
             * 每个供应商下账号的**积分合计**（供组头展示）。
             * ★ 口径与账号池页一致：只累加 `credits_known` 的账号，未知的单独计数，
             *   不把未知当 0 混加（否则组头数字静默偏小且无从解释）。
             */
            providerCredits: function () {
                var out = {};
                var self = this;
                Object.keys(this.accountsByProvider).forEach(function (k) {
                    var g = { total: 0, known: 0, unknown: 0, sum: 0 };
                    self.accountsByProvider[k].forEach(function (a) {
                        g.total++;
                        if (a.credits_known && a.credits !== null && a.credits !== undefined) {
                            g.sum += Number(a.credits) || 0;
                            g.known++;
                        } else {
                            g.unknown++;
                        }
                    });
                    out[k] = g;
                });
                return out;
            },
            allProvidersExpanded: function () {
                var self = this;
                return (this.items || []).every(function (p) {
                    return !!self.expandedProviders[p.provider_key];
                });
            },
            // 各供应商的 Token 取得方式不同，指引文案必须分别说明，否则用户取到的凭据无法调用 API
            // 注意：Trae 与豆包同为 WEBVIEW_CALLBACK，仅凭 loginType 会串台，必须先按 provider_key 区分
            tokenSourceHint: function () {
                var key = (this.loginProvider && this.loginProvider.provider_key) || '';
                if (key === 'doubao') {
                    return '豆包凭据来自网页版登录态（Cookie），不是 accessToken。' +
                           '请在浏览器登录 www.doubao.com → F12 → Application → Cookies，' +
                           '复制整条 Cookie（至少包含 sessionid、msToken）粘贴到下方。';
                }
                if (key === 'trae' || key === 'trae_intl') {
                    return '请从桌面客户端（Trae IDE）的本地登录数据中取出 accessToken。' +
                           '网页版 localStorage 里的 Cloud-IDE-Token 受众不同，无法用于调用 API。';
                }
                if (key === 'xiaohuanxiong') {
                    return '商汤小浣熊有两种方式：① 电脑端用「电脑版授权」，授权后地址栏会变成 ' +
                           'office-raccoon://auth/callback?code=..&state=..（浏览器打不开该协议是正常的），' +
                           '把这条链接整条复制粘贴提交；② 手机端用「手机版授权」，' +
                           '回调会换成 http://127.0.0.1:18080/…?authorization_code=..&state=.. ，' +
                           '手机提示「无法连接」是正常的，长按地址栏复制这条链接粘贴提交即可。' +
                           '两种方式都拿不到时，可改用「手工填 Token」：' +
                           '在浏览器登录 xiaohuanxiong.com → F12 → Network 复制 Authorization: Bearer 后的 JWT，' +
                           '或从官方桌面端 ~/.box-agent/config/auth.json 读取 access_token。';
                }
                if (key === 'zcode' || key === 'zcode_intl') {
                    return 'ZCode 走智谱 GLM Coding Plan 授权：点击「生成授权链接」→ 用智谱账号登录并授权 → ' +
                           '本页会自动轮询换取令牌入库。也可直接粘贴凭据里的 access_token。';
                }
                if (this.loginType === 'OAUTH_LOOPBACK') {
                    return '该供应商走 Google OAuth：先点下方「发起授权」完成一次授权，再把拿到的 refresh_token 粘贴回来。';
                }
                if (this.loginType === 'WEBVIEW_CALLBACK') {
                    return '请从桌面客户端的本地登录数据中取出 accessToken。' +
                           '网页版 localStorage 里的凭据受众不同，无法用于调用 API。';
                }
                if (this.loginType === 'SMS_CODE') {
                    return 'Loomy 的登录态存放在桌面客户端的本地数据里，字段名是 session，' +
                           '复制它的值即可（不是网页 Cookie）。';
                }
                return '打开供应商官网并登录 → F12 开发者工具 → Application → Local Storage → 复制 access_token 的值。';
            }
        },
        methods: {
            load: function () {
                var self = this;
                this.loading = true;
                api.scoped.providers().then(function (res) {
                    self.loading = false;
                    if (!res.ok) { self.error = res.error || '加载失败'; return; }
                    self.error = '';
                    self.items = ((res.data && res.data.items) || []).slice().sort(function (a, b) {
                        return (a.kind === 'custom' ? 1 : 0) - (b.kind === 'custom' ? 1 : 0);
                    });
                    S.state.providers = self.items;
                    self.loadAccounts();
                    self.consumePendingLogin();
                });
            },
            // 账号列表一次性拉取后按 provider_key 分组：普通用户仅见本人账号，管理员见全部
            loadAccounts: function () {
                var self = this;
                this.accountsLoading = true;
                api.scoped.accounts({}).then(function (res) {
                    self.accountsLoading = false;
                    if (!res.ok) return;
                    self.accounts = (res.data && res.data.items) || [];
                });
            },
            accountsFor: function (p) { return this.accountsByProvider[p.provider_key] || []; },

            /* ── 供应商页：账号区折叠（默认折叠） ───────────────────── */

            /** 该供应商的账号区是否折叠（该页无搜索框，纯折叠态） */
            isProviderCollapsed: function (key) {
                return !this.expandedProviders[key];
            },
            toggleProviderCollapse: function (key) {
                var next = {};
                var self = this;
                Object.keys(this.expandedProviders || {}).forEach(function (k) { next[k] = self.expandedProviders[k]; });
                next[key] = !next[key];
                this.expandedProviders = next;
            },
            toggleAllProviders: function () {
                if (this.allProvidersExpanded) { this.expandedProviders = {}; return; }
                var next = {};
                (this.items || []).forEach(function (p) { next[p.provider_key] = true; });
                this.expandedProviders = next;
            },
            /** 该供应商积分合计文案（未知时回「未知」，不显示 0） */
            providerCreditsLabel: function (key) {
                var g = (this.providerCredits || {})[key];
                if (!g || g.known === 0) return '未知';
                return fmt.num(g.sum, 2);
            },
            providerCreditsMeta: function (key) {
                return (this.providerCredits || {})[key] || { total: 0, known: 0, unknown: 0, sum: 0 };
            },

            splitTokens: function (text) {
                return String(text || '').split(/[\s,\n]+/).map(function (s) { return s.trim(); }).filter(Boolean);
            },
            // 写权限：管理员可动全部；普通用户仅可动**自己创建**的供应商实例（user_id === 本人 / mine 标记）
            // 后端还有一层归属校验兜底，这里只是提前给出友好提示。
            canWrite: function (p) {
                if (this.isAdmin) return true;
                if (!p) return false;
                return !!p.mine || (p.user_id && p.user_id > 0);
            },
            requireWrite: function (p) {
                if (this.canWrite(p)) return true;
                S.toast('内置供应商由管理员维护，你只能管理自己创建的供应商', 'warning');
                return false;
            },
            openCreate: function () {
                this.form = { display_name: '', provider_key: '', alias: '', base_url: '', api_keys: '', models: '' };
                this.showCreate = true;
            },
            submitCreate: function () {
                var self = this;
                if (!this.form.display_name) { S.toast('请填写供应商名称', 'warning'); return; }
                this.saving = true;
                api.scoped.createProvider({
                    display_name: this.form.display_name,
                    provider_key: this.form.provider_key,
                    alias: this.form.alias,
                    base_url: this.form.base_url,
                    api_keys: this.splitTokens(this.form.api_keys),
                    models: this.splitTokens(this.form.models)
                }).then(function (res) {
                    self.saving = false;
                    if (S.report(res)) { self.showCreate = false; self.load(); }
                });
            },
            toggle: function (p) {
                var self = this;
                if (!this.requireWrite(p)) return;
                this.busy = true;
                api.scoped.updateProvider(p.provider_key, { enabled: !p.enabled }).then(function (res) {
                    self.busy = false;
                    if (S.report(res, p.enabled ? '已停用' : '已启用')) self.load();
                });
            },
            openEdit: function (p) {
                if (!this.requireWrite(p)) return;
                this.editing = p;
                var o = p.options || {};
                this.editForm = {
                    display_name: p.display_name,
                    default_model: p.default_model || '',
                    alias: o.alias || '',
                    base_url: o.base_url || '',
                    api_keys: '',
                    models: Array.isArray(o.models) ? o.models.join('\n') : ''
                };
                this.showEdit = true;
            },
            submitEdit: function () {
                var self = this;
                var p = this.editing;
                if (!this.requireWrite(p)) return;
                this.saving = true;
                var payload = {
                    display_name: this.editForm.display_name,
                    default_model: this.editForm.default_model
                };
                if (p.kind === 'custom') {
                    payload.base_url = this.editForm.base_url;
                    payload.alias = this.editForm.alias;
                    if (this.editForm.api_keys.trim()) {
                        payload.api_keys = this.splitTokens(this.editForm.api_keys);
                    }
                    payload.models = this.splitTokens(this.editForm.models);
                }
                api.scoped.updateProvider(p.provider_key, payload).then(function (res) {
                    self.saving = false;
                    if (S.report(res)) { self.showEdit = false; self.load(); }
                });
            },
            remove: function (p) {
                var self = this;
                if (!this.requireWrite(p)) return;
                global.AigwConfirm('删除供应商「' + p.display_name + '」将同时清除其下所有账号与模型，且不可恢复。', {
                    title: '删除供应商', danger: true, confirmText: '确认删除'
                }).then(function (yes) {
                    if (!yes) return;
                    self.busy = true;
                    api.scoped.deleteProvider(p.provider_key).then(function (res) {
                        self.busy = false;
                        if (S.report(res)) self.load();
                    });
                });
            },
            test: function (p) {
                var self = this;
                if (!this.requireWrite(p)) return;
                this.busy = true;
                api.scoped.testProvider(p.provider_key).then(function (res) {
                    self.busy = false;
                    S.report(res, '连通性正常：' + p.display_name);
                });
            },
            goModels: function () { S.navigate('models'); },

            // 添加账号：管理员与普通用户都可用；普通用户入库账号自动归属本人
            openLogin: function (p) {
                this.loginProvider = p;
                this.loginType = p.login_type;
                this.loginForm = {
                    phone: '', code: '', api_key: '',
                    base_url: (p.options && p.options.base_url) || '',
                    access_token: '',
                    refresh_token: '',
                    cookie: '', ms_token: '', device_id: '', web_id: '',
                    callback_url: '',
                    login_json: ''
                };
                this.loginSession = null;
                this.loginMessage = '';
                this.loginPolling = false;
                this.codeSent = false;
                this.showLogin = true;
            },
            // 从「账号管理」页跳转过来时，自动打开对应供应商的登录弹窗
            consumePendingLogin: function () {
                var key = S.state.pendingLoginProvider;
                if (!key) return;
                S.state.pendingLoginProvider = '';
                for (var i = 0; i < this.items.length; i++) {
                    if (this.items[i].provider_key === key) { this.openLogin(this.items[i]); return; }
                }
            },
            // 后端 LoginSession::toArray() 输出 snake_case，这里统一归一化，
            // 避免模板读到 undefined（此前 auth_url/user_code 对不上，授权链接根本不显示）
            normalizeSession: function (raw) {
                if (!raw || typeof raw !== 'object') return null;
                return {
                    sessionId:  raw.sessionId || raw.session_id || '',
                    state:      raw.state || 'waiting',
                    message:    raw.message || '',
                    authUrl:    raw.authUrl || raw.auth_url || raw.qrUrl || raw.url || '',
                    userCode:   raw.userCode || raw.user_code || '',
                    deviceCode: raw.deviceCode || raw.device_code || '',
                    accountUid: raw.accountUid || raw.account_uid || '',
                    nickname:   raw.nickname || '',
                    msgid:      (raw.extra && raw.extra.msgid) || raw.msgid || '',
                    error:      raw.error || ''
                };
            },
            // 等待授权中的状态：后端用 waiting，历史实现用 pending，两者都接受
            isWaitingState: function (state) {
                return state === 'waiting' || state === 'pending';
            },
            loginPayload: function () {
                var f = this.loginForm, p = this.loginProvider;
                var payload = { provider_key: p.provider_key };
                if (this.loginSession && this.loginSession.sessionId) {
                    payload.session_id = this.loginSession.sessionId;
                }
                if (this.loginType === 'API_KEY') {
                    payload.base_url = f.base_url;
                    payload.api_key = f.api_key;
                    payload.nickname = p.display_name;
                } else if (this.loginType === 'SMS_CODE') {
                    payload.phone = f.phone;
                    payload.code = f.code;
                } else if (p.provider_key === 'doubao') {
                    payload.credential = {
                        cookie: f.cookie,
                        ms_token: f.ms_token,
                        device_id: f.device_id,
                        web_id: f.web_id
                    };
                } else if (f.access_token) {
                    payload.access_token = f.access_token;
                }
                return payload;
            },
            startLogin: function () { this.runStartLogin(false); },
            /**
             * 手机模式：不带 login_source，改带 http://127.0.0.1:18080 回环 redirect，
             * 上游因此产出标准 http 回调（?authorization_code=..&state=..），
             * 手机地址栏会保留该 URL，长按即可复制后粘回「粘贴回调地址」。
             */
            startMobileLogin: function () { this.runStartLogin(true); },
            runStartLogin: function (mobile) {
                var self = this;
                this.saving = true;
                this.loginMessage = '';
                var payload = this.loginPayload();
                if (mobile) payload.mobile = true;
                api.scoped.startLogin(this.loginProvider.provider_key, payload).then(function (res) {
                    self.saving = false;
                    if (!res.ok) { self.loginMessage = res.error || '发起登录失败'; return; }
                    self.loginSession = self.normalizeSession(res.data);
                    self.loginMessage = (self.loginSession && self.loginSession.message) || '请按提示完成登录';
                    if (self.loginSession && self.loginSession.state === 'success') {
                        S.toast('账号已添加', 'success');
                        self.showLogin = false;
                        self.load();
                    } else if (self.loginSession && self.isWaitingState(self.loginSession.state)) {
                        self.beginPoll();
                    }
                });
            },
            beginPoll: function () {
                var self = this;
                if (this.loginPolling) return;
                this.loginPolling = true;
                var attempts = 0;
                var tick = function () {
                    if (!self.showLogin || !self.loginPolling || attempts++ > 40) {
                        self.loginPolling = false;
                        return;
                    }
                    api.scoped.pollLogin(self.loginProvider.provider_key, self.loginPayload()).then(function (res) {
                        var s = self.normalizeSession(res.ok ? res.data : null);
                        if (s) {
                            // 后端轮询响应通常不带 auth_url（只有发起时才返回授权链接），
                            // 直接覆盖会让页面上的授权链接/设备码在 3 秒后消失，这里沿用上一次的值
                            var prev = self.loginSession;
                            if (prev) {
                                if (!s.authUrl) s.authUrl = prev.authUrl || '';
                                if (!s.userCode) s.userCode = prev.userCode || '';
                                if (!s.sessionId) s.sessionId = prev.sessionId || '';
                            }
                            self.loginSession = s;
                            if (s.message) self.loginMessage = s.message;
                            if (s.state === 'success') {
                                self.loginPolling = false;
                                S.toast('账号已成功入池', 'success');
                                self.showLogin = false;
                                self.load();
                                return;
                            }
                            if (s.state === 'failed' || s.state === 'expired') {
                                self.loginPolling = false;
                                self.loginMessage = s.message || '登录失败';
                                return;
                            }
                        }
                        setTimeout(tick, 3000);
                    });
                };
                setTimeout(tick, 3000);
            },
            // 回环回调页打不开时的兜底：粘贴地址栏完整 URL，后端解析后完成入库
            submitCallbackUrl: function () {
                var self = this;
                var url = (this.loginForm.callback_url || '').trim();
                if (!url) { S.toast('请先粘贴回调地址', 'warning'); return; }
                this.saving = true;
                this.loginMessage = '';
                api.scoped.submitAuthCallback(this.loginProvider.provider_key, {
                    provider_key: this.loginProvider.provider_key,
                    redirect_url: url,
                    session_id: (this.loginSession && this.loginSession.sessionId) || ''
                }).then(function (res) {
                    self.saving = false;
                    if (res && res.ok) {
                        self.loginMessage = res.message || '授权已完成';
                        S.toast('账号已成功入池', 'success');
                        self.showLogin = false;
                        self.load();
                        return;
                    }
                    self.loginMessage = (res && (res.error || res.message)) || '提交回调失败';
                    S.toast(self.loginMessage, 'error');
                });
            },
            // 设备码流程：手动触发一次检测（自动轮询超时或用户登录较慢时兜底）
            checkLoginNow: function () {
                var self = this;
                if (!this.loginProvider) return;
                if (!this.loginSession || !this.loginSession.sessionId) {
                    S.toast('请先点击「生成授权链接」', 'warning');
                    return;
                }
                this.saving = true;
                this.loginMessage = '正在检测授权状态…';
                api.scoped.pollLogin(this.loginProvider.provider_key, this.loginPayload()).then(function (res) {
                    self.saving = false;
                    var s = self.normalizeSession(res.ok ? res.data : null);
                    if (!res.ok || !s) {
                        self.loginMessage = (res && res.error) || '检测失败，请稍后重试';
                        return;
                    }
                    self.loginSession = s;
                    self.loginMessage = s.message || '等待授权完成…';
                    if (s.state === 'success') {
                        self.loginPolling = false;
                        S.toast('账号已成功入池', 'success');
                        self.showLogin = false;
                        self.load();
                        return;
                    }
                    if (s.state === 'failed' || s.state === 'expired') {
                        self.loginPolling = false;
                        return;
                    }
                    if (!self.loginPolling) self.beginPoll();
                });
            },
            saveTokenDirect: function () {
                var self = this;
                if (!this.loginForm.access_token) { S.toast('请粘贴 Access Token', 'warning'); return; }
                this.saving = true;
                api.scoped.createAccount({
                    provider_key: this.loginProvider.provider_key,
                    access_token: this.loginForm.access_token,
                    nickname: this.loginProvider.display_name
                }).then(function (res) {
                    self.saving = false;
                    if (res && res.ok) {
                        self.loginMessage = (res.data && res.data.message) || '账号已保存';
                        S.toast(self.loginMessage, 'success');
                        self.showLogin = false;
                        self.load();
                        return;
                    }
                    self.loginMessage = (res && res.error) || '保存失败';
                    S.toast(self.loginMessage, 'error');
                });
            },
            // 豆包：凭据是网页 Cookie（含 sessionid/msToken），不是 accessToken，单独走 credential 导入
            saveDoubaoCredential: function () {
                var self = this;
                var f = this.loginForm;
                if (!(f.cookie || '').trim() && !(f.ms_token || '').trim()) {
                    S.toast('请至少粘贴豆包 Cookie 或 msToken', 'warning');
                    return;
                }
                this.saving = true;
                this.loginMessage = '';
                api.scoped.createAccount({
                    provider_key: this.loginProvider.provider_key,
                    credential: {
                        cookie: (f.cookie || '').trim(),
                        ms_token: (f.ms_token || '').trim(),
                        device_id: (f.device_id || '').trim(),
                        web_id: (f.web_id || '').trim()
                    },
                    nickname: this.loginProvider.display_name
                }).then(function (res) {
                    self.saving = false;
                    if (res && res.ok) {
                        self.loginMessage = (res.data && res.data.message) || '豆包账号已保存';
                        S.toast(self.loginMessage, 'success');
                        self.showLogin = false;
                        self.load();
                        return;
                    }
                    self.loginMessage = (res && res.error) || '保存失败';
                    S.toast(self.loginMessage, 'error');
                });
            },
            // 小浣熊：手机端拿不到 office-raccoon:// 回调时的兜底——手工粘贴 JWT 凭据
            saveXiaohuanxiongCredential: function () {
                var self = this;
                var f = this.loginForm;
                var access = (f.access_token || '').trim();
                if (!access) { S.toast('请粘贴 access_token', 'warning'); return; }
                this.saving = true;
                this.loginMessage = '';
                api.scoped.createAccount({
                    provider_key: this.loginProvider.provider_key,
                    credential: {
                        access_token: access,
                        refresh_token: (f.refresh_token || '').trim()
                    },
                    nickname: this.loginProvider.display_name
                }).then(function (res) {
                    self.saving = false;
                    if (res && res.ok) {
                        self.loginMessage = (res.data && res.data.message) || '小浣熊账号已保存';
                        S.toast(self.loginMessage, 'success');
                        self.showLogin = false;
                        self.load();
                        return;
                    }
                    self.loginMessage = (res && res.error) || '保存失败';
                    S.toast(self.loginMessage, 'error');
                });
            },
            // WorkBuddy / CodeBuddy：粘贴桌面客户端登录 JSON（含 account + auth 两段）直接入库
            saveLoginJson: function () {
                var self = this;
                var raw = (this.loginForm.login_json || '').trim();
                if (!raw) { S.toast('请粘贴登录 JSON', 'warning'); return; }
                var parsed;
                try {
                    parsed = JSON.parse(raw);
                } catch (e) {
                    S.toast('JSON 解析失败，请确认已完整复制', 'error');
                    return;
                }
                if (!parsed || typeof parsed !== 'object') { S.toast('JSON 内容不是对象', 'error'); return; }
                this.saving = true;
                this.loginMessage = '';
                api.scoped.createAccount({
                    provider_key: this.loginProvider.provider_key,
                    credential: parsed,
                    nickname: this.loginProvider.display_name
                }).then(function (res) {
                    self.saving = false;
                    if (res && res.ok) {
                        self.loginMessage = (res.data && res.data.message) || '登录凭据已导入';
                        S.toast(self.loginMessage, 'success');
                        self.showLogin = false;
                        self.load();
                        return;
                    }
                    self.loginMessage = (res && res.error) || '导入失败';
                    S.toast(self.loginMessage, 'error');
                });
            },
            openProviderSite: function () {
                var hosts = this.loginProvider.hosts;
                var host = (hosts && hosts.length) ? hosts[hosts.length - 1] : '';
                if (!host) { S.toast('该供应商未配置官网地址', 'warning'); return; }
                window.open('https://' + host, '_blank');
            },
            // 短信登录第一步：发验证码（后端 startLogin 带 phone 即触发发送）
            sendSmsCode: function () {
                var self = this;
                var phone = (this.loginForm.phone || '').trim();
                if (!/^1[3-9]\d{9}$/.test(phone)) { S.toast('请填写正确的手机号', 'warning'); return; }
                this.saving = true;
                this.loginMessage = '';
                api.scoped.startLogin(this.loginProvider.provider_key, {
                    provider_key: this.loginProvider.provider_key,
                    phone: phone
                }).then(function (res) {
                    self.saving = false;
                    if (!res.ok) {
                        self.loginMessage = res.error || '发送验证码失败';
                        S.toast(self.loginMessage, 'error');
                        return;
                    }
                    self.loginSession = self.normalizeSession(res.data);
                    self.loginMessage = (self.loginSession && self.loginSession.message) || '验证码已发送';
                    if (self.loginSession && self.loginSession.state === 'failed') {
                        S.toast(self.loginMessage, 'error');
                        return;
                    }
                    self.codeSent = true;
                    S.toast('验证码已发送', 'success');
                });
            },
            // 短信登录第二步：校验验证码并落库
            verifySmsCode: function () {
                var self = this;
                var phone = (this.loginForm.phone || '').trim();
                var code = (this.loginForm.code || '').trim();
                if (!/^1[3-9]\d{9}$/.test(phone)) { S.toast('请填写正确的手机号', 'warning'); return; }
                if (!code) { S.toast('请填写短信验证码', 'warning'); return; }
                this.saving = true;
                this.loginMessage = '';
                api.scoped.pollLogin(this.loginProvider.provider_key, {
                    provider_key: this.loginProvider.provider_key,
                    session_id: (this.loginSession && this.loginSession.sessionId) || '',
                    msgid: (this.loginSession && this.loginSession.msgid) || '',
                    phone: phone,
                    code: code
                }).then(function (res) {
                    self.saving = false;
                    var s = self.normalizeSession(res.ok ? res.data : null);
                    if (!res.ok || !s) {
                        self.loginMessage = (res && res.error) || '登录失败';
                        S.toast(self.loginMessage, 'error');
                        return;
                    }
                    self.loginSession = s;
                    if (s.state === 'success') {
                        S.toast('账号已成功入池', 'success');
                        self.showLogin = false;
                        self.load();
                        return;
                    }
                    self.loginMessage = s.message || '验证码校验失败';
                    S.toast(self.loginMessage, 'error');
                });
            },
            closeLogin: function () { this.loginPolling = false; this.showLogin = false; },
            openAuthUrl: function (url) { window.open(url, '_blank'); },
            // 手机端常用：授权链接本身也需要能复制（很多手机浏览器点开新标签后会丢失来源页面）
            copyText: function (text, label) {
                var t = String(text || '');
                if (t === '') { S.toast('没有可复制的内容', 'warning'); return; }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(t).then(function () {
                        S.toast((label || '内容') + '已复制', 'success');
                    }).catch(function () { S.toast('复制失败，请长按选择复制', 'warning'); });
                } else {
                    S.toast('复制失败，请长按选择复制', 'warning');
                }
            }
        },
        template:
            '<div>' +
              '<div class="page-head">' +
                '<div class="page-head__main">' +
                  '<div class="topbar__title">供应商</div>' +
                  '<div class="topbar__desc">{{ isAdmin ? ("共 " + items.length + " 个上游 · " + totalAccounts + " 个账号，按供应商分组") : ("共 " + items.length + " 个上游 · 你的 " + totalAccounts + " 个账号按供应商分组") }}</div>' +
                '</div>' +
                '<div class="page-head__actions">' +
                  '<button type="button" class="btn btn--sm" @click="load">刷新</button>' +
                  '<button type="button" class="btn btn--primary btn--sm" @click="openCreate">+ 自定义供应商</button>' +
                '</div>' +
              '</div>' +

              '<div v-if="!isAdmin" class="note note--info" style="margin-bottom:16px">' +
                '你可以自助创建自定义供应商（如自建中转站），并为其添加账号、同步模型。' +
                '内置供应商由管理员统一维护 —— 你可以添加自己的账号，但配置与模型开关请在自己创建的自定义供应商上操作。' +
              '</div>' +

              '<div v-if="loading"><aigw-loading /></div>' +
              '<div v-else-if="error" class="card">' +
                '<aigw-empty icon="warning" title="加载失败" :desc="error" />' +
              '</div>' +
              '<div v-else-if="!items.length" class="card">' +
                '<aigw-empty icon="providers" title="还没有供应商" :desc="\'添加一个自定义供应商即可开始\'">' +
                  '<button type="button" class="btn btn--primary" @click="openCreate">+ 自定义供应商</button>' +
                '</aigw-empty>' +
              '</div>' +

              '<div v-else class="provider-groups">' +
                '<div v-if="items.length" class="page-subbar">' +
                  '<span class="text-soft">共 {{ items.length }} 个供应商（账号区默认折叠）</span>' +
                  '<button type="button" class="btn btn--sm" @click="toggleAllProviders">' +
                    '{{ allProvidersExpanded ? "全部折叠" : "全部展开" }}' +
                  '</button>' +
                '</div>' +
                '<div v-for="p in items" :key="p.provider_key" class="provider-group">' +
                  '<aigw-provider-card :provider="p" :busy="busy" :readonly="!canWrite(p)" :can-login="true"' +
                    ' @toggle="toggle(p)" @edit="openEdit(p)" @remove="remove(p)" @test="test(p)"' +
                    ' @models="goModels" @login="openLogin(p)" />' +
                  '<div class="provider-group__body" v-show="!isProviderCollapsed(p.provider_key)">' +
                    '<div class="provider-group__bar">' +
                      '<button type="button" class="grouphead__toggle" @click="toggleProviderCollapse(p.provider_key)"' +
                              ' :aria-expanded="isProviderCollapsed(p.provider_key) ? \'false\' : \'true\'"' +
                              ' :title="isProviderCollapsed(p.provider_key) ? \'展开账号\' : \'折叠账号\'">' +
                        '<aigw-icon :name="isProviderCollapsed(p.provider_key) ? \'chevronDown\' : \'chevronUp\'" :size="16" />' +
                      '</button>' +
                      '<span class="provider-group__label">账号</span>' +
                      '<aigw-badge tone="muted">{{ accountsFor(p).length }}</aigw-badge>' +
                      '<span class="provider-group__credits" :title="\'该供应商下 \' + providerCreditsMeta(p.provider_key).known + \' 个账号的积分合计\' + (providerCreditsMeta(p.provider_key).unknown ? \'（另有 \' + providerCreditsMeta(p.provider_key).unknown + \' 个账号积分未知，未计入）\' : \'\')">' +
                        '<aigw-badge tone="clay" v-if="providerCreditsMeta(p.provider_key).known">' +
                          '总积分 {{ providerCreditsLabel(p.provider_key) }}' +
                        '</aigw-badge>' +
                        '<aigw-badge tone="muted" v-else>总积分 未知</aigw-badge>' +
                      '</span>' +
                    '</div>' +
                    '<div v-if="accountsFor(p).length" class="grid grid--cards">' +
                      '<aigw-account-card v-for="a in accountsFor(p)" :key="a.id" :account="a" :busy="busy"' +
                        ' @toggle="toggleAccount(a)" @refresh="refreshAccount(a)" @remove="removeAccount(a)"' +
                        ' @detail="openDetail(a)" @models="openModels(a)" @cooldown="clearCooldown(a)"' +
                        ' @action="runAccountAction(a, $event)" />' +
                    '</div>' +
                    '<div v-else class="provider-group__empty">' +
                      '暂无账号 · 点击上方「添加账号」把你的账号接入该供应商' +
                    '</div>' +
                  '</div>' +
                  /* 折叠态：仍给出「账号 N 个 · 总积分 X」一行摘要，否则收起后什么都看不到 */
                  '<div v-show="isProviderCollapsed(p.provider_key)" class="provider-group__bar provider-group__bar--collapsed">' +
                    '<span class="provider-group__label">账号 {{ accountsFor(p).length }} 个</span>' +
                    '<span class="provider-group__credits">' +
                      '<aigw-badge tone="clay" v-if="providerCreditsMeta(p.provider_key).known">' +
                        '总积分 {{ providerCreditsLabel(p.provider_key) }}' +
                      '</aigw-badge>' +
                      '<aigw-badge tone="muted" v-else>总积分 未知</aigw-badge>' +
                    '</span>' +
                  '</div>' +
                '</div>' +
              '</div>' +

              /* ── 新建自定义供应商 ── */
              '<aigw-modal v-if="showCreate" title="新建自定义供应商" wide :busy="saving"' +
                           ' confirm-text="创建" @close="showCreate = false" @confirm="submitCreate">' +
                '<div class="note note--info" style="margin-bottom:16px">' +
                  '任何兼容 OpenAI 协议的中转或自建服务均可接入。填好 Base URL 与 API Key，保存后即可直接调用。' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">供应商名称 *</label>' +
                  '<input class="input" v-model="form.display_name" placeholder="例如：OpenAI 代理">' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">标识 Key（可选）</label>' +
                  '<input class="input mono" v-model="form.provider_key" placeholder="myapi">' +
                  '<div class="field__hint">留空则按名称自动生成。管理员为 custom_xxx；普通用户会带上用户号前缀（custom_u12_xxx）以保证全局唯一</div>' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">调用前缀（可选）</label>' +
                  '<input class="input mono" v-model="form.alias" placeholder="myapi">' +
                  '<div class="field__hint">模型在 /v1/models 里显示为「前缀/模型名」，留空则用上面的标识 Key。仅限小写字母、数字、_ 和 -，不能与其他供应商或前缀重复</div>' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">Base URL</label>' +
                  '<input class="input mono" v-model="form.base_url" placeholder="https://api.example.com/v1">' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">API Key</label>' +
                  '<textarea class="textarea" v-model="form.api_keys" placeholder="sk-xxxx（一行一个，可填多个）"></textarea>' +
                  '<div class="field__hint">填写后会自动创建对应账号，实现「保存即可跑」</div>' +
                '</div>' +
                '<div class="field mb-0">' +
                  '<label class="field__label">模型清单</label>' +
                  '<textarea class="textarea" v-model="form.models"' +
                            ' placeholder="gpt-4o&#10;gpt-4o-mini&#10;claude-3-5-sonnet"></textarea>' +
                  '<div class="field__hint">一行一个；留空则按请求里的 model 直传上游。模型倍率可在「模型目录」里同步或逐个设置</div>' +
                '</div>' +
              '</aigw-modal>' +

              /* ── 编辑供应商 ── */
              '<aigw-modal v-if="showEdit && editing" :title="\'配置 · \' + editing.display_name" wide' +
                           ' :busy="saving" confirm-text="保存" @close="showEdit = false" @confirm="submitEdit">' +
                '<div class="field">' +
                  '<label class="field__label">显示名称</label>' +
                  '<input class="input" v-model="editForm.display_name">' +
                '</div>' +
                '<template v-if="editing.kind === \'custom\'">' +
                  '<div class="field">' +
                    '<label class="field__label">调用前缀（可选）</label>' +
                    '<input class="input mono" v-model="editForm.alias" placeholder="留空 = 使用标识 Key">' +
                    '<div class="field__hint">' +
                      '模型对外显示为「前缀/模型名」。清空可回落到标识 Key ' +
                      '（{{ editing.provider_key }}）。改了前缀后，用旧名字调用仍然可用。' +
                    '</div>' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">Base URL</label>' +
                    '<input class="input mono" v-model="editForm.base_url">' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">更新 API Key</label>' +
                    '<textarea class="textarea" v-model="editForm.api_keys" placeholder="留空表示不修改"></textarea>' +
                    '<div class="field__hint">' +
                      '当前已配置 {{ (editing.options && editing.options.api_key_count) || 0 }} 个 Key（脱敏）。填写新值会整体替换。' +
                    '</div>' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">模型清单</label>' +
                    '<textarea class="textarea" v-model="editForm.models"></textarea>' +
                  '</div>' +
                '</template>' +
                '<div class="field mb-0">' +
                  '<label class="field__label">默认模型</label>' +
                  '<input class="input mono" v-model="editForm.default_model" placeholder="请求未指定 model 时使用">' +
                '</div>' +
              '</aigw-modal>' +

              /* ── 添加账号 ── */
              '<aigw-modal v-if="showLogin && loginProvider"' +
                           ' :title="\'添加账号 · \' + loginProvider.display_name" wide :busy="saving"' +
                           ' :hide-foot="loginType !== \'API_KEY\'" confirm-text="保存账号"' +
                           ' @close="closeLogin" @confirm="startLogin">' +

                '<template v-if="loginType === \'API_KEY\'">' +
                  '<div class="field">' +
                    '<label class="field__label">Base URL</label>' +
                    '<input class="input mono" v-model="loginForm.base_url" placeholder="https://api.example.com/v1">' +
                  '</div>' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">API Key</label>' +
                    '<input class="input mono" v-model="loginForm.api_key" placeholder="sk-xxxx">' +
                  '</div>' +
                '</template>' +

                '<template v-else-if="loginType === \'SMS_CODE\'">' +
                  '<div class="note note--info" style="margin-bottom:16px">' +
                    '输入手机号获取短信验证码即可登录（服务端已完成讯飞 HMAC 签名）。' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">手机号</label>' +
                    '<div class="field__row">' +
                      '<input class="input" v-model="loginForm.phone" placeholder="请输入手机号">' +
                      '<button type="button" class="btn btn--sm" :disabled="saving" @click="sendSmsCode">' +
                        '{{ codeSent ? "重新获取" : "获取验证码" }}' +
                      '</button>' +
                    '</div>' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">验证码</label>' +
                    '<input class="input" v-model="loginForm.code" placeholder="收到的短信验证码">' +
                  '</div>' +
                  '<button type="button" class="btn btn--sm mt-8" :disabled="saving" @click="verifySmsCode">' +
                    '用验证码登录' +
                  '</button>' +
                  '<details class="mt-16">' +
                    '<summary class="text-faint" style="cursor:pointer;font-size:13px">' +
                      '可选：从桌面客户端导入 session' +
                    '</summary>' +
                    '<div class="mt-8">' +
                      '<div class="field">' +
                        '<label class="field__label">取出 session</label>' +
                        '<div class="field__hint">{{ tokenSourceHint }}</div>' +
                      '</div>' +
                      '<div class="field mb-0">' +
                        '<label class="field__label">粘贴 session</label>' +
                        '<textarea class="textarea" v-model="loginForm.access_token"' +
                                  ' placeholder="粘贴桌面客户端里的 session"></textarea>' +
                        '<div class="field__hint">保存时会自动向 Loomy 校验登录态有效性，无效会给出具体原因</div>' +
                      '</div>' +
                    '</div>' +
                  '</details>' +
                '</template>' +

                '<template v-else>' +
                  /* WorkBuddy：设备码授权（取 state → 开授权页 → 自动轮询换令牌） */
                  '<div v-if="loginType === \'DEVICE_CODE\'">' +
                    '<div class="field">' +
                      '<label class="field__label">① 获取授权链接</label>' +
                      '<button type="button" class="btn btn--sm" :disabled="saving" @click="startLogin">' +
                        '生成授权链接' +
                      '</button>' +
                    '</div>' +
                    '<div v-if="loginSession && loginSession.authUrl" class="field">' +
                      '<label class="field__label">② 在浏览器完成登录</label>' +
                      '<div class="field__row">' +
                        '<input class="input mono" :value="loginSession.authUrl" readonly>' +
                        '<button type="button" class="btn btn--sm"' +
                                ' @click="openAuthUrl(loginSession.authUrl)">打开</button>' +
                      '</div>' +
                      '<div class="field__hint">完成登录后本页会自动检测并入库，无需手动复制 Token</div>' +
                    '</div>' +
                    '<div v-if="loginSession" class="field">' +
                      '<label class="field__label">③ 完成登录后，点击检测</label>' +
                      '<button type="button" class="btn btn--sm" :disabled="saving" @click="checkLoginNow">' +
                        '立即检测授权状态' +
                      '</button>' +
                      '<div class="field__hint">设备码授权没有回调链接：请在浏览器用同一账号完成登录，然后回到本页点击此按钮即可入库（本页也会每 3 秒自动检测一次）</div>' +
                    '</div>' +
                    '<div class="field mb-0" style="border-top:1px solid var(--line);padding-top:16px;margin-top:16px">' +
                      '<label class="field__label">或：粘贴登录 JSON 导入</label>' +
                      '<div class="field__hint">从桌面客户端登录态文件整段复制（形如 {"account":{...},"auth":{"accessToken":"..."}}，' +
                        '字段名需含 accessToken / refreshToken / domain）粘贴到下方，导入时会向上游校验登录态是否仍有效。</div>' +
                      '<textarea class="textarea mono mt-8" v-model="loginForm.login_json"' +
                                ' rows="6" placeholder=\'{"account":{"uid":"...","nickname":"..."},"auth":{"accessToken":"eyJ...","refreshToken":"eyJ...","domain":"www.codebuddy.cn"}}\'></textarea>' +
                      '<button type="button" class="btn btn--primary w-full mt-8" :disabled="saving"' +
                              ' @click="saveLoginJson">' +
                        '{{ saving ? "导入中…" : "校验并导入登录 JSON" }}' +
                      '</button>' +
                    '</div>' +
                  '</div>' +

                  /* 豆包：凭据是网页 Cookie（含 sessionid/msToken），不是 accessToken */
                  '<div v-else-if="loginProvider && loginProvider.provider_key === \'doubao\'">' +
                    '<div class="note note--info" style="margin-bottom:16px">' +
                      '豆包不使用 accessToken，凭据是<b>网页版登录 Cookie</b>（至少含 sessionid、msToken）。' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">① 取出 Cookie</label>' +
                      '<div class="field__hint">{{ tokenSourceHint }}</div>' +
                      '<button type="button" class="btn btn--sm mt-8" @click="openProviderSite">' +
                        '打开豆包官网 →' +
                      '</button>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">② 粘贴 Cookie</label>' +
                      '<textarea class="textarea" v-model="loginForm.cookie"' +
                                ' placeholder="sessionid=xxx; msToken=yyy; ...（可粘贴整条 Cookie）"></textarea>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">msToken（可选，Cookie 里没有时单独粘贴）</label>' +
                      '<input class="input mono" v-model="loginForm.ms_token" placeholder="msToken 的值">' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">device_id（可选）</label>' +
                      '<input class="input mono" v-model="loginForm.device_id" placeholder="用于查询串 device_id / tea_uuid">' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">web_id（可选）</label>' +
                      '<input class="input mono" v-model="loginForm.web_id" placeholder="用于查询串 web_id">' +
                    '</div>' +
                  '</div>' +

                  /* 商汤小浣熊：授权页 → 自定义协议回调 / 手机回环回调 → 粘贴链接换码 */
                  '<div v-else-if="loginProvider && loginProvider.provider_key === \'xiaohuanxiong\'">' +
                    '<div class="note note--info" style="margin-bottom:12px">' +
                      '小浣熊凭据是一对 access_token / refresh_token，需通过网页授权换取。' +
                    '</div>' +
                    '<div class="note note--warn note--sm" style="margin-bottom:16px">' +
                      'iPhone / iPad 上的 Safari、Chrome、微信内置浏览器内核都是 WebKit：' +
                      '电脑版的 <b>office-raccoon://</b> 自定义协议回调在手机上只会弹「网址无效」，' +
                      '而且地址栏不保留链接，拿不到 code。' +
                      '手机上请用下面的「手机版授权」，它会换成 <b>http://127.0.0.1</b> 回环回调，' +
                      '地址栏会保留完整链接、可长按复制。' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">① 发起授权</label>' +
                      '<div class="field__row">' +
                        '<button type="button" class="btn btn--sm" :disabled="saving" @click="startLogin">' +
                          '电脑版授权' +
                        '</button>' +
                        '<button type="button" class="btn btn--sm btn--primary" :disabled="saving"' +
                                ' @click="startMobileLogin">' +
                          '手机版授权' +
                        '</button>' +
                      '</div>' +
                      '<div class="field__hint">电脑版回调是 office-raccoon://（需在电脑操作）；' +
                        '手机版回调是 http://127.0.0.1:18080/…（手机可直接复制）</div>' +
                    '</div>' +
                    '<div v-if="loginSession && loginSession.authUrl" class="field">' +
                      '<label class="field__label">授权链接</label>' +
                      '<div class="field__row">' +
                        '<input class="input mono" :value="loginSession.authUrl" readonly>' +
                        '<button type="button" class="btn btn--sm"' +
                                ' @click="copyText(loginSession.authUrl, \'授权链接\')">复制</button>' +
                        '<button type="button" class="btn btn--sm"' +
                                ' @click="openAuthUrl(loginSession.authUrl)">打开</button>' +
                      '</div>' +
                      '<div class="field__hint">用小浣熊账号登录并确认授权</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">② 粘贴回调地址</label>' +
                      '<div class="field__row">' +
                        '<input class="input mono" v-model="loginForm.callback_url"' +
                               ' placeholder="office-raccoon://auth/callback?code=… 或 http://127.0.0.1:18080/…?authorization_code=…">' +
                        '<button type="button" class="btn btn--sm" :disabled="saving" @click="submitCallbackUrl">提交</button>' +
                      '</div>' +
                      '<div class="field__hint">授权成功后页面会跳到回调链接（打不开是正常的）；' +
                        '把地址栏里那条完整链接复制粘贴到这里提交。' +
                        '手机版回环链接在手机上会提示「无法连接」，但地址栏里那条 http://127.0.0.1:18080/… 链接是完整的，长按即可复制</div>' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">方式二：手工填 Token（兜底）</label>' +
                      '<div class="field__hint">在浏览器登录 xiaohuanxiong.com → F12 → Network，' +
                        '任选一个 api/web 请求，复制请求头里的 Authorization: Bearer eyJ… 中的整串 Token 粘贴到下方；' +
                        '若已安装官方桌面端，也可直接读 ~/.box-agent/config/auth.json 里的 access_token / refresh_token</div>' +
                      '<input class="input mono mt-8" v-model="loginForm.access_token"' +
                             ' placeholder="access_token（eyJ 开头的 JWT）">' +
                      '<input class="input mono mt-8" v-model="loginForm.refresh_token"' +
                             ' placeholder="refresh_token（可选，填写后可自动续期）">' +
                      '<button type="button" class="btn btn--primary w-full mt-8" :disabled="saving"' +
                              ' @click="saveXiaohuanxiongCredential">' +
                        '{{ saving ? "保存中…" : "保存 Token" }}' +
                      '</button>' +
                    '</div>' +
                  '</div>' +

                  /* Trae / Antigravity：OAuth 为主路径，手工填 Token 作为可选兜底 */
                  '<div v-else>' +
                  '<details open class="mt-16">' +
                    '<summary style="cursor:pointer;font-size:13px;font-weight:600">' +
                      '方式一：走内置 OAuth 授权（推荐）' +
                    '</summary>' +
                    '<div class="mt-8">' +
                      '<div v-if="loginProvider && loginProvider.provider_key === \'trae_intl\'"' +
                           ' class="note note--warn note--sm" style="margin-bottom:12px">' +
                        '国际版 TraeWork（trae.ai）需要<b>受支持地区的网络</b>才能完成登录授权。' +
                        '若打开授权链接后提示 “TraeWork Unavailable / not available in your region”，' +
                        '说明当前网络地区受限，请更换网络后再试。' +
                      '</div>' +
                      '<div v-if="loginSession && loginSession.authUrl" class="field">' +
                        '<label class="field__label">授权链接</label>' +
                        '<div class="field__row">' +
                          '<input class="input mono" :value="loginSession.authUrl" readonly>' +
                          '<button type="button" class="btn btn--sm"' +
                                  ' @click="openAuthUrl(loginSession.authUrl)">打开</button>' +
                        '</div>' +
                        '<div class="field__hint">在新标签页完成登录后，本页会自动检测并入库</div>' +
                      '</div>' +
                      '<div v-if="loginSession && loginSession.userCode" class="field">' +
                        '<label class="field__label">设备码</label>' +
                        '<input class="input mono" :value="loginSession.userCode" readonly>' +
                      '</div>' +
                      '<button type="button" class="btn btn--sm" :disabled="saving" @click="startLogin">' +
                        '发起授权' +
                      '</button>' +
                      '<div class="field mt-16">' +
                        '<label class="field__label">或粘贴回调地址</label>' +
                        '<div class="field__row">' +
                          '<input class="input mono" v-model="loginForm.callback_url"' +
                                 ' placeholder="浏览器地址栏里的完整回调链接">' +
                          '<button type="button" class="btn btn--sm" :disabled="saving" @click="submitCallbackUrl">提交</button>' +
                        '</div>' +
                        '<div class="field__hint">若回调页打不开，或浏览器与网关不在同一台机器（远程 / 另一台电脑，回环地址 127.0.0.1 无法直达网关），请复制浏览器地址栏里的完整回调链接，回到管理台用「粘贴回调地址」提交即可完成入库</div>' +
                      '</div>' +
                    '</div>' +
                  '</details>' +
                    '<details class="mt-16">' +
                    '<summary style="cursor:pointer;font-size:13px;font-weight:600">' +
                      '方式二：手工填 Token（可选，兜底）' +
                    '</summary>' +
                    '<div class="mt-8">' +
                    '<div class="field">' +
                      '<label class="field__label">① 取出 Access Token</label>' +
                      '<div class="field__hint">{{ tokenSourceHint }}</div>' +
                      '<button type="button" class="btn btn--sm mt-8" @click="openProviderSite">' +
                        '打开供应商官网 →' +
                      '</button>' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">② 粘贴 Access Token</label>' +
                      '<textarea class="textarea" v-model="loginForm.access_token"' +
                                ' placeholder="粘贴上一步复制的 Token"></textarea>' +
                      '<div class="field__hint">保存时会自动向供应商校验 Token 有效性，无效会给出具体原因</div>' +
                    '</div>' +
                    '<button type="button" class="btn btn--primary w-full mt-8" :disabled="saving"' +
                            ' @click="saveTokenDirect">' +
                      '{{ saving ? "校验中…" : "校验并保存 Token" }}' +
                    '</button>' +
                    '</div>' +
                    '</details>' +
                  '</div>' +

                '</template>' +

                '<div v-if="loginMessage" class="note note--info mt-16">{{ loginMessage }}</div>' +
                '<div v-if="loginPolling" class="text-faint mt-8" style="font-size:12px">' +
                  '正在等待授权完成…（每 3 秒自动检查一次）' +
                '</div>' +

                '<div v-if="loginType !== \'API_KEY\' && loginType !== \'DEVICE_CODE\' && !(loginProvider && loginProvider.provider_key === \'xiaohuanxiong\')" class="mt-16">' +
                  '<button type="button" class="btn btn--primary w-full"' +
                          ' :disabled="saving"' +
                          ' @click="(loginProvider && loginProvider.provider_key === \'doubao\') ? saveDoubaoCredential() : saveTokenDirect()">' +
                    '{{ saving ? "校验中…" : (loginProvider && loginProvider.provider_key === "doubao" ? "校验并保存豆包凭据" : (loginType === "SMS_CODE" ? "校验并保存 session" : "校验并保存 Token")) }}' +
                  '</button>' +
                '</div>' +
              '</aigw-modal>' +

              /* ── 账号详情 ── */
              '<aigw-modal v-if="showDetail && detail" :title="\'账号详情 · \' + detail.nickname" wide' +
                           ' hide-foot @close="showDetail = false">' +
                '<div class="grid grid--2">' +
                  '<div class="field"><label class="field__label">供应商</label>' +
                    '<input class="input" :value="detail.provider_name" readonly></div>' +
                  '<div v-if="detail.region" class="field"><label class="field__label">区域</label>' +
                    '<input class="input" :value="detail.region.label + \'（\' + detail.region.domain + \'）\'" readonly></div>' +
                  '<div class="field"><label class="field__label">账号 ID</label>' +
                    '<input class="input mono" :value="detail.account_uid" readonly></div>' +
                  '<div class="field"><label class="field__label">状态</label>' +
                    '<input class="input" :value="(detail.state || {}).label" readonly></div>' +
                  '<div class="field"><label class="field__label">额度</label>' +
                    '<input class="input" :value="detail.credits_known ? fmt.num(detail.credits, 2) : \'未知\'" readonly></div>' +
                  '<div class="field"><label class="field__label">错误次数</label>' +
                    '<input class="input" :value="detail.error_count" readonly></div>' +
                  '<div class="field"><label class="field__label">最近刷新</label>' +
                    '<input class="input" :value="fmt.time(detail.last_refresh_at)" readonly></div>' +
                  '<div class="field"><label class="field__label">启用状态</label>' +
                    '<input class="input" :value="detail.enabled === false ? \'已停用\' : \'已启用\'" readonly></div>' +
                  '<div class="field"><label class="field__label">冷却剩余</label>' +
                    '<input class="input" :value="coolingLabel(detail)" readonly></div>' +
                  '<div class="field"><label class="field__label">状态原因</label>' +
                    '<input class="input" :value="detail.reason || \'—\'" readonly></div>' +
                '</div>' +
                '<div v-if="detail.metered" class="mt-16">' +
                  '<label class="field__label">网关实测消耗（本网关自计量）</label>' +
                  '<div class="grid grid--stats">' +
                    '<div class="stat stat--primary"><div class="stat__top"><div class="stat__label">今日消耗 Token</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.metered.today_tokens, 0) }}</div></div>' +
                    '<div class="stat stat--celadon"><div class="stat__top"><div class="stat__label">累计消耗 Token</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.metered.total_tokens, 0) }}</div></div>' +
                    '<div class="stat stat--amber"><div class="stat__top"><div class="stat__label">累计调用</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.metered.calls, 0) }}</div></div>' +
                    '<div class="stat"><div class="stat__top"><div class="stat__label">成功</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.metered.ok, 0) }}</div></div>' +
                  '</div>' +
                  '<div class="note note--sm mt-8">' +
                    '今日调用 {{ fmt.num(detail.metered.today_calls, 0) }} 次 · ' +
                    '输入 {{ fmt.num(detail.metered.prompt_tokens, 0) }} · ' +
                    '输出 {{ fmt.num(detail.metered.completion_tokens, 0) }} · ' +
                    '思维 {{ fmt.num(detail.metered.reasoning_tokens, 0) }} · ' +
                    '缓存读 {{ fmt.num(detail.metered.cache_read_tokens, 0) }}' +
                    '<span v-if="detail.metered.last_at"> · 最近 {{ detail.metered.last_at }}</span>' +
                  '</div>' +
                  '<div class="note note--sm">{{ detail.metered.note }}</div>' +
                '</div>' +
                '<div v-if="(detail.meta || {}).usage" class="mt-16">' +
                  '<label class="field__label">上游订阅额度（billing/balance 口径）</label>' +
                  '<div class="grid grid--stats">' +
                    '<div class="stat stat--primary"><div class="stat__top"><div class="stat__label">总额</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.meta.usage.total, 2) }}</div></div>' +
                    '<div class="stat stat--amber"><div class="stat__top"><div class="stat__label">已用</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.meta.usage.used, 2) }}</div></div>' +
                    '<div class="stat stat--celadon"><div class="stat__top"><div class="stat__label">剩余</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.meta.usage.remain, 2) }}</div></div>' +
                  '</div>' +
                  '<div class="note note--sm">⚠️ 该数值由上游账单接口下发，部分供应商（如 ZCode）的对话走<b>独立计费池</b>，此桶不会扣减 —— 真实消耗以上方「网关实测」为准。</div>' +
                '</div>' +
                '<div v-if="((detail.meta || {}).packs || []).length" class="mt-16">' +
                  '<label class="field__label">额度包详情（共 {{ detail.meta.packs.length }} 个）</label>' +
                  '<div class="table__wrap"><table class="table">' +
                    '<thead><tr><th>名称</th><th>分组</th><th>总额</th><th>已用</th><th>剩余</th></tr></thead>' +
                    '<tbody>' +
                      '<tr v-for="(p, i) in detail.meta.packs" :key="i">' +
                        '<td>{{ p.name || "—" }}</td>' +
                        '<td class="text-soft">{{ p.group || "—" }}</td>' +
                        '<td>{{ fmt.num(p.limit, 2) }}</td>' +
                        '<td>{{ fmt.num(p.used, 2) }}</td>' +
                        '<td>{{ fmt.num(p.remain, 2) }}</td>' +
                      '</tr>' +
                    '</tbody>' +
                  '</table></div>' +
                '</div>' +
                '<div v-if="detail.last_error" class="note note--warn note--sm mt-8">最近错误：{{ detail.last_error }}</div>' +
                '<div class="field mt-16">' +
                  '<label class="field__label">凭据预览（已脱敏）</label>' +
                  '<textarea class="textarea" readonly rows="5" :value="previewText(detail.credential_preview)"></textarea>' +
                '</div>' +
                '<div v-if="detail.credits_detail" class="field mb-0">' +
                  '<label class="field__label">额度明细</label>' +
                  '<textarea class="textarea" readonly rows="2" :value="detail.credits_detail"></textarea>' +
                '</div>' +
              '</aigw-modal>' +

              /* ── 可用模型 ── */
              '<aigw-modal v-if="showModels" :title="\'可用模型 · \' + modelTitle" wide hide-foot' +
                           ' @close="showModels = false">' +
                '<div v-if="modelList.length" class="grid grid--2">' +
                  '<div v-for="(m, i) in modelList" :key="i" class="modelrow">' +
                    '<div class="modelrow__main">' +
                      '<div class="modelrow__id truncate">{{ modelLabel(m) }}</div>' +
                      '<div v-if="modelSub(m)" class="modelrow__name truncate">{{ modelSub(m) }}</div>' +
                    '</div>' +
                  '</div>' +
                '</div>' +
                '<aigw-empty v-else icon="models" title="该账号未返回模型列表" />' +
              '</aigw-modal>' +

              /* ── 额度弹窗（额度快照免验证码；领取为折叠项） ── */
              '<aigw-modal v-if="showClaim" :title="\'额度与领取 · \' + ((claimAccount && claimAccount.nickname) || \'\')" wide' +
                           ' :busy="claimBusy" :hide-foot="!claimPlans.length" confirm-text="一键领取"' +
                           ' @close="showClaim = false" @confirm="submitClaim">' +

                /* ① 额度快照：只读，不需要验证码 —— 这是本弹窗的主内容 */
                '<div v-if="claimQuota" class="note note--info" style="margin-bottom:12px">' +
                  '<b>当前额度</b>（只读查询，<b>无需验证码</b>）' +
                  '<div style="margin-top:6px">' +
                    '剩余 <b>{{ fmt.num(claimQuota.total_remain, 0) }}</b> / {{ fmt.num(claimQuota.total_limit, 0) }} token' +
                    '<span v-if="claimQuota.expire_at" class="text-soft"> · 每日重置</span>' +
                  '</div>' +
                  '<div style="margin-top:8px" v-if="claimQuota.packages && claimQuota.packages.length">' +
                    '<div v-for="q in claimQuota.packages" :key="q.name" style="margin-top:4px">' +
                      '<aigw-badge tone="success" dot>{{ q.name }}</aigw-badge>' +
                      ' <span class="mono">{{ fmt.num(q.remain, 0) }}</span>' +
                      ' <span class="text-soft">/ {{ fmt.num(q.limit, 0) }}</span>' +
                    '</div>' +
                  '</div>' +
                  '<div style="margin-top:8px">' +
                    '对话通道（免验证码）：' +
                    '<aigw-badge :tone="claimQuota.api_key_ready ? \'success\' : \'warning\'" dot>' +
                      '{{ claimQuota.api_key_ready ? \'已就绪\' : \'未就绪\' }}' +
                    '</aigw-badge>' +
                  '</div>' +
                '</div>' +
                '<div v-else-if="claimBusy" class="text-soft" style="padding:8px 0">正在读取额度…</div>' +

                /* ② 可领取额度 */
                '<div class="field"><label class="field__label">可领取 / 已生效额度</label>' +
                  '<div v-if="claimPlans.length">' +
                    '<label v-for="p in claimPlans" :key="p.plan_id" class="modelrow" style="margin-bottom:8px;cursor:pointer">' +
                      '<div class="modelrow__main">' +
                        '<div class="modelrow__id"><input type="radio" :value="p.plan_id" v-model="claimForm.plan_id"> ' +
                          '{{ p.name || p.plan_id }}</div>' +
                        '<div class="modelrow__name truncate text-soft">' +
                          '{{ p.plan_id }}{{ p.description ? \' · \' + p.description : \'\' }}' +
                        '</div>' +
                        '<div v-if="p.entitlements && p.entitlements.length" class="text-soft" style="margin-top:4px">' +
                          '<span v-for="e in p.entitlements" :key="e.show_name" style="margin-right:10px">' +
                            '{{ e.show_name }} <span class="mono">{{ fmt.num(e.remain_units != null ? e.remain_units : e.grant_units, 0) }}</span>' +
                            '<span v-if="e.period" class="text-soft">/{{ e.period === \'daily\' ? \'日\' : e.period }}</span>' +
                          '</span>' +
                        '</div>' +
                      '</div>' +
                      '<div class="modelrow__side">' +
                        '<aigw-badge :tone="p.claimable ? \'success\' : \'muted\'" dot>' +
                          '{{ p.claimable ? \'可领取\' : (p.status || \'不可领\') }}' +
                        '</aigw-badge>' +
                      '</div>' +
                    '</label>' +
                  '</div>' +
                  '<aigw-empty v-else-if="!claimBusy" icon="moon" title="当前没有可领取或已生效的额度包"' +
                              ' desc="本项为空表示该账号暂时没有可领额度" />' +
                '</div>' +

                /* ③ 一键领取说明：验证码由服务端自动无痕求解 */
                '<div class="note note--info" style="margin-bottom:12px" v-if="claimPlans.length">' +
                  '<b>无需输入验证码</b>：点下方「一键领取」即可。服务端会在本机拉起无头浏览器，' +
                  '走阿里云<b>无痕验证</b>（<span class="mono">startTracelessVerification()</span>）' +
                  '自动取得一次性凭证并提交，全程<b>零人工交互</b>，约需 10 秒。' +
                '</div>' +

                /* 兜底：自动求解失败时可手动粘验证码 */
                '<div class="field" v-if="claimPlans.length">' +
                  '<button type="button" class="btn btn--sm" @click="showClaimForm = !showClaimForm">' +
                    '{{ showClaimForm ? \'收起手动兜底\' : \'自动领取失败？手动粘贴验证码\' }}' +
                  '</button>' +
                '</div>' +
                '<template v-if="showClaimForm && claimPlans.length">' +
                  '<div class="note" style="margin-bottom:12px">' +
                    '仅当自动无痕验证被风控降级（弹出滑块）时才需要手动：' +
                    '<ol style="margin:6px 0 0 18px;padding:0;line-height:1.8">' +
                      '<li>点击下方按钮，在新标签页用<b>同一账号</b>打开领取页。</li>' +
                      '<li>在页面完成阿里云人机验证。</li>' +
                      '<li>按 <span class="mono">F12</span> → <b>Network</b>，筛选 <span class="mono">billing/claim</span>。</li>' +
                      '<li>复制请求头 <span class="mono">X-Aliyun-Captcha-Verify-Param</span> 的值，粘贴到下方后点「一键领取」。</li>' +
                    '</ol>' +
                  '</div>' +
                  '<div class="field">' +
                    '<button type="button" class="btn btn--sm" @click="openClaimSite">打开 zcode 领取页（新标签页）</button>' +
                  '</div>' +
                  '<div class="field mb-0"><label class="field__label">阿里云验证码（留空 = 自动求解）</label>' +
                    '<input class="input mono" v-model="claimForm.captcha" placeholder="留空即可自动过验证码；如需手动则粘贴 X-Aliyun-Captcha-Verify-Param 的值">' +
                    '<div class="field__hint">填了就以你粘贴的为准；验证码一次性且有 90 秒时效，请尽快提交。</div>' +
                  '</div>' +
                '</template>' +

                '<div v-if="claimProgress" class="note note--info" style="margin-top:12px">' +
                  '<b>后台领取中</b>：{{ claimProgress }}' +
                  '<div class="text-soft" style="margin-top:4px">领取在服务端后台执行（需驱动浏览器过无痕验证），' +
                  '不受页面 / CDN 超时影响，请保持页面打开。</div>' +
                '</div>' +

                '<div v-if="claimResult" class="note" style="margin-top:12px"' +
                     ' :class="claimResult.ok ? \'note--info\' : \'note--warn\'">' +
                  '<b>{{ claimResult.ok ? (claimOutcomeLabel(claimResult.outcome) || \'完成\') : \'领取失败\' }}</b>' +
                  '<span v-if="claimResult.outcome" class="text-soft">（{{ claimResult.outcome }}）</span>' +
                  '：{{ claimResult.message }}' +
                '</div>' +
              '</aigw-modal>' +
            '</div>'
    };

    /* ============================================================
       账号池页
       ============================================================ */
    pages.PageAccounts = {
        data: function () {
            return {
                loading: true, busy: false, error: '',
                items: [], summary: {}, providers: [],
                pool: { total: 0, providers: [] },
                // 按供应商分组的折叠状态：展开集合（**默认全部折叠**，只记「已展开」的 key）
                // 不落 localStorage —— 需求是「默认折叠」，记住状态反而会让下次进来不是默认态
                expanded: {},
                filters: { provider: '', keyword: '', status: '', user_id: '' },
                statusOptions: [
                    { value: '', label: '全部状态' },
                    { value: 'READY', label: '可用' },
                    { value: 'COOLING', label: '冷却中' },
                    { value: 'ERROR', label: '错误' },
                    { value: 'SESSION_DEAD', label: '登录失效' },
                    { value: 'DISABLED', label: '已停用' }
                ],
                showDetail: false, detail: null,
                showModels: false, modelList: [], modelTitle: '',
                showTravelLogs: false, travelLogs: [], travelSummary: {},
                showActivityLogs: false, activityLogs: [], activitySummary: {},
                showClaim: false, claimAccount: null, claimPlans: [], claimBusy: false,
                claimForm: { plan_id: '', captcha: '' }, claimResult: null, claimProgress: '',
                claimQuota: null, showClaimForm: false,
                showClaimLogs: false, claimLogs: [], claimSummary: {}
            };
        },
        computed: {
            isAdmin: function () { return S.state.role !== 'user'; },
            routeUser: function () {
                if (S.state.route !== 'accounts') return '';
                var p = S.state.params || {};
                return p.id ? String(p.id) : '';
            },
            summaryCards: function () {
                var s = this.summary || {};
                return [
                    { label: '账号总数', value: s.total !== undefined ? s.total : this.items.length, tone: 'primary', icon: 'accounts' },
                    { label: '可用', value: s.ready !== undefined ? s.ready : (s.active !== undefined ? s.active : '—'), tone: 'celadon', icon: 'check' },
                    { label: '冷却中', value: s.cooling !== undefined ? s.cooling : '—', tone: 'amber', icon: 'timer' },
                    { label: '异常 / 停用', value: s.problem !== undefined ? s.problem : (s.disabled !== undefined ? s.disabled : '—'), tone: 'rust', icon: 'warning' }
                ];
            },
            /**
             * 按供应商分组（组内即下方要渲染的账号卡）。
             *
             * ★ 只对**当前已渲染的 `items`** 分组，不用后端下发的 `by_provider`：
             *   后端那份建立在「过滤前」的账号集合上，一旦按状态/关键词筛选，
             *   组头数字就会和下方实际卡片对不上（静默漂移，血案 #31 同类）。
             *   在这里现算，组头的总数与总积分永远可逐张卡片验算。
             *
             * @returns {Array<{key,name,accounts,total,usable,cooling,disabled,error,creditsTotal,creditsKnown,creditsUnknown}>}
             */
            groups: function () {
                var order = [];
                var map = {};
                (this.items || []).forEach(function (a) {
                    var k = a.provider_key || '(未知)';
                    if (!map[k]) {
                        map[k] = {
                            key: k,
                            name: a.provider_name || k,
                            accounts: [],
                            total: 0, usable: 0, cooling: 0, disabled: 0, error: 0,
                            creditsTotal: 0, creditsKnown: 0, creditsUnknown: 0
                        };
                        order.push(k);
                    }
                    var g = map[k];
                    g.accounts.push(a);
                    g.total++;

                    var code = (a.state || {}).code || '';
                    if (code === 'READY') g.usable++;
                    else if (code === 'COOLING') g.cooling++;
                    else if (code === 'DISABLED' || code === 'SESSION_DEAD') g.disabled++;
                    else if (code === 'ERROR') g.error++;

                    // 积分口径：**只累加「已探知」的账号**，未知的单独计数。
                    // 若把未知当 0 混加，组头数字会静默偏小且无从解释。
                    if (a.credits_known && a.credits !== null && a.credits !== undefined) {
                        g.creditsTotal += Number(a.credits) || 0;
                        g.creditsKnown++;
                    } else {
                        g.creditsUnknown++;
                    }
                });
                return order.map(function (k) { return map[k]; });
            },
            allExpanded: function () {
                var gs = this.groups;
                if (!gs.length) return false;
                var self = this;
                return gs.every(function (g) { return !!self.expanded[g.key]; });
            }
        },
        watch: {
            routeUser: function (v) {
                var val = v || '';
                if (String(this.filters.user_id) === val) return;
                this.filters.user_id = val;
                this.load();
            }
        },
        mounted: function () {
            this.filters.user_id = this.routeUser || '';
            this.loadProviders();
            this.loadPool();
            this.load();
        },
        methods: {
            loadPool: function () {
                var self = this;
                api.scoped.poolOverview().then(function (res) {
                    if (!res.ok) return;
                    var d = res.data || {};
                    var map = d.providers || {};
                    var list = [];
                    Object.keys(map).forEach(function (k) {
                        var p = map[k] || {};
                        list.push({
                            key: k,
                            name: p.display_name || k,
                            loginType: p.login_type || '',
                            enabled: p.enabled !== false,
                            kind: p.kind || ''
                        });
                    });
                    var total = d.total;
                    if (total && typeof total === 'object') total = total.total;
                    self.pool = { total: total || list.length, providers: list };
                    S.state.poolOverview = self.pool;
                });
            },
            goProviderLogin: function (p) {
                if (!this.isAdmin) { this.filters.provider = p.key; this.load(); return; }
                S.state.pendingLoginProvider = p.key;
                S.navigate('providers');
            },
            loadProviders: function (done) {
                var self = this;
                api.scoped.providers().then(function (res) {
                    if (res.ok) self.providers = (res.data && res.data.items) || [];
                    if (typeof done === 'function') done();
                });
            },
            load: function () {
                var self = this;
                this.loading = true;
                api.scoped.accounts(this.filters).then(function (res) {
                    self.loading = false;
                    if (!res.ok) { self.error = res.error || '加载失败'; return; }
                    self.error = '';
                    var d = res.data || {};
                    self.items = d.items || [];
                    self.summary = d.summary || {};
                });
            },
            resetFilters: function () {
                this.filters = { provider: '', keyword: '', status: '', user_id: '' };
                this.expanded = {};
                this.load();
            },
            clearUserFilter: function () {
                this.filters.user_id = '';
                this.load();
            },

            /* ── 分组折叠（默认折叠；仅记「已展开」的 key） ───────────── */

            /**
             * 是否折叠。
             * ★ 有关键词时**强制展开** —— 用户是来「找账号」的，折叠着让他再点一次
             *   等于把搜索结果藏起来（与模型目录页同一约定）。
             */
            isCollapsed: function (key) {
                if (String(this.filters.keyword || '').trim() !== '') return false;
                return !this.expanded[key];
            },
            toggleCollapse: function (key) {
                var next = {};
                var self = this;
                Object.keys(this.expanded || {}).forEach(function (k) { next[k] = self.expanded[k]; });
                next[key] = !next[key];
                this.expanded = next;
            },
            /** 一键全部展开 / 全部折叠 */
            toggleAll: function () {
                if (this.allExpanded) { this.expanded = {}; return; }
                var next = {};
                this.groups.forEach(function (g) { next[g.key] = true; });
                this.expanded = next;
            },

            /** 组内已知积分合计（未探知的账号不混入 0，只计入「未知」个数） */
            creditsLabel: function (g) {
                if (!g) return '未知';
                if (g.creditsKnown === 0) return '未知';
                return fmt.num(g.creditsTotal, 2);
            },
            /** 组头副标题里的一句话摘要 */
            groupSummaryText: function (g) {
                if (!g) return '';
                var parts = [g.total + ' 个账号'];
                if (g.usable) parts.push('可用 ' + g.usable);
                if (g.cooling) parts.push('冷却 ' + g.cooling);
                if (g.error) parts.push('错误 ' + g.error);
                if (g.disabled) parts.push('停用 ' + g.disabled);
                if (g.creditsUnknown) parts.push('积分未知 ' + g.creditsUnknown);
                return parts.join(' · ');
            },
            refreshAll: function () {
                var self = this;
                this.busy = true;
                api.scoped.refreshAll(this.filters.provider).then(function (res) {
                    self.busy = false;
                    S.report(res);
                    self.load();
                });
            },
            travelAll: function () {
                var self = this;
                this.busy = true;
                api.scoped.travelAll(this.filters.provider).then(function (res) {
                    self.busy = false;
                    S.report(res);
                    self.load();
                    if (self.showTravelLogs) self.openTravelLogs();
                });
            },
            openTravelLogs: function () {
                var self = this;
                api.scoped.travelLogs({ provider: this.filters.provider || '', limit: 100 }).then(function (res) {
                    if (!res.ok) { S.report(res); return; }
                    var d = res.data || {};
                    self.travelLogs = d.items || [];
                    self.travelSummary = d.summary || {};
                    self.showTravelLogs = true;
                });
            },
            travelStateLabel: function (state) {
                return {
                    idle: '空闲', traveling: '在途', arrived: '已到站', adopted: '已领养', blocked: '门槛未达'
                }[state] || (state || '—');
            },
            travelStateTone: function (state) {
                return {
                    arrived: 'success', adopted: 'success', traveling: 'amber', idle: 'muted', blocked: 'warning'
                }[state] || 'muted';
            },
            activityAll: function () {
                var self = this;
                this.busy = true;
                api.scoped.activityAll(this.filters.provider).then(function (res) {
                    self.busy = false;
                    S.report(res);
                    self.load();
                    if (self.showActivityLogs) self.openActivityLogs();
                });
            },
            tasksAll: function () {
                var self = this;
                this.busy = true;
                api.scoped.tasksAll(this.filters.provider).then(function (res) {
                    self.busy = false;
                    S.report(res);
                    self.load();
                });
            },
            openActivityLogs: function () {
                var self = this;
                api.scoped.activityLogs({ provider: this.filters.provider || '', limit: 100 }).then(function (res) {
                    if (!res.ok) { S.report(res); return; }
                    var d = res.data || {};
                    self.activityLogs = d.items || [];
                    self.activitySummary = d.summary || {};
                    self.showActivityLogs = true;
                });
            },
            toggle: function (a) {
                var self = this;
                this.busy = true;
                api.scoped.toggleAccount(a.id).then(function (res) {
                    self.busy = false;
                    if (S.report(res)) self.load();
                });
            },
            refresh: function (a) {
                var self = this;
                this.busy = true;
                api.scoped.refreshAccount(a.id).then(function (res) {
                    self.busy = false;
                    S.report(res);
                    self.load();
                });
            },
            clearCooldown: function (a) {
                var self = this;
                this.busy = true;
                api.scoped.clearCooldown(a.id).then(function (res) {
                    self.busy = false;
                    if (S.report(res)) self.load();
                });
            },
            runAccountAction: function (a, payload) {
                if (payload.dialog === 'claim') { this.openClaim(a); return; }
                var self = this;
                // 参数名由动作自带（默认 code）；allowEmpty 允许「留空」作为有效值（如清除）。
                var params = {};
                if (payload.value !== '' && payload.value !== undefined && payload.value !== null) {
                    params[payload.param || 'code'] = payload.value;
                } else if (payload.allowEmpty) {
                    params[payload.param || 'code'] = '';
                }
                this.busy = true;
                api.scoped.accountAction(a.id, payload.action, params).then(function (res) {
                    self.busy = false;
                    S.report(res);
                    if (res.ok && payload.action !== 'checkin_status' && payload.action !== 'onboarding_tasks') {
                        self.load();
                    }
                });
            },
            openClaim: function (a) {
                var self = this;
                this.claimAccount = a;
                this.claimPlans = [];
                this.claimForm = { plan_id: '', captcha: '' };
                this.claimResult = null;
                this.claimQuota = null;
                this.showClaimForm = false;
                this.claimBusy = true;
                this.showClaim = true;
                api.scoped.accountAction(a.id, 'claim_preview', {}).then(function (res) {
                    self.claimBusy = false;
                    var data = (res && res.data) || {};
                    self.claimPlans = data.plans || [];
                    // 额度快照是 claim_preview 一起下发的（只读，不需要验证码）
                    self.claimQuota = (data.packages || data.total_remain != null) ? {
                        packages: data.packages || [],
                        total_remain: data.total_remain,
                        total_limit: data.total_limit,
                        expire_at: data.expire_at,
                        api_key_ready: !!data.api_key_ready
                    } : null;
                    var first = self.claimPlans.filter(function (p) { return p.claimable; })[0] || self.claimPlans[0];
                    if (first) self.claimForm.plan_id = first.plan_id;
                    // 没有可领项时不再显红字报错——空 = 「暂时没有新的可领」，属正常
                    if (!res.ok) self.claimResult = { ok: false, message: res.message || '额度查询失败' };
                });
            },
            submitClaim: function () {
                var self = this;
                if (!this.claimAccount) return;
                if (!this.claimForm.plan_id) { S.toast('请选择要领取的额度', 'warning'); return; }
                this.claimBusy = true;
                this.claimResult = null;
                this.claimProgress = '正在提交任务…';
                // 一键领取：验证码由服务端**自动无痕求解**（阿里云 2.0 V3 的
                // startTracelessVerification()，风控评分通过即零交互返回 param），
                // 所以这里默认不带 captcha。用户在兜底输入框里手动粘了值才带上。
                var payload = { plan_id: this.claimForm.plan_id };
                var manual = (this.claimForm.captcha || '').trim();
                if (manual) payload.captcha = manual;
                api.scoped.accountAction(this.claimAccount.id, 'claim', payload).then(function (res) {
                    var data = (res && res.data) || {};
                    // 服务端已把长耗时动作异步化：立即返回 task_id，真实领取在后台跑。
                    if (data.async && data.task_id) {
                        self.claimProgress = (data.reused ? '已有任务在执行，等待结果…' : '任务已提交，正在后台领取…');
                        self.pollClaimTask(data.task_id);
                        return;
                    }
                    self.claimBusy = false;
                    self.claimProgress = '';
                    self.claimResult = {
                        ok: !!res.ok,
                        message: res.message || '',
                        outcome: data.outcome || ''
                    };
                    S.report(res);
                    if (res.ok) {
                        self.load();
                        self.openClaim(self.claimAccount);
                    }
                });
            },
            /** 轮询异步领取任务结果（与 mixin 版一致，见上） */
            pollClaimTask: function (taskId) {
                var self = this;
                var tries = 0;
                var maxTries = 60;        // 60 × 2s = 120s
                var errStreak = 0;
                var maxErrStreak = 5;

                function stop(msg) {
                    clearInterval(timer);
                    self.claimBusy = false;
                    self.claimProgress = '';
                    self.claimResult = { ok: false, message: msg };
                }

                var timer = setInterval(function () {
                    tries++;
                    if (tries > maxTries) {
                        stop('领取超时未返回结果，请稍后在「签到日志」确认，或重试。');
                        return;
                    }
                    api.scoped.accountTask(taskId).then(function (res) {
                        if (!res || !res.ok) {
                            errStreak++;
                            var em = (res && (res.message || res.error)) || '查询任务状态失败';
                            if (errStreak >= maxErrStreak) {
                                stop('无法查询领取结果：' + em + '（请检查后台任务是否启动，见 storage/logs/action-worker.log）');
                                return;
                            }
                            self.claimProgress = '查询任务状态失败，正在重试…';
                            return;
                        }
                        errStreak = 0;

                        var d = (res && res.data) || {};
                        if (!d.done) {
                            var sec = Math.round((d.elapsed_ms || 0) / 1000);
                            self.claimProgress = sec < 15
                                ? '正在过无痕验证（第 ' + sec + ' 秒）…'
                                : '正在领取额度（第 ' + sec + ' 秒）…';
                            return;
                        }
                        clearInterval(timer);
                        self.claimBusy = false;
                        self.claimProgress = '';
                        var result = d.result || {};
                        self.claimResult = {
                            ok: !!d.ok,
                            message: d.message || (d.ok ? '领取成功' : '领取失败'),
                            outcome: result.outcome || ''
                        };
                        S.report({ ok: !!d.ok, message: d.message || '' });
                        if (d.ok) {
                            self.load();
                            self.openClaim(self.claimAccount);
                        }
                    }).catch(function (e) {
                        errStreak++;
                        if (errStreak >= maxErrStreak) {
                            stop('无法查询领取结果：' + (e && e.message ? e.message : '网络异常')
                                + '（请稍后在「签到日志」确认，或重试）');
                        }
                    });
                }, 2000);
            },
            claimOutcomeLabel: function (o) {
                return ({
                    claimed: '领取成功',
                    already: '今日已领',
                    not_found: '已下线',
                    need_captcha: '需人工验证',
                    failed: '失败'
                })[o] || '';
            },
            openClaimSite: function () { window.open('https://zcode.z.ai', '_blank'); },
            openClaimLogs: function () {
                var self = this;
                api.scoped.checkinLogs({ provider: this.filters.provider || '', limit: 100 }).then(function (res) {
                    if (!res.ok) { S.report(res); return; }
                    var d = res.data || {};
                    self.claimLogs = d.items || [];
                    self.claimSummary = d.summary || {};
                    self.showClaimLogs = true;
                });
            },
            claimStatusLabel: function (status) {
                return { SUCCESS: '领取成功', ALREADY: '已领取', FAILED: '失败', SKIPPED: '跳过' }[status] || (status || '—');
            },
            claimStatusTone: function (status) {
                return { SUCCESS: 'success', ALREADY: 'muted', FAILED: 'danger', SKIPPED: 'warning' }[status] || 'muted';
            },
            remove: function (a) {
                var self = this;
                global.AigwConfirm('确定删除账号「' + a.nickname + '」？该操作不可恢复。', {
                    title: '删除账号', danger: true, confirmText: '确认删除'
                }).then(function (yes) {
                    if (!yes) return;
                    self.busy = true;
                    api.scoped.deleteAccount(a.id).then(function (res) {
                        self.busy = false;
                        if (S.report(res)) self.load();
                    });
                });
            },
            openDetail: function (a) {
                var self = this;
                api.scoped.account(a.id).then(function (res) {
                    if (!res.ok) { S.report(res); return; }
                    self.detail = res.data;
                    self.showDetail = true;
                });
            },
            openModels: function (a) {
                var self = this;
                self.busy = true;
                api.scoped.accountModels(a.id).then(function (res) {
                    self.busy = false;
                    if (!res.ok) { S.report(res); return; }
                    self.modelList = (res.data && res.data.models) || [];
                    self.modelTitle = a.nickname;
                    self.showModels = true;
                });
            },
            modelLabel: function (m) { return m.id || m.model || m.name || String(m); },
            coolingLabel: function (a) {
                var sec = Number(a && a.cooling_remain);
                if (!sec || sec <= 0) return '无';
                return fmt.countdown(sec);
            },
            modelSub: function (m) { return m.name || m.display_name || ''; },
            // textarea 不支持插值，统一走 :value 绑定
            previewText: function (obj) {
                try { return JSON.stringify(obj || {}, null, 2); } catch (e) { return String(obj); }
            }
        },
        template:
            '<div>' +
              '<div class="page-head">' +
                '<div class="page-head__main">' +
                  '<div class="topbar__title">账号池</div>' +
                  '<div class="topbar__desc">当前筛选下共 {{ items.length }} 个账号</div>' +
                  '<div v-if="isAdmin && filters.user_id" class="mt-8 flex items-center">' +
                    '<aigw-badge tone="plum">仅看用户 #{{ filters.user_id }} 的账号</aigw-badge>' +
                    '<button type="button" class="btn btn--sm" style="margin-left:8px" @click="clearUserFilter">显示全部</button>' +
                  '</div>' +
                '</div>' +
                '<div class="page-head__actions">' +
                  '<button type="button" class="btn btn--sm" @click="load">刷新</button>' +
                  '<button type="button" class="btn btn--sm" :disabled="busy" @click="refreshAll">批量刷新额度</button>' +
                  '<button type="button" class="btn btn--sm btn--amber" :disabled="busy" @click="travelAll">猫猫旅行</button>' +
                  '<button type="button" class="btn btn--sm" @click="openTravelLogs">旅行日志</button>' +
                  '<button type="button" class="btn btn--sm btn--amber" :disabled="busy" @click="activityAll">每日活跃</button>' +
                  '<button type="button" class="btn btn--sm" @click="openActivityLogs">活跃日志</button>' +
                  '<button type="button" class="btn btn--sm btn--celadon" :disabled="busy" @click="tasksAll">成长任务</button>' +
                  '<button type="button" class="btn btn--sm btn--celadon" @click="openClaimLogs">领取记录</button>' +
                '</div>' +
              '</div>' +

              '<div class="grid grid--stats" style="margin-bottom:16px">' +
                '<aigw-stat v-for="c in summaryCards" :key="c.label" :label="c.label" :value="c.value" :tone="c.tone" :icon="c.icon" />' +
              '</div>' +

              '<aigw-card v-if="pool.providers.length" class="mb-16" title="供应商总览"' +
                            ' :sub="\'共 \' + pool.providers.length + (isAdmin ? \' 个供应商，点击可跳转到供应商页登录\' : \' 个供应商，点击可按此供应商筛选\')">' +
                '<div class="chipgrid">' +
                  '<button v-for="p in pool.providers" :key="p.key" type="button" class="chip"' +
                          ' :class="{ \'is-on\': p.enabled }"' +
                          ' :title="isAdmin ? (p.enabled ? \'点击管理该供应商账号\' : \'该供应商已停用\') : (p.enabled ? \'点击按此供应商筛选\' : \'该供应商已停用\')"' +
                          ' @click="goProviderLogin(p)">' +
                    '<span class="chip__dot" :class="{ \'is-on\': p.enabled }"></span>' +
                    '<span class="chip__name truncate">{{ p.name }}</span>' +
                    '<span class="chip__meta mono">{{ p.loginType }}</span>' +
                  '</button>' +
                '</div>' +
              '</aigw-card>' +

              '<div class="card" style="margin-bottom:16px"><div class="card__body">' +
                '<div class="grid grid--2">' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">供应商</label>' +
                    '<select class="select" v-model="filters.provider" @change="load">' +
                      '<option value="">全部供应商</option>' +
                      '<option v-for="p in providers" :key="p.provider_key" :value="p.provider_key">' +
                        '{{ p.display_name }}' +
                      '</option>' +
                    '</select>' +
                  '</div>' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">状态</label>' +
                    '<select class="select" v-model="filters.status" @change="load">' +
                      '<option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>' +
                    '</select>' +
                  '</div>' +
                '</div>' +
                '<div class="field mt-16 mb-0">' +
                  '<label class="field__label">关键词</label>' +
                  '<div class="field__row">' +
                    '<input class="input" v-model="filters.keyword" placeholder="搜索昵称或账号 ID" @keyup.enter="load">' +
                    '<button type="button" class="btn btn--primary" @click="load">查询</button>' +
                    '<button type="button" class="btn" @click="resetFilters">重置</button>' +
                  '</div>' +
                '</div>' +
              '</div></div>' +

              '<div v-if="loading"><aigw-loading /></div>' +
              '<div v-else-if="error" class="card">' +
                '<aigw-empty icon="warning" title="加载失败" :desc="error" />' +
              '</div>' +
              '<div v-else-if="!items.length" class="card">' +
                '<aigw-empty icon="accounts" title="没有匹配的账号" desc="换个筛选条件，或到「供应商」页把账号接入上游" />' +
              '</div>' +

              /* ── 按供应商分组（默认折叠，组头显示该组账号总积分） ──
                 ★ 折叠落在 `.card__body` 上而非内层 `.grid--cards`：
                   body 自带 16px 上下 padding，若只藏网格，折叠后会留下一条空白。 */
              '<div v-else>' +
                '<div class="page-subbar">' +
                  '<span class="text-soft">' + '共 {{ groups.length }} 个供应商 · {{ items.length }} 个账号（默认折叠）' + '</span>' +
                  '<button type="button" class="btn btn--sm" @click="toggleAll">' +
                    '{{ allExpanded ? "全部折叠" : "全部展开" }}' +
                  '</button>' +
                '</div>' +

                '<section v-for="g in groups" :key="g.key" class="card">' +
                  '<header class="card__head card__head--custom">' +
                    '<div class="grouphead">' +
                      '<button type="button" class="grouphead__toggle" @click="toggleCollapse(g.key)"' +
                              ' :aria-expanded="isCollapsed(g.key) ? \'false\' : \'true\'"' +
                              ' :title="isCollapsed(g.key) ? \'展开该供应商的账号\' : \'折叠该供应商的账号\'">' +
                        '<aigw-icon :name="isCollapsed(g.key) ? \'chevronDown\' : \'chevronUp\'" :size="16" />' +
                      '</button>' +
                      '<div class="grouphead__main">' +
                        '<div class="grouphead__title">' +
                          '<span class="grouphead__name">{{ g.name }}</span>' +
                          '<span class="mono text-faint" style="font-size:11.5px">{{ g.key }}</span>' +
                        '</div>' +
                        '<div class="grouphead__sub">' +
                          '<span>{{ groupSummaryText(g) }}</span>' +
                        '</div>' +
                      '</div>' +
                      '<div class="grouphead__actions">' +
                        '<span class="grouphead__credits" :title="\'该供应商下 \' + g.creditsKnown + \' 个账号的积分合计\' + (g.creditsUnknown ? \'（另有 \' + g.creditsUnknown + \' 个账号积分未知，未计入）\' : \'\')">' +
                          '<aigw-badge tone="clay" v-if="g.creditsKnown">' +
                            '总积分 {{ creditsLabel(g) }}' +
                          '</aigw-badge>' +
                          '<aigw-badge tone="muted" v-else>总积分 未知</aigw-badge>' +
                        '</span>' +
                      '</div>' +
                    '</div>' +
                  '</header>' +

                  '<div class="card__body" v-show="!isCollapsed(g.key)">' +
                    '<div class="grid grid--cards">' +
                      '<aigw-account-card v-for="a in g.accounts" :key="a.id" :account="a" :busy="busy"' +
                        ' @toggle="toggle(a)" @refresh="refresh(a)" @remove="remove(a)" @detail="openDetail(a)"' +
                        ' @models="openModels(a)" @cooldown="clearCooldown(a)" @action="runAccountAction(a, $event)" />' +
                    '</div>' +
                  '</div>' +
                '</section>' +
              '</div>' +

              /* ── 账号详情 ── */
              '<aigw-modal v-if="showDetail && detail" :title="\'账号详情 · \' + detail.nickname" wide' +
                           ' hide-foot @close="showDetail = false">' +
                '<div class="grid grid--2">' +
                  '<div class="field"><label class="field__label">供应商</label>' +
                    '<input class="input" :value="detail.provider_name" readonly></div>' +
                  '<div v-if="detail.region" class="field"><label class="field__label">区域</label>' +
                    '<input class="input" :value="detail.region.label + \'（\' + detail.region.domain + \'）\'" readonly></div>' +
                  '<div class="field"><label class="field__label">账号 ID</label>' +
                    '<input class="input mono" :value="detail.account_uid" readonly></div>' +
                  '<div class="field"><label class="field__label">状态</label>' +
                    '<input class="input" :value="(detail.state || {}).label" readonly></div>' +
                  '<div class="field"><label class="field__label">额度</label>' +
                    '<input class="input" :value="detail.credits_known ? fmt.num(detail.credits, 2) : \'未知\'" readonly></div>' +
                  '<div class="field"><label class="field__label">错误次数</label>' +
                    '<input class="input" :value="detail.error_count" readonly></div>' +
                  '<div class="field"><label class="field__label">最近刷新</label>' +
                    '<input class="input" :value="fmt.time(detail.last_refresh_at)" readonly></div>' +
                  '<div class="field"><label class="field__label">启用状态</label>' +
                    '<input class="input" :value="detail.enabled === false ? \'已停用\' : \'已启用\'" readonly></div>' +
                  '<div class="field"><label class="field__label">冷却剩余</label>' +
                    '<input class="input" :value="coolingLabel(detail)" readonly></div>' +
                  '<div class="field"><label class="field__label">状态原因</label>' +
                    '<input class="input" :value="detail.reason || \'—\'" readonly></div>' +
                '</div>' +
                '<div v-if="detail.metered" class="mt-16">' +
                  '<label class="field__label">网关实测消耗（本网关自计量）</label>' +
                  '<div class="grid grid--stats">' +
                    '<div class="stat stat--primary"><div class="stat__top"><div class="stat__label">今日消耗 Token</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.metered.today_tokens, 0) }}</div></div>' +
                    '<div class="stat stat--celadon"><div class="stat__top"><div class="stat__label">累计消耗 Token</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.metered.total_tokens, 0) }}</div></div>' +
                    '<div class="stat stat--amber"><div class="stat__top"><div class="stat__label">累计调用</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.metered.calls, 0) }}</div></div>' +
                    '<div class="stat"><div class="stat__top"><div class="stat__label">成功</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.metered.ok, 0) }}</div></div>' +
                  '</div>' +
                  '<div class="note note--sm mt-8">' +
                    '今日调用 {{ fmt.num(detail.metered.today_calls, 0) }} 次 · ' +
                    '输入 {{ fmt.num(detail.metered.prompt_tokens, 0) }} · ' +
                    '输出 {{ fmt.num(detail.metered.completion_tokens, 0) }} · ' +
                    '思维 {{ fmt.num(detail.metered.reasoning_tokens, 0) }} · ' +
                    '缓存读 {{ fmt.num(detail.metered.cache_read_tokens, 0) }}' +
                    '<span v-if="detail.metered.last_at"> · 最近 {{ detail.metered.last_at }}</span>' +
                  '</div>' +
                  '<div class="note note--sm">{{ detail.metered.note }}</div>' +
                '</div>' +
                '<div v-if="(detail.meta || {}).usage" class="mt-16">' +
                  '<label class="field__label">上游订阅额度（billing/balance 口径）</label>' +
                  '<div class="grid grid--stats">' +
                    '<div class="stat stat--primary"><div class="stat__top"><div class="stat__label">总额</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.meta.usage.total, 2) }}</div></div>' +
                    '<div class="stat stat--amber"><div class="stat__top"><div class="stat__label">已用</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.meta.usage.used, 2) }}</div></div>' +
                    '<div class="stat stat--celadon"><div class="stat__top"><div class="stat__label">剩余</div></div>' +
                      '<div class="stat__value">{{ fmt.num(detail.meta.usage.remain, 2) }}</div></div>' +
                  '</div>' +
                  '<div class="note note--sm">⚠️ 该数值由上游账单接口下发，部分供应商（如 ZCode）的对话走<b>独立计费池</b>，此桶不会扣减 —— 真实消耗以上方「网关实测」为准。</div>' +
                '</div>' +
                '<div v-if="((detail.meta || {}).packs || []).length" class="mt-16">' +
                  '<label class="field__label">额度包详情（共 {{ detail.meta.packs.length }} 个）</label>' +
                  '<div class="table__wrap"><table class="table">' +
                    '<thead><tr><th>名称</th><th>分组</th><th>总额</th><th>已用</th><th>剩余</th></tr></thead>' +
                    '<tbody>' +
                      '<tr v-for="(p, i) in detail.meta.packs" :key="i">' +
                        '<td>{{ p.name || "—" }}</td>' +
                        '<td class="text-soft">{{ p.group || "—" }}</td>' +
                        '<td>{{ fmt.num(p.limit, 2) }}</td>' +
                        '<td>{{ fmt.num(p.used, 2) }}</td>' +
                        '<td>{{ fmt.num(p.remain, 2) }}</td>' +
                      '</tr>' +
                    '</tbody>' +
                  '</table></div>' +
                '</div>' +
                '<div v-if="detail.last_error" class="note note--warn note--sm mt-8">最近错误：{{ detail.last_error }}</div>' +
                '<div class="field mt-16">' +
                  '<label class="field__label">凭据预览（已脱敏）</label>' +
                  '<textarea class="textarea" readonly rows="5" :value="previewText(detail.credential_preview)"></textarea>' +
                '</div>' +
                '<div v-if="detail.credits_detail" class="field mb-0">' +
                  '<label class="field__label">额度明细</label>' +
                  '<textarea class="textarea" readonly rows="2" :value="detail.credits_detail"></textarea>' +
                '</div>' +
              '</aigw-modal>' +

              /* ── 可用模型 ── */
              '<aigw-modal v-if="showModels" :title="\'可用模型 · \' + modelTitle" wide hide-foot' +
                           ' @close="showModels = false">' +
                '<div v-if="modelList.length" class="grid grid--2">' +
                  '<div v-for="(m, i) in modelList" :key="i" class="modelrow">' +
                    '<div class="modelrow__main">' +
                      '<div class="modelrow__id truncate">{{ modelLabel(m) }}</div>' +
                      '<div v-if="modelSub(m)" class="modelrow__name truncate">{{ modelSub(m) }}</div>' +
                    '</div>' +
                  '</div>' +
                '</div>' +
                '<aigw-empty v-else icon="models" title="该账号未返回模型列表" />' +
              '</aigw-modal>' +

              /* ── 猫猫旅行日志 ── */
              '<aigw-modal v-if="showTravelLogs" title="猫猫旅行日志" wide hide-foot' +
                           ' @close="showTravelLogs = false">' +
                '<div class="grid grid--stats" style="margin-bottom:16px">' +
                  '<aigw-stat label="今日推进" :value="travelSummary.total || 0" tone="primary" icon="activity" />' +
                  '<aigw-stat label="已到站" :value="(travelSummary.by_state && travelSummary.by_state.arrived) || 0" tone="celadon" icon="check" />' +
                  '<aigw-stat label="在途" :value="(travelSummary.by_state && travelSummary.by_state.traveling) || 0" tone="amber" icon="proxy" />' +
                  '<aigw-stat label="门槛未达" :value="(travelSummary.by_state && travelSummary.by_state.blocked) || 0" tone="rust" icon="warning" />' +
                '</div>' +
                '<div v-if="travelLogs.length">' +
                  '<div v-for="log in travelLogs" :key="log.id" class="modelrow" style="margin-bottom:8px">' +
                    '<div class="modelrow__main">' +
                      '<div class="modelrow__id truncate">{{ log.account_uid }}</div>' +
                      '<div class="modelrow__name truncate">{{ log.message || log.location || "—" }}</div>' +
                    '</div>' +
                    '<div class="modelrow__side text-soft">' +
                      '<aigw-badge :tone="travelStateTone(log.state)" dot>{{ travelStateLabel(log.state) }}</aigw-badge>' +
                      '<div class="nowrap" style="font-size:11.5px">{{ log.travel_date }}</div>' +
                    '</div>' +
                  '</div>' +
                '</div>' +
                '<aigw-empty v-else icon="moon" title="暂无旅行记录" desc="点击「猫猫旅行」推进一次，或等待定时任务执行" />' +
              '</aigw-modal>' +

              /* ── 每日活跃注入日志 ── */
              '<aigw-modal v-if="showActivityLogs" title="每日活跃注入日志" wide hide-foot' +
                           ' @close="showActivityLogs = false">' +
                '<div class="grid grid--stats" style="margin-bottom:16px">' +
                  '<aigw-stat label="今日已注入" :value="activitySummary.total || 0" tone="primary" icon="activity" />' +
                  '<aigw-stat label="成功" :value="(activitySummary.by_status && activitySummary.by_status.SUCCESS) || 0" tone="celadon" icon="check" />' +
                  '<aigw-stat label="失败" :value="(activitySummary.by_status && activitySummary.by_status.FAILED) || 0" tone="rust" icon="warning" />' +
                '</div>' +
                '<div v-if="activityLogs.length">' +
                  '<div v-for="log in activityLogs" :key="log.id" class="modelrow" style="margin-bottom:8px">' +
                    '<div class="modelrow__main">' +
                      '<div class="modelrow__id truncate">{{ log.account_uid }}</div>' +
                      '<div class="modelrow__name truncate">{{ log.message || log.model || "—" }}</div>' +
                    '</div>' +
                    '<div class="modelrow__side text-soft">' +
                      '<aigw-badge :tone="log.status === \'SUCCESS\' ? \'success\' : \'warning\'" dot>{{ log.status === \'SUCCESS\' ? \'已注入\' : \'失败\' }}</aigw-badge>' +
                      '<div class="nowrap" style="font-size:11.5px">{{ log.activity_date }}</div>' +
                    '</div>' +
                  '</div>' +
                '</div>' +
                '<aigw-empty v-else icon="activity" title="暂无活跃记录" desc="点击「每日活跃」注入一次，或等待定时任务执行" />' +
              '</aigw-modal>' +

              /* ── 额度与领取弹窗（额度快照免验证码；领取为自动无痕求解） ── */
              '<aigw-modal v-if="showClaim" :title="\'额度与领取 · \' + ((claimAccount && claimAccount.nickname) || \'\')" wide' +
                           ' :busy="claimBusy" :hide-foot="!claimPlans.length" confirm-text="一键领取"' +
                           ' @close="showClaim = false" @confirm="submitClaim">' +

                /* ① 额度快照：只读，不需要验证码 —— 这是本弹窗的主内容 */
                '<div v-if="claimQuota" class="note note--info" style="margin-bottom:12px">' +
                  '<b>当前额度</b>（只读查询，<b>无需验证码</b>）' +
                  '<div style="margin-top:6px">' +
                    '剩余 <b>{{ fmt.num(claimQuota.total_remain, 0) }}</b> / {{ fmt.num(claimQuota.total_limit, 0) }} token' +
                    '<span v-if="claimQuota.expire_at" class="text-soft"> · 每日重置</span>' +
                  '</div>' +
                  '<div style="margin-top:8px" v-if="claimQuota.packages && claimQuota.packages.length">' +
                    '<div v-for="q in claimQuota.packages" :key="q.name" style="margin-top:4px">' +
                      '<aigw-badge tone="success" dot>{{ q.name }}</aigw-badge>' +
                      ' <span class="mono">{{ fmt.num(q.remain, 0) }}</span>' +
                      ' <span class="text-soft">/ {{ fmt.num(q.limit, 0) }}</span>' +
                    '</div>' +
                  '</div>' +
                  '<div style="margin-top:8px">' +
                    '对话通道（免验证码）：' +
                    '<aigw-badge :tone="claimQuota.api_key_ready ? \'success\' : \'warning\'" dot>' +
                      '{{ claimQuota.api_key_ready ? \'已就绪\' : \'未就绪\' }}' +
                    '</aigw-badge>' +
                  '</div>' +
                '</div>' +
                '<div v-else-if="claimBusy" class="text-soft" style="padding:8px 0">正在读取额度…</div>' +

                /* ② 可领取额度 */
                '<div class="field"><label class="field__label">可领取 / 已生效额度</label>' +
                  '<div v-if="claimPlans.length">' +
                    '<label v-for="p in claimPlans" :key="p.plan_id" class="modelrow" style="margin-bottom:8px;cursor:pointer">' +
                      '<div class="modelrow__main">' +
                        '<div class="modelrow__id"><input type="radio" :value="p.plan_id" v-model="claimForm.plan_id"> ' +
                          '{{ p.name || p.plan_id }}</div>' +
                        '<div class="modelrow__name truncate text-soft">' +
                          '{{ p.plan_id }}{{ p.description ? \' · \' + p.description : \'\' }}' +
                        '</div>' +
                        '<div v-if="p.entitlements && p.entitlements.length" class="text-soft" style="margin-top:4px">' +
                          '<span v-for="e in p.entitlements" :key="e.show_name" style="margin-right:10px">' +
                            '{{ e.show_name }} <span class="mono">{{ fmt.num(e.remain_units != null ? e.remain_units : e.grant_units, 0) }}</span>' +
                            '<span v-if="e.period" class="text-soft">/{{ e.period === \'daily\' ? \'日\' : e.period }}</span>' +
                          '</span>' +
                        '</div>' +
                      '</div>' +
                      '<div class="modelrow__side">' +
                        '<aigw-badge :tone="p.claimable ? \'success\' : \'muted\'" dot>' +
                          '{{ p.claimable ? \'可领取\' : (p.status || \'不可领\') }}' +
                        '</aigw-badge>' +
                      '</div>' +
                    '</label>' +
                  '</div>' +
                  '<aigw-empty v-else-if="!claimBusy" icon="moon" title="当前没有可领取或已生效的额度包"' +
                              ' desc="本项为空表示该账号暂时没有可领额度" />' +
                '</div>' +

                /* ③ 一键领取说明：验证码由服务端自动无痕求解 */
                '<div class="note note--info" style="margin-bottom:12px" v-if="claimPlans.length">' +
                  '<b>无需输入验证码</b>：点下方「一键领取」即可。服务端会在本机拉起无头浏览器，' +
                  '走阿里云<b>无痕验证</b>（<span class="mono">startTracelessVerification()</span>）' +
                  '自动取得一次性凭证并提交，全程<b>零人工交互</b>，约需 10 秒。' +
                '</div>' +

                /* 兜底：自动求解失败时可手动粘验证码 */
                '<div class="field" v-if="claimPlans.length">' +
                  '<button type="button" class="btn btn--sm" @click="showClaimForm = !showClaimForm">' +
                    '{{ showClaimForm ? \'收起手动兜底\' : \'自动领取失败？手动粘贴验证码\' }}' +
                  '</button>' +
                '</div>' +
                '<template v-if="showClaimForm && claimPlans.length">' +
                  '<div class="note" style="margin-bottom:12px">' +
                    '仅当自动无痕验证被风控降级（弹出滑块）时才需要手动：' +
                    '<ol style="margin:6px 0 0 18px;padding:0;line-height:1.8">' +
                      '<li>点击下方按钮，在新标签页用<b>同一账号</b>打开领取页。</li>' +
                      '<li>在页面完成阿里云人机验证。</li>' +
                      '<li>按 <span class="mono">F12</span> → <b>Network</b>，筛选 <span class="mono">billing/claim</span>。</li>' +
                      '<li>复制请求头 <span class="mono">X-Aliyun-Captcha-Verify-Param</span> 的值，粘贴到下方后点「一键领取」。</li>' +
                    '</ol>' +
                  '</div>' +
                  '<div class="field">' +
                    '<button type="button" class="btn btn--sm" @click="openClaimSite">打开 zcode 领取页（新标签页）</button>' +
                  '</div>' +
                  '<div class="field mb-0"><label class="field__label">阿里云验证码（留空 = 自动求解）</label>' +
                    '<input class="input mono" v-model="claimForm.captcha" placeholder="留空即可自动过验证码；如需手动则粘贴 X-Aliyun-Captcha-Verify-Param 的值">' +
                    '<div class="field__hint">填了就以你粘贴的为准；验证码一次性且有 90 秒时效，请尽快提交。</div>' +
                  '</div>' +
                '</template>' +

                '<div v-if="claimProgress" class="note note--info" style="margin-top:12px">' +
                  '<b>后台领取中</b>：{{ claimProgress }}' +
                  '<div class="text-soft" style="margin-top:4px">领取在服务端后台执行（需驱动浏览器过无痕验证），' +
                  '不受页面 / CDN 超时影响，请保持页面打开。</div>' +
                '</div>' +

                '<div v-if="claimResult" class="note" style="margin-top:12px"' +
                     ' :class="claimResult.ok ? \'note--info\' : \'note--warn\'">' +
                  '<b>{{ claimResult.ok ? (claimOutcomeLabel(claimResult.outcome) || \'完成\') : \'领取失败\' }}</b>' +
                  '<span v-if="claimResult.outcome" class="text-soft">（{{ claimResult.outcome }}）</span>' +
                  '：{{ claimResult.message }}' +
                '</div>' +
              '</aigw-modal>' +

              /* ── 领取记录弹窗 ── */
              '<aigw-modal v-if="showClaimLogs" title="领取 / 签到记录" wide hide-foot' +
                           ' @close="showClaimLogs = false">' +
                '<div class="grid grid--stats" style="margin-bottom:16px">' +
                  '<aigw-stat label="今日记录" :value="claimSummary.total || 0" tone="primary" icon="activity" />' +
                  '<aigw-stat label="领取成功" :value="claimSummary.success || 0" tone="celadon" icon="check" />' +
                  '<aigw-stat label="已领取" :value="claimSummary.already || 0" tone="amber" icon="timer" />' +
                  '<aigw-stat label="失败" :value="claimSummary.failed || 0" tone="rust" icon="warning" />' +
                '</div>' +
                '<div v-if="claimLogs.length">' +
                  '<div v-for="log in claimLogs" :key="log.id" class="modelrow" style="margin-bottom:8px">' +
                    '<div class="modelrow__main">' +
                      '<div class="modelrow__id truncate">{{ log.nickname || log.account_uid }}</div>' +
                      '<div class="modelrow__name truncate">{{ log.message || "—" }}</div>' +
                    '</div>' +
                    '<div class="modelrow__side text-soft">' +
                      '<aigw-badge :tone="claimStatusTone(log.status)" dot>{{ claimStatusLabel(log.status) }}</aigw-badge>' +
                      '<div class="nowrap" style="font-size:11.5px">{{ log.provider_key }}</div>' +
                    '</div>' +
                  '</div>' +
                '</div>' +
                '<aigw-empty v-else icon="moon" title="暂无领取记录" desc="点击账号卡片「领取额度」后，结果会记录在此" />' +
              '</aigw-modal>' +
            '</div>'
    };

    global.AigwPagesRegister(pages);
})(window);