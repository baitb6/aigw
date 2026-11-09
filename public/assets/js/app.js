/* ============================================================
   用户端入口外壳（/）—— 账号端控制台
   仅承载普通用户身份：登录页为「登录 / 注册」，不出现管理员入口。
   管理员请访问 /admin（admin.html + app-admin.js）。
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
        loginPage: 'page-login',

        onLogout: function (role, store) {
            global.AigwConfirm('退出后需要重新登录才能访问控制台，确定继续吗？', {
                title: '退出登录',
                confirmText: '退出'
            }).then(function (yes) {
                if (!yes) return;
                global.AigwApi.user.logout().then(function () {
                    store.clearSession();
                    store.toast('已安全退出', 'success');
                    store.navigate('dashboard');
                });
            });
        }
    });
})(window);