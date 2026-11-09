/* ============================================================
   页面组件（续 6）：数据大屏
   —— 三块屏共用一个聚合接口（/api/admin/screens）：
      1. 调用量大屏：所有调用 + 分布 + 排行 + 明细下钻
      2. 注册登录大屏：注册/登录/失败/锁定 + 来源 IP + 最近明细
      3. 指标大屏：累计调用 / 成功率 / 平均耗时 / 消耗 Token（全时段 + 窗口）
   ============================================================ */
(function (global) {
    'use strict';

    var S = global.AigwStore;
    var api = global.AigwApi;
    var fmt = S.fmt;
    var pages = {};

    /** 大屏三块 tab 的公共元数据 */
    var SCREEN_TABS = [
        { value: 'calls', label: '调用量大屏' },
        { value: 'auth',  label: '注册登录大屏' },
        { value: 'metrics', label: '指标大屏' }
    ];

    var DAY_OPTIONS = [
        { value: 1,  label: '近 1 天' },
        { value: 7,  label: '近 7 天' },
        { value: 30, label: '近 30 天' },
        { value: 90, label: '近 90 天' }
    ];

    /** 成功率 → 语义色（>=98 绿 / >=90 琥珀 / 其余 红） */
    function rateTone(rate) {
        var r = Number(rate) || 0;
        if (r >= 98) return 'celadon';
        if (r >= 90) return 'amber';
        return 'rust';
    }

    /* ============================================================
       数据大屏（主页面）
       ============================================================ */
    pages.PageScreens = {
        data: function () {
            return {
                loading: true, busy: false, error: '',
                tab: 'calls',
                days: 7,
                data: null,
                /** 服务端结果缓存状态：{cached, age, ttl}，用于在页头提示数据新鲜度 */
                cacheInfo: null,
                tabs: SCREEN_TABS,
                dayOptions: DAY_OPTIONS,

                // 明细下钻（复用 /api/admin/logs：已带思维脱敏，不重复实现）
                logs: { items: [], total: 0, page: 1, pages: 1, perPage: 50 },
                logLoading: false, logError: '',
                logFilters: { provider: '', model: '', success: '', keyword: '', user_id: '' },
                showDetail: false, detail: null, detailLoading: false, detailError: ''
            };
        },
        computed: {
            m: function () { return (this.data && this.data.metrics) || {}; },
            // 模板里用 metrics.xxx 更直观（m 保留给短表达式）
            metrics: function () { return (this.data && this.data.metrics) || {}; },
            // 调色板：模板里不能直接访问 S，用 computed 转出来
            palette: function () { return S.palette; },
            calls: function () { return (this.data && this.data.calls) || {}; },
            auth: function () { return (this.data && this.data.auth) || {}; },
            authOverall: function () { return this.auth.overall || {}; },
            providers: function () { return (this.data && this.data.providers) || []; },

            dayCount: function () { return this.days; },

            // ── 调用量：分布与序列 ──────────────────────────────
            daily: function () { return this.calls.daily || []; },
            hourly: function () { return this.calls.hourly || []; },
            buckets: function () { return this.calls.buckets || []; },
            byProvider: function () { return this.calls.by_provider || []; },
            byModel: function () { return this.calls.by_model || []; },
            byAccount: function () { return this.calls.by_account || []; },
            bySource: function () { return this.calls.by_source || []; },
            byUser: function () { return this.calls.by_user || []; },
            errorTop: function () { return this.calls.error_top || []; },
            topModels: function () { return this.calls.top_models || []; },

            // ── 注册登录序列 ────────────────────────────────────
            authDaily: function () { return this.auth.daily || []; },
            recentRegister: function () { return this.auth.recent_register || []; },
            recentLogin: function () { return this.auth.recent_login || []; },
            topRegisterIps: function () { return this.auth.top_register_ips || []; },
            topFailIps: function () { return this.auth.top_fail_ips || []; },

            // 环图：成功 / 失败切分（窗口口径）
            resultItems: function () {
                var o = this.calls.overall || {};
                var total = Number(o.calls) || 0;
                var ok = Number(o.success_calls) || 0;
                var fail = Math.max(0, total - ok);
                return [
                    { label: '成功', value: ok, color: S.palette[2] },
                    { label: '失败', value: fail, color: S.palette[4] }
                ];
            },
            // 环图：来源切分
            sourceItems: function () {
                var self = this;
                return this.bySource.map(function (s, i) {
                    return {
                        label: s.source_label || s.source || '未标识',
                        value: Number(s.calls) || 0,
                        color: S.palette[i % S.palette.length]
                    };
                });
            },
            // 环图：供应商切分
            providerItems: function () {
                var self = this;
                return this.byProvider.slice(0, 8).map(function (p, i) {
                    return {
                        label: p.display_name || p.provider_key,
                        value: Number(p.calls) || 0,
                        color: S.palette[i % S.palette.length]
                    };
                });
            },
            providerMax: function () {
                var m = 0;
                this.byProvider.forEach(function (p) { if (Number(p.calls) > m) m = Number(p.calls); });
                return m || 1;
            },
            bucketMax: function () {
                var m = 0;
                this.buckets.forEach(function (b) { if (Number(b.calls) > m) m = Number(b.calls); });
                return m || 1;
            },
            hourlyMax: function () {
                var m = 0;
                this.hourly.forEach(function (h) { if (Number(h.calls) > m) m = Number(h.calls); });
                return m || 1;
            },
            registerMax: function () {
                var m = 0;
                this.authDaily.forEach(function (d) { if (Number(d.register) > m) m = Number(d.register); });
                return m || 1;
            },
            failMax: function () {
                var m = 0;
                this.topFailIps.forEach(function (i) { if (Number(i.events) > m) m = Number(i.events); });
                return m || 1;
            },
            regIpMax: function () {
                var m = 0;
                this.topRegisterIps.forEach(function (i) { if (Number(i.events) > m) m = Number(i.events); });
                return m || 1;
            },
            // 明细分页页码
            pageList: function () {
                var out = [], start = Math.max(1, this.logs.page - 2);
                var end = Math.min(this.logs.pages, start + 4);
                start = Math.max(1, end - 4);
                for (var i = start; i <= end; i++) out.push(i);
                return out;
            },
            tabTitle: function () {
                var t = this.tabs.filter(function (x) { return x.value === this.tab; }, this)[0];
                return t ? t.label : '数据大屏';
            }
        },
        watch: {
            days: function () { this.load(); },
            tab: function (v) {
                // 切到「调用量大屏」时才需要拉明细，省一次请求
                if (v === 'calls' && !this.logs.items.length) this.loadLogs();
            }
        },
        mounted: function () { this.load(); },
        methods: {
            fmt: fmt,
            rateTone: rateTone,

            /**
             * @param {boolean} force 跳过服务端 60 秒结果缓存（点「刷新」时为 true）。
             *        打开页面/切换天数走缓存：大屏一次要跑 20+ 个聚合查询，
             *        随数据量线性变慢，没必要每次重算。
             */
            load: function (force) {
                var self = this;
                this.loading = true;
                api.admin.screens(this.days, !!force).then(function (res) {
                    self.loading = false;
                    if (!res.ok) { self.error = res.error || '加载失败'; return; }
                    self.error = '';
                    self.data = res.data || {};
                    self.cacheInfo = (res.data && res.data.cache) || null;
                    if (self.tab === 'calls') self.loadLogs();
                });
            },

            /* ── 调用明细（下钻） ────────────────────────────── */
            loadLogs: function () {
                var self = this;
                this.logLoading = true;
                api.admin.logs({
                    provider: this.logFilters.provider,
                    model: this.logFilters.model,
                    success: this.logFilters.success,
                    keyword: this.logFilters.keyword,
                    user_id: this.logFilters.user_id,
                    page: this.logs.page,
                    per_page: this.logs.perPage
                }).then(function (res) {
                    self.logLoading = false;
                    if (!res.ok) { self.logError = res.error || '加载失败'; return; }
                    self.logError = '';
                    var d = res.data || {};
                    self.logs = {
                        items: d.items || [],
                        total: d.total || 0,
                        page: d.page || 1,
                        pages: d.pages || 1,
                        perPage: d.per_page || 50
                    };
                });
            },
            searchLogs: function () { this.logs.page = 1; this.loadLogs(); },
            resetLogFilters: function () {
                this.logFilters = { provider: '', model: '', success: '', keyword: '', user_id: '' };
                this.logs.page = 1;
                this.loadLogs();
            },
            goLogPage: function (p) {
                if (p < 1 || p > this.logs.pages) return;
                this.logs.page = p;
                this.loadLogs();
            },
            /**
             * 打开调用详情：与「调用日志」页同一套逻辑 ——
             * 列表不返回正文，这里按 id 单条拉全文（含完整思维注入正文）。
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

                api.admin.logDetail(row.id).then(function (res) {
                    if (!self.showDetail) return;
                    var d = (res && res.data) ? res.data : null;
                    if (!d) { self.detailError = '详情加载失败'; self.detailLoading = false; return; }
                    self.detail = Object.assign({}, row, d);
                    self.detailLoading = false;
                }).catch(function (e) {
                    if (!self.showDetail) return;
                    self.detailLoading = false;
                    self.detailError = (e && e.message) ? e.message : '详情加载失败';
                });
            },
            exportCalls: function () {
                var self = this;
                this.busy = true;
                api.admin.exportLogs(this.logFilters).then(function (ok) {
                    self.busy = false;
                    S.toast(ok ? '导出已开始，请留意浏览器下载' : '导出失败', ok ? 'success' : 'error');
                });
            },

            // ── 展示辅助 ──────────────────────────────────────
            barWidth: function (v, max) {
                max = Number(max) || 1;
                return Math.max(0, Math.min(100, Math.round(Number(v) / max * 100)));
            },
            providerBar: function (p) { return this.barWidth(p.calls, this.providerMax); },
            bucketBar: function (b) { return this.barWidth(b.calls, this.bucketMax); },
            hourlyBar: function (h) { return this.barWidth(h.calls, this.hourlyMax); },
            registerBar: function (d) { return this.barWidth(d.register, this.registerMax); },
            failBar: function (i) { return this.barWidth(i.events, this.failMax); },
            regIpBar: function (i) { return this.barWidth(i.events, this.regIpMax); },
            providerColor: function (p) { return S.providerTone.key(p.provider_key); },

            costLabel: function (v) {
                var n = Number(v) || 0;
                return fmt.num(n, n < 1 ? 4 : 2);
            },
            tokensLabel: function (v) { return fmt.compact(v); },
            durationLabel: function (v) { return fmt.duration(v); },
            percentLabel: function (v) {
                var n = Number(v);
                return isFinite(n) ? n.toFixed(1) + '%' : '—';
            },
            rateLabel: function (row) {
                var r = Number(row && row.rate);
                return isFinite(r) ? r.toFixed(2) : '1.00';
            },
            rowCostLabel: function (row) {
                if (row && row.cost !== undefined && row.cost !== null) {
                    return fmt.num(Number(row.cost), 4);
                }
                return '0.0000';
            },
            tokens: function (row) {
                return (Number(row && row.prompt_tokens) || 0) + (Number(row && row.completion_tokens) || 0);
            },
            statusTone: function (row) {
                if (row && row.success) return 'success';
                return Number(row && row.status_code || 0) === 429 ? 'warning' : 'danger';
            },
            finishLabel: function (row) {
                var f = String((row && row.finish_reason) || '').trim();
                if (f === '') return '未记录';
                return {
                    stop: 'stop（正常结束）',
                    length: 'length（撞到 max_tokens，输出被截断）',
                    tool_calls: 'tool_calls（转为工具调用）',
                    content_filter: 'content_filter（被内容策略截断）'
                }[f] || f;
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
            copyText: function (text, label) {
                text = String(text || '');
                if (!text) { S.toast('内容为空', 'warning'); return; }
                var done = function () { S.toast((label || '内容') + '已复制', 'success'); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, function () {
                        S.toast('复制失败，请手动选择', 'error');
                    });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); done(); } catch (e) { S.toast('复制失败', 'error'); }
                    document.body.removeChild(ta);
                }
            },
            go: function (page) { S.navigate(page); },
            accountLabel: function (a) {
                return a.account_nickname || a.account_uid || '—';
            }
        },
        template:
            '<div class="page-screen">' +
              '<div class="page-head">' +
                '<div class="page-head__main">' +
                  '<div class="topbar__title">数据大屏</div>' +
                  '<div class="topbar__desc">调用量 · 注册登录 · 累计指标（全站聚合）</div>' +
                '</div>' +
                '<div class="page-head__actions">' +
                  '<select class="select" style="width:auto" v-model.number="days">' +
                    '<option v-for="o in dayOptions" :key="o.value" :value="o.value">{{ o.label }}</option>' +
                  '</select>' +
                  '<button type="button" class="btn btn--sm" :disabled="loading" @click="load(true)">刷新</button>' +
                  '<span v-if="cacheInfo" class="text-faint" style="font-size:12px;align-self:center">' +
                    '{{ cacheInfo.cached ? ("结果缓存 " + cacheInfo.age + " 秒前") : "已实时刷新" }}' +
                  '</span>' +
                '</div>' +
              '</div>' +

              '<div class="mt-16"><aigw-segbar v-model="tab" :options="tabs" /></div>' +

              '<div v-if="loading"><aigw-loading /></div>' +
              '<div v-else-if="error" class="card mt-16">' +
                '<aigw-empty icon="warning" title="加载失败" :desc="error" />' +
              '</div>' +

              '<div v-else>' +

                /* ══════════════════════════════════════════════
                   1) 调用量大屏
                   ══════════════════════════════════════════════ */
                '<div v-if="tab === \'calls\'">' +
                  '<div class="grid grid--stats mt-16">' +
                    '<aigw-stat label="窗口调用" :value="fmt.num((calls.overall || {}).calls)" tone="primary" icon="logs"' +
                              ' :foot="\'窗口 \' + days + \' 天\'" />' +
                    '<aigw-stat label="窗口成功率" :value="percentLabel((calls.overall || {}).success_rate)"' +
                              ' :tone="rateTone((calls.overall || {}).success_rate)" icon="check"' +
                              ' :foot="\'成功 \' + fmt.num((calls.overall || {}).success_calls) + \' 次\'" />' +
                    '<aigw-stat label="窗口平均耗时" :value="durationLabel((calls.overall || {}).avg_duration)" tone="celadon" icon="timer"' +
                              ' foot="按窗口内全部调用取平均" />' +
                    '<aigw-stat label="窗口消耗 Token" :value="tokensLabel((calls.overall || {}).total_tokens)" tone="plum" icon="dashboard"' +
                              ' :foot="\'输入 \' + fmt.compact((calls.overall || {}).prompt_tokens) + \' / 输出 \' + fmt.compact((calls.overall || {}).completion_tokens)" />' +
                    '<aigw-stat label="窗口预估费用" :value="costLabel((calls.overall || {}).cost)" tone="clay" icon="money"' +
                              ' foot="total_tokens × 模型倍率 ÷ 1000" />' +
                    '<aigw-stat label="缓存 Token" :value="tokensLabel((calls.overall || {}).cache_read_tokens)" tone="celadon" icon="bolt"' +
                              ' :foot="\'写入 \' + fmt.compact((calls.overall || {}).cache_write_tokens)" />' +
                    '<aigw-stat label="调用用户" :value="fmt.num(metrics.active_callers)" tone="amber" icon="users" foot="全时段去重" />' +
                    '<aigw-stat label="累计调用" :value="fmt.compact(metrics.total_calls)" tone="primary" icon="activity" foot="开站至今" />' +
                  '</div>' +

                  '<aigw-card title="调用趋势" sub="窗口内每日调用量 / Token / 费用" class="mt-16">' +
                    '<div v-if="!daily.length"><aigw-empty icon="chart" title="暂无调用" desc="窗口内还没有调用记录" /></div>' +
                    '<aigw-line v-else :data="daily" value-key="calls" label-key="day" unit=" 次" :height="200" />' +
                  '</aigw-card>' +

                  '<div class="grid grid--2 mt-16">' +
                    '<aigw-card title="结果分布" sub="窗口内成功 / 失败">' +
                      '<aigw-donut :items="resultItems" caption="总调用" unit=" 次" />' +
                    '</aigw-card>' +
                    '<aigw-card title="来源分布" sub="WorkBuddy / CodeBuddy 各端">' +
                      '<aigw-donut v-if="sourceItems.length" :items="sourceItems" caption="调用" unit=" 次" />' +
                      '<div v-else class="text-faint" style="font-size:12.5px">窗口内暂无来源数据</div>' +
                    '</aigw-card>' +
                  '</div>' +

                  '<div class="grid grid--2 mt-16">' +
                    '<aigw-card title="24 小时分布" sub="窗口内按小时聚合，看高峰时段">' +
                      '<div class="table__wrap">' +
                        '<table class="table"><thead><tr>' +
                          '<th>时段</th><th>占比</th><th style="text-align:right">调用</th>' +
                        '</tr></thead><tbody>' +
                          '<tr v-for="h in hourly" :key="h.hour">' +
                            '<td class="mono nowrap">{{ h.label }}</td>' +
                            '<td><aigw-bar :value="hourlyBar(h)" :color="palette[0]" /></td>' +
                            '<td class="num text-soft">{{ fmt.num(h.calls) }}</td>' +
                          '</tr>' +
                        '</tbody></table>' +
                      '</div>' +
                    '</aigw-card>' +
                    '<aigw-card title="耗时分布" sub="窗口内调用耗时直方图">' +
                      '<div class="table__wrap">' +
                        '<table class="table"><thead><tr>' +
                          '<th>耗时档</th><th>占比</th><th style="text-align:right">调用</th>' +
                        '</tr></thead><tbody>' +
                          '<tr v-for="b in buckets" :key="b.label">' +
                            '<td class="nowrap">{{ b.label }}</td>' +
                            '<td><aigw-bar :value="bucketBar(b)" :color="palette[6]" /></td>' +
                            '<td class="num text-soft">{{ fmt.num(b.calls) }}</td>' +
                          '</tr>' +
                        '</tbody></table>' +
                      '</div>' +
                    '</aigw-card>' +
                  '</div>' +

                  '<aigw-card title="供应商分布" sub="窗口内按供应商汇总" class="mt-16">' +
                    '<div v-if="!byProvider.length" class="text-faint" style="font-size:12.5px">窗口内暂无调用</div>' +
                    '<div v-else class="table__wrap">' +
                      '<table class="table"><thead><tr>' +
                        '<th>供应商</th><th>占比</th>' +
                        '<th style="text-align:right">调用</th>' +
                        '<th style="text-align:right">成功率</th>' +
                        '<th style="text-align:right">平均耗时</th>' +
                        '<th style="text-align:right">Token</th>' +
                        '<th style="text-align:right">倍率</th>' +
                        '<th style="text-align:right">费用</th>' +
                      '</tr></thead><tbody>' +
                        '<tr v-for="p in byProvider" :key="p.provider_key">' +
                          '<td class="truncate">{{ p.display_name || p.provider_key }}</td>' +
                          '<td><aigw-bar :value="providerBar(p)" :color="providerColor(p)" /></td>' +
                          '<td class="num text-soft">{{ fmt.num(p.calls) }}</td>' +
                          '<td class="num"><aigw-badge :tone="rateTone(p.calls ? p.success_calls / p.calls * 100 : 0)">' +
                            '{{ percentLabel(p.calls ? p.success_calls / p.calls * 100 : 0) }}</aigw-badge></td>' +
                          '<td class="num text-soft">{{ durationLabel(p.avg_duration) }}</td>' +
                          '<td class="num text-soft">{{ fmt.compact(p.total_tokens) }}</td>' +
                          '<td class="num text-soft">{{ rateLabel(p) }}</td>' +
                          '<td class="num text-soft">{{ costLabel(p.cost) }}</td>' +
                        '</tr>' +
                      '</tbody></table>' +
                    '</div>' +
                  '</aigw-card>' +

                  '<div class="grid grid--2 mt-16">' +
                    '<aigw-card title="模型用量排行" sub="按 Token 消耗排序（窗口内）">' +
                      '<div v-if="!byModel.length" class="text-faint" style="font-size:12.5px">窗口内暂无调用</div>' +
                      '<div v-else class="table__wrap">' +
                        '<table class="table"><thead><tr>' +
                          '<th>模型</th><th style="text-align:right">调用</th>' +
                          '<th style="text-align:right">Total Token</th>' +
                          '<th style="text-align:right">倍率</th><th style="text-align:right">费用</th>' +
                        '</tr></thead><tbody>' +
                          '<tr v-for="mm in byModel" :key="mm.provider_key + \'::\' + mm.model">' +
                            '<td class="truncate">' +
                              '<div>{{ mm.model }}</div>' +
                              '<div class="text-faint mono" style="font-size:11.5px">{{ mm.provider_key }}</div>' +
                            '</td>' +
                            '<td class="num text-soft">{{ fmt.num(mm.calls) }}</td>' +
                            '<td class="num text-soft">{{ fmt.num(mm.total_tokens) }}</td>' +
                            '<td class="num text-soft">{{ rateLabel(mm) }}</td>' +
                            '<td class="num text-soft">{{ costLabel(mm.cost) }}</td>' +
                          '</tr>' +
                        '</tbody></table>' +
                      '</div>' +
                    '</aigw-card>' +
                    '<aigw-card title="账号消耗排行" sub="按 Token 消耗排序（窗口内）">' +
                      '<div v-if="!byAccount.length" class="text-faint" style="font-size:12.5px">窗口内暂无调用</div>' +
                      '<div v-else class="table__wrap">' +
                        '<table class="table"><thead><tr>' +
                          '<th>账号</th><th>供应商</th>' +
                          '<th style="text-align:right">调用</th>' +
                          '<th style="text-align:right">Token</th>' +
                          '<th style="text-align:right">费用</th>' +
                        '</tr></thead><tbody>' +
                          '<tr v-for="(a, i) in byAccount" :key="a.provider_key + \'::\' + a.account_uid + \'::\' + i">' +
                            '<td class="truncate">{{ accountLabel(a) }}</td>' +
                            '<td class="truncate text-soft">{{ a.provider_key }}</td>' +
                            '<td class="num text-soft">{{ fmt.num(a.calls) }}</td>' +
                            '<td class="num text-soft">{{ fmt.num(a.total_tokens) }}</td>' +
                            '<td class="num text-soft">{{ costLabel(a.cost) }}</td>' +
                          '</tr>' +
                        '</tbody></table>' +
                      '</div>' +
                    '</aigw-card>' +
                  '</div>' +

                  '<div class="grid grid--2 mt-16">' +
                    '<aigw-card title="用户调用排行" sub="按调用次数排序（窗口内，仅用户维度）">' +
                      '<div v-if="!byUser.length" class="text-faint" style="font-size:12.5px">暂无用户维度数据（或当前为用户端视角）</div>' +
                      '<div v-else class="table__wrap">' +
                        '<table class="table"><thead><tr>' +
                          '<th>用户</th><th style="text-align:right">调用</th>' +
                          '<th style="text-align:right">成功率</th>' +
                          '<th style="text-align:right">平均耗时</th>' +
                          '<th style="text-align:right">Token</th>' +
                        '</tr></thead><tbody>' +
                          '<tr v-for="u in byUser" :key="u.user_id">' +
                            '<td class="truncate"><a href="#" @click.prevent="go(\'logs\')">用户 #{{ u.user_id }}</a></td>' +
                            '<td class="num text-soft">{{ fmt.num(u.calls) }}</td>' +
                            '<td class="num"><aigw-badge :tone="rateTone(u.success_rate)">{{ percentLabel(u.success_rate) }}</aigw-badge></td>' +
                            '<td class="num text-soft">{{ durationLabel(u.avg_duration) }}</td>' +
                            '<td class="num text-soft">{{ fmt.num(u.total_tokens) }}</td>' +
                          '</tr>' +
                        '</tbody></table>' +
                      '</div>' +
                    '</aigw-card>' +
                    '<aigw-card title="错误排行" sub="窗口内非成功调用的错误码分布">' +
                      '<div v-if="!errorTop.length" class="text-faint" style="font-size:12.5px">窗口内没有失败调用</div>' +
                      '<div v-else class="table__wrap">' +
                        '<table class="table"><thead><tr>' +
                          '<th>错误码</th><th style="text-align:right">次数</th>' +
                          '<th>最近一次</th><th style="text-align:right">最近发生</th>' +
                        '</tr></thead><tbody>' +
                          '<tr v-for="e in errorTop" :key="e.error_code">' +
                            '<td class="mono truncate">{{ e.error_code || "（无码）" }}</td>' +
                            '<td class="num text-soft">{{ fmt.num(e.calls) }}</td>' +
                            '<td class="truncate text-soft">{{ e.error || "—" }}</td>' +
                            '<td class="text-faint nowrap" style="font-size:12px">{{ fmt.time(e.last_at) }}</td>' +
                          '</tr>' +
                        '</tbody></table>' +
                      '</div>' +
                    '</aigw-card>' +
                  '</div>' +

                  /* ── 所有调用明细（含详情下钻） ─────────────── */
                  '<aigw-card title="所有调用明细" sub="按上方筛选下钻单条请求详情（内容留痕不截断）" class="mt-16">' +
                    '<div class="grid grid--2">' +
                      '<div class="field mb-0">' +
                        '<label class="field__label">关键词（模型 / 错误）</label>' +
                        '<input class="input" v-model="logFilters.keyword" placeholder="输入后回车搜索" @keyup.enter="searchLogs">' +
                      '</div>' +
                      '<div class="field mb-0">' +
                        '<label class="field__label">归属用户 ID</label>' +
                        '<input class="input mono" v-model="logFilters.user_id" placeholder="留空 = 全部" @keyup.enter="searchLogs">' +
                      '</div>' +
                      '<div class="field">' +
                        '<label class="field__label">供应商</label>' +
                        '<select class="select" v-model="logFilters.provider" @change="searchLogs">' +
                          '<option value="">全部供应商</option>' +
                          '<option v-for="p in providers" :key="p.provider_key" :value="p.provider_key">' +
                            '{{ p.display_name || p.provider_key }}</option>' +
                        '</select>' +
                      '</div>' +
                      '<div class="field">' +
                        '<label class="field__label">结果</label>' +
                        '<select class="select" v-model="logFilters.success" @change="searchLogs">' +
                          '<option value="">全部结果</option>' +
                          '<option value="1">成功</option>' +
                          '<option value="0">失败</option>' +
                        '</select>' +
                      '</div>' +
                    '</div>' +
                    '<div class="field__row mt-8">' +
                      '<button type="button" class="btn btn--primary btn--sm" @click="searchLogs">查询</button>' +
                      '<button type="button" class="btn btn--sm" @click="resetLogFilters">重置</button>' +
                      '<button type="button" class="btn btn--sm" :disabled="busy" @click="exportCalls">导出 CSV</button>' +
                      '<span class="text-faint" style="font-size:12.5px">共 {{ fmt.num(logs.total) }} 条</span>' +
                    '</div>' +

                    '<div v-if="logLoading" class="mt-16"><aigw-loading /></div>' +
                    '<div v-else-if="logError" class="mt-16"><aigw-empty icon="warning" title="加载失败" :desc="logError" /></div>' +
                    '<div v-else-if="!logs.items.length" class="mt-16"><aigw-empty icon="logs" title="暂无调用记录" desc="窗口内没有匹配的调用" /></div>' +
                    '<div v-else class="table__wrap mt-16">' +
                      '<table class="table">' +
                        '<thead><tr>' +
                          '<th>时间</th><th>供应商</th><th>模型</th><th>结果</th>' +
                          '<th style="text-align:right">耗时</th>' +
                          '<th style="text-align:right">首字</th>' +
                          '<th style="text-align:right">Token</th>' +
                          '<th style="text-align:right">费用</th>' +
                          '<th>账号</th><th>用户</th><th>来源</th>' +
                          '<th style="text-align:right">操作</th>' +
                        '</tr></thead>' +
                        '<tbody>' +
                          '<tr v-for="r in logs.items" :key="r.id">' +
                            '<td class="text-soft nowrap">{{ fmt.time(r.created_at) }}</td>' +
                            '<td class="truncate">{{ r.provider_key }}</td>' +
                            '<td class="truncate mono" style="font-size:12px">{{ r.model }}</td>' +
                            '<td><aigw-badge :tone="statusTone(r)" dot>{{ r.success ? "成功" : (r.status_code || "失败") }}</aigw-badge></td>' +
                            '<td class="num text-soft">{{ durationLabel(r.duration_ms) }}</td>' +
                            '<td class="num text-soft">{{ r.first_token_ms ? durationLabel(r.first_token_ms) : "—" }}</td>' +
                            '<td class="num text-soft">{{ fmt.num(tokens(r)) }}</td>' +
                            '<td class="num text-soft">{{ rowCostLabel(r) }}</td>' +
                            '<td class="truncate text-soft">{{ r.account_nickname || r.account_uid || "—" }}</td>' +
                            '<td class="truncate text-soft">{{ r.user_id > 0 ? ("#" + r.user_id) : "全局" }}</td>' +
                            '<td class="truncate text-soft" style="font-size:12px">{{ r.source || "—" }}</td>' +
                            '<td style="text-align:right" class="nowrap">' +
                              '<aigw-badge v-if="r.finish_reason === \'length\'" tone="danger" style="margin-right:6px" title="撞到 max_tokens">截断</aigw-badge>' +
                              '<aigw-badge v-if="r.has_content" tone="celadon" style="margin-right:6px">内容</aigw-badge>' +
                              '<button type="button" class="btn btn--sm" @click="openDetail(r)">详情</button>' +
                            '</td>' +
                          '</tr>' +
                        '</tbody>' +
                      '</table>' +
                    '</div>' +
                    '<div v-if="logs.pages > 1" class="mt-16">' +
                      '<div class="flex items-center justify-between">' +
                        '<span class="text-faint" style="font-size:12.5px">' +
                          '第 {{ logs.page }} / {{ logs.pages }} 页 · 共 {{ fmt.num(logs.total) }} 条</span>' +
                        '<div class="wrap">' +
                          '<button type="button" class="btn btn--sm" :disabled="logs.page <= 1" @click="goLogPage(logs.page - 1)">上一页</button>' +
                          '<button v-for="p in pageList" :key="p" type="button" class="btn btn--sm"' +
                                  ' :class="{ \'btn--primary\': p === logs.page }" @click="goLogPage(p)">{{ p }}</button>' +
                          '<button type="button" class="btn btn--sm" :disabled="logs.page >= logs.pages" @click="goLogPage(logs.page + 1)">下一页</button>' +
                        '</div>' +
                      '</div>' +
                    '</div>' +
                  '</aigw-card>' +
                '</div>' +

                /* ══════════════════════════════════════════════
                   2) 注册登录大屏
                   ══════════════════════════════════════════════ */
                '<div v-else-if="tab === \'auth\'">' +
                  '<div class="grid grid--stats mt-16">' +
                    '<aigw-stat label="注册用户（全量）" :value="fmt.num(metrics.users_total)" tone="primary" icon="users"' +
                              ' :foot="\'启用 \' + fmt.num(metrics.users_active) + \' / 禁用 \' + fmt.num(metrics.users_disabled)" />' +
                    '<aigw-stat label="窗口内注册" :value="fmt.num(authOverall.register_total)" tone="celadon" icon="plus"' +
                              ' :foot="\'今日 \' + fmt.num(authOverall.register_today) + \' · 本周 \' + fmt.num(authOverall.register_week)" />' +
                    '<aigw-stat label="窗口内登录" :value="fmt.num(authOverall.login_total)" tone="plum" icon="key"' +
                              ' :foot="\'今日 \' + fmt.num(authOverall.login_today) + \' · 本周 \' + fmt.num(authOverall.login_week)" />' +
                    '<aigw-stat label="独立登录用户" :value="fmt.num(authOverall.login_users)" tone="amber" icon="accounts"' +
                              ' foot="窗口内去重" />' +
                    '<aigw-stat label="登录失败" :value="fmt.num(authOverall.login_failed)" tone="rust" icon="warning"' +
                              ' foot="窗口内 login.fail" />' +
                    '<aigw-stat label="登录被锁" :value="fmt.num(authOverall.login_locked)" tone="rust" icon="shield"' +
                              ' foot="窗口内 login.locked" />' +
                  '</div>' +

                  '<aigw-card title="注册 / 登录趋势" sub="窗口内每日注册、登录、失败与锁定" class="mt-16">' +
                    '<div v-if="!authDaily.length"><aigw-empty icon="chart" title="暂无数据" desc="窗口内没有注册或登录事件" /></div>' +
                    '<aigw-line v-else :data="authDaily" value-key="register" label-key="day" unit=" 人" :height="180" />' +
                    '<div v-if="authDaily.length" class="table__wrap mt-16">' +
                      '<table class="table"><thead><tr>' +
                        '<th>日期</th><th style="text-align:right">注册</th>' +
                        '<th style="text-align:right">登录</th>' +
                        '<th style="text-align:right">登录失败</th>' +
                        '<th style="text-align:right">被锁定</th>' +
                        '<th>注册占比</th>' +
                      '</tr></thead><tbody>' +
                        '<tr v-for="d in authDaily" :key="d.day">' +
                          '<td class="nowrap">{{ fmt.dayLabel(d.day) }}</td>' +
                          '<td class="num"><b>{{ fmt.num(d.register) }}</b></td>' +
                          '<td class="num text-soft">{{ fmt.num(d.login) }}</td>' +
                          '<td class="num text-soft">{{ fmt.num(d.failed) }}</td>' +
                          '<td class="num text-soft">{{ fmt.num(d.locked) }}</td>' +
                          '<td><aigw-bar :value="registerBar(d)" :color="palette[2]" /></td>' +
                        '</tr>' +
                      '</tbody></table>' +
                    '</div>' +
                  '</aigw-card>' +

                  '<div class="grid grid--2 mt-16">' +
                    '<aigw-card title="注册来源 IP 排行" sub="窗口内按来源 IP 聚合，批量注册一眼可见">' +
                      '<div v-if="!topRegisterIps.length" class="text-faint" style="font-size:12.5px">窗口内没有注册事件</div>' +
                      '<div v-else class="table__wrap">' +
                        '<table class="table"><thead><tr>' +
                          '<th>来源 IP</th><th>占比</th>' +
                          '<th style="text-align:right">注册数</th>' +
                          '<th style="text-align:right">不同用户</th>' +
                        '</tr></thead><tbody>' +
                          '<tr v-for="i in topRegisterIps" :key="i.ip">' +
                            '<td class="mono truncate">{{ i.ip }}</td>' +
                            '<td><aigw-bar :value="regIpBar(i)" :color="palette[0]" /></td>' +
                            '<td class="num text-soft">{{ fmt.num(i.events) }}</td>' +
                            '<td class="num text-soft">{{ fmt.num(i.users) }}</td>' +
                          '</tr>' +
                        '</tbody></table>' +
                      '</div>' +
                    '</aigw-card>' +
                    '<aigw-card title="登录失败来源 IP" sub="窗口内按 IP 聚合，撞库排查">' +
                      '<div v-if="!topFailIps.length" class="text-faint" style="font-size:12.5px">窗口内没有登录失败</div>' +
                      '<div v-else class="table__wrap">' +
                        '<table class="table"><thead><tr>' +
                          '<th>来源 IP</th><th>占比</th><th style="text-align:right">失败次数</th>' +
                        '</tr></thead><tbody>' +
                          '<tr v-for="i in topFailIps" :key="i.ip">' +
                            '<td class="mono truncate">{{ i.ip }}</td>' +
                            '<td><aigw-bar :value="failBar(i)" :color="palette[4]" /></td>' +
                            '<td class="num text-soft">{{ fmt.num(i.events) }}</td>' +
                          '</tr>' +
                        '</tbody></table>' +
                      '</div>' +
                    '</aigw-card>' +
                  '</div>' +

                  '<div class="grid grid--2 mt-16">' +
                    '<aigw-card title="最近注册" sub="窗口内最近 20 条注册事件">' +
                      '<div v-if="!recentRegister.length" class="text-faint" style="font-size:12.5px">暂无注册记录</div>' +
                      '<div v-else class="table__wrap">' +
                        '<table class="table"><thead><tr>' +
                          '<th>时间</th><th>用户 #</th><th>操作者</th><th>来源 IP</th>' +
                        '</tr></thead><tbody>' +
                          '<tr v-for="(r, i) in recentRegister" :key="\'rg\' + i">' +
                            '<td class="text-soft nowrap" style="font-size:12px">{{ fmt.time(r.created_at) }}</td>' +
                            '<td class="nowrap">{{ r.user_id > 0 ? ("#" + r.user_id) : "—" }}</td>' +
                            '<td class="truncate">{{ r.actor_name || "—" }}</td>' +
                            '<td class="mono text-soft truncate">{{ r.ip || "—" }}</td>' +
                          '</tr>' +
                        '</tbody></table>' +
                      '</div>' +
                    '</aigw-card>' +
                    '<aigw-card title="最近登录" sub="窗口内最近 20 条登录成功事件">' +
                      '<div v-if="!recentLogin.length" class="text-faint" style="font-size:12.5px">暂无登录记录</div>' +
                      '<div v-else class="table__wrap">' +
                        '<table class="table"><thead><tr>' +
                          '<th>时间</th><th>用户 #</th><th>操作者</th><th>来源 IP</th>' +
                        '</tr></thead><tbody>' +
                          '<tr v-for="(r, i) in recentLogin" :key="\'lg\' + i">' +
                            '<td class="text-soft nowrap" style="font-size:12px">{{ fmt.time(r.created_at) }}</td>' +
                            '<td class="nowrap">{{ r.user_id > 0 ? ("#" + r.user_id) : "管理员" }}</td>' +
                            '<td class="truncate">{{ r.actor_name || "—" }}</td>' +
                            '<td class="mono text-soft truncate">{{ r.ip || "—" }}</td>' +
                          '</tr>' +
                        '</tbody></table>' +
                      '</div>' +
                    '</aigw-card>' +
                  '</div>' +

                  '<div class="field__row mt-16">' +
                    '<button type="button" class="btn btn--sm" @click="go(\'users\')">前往用户管理</button>' +
                    '<button type="button" class="btn btn--sm" @click="go(\'audit\')">查看完整行为审计</button>' +
                  '</div>' +
                '</div>' +

                /* ══════════════════════════════════════════════
                   3) 指标大屏（累计调用 / 成功率 / 平均耗时 / Token）
                   ══════════════════════════════════════════════ */
                '<div v-else>' +
                  '<div class="grid grid--stats mt-16">' +
                    '<aigw-stat label="累计调用" :value="fmt.compact(metrics.total_calls)" tone="primary" icon="activity"' +
                              ' foot="开站至今全部调用" />' +
                    '<aigw-stat label="累计成功率" :value="percentLabel(metrics.total_success_rate)"' +
                              ' :tone="rateTone(metrics.total_success_rate)" icon="check"' +
                              ' :foot="\'成功 \' + fmt.num(metrics.total_success_calls) + \' / 失败 \' + fmt.num(metrics.total_failed_calls)" />' +
                    '<aigw-stat label="累计平均耗时" :value="durationLabel(metrics.total_avg_duration)" tone="celadon" icon="timer"' +
                              ' :foot="\'平均首字 \' + durationLabel(metrics.total_avg_first_token)" />' +
                    '<aigw-stat label="累计消耗 Token" :value="tokensLabel(metrics.total_tokens)" tone="plum" icon="dashboard"' +
                              ' foot="输入 + 输出" />' +
                    '<aigw-stat label="累计输入 Token" :value="tokensLabel(metrics.total_prompt_tokens)" tone="amber" icon="upload"' +
                              ' foot="prompt_tokens 合计" />' +
                    '<aigw-stat label="累计输出 Token" :value="tokensLabel(metrics.total_completion_tokens)" tone="clay" icon="download"' +
                              ' foot="completion_tokens 合计" />' +
                    '<aigw-stat label="累计缓存 Token" :value="tokensLabel(metrics.total_cache_read_tokens)" tone="celadon" icon="bolt"' +
                              ' :foot="\'写入 \' + fmt.compact(metrics.total_cache_write_tokens)" />' +
                    '<aigw-stat label="累计推理 Token" :value="tokensLabel(metrics.total_reasoning_tokens)" tone="plum" icon="cpu"' +
                              ' foot="reasoning_tokens 合计" />' +
                    '<aigw-stat label="累计预估费用" :value="costLabel(metrics.total_cost)" tone="rust" icon="money"' +
                              ' foot="total_tokens × 倍率 ÷ 1000" />' +
                    '<aigw-stat label="流式调用" :value="fmt.num(metrics.total_stream_calls)" tone="primary" icon="bolt"' +
                              ' foot="streaming = 1" />' +
                    '<aigw-stat label="活跃调用用户" :value="fmt.num(metrics.active_callers)" tone="amber" icon="users"' +
                              ' foot="去重 user_id" />' +
                    '<aigw-stat label="活跃账号" :value="fmt.num(metrics.active_accounts)" tone="celadon" icon="key"' +
                              ' foot="去重 account_uid" />' +
                  '</div>' +

                  '<aigw-card title="核心指标对照" sub="全时段累计 vs 最近 N 天窗口" class="mt-16">' +
                    '<div class="table__wrap">' +
                      '<table class="table"><thead><tr>' +
                        '<th>指标</th><th style="text-align:right">累计（开站至今）</th>' +
                        '<th style="text-align:right">窗口（近 {{ days }} 天）</th>' +
                        '<th>窗口占比</th>' +
                      '</tr></thead>' +
                      '<tbody>' +
                        '<tr>' +
                          '<td>调用次数</td>' +
                          '<td class="num"><b>{{ fmt.num(metrics.total_calls) }}</b></td>' +
                          '<td class="num">{{ fmt.num(metrics.window_calls) }}</td>' +
                          '<td><aigw-bar :value="barWidth(metrics.window_calls, metrics.total_calls)" :color="palette[0]" /></td>' +
                        '</tr>' +
                        '<tr>' +
                          '<td>成功率</td>' +
                          '<td class="num"><aigw-badge :tone="rateTone(metrics.total_success_rate)">{{ percentLabel(metrics.total_success_rate) }}</aigw-badge></td>' +
                          '<td class="num"><aigw-badge :tone="rateTone(metrics.window_success_rate)">{{ percentLabel(metrics.window_success_rate) }}</aigw-badge></td>' +
                          '<td class="text-faint" style="font-size:12px">—</td>' +
                        '</tr>' +
                        '<tr>' +
                          '<td>平均耗时</td>' +
                          '<td class="num">{{ durationLabel(metrics.total_avg_duration) }}</td>' +
                          '<td class="num">{{ durationLabel(metrics.window_avg_duration) }}</td>' +
                          '<td class="text-faint" style="font-size:12px">—</td>' +
                        '</tr>' +
                        '<tr>' +
                          '<td>消耗 Token</td>' +
                          '<td class="num"><b>{{ fmt.num(metrics.total_tokens) }}</b></td>' +
                          '<td class="num">{{ fmt.num(metrics.window_tokens) }}</td>' +
                          '<td><aigw-bar :value="barWidth(metrics.window_tokens, metrics.total_tokens)" :color="palette[3]" /></td>' +
                        '</tr>' +
                        '<tr>' +
                          '<td>预估费用</td>' +
                          '<td class="num">{{ costLabel(metrics.total_cost) }}</td>' +
                          '<td class="num">{{ costLabel(metrics.window_cost) }}</td>' +
                          '<td><aigw-bar :value="barWidth(metrics.window_cost, metrics.total_cost)" :color="palette[4]" /></td>' +
                        '</tr>' +
                      '</tbody></table>' +
                    '</div>' +
                  '</aigw-card>' +

                  '<div class="grid grid--2 mt-16">' +
                    '<aigw-card title="供应商用量分布" sub="窗口内按调用次数">' +
                      '<aigw-donut v-if="providerItems.length" :items="providerItems" caption="调用" unit=" 次" />' +
                      '<div v-else class="text-faint" style="font-size:12.5px">窗口内暂无调用</div>' +
                    '</aigw-card>' +
                    '<aigw-card title="资源与用户" sub="全站规模一览">' +
                      '<div class="table__wrap">' +
                        '<table class="table"><tbody>' +
                          '<tr><td>注册用户</td><td class="num"><b>{{ fmt.num(metrics.users_total) }}</b></td></tr>' +
                          '<tr><td>启用用户</td><td class="num text-soft">{{ fmt.num(metrics.users_active) }}</td></tr>' +
                          '<tr><td>禁用用户</td><td class="num text-soft">{{ fmt.num(metrics.users_disabled) }}</td></tr>' +
                          '<tr><td>供应商（启用 / 总数）</td>' +
                              '<td class="num text-soft">{{ fmt.num(metrics.providers_enabled) }} / {{ fmt.num(metrics.providers_total) }}</td></tr>' +
                          '<tr><td>活跃账号（去重）</td><td class="num text-soft">{{ fmt.num(metrics.active_accounts) }}</td></tr>' +
                          '<tr><td>活跃模型（去重）</td><td class="num text-soft">{{ fmt.num(metrics.active_models) }}</td></tr>' +
                          '<tr><td>首次调用</td><td class="num text-soft" style="font-size:12px">{{ metrics.first_call_at || "—" }}</td></tr>' +
                          '<tr><td>最近调用</td><td class="num text-soft" style="font-size:12px">{{ metrics.last_call_at || "—" }}</td></tr>' +
                        '</tbody></table>' +
                      '</div>' +
                    '</aigw-card>' +
                  '</div>' +

                  '<aigw-card title="窗口内模型用量" sub="按 Token 消耗排序" class="mt-16">' +
                    '<div v-if="!topModels.length" class="text-faint" style="font-size:12.5px">窗口内暂无调用</div>' +
                    '<div v-else class="table__wrap">' +
                      '<table class="table"><thead><tr>' +
                        '<th>模型</th><th>供应商</th>' +
                        '<th style="text-align:right">调用</th>' +
                        '<th style="text-align:right">Token</th>' +
                        '<th style="text-align:right">倍率</th>' +
                        '<th style="text-align:right">费用</th>' +
                      '</tr></thead><tbody>' +
                        '<tr v-for="mm in topModels" :key="mm.provider_key + \'::\' + mm.model">' +
                          '<td class="truncate">{{ mm.model }}</td>' +
                          '<td class="truncate text-soft">{{ mm.provider_key }}</td>' +
                          '<td class="num text-soft">{{ fmt.num(mm.calls) }}</td>' +
                          '<td class="num text-soft">{{ fmt.num(mm.total_tokens) }}</td>' +
                          '<td class="num text-soft">{{ rateLabel(mm) }}</td>' +
                          '<td class="num text-soft">{{ costLabel(mm.cost) }}</td>' +
                        '</tr>' +
                      '</tbody></table>' +
                    '</div>' +
                  '</aigw-card>' +
                '</div>' +

              '</div>' +

              /* ── 请求详情（与「调用日志」详情同构，含内容留痕） ── */
              '<aigw-modal v-if="showDetail && detail" title="请求详情" wide hide-foot @close="showDetail = false">' +
                '<div class="grid grid--2">' +
                  '<div class="field"><label class="field__label">时间</label>' +
                    '<input class="input" :value="fmt.time(detail.created_at)" readonly></div>' +
                  '<div class="field"><label class="field__label">供应商</label>' +
                    '<input class="input" :value="detail.provider_key" readonly></div>' +
                  '<div class="field"><label class="field__label">模型</label>' +
                    '<input class="input mono" :value="detail.model" readonly></div>' +
                  '<div class="field"><label class="field__label">客户端原始模型（requested_model）</label>' +
                    '<input class="input mono" :value="requestedModelLabel(detail)" readonly></div>' +
                  '<div class="field"><label class="field__label">结果 / 状态码</label>' +
                    '<input class="input" :value="(detail.success ? \'成功\' : \'失败\') + \' · \' + (detail.status_code || \'\')" readonly></div>' +
                  '<div class="field"><label class="field__label">耗时</label>' +
                    '<input class="input" :value="fmt.duration(detail.duration_ms)" readonly></div>' +
                  '<div class="field"><label class="field__label">首字耗时</label>' +
                    '<input class="input" :value="fmt.duration(detail.first_token_ms)" readonly></div>' +
                  '<div class="field"><label class="field__label">Token（输入 / 输出）</label>' +
                    '<input class="input" :value="(detail.prompt_tokens || 0) + \' / \' + (detail.completion_tokens || 0)" readonly></div>' +
                  '<div class="field"><label class="field__label">收尾原因（finish_reason）</label>' +
                    '<input class="input mono" :value="finishLabel(detail)" readonly></div>' +
                  '<div class="field"><label class="field__label">推理 Token</label>' +
                    '<input class="input" :value="fmt.num(detail.reasoning_tokens || 0)" readonly></div>' +
                  '<div class="field"><label class="field__label">缓存 Token（读 / 写）</label>' +
                    '<input class="input" :value="(detail.cache_read_tokens || 0) + \' / \' + (detail.cache_write_tokens || 0)" readonly></div>' +
                  '<div class="field"><label class="field__label">计费倍率</label>' +
                    '<input class="input" :value="rateLabel(detail)" readonly></div>' +
                  '<div class="field"><label class="field__label">预估费用</label>' +
                    '<input class="input" :value="rowCostLabel(detail)" readonly></div>' +
                  '<div class="field"><label class="field__label">重试次数</label>' +
                    '<input class="input" :value="detail.retry_count || 0" readonly></div>' +
                  '<div class="field"><label class="field__label">账号</label>' +
                    '<input class="input" :value="detail.account_nickname || detail.account_uid || \'—\'" readonly></div>' +
                  '<div class="field"><label class="field__label">归属用户</label>' +
                    '<input class="input" :value="detail.user_id > 0 ? (\'用户 #\' + detail.user_id) : \'管理员 / 全局\'" readonly></div>' +
                  '<div class="field"><label class="field__label">来源</label>' +
                    '<input class="input" :value="detail.source || \'—\'" readonly></div>' +
                  '<div class="field"><label class="field__label">请求 ID</label>' +
                    '<input class="input mono" :value="detail.request_id || \'—\'" readonly></div>' +
                  '<div class="field"><label class="field__label">来源 IP</label>' +
                    '<input class="input mono" :value="detail.client_ip || \'—\'" readonly></div>' +
                '</div>' +
                '<div v-if="detail.error" class="field mt-8">' +
                  '<label class="field__label">错误信息</label>' +
                  '<textarea class="textarea" readonly rows="4" :value="detail.error"></textarea>' +
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
                  '<textarea v-else class="textarea mono" readonly rows="12" :value="detailLoading ? \'加载中…\' : (detail.request_body || \'（未记录或为空）\')"></textarea>' +
                  '<div class="text-faint" style="font-size:12px;margin-top:6px">' +
                    '完整保留，不截断；思维注入块显示全文（块首尾的 ops-notes 锚点即注入标记）。' +
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
                  '<textarea v-if="!detailError" class="textarea mono" readonly rows="12" :value="detailLoading ? \'加载中…\' : (detail.response_body || \'（未记录或为空）\')"></textarea>' +
                '</div>' +
              '</aigw-modal>' +
            '</div>'
    };

    global.AigwPagesRegister(pages);
})(window);
