/* ============================================================
   页面组件（续 5）：用户行为审计
   —— 「谁在什么时候对什么做了什么、结果如何、从哪来」
   ============================================================ */
(function (global) {
    'use strict';

    var S = global.AigwStore;
    var api = global.AigwApi;
    var fmt = S.fmt;
    var pages = {};

    pages.PageAudit = {
        data: function () {
            return {
                loading: true, busy: false, error: '',
                items: [], total: 0, page: 1, pages: 1, perPage: 20,
                filters: {
                    keyword: '', category: '', action: '', actor_type: '',
                    status: '', user_id: '', since: '', until: ''
                },
                stats: null,
                options: { categories: [], actions: [] },
                days: 7,
                showDetail: false, detail: null
            };
        },
        computed: {
            pageList: function () {
                var out = [], start = Math.max(1, this.page - 2);
                var end = Math.min(this.pages, start + 4);
                start = Math.max(1, end - 4);
                for (var i = start; i <= end; i++) out.push(i);
                return out;
            },
            routeUser: function () {
                if (S.state.route !== 'audit') return '';
                var p = S.state.params || {};
                return p.id ? String(p.id) : '';
            },
            overall: function () { return (this.stats && this.stats.overall) || {}; },
            topActions: function () { return (this.stats && this.stats.top_actions) || []; },
            byCategory: function () { return (this.stats && this.stats.by_category) || []; },
            viewTitle: function () {
                return this.filters.user_id ? ('用户 #' + this.filters.user_id + ' 的行为记录') : '用户行为审计';
            }
        },
        mounted: function () {
            if (this.routeUser) {
                this.filters.user_id = this.routeUser;
            }
            this.load();
        },
        methods: {
            load: function () {
                var self = this;
                this.loading = true;
                var q = {
                    keyword: this.filters.keyword,
                    category: this.filters.category,
                    action: this.filters.action,
                    actor_type: this.filters.actor_type,
                    status: this.filters.status,
                    user_id: this.filters.user_id,
                    since: this.filters.since,
                    until: this.filters.until,
                    days: this.days,
                    page: this.page,
                    per_page: this.perPage
                };
                api.scoped.audit(q).then(function (res) {
                    self.loading = false;
                    if (!res.ok) { self.error = res.error || '加载失败'; return; }
                    self.error = '';
                    var d = res.data || {};
                    self.items = d.items || [];
                    self.total = d.total || 0;
                    self.page = d.page || 1;
                    self.pages = d.pages || 1;
                    self.stats = d.stats || null;
                    self.options = d.options || { categories: [], actions: [] };
                });
            },
            search: function () { this.page = 1; this.load(); },
            resetFilters: function () {
                this.filters = {
                    keyword: '', category: '', action: '', actor_type: '',
                    status: '', user_id: '', since: '', until: ''
                };
                this.page = 1;
                this.load();
            },
            goPage: function (p) {
                if (p < 1 || p > this.pages || p === this.page) return;
                this.page = p;
                this.load();
            },
            changeDays: function () { this.page = 1; this.load(); },
            openDetail: function (row) {
                this.detail = row;
                this.showDetail = true;
            },
            statusTone: function (row) {
                var st = Number(row.status || 0);
                if (st >= 500) return 'danger';
                if (st >= 400) return 'warning';
                return 'success';
            },
            statusLabel: function (row) {
                var st = Number(row.status || 0);
                return st >= 400 ? ('失败 ' + st) : '成功';
            },
            actorTone: function (type) {
                if (type === 'admin') return 'primary';
                if (type === 'user') return 'celadon';
                if (type === 'guest') return 'rust';
                return 'muted';
            },
            entityLabel: function (row) {
                if (!row.target_type) return '—';
                return row.target_type + (row.target_id ? (' #' + row.target_id) : '');
            },
            detailText: function (row) {
                if (!row || !row.detail) return '（无明细）';
                try {
                    return JSON.stringify(row.detail, null, 2);
                } catch (e) {
                    return String(row.detail);
                }
            },
            exportCsv: function () {
                var self = this;
                this.busy = true;
                api.scoped.exportAudit({
                    keyword: this.filters.keyword,
                    category: this.filters.category,
                    action: this.filters.action,
                    actor_type: this.filters.actor_type,
                    status: this.filters.status,
                    user_id: this.filters.user_id,
                    since: this.filters.since,
                    until: this.filters.until
                }).then(function (ok) {
                    self.busy = false;
                    S.toast(ok ? '导出已开始' : '导出失败', ok ? 'success' : 'error');
                });
            },
            prune: function () {
                var self = this;
                global.AigwConfirm('将删除 90 天以前的审计记录，不可恢复。', {
                    title: '清理旧审计', danger: true, confirmText: '清理'
                }).then(function (yes) {
                    if (!yes) return;
                    self.busy = true;
                    api.admin.pruneAudit(90).then(function (res) {
                        self.busy = false;
                        if (S.report(res)) self.load();
                    });
                });
            },
            copyDetail: function (row) {
                var text = this.detailText(row);
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        S.toast('已复制明细', 'success');
                    }).catch(function () { S.toast('复制失败', 'warning'); });
                } else {
                    S.toast('复制失败，请手动选择', 'warning');
                }
            }
        },
        template:
            '<div>' +
              '<div class="page-head">' +
                '<div class="page-head__main">' +
                  '<div class="topbar__title">{{ viewTitle }}</div>' +
                  '<div class="topbar__desc">共 {{ fmt.num(total) }} 条行为记录</div>' +
                '</div>' +
                '<div class="page-head__actions">' +
                  '<button type="button" class="btn btn--sm" :disabled="loading" @click="load">刷新</button>' +
                  '<button type="button" class="btn btn--sm" :disabled="busy" @click="exportCsv">导出 CSV</button>' +
                  '<button type="button" class="btn btn--sm btn--danger" :disabled="busy" @click="prune">清理旧记录</button>' +
                '</div>' +
              '</div>' +

              '<div class="grid grid--stats mt-16">' +
                '<aigw-stat label="事件总数" :value="fmt.num(overall.events)" tone="primary" icon="logs" />' +
                '<aigw-stat label="今日事件" :value="fmt.num(overall.today)" tone="celadon" icon="check" />' +
                '<aigw-stat label="涉及用户" :value="fmt.num(overall.users)" tone="celadon" icon="users" />' +
                '<aigw-stat label="失败 / 被拒" :value="fmt.num(overall.failed)" tone="rust" icon="warning" />' +
              '</div>' +

              '<div class="card mt-16"><div class="card__body">' +
                '<div class="grid grid--2">' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">关键词（摘要 / 动作 / 操作者 / 对象 / 明细）</label>' +
                    '<input class="input" v-model="filters.keyword" placeholder="输入后回车搜索" @keyup.enter="search">' +
                  '</div>' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">归属用户 ID</label>' +
                    '<input class="input mono" v-model="filters.user_id" placeholder="留空 = 全部用户" @keyup.enter="search">' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">分类</label>' +
                    '<select class="select" v-model="filters.category" @change="search">' +
                      '<option value="">全部分类</option>' +
                      '<option v-for="c in options.categories" :key="c" :value="c">{{ c }}</option>' +
                    '</select>' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">动作</label>' +
                    '<select class="select" v-model="filters.action" @change="search">' +
                      '<option value="">全部动作</option>' +
                      '<option v-for="a in options.actions" :key="a.action" :value="a.action">{{ a.action }}</option>' +
                    '</select>' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">操作者身份</label>' +
                    '<select class="select" v-model="filters.actor_type" @change="search">' +
                      '<option value="">全部身份</option>' +
                      '<option value="user">普通用户</option>' +
                      '<option value="admin">管理员</option>' +
                      '<option value="guest">未登录访客</option>' +
                      '<option value="system">系统</option>' +
                    '</select>' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">结果</label>' +
                    '<select class="select" v-model="filters.status" @change="search">' +
                      '<option value="">全部结果</option>' +
                      '<option value="ok">成功</option>' +
                      '<option value="failed">失败 / 被拒</option>' +
                    '</select>' +
                  '</div>' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">开始时间</label>' +
                    '<input class="input" type="datetime-local" v-model="filters.since" @change="search">' +
                  '</div>' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">结束时间</label>' +
                    '<input class="input" type="datetime-local" v-model="filters.until" @change="search">' +
                  '</div>' +
                '</div>' +
                '<div class="field mt-16 mb-0">' +
                  '<div class="field__row">' +
                    '<button type="button" class="btn btn--primary" @click="search">查询</button>' +
                    '<button type="button" class="btn" @click="resetFilters">重置</button>' +
                    '<span class="text-faint" style="font-size:12.5px;margin-left:8px">统计区间</span>' +
                    '<select class="select" style="width:auto" v-model.number="days" @change="changeDays">' +
                      '<option :value="1">近 1 天</option>' +
                      '<option :value="7">近 7 天</option>' +
                      '<option :value="30">近 30 天</option>' +
                      '<option :value="90">近 90 天</option>' +
                    '</select>' +
                  '</div>' +
                '</div>' +
              '</div></div>' +

              '<aigw-card title="行为流水" sub="每一条都是一次真实操作：登录、改密、开关思维、增删账号、管理员管控……" flush class="mt-16">' +
                '<div v-if="loading"><aigw-loading /></div>' +
                '<div v-else-if="error">' +
                  '<aigw-empty icon="warning" title="加载失败" :desc="error" />' +
                '</div>' +
                '<div v-else-if="!items.length">' +
                  '<aigw-empty icon="logs" title="暂无行为记录" desc="当前筛选条件下没有匹配的操作事件" />' +
                '</div>' +
                '<div v-else class="table__wrap">' +
                  '<table class="table">' +
                    '<thead><tr>' +
                      '<th>时间</th><th>归属用户</th><th>身份 / 操作者</th>' +
                      '<th>动作</th><th>摘要</th><th>来源 IP</th>' +
                      '<th>结果</th><th style="text-align:right">操作</th>' +
                    '</tr></thead>' +
                    '<tbody>' +
                      '<tr v-for="r in items" :key="r.id">' +
                        '<td class="text-soft nowrap">{{ fmt.time(r.created_at) }}</td>' +
                        '<td class="truncate">{{ r.user_label || ("用户 #" + r.user_id) }}</td>' +
                        '<td class="nowrap">' +
                          '<aigw-badge :tone="actorTone(r.actor_type)" dot>{{ r.actor_label || r.actor_type }}</aigw-badge> ' +
                          '<span class="text-faint" style="font-size:12px">{{ r.actor_name || "" }}</span>' +
                        '</td>' +
                        '<td class="nowrap">' +
                          '<div class="truncate">{{ r.action_label || r.action }}</div>' +
                          '<div class="text-faint mono" style="font-size:11.5px">{{ r.category_label || r.category }}</div>' +
                        '</td>' +
                        '<td class="truncate">{{ r.summary || "—" }}</td>' +
                        '<td class="mono text-soft nowrap">{{ r.ip || "—" }}</td>' +
                        '<td><aigw-badge :tone="statusTone(r)" dot>{{ statusLabel(r) }}</aigw-badge></td>' +
                        '<td style="text-align:right" class="nowrap">' +
                          '<button type="button" class="btn btn--sm" @click="openDetail(r)">详情</button>' +
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

              '<div class="grid grid--2 mt-16">' +
                '<aigw-card title="热门动作" sub="所选统计区间内的事件排行">' +
                  '<div v-if="!topActions.length" class="text-faint" style="font-size:12.5px">区间内暂无事件</div>' +
                  '<div v-else class="table__wrap">' +
                    '<table class="table"><thead><tr>' +
                      '<th>动作</th><th style="text-align:right">次数</th><th style="text-align:right">失败</th>' +
                    '</tr></thead><tbody>' +
                      '<tr v-for="a in topActions" :key="a.action">' +
                        '<td class="truncate">{{ a.action }}</td>' +
                        '<td style="text-align:right" class="text-soft">{{ fmt.num(a.events) }}</td>' +
                        '<td style="text-align:right" class="text-soft">{{ fmt.num(a.failed) }}</td>' +
                      '</tr>' +
                    '</tbody></table>' +
                  '</div>' +
                '</aigw-card>' +
                '<aigw-card title="分类分布" sub="按行为类别聚合">' +
                  '<div v-if="!byCategory.length" class="text-faint" style="font-size:12.5px">区间内暂无事件</div>' +
                  '<div v-else class="table__wrap">' +
                    '<table class="table"><thead><tr>' +
                      '<th>分类</th><th style="text-align:right">事件</th><th style="text-align:right">失败</th>' +
                    '</tr></thead><tbody>' +
                      '<tr v-for="c in byCategory" :key="c.category">' +
                        '<td class="truncate">{{ c.category || "其他" }}</td>' +
                        '<td style="text-align:right" class="text-soft">{{ fmt.num(c.events) }}</td>' +
                        '<td style="text-align:right" class="text-soft">{{ fmt.num(c.failed) }}</td>' +
                      '</tr>' +
                    '</tbody></table>' +
                  '</div>' +
                '</aigw-card>' +
              '</div>' +

              '<aigw-modal v-if="showDetail && detail" title="行为详情" wide hide-foot @close="showDetail = false">' +
                '<div class="grid grid--2">' +
                  '<div class="field"><label class="field__label">时间</label>' +
                    '<input class="input" :value="fmt.time(detail.created_at)" readonly></div>' +
                  '<div class="field"><label class="field__label">结果</label>' +
                    '<input class="input" :value="statusLabel(detail)" readonly></div>' +
                  '<div class="field"><label class="field__label">归属用户</label>' +
                    '<input class="input" :value="detail.user_label || (detail.user_id > 0 ? (\'用户 #\' + detail.user_id) : \'全局 / 系统\')" readonly></div>' +
                  '<div class="field"><label class="field__label">操作者</label>' +
                    '<input class="input" :value="(detail.actor_label || detail.actor_type) + (detail.actor_name ? \' · \' + detail.actor_name : \'\')" readonly></div>' +
                  '<div class="field"><label class="field__label">动作</label>' +
                    '<input class="input mono" :value="detail.action" readonly></div>' +
                  '<div class="field"><label class="field__label">分类</label>' +
                    '<input class="input" :value="detail.category_label || detail.category" readonly></div>' +
                  '<div class="field"><label class="field__label">对象</label>' +
                    '<input class="input mono" :value="entityLabel(detail)" readonly></div>' +
                  '<div class="field"><label class="field__label">来源 IP</label>' +
                    '<input class="input mono" :value="detail.ip || \'—\'" readonly></div>' +
                  '<div class="field"><label class="field__label">请求方法与路径</label>' +
                    '<input class="input mono" :value="(detail.method || \'\') + \' \' + (detail.path || \'\')" readonly></div>' +
                  '<div class="field"><label class="field__label">User-Agent</label>' +
                    '<input class="input mono" :value="detail.ua || \'—\'" readonly></div>' +
                '</div>' +
                '<div class="field mt-16">' +
                  '<label class="field__label">摘要</label>' +
                  '<input class="input" :value="detail.summary || \'—\'" readonly>' +
                '</div>' +
                '<div class="mt-16">' +
                  '<div class="field__row" style="justify-content:space-between">' +
                    '<label class="field__label" style="margin:0">明细（JSON）</label>' +
                    '<button type="button" class="btn btn--sm" @click="copyDetail(detail)">复制</button>' +
                  '</div>' +
                  '<pre class="mono" style="margin-top:8px;padding:12px;border:1px solid var(--border);border-radius:8px;max-height:320px;overflow:auto;font-size:12.5px;white-space:pre-wrap;word-break:break-all">{{ detailText(detail) }}</pre>' +
                '</div>' +
              '</aigw-modal>' +
            '</div>'
    };

    global.AigwPagesRegister(pages);
})(window);
