/* ============================================================
   后台端入口外壳（/admin）—— 管理端控制台
   仅承载管理员身份：登录页为「管理员登录」，不出现注册/普通用户入口。
   用户端在 /（index.html + app.js）。
   ============================================================ */
(function (global) {
    'use strict';

    var S = global.AigwStore;

    if (!global.AigwShell || !S) {
        document.getElementById('app').innerHTML =
            '<div class="loading"><p>前端资源加载失败，请刷新页面重试。</p></div>';
        return;
    }

    global.AigwShell.mount({
        brandName: 'AI 聚合网关',
        brandSub: '管理后台',
        loginPage: 'page-admin-login',

        onLogout: function (role, store) {
            global.AigwConfirm('退出后需要重新登录才能进入后台，确定继续吗？', {
                title: '退出登录',
                confirmText: '退出'
            }).then(function (yes) {
                if (!yes) return;
                global.AigwApi.admin.logout().then(function () {
                    store.clearSession();
                    store.toast('已安全退出后台', 'success');
                    store.navigate('users');
                });
            });
        }
    });
})(window);