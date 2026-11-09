/* ============================================================
   SVG 图标组件
   ------------------------------------------------------------
   全站统一使用线性矢量图标，不使用 emoji。
   同时兼容旧的字符字形（◈ ◉ ⬡ ☰ ◫ ⚙ ⇄ ☺ 等），
   旧模板中的字形会自动映射为对应图标，平滑迁移。
   ============================================================ */
(function (global) {
    'use strict';

    var Vue = global.Vue;

    /* ── 图标路径表（24×24 · 线性描边） ─────────────────── */

    var ICONS = {
        /* 导航 */
        dashboard: ['M4 4h6.5v6.5H4z', 'M13.5 4H20v6.5h-6.5z', 'M4 13.5h6.5V20H4z', 'M13.5 13.5H20V20h-6.5z'],
        accounts:  ['M16 20v-1.4a3.6 3.6 0 0 0-3.6-3.6H7.6A3.6 3.6 0 0 0 4 18.6V20', 'M10 11.2a3.1 3.1 0 1 0 0-6.2 3.1 3.1 0 0 0 0 6.2', 'M20 20v-1.4a3.6 3.6 0 0 0-2.7-3.48', 'M15.2 5.1a3.1 3.1 0 0 1 0 6'],
        providers: ['M12 3l7.5 4.4v9.2L12 21l-7.5-4.4V7.4z', 'M12 12l7.5-4.4M12 12v9M12 12L4.5 7.6'],
        models:    ['M4 6h16', 'M4 12h16', 'M4 18h9'],
        prompts:   ['M5 4h14v11H9l-4 4z', 'M8.5 8h7M8.5 11h4.5'],
        logs:      ['M7 3h7l5 5v13H7z', 'M14 3v5h5', 'M10 13h6M10 17h6'],
        settings:  ['M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', 'M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z'],
        proxy:     ['M3 8h14l-3-3', 'M21 16H7l3 3'],
        profile:   ['M12 12.5a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'M4.5 20a7.5 7.5 0 0 1 15 0'],
        users:     ['M9 12a3.2 3.2 0 1 0 0-6.4A3.2 3.2 0 0 0 9 12z', 'M3.5 19v-1.3A4.2 4.2 0 0 1 7.7 13.5h2.6a4.2 4.2 0 0 1 4.2 4.2V19', 'M16.2 5.6a3.2 3.2 0 0 1 0 6.2', 'M17.6 13.6a4.2 4.2 0 0 1 3 4.1V19'],

        /* 通用操作 */
        menu:      ['M4 7h16', 'M4 12h16', 'M4 17h16'],
        close:     ['M6 6l12 12', 'M18 6L6 18'],
        check:     ['M5 13l4 4L19 7'],
        plus:      ['M12 5v14', 'M5 12h14'],
        minus:     ['M5 12h14'],
        chevronDown: ['M6 9.5l6 6 6-6'],
        chevronUp:   ['M6 14.5l6-6 6 6'],
        search:    ['M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14z', 'M20.5 20.5l-4-4'],
        filter:    ['M3 5h18l-7 8.4V20l-4-2.2v-4.4z'],
        refresh:   ['M20 11a8 8 0 1 0-.6 4', 'M20 5v6h-6'],
        external:  ['M14 4h6v6', 'M20 4l-8.5 8.5', 'M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5'],
        copy:      ['M9 9h10v11H9z', 'M5 15V4h11'],
        edit:      ['M4 20h4L19 9l-4-4L4 16z', 'M14 6l4 4'],
        trash:     ['M5 7h14', 'M9 7V5h6v2', 'M7 7l1 13h8l1-13', 'M10 11v6M14 11v6'],
        eye:       ['M2.5 12S6 6 12 6s9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z'],
        key:       ['M15 9a3 3 0 1 0-4.2 2.8L4 18.6V21h3v-2h2v-2h2l1.3-1.3A3 3 0 0 0 15 9z', 'M15.6 8.4h.01'],
        logout:    ['M15 12H4', 'M8 8l-4 4 4 4', 'M14 4h5a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-5'],
        download:  ['M12 4v11', 'M8 11l4 4 4-4', 'M4 20h16'],
        upload:    ['M12 20V9', 'M8 13l4-4 4 4', 'M4 4h16'],
        calendar:  ['M4 6h16v14H4z', 'M8 3v4M16 3v4', 'M4 10h16'],
        link:      ['M9.5 14.5l5-5', 'M8 16H6a4 4 0 0 1 0-8h2', 'M16 8h2a4 4 0 0 1 0 8h-2'],

        /* 状态与语义 */
        empty:     ['M4 13h4l1.8 3h4.4L16 13h4', 'M4 13l2-7h12l2 7v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z'],
        warning:   ['M12 4l9 16H3z', 'M12 10v4', 'M12 17.2h.01'],
        info:      ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 11v5', 'M12 8h.01'],
        money:     ['M7 5l5 6 5-6', 'M12 11v8', 'M8 13h8M8 16h8'],
        timer:     ['M12 21a8 8 0 1 0 0-16 8 8 0 0 0 0 16z', 'M12 9.5V13l2.6 1.6', 'M9 2.5h6'],
        clock:     ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 7.5V12l3 1.8'],
        bolt:      ['M13 3L5 13h6l-1 8 8-10h-6z'],
        shield:    ['M12 3l7 3v6c0 4-3 7.2-7 9-4-1.8-7-5-7-9V6z'],
        flame:     ['M12 3c3 4 5 6.4 5 10a5 5 0 0 1-10 0c0-2 1-3.2 2-4.2', 'M12 21a3 3 0 0 0 3-3c0-2-3-3.5-3-6-1 1.5-3 3-3 6a3 3 0 0 0 3 3z'],
        server:    ['M4 4h16v6H4z', 'M4 14h16v6H4z', 'M8 7h.01', 'M8 17h.01'],
        globe:     ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M3 12h18', 'M12 3c2.6 3 2.6 15 0 18', 'M12 3c-2.6 3-2.6 15 0 18'],
        cpu:       ['M9 9h6v6H9z', 'M6 6h12v12H6z', 'M9 2.5v3M15 2.5v3M9 18.5v3M15 18.5v3', 'M2.5 9h3M2.5 15h3M18.5 9h3M18.5 15h3'],
        bell:      ['M18 16v-5a6 6 0 1 0-12 0v5l-2 2.2h16z', 'M10 20.5a2 2 0 0 0 4 0'],

        /* 图表 */
        trend:     ['M3 16.5l5-5.5 4 3.5 7-8', 'M15 6.5h4v4'],
        chart:     ['M3 20h18', 'M6 20V11M11 20V5M16 20v-6M21 20v-9'],
        activity:  ['M3 12h4l2.5 6.5L14 5l2.5 7H21'],
        gauge:     ['M4 18a8 8 0 1 1 16 0', 'M12 18l3-5'],
        layers:    ['M12 3l8 4.5-8 4.5-8-4.5z', 'M4 12.5l8 4.5 8-4.5', 'M4 16.5l8 4.5 8-4.5'],
        pie:       ['M12 3a9 9 0 1 0 9 9h-9z', 'M12 3v9h9A9 9 0 0 0 12 3z'],
        spark:     ['M12 3v4M12 17v4M3 12h4M17 12h4', 'M6 6l2.5 2.5M15.5 15.5L18 18M18 6l-2.5 2.5M8.5 15.5L6 18'],

        /* 主题 */
        moon:      ['M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z'],
        sun:       ['M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10z', 'M12 2v2M12 20v2', 'M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4', 'M2 12h2M20 12h2', 'M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4'],

        /* 品牌 */
        leaf:      ['M5 19c0-7 5-11 14-12 0 9-5.5 13-13 13H5z', 'M5 19c1-3.2 3.2-5.4 6.5-6.5']
    };

    /* ── 兼容旧字符字形 ─────────────────────────────────── */

    var GLYPHS = {
        '\u25C8': 'dashboard',   // ◈
        '\u25C9': 'accounts',    // ◉
        '\u2B21': 'providers',   // ⬡
        '\u2630': 'models',      // ☰
        '\u25EB': 'logs',        // ◫
        '\u2699': 'settings',    // ⚙
        '\u21C4': 'proxy',       // ⇄
        '\u263A': 'profile',     // ☺
        '\u2715': 'close',       // ✕
        '\u2716': 'close',       // ✖
        '\u25CC': 'empty',       // ◌
        '\u2713': 'check',       // ✓
        '\u2714': 'check',       // ✔
        '\u26A0': 'warning',     // ⚠
        '\u00A5': 'money',       // ¥
        '\u23F1': 'timer',       // ⏱
        '\u263E': 'moon',        // ☾
        '\uD83C\uDF3F': 'leaf',  // 🌿
        '\u2197': 'external'     // ↗
    };

    function resolve(name) {
        var n = String(name == null ? '' : name).trim();
        if (!n) return '';
        if (Object.prototype.hasOwnProperty.call(ICONS, n)) return n;
        if (GLYPHS[n]) return GLYPHS[n];
        return '';
    }

    /* ── 图标组件 ───────────────────────────────────────── */

    var AigwIcon = {
        props: {
            name: String,
            size: { type: [Number, String], default: 18 },
            stroke: { type: [Number, String], default: 1.7 }
        },
        computed: {
            resolved: function () { return resolve(this.name); },
            known: function () { return !!this.resolved; },
            px: function () { return String(this.size); },
            inner: function () {
                var paths = ICONS[this.resolved] || [];
                return paths.map(function (d) { return '<path d="' + d + '"/>'; }).join('');
            }
        },
        template:
            '<svg v-if="known" class="ic" :width="px" :height="px" viewBox="0 0 24 24" fill="none"' +
                ' stroke="currentColor" :stroke-width="stroke" stroke-linecap="round"' +
                ' stroke-linejoin="round" aria-hidden="true" focusable="false" v-html="inner"></svg>' +
            '<span v-else-if="name" class="ic ic--text" aria-hidden="true">{{ name }}</span>'
    };

    global.AigwIconComponent = AigwIcon;

    global.AigwIcons = {
        ICONS: ICONS,
        GLYPHS: GLYPHS,
        has: function (name) { return !!resolve(name); },
        resolve: resolve,
        names: function () { return Object.keys(ICONS); }
    };

    if (Vue && Vue.createApp) {
        global.AigwInstallIcons = function (app) {
            app.component('AigwIcon', AigwIcon);
        };
    }
})(window);
