/* ============================================================
   API 客户端：统一封装 fetch、错误解包、401 跳登录
   ============================================================ */
(function (global) {
    'use strict';

    var BASE = '';

    /** 未登录回调（由 app.js 注入） */
    var onUnauthorized = function () {};

    function setUnauthorizedHandler(fn) {
        onUnauthorized = typeof fn === 'function' ? fn : function () {};
    }

    /* ── 角色（决定共享页面调用 /api/admin 还是 /api/user） ── */
    var currentRole = 'admin';

    function setRole(role) {
        currentRole = role === 'user' ? 'user' : 'admin';
    }

    /** 由请求路径推断其所属身份，用于 401 时判定是否清理当前会话 */
    function roleForPath(path) {
        if (typeof path === 'string') {
            if (path.indexOf('/api/admin') === 0) return 'admin';
            if (path.indexOf('/api/user') === 0) return 'user';
        }
        return '';
    }

    /**
     * 下载 CSV（管理端与用户端共用）
     * @param {string} path 形如 /api/admin/logs/export
     * @param {object} [q] 查询参数
     * @param {string} [name] 文件名前缀（默认 call-logs-）
     */
    function exportCsv(path, q, name) {
        var pairs = [];
        Object.keys(q || {}).forEach(function (k) {
            var v = q[k];
            if (v === undefined || v === null || v === '') return;
            pairs.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
        });
        var href = BASE + path + (pairs.length ? '?' + pairs.join('&') : '');
        return fetch(href, { credentials: 'same-origin', headers: { 'Accept': 'text/csv' } })
            .then(function (res) {
                if (res.status === 401) {
                    var reqRole = roleForPath(path);
                    if (!reqRole || reqRole === currentRole) onUnauthorized();
                    return false;
                }
                if (!res.ok) return false;
                return res.blob().then(function (blob) {
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = (name || 'call-logs-') + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.csv';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
                    return true;
                });
            })
            .catch(function () { return false; });
    }

    /**
     * 核心请求。后端统一返回 { ok, message?, data?, error? }
     * @param {string} [options.role] 该请求所属身份（'admin'|'user'），用于 401 时判定是否清理当前会话
     * @returns {Promise<{ok:boolean,data:any,message:string,error:string,status:number}>}
     */
    function request(method, path, body, options) {
        options = options || {};

        var init = {
            method: method,
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        };

        if (body !== undefined && body !== null) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(body);
        }

        return fetch(BASE + path, init).then(function (res) {
            return res.text().then(function (text) {
                var payload = null;
                if (text) {
                    try { payload = JSON.parse(text); } catch (e) { payload = null; }
                }

                if (res.status === 401) {
                    // 仅当 401 来自「当前登录身份」时才判定会话失效。
                    // 引导阶段会主动探测另一身份（如用户已登录时先探 admin），
                    // 那种 401 属于正常情况，不能清会话、不能弹「已失效」。
                    var reqRole = options.role || roleForPath(path);
                    if (!reqRole || reqRole === currentRole) {
                        onUnauthorized();
                    }
                }

                if (!payload || typeof payload !== 'object') {
                    return {
                        ok: false,
                        status: res.status,
                        data: null,
                        message: '',
                        error: text ? String(text).slice(0, 400) : ('请求失败（HTTP ' + res.status + '）')
                    };
                }

                var errMsg = '';
                if (payload.error) {
                    errMsg = typeof payload.error === 'string'
                        ? payload.error
                        : (payload.error.message || '');
                }

                return {
                    ok: payload.ok === true,
                    status: res.status,
                    data: payload.data !== undefined ? payload.data : null,
                    message: payload.message || '',
                    error: errMsg
                };
            });
        }).catch(function (e) {
            return {
                ok: false,
                status: 0,
                data: null,
                message: '',
                error: '网络异常：' + (e && e.message ? e.message : '无法连接服务器')
            };
        });
    }

    var api = {
        get: function (p, q, o) {
            if (q && Object.keys(q).length) {
                var pairs = [];
                Object.keys(q).forEach(function (k) {
                    var v = q[k];
                    if (v === undefined || v === null || v === '') return;
                    pairs.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
                });
                if (pairs.length) p += (p.indexOf('?') === -1 ? '?' : '&') + pairs.join('&');
            }
            return request('GET', p, undefined, o);
        },
        post: function (p, b, o) { return request('POST', p, b, o); },
        put: function (p, b, o) { return request('PUT', p, b, o); },
        del: function (p, b, o) { return request('DELETE', p, b, o); },
        siteConfig: function () { return api.get('/api/site-config'); },
        captchaUrl: function () { return BASE + '/api/captcha?t=' + Date.now(); },
        setUnauthorizedHandler: setUnauthorizedHandler,
        setRole: setRole
    };

    /* ── 领域接口 ───────────────────────────────────────── */

    api.admin = {
        // 认证
        login: function (username, password, captcha) {
            return api.post('/api/admin/login', { username: username, password: password, captcha: captcha || '' });
        },
        logout: function () { return api.post('/api/admin/logout', {}); },
        me: function () { return api.get('/api/admin/me', null, { role: 'admin' }); },
        updateProfile: function (payload) { return api.post('/api/admin/profile', payload); },
        changePassword: function (payload) { return api.post('/api/admin/password', payload); },

        // 仪表盘
        dashboard: function (days) { return api.get('/api/admin/dashboard', { days: days }); },

        // 设置 / 代理
        settings: function () { return api.get('/api/admin/settings'); },
        saveSettings: function (payload) { return api.post('/api/admin/settings', payload); },
        // 邮件（SMTP）：端口不限，80 端口亦可发信
        mailConfig: function () { return api.get('/api/admin/mail/config'); },
        mailTest: function (to) { return api.post('/api/admin/mail/test', { to: to || '' }); },
        mailTestConnect: function () { return api.post('/api/admin/mail/test-connect', {}); },
        regenerateKey: function () { return api.post('/api/admin/settings/regenerate-key', {}); },
        proxy: function () { return api.get('/api/admin/proxy'); },
        saveProxy: function (payload) { return api.post('/api/admin/proxy', payload); },

        // 提示词模板（全局）
        promptTemplates: function () { return api.get('/api/admin/prompt-templates'); },
        createPromptTemplate: function (payload) { return api.post('/api/admin/prompt-templates', payload); },
        updatePromptTemplate: function (id, payload) { return api.put('/api/admin/prompt-templates/' + id, payload); },
        deletePromptTemplate: function (id) { return api.del('/api/admin/prompt-templates/' + id); },

        // 思维注入（全局）：网关侧改写 system 思维
        personas: function () { return api.get('/api/admin/personas'); },
        personaStatus: function () { return api.get('/api/admin/personas/status'); },
        createPersona: function (payload) { return api.post('/api/admin/personas', payload); },
        seedPersona: function () { return api.post('/api/admin/personas/seed', {}); },
        previewPersona: function (payload) { return api.post('/api/admin/personas/preview', payload); },
        selfcheckPersona: function (payload) { return api.post('/api/admin/personas/selfcheck', payload); },
        persona: function (id) { return api.get('/api/admin/personas/' + id); },
        updatePersona: function (id, payload) { return api.put('/api/admin/personas/' + id, payload); },
        deletePersona: function (id) { return api.del('/api/admin/personas/' + id); },
        setDefaultPersona: function (id, isDefault) {
            return api.post('/api/admin/personas/' + id + '/default', { is_default: !!isDefault });
        },
        setPersonaTarget: function (id, target, enabled) {
            return api.post('/api/admin/personas/' + id + '/target', { target: target, enabled: !!enabled });
        },
        personaRevisions: function (id) { return api.get('/api/admin/personas/' + id + '/revisions'); },
        restorePersona: function (id, revisionId) {
            return api.post('/api/admin/personas/' + id + '/restore', { revision_id: revisionId });
        },

        // 供应商
        providers: function () { return api.get('/api/admin/providers'); },
        createProvider: function (payload) { return api.post('/api/admin/providers/custom', payload); },
        updateProvider: function (key, payload) { return api.put('/api/admin/providers/' + encodeURIComponent(key), payload); },
        deleteProvider: function (key) { return api.del('/api/admin/providers/' + encodeURIComponent(key)); },
        testProvider: function (key, accountId) {
            return api.post('/api/admin/providers/' + encodeURIComponent(key) + '/test', { account_id: accountId || 0 });
        },

        // 账号池
        accounts: function (q) { return api.get('/api/admin/accounts', q); },
        account: function (id) { return api.get('/api/admin/accounts/' + id); },
        createAccount: function (payload) { return api.post('/api/admin/accounts', payload); },
        updateAccount: function (id, payload) { return api.put('/api/admin/accounts/' + id, payload); },
        deleteAccount: function (id) { return api.del('/api/admin/accounts/' + id); },
        toggleAccount: function (id) { return api.post('/api/admin/accounts/' + id + '/toggle', {}); },
        refreshAccount: function (id) { return api.post('/api/admin/accounts/' + id + '/refresh', {}); },
        refreshAll: function (provider) { return api.post('/api/admin/accounts/refresh-all', { provider: provider || '' }); },
        checkinAll: function (provider) { return api.post('/api/admin/accounts/checkin-all', { provider: provider || '' }); },
        travelAll: function (provider, force) { return api.post('/api/admin/accounts/travel-all', { provider: provider || '', force: !!force }); },
        travelLogs: function (q) { return api.get('/api/admin/accounts/travel-logs', q); },
        activityAll: function (provider, force) { return api.post('/api/admin/accounts/activity-all', { provider: provider || '', force: !!force }); },
        activityLogs: function (q) { return api.get('/api/admin/accounts/activity-logs', q); },
        tasksAll: function (provider, force) { return api.post('/api/admin/accounts/tasks-all', { provider: provider || '', force: !!force }); },
        // 成长中心可视化：只读快照 + 单账号单项动作
        growth: function (provider) { return api.get('/api/admin/accounts/growth', { provider: provider || '' }); },
        growthAccount: function (id, force) {
            return api.get('/api/admin/accounts/' + id + '/growth', force ? { force: 1 } : {});
        },
        growthAction: function (id, action, params) {
            return api.post('/api/admin/accounts/' + id + '/growth', { action: action, params: params || {} });
        },
        accountAction: function (id, action, params) {
            return api.post('/api/admin/accounts/' + id + '/action', { action: action, params: params || {} });
        },
        /**
         * 查询异步动作任务结果（长耗时动作：ZCode 领额度等）
         * 后端把这类动作改为「提交即返回 task_id」，避免被 CDN 回源超时（524）掐断。
         */
        accountTask: function (taskId) {
            return api.get('/api/admin/accounts/action-task', { task_id: taskId });
        },
        checkinLogs: function (q) { return api.get('/api/admin/accounts/checkin-logs', q); },
        accountModels: function (id) { return api.post('/api/admin/accounts/' + id + '/models', {}); },
        clearCooldown: function (id) { return api.post('/api/admin/accounts/' + id + '/cooldown/clear', {}); },
        poolOverview: function () { return api.get('/api/admin/account-pool/overview'); },
        creditMonitor: function (q) { return api.get('/api/admin/accounts/credits/monitor', q); },

        // 定时任务
        scheduler: function () { return api.get('/api/admin/scheduler'); },
        saveScheduler: function (payload) { return api.post('/api/admin/scheduler', payload); },
        runScheduler: function () { return api.post('/api/admin/scheduler/run', {}); },

        // 登录流程
        startLogin: function (provider, payload) {
            return api.post('/api/admin/auth/' + encodeURIComponent(provider) + '/start', payload || {});
        },
        pollLogin: function (provider, payload) {
            return api.post('/api/admin/auth/' + encodeURIComponent(provider) + '/poll', payload || {});
        },
        submitAuthCallback: function (provider, payload) {
            return api.post('/api/admin/auth/' + encodeURIComponent(provider) + '/callback', payload || {});
        },

        // 模型
        models: function () { return api.get('/api/admin/models'); },
        createModel: function (payload) { return api.post('/api/admin/models', payload); },
        updateModel: function (id, payload) { return api.put('/api/admin/models/' + id, payload); },
        deleteModel: function (id) { return api.del('/api/admin/models/' + id); },
        toggleProviderModels: function (providerKey, enabled) {
            return api.post('/api/admin/models/toggle-provider', { provider_key: providerKey, enabled: enabled });
        },
        syncModels: function (providerKey) { return api.post('/api/admin/models/sync', { provider_key: providerKey || '' }); },

        // 日志
        logs: function (q) { return api.get('/api/admin/logs', q); },
        // 单条完整详情：含完整正文与思维注入正文（列表接口只给摘要，见 LogRepository::paginate）
        logDetail: function (id) { return api.get('/api/admin/logs/detail', { id: id }); },
        // q 用于带上用户筛选（user / user_id），让统计卡片跟着筛选一起收窄
        logStats: function (days, q) {
            return api.get('/api/admin/logs/stats', Object.assign({ days: days || 7 }, q || {}));
        },
        exportLogs: function (q) { return exportCsv('/api/admin/logs/export', q); },
        pruneLogs: function (days) { return api.post('/api/admin/logs/prune', { days: days }); },
        clearLogs: function () { return api.post('/api/admin/logs/clear', {}); },

        // 数据大屏（调用量 / 注册登录 / 累计指标，一个聚合入口）
        // force=1 跳过服务端 60 秒结果缓存（大屏的「刷新」按钮用它，切换天数则走缓存）
        screens: function (days, force) {
            return api.get('/api/admin/screens', { days: days || 7, force: force ? 1 : '' });
        },

        // 用户行为审计
        audit: function (q) { return api.get('/api/admin/audit', q); },
        auditStats: function (days) { return api.get('/api/admin/audit/stats', { days: days || 7 }); },
        auditOptions: function () { return api.get('/api/admin/audit/options'); },
        auditTimeline: function (userId, limit) {
            return api.get('/api/admin/audit/timeline', { user_id: userId, limit: limit || 100 });
        },
        exportAudit: function (q) { return exportCsv('/api/admin/audit/export', q, 'audit-logs-'); },
        pruneAudit: function (days) { return api.post('/api/admin/audit/prune', { days: days }); },

        // 普通用户管理
        users: function (q) { return api.get('/api/admin/users', q); },
        user: function (id) { return api.get('/api/admin/users/' + id); },
        updateUser: function (id, payload) { return api.put('/api/admin/users/' + id, payload); },
        deleteUser: function (id) { return api.del('/api/admin/users/' + id); },
        toggleUser: function (id) { return api.post('/api/admin/users/' + id + '/toggle', {}); },
        regenerateUserKey: function (id) { return api.post('/api/admin/users/' + id + '/regenerate-key', {}); }
    };

    /* ── 用户端接口（普通用户，仅能操作本人数据） ─────────── */
    api.user = {
        // 认证
        registerConfig: function () { return api.get('/api/user/register-config'); },
        // 邮箱验证码（注册/找回密码/改密码）；purpose: register | reset | change
        // purpose=change 时后端强制取会话邮箱，email 传什么都会被忽略
        emailCode: function (email, captcha, purpose) {
            return api.post('/api/user/email-code', {
                email: email,
                captcha: captcha || '',
                purpose: purpose || 'register'
            });
        },
        register: function (payload) { return api.post('/api/user/register', payload); },
        // 找回密码（未登录）：凭邮箱验证码重置
        resetPassword: function (payload) { return api.post('/api/user/reset-password', payload); },
        login: function (username, password, captcha) {
            return api.post('/api/user/login', { username: username, password: password, captcha: captcha || '' });
        },
        logout: function () { return api.post('/api/user/logout', {}); },
        me: function () { return api.get('/api/user/me', null, { role: 'user' }); },
        updateProfile: function (payload) { return api.post('/api/user/profile', payload); },
        changePassword: function (payload) { return api.post('/api/user/password', payload); },
        regenerateApiKey: function () { return api.post('/api/user/api-key/regenerate', {}); },

        // 仪表盘（仅本人统计）
        dashboard: function (days) { return api.get('/api/user/dashboard', { days: days }); },

        // 供应商（仅可用元数据，统计按本人聚合）
        providers: function () { return api.get('/api/user/providers'); },
        // 自建 / 修改 / 删除自定义供应商（归属本人）
        createProvider: function (payload) { return api.post('/api/user/providers/custom', payload); },
        updateProvider: function (key, payload) {
            return api.put('/api/user/providers/' + encodeURIComponent(key), payload);
        },
        deleteProvider: function (key) {
            return api.del('/api/user/providers/' + encodeURIComponent(key));
        },
        testProvider: function (key, accountId) {
            return api.post('/api/user/providers/' + encodeURIComponent(key) + '/test', accountId ? { account_id: accountId } : {});
        },

        // 模型目录（作用域感知：全局模型 + 本人私有模型；可同步 / 增删改）
        models: function () { return api.get('/api/user/models'); },
        createModel: function (payload) { return api.post('/api/user/models', payload); },
        updateModel: function (id, payload) { return api.put('/api/user/models/' + id, payload); },
        deleteModel: function (id) { return api.del('/api/user/models/' + id); },
        toggleProviderModels: function (providerKey, enabled) {
            return api.post('/api/user/models/toggle-provider', { provider_key: providerKey, enabled: enabled });
        },
        syncModels: function (providerKey) {
            return api.post('/api/user/models/sync', { provider_key: providerKey || '' });
        },

        // 网关设置（只读接入信息：网关地址与本人 API Key）
        settings: function () { return api.get('/api/user/settings'); },

        // 提示词模板（本人自建 + 只读全局）
        promptTemplates: function () { return api.get('/api/user/prompt-templates'); },
        createPromptTemplate: function (payload) { return api.post('/api/user/prompt-templates', payload); },
        updatePromptTemplate: function (id, payload) { return api.put('/api/user/prompt-templates/' + id, payload); },
        deletePromptTemplate: function (id) { return api.del('/api/user/prompt-templates/' + id); },

        // 思维注入（本人自建 + 全局只读；靶点/预演/修订属管理台能力）
        personas: function () { return api.get('/api/user/personas'); },
        createPersona: function (payload) { return api.post('/api/user/personas', payload); },
        updatePersona: function (id, payload) { return api.put('/api/user/personas/' + id, payload); },
        deletePersona: function (id) { return api.del('/api/user/personas/' + id); },
        // 用户级平台开关：自选在哪些平台接受注入
        setPersonaPlatform: function (target, enabled) {
            return api.post('/api/user/personas/platform', { target: target, enabled: !!enabled });
        },
        // 用户级思维开关：自选哪几份思维对自己生效（默认全关）
        setPersonaEnable: function (personaId, enabled) {
            return api.post('/api/user/personas/enable', { persona_id: personaId, enabled: !!enabled });
        },
        // 用户自测自证口令
        selfcheckPersona: function (text) { return api.post('/api/user/personas/selfcheck', { text: text }); },

        // 我的账户
        accounts: function (q) { return api.get('/api/user/accounts', q); },
        account: function (id) { return api.get('/api/user/accounts/' + id); },
        createAccount: function (payload) { return api.post('/api/user/accounts', payload); },
        updateAccount: function (id, payload) { return api.put('/api/user/accounts/' + id, payload); },
        deleteAccount: function (id) { return api.del('/api/user/accounts/' + id); },
        toggleAccount: function (id) { return api.post('/api/user/accounts/' + id + '/toggle', {}); },
        refreshAccount: function (id) { return api.post('/api/user/accounts/' + id + '/refresh', {}); },
        refreshAll: function (provider) { return api.post('/api/user/accounts/refresh-all', { provider: provider || '' }); },
        checkinAll: function (provider) { return api.post('/api/user/accounts/checkin-all', { provider: provider || '' }); },
        travelAll: function (provider, force) { return api.post('/api/user/accounts/travel-all', { provider: provider || '', force: !!force }); },
        travelLogs: function (q) { return api.get('/api/user/accounts/travel-logs', q); },
        activityAll: function (provider, force) { return api.post('/api/user/accounts/activity-all', { provider: provider || '', force: !!force }); },
        activityLogs: function (q) { return api.get('/api/user/accounts/activity-logs', q); },
        tasksAll: function (provider, force) { return api.post('/api/user/accounts/tasks-all', { provider: provider || '', force: !!force }); },
        growth: function (provider) { return api.get('/api/user/accounts/growth', { provider: provider || '' }); },
        growthAccount: function (id, force) {
            return api.get('/api/user/accounts/' + id + '/growth', force ? { force: 1 } : {});
        },
        growthAction: function (id, action, params) {
            return api.post('/api/user/accounts/' + id + '/growth', { action: action, params: params || {} });
        },
        accountAction: function (id, action, params) {
            return api.post('/api/user/accounts/' + id + '/action', { action: action, params: params || {} });
        },
        /** 查询异步动作任务结果（用户端，service 端同 admin） */
        accountTask: function (taskId) {
            return api.get('/api/user/accounts/action-task', { task_id: taskId });
        },
        checkinLogs: function (q) { return api.get('/api/user/accounts/checkin-logs', q); },
        accountModels: function (id) { return api.post('/api/user/accounts/' + id + '/models', {}); },
        clearCooldown: function (id) { return api.post('/api/user/accounts/' + id + '/cooldown/clear', {}); },
        poolOverview: function () { return api.get('/api/user/account-pool/overview'); },
        creditMonitor: function (q) { return api.get('/api/user/accounts/credits/monitor', q); },

        // 登录流程（浏览器授权 / 短信 / 设备码 / API Key）
        startLogin: function (provider, payload) {
            return api.post('/api/user/auth/' + encodeURIComponent(provider) + '/start', payload || {});
        },
        pollLogin: function (provider, payload) {
            return api.post('/api/user/auth/' + encodeURIComponent(provider) + '/poll', payload || {});
        },
        submitAuthCallback: function (provider, payload) {
            return api.post('/api/user/auth/' + encodeURIComponent(provider) + '/callback', payload || {});
        },

        // 调用日志（仅本人调用）
        logs: function (q) { return api.get('/api/user/logs', q); },
        logDetail: function (id) { return api.get('/api/user/logs/detail', { id: id }); },
        logStats: function (days, q) {
            return api.get('/api/user/logs/stats', Object.assign({ days: days || 7 }, q || {}));
        },
        exportLogs: function (q) { return exportCsv('/api/user/logs/export', q); },

        // 我的操作记录（仅本人）
        audit: function (q) { return api.get('/api/user/audit', q); },
        auditTimeline: function (limit) {
            return api.get('/api/user/audit/timeline', { limit: limit || 100 });
        }
    };

    /**
     * 角色自适应命名空间：共享页面统一用 api.scoped.xxx()
     * 由 store 在登录/引导后调用 api.setRole() 决定指向 admin 还是 user。
     */
    Object.defineProperty(api, 'scoped', {
        get: function () { return currentRole === 'user' ? api.user : api.admin; }
    });

    global.AigwApi = api;
})(window);
