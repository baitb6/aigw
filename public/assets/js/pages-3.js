/* ============================================================
   页面组件（续 2）：模型目录 / 调用日志
   ============================================================ */
(function (global) {
    'use strict';

    var S = global.AigwStore;
    var api = global.AigwApi;
    var fmt = S.fmt;
    var pages = {};

    /* ============================================================
       模型目录
       ============================================================ */
    pages.PageModels = {
        data: function () {
            return {
                loading: true, busy: false, error: '',
                groups: [], total: 0, enabled: 0, rated: 0, suppliers: [],
                keyword: '',
                expanded: {},
                // 分批渲染：折叠的分组不建 DOM；展开的分组一次只铺 renderStep 行，
                // 点「显示更多」按同一步长递增。几千个模型时必须分批，
                // 否则一次渲染上万节点会让页面直接卡死。
                renderStep: 100,
                limits: {},
                // 全量同步可能持续数十秒~数分钟：给出「同步中 N 秒」的活提示，
                // 否则按钮只是变灰，看起来就像卡住了。
                syncing: false, syncSeconds: 0, syncTimer: null,
                showAdd: false,
                form: { provider_key: '', model_id: '', display_name: '', enabled: true },
                showEdit: false, editing: null,
                editForm: { model_id: '', display_name: '', enabled: true, rate: '' }
            };
        },
        computed: {
            isAdmin: function () { return S.state.role !== 'user'; },
            filtered: function () {
                var kw = this.keyword.trim().toLowerCase();
                if (!kw) return this.groups;
                return this.groups.map(function (g) {
                    var models = (g.models || []).filter(function (m) {
                        var id = String(m.model_id || m.id || m.model || '').toLowerCase();
                        var nm = String(m.display_name || m.name || '').toLowerCase();
                        return id.indexOf(kw) !== -1 || nm.indexOf(kw) !== -1;
                    });
                    return {
                        provider_key: g.provider_key, display_name: g.display_name, enabled: g.enabled,
                        rate_count: g.rate_count, rate_min: g.rate_min, rate_max: g.rate_max, rate_avg: g.rate_avg,
                        models: models
                    };
                }).filter(function (g) { return g.models.length > 0; });
            },
            supplierList: function () {
                var out = [];
                this.groups.forEach(function (g) {
                    out.push({ provider_key: g.provider_key, display_name: g.display_name });
                });
                if (!out.length) {
                    this.suppliers.forEach(function (s) {
                        if (typeof s === 'string') out.push({ provider_key: s, display_name: s });
                        else out.push({ provider_key: s.provider_key, display_name: s.display_name || s.provider_key });
                    });
                }
                return out;
            },
            /**
             * 过滤 + 分批裁剪后的分组（真正参与 v-for 的数组）。
             *
             * 裁剪放在 computed 里而不是模板方法里：分组几千个模型时，
             * 每次渲染都重新 slice 会产生大量无谓 diff。
             */
            rendered: function () {
                var limits = this.limits;
                var step = this.renderStep;
                return this.filtered.map(function (g) {
                    var list = g.models || [];
                    var limit = limits[g.provider_key] || step;
                    var shown = list.length > limit ? list.slice(0, limit) : list;
                    return {
                        provider_key: g.provider_key, display_name: g.display_name, enabled: g.enabled,
                        rate_count: g.rate_count, rate_min: g.rate_min, rate_max: g.rate_max, rate_avg: g.rate_avg,
                        models: shown, count: list.length, hidden: list.length - shown.length
                    };
                });
            }
        },
        mounted: function () { this.load(); },
        unmounted: function () {
            if (this.syncTimer) { clearInterval(this.syncTimer); this.syncTimer = null; }
        },
        methods: {
            load: function () {
                var self = this;
                this.loading = true;
                api.scoped.models().then(function (res) {
                    self.loading = false;
                    if (!res.ok) { self.error = res.error || '加载失败'; return; }
                    self.error = '';
                    var d = res.data || {};
                    self.groups = d.groups || [];
                    self.total = d.total || 0;
                    self.enabled = d.enabled || 0;
                    self.rated = d.rated || 0;
                    self.suppliers = d.suppliers || d.providers || [];
                });
            },
            modelId: function (m) { return m.model_id || m.id || m.model || ''; },
            modelName: function (m) { return m.display_name || m.name || ''; },
            callName: function (providerKey, m) {
                return providerKey + '/' + this.modelId(m);
            },
            rateLabel: function (r) {
                if (r === null || r === undefined || r === '') return '—';
                var n = Number(r);
                if (!isFinite(n)) return '—';
                return String(Math.round(n * 10000) / 10000);
            },
            groupRateLabel: function (g) {
                if (!g || !g.rate_count) return '未采集';
                if (Number(g.rate_min) === Number(g.rate_max)) return this.rateLabel(g.rate_min);
                return this.rateLabel(g.rate_min) + ' ~ ' + this.rateLabel(g.rate_max);
            },
            numLabel: function (v) {
                if (v === null || v === undefined || v === '') return '';
                var n = Number(v);
                if (!isFinite(n)) return '';
                return n.toLocaleString('en-US');
            },
            modelDetail: function (m) {
                var parts = [];
                if (m.context_window) parts.push('上下文 ' + this.numLabel(m.context_window));
                if (m.max_tokens) parts.push('最大输出 ' + this.numLabel(m.max_tokens));
                if (m.fee_level !== null && m.fee_level !== undefined) parts.push('计费等级 ' + m.fee_level);
                if (m.entitlement_cost !== null && m.entitlement_cost !== undefined) parts.push('权益成本 ' + m.entitlement_cost);
                if (m.multimodal) parts.push('多模态');
                if (m.reasoning) parts.push('推理');
                return parts.join(' · ');
            },
            syncedLabel: function (m) {
                return m.synced_at ? '同步于 ' + m.synced_at : '未同步';
            },
            isEnabled: function (m) { return m.enabled !== false && m.enabled !== 0; },
            canEdit: function (m) { return !!m.id && String(m.id).indexOf('__') !== 0; },
            // 默认折叠：仅当显式展开（或正在搜索）时才展示模型列表
            isCollapsed: function (key) { if (this.keyword.trim()) return false; return !this.expanded[key]; },
            toggleCollapse: function (key) { this.expanded[key] = !this.expanded[key]; },
            showMore: function (g) {
                this.limits[g.provider_key] = (this.limits[g.provider_key] || this.renderStep) + this.renderStep;
            },
            toggleModel: function (m) {
                var self = this;
                if (!m.id) { S.toast('该模型缺少数据库 ID，无法切换状态', 'warning'); return; }
                this.busy = true;
                api.scoped.updateModel(m.id, { enabled: !this.isEnabled(m) }).then(function (res) {
                    self.busy = false;
                    if (S.report(res)) self.load();
                });
            },
            toggleGroup: function (g, enabled) {
                var self = this;
                this.busy = true;
                api.scoped.toggleProviderModels(g.provider_key, enabled).then(function (res) {
                    self.busy = false;
                    if (S.report(res)) self.load();
                });
            },
            sync: function (providerKey) {
                var self = this;
                if (this.syncing) return;
                this.busy = true;
                this.syncing = true;
                this.syncSeconds = 0;
                this.syncTimer = setInterval(function () { self.syncSeconds++; }, 1000);
                // ⚠️ 同步在**一个请求里跑完**（逐个供应商打上游 + 批量落库，可能几十秒）。
                //    线上 CDN 回源超时（EdgeOne 默认 15s、实测 ~50s）可能让浏览器看到 524，
                //    但源站 PHP 会把同步跑完（模型仍会进来）。
                //    曾试过「后台任务 + 轮询」绕开它，但线上 worker 没接管任务 → 同步直接失败，
                //    已回滚（原因见 ModelController::sync() 注释）。
                api.scoped.syncModels(providerKey).then(function (res) {
                    self.stopSync();
                    S.report(res);
                    self.load();
                });
            },
            stopSync: function () {
                if (this.syncTimer) { clearInterval(this.syncTimer); this.syncTimer = null; }
                this.syncing = false;
                this.busy = false;
            },
            openAdd: function () {
                var first = this.supplierList[0];
                this.form = {
                    provider_key: first ? first.provider_key : '',
                    model_id: '', display_name: '', enabled: true
                };
                this.showAdd = true;
            },
            submitAdd: function () {
                var self = this;
                if (!this.form.provider_key || !this.form.model_id) {
                    S.toast('请填写供应商与模型 ID', 'warning');
                    return;
                }
                this.busy = true;
                api.scoped.createModel(this.form).then(function (res) {
                    self.busy = false;
                    if (S.report(res, '模型已添加')) { self.showAdd = false; self.load(); }
                });
            },
            openEdit: function (m) {
                if (!m.id) { S.toast('该模型无法编辑（缺少数据库 ID）', 'warning'); return; }
                this.editing = m;
                this.editForm = {
                    model_id: this.modelId(m),
                    display_name: this.modelName(m),
                    enabled: this.isEnabled(m),
                    rate: (m.rate === null || m.rate === undefined) ? '' : String(m.rate)
                };
                this.showEdit = true;
            },
            submitEdit: function () {
                var self = this;
                if (!this.editing || !this.editing.id) { S.toast('该模型无法编辑', 'warning'); return; }
                this.busy = true;
                api.scoped.updateModel(this.editing.id, this.editForm).then(function (res) {
                    self.busy = false;
                    if (S.report(res, '模型已更新')) { self.showEdit = false; self.load(); }
                });
            },
            remove: function (m) {
                var self = this;
                if (!m.id) { S.toast('该模型无法删除', 'warning'); return; }
                var id = this.modelId(m);
                global.AigwConfirm('确定删除模型「' + id + '」？', {
                    title: '删除模型', danger: true, confirmText: '确认删除'
                }).then(function (yes) {
                    if (!yes) return;
                    self.busy = true;
                    api.scoped.deleteModel(m.id).then(function (res) {
                        self.busy = false;
                        if (S.report(res)) self.load();
                    });
                });
            },
            copy: function (text) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        S.toast('已复制调用名：' + text, 'success');
                    }, function () { S.toast('复制失败', 'error'); });
                } else {
                    S.toast('当前浏览器不支持自动复制', 'warning');
                }
            }
        },
        template:
            '<div>' +
              '<div class="page-head">' +
                '<div class="page-head__main">' +
                  '<div class="topbar__title">模型目录</div>' +
                  '<div class="topbar__desc">共 {{ total }} 个模型，已启用 {{ enabled }} 个，已采集倍率 {{ rated }} 个</div>' +
                '</div>' +
                '<div class="page-head__actions">' +
                  '<button type="button" class="btn btn--sm" :disabled="busy" @click="sync(\'\')">{{ syncing ? \'同步中… \' + syncSeconds + \'s\' : \'全量同步\' }}</button>' +
                  '<button type="button" class="btn btn--sm btn--primary" @click="openAdd">添加模型</button>' +
                '</div>' +
              '</div>' +

              '<div v-if="syncing" class="note note--info mt-16">' +
                '正在从上游拉取模型并写入目录（已等待 {{ syncSeconds }} 秒）。' +
                '模型较多时需要一两分钟，请勿关闭或刷新页面。' +
              '</div>' +

              '<div class="note note--info mt-16">' +
                '调用时把请求里的 <span class="mono">model</span> 写成 <b class="mono">供应商key/模型ID</b>，即可锁定到指定平台的该模型，' +
                '网关 API Key 全程不用更换。例如 <span class="mono">trae/glm-5.2</span>、<span class="mono">loomy/claude-sonnet-4</span>。' +
                '不带前缀的裸模型名只会在已启用目录里匹配，多个平台有同名模型时结果不确定。' +
              '</div>' +

              '<div class="card mt-16"><div class="card__body">' +
                '<div class="field__row">' +
                  '<input class="input" v-model="keyword" placeholder="搜索模型 ID 或名称">' +
                  '<button type="button" class="btn" @click="keyword = \'\'">清除</button>' +
                '</div>' +
              '</div></div>' +

              '<div v-if="loading" class="mt-16"><aigw-loading /></div>' +
              '<div v-else-if="error" class="card mt-16">' +
                '<aigw-empty icon="warning" title="加载失败" :desc="error" />' +
              '</div>' +
              '<div v-else-if="!filtered.length" class="card mt-16">' +
                '<aigw-empty icon="models" title="没有匹配的模型" :desc="\'可以从上游同步，或手工添加\'" />' +
              '</div>' +

              '<div v-else>' +
                '<aigw-card v-for="g in rendered" :key="g.provider_key" class="mt-16">' +
                  '<template #header>' +
                    '<div class="grouphead">' +
                      '<button type="button" class="grouphead__toggle" @click="toggleCollapse(g.provider_key)"' +
                              ' :aria-expanded="isCollapsed(g.provider_key) ? \'false\' : \'true\'"' +
                              ' :title="isCollapsed(g.provider_key) ? \'展开\' : \'折叠\'">' +
                        '<aigw-icon :name="isCollapsed(g.provider_key) ? \'chevronDown\' : \'chevronUp\'" :size="16" />' +
                      '</button>' +
                      '<div class="grouphead__main">' +
                        '<div class="grouphead__title">' +
                          '<span class="grouphead__name">{{ g.display_name || g.provider_key }}</span>' +
                          '<aigw-badge :tone="g.enabled ? \'success\' : \'muted\'" dot>' +
                            '{{ g.enabled ? "启用" : "停用" }}' +
                          '</aigw-badge>' +
                        '</div>' +
                        '<div class="grouphead__sub">' +
                          '<span class="mono">{{ g.provider_key }}</span>' +
                          '<span>·</span><span>{{ g.count }} 个模型</span>' +
                          '<span>·</span><span>倍率 {{ groupRateLabel(g) }}</span>' +
                          '<span>·</span><span>{{ g.rate_count || 0 }}/{{ g.count }} 采集</span>' +
                        '</div>' +
                      '</div>' +
                      '<div class="grouphead__actions">' +
                        '<button type="button" class="btn btn--sm" :disabled="busy" @click="sync(g.provider_key)">同步</button>' +
                        '<button type="button" class="btn btn--sm" :disabled="busy" @click="toggleGroup(g, true)">全部启用</button>' +
                        '<button type="button" class="btn btn--sm" :disabled="busy" @click="toggleGroup(g, false)">全部停用</button>' +
                      '</div>' +
                    '</div>' +
                  '</template>' +
                  '<div class="grid grid--list" v-if="!isCollapsed(g.provider_key)">' +
                    '<div v-for="m in g.models" :key="modelId(m) + \'-\' + (m.id || \'\')" class="modelrow">' +
                      '<div class="modelrow__main">' +
                          '<div class="modelrow__title">' +
                            '<span class="modelrow__id">{{ modelId(m) }}</span>' +
                            '<aigw-badge :tone="isEnabled(m) ? \'celadon\' : \'muted\'" dot>' +
                              '{{ isEnabled(m) ? "启用" : "停用" }}' +
                            '</aigw-badge>' +
                            '<aigw-badge :tone="(m.rate === null || m.rate === undefined) ? \'muted\' : \'clay\'">' +
                              '倍率 {{ rateLabel(m.rate) }}' +
                            '</aigw-badge>' +
                            '<aigw-badge v-if="m.is_custom" tone="clay">自定义</aigw-badge>' +
                          '</div>' +
                          '<div class="modelrow__call">' +
                            '<span class="modelrow__call-label">调用名</span>' +
                            '<span class="modelrow__call-val mono">{{ callName(g.provider_key, m) }}</span>' +
                          '</div>' +
                          '<div v-if="modelName(m)" class="modelrow__alias">{{ modelName(m) }}</div>' +
                          '<div v-if="modelDetail(m)" class="modelrow__meta">{{ modelDetail(m) }}</div>' +
                        '</div>' +
                        '<div class="modelrow__side">' +
                          '<div v-if="(m.capabilities || []).length" class="modelrow__tags">' +
                            '<aigw-badge v-for="cap in m.capabilities" :key="cap" tone="muted">{{ cap }}</aigw-badge>' +
                          '</div>' +
                        '<div class="modelrow__ops">' +
                          '<button type="button" class="btn btn--sm" @click="copy(callName(g.provider_key, m))">复制调用名</button>' +
                          '<button type="button" class="btn btn--sm" :disabled="busy" @click="toggleModel(m)">' +
                            '{{ isEnabled(m) ? "停用" : "启用" }}' +
                          '</button>' +
                          '<button type="button" class="btn btn--sm" :disabled="busy" @click="openEdit(m)">编辑</button>' +
                          '<button type="button" class="btn btn--sm btn--danger" :disabled="busy" @click="remove(m)">删除</button>' +
                        '</div>' +
                      '</div>' +
                    '</div>' +
                    '<div v-if="g.hidden" class="modelrow">' +
                      '<div class="modelrow__main">' +
                        '<button type="button" class="btn btn--sm" @click="showMore(g)">' +
                          '显示更多（还有 {{ g.hidden }} 个）' +
                        '</button>' +
                      '</div>' +
                    '</div>' +
                  '</div>' +
                '</aigw-card>' +
              '</div>' +

              '<aigw-modal v-if="showAdd" title="添加模型" :busy="busy" confirm-text="添加"' +
                           ' @close="showAdd = false" @confirm="submitAdd">' +
                '<div class="field">' +
                  '<label class="field__label">供应商 *</label>' +
                  '<select class="select" v-model="form.provider_key">' +
                    '<option v-for="s in supplierList" :key="s.provider_key" :value="s.provider_key">' +
                      '{{ s.display_name }}' +
                    '</option>' +
                  '</select>' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">模型 ID *</label>' +
                  '<input class="input mono" v-model="form.model_id" placeholder="gpt-4o">' +
                  '<div class="field__hint">只填上游模型 ID 本身，不要带供应商前缀；对外调用时会拼成 <span class="mono">供应商key/模型ID</span></div>' +
                '</div>' +
                '<div class="field mb-0">' +
                  '<label class="field__label">显示名称</label>' +
                  '<input class="input" v-model="form.display_name" placeholder="可选">' +
                '</div>' +
              '</aigw-modal>' +

              '<aigw-modal v-if="showEdit" title="编辑模型" :busy="busy" confirm-text="保存"' +
                           ' @close="showEdit = false" @confirm="submitEdit">' +
                '<div class="field">' +
                  '<label class="field__label">模型 ID</label>' +
                  '<input class="input mono" v-model="editForm.model_id">' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">显示名称</label>' +
                  '<input class="input" v-model="editForm.display_name">' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">计费倍率（留空表示未采集，按 1.0 计）</label>' +
                  '<input class="input mono" type="number" step="0.0001" min="0"' +
                         ' v-model="editForm.rate" placeholder="例如 0.04">' +
                '</div>' +
                '<div v-if="editing && modelDetail(editing)" class="field">' +
                  '<label class="field__label">上游详情</label>' +
                  '<div class="text-soft">' +
                    '{{ modelDetail(editing) }}<br>{{ syncedLabel(editing) }}' +
                  '</div>' +
                '</div>' +
                '<div class="field mb-0">' +
                  '<aigw-switch v-model="editForm.enabled" label="启用该模型" />' +
                '</div>' +
              '</aigw-modal>' +
            '</div>'
    };

    /* ============================================================
       调用日志
       ============================================================ */
    pages.PageLogs = {
        data: function () {
            return {
                loading: true, busy: false, error: '',
                items: [], total: 0, page: 1, pages: 1, perPage: 20,
                // user：用户筛选（管理员可填 ID / 邮箱 / 昵称；普通用户该输入框不渲染，
                // 且后端会把作用域强制锁回本人，前端传什么都不会越权）
                filters: { provider: '', model: '', success: '', keyword: '', user: '' },
                providers: [],
                userMatches: [],
                stats: null, statsDays: 7, trendMetric: 'calls',
                showDetail: false, detail: null, detailLoading: false, detailError: ''
            };
        },
        computed: {
            isAdmin: function () { return S.state.role !== 'user'; },
            routeUser: function () {
                if (S.state.route !== 'logs') return '';
                var p = S.state.params || {};
                return p.id ? String(p.id) : '';
            },
            overall: function () { return (this.stats && this.stats.overall) || {}; },
            daily: function () { return (this.stats && this.stats.daily) || []; },
            trendOptions: function () {
                return [
                    { value: 'calls', label: '调用' },
                    { value: 'tokens', label: 'Token' },
                    { value: 'cost', label: '费用' }
                ];
            },
            trendUnit: function () {
                if (this.trendMetric === 'cost') return '';
                return this.trendMetric === 'tokens' ? ' Token' : ' 次';
            },
            trendDecimals: function () { return this.trendMetric === 'cost' ? 4 : 0; },
            trendTotal: function () {
                var key = this.trendMetric, sum = 0;
                (this.daily || []).forEach(function (d) { sum += Number(d[key]) || 0; });
                return sum;
            },
            resultItems: function () {
                var total = Number(this.overall.total) || 0;
                var rate = Number(this.overall.success_rate);
                if (!isFinite(rate)) rate = 0;
                var ok = Math.round(total * rate / 100);
                var fail = Math.max(0, total - ok);
                return [
                    { label: '成功', value: ok, color: S.palette[2] },
                    { label: '失败', value: fail, color: S.palette[4] }
                ];
            },
            pageList: function () {
                var out = [], start = Math.max(1, this.page - 2);
                var end = Math.min(this.pages, start + 4);
                start = Math.max(1, end - 4);
                for (var i = start; i <= end; i++) out.push(i);
                return out;
            },
            /** 命中用户的一句话摘要（筛选提示条用） */
            userMatchesText: function () {
                return (this.userMatches || []).map(function (u) {
                    return (u.label || ('用户 #' + u.id)) + ' #' + u.id;
                }).join('、');
            }
        },
        watch: {
            statsDays: function () { this.loadStats(); },
            routeUser: function (v) {
                var val = v || '';
                if (String(this.filters.user) === val) return;
                this.filters.user = val;
                this.page = 1;
                this.load();
                this.loadStats();
            }
        },
        mounted: function () {
            var self = this;
            this.filters.user = this.routeUser || '';
            api.scoped.providers().then(function (res) {
                if (res.ok) self.providers = (res.data && res.data.items) || [];
            });
            this.load();
            this.loadStats();
        },
        methods: {
            load: function () {
                var self = this;
                this.loading = true;
                var q = {
                    provider: this.filters.provider,
                    model: this.filters.model,
                    success: this.filters.success,
                    keyword: this.filters.keyword,
                    user: this.filters.user,
                    page: this.page,
                    per_page: this.perPage
                };
                api.scoped.logs(q).then(function (res) {
                    self.loading = false;
                    if (!res.ok) { self.error = res.error || '加载失败'; return; }
                    self.error = '';
                    var d = res.data || {};
                    self.items = d.items || [];
                    self.total = d.total || 0;
                    self.page = d.page || 1;
                    self.pages = d.pages || 1;
                    self.userMatches = d.user_matches || [];
                });
            },
            loadStats: function () {
                var self = this;
                api.scoped.logStats(this.statsDays, { user: this.filters.user }).then(function (res) {
                    if (res.ok) self.stats = res.data || {};
                });
            },
            search: function () { this.page = 1; this.load(); this.loadStats(); },
            exportCsv: function () {
                if (this.busy) return;
                this.busy = true;
                var self = this;
                api.scoped.exportLogs(this.filters).then(function (ok) {
                    self.busy = false;
                    if (ok) S.toast('导出已开始，请留意浏览器下载', 'success');
                });
            },
            resetFilters: function () {
                this.filters = { provider: '', model: '', success: '', keyword: '', user: '' };
                this.page = 1;
                this.load();
                this.loadStats();
            },
            clearUserFilter: function () {
                this.filters.user = '';
                this.page = 1;
                this.load();
                this.loadStats();
            },
            /**
             * 点列表里的用户 → 直接把该用户设为筛选条件（省得去用户管理页抄 ID）。
             */
            filterByUser: function (row) {
                if (!this.isAdmin || !row) return;
                var uid = Number(row.user_id || 0);
                if (uid <= 0) return;
                this.filters.user = String(uid);
                this.page = 1;
                this.load();
                this.loadStats();
            },
            /** 用户列展示文案：user_label 由后端给（含「全局（管理员密钥）」） */
            userLabel: function (row) {
                if (!row) return '';
                if (row.user_label) return row.user_label;
                var uid = Number(row.user_id || 0);
                return uid > 0 ? ('用户 #' + uid) : '全局';
            },
            /** 用户列紧凑标签：昵称/邮箱/用户名 + #id；全局密钥产生的调用显示为「全局」 */
            userCellLabel: function (row) {
                if (!row) return '';
                var uid = Number(row.user_id || 0);
                if (uid <= 0) return '全局';
                var u = row.user || {};
                var name = u.nickname || u.email || u.username || '用户';
                return name + ' #' + uid;
            },
            goPage: function (p) {
                if (p < 1 || p > this.pages) return;
                this.page = p;
                this.load();
            },
            /**
             * 打开调用详情。
             *
             * 列表接口**不再返回正文**（正文动辄几万字，一页 20 条会把响应撑到几百 KB，
             * 这正是列表卡顿的唯一原因）。所以这里先渲染已知的结构化字段让弹窗立刻出来，
             * 再按 id 单条拉全文 —— 用户看到的是「秒开 + 正文稍后填充」，
             * 而不是「点一下卡两秒」。
             */
            openDetail: function (row) {
                var self = this;
                this.detail = row;
                this.detailLoading = !!row.has_content;
                this.detailError = '';
                this.showDetail = true;

                if (!row.has_content) {
                    this.detailLoading = false;
                    return;
                }

                api.scoped.logDetail(row.id).then(function (res) {
                    if (!self.showDetail) return;
                    var d = (res && res.data) ? res.data : null;
                    if (!d) { self.detailError = '详情加载失败'; self.detailLoading = false; return; }
                    // 保留列表里已有的派生字段（成功/耗时等），正文以详情为准
                    self.detail = Object.assign({}, row, d);
                    self.detailLoading = false;
                }).catch(function (e) {
                    if (!self.showDetail) return;
                    self.detailLoading = false;
                    self.detailError = (e && e.message) ? e.message : '详情加载失败';
                });
            },
            prune: function () {
                if (!this.isAdmin) return;
                var self = this;
                global.AigwConfirm('将清理 30 天前的调用日志，确定继续？', {
                    title: '清理历史日志', danger: true, confirmText: '确认清理'
                }).then(function (yes) {
                    if (!yes) return;
                    self.busy = true;
                    api.admin.pruneLogs(30).then(function (res) {
                        self.busy = false;
                        if (S.report(res)) { self.load(); self.loadStats(); }
                    });
                });
            },
            clearAll: function () {
                if (!this.isAdmin) return;
                var self = this;
                global.AigwConfirm('将清空全部调用日志，该操作不可恢复。', {
                    title: '清空日志', danger: true, confirmText: '确认清空'
                }).then(function (yes) {
                    if (!yes) return;
                    self.busy = true;
                    api.admin.clearLogs().then(function (res) {
                        self.busy = false;
                        if (S.report(res)) { self.load(); self.loadStats(); }
                    });
                });
            },
            statusTone: function (row) {
                if (row.success) return 'success';
                return Number(row.status_code || 0) === 429 ? 'warning' : 'danger';
            },
            tokens: function (row) {
                return (Number(row.prompt_tokens) || 0) + (Number(row.completion_tokens) || 0);
            },
            cost: function (row) {
                if (row.cost !== undefined && row.cost !== null) return Number(row.cost);
                var rate = Number(row.rate);
                if (!isFinite(rate)) rate = 1;
                return this.tokens(row) * rate / 1000;
            },
            rateLabel: function (row) {
                var r = Number(row.rate);
                return isFinite(r) ? r.toFixed(2) : '1.00';
            },
            costLabel: function (row) {
                return fmt.num(Math.round(this.cost(row) * 10000) / 10000, 4);
            },
            // 收尾原因：区分「输出被截断」的真因。
            // length = 撞到 max_tokens（真截断）；stop 但内容很短 = 上游提前收尾
            // （多为 reasoning 占满 token，见 reasoning_tokens 栏）。
            finishLabel: function (row) {
                var f = String((row && row.finish_reason) || '').trim();
                if (f === '') return '未记录';
                var map = {
                    stop: 'stop（正常结束）',
                    length: 'length（撞到 max_tokens，输出被截断）',
                    tool_calls: 'tool_calls（转为工具调用）',
                    content_filter: 'content_filter（被内容策略截断）',
                };
                return map[f] || f;
            },
            /**
             * 客户端**原始**请求的模型名（未经 resolveModel 解析）。
             *
             * 「模型」栏显示的是路由**结果**：客户端发 `trae/xxx` 时这里可能变成
             * `xxx`，发了个目录里没有的名字时会被兜底成默认供应商的模型 ——
             * 光看「模型」栏看不出「客户端到底发了什么」。
             * 两者不同时并排显示，一眼就能判断是「客户端发错」还是「网关解析错」。
             */
            requestedModelLabel: function (row) {
                var req = String((row && row.requested_model) || '').trim();
                var res = String((row && row.model) || '').trim();
                if (req === '') return '未记录（历史行或自证回执）';
                if (req === res) return req + '（与解析结果一致）';
                return req + ' → ' + res;
            },
            /**
             * 正文体积标签（列表里「内容」徽标的悬停提示）。
             *
             * 列表不携带正文，长度由后端 `req_body_length` / `res_body_length` 给出
             * （写入时算好的明文字符数，压缩态也是精确值）。
             */
            bodySizeLabel: function (row) {
                if (!row) return '';
                var rq = Number(row.req_body_length || 0);
                var rs = Number(row.res_body_length || 0);
                var parts = [];
                if (rq > 0) parts.push('请求 ' + fmt.num(rq) + ' 字');
                if (rs > 0) parts.push('回复 ' + fmt.num(rs) + ' 字');
                if (!parts.length) return '';
                // 普通用户只看到思维名称标签，正文对应位置已被替换，长度也会随之变化
                var tail = this.isAdmin ? '（正文完整保留，点详情查看）' : '（思维注入块仅显示名称标签）';
                return '已留痕：' + parts.join(' / ') + tail;
            },
            copyText: function (text, label) {
                var t = String(text || '');
                if (t === '') { S.toast('没有可复制的内容', 'warning'); return; }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(t).then(function () {
                        S.toast((label || '内容') + '已复制', 'success');
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
                  '<div class="topbar__title">调用日志</div>' +
                  '<div class="topbar__desc">共 {{ fmt.num(total) }} 条记录</div>' +
                  '<div v-if="isAdmin && filters.user" class="mt-8 flex items-center wrap">' +
                    '<aigw-badge tone="plum">仅看用户「{{ filters.user }}」的日志</aigw-badge>' +
                    '<span v-if="userMatches.length" class="text-faint" style="font-size:12px;margin-left:8px">' +
                      '命中 {{ userMatches.length }} 位：{{ userMatchesText }}' +
                    '</span>' +
                    '<span v-else class="text-faint" style="font-size:12px;margin-left:8px">' +
                      '没有匹配到任何用户 —— 请核对用户 ID / 邮箱 / 昵称' +
                    '</span>' +
                    '<button type="button" class="btn btn--sm" style="margin-left:8px" @click="clearUserFilter">显示全部</button>' +
                  '</div>' +
                '</div>' +
                '<div class="page-head__actions">' +
                  '<button type="button" class="btn btn--sm" :disabled="busy" @click="exportCsv">导出 CSV</button>' +
                  '<button type="button" class="btn btn--sm" v-if="isAdmin" :disabled="busy" @click="prune">清理 30 天前</button>' +
                  '<button type="button" class="btn btn--sm btn--danger" v-if="isAdmin" :disabled="busy" @click="clearAll">清空日志</button>' +
                '</div>' +
              '</div>' +

              '<div class="grid grid--stats mt-16">' +
                '<aigw-stat label="总调用" :value="fmt.compact(overall.total)" tone="primary" icon="logs" />' +
                '<aigw-stat label="成功率" :value="fmt.pct(overall.success_rate)" tone="celadon" icon="check" />' +
                '<aigw-stat label="平均耗时" :value="fmt.duration(overall.avg_duration)" tone="amber" icon="timer" />' +
                '<aigw-stat label="总 Token" :value="fmt.compact(overall.tokens)" tone="plum" icon="dashboard" />' +
                '<aigw-stat label="预估费用" :value="fmt.num(overall.cost, 4)" tone="clay" icon="money" />' +
              '</div>' +

              '<div class="grid grid--2 mt-16">' +
                '<aigw-card :title="\'调用趋势\'" :sub="\'最近 \' + statsDays + \' 天 · 合计 \' + fmt.num(trendTotal, trendDecimals) + trendUnit">' +
                  '<template #actions>' +
                    '<aigw-segbar v-model="trendMetric" :options="trendOptions" />' +
                    '<aigw-segbar v-model="statsDays" :options="[' +
                      '{ value: 7, label: \'7 天\' }, { value: 14, label: \'14 天\' }, { value: 30, label: \'30 天\' }]" />' +
                  '</template>' +
                  '<aigw-trend v-if="daily.length" :data="daily"' +
                              ' :value-key="trendMetric" :unit="trendUnit" :decimals="trendDecimals" />' +
                  '<aigw-empty v-else icon="logs" title="暂无统计数据" />' +
                '</aigw-card>' +
                '<aigw-card title="调用结果占比" sub="窗口内的成功与失败分布">' +
                  '<aigw-donut :items="resultItems" :size="150" caption="总调用"' +
                              ' :unit="\' 次\'" />' +
                '</aigw-card>' +
              '</div>' +

              '<div class="card mt-16"><div class="card__body">' +
                '<div class="grid grid--2">' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">供应商</label>' +
                    '<select class="select" v-model="filters.provider" @change="search">' +
                      '<option value="">全部供应商</option>' +
                      '<option v-for="p in providers" :key="p.provider_key" :value="p.provider_key">' +
                        '{{ p.display_name }}' +
                      '</option>' +
                    '</select>' +
                  '</div>' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">模型</label>' +
                    '<input class="input" v-model="filters.model" placeholder="精确匹配，如 gpt-4o"' +
                           ' @keyup.enter="search">' +
                  '</div>' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">结果</label>' +
                    '<select class="select" v-model="filters.success" @change="search">' +
                      '<option value="">全部结果</option>' +
                      '<option value="1">成功</option>' +
                      '<option value="0">失败</option>' +
                    '</select>' +
                  '</div>' +
                '</div>' +
                /* ── 按用户查找（仅管理台；普通用户端后端会强制锁回本人） ── */
                '<div v-if="isAdmin" class="field mt-16">' +
                  '<label class="field__label">用户（ID / 邮箱 / 昵称）</label>' +
                  '<div class="field__row">' +
                    '<input class="input" v-model="filters.user"' +
                           ' placeholder="例如 3、user@example.com 或昵称"' +
                           ' @keyup.enter="search">' +
                    '<button type="button" class="btn" v-if="filters.user" @click="clearUserFilter">清除</button>' +
                  '</div>' +
                  '<div class="field__hint">留空 = 不限制；点下方列表里的用户也能一键筛选</div>' +
                '</div>' +
                '<div class="field mt-16 mb-0">' +
                  '<label class="field__label">关键词（模型名 / 错误信息）</label>' +
                  '<div class="field__row">' +
                    '<input class="input" v-model="filters.keyword" placeholder="例如 gpt-4o 或 timeout"' +
                           ' @keyup.enter="search">' +
                    '<button type="button" class="btn btn--primary" @click="search">查询</button>' +
                    '<button type="button" class="btn" @click="resetFilters">重置</button>' +
                  '</div>' +
                '</div>' +
              '</div></div>' +

              '<aigw-card title="请求明细" flush class="mt-16">' +
                '<div v-if="loading"><aigw-loading /></div>' +
                '<div v-else-if="error">' +
                  '<aigw-empty icon="warning" title="加载失败" :desc="error" />' +
                '</div>' +
                '<div v-else-if="!items.length">' +
                  '<aigw-empty icon="logs" title="没有匹配的日志" desc="调整筛选条件后重试" />' +
                '</div>' +
                '<div v-else class="table__wrap">' +
                  '<table class="table">' +
                    '<thead><tr>' +
                      '<th>时间</th><th v-if="isAdmin">用户</th><th>供应商</th><th>模型</th><th>结果</th>' +
                      '<th style="text-align:right">耗时</th><th style="text-align:right">Token</th>' +
                      '<th style="text-align:right">倍率</th><th style="text-align:right">费用</th>' +
                      '<th>流式</th><th></th>' +
                    '</tr></thead>' +
                    '<tbody>' +
                      '<tr v-for="(r, i) in items" :key="i">' +
                        '<td class="text-soft nowrap">{{ fmt.time(r.created_at) }}</td>' +
                        /* 用户列仅管理台可见：普通用户端后端已锁死本人，这列没有信息量 */
                        '<td v-if="isAdmin" class="nowrap">' +
                          '<button v-if="isAdmin && r.user_id > 0" type="button" class="btn btn--sm"' +
                                  ' style="max-width:180px"' +
                                  ' :title="userLabel(r) + \' —— 点击只看该用户的日志\'"' +
                                  ' @click="filterByUser(r)">' +
                            '{{ userCellLabel(r) }}' +
                          '</button>' +
                          '<span v-else class="text-soft">{{ userCellLabel(r) }}</span>' +
                        '</td>' +
                        '<td class="nowrap">{{ r.provider_key }}</td>' +
                        '<td class="mono truncate" style="max-width:200px">{{ r.model }}</td>' +
                        '<td>' +
                          '<aigw-badge :tone="statusTone(r)" dot>' +
                            '{{ r.success ? "成功" : (r.status_code || "失败") }}' +
                          '</aigw-badge>' +
                        '</td>' +
                        '<td style="text-align:right" class="text-soft">{{ fmt.duration(r.duration_ms) }}</td>' +
                        '<td style="text-align:right" class="text-soft">' +
                          '{{ fmt.num(tokens(r)) }}' +
                          '<span v-if="r.reasoning_tokens > 0" class="text-faint" style="margin-left:4px"' +
                                ' title="其中推理 Token（不计入可见内容，输出看着短多半是这个原因）">' +
                            '(推理{{ fmt.num(r.reasoning_tokens) }})</span>' +
                        '</td>' +
                        '<td style="text-align:right" class="text-soft">{{ rateLabel(r) }}</td>' +
                        '<td style="text-align:right" class="text-soft">{{ costLabel(r) }}</td>' +
                        '<td>' +
                          '<aigw-badge :tone="r.stream ? \'plum\' : \'muted\'">' +
                            '{{ r.stream ? "SSE" : "同步" }}' +
                          '</aigw-badge>' +
                        '</td>' +
                        '<td style="text-align:right" class="nowrap">' +
                          '<aigw-badge v-if="r.finish_reason === \'length\'" tone="danger" style="margin-right:6px"' +
                                ' title="撞到 max_tokens，输出被截断">截断</aigw-badge>' +
                          '<aigw-badge v-if="r.has_content" tone="celadon" style="margin-right:6px"' +
                                ' :title="bodySizeLabel(r)">内容</aigw-badge>' +
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

              '<aigw-modal v-if="showDetail && detail" title="请求详情" wide hide-foot @close="showDetail = false">' +
                '<div class="grid grid--2">' +
                  '<div class="field"><label class="field__label">时间</label>' +
                    '<input class="input" :value="fmt.time(detail.created_at)" readonly></div>' +
                  '<div class="field"><label class="field__label">归属用户</label>' +
                    '<input class="input" :value="detail.user_label || \'—\'" readonly></div>' +
                  '<div class="field"><label class="field__label">供应商</label>' +
                    '<input class="input" :value="detail.provider_key" readonly></div>' +
                  '<div class="field"><label class="field__label">模型</label>' +
                    '<input class="input mono" :value="detail.model" readonly></div>' +
                  '<div class="field"><label class="field__label">客户端原始模型（requested_model）</label>' +
                    '<input class="input mono" :value="requestedModelLabel(detail)" readonly></div>' +
                  '<div class="field"><label class="field__label">状态码</label>' +
                    '<input class="input" :value="detail.status_code" readonly></div>' +
                  '<div class="field"><label class="field__label">耗时</label>' +
                    '<input class="input" :value="fmt.duration(detail.duration_ms)" readonly></div>' +
                  '<div class="field"><label class="field__label">首字耗时</label>' +
                    '<input class="input" :value="fmt.duration(detail.first_token_ms)" readonly></div>' +
                  '<div class="field"><label class="field__label">Token（输入 / 输出）</label>' +
                    '<input class="input" :value="(detail.prompt_tokens || 0) + \' / \' + (detail.completion_tokens || 0)" readonly></div>' +
                  '<div class="field"><label class="field__label">收尾原因（finish_reason）</label>' +
                    '<input class="input mono" :value="finishLabel(detail)" readonly></div>' +
                  '<div class="field"><label class="field__label">推理 Token（不计入可见内容）</label>' +
                    '<input class="input" :value="fmt.num(detail.reasoning_tokens || 0)" readonly></div>' +
                  '<div class="field"><label class="field__label">计费倍率</label>' +
                    '<input class="input" :value="rateLabel(detail)" readonly></div>' +
                  '<div class="field"><label class="field__label">预估费用</label>' +
                    '<input class="input" :value="costLabel(detail)" readonly></div>' +
                  '<div class="field"><label class="field__label">重试次数</label>' +
                    '<input class="input" :value="detail.retry_count || 0" readonly></div>' +
                  '<div class="field"><label class="field__label">账号</label>' +
                    '<input class="input" :value="detail.account_nickname || detail.account_uid || \'—\'" readonly></div>' +
                  '<div class="field"><label class="field__label">请求 ID</label>' +
                    '<input class="input mono" :value="detail.request_id || \'—\'" readonly></div>' +
                '</div>' +
                '<div v-if="detail.error" class="field mt-8">' +
                  '<label class="field__label">错误信息</label>' +
                  '<textarea class="textarea" readonly rows="4" :value="detail.error"></textarea>' +
                '</div>' +
                '<div v-if="detail.client_ip" class="field mb-0">' +
                  '<label class="field__label">来源 IP</label>' +
                  '<input class="input mono" :value="detail.client_ip" readonly>' +
                '</div>' +
                '<div v-if="detail.has_content" class="field mt-16">' +
                  '<div class="flex items-center justify-between mb-8">' +
                    '<label class="field__label mb-0">请求内容（Prompt / messages 摘要）' +
                      '<span v-if="detailLoading" class="text-faint" style="font-weight:400">· 加载中…</span>' +
                      '<span v-else-if="detail.req_body_length" class="text-faint" style="font-weight:400">· ' + '{{ fmt.num(detail.req_body_length) }} 字</span>' +
                    '</label>' +
                    '<button type="button" class="btn btn--sm" @click="copyText(detail.request_body, \'请求内容\')">复制</button>' +
                  '</div>' +
                  '<div v-if="detailError" class="text-faint" style="font-size:12px">{{ detailError }}</div>' +
                  '<textarea v-else class="textarea mono" readonly rows="10" :value="detailLoading ? \'加载中…\' : (detail.request_body || \'（未记录或为空）\')"></textarea>' +
                    '<div class="text-faint" style="font-size:12px;margin-top:6px">' +
                      '{{ isAdmin ? \'完整保留，不截断；思维注入块显示全文（块首尾的 ops-notes 锚点即注入标记）。\' : \'思维注入块只显示名称标签（如【系统内置X】），不展示正文；块首尾的 ops-notes 锚点仍在，可据此判断本次是否注入。\' }}' +
                    '</div>' +
                '</div>' +
                '<div v-if="detail.has_content" class="field mb-0">' +
                  '<div class="flex items-center justify-between mb-8">' +
                    '<label class="field__label mb-0">回复内容（模型输出）' +
                      '<span v-if="detailLoading" class="text-faint" style="font-weight:400">· 加载中…</span>' +
                      '<span v-else-if="detail.res_body_length" class="text-faint" style="font-weight:400">· ' + '{{ fmt.num(detail.res_body_length) }} 字</span>' +
                    '</label>' +
                    '<button type="button" class="btn btn--sm" @click="copyText(detail.response_body, \'回复内容\')">复制</button>' +
                  '</div>' +
                  '<textarea v-if="!detailError" class="textarea mono" readonly rows="10" :value="detailLoading ? \'加载中…\' : (detail.response_body || \'（未记录或为空）\')"></textarea>' +
                '</div>' +
              '</aigw-modal>' +
            '</div>'
    };

    global.AigwPagesRegister(pages);
})(window);
