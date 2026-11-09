/* ============================================================
   页面组件（续 4）：用户管理
   ============================================================ */
(function (global) {
    'use strict';

    var S = global.AigwStore;
    var api = global.AigwApi;
    var fmt = S.fmt;
    var pages = {};

    pages.PageUsers = {
        data: function () {
            return {
                loading: true, busy: false, error: '',
                items: [], total: 0, page: 1, pages: 1, perPage: 20,
                filters: { keyword: '', status: '' },
                summary: { total: 0, active: 0, disabled: 0 },
                showDetail: false, detail: null,
                detailAccounts: [], detailLogs: [], detailLoading: false,
                showEdit: false, edit: { id: 0, nickname: '', email: '', status: 'ACTIVE' },
                showKey: false, keyValue: ''
            };
        },
        computed: {
            pageList: function () {
                var out = [], start = Math.max(1, this.page - 2);
                var end = Math.min(this.pages, start + 4);
                start = Math.max(1, end - 4);
                for (var i = start; i <= end; i++) out.push(i);
                return out;
            }
        },
        mounted: function () { this.load(); },
        methods: {
            load: function () {
                var self = this;
                this.loading = true;
                api.admin.users({
                    keyword: this.filters.keyword,
                    status: this.filters.status,
                    page: this.page,
                    per_page: this.perPage
                }).then(function (res) {
                    self.loading = false;
                    if (!res.ok) { self.error = res.error || '加载失败'; return; }
                    self.error = '';
                    var d = res.data || {};
                    self.items = d.items || [];
                    self.total = d.total || 0;
                    self.page = d.page || 1;
                    self.pages = d.pages || 1;
                    self.summary = d.summary || { total: 0, active: 0, disabled: 0 };
                });
            },
            search: function () { this.page = 1; this.load(); },
            resetFilters: function () {
                this.filters = { keyword: '', status: '' };
                this.page = 1;
                this.load();
            },
            goPage: function (p) {
                if (p < 1 || p > this.pages || p === this.page) return;
                this.page = p;
                this.load();
            },
            statusTone: function (status) {
                return String(status) === 'ACTIVE' ? 'success' : 'danger';
            },
            statusLabel: function (status) {
                return String(status) === 'ACTIVE' ? '已启用' : '已禁用';
            },
            openDetail: function (row) {
                var self = this;
                this.busy = true;
                this.detailAccounts = [];
                this.detailLogs = [];
                api.admin.user(row.id).then(function (res) {
                    self.busy = false;
                    if (!res.ok) { S.toast(res.error || '加载详情失败', 'error'); return; }
                    self.detail = res.data || row;
                    self.showDetail = true;
                    self.loadDetailRelations(row.id);
                });
            },
            loadDetailRelations: function (userId) {
                var self = this;
                this.detailLoading = true;
                Promise.all([
                    api.admin.accounts({ user_id: userId }),
                    api.admin.logs({ user_id: userId, page: 1, per_page: 6 })
                ]).then(function (out) {
                    self.detailLoading = false;
                    self.detailAccounts = (out[0].ok && out[0].data && out[0].data.items) || [];
                    self.detailLogs = (out[1].ok && out[1].data && out[1].data.items) || [];
                }).catch(function () { self.detailLoading = false; });
            },
            logTokens: function (row) {
                return (Number(row.prompt_tokens) || 0) + (Number(row.completion_tokens) || 0);
            },
            logCost: function (row) {
                if (row.cost !== undefined && row.cost !== null) return Number(row.cost);
                return this.logTokens(row) / 1000;
            },
            logTone: function (row) {
                if (row.success) return 'success';
                return Number(row.status_code || 0) === 429 ? 'warning' : 'danger';
            },
            viewAccounts: function (row) {
                if (!row) return;
                this.showDetail = false;
                S.navigate('accounts', { id: row.id });
            },
            openEdit: function (row) {
                this.edit = {
                    id: row.id,
                    nickname: row.nickname || '',
                    email: row.email || '',
                    status: String(row.status || 'ACTIVE')
                };
                this.showEdit = true;
            },
            saveEdit: function () {
                var self = this;
                this.busy = true;
                api.admin.updateUser(this.edit.id, {
                    nickname: this.edit.nickname,
                    email: this.edit.email,
                    status: this.edit.status
                }).then(function (res) {
                    self.busy = false;
                    if (S.report(res)) { self.showEdit = false; self.load(); }
                });
            },
            toggle: function (row) {
                var self = this;
                var enable = String(row.status) !== 'ACTIVE';
                global.AigwConfirm(
                    enable ? '确定启用该用户？' : '禁用后该用户的 API Key 将立即失效，确定继续？',
                    { title: enable ? '启用用户' : '禁用用户', danger: !enable, confirmText: enable ? '启用' : '禁用' }
                ).then(function (yes) {
                    if (!yes) return;
                    self.busy = true;
                    api.admin.toggleUser(row.id).then(function (res) {
                        self.busy = false;
                        if (S.report(res)) self.load();
                    });
                });
            },
            resetKey: function (row) {
                var self = this;
                global.AigwConfirm('重置后旧 API Key 立即失效，确定继续？', {
                    title: '重置 API Key', danger: true, confirmText: '重置'
                }).then(function (yes) {
                    if (!yes) return;
                    self.busy = true;
                    api.admin.regenerateUserKey(row.id).then(function (res) {
                        self.busy = false;
                        if (!res.ok) { S.report(res); return; }
                        self.keyValue = (res.data && res.data.api_key) || '';
                        self.showKey = true;
                        self.load();
                    });
                });
            },
            viewLogs: function (row) {
                if (!row) return;
                this.showDetail = false;
                S.navigate('logs', { id: row.id });
            },
            remove: function (row) {
                var self = this;
                global.AigwConfirm('将删除该用户，并级联清理其归属账号与调用日志，不可恢复。', {
                    title: '删除用户', danger: true, confirmText: '删除'
                }).then(function (yes) {
                    if (!yes) return;
                    self.busy = true;
                    api.admin.deleteUser(row.id).then(function (res) {
                        self.busy = false;
                        if (S.report(res)) self.load();
                    });
                });
            },
            copyText: function (text) {
                if (!text) return;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        S.toast('已复制到剪贴板', 'success');
                    }).catch(function () { S.toast('复制失败，请手动选择复制', 'warning'); });
                } else {
                    S.toast('复制失败，请手动选择复制', 'warning');
                }
            }
        },
        template:
            '<div>' +
              '<div class="page-head">' +
                '<div class="page-head__main">' +
                  '<div class="topbar__title">用户管理</div>' +
                  '<div class="topbar__desc">共 {{ fmt.num(total) }} 位注册用户</div>' +
                '</div>' +
                '<div class="page-head__actions">' +
                  '<button type="button" class="btn btn--sm" :disabled="loading" @click="load">刷新</button>' +
                '</div>' +
              '</div>' +

              '<div class="grid grid--stats mt-16">' +
                '<aigw-stat label="用户总数" :value="fmt.num(summary.total)" tone="primary" icon="users" />' +
                '<aigw-stat label="已启用" :value="fmt.num(summary.active)" tone="celadon" icon="check" />' +
                '<aigw-stat label="已禁用" :value="fmt.num(summary.disabled)" tone="rust" icon="warning" />' +
              '</div>' +

              '<div class="card mt-16"><div class="card__body">' +
                '<div class="grid grid--2">' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">关键词（ID / 邮箱 / 昵称 / 用户名）</label>' +
                    '<input class="input" v-model="filters.keyword" placeholder="输入 ID 或邮箱后回车搜索" @keyup.enter="search">' +
                  '</div>' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">状态</label>' +
                    '<select class="select" v-model="filters.status" @change="search">' +
                      '<option value="">全部状态</option>' +
                      '<option value="ACTIVE">已启用</option>' +
                      '<option value="DISABLED">已禁用</option>' +
                    '</select>' +
                  '</div>' +
                '</div>' +
                '<div class="field mt-16 mb-0">' +
                  '<div class="field__row">' +
                    '<button type="button" class="btn btn--primary" @click="search">查询</button>' +
                    '<button type="button" class="btn" @click="resetFilters">重置</button>' +
                  '</div>' +
                '</div>' +
              '</div></div>' +

              '<aigw-card title="用户列表" sub="注册用户、归属账号与调用次数" flush class="mt-16">' +
                '<div v-if="loading"><aigw-loading /></div>' +
                '<div v-else-if="error">' +
                  '<aigw-empty icon="warning" title="加载失败" :desc="error" />' +
                '</div>' +
                '<div v-else-if="!items.length">' +
                  '<aigw-empty icon="users" title="暂无用户" desc="尚未有用户注册，或筛选条件下无结果" />' +
                '</div>' +
                '<div v-else class="table__wrap">' +
                  '<table class="table">' +
                    '<thead><tr>' +
                      '<th>ID</th><th>用户</th><th>用户名</th><th>状态</th>' +
                      '<th style="text-align:right">归属账号</th>' +
                      '<th style="text-align:right">调用次数</th>' +
                      '<th>最近登录</th><th style="text-align:right">操作</th>' +
                    '</tr></thead>' +
                    '<tbody>' +
                      '<tr v-for="u in items" :key="u.id">' +
                        /* 用户 ID 是排查调用日志/审计/账号的钥匙，必须一眼可见且可复制 */
                        '<td class="mono text-soft nowrap" style="cursor:pointer"' +
                            ' title="点击复制用户 ID" @click="copyText(String(u.id))">#{{ u.id }}</td>' +
                        '<td>' +
                          '<div class="flex items-center gap-8">' +
                            '<aigw-avatar :name="u.nickname || u.username || u.email" :size="32" />' +
                            '<div>' +
                              '<div class="truncate">{{ u.nickname || u.username || ("用户 #" + u.id) }}</div>' +
                              '<div class="text-faint" style="font-size:12px">{{ u.email || "—" }}</div>' +
                            '</div>' +
                          '</div>' +
                        '</td>' +
                        '<td class="mono text-soft nowrap">{{ u.username || "—" }}</td>' +
                        '<td>' +
                          '<aigw-badge :tone="statusTone(u.status)" dot>{{ statusLabel(u.status) }}</aigw-badge>' +
                        '</td>' +
                        '<td style="text-align:right" class="text-soft">{{ fmt.num(u.account_count || 0) }}</td>' +
                        '<td style="text-align:right" class="text-soft">{{ fmt.num(u.call_count || 0) }}</td>' +
                        '<td class="text-soft nowrap">{{ fmt.ago(u.last_login_at) }}</td>' +
                        '<td style="text-align:right" class="nowrap">' +
                          '<button type="button" class="btn btn--sm" @click="viewLogs(u)"' +
                                  ' title="在调用日志页只看该用户的请求">日志</button> ' +
                          '<button type="button" class="btn btn--sm" @click="openDetail(u)">详情</button> ' +
                          '<button type="button" class="btn btn--sm" @click="openEdit(u)">编辑</button> ' +
                          '<button type="button" class="btn btn--sm" @click="toggle(u)">' +
                            '{{ String(u.status) === "ACTIVE" ? "禁用" : "启用" }}' +
                          '</button> ' +
                          '<button type="button" class="btn btn--sm btn--danger" @click="remove(u)">删除</button>' +
                        '</td>' +
                      '</tr>' +
                    '</tbody>' +
                  '</table>' +
                '</div>' +

                '<div v-if="pages > 1" class="card__body" style="border-top:1px solid var(--border)">' +
                  '<div class="flex items-center justify-between">' +
                    '<span class="text-faint" style="font-size:12.5px">' +
                      '第 {{ page }} / {{ pages }} 页 · 共 {{ fmt.num(total) }} 条' +
                    '</span>' +
                    '<div class="wrap">' +
                      '<button type="button" class="btn btn--sm" :disabled="page <= 1" @click="goPage(page - 1)">上一页</button>' +
                      '<button v-for="p in pageList" :key="p" type="button" class="btn btn--sm"' +
                              ' :class="{ \'btn--primary\': p === page }" @click="goPage(p)">{{ p }}</button>' +
                      '<button type="button" class="btn btn--sm" :disabled="page >= pages" @click="goPage(page + 1)">下一页</button>' +
                    '</div>' +
                  '</div>' +
                '</div>' +
              '</aigw-card>' +

              '<aigw-modal v-if="showDetail && detail" title="用户详情" wide hide-foot @close="showDetail = false">' +
                '<div class="grid grid--2">' +
                  '<div class="field"><label class="field__label">用户 ID</label>' +
                    '<div class="field__row">' +
                      '<input class="input mono" :value="detail.id" readonly>' +
                      '<button type="button" class="btn" @click="copyText(String(detail.id))">复制</button>' +
                    '</div></div>' +
                  '<div class="field"><label class="field__label">昵称</label>' +
                    '<input class="input" :value="detail.nickname || \'—\'" readonly></div>' +
                  '<div class="field"><label class="field__label">邮箱</label>' +
                    '<input class="input" :value="detail.email || \'—\'" readonly></div>' +
                  '<div class="field"><label class="field__label">用户名</label>' +
                    '<input class="input mono" :value="detail.username || \'—\'" readonly></div>' +
                  '<div class="field"><label class="field__label">状态</label>' +
                    '<input class="input" :value="statusLabel(detail.status)" readonly></div>' +
                  '<div class="field"><label class="field__label">归属账号</label>' +
                    '<input class="input" :value="fmt.num(detail.account_count || 0)" readonly></div>' +
                  '<div class="field"><label class="field__label">调用次数</label>' +
                    '<input class="input" :value="fmt.num(detail.call_count || 0)" readonly></div>' +
                  '<div class="field"><label class="field__label">最近登录</label>' +
                    '<input class="input" :value="fmt.time(detail.last_login_at)" readonly></div>' +
                  '<div class="field"><label class="field__label">最近登录 IP</label>' +
                    '<input class="input mono" :value="detail.last_login_ip || \'—\'" readonly></div>' +
                  '<div class="field"><label class="field__label">注册时间</label>' +
                    '<input class="input" :value="fmt.time(detail.created_at)" readonly></div>' +
                '</div>' +
                '<div class="field mt-16">' +
                  '<label class="field__label">用户 API Key</label>' +
                  '<div class="field__row">' +
                    '<input class="input mono" :value="detail.api_key || \'—\'" readonly>' +
                    '<button type="button" class="btn" @click="copyText(detail.api_key)">复制</button>' +
                  '</div>' +
                '</div>' +

                /* ── 归属账号（内联预览） ── */
                '<div class="mt-16">' +
                  '<div class="field__row" style="justify-content:space-between">' +
                    '<label class="field__label" style="margin:0">归属账号（{{ fmt.num(detail.account_count || 0) }}）</label>' +
                    '<button type="button" class="btn btn--sm" @click="viewAccounts(detail)">查看全部账号</button>' +
                  '</div>' +
                  '<div v-if="detailLoading" class="mt-8"><aigw-loading /></div>' +
                  '<div v-else-if="!detailAccounts.length" class="text-faint mt-8" style="font-size:12.5px">该用户暂无归属账号</div>' +
                  '<div v-else class="table__wrap mt-8">' +
                    '<table class="table"><thead><tr>' +
                      '<th>昵称</th><th>供应商</th><th>账号 ID</th><th>状态</th>' +
                    '</tr></thead><tbody>' +
                      '<tr v-for="a in detailAccounts" :key="a.id">' +
                        '<td class="truncate">{{ a.nickname || "—" }}</td>' +
                        '<td class="text-soft nowrap">{{ a.provider_name || a.provider_key }}</td>' +
                        '<td class="mono truncate text-soft">{{ a.account_uid || "—" }}</td>' +
                        '<td><aigw-badge :tone="(a.state && a.state.tone) || \'muted\'" dot>' +
                          '{{ (a.state && a.state.label) || a.status }}</aigw-badge></td>' +
                      '</tr>' +
                    '</tbody></table>' +
                  '</div>' +
                '</div>' +

                /* ── 最近调用日志（内联预览） ── */
                '<div class="mt-16">' +
                  '<div class="field__row" style="justify-content:space-between">' +
                    '<label class="field__label" style="margin:0">最近调用日志（{{ fmt.num(detail.call_count || 0) }}）</label>' +
                    '<button type="button" class="btn btn--sm" @click="viewLogs(detail)">查看调用日志</button>' +
                  '</div>' +
                  '<div v-if="detailLoading" class="mt-8"><aigw-loading /></div>' +
                  '<div v-else-if="!detailLogs.length" class="text-faint mt-8" style="font-size:12.5px">该用户暂无调用记录</div>' +
                  '<div v-else class="table__wrap mt-8">' +
                    '<table class="table"><thead><tr>' +
                      '<th>时间</th><th>模型</th><th>结果</th>' +
                      '<th style="text-align:right">Token</th><th style="text-align:right">费用</th>' +
                    '</tr></thead><tbody>' +
                      '<tr v-for="l in detailLogs" :key="l.id">' +
                        '<td class="text-soft nowrap">{{ fmt.time(l.created_at) }}</td>' +
                        '<td class="mono truncate">{{ l.model || "—" }}</td>' +
                        '<td><aigw-badge :tone="logTone(l)" dot>{{ l.success ? "成功" : "失败" }}</aigw-badge></td>' +
                        '<td style="text-align:right" class="text-soft">{{ fmt.num(logTokens(l)) }}</td>' +
                        '<td style="text-align:right" class="text-soft">{{ fmt.num(logCost(l), 4) }}</td>' +
                      '</tr>' +
                    '</tbody></table>' +
                  '</div>' +
                '</div>' +

                '<div class="mt-16 flex items-center">' +
                  '<button type="button" class="btn btn--danger" @click="resetKey(detail)">重置 API Key</button>' +
                '</div>' +
              '</aigw-modal>' +

              '<aigw-modal v-if="showEdit" title="编辑用户" :busy="busy" confirm-text="保存"' +
                          ' @close="showEdit = false" @confirm="saveEdit">' +
                '<div class="field">' +
                  '<label class="field__label">昵称</label>' +
                  '<input class="input" v-model="edit.nickname" placeholder="显示名称">' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">邮箱</label>' +
                  '<input class="input" v-model="edit.email" placeholder="登录邮箱">' +
                '</div>' +
                '<div class="field mb-0">' +
                  '<label class="field__label">状态</label>' +
                  '<select class="select" v-model="edit.status">' +
                    '<option value="ACTIVE">启用</option>' +
                    '<option value="DISABLED">禁用</option>' +
                  '</select>' +
                '</div>' +
              '</aigw-modal>' +

              '<aigw-modal v-if="showKey" title="新的用户 API Key" hide-foot @close="showKey = false">' +
                '<p class="text-soft">请立即复制保存，该 Key 用于调用网关，关闭后不再完整展示。</p>' +
                '<div class="field__row mt-8">' +
                  '<input class="input mono" :value="keyValue" readonly>' +
                  '<button type="button" class="btn btn--primary" @click="copyText(keyValue)">复制</button>' +
                '</div>' +
              '</aigw-modal>' +
            '</div>'
    };

    global.AigwPagesRegister(pages);
})(window);
