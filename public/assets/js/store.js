/* ============================================================
   用户端状态：只认普通用户身份（账号端）
   ------------------------------------------------------------
   与后台端（store-admin.js）完全独立：本文件只会以 'user' 身份
   引导会话，接口请求固定走 /api/user/*；后台入口在 /admin，
   由 admin.html + store-admin.js 承载。
   ============================================================ */
(function (global) {
    'use strict';

    var Vue = global.Vue;
    var api = global.AigwApi;
    var core = global.AigwCore;

    if (!Vue || !api || !core) {
        throw new Error('AigwCore / AigwApi 未加载，用户端 store 无法初始化');
    }

    var fmt = core.fmt;

    /** 本端固定身份 */
    var MODE = 'user';

    var state = Vue.reactive({
        // 认证（用户端只使用 user 字段，admin 恒为 null）
        admin: null,
        user: null,
        role: '',            // '' 未登录 | 'user' 已登录（用户端不会出现 'admin'）
        booting: true,

        // 路由
        route: 'dashboard',
        params: {},

        // 侧栏
        sidebarOpen: false,

        // 全局 loading 遮罩（按 key 计数）
        busy: {},

        // 缓存
        settings: null,
        providers: [],
        poolOverview: null,

        // 站点品牌（后台可改；启动时从 /api/site-config 拉取，未取到用兜底值）
        brand: { name: '', subtitle: '', desc: '' },

        // 弹窗公告（后台可配；公开接口 /api/site-config 下发）
        //   enabled  总开关；target 显示位置（all|register|dashboard）
        //   version  版本号：管理员改动内容后自动 +1，前端据此判断「是否已读过」
        notice: { enabled: false, title: '', content: '', type: 'info', target: 'all', version: 0 },
        noticeOpen: false,        // 弹窗是否正在显示
        noticeShownTarget: '',    // 本次弹出对应的位置（关闭时按它记录已读）

        // 注册相关公开开关（来自 /api/site-config）
        allowRegister: true,
        mailVerify: false,

        // 跨页跳转：待打开的供应商登录弹窗
        pendingLoginProvider: '',

        // 主题：light | dark
        theme: 'light',

        // 计数器
        version: '1.0.0'
    });

    /* ── Toast ──────────────────────────────────────────── */

    var toasts = Vue.reactive([]);
    var toastSeq = 0;

    function toast(message, type, title) {
        if (!message) return;
        var id = ++toastSeq;
        toasts.push({
            id: id,
            message: String(message),
            type: type || 'info',
            title: title || { success: '操作成功', error: '操作失败', warning: '请注意', info: '' }[type || 'info'] || ''
        });
        setTimeout(function () {
            var idx = toasts.findIndex(function (t) { return t.id === id; });
            if (idx !== -1) toasts.splice(idx, 1);
        }, type === 'error' ? 5200 : 3200);
    }

    function dismissToast(id) {
        var idx = toasts.findIndex(function (t) { return t.id === id; });
        if (idx !== -1) toasts.splice(idx, 1);
    }

    /** 把一次 API 结果转成 Toast（成功/失败通吃） */
    function report(res, successMessage) {
        if (res && res.ok) {
            toast(successMessage || res.message || '操作成功', 'success');
            return true;
        }
        toast((res && (res.error || res.message)) || '操作失败', 'error');
        return false;
    }

    /* ── 路由（history 模式） ─────────────────────────────── */

    /** 本端的 history 基路径：用户端控制台挂在站点根，故为空串 */
    var BASE = core.baseFor(MODE);

    function pagesFor(role) { return core.pagesFor(MODE); }
    function canAccess(role, id) { return core.canAccess(MODE, id); }
    function pageMeta(id) { return core.pageMeta(id); }
    function roleLabel(role) { return core.roleLabel(MODE); }

    function parseRoute() {
        return core.resolveRoute(MODE, BASE);
    }

    /** 当前页面 id 的规范路径（用于 replaceState / 分享链接） */
    function canonicalPath() {
        var r = parseRoute();
        return core.buildPath(r.id, r.params, BASE);
    }

    /**
     * 页面跳转（history 模式，URL 不带 #）。
     * 同路径不重复入栈；opts.replace = true 走 replaceState（不新增历史条目）。
     * 极端情况下（file:// 或 history 被禁用）回退到 hash，保证功能不丢。
     */
    function navigate(id, params, opts) {
        var target = core.buildPath(id, params, BASE);
        try {
            if (opts && opts.replace) {
                history.replaceState({ aigw: true }, '', target);
            } else if (location.pathname + location.search !== target) {
                history.pushState({ aigw: true }, '', target);
            }
        } catch (e) {
            location.hash = core.buildHash(id, params);
        }
        state.sidebarOpen = false;
        applyRoute();
    }

    function applyRoute() {
        var r = parseRoute();
        state.route = r.id;
        state.params = r.params;
        applyTitle();
    }

    /* ── 站点品牌 ───────────────────────────────────────── */
    var BRAND_FALLBACK = { name: 'AI 聚合网关', subtitle: 'Aggregator', desc: '' };

    /** 品牌名（供 shell / 登录页直接取用） */
    function brandName() {
        return state.brand.name || BRAND_FALLBACK.name;
    }

    function brandSubtitle() {
        return state.brand.subtitle || BRAND_FALLBACK.subtitle;
    }

    /** 浏览器标题：页面名 + 站点名（未登录时只显示站点名） */
    function applyTitle() {
        var name = brandName();
        if (state.booting || !state.role) {
            document.title = name;
            return;
        }
        var meta = core.pageMeta(state.route);
        document.title = (meta && meta.title ? meta.title + ' · ' : '') + name;
    }

    /**
     * 拉取站点品牌与注册开关（公开接口，未登录也能取）。
     * 失败不阻断启动，仅保持兜底值。
     */
    function loadBrand() {
        return api.siteConfig().then(function (res) {
            if (res && res.ok && res.data) {
                var d = res.data;
                state.brand = {
                    name: String(d.app_name || ''),
                    subtitle: String(d.app_subtitle || ''),
                    desc: String(d.app_desc || '')
                };
                state.allowRegister = d.allow_register !== false;
                state.mailVerify = !!d.mail_verify;
                // 弹窗公告（公开接口下发；关闭时后端不返回标题与正文）
                var n = d.notice || {};
                state.notice = {
                    enabled: !!n.enabled,
                    title: String(n.title || ''),
                    content: String(n.content || ''),
                    type: String(n.type || 'info'),
                    target: String(n.target || 'all'),
                    version: Number(n.version) || 0,
                    // 「跳转加群」按钮（后台可控群号）
                    group_enabled: !!n.group_enabled,
                    group_qq: String(n.group_qq || ''),
                    group_url: String(n.group_url || '')
                };
            }
            applyTitle();
            return state.brand;
        }).catch(function () {
            applyTitle();
            return state.brand;
        });
    }

    /* ── 弹窗公告（注册页 / 总览面板） ───────────────────── */

    /** localStorage 里记录「某位置已读到的公告版本」的键前缀 */
    var NOTICE_KEY = 'aigw.notice.seen';

    /**
     * 是否应该在某位置弹出公告。
     * @param {string} target 'register' | 'dashboard'
     * @returns {boolean} 是否已弹出
     */
    function maybeShowNotice(target) {
        var n = state.notice || {};
        if (!n.enabled) return false;
        // 位置匹配：all 两处都弹；否则只弹指定位置
        if (n.target !== 'all' && n.target !== target) return false;
        // 标题与正文都为空时没有任何可展示的内容，直接跳过
        if (!String(n.title || '').trim() && !String(n.content || '').trim()) return false;

        // 同一位置 + 同一版本只弹一次：已读就不再打扰。
        // 管理员改动内容 → 后端把 version +1 → 版本对不上 → 重新弹一次。
        var key = NOTICE_KEY + '.' + target;
        var seen = '';
        try { seen = localStorage.getItem(key) || ''; } catch (e) {}
        if (seen === String(n.version)) return false;

        state.noticeShownTarget = target;
        state.noticeOpen = true;
        return true;
    }

    /** 关闭公告并记录已读（下次同一版本不再弹） */
    function closeNotice() {
        var t = state.noticeShownTarget || 'register';
        try {
            localStorage.setItem(NOTICE_KEY + '.' + t, String((state.notice || {}).version || 0));
        } catch (e) {}
        state.noticeOpen = false;
    }

    /**
     * 把旧 hash 链接就地替换为 clean path（不新增历史条目）：
     *   /console#/personas → /personas
     * 固定入口别名（/console、/admin）保持原样，本身就是无 # 的干净地址。
     * 查询串（如 ?register=1）原样保留。
     */
    function migrateUrl() {
        if (!core.needsHashMigration()) return false;
        var canonical = canonicalPath();
        try {
            history.replaceState({ aigw: true }, '', canonical + (location.search || ''));
            return true;
        } catch (e) {
            return false;
        }
    }

    /** 可分享的绝对链接 */
    function linkFor(id, params) {
        return location.origin + core.buildPath(id, params, BASE);
    }

    /* ── 主题 ───────────────────────────────────────────── */

    function applyTheme(name) {
        var t = name === 'dark' ? 'dark' : 'light';
        state.theme = t;
        core.writeTheme(t);
        return t;
    }

    function setTheme(name) { return applyTheme(name); }
    function toggleTheme() { return applyTheme(state.theme === 'dark' ? 'light' : 'dark'); }
    function initTheme() { applyTheme(core.readTheme()); }

    /* ── 启动 ───────────────────────────────────────────── */

    /** 建立会话（用户端只接受 'user'，其余值一律按用户处理） */
    function setSession(role, profile) {
        state.role = 'user';
        state.user = profile || {};
        state.admin = null;
        if (api.setRole) api.setRole('user');
        return 'user';
    }

    /** 清空会话（退出 / 失效） */
    function clearSession() {
        state.admin = null;
        state.user = null;
        state.role = '';
        if (api.setRole) api.setRole('user');
    }

    function boot() {
        initTheme();
        window.addEventListener('popstate', applyRoute);
        // 兼容旧 hash 链接（#/personas）：hash 变化时同样重新解析
        window.addEventListener('hashchange', applyRoute);
        migrateUrl();
        applyRoute();

        // 站点品牌 + 注册开关：公开接口，与会话探测并行拉取
        var brandReady = loadBrand();

        api.setUnauthorizedHandler(function () {
            // 引导阶段的 401 属正常探测结果，不清会话、不弹提示
            if (state.booting) return;
            if (state.user) {
                clearSession();
                toast('登录状态已失效，请重新登录', 'warning');
            }
        });

        // 用户端只探测用户会话，绝不探测管理员会话
        return brandReady.then(function () {
            return api.user.me();
        }).then(function (res) {
            if (res.ok && res.data) {
                return setSession('user', res.data.user || res.data);
            }
            clearSession();
            return '';
        }).then(function (who) {
            state.booting = false;
            migrateUrl();
            applyRoute();
            return who;
        });
    }

    global.AigwStore = {
        mode: MODE,
        base: BASE,
        state: state,
        toasts: toasts,
        fmt: fmt,
        PAGES: core.PAGES,
        pageMeta: pageMeta,
        pagesFor: pagesFor,
        canAccess: canAccess,
        roleLabel: roleLabel,
        setSession: setSession,
        clearSession: clearSession,
        providerTone: core.providerTone,
        palette: core.palette,
        toast: toast,
        dismissToast: dismissToast,
        report: report,
        navigate: navigate,
        linkFor: linkFor,
        brandName: brandName,
        brandSubtitle: brandSubtitle,
        applyTitle: applyTitle,
        reloadBrand: loadBrand,
        maybeShowNotice: maybeShowNotice,
        closeNotice: closeNotice,
        setTheme: setTheme,
        toggleTheme: toggleTheme,
        boot: boot
    };
})(window);