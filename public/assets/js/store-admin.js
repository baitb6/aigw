/* ============================================================
   后台端状态：只认管理员身份（后台端）
   ------------------------------------------------------------
   与用户端（store.js）完全独立：本文件只会以 'admin' 身份引导
   会话，接口请求固定走 /api/admin/*；入口为 /admin。
   ============================================================ */
(function (global) {
    'use strict';

    var Vue = global.Vue;
    var api = global.AigwApi;
    var core = global.AigwCore;

    if (!Vue || !api || !core) {
        throw new Error('AigwCore / AigwApi 未加载，后台端 store 无法初始化');
    }

    var fmt = core.fmt;

    /** 本端固定身份 */
    var MODE = 'admin';

    var state = Vue.reactive({
        // 认证（后台端只使用 admin 字段，user 恒为 null）
        admin: null,
        user: null,
        role: '',            // '' 未登录 | 'admin' 已登录（后台端不会出现 'user'）
        booting: true,

        // 路由
        route: 'users',
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

        // 注册相关公开开关（来自 /api/site-config）
        allowRegister: true,
        mailVerify: false,

        // 跨页跳转：待打开的供应商登录弹窗
        pendingLoginProvider: '',

        // 主题：light | dark（与用户端共用同一偏好键）
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

    /** 本端的 history 基路径：管理后台挂在 /admin 之下 */
    var BASE = core.baseFor(MODE);

    function pagesFor(role) { return core.pagesFor(MODE); }
    function canAccess(role, id) { return core.canAccess(MODE, id); }
    function pageMeta(id) { return core.pageMeta(id, MODE); }
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
     * 极端情况下（history 被禁用）回退到 hash，保证功能不丢。
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

    /**
     * 把旧 hash 链接就地替换为 clean path（不新增历史条目）：
     *   /admin#/users → /admin/users
     * 固定入口别名（/admin）保持原样，本身就是无 # 的干净地址。
     * 查询串原样保留。
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

    /* ── 站点品牌 ───────────────────────────────────────── */

    var BRAND_FALLBACK = { name: 'AI 聚合网关', subtitle: '管理后台', desc: '' };

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
            document.title = name + ' · 管理后台';
            return;
        }
        var meta = core.pageMeta(state.route, MODE);
        document.title = (meta && meta.title ? meta.title + ' · ' : '') + name;
    }

    /**
     * 拉取站点品牌（公开接口，未登录也能取）。
     * 后台与用户端同源同开关：后台改了站点名称，这里刷新即生效。
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
            }
            applyTitle();
            return state.brand;
        }).catch(function () {
            applyTitle();
            return state.brand;
        });
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

    /** 建立会话（后台端只接受 'admin'，其余值一律按管理员处理） */
    function setSession(role, profile) {
        state.role = 'admin';
        state.admin = profile || {};
        state.user = null;
        if (api.setRole) api.setRole('admin');
        return 'admin';
    }

    /** 清空会话（退出 / 失效） */
    function clearSession() {
        state.admin = null;
        state.user = null;
        state.role = '';
        if (api.setRole) api.setRole('admin');
    }

    function boot() {
        initTheme();
        window.addEventListener('popstate', applyRoute);
        // 兼容旧 hash 链接（#/users）：hash 变化时同样重新解析
        window.addEventListener('hashchange', applyRoute);
        migrateUrl();
        applyRoute();

        // 站点品牌：公开接口，与会话探测并行拉取（后台改的站点名称在这里生效）
        var brandReady = loadBrand();

        api.setUnauthorizedHandler(function () {
            // 引导阶段的 401 属正常探测结果，不清会话、不弹提示
            if (state.booting) return;
            if (state.admin) {
                clearSession();
                toast('登录状态已失效，请重新登录', 'warning');
            }
        });

        // 后台端只探测管理员会话，绝不探测用户会话
        return brandReady.then(function () {
            return api.admin.me();
        }).then(function (res) {
            if (res.ok && res.data) {
                return setSession('admin', res.data.admin || res.data);
            }
            clearSession();
            return '';
        }).then(function (who) {
            state.booting = false;
            migrateUrl();
            applyRoute();
            applyTitle();
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
        setTheme: setTheme,
        toggleTheme: toggleTheme,
        boot: boot
    };
})(window);