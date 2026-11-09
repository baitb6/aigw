/* ============================================================
   共用外壳：侧栏导航 + 顶栏 + 页面路由挂载（组件工厂）
   ------------------------------------------------------------
   用户端与后台端各自的入口（app.js / app-admin.js）调用
   AigwShell.create(config) 取得外壳定义，只需指定：
     - brandName / brandSub：左上角品牌与副标题
     - loginPage：未登录时渲染的登录组件名
     - onLogout：退出登录动作（调用各自的端接口并清会话）
   ============================================================ */
(function (global) {
    'use strict';

    var Vue = global.Vue;
    var S = global.AigwStore;

    function create(config) {
        config = config || {};
        var loginPage = config.loginPage || 'page-login';

        var App = {
            data: function () {
                return { st: S.state };
            },

            computed: {
                loggedIn: function () {
                    return !!(this.st.admin || this.st.user);
                },

                groups: function () {
                    var out = [], index = {};
                    S.pagesFor(this.st.role).forEach(function (p) {
                        if (!index[p.group]) {
                            index[p.group] = { name: p.group, items: [] };
                            out.push(index[p.group]);
                        }
                        index[p.group].items.push(p);
                    });
                    return out;
                },

                meta: function () {
                    return S.pageMeta(this.st.route);
                },

                currentPage: function () {
                    var name = 'Page' + this.st.route.charAt(0).toUpperCase() + this.st.route.slice(1);
                    if (global.AigwPages[name]) return name;
                    var home = S.homeFor(this.st.role);
                    return 'Page' + home.charAt(0).toUpperCase() + home.slice(1);
                },

                accountName: function () {
                    var a = this.st.user || this.st.admin || {};
                    return a.nickname || a.username || (this.st.role === 'user' ? '用户' : '管理员');
                },

                accountInitial: function () {
                    return (this.accountName || 'A').trim().charAt(0).toUpperCase();
                },

                roleLabel: function () {
                    return S.roleLabel(this.st.role);
                },

                brandName: function () {
                    // 优先取后台可配置的站点名称（/api/site-config 下发），其次端内配置，最后兜底
                    var dyn = (S.brandName && S.brandName()) || (this.st.brand && this.st.brand.name);
                    return dyn || config.brandName || 'AI 聚合网关';
                },

                brandSub: function () {
                    // 副标题语义 = 本端标识（用户端无配置 → 用后台可改的副标题兜底）
                    if (config.brandSub) return config.brandSub;
                    var dyn = (S.brandSubtitle && S.brandSubtitle()) || (this.st.brand && this.st.brand.subtitle);
                    return dyn || ('v' + this.version);
                },

                version: function () {
                    return (this.st.settings && this.st.settings.version) || this.st.version || '1.0.0';
                },

                isDark: function () {
                    return this.st.theme === 'dark';
                }
            },

            methods: {
                go: function (id) {
                    S.navigate(id);
                    this.closeSidebar();
                },

                isActive: function (id) {
                    return this.st.route === id;
                },

                toggleSidebar: function () {
                    this.st.sidebarOpen = !this.st.sidebarOpen;
                },

                closeSidebar: function () {
                    this.st.sidebarOpen = false;
                },

                toggleTheme: function () {
                    S.toggleTheme();
                },

                logout: function () {
                    if (typeof config.onLogout === 'function') {
                        config.onLogout(this.st.role, S);
                    }
                }
            },

            template: [
                '<div class="shell" :class="{ \'shell--guest\': !loggedIn }">',

                /* ── 侧栏 ─────────────────────────────────── */
                '<aside class="sidebar" :class="{ \'is-open\': st.sidebarOpen }">',
                '  <div class="brand">',
                '    <div class="brand__mark"><aigw-icon name="layers" :size="18" /></div>',
                '    <div class="brand__text">',
                '      <div class="brand__name">{{ brandName }}</div>',
                '      <div class="brand__sub">{{ brandSub }}</div>',
                '    </div>',
                '  </div>',

                '  <nav class="nav">',
                '    <template v-for="g in groups" :key="g.name">',
                '      <p class="nav__group">{{ g.name }}</p>',
                '      <button v-for="p in g.items" :key="p.id" type="button"',
                '              class="nav__item" :class="{ \'is-active\': isActive(p.id) }"',
                '              @click="go(p.id)">',
                '        <aigw-icon :name="p.icon" :size="18" />',
                '        <span class="nav__label">{{ p.title }}</span>',
                '      </button>',
                '    </template>',
                '  </nav>',

                '  <div class="sidebar__foot">',
                '    <a class="text-faint" href="/v1/health" target="_blank" rel="noopener">健康检查</a>',
                '    <span> · OpenAI 兼容</span>',
                '  </div>',
                '</aside>',

                '<div class="scrim" :class="{ \'is-open\': st.sidebarOpen }" @click="closeSidebar"></div>',

                /* ── 主区 ─────────────────────────────────── */
                '<div class="main">',

                '  <header class="topbar" v-if="loggedIn">',
                '    <button class="menu-toggle" type="button" @click="toggleSidebar" aria-label="打开导航"><aigw-icon name="menu" :size="19" /></button>',
                '    <div class="topbar__head">',
                '      <div class="topbar__title truncate">{{ meta.title }}</div>',
                '      <div class="topbar__desc truncate">{{ meta.desc }}</div>',
                '    </div>',
                '    <div class="topbar__actions">',
                '      <span class="badge badge--muted topbar__role">{{ roleLabel }}</span>',
                '      <span class="badge badge--muted topbar__user">',
                '        <aigw-icon name="profile" :size="13" /><span class="topbar__user-name">{{ accountName }}</span>',
                '      </span>',
                '      <div class="topbar__group">',
                '        <button class="btn btn--icon btn--sm" type="button" @click="toggleTheme" :title="isDark ? \'浅色模式\' : \'深色模式\'" :aria-label="isDark ? \'浅色模式\' : \'深色模式\'">',
                '          <aigw-icon :name="isDark ? \'sun\' : \'moon\'" :size="17" />',
                '        </button>',
                '        <button class="btn btn--icon btn--sm" type="button" @click="logout" title="退出登录" aria-label="退出登录">',
                '          <aigw-icon name="logout" :size="16" />',
                '        </button>',
                '      </div>',
                '    </div>',
                '  </header>',

                '  <main class="content">',
                '    <div v-if="st.booting" class="loading">',
                '      <span class="spinner"></span>',
                '      <span>正在载入控制台…</span>',
                '    </div>',
                '    <component v-else-if="!loggedIn" :is="\'' + loginPage + '\'"></component>',
                '    <component v-else :is="currentPage"></component>',
                '  </main>',

                '</div>',

                '<aigw-toasts></aigw-toasts>',
                '<aigw-confirm></aigw-confirm>',
                // 弹窗公告：仅用户端 store 提供 notice 状态，管理端自然不显示
                '<aigw-notice></aigw-notice>',
                '</div>'
            ].join('\n')
        };

        return App;
    }

    /** 标准挂载流程：创建应用、注入全局、注册组件、挂载、引导会话 */
    function mount(config) {
        config = config || {};

        if (!Vue || !S) {
            document.getElementById('app').innerHTML =
                '<div class="loading"><p>前端资源加载失败，请刷新页面重试。</p></div>';
            return null;
        }

        var app = Vue.createApp(create(config));

        // 全局注入格式化工具：所有模板可直接使用 fmt.xxx()
        app.config.globalProperties.fmt = S.fmt;

        if (global.AigwInstallIcons) global.AigwInstallIcons(app);
        if (global.AigwInstallComponents) global.AigwInstallComponents(app);

        Object.keys(global.AigwPages || {}).forEach(function (name) {
            app.component(name, global.AigwPages[name]);
        });

        app.mount('#app');

        S.boot();

        return app;
    }

    global.AigwShell = { create: create, mount: mount };
})(window);