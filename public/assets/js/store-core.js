/* ============================================================
   共用基础：格式化工具 + 页面清单 + 路由解析 + 主题 + Toast
   ------------------------------------------------------------
   本文件被「用户端 store.js」「后台端 store-admin.js」共同依赖，
   只提供与登录身份无关的公共能力，不直接暴露到 window.AigwStore。
   ============================================================ */
(function (global) {
    'use strict';

    var Vue = global.Vue;

    /* ── 工具函数 ───────────────────────────────────────── */

    var fmt = {
        /** 解析时间戳（秒/毫秒）或 'YYYY-MM-DD HH:MM:SS' 字符串为毫秒；无法解析返回 NaN */
        toMs: function (v) {
            if (v === null || v === undefined || v === '') return NaN;
            if (typeof v === 'string' && /^\d{4}-\d{2}-\d{2}/.test(v)) {
                var d = new Date(v.replace(/-/g, '/'));
                return d.getTime();
            }
            var n = Number(v);
            if (!isFinite(n) || n <= 0) return NaN;
            if (n < 1e11) n *= 1000;
            return n;
        },

        /** 数字千分位 */
        num: function (v, digits) {
            var n = Number(v);
            if (!isFinite(n)) return '—';
            var d = typeof digits === 'number' ? digits : 0;
            var s = Math.abs(n).toFixed(d);
            var parts = s.split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            return (n < 0 ? '-' : '') + parts.join('.');
        },

        /** 大数压缩：12345 → 12.3k */
        compact: function (v) {
            var n = Number(v);
            if (!isFinite(n)) return '—';
            if (Math.abs(n) >= 1e9) return (n / 1e9).toFixed(1) + 'B';
            if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(1) + 'M';
            if (Math.abs(n) >= 1e4) return (n / 1e3).toFixed(1) + 'k';
            return fmt.num(n);
        },

        /** 百分比 */
        pct: function (v, digits) {
            var n = Number(v);
            if (!isFinite(n)) return '—';
            return n.toFixed(typeof digits === 'number' ? digits : 1) + '%';
        },

        /** 秒 → 人读时长 */
        duration: function (ms) {
            var n = Number(ms);
            if (!isFinite(n) || n <= 0) return '—';
            if (n < 1000) return Math.round(n) + 'ms';
            if (n < 60000) return (n / 1000).toFixed(1) + 's';
            return Math.floor(n / 60000) + 'm' + Math.round((n % 60000) / 1000) + 's';
        },

        /** 秒数 → 倒计时文本 */
        countdown: function (seconds) {
            var s = Math.max(0, Math.floor(Number(seconds) || 0));
            if (!s) return '已结束';
            var h = Math.floor(s / 3600),
                m = Math.floor((s % 3600) / 60),
                sec = s % 60;
            if (h > 0) return h + ' 小时 ' + m + ' 分';
            if (m > 0) return m + ' 分 ' + sec + ' 秒';
            return sec + ' 秒';
        },

        /** 时间戳（秒或毫秒）或日期字符串 → 本地时间 */
        time: function (v) {
            var n = fmt.toMs(v);
            if (!isFinite(n)) return '—';
            var d = new Date(n);
            if (isNaN(d.getTime())) return '—';
            var p = function (x) { return x < 10 ? '0' + x : String(x); };
            return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
                + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
        },

        /** 相对时间 */
        ago: function (v) {
            var n = fmt.toMs(v);
            if (!isFinite(n)) return '从未';
            var diff = Math.floor((Date.now() - n) / 1000);
            if (diff < 0) diff = 0;
            if (diff < 60) return diff + ' 秒前';
            if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前';
            if (diff < 86400) return Math.floor(diff / 3600) + ' 小时前';
            if (diff < 2592000) return Math.floor(diff / 86400) + ' 天前';
            return fmt.time(n);
        },

        /** 日期标签 MM-DD */
        dayLabel: function (v) {
            if (!v) return '';
            var s = String(v);
            var m = s.match(/(\d{4})-(\d{2})-(\d{2})/);
            if (m) return m[2] + '-' + m[3];
            return s.slice(-5);
        }
    };

    /** 供应商配色：纯色、无渐变，与中性蓝主色区分 */
    var TONES = ['primary', 'amber', 'celadon', 'rust', 'plum', 'clay'];

    var TONE_COLORS = {
        primary: '#c2683c',
        amber:   '#c6912a',
        celadon: '#3d8f6f',
        rust:    '#b8503f',
        plum:    '#93496a',
        clay:    '#7d675a'
    };

    /** 图表色板：纯色顺序色，图表与图例统一取用 */
    var PALETTE = ['#2563eb', '#e0762d', '#2f9e6e', '#d99a1c', '#cf5744', '#0f766e', '#a4557e', '#64748b'];

    var providerTone = {
        /** 稳定的字符串 → 色板索引 */
        _seed: function (str) {
            var h = 0, s = String(str || '');
            for (var i = 0; i < s.length; i++) {
                h = (h * 31 + s.charCodeAt(i)) % 100000;
            }
            return h;
        },
        key: function (name) {
            return TONES[providerTone._seed(name) % TONES.length];
        },
        /** 供应商主色（纯色，无渐变） */
        color: function (name) {
            return TONE_COLORS[providerTone.key(name)];
        },
        /** 供应商头像用色（实心背景） */
        avatar: function (name) {
            return providerTone.color(name);
        },
        /** 账号卡顶部色条 */
        line: function (name) {
            return providerTone.color(name);
        },
        /** 图表色板 */
        palette: PALETTE,
        colors: TONE_COLORS,
        /** 两字缩写 */
        initials: function (name) {
            var s = String(name || '').trim();
            if (!s) return '?';
            if (/^[\x00-\x7F]+$/.test(s)) {
                var parts = s.split(/[\s_\-]+/).filter(Boolean);
                if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
                return s.slice(0, 2).toUpperCase();
            }
            return s.slice(0, 2);
        }
    };

    /* ── 页面清单（全量，登录后按角色过滤） ───────────────── */

    // 职责划分：后台（/admin）只做运维管理——用户管理、供应商、网络代理；
    // 普通用户端（/）只做日常使用——总览、账号池、模型目录、调用日志、网关设置、我的账户。
    // 「供应商」为共享页：管理员可增删改测试，普通用户只读（数据脱敏且按用户隔离）。
    var PAGES = [
        { id: 'dashboard', title: '总览面板', desc: '账号池与调用概况', icon: 'dashboard', group: '监控', roles: ['user'] },
        { id: 'screens',   title: '数据大屏', desc: '调用量、注册登录与累计指标', icon: 'gauge', group: '监控', roles: ['admin'] },
        { id: 'accounts',  title: '账号池',   desc: '凭据、额度与冷却状态', icon: 'accounts', group: '资源', roles: ['user'] },
        { id: 'growth',    title: '成长中心', desc: '猫猫旅行与成长任务，可看可点', icon: 'flame', group: '资源', roles: ['admin', 'user'] },
        { id: 'providers', title: '供应商',   desc: '上游接入与适配器配置', icon: 'providers', group: '资源', roles: ['admin', 'user'] },
        { id: 'models',    title: '模型目录', desc: '对外暴露的模型清单', icon: 'models', group: '资源', roles: ['admin', 'user'] },
        { id: 'logs',      title: '调用日志', desc: '请求明细与统计分析', icon: 'logs', group: '监控', roles: ['admin', 'user'] },
        { id: 'audit',     title: '用户行为', desc: '登录、改密、开关与增删改全留痕', icon: 'logs', group: '监控', roles: ['admin'] },
        { id: 'users',     title: '用户管理', desc: '注册用户、状态与用量', icon: 'users', group: '系统', roles: ['admin'] },
        { id: 'personas',  title: '思维注入', desc: '网关侧改写系统思维', icon: 'flame', group: '系统', roles: ['admin', 'user'] },
        { id: 'settings',  title: '网关设置', desc: 'API Key、默认模型与限流', icon: 'settings', group: '系统', roles: ['admin', 'user'] },
        { id: 'proxy',     title: '网络代理', desc: '出站代理与连通性', icon: 'proxy', group: '系统', roles: ['admin'] },
        { id: 'profile',   title: '我的账户', desc: '资料、API Key 与密码', icon: 'profile', group: '系统', roles: ['user'] }
    ];

    /** 各身份的默认落地页（第一个可访问页面） */
    var HOME = { admin: 'users', user: 'dashboard' };

    function homeFor(role) {
        return HOME[role === 'user' ? 'user' : 'admin'];
    }

    function pageMeta(id, role) {
        for (var i = 0; i < PAGES.length; i++) {
            if (PAGES[i].id === id) return PAGES[i];
        }
        var home = homeFor(role);
        for (var j = 0; j < PAGES.length; j++) {
            if (PAGES[j].id === home) return PAGES[j];
        }
        return PAGES[0];
    }

    /** 按角色返回可见页面（未知角色按管理员处理） */
    function pagesFor(role) {
        var r = role === 'user' ? 'user' : 'admin';
        return PAGES.filter(function (p) {
            return !p.roles || p.roles.indexOf(r) !== -1;
        });
    }

    function canAccess(role, id) {
        var r = role === 'user' ? 'user' : 'admin';
        for (var i = 0; i < PAGES.length; i++) {
            if (PAGES[i].id === id) {
                return !PAGES[i].roles || PAGES[i].roles.indexOf(r) !== -1;
            }
        }
        return false;
    }

    function roleLabel(role) {
        return role === 'user' ? '普通用户' : '管理员';
    }

    /* ── 路由（history 模式：URL 里不再出现 #） ────────────────
       两端的基路径不同：
         · 用户端控制台  → ''        （/dashboard、/personas/80）
         · 管理后台      → '/admin'  （/admin/users、/admin/audit/5）
       保留旧 hash 链接（#/personas）的读取能力，便于老书签继续可用。 */

    /** 各端 history 基路径 */
    var BASES = { user: '', admin: '/admin' };

    function baseFor(role) {
        return role === 'user' ? BASES.user : BASES.admin;
    }

    /** 规范化基路径：去尾部斜杠；空串表示根 */
    function normalizeBase(base) {
        if (base === null || base === undefined) return '';
        var b = String(base).trim();
        if (b === '' || b === '/') return '';
        b = '/' + b.replace(/^\/+|\/+$/g, '');
        return b === '/' ? '' : b;
    }

    /** 站点固定入口（这些 pathname 不参与页面解析） */
    var FIXED_SEGMENTS = { console: 1, admin: 1, 'index.html': 1, 'admin.html': 1, 'home.html': 1 };

    /**
     * 解析当前 URL 对应的页面 id 与参数。
     * 优先读 clean path，读不到再回退旧 hash（老链接兼容）。
     *
     * @param {string} fallbackRole 当前端身份（user | admin）
     * @param {string} [base] 基路径，缺省按身份推断
     */
    function resolveRoute(fallbackRole, base) {
        var b = normalizeBase(base === undefined ? baseFor(fallbackRole) : base);
        var seg = [];

        // 1) clean path：先剥掉基路径，再按 / 切分
        var path = String(location.pathname || '/');
        if (b !== '' && (path === b || path.indexOf(b + '/') === 0)) {
            path = path.slice(b.length);
        }
        var raw = path.replace(/^\/+|\/+$/g, '');
        if (raw !== '') {
            seg = raw.split('/').filter(Boolean);
            // 兼容 /console/xxx 这种历史入口写法，剥掉首段的固定入口名
            if (seg.length && FIXED_SEGMENTS[seg[0]]) {
                seg.shift();
            }
        }

        // 2) clean path 没给出有效段时，回退旧 hash（#/personas）
        if (!seg.length) {
            var h = String(location.hash || '').replace(/^#\/?/, '');
            if (h) {
                seg = h.split('/').filter(Boolean);
                if (seg.length && FIXED_SEGMENTS[seg[0]]) seg.shift();
            }
        }

        var id = seg[0] || homeFor(fallbackRole);
        // 无权访问（或页面不存在）时回落到本端首页，并丢弃原参数，避免脏 id 透传
        if (!canAccess(fallbackRole, id)) {
            return { id: homeFor(fallbackRole), params: { id: '' } };
        }
        return { id: id, params: { id: seg[1] || '' } };
    }

    /**
     * 生成前端页面路径（不含 origin）。
     *   buildPath('dashboard', null, '')            → '/dashboard'
     *   buildPath('personas', {id:'80'}, '')        → '/personas/80'
     *   buildPath('audit', {id:'5'}, '/admin')      → '/admin/audit/5'
     */
    function buildPath(id, params, base) {
        var b = normalizeBase(base);
        var path = b + '/' + String(id || '');
        if (params && params.id) {
            path += '/' + encodeURIComponent(String(params.id));
        }
        return path;
    }

    /** 旧接口：保留 hash 形式（仅用于兼容，不再作为主路由） */
    function buildHash(id, params) {
        return '#/' + id + (params && params.id ? '/' + params.id : '');
    }

    /** 当前 URL 是否为「旧 hash 路由」，需要迁移成 clean path */
    function needsHashMigration() {
        var h = String(location.hash || '');
        return h !== '' && h !== '#' && /^#\/?/.test(h);
    }

    function isFixedSegment(name) {
        return !!FIXED_SEGMENTS[name];
    }


    /* ── 主题 ───────────────────────────────────────────── */

    var THEME_KEY = 'aigw.theme';

    function readTheme() {
        var saved = '';
        try { saved = localStorage.getItem(THEME_KEY) || ''; } catch (e) {}
        if (saved !== 'dark' && saved !== 'light') {
            saved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }
        return saved;
    }

    function writeTheme(t) {
        document.documentElement.setAttribute('data-theme', t);
        try { localStorage.setItem(THEME_KEY, t); } catch (e) {}
    }

    global.AigwCore = {
        fmt: fmt,
        PAGES: PAGES,
        pageMeta: pageMeta,
        pagesFor: pagesFor,
        canAccess: canAccess,
        homeFor: homeFor,
        roleLabel: roleLabel,
        resolveRoute: resolveRoute,
        buildPath: buildPath,
        buildHash: buildHash,
        baseFor: baseFor,
        normalizeBase: normalizeBase,
        needsHashMigration: needsHashMigration,
        isFixedSegment: isFixedSegment,
        providerTone: providerTone,
        palette: PALETTE,
        readTheme: readTheme,
        writeTheme: writeTheme
    };
})(window);
