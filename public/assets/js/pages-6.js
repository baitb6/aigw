/* ============================================================
   页面组件（续 5）：思维注入（对应客户端「破甲」能力的网关侧实现）
   ------------------------------------------------------------
   与「提示词模板」的区别：
     - 提示词模板 = 面向业务场景的 system 片段（可按模型/别名挑选）
     - 思维注入   = 面向身份的 system 顶层思维，网关转发上游前统一改写
   管理员维护全局思维（对所有调用生效）；普通用户只能维护本人自建思维，
   全局思维对其只读（不回正文）。靶点开关 / 预演 / 自证 / 修订属管理台能力。
   ============================================================ */
(function (global) {
    'use strict';

    var S = global.AigwStore;
    var api = global.AigwApi;
    var fmt = S.fmt;
    var pages = {};

    var BLANK_FORM = {
        id: 0,
        name: '',
        description: '',
        content: '',
        mode: 'prepend',
        enabled: true,
        is_default: false,
        targets: [],
        models: [],
        selfcheck_on: true,
        selfcheck_pass: '思维自检',
        selfcheck_reply: '思维自检成功'
    };

    pages.PagePersonas = {
        data: function () {
            return {
                loading: true, busy: false, error: '',

                items: [], globals: [],
                modes: [], targets: [], models: [], status: null,
                defaults: { passphrase: '思维自检', reply: '思维自检成功' },

                // 治理闸门：customAllowed=false 时普通用户不能自定义思维；
                // systemEnabled=false 时系统内置（全局）思维不注入
                customAllowed: true, systemEnabled: false,

                // 新建 / 编辑
                showEdit: false, form: Object.assign({}, BLANK_FORM),
                modelInput: '',

                // 靶点
                showTargets: false, targetPersona: null, targetStates: [], targetBusy: '',

                // 修订
                showRevisions: false, revPersona: null, revisions: [], revisionsLoading: false,

                // 预演
                showPreview: false, previewPersona: null, previewSample: '你是一个乐于助人的 AI 助手。',
                previewResult: null, previewLoading: false,

                // 自证
                checkText: '思维自检', checkResult: null, checking: false,

                // 用户端：平台开关（自选在哪些平台接受注入）+ 思维开关（自选哪一份思维）
                // 两者都默认全部关闭；思维开关是**互斥单选**，同一时间只能开一份
                platforms: [], platformBusy: '', enableBusy: '',
                activePersona: null,
                userCheckText: '', userCheckResult: null, userChecking: false,

                // 正文查看
                showBody: false, bodyTitle: '', bodyContent: ''
            };
        },

        computed: {
            isAdmin: function () { return S.state.role !== 'user'; },

            /** 是否可维护思维：管理员恒可；普通用户受治理开关约束 */
            canManage: function () { return this.isAdmin || this.customAllowed; },

            targetRows: function () {
                return (this.status && this.status.targets) || [];
            },

            warnings: function () {
                return (this.status && this.status.warnings) || [];
            },

            summary: function () {
                var self = this;
                var hits = 0;
                this.items.forEach(function (p) { hits += Number(p.hit_count || 0); });

                var active = (this.status && this.status.active) || null;
                var on = 0;
                this.targetRows.forEach(function (t) {
                    if (active && Number(t.persona_id) === Number(active.id) && t.enabled) on++;
                });

                return {
                    total: this.items.length,
                    activeName: active ? active.name : '未启用',
                    activeSub: active
                        ? ('v' + active.version + ' · ' + self.modeShort(active.mode) + ' · ' + fmt.num(active.bytes) + ' B')
                        : '当前没有启用中的思维，网关按原样透传',
                    targetOn: on,
                    hits: hits
                };
            },

            /** 网关自证调用示例（管理员 / 普通用户通用） */
            selfcheckSnippet: function () {
                var pass = this.isAdmin
                    ? (this.defaults.passphrase || '思维自检')
                    : (this.userPassphrase || '（先在下面开启一份思维）');
                return 'curl -X POST ' + location.origin + '/v1/chat/completions \\\n' +
                    '  -H "Authorization: Bearer <你的 API Key>" \\\n' +
                    '  -H "Content-Type: application/json" \\\n' +
                    '  -d \'{"model":"<模型 ID>","messages":[{"role":"user","content":"' + pass + '"}]}\'';
            },

            /**
             * 普通用户可见的自证口令：**跟随「当前开启的那一份思维」**。
             * 没开启任何思维时返回空串，由卡片给出引导文案。
             */
            userPassphrase: function () {
                var a = this.activePersona;
                return (a && a.selfcheck_pass) || '';
            },

            /** 当前开启的那份思维名（文案用） */
            activePersonaName: function () {
                return this.activePersona ? this.activePersona.name : '';
            }
        },

        mounted: function () { this.load(); },

        methods: {
            /* ── 载入 ───────────────────────────────────────── */
            load: function () {
                var self = this;
                this.loading = true;

                return api.scoped.personas().then(function (res) {
                    self.loading = false;
                    if (!res.ok) { self.error = res.error || '加载失败'; return; }
                    self.error = '';

                    var d = res.data || {};
                    self.items = d.items || [];
                    self.globals = d.global || [];
                    self.modes = d.modes || [];
                    self.targets = d.targets || [];
                    self.models = d.models || [];
                    self.status = d.status || null;
                    self.customAllowed = d.custom_allowed !== false;
                    self.systemEnabled = d.system_enabled === true;
                    self.defaults = d.defaults || { passphrase: '思维自检', reply: '思维自检成功' };
                    self.platforms = d.platforms || [];
                    self.activePersona = d.active_persona || null;
                    if (!self.checkText) self.checkText = self.defaults.passphrase;
                    if (!self.userCheckText && self.activePersona) self.userCheckText = self.userPassphrase;
                });
            },

            refreshStatus: function () {
                if (!this.isAdmin) return Promise.resolve();
                var self = this;
                return api.admin.personaStatus().then(function (res) {
                    if (res.ok && res.data) {
                        self.status = res.data.status || self.status;
                        self.targets = res.data.targets || self.targets;
                    }
                });
            },

            /* ── 展示辅助 ───────────────────────────────────── */
            modeShort: function (mode) {
                return { prepend: '前置', append: '后置', replace: '替换' }[mode] || mode || '—';
            },

            modeTone: function (mode) {
                return { prepend: 'primary', append: 'celadon', replace: 'rust' }[mode] || 'muted';
            },

            targetLabel: function (key) {
                for (var i = 0; i < this.targets.length; i++) {
                    if (this.targets[i].value === key) return this.targets[i].label || key;
                }
                return key;
            },

            scopeLabel: function (p) {
                return Number(p.user_id) === 0 ? '全局' : '本人';
            },

            /** 模型白名单展示：空=全部模型；多了就折叠成「…等 N 项」 */
            modelScopeLabel: function (p) {
                var m = (p && p.models) || [];
                if (!m.length) return '全部模型';
                if (m.length <= 2) return '模型 ' + m.join(' / ');
                return '模型 ' + m.slice(0, 2).join(' / ') + ' 等 ' + m.length + ' 项';
            },

           /* ── 一键灌入默认思维 ────────────────────────────── */
            seed: function () {
                var self = this;
                this.busy = true;
                api.admin.seedPersona().then(function (res) {
                    self.busy = false;
                    if (S.report(res)) self.load();
                });
            },

            /* ── 新建 / 编辑 ─────────────────────────────────── */
            openCreate: function () {
                this.form = Object.assign({}, BLANK_FORM, {
                    selfcheck_pass: this.defaults.passphrase,
                    selfcheck_reply: this.defaults.reply
                });
                this.showEdit = true;
            },

            openEdit: function (row) {
                this.form = {
                    id: row.id,
                    name: row.name || '',
                    description: row.description || '',
                    content: row.content || '',
                    mode: row.mode || 'prepend',
                    enabled: !!row.enabled,
                    is_default: !!row.is_default,
                    targets: (row.targets || []).slice(),
                    models: (row.models || []).slice(),
                    selfcheck_on: !!row.selfcheck_on,
                    selfcheck_pass: row.selfcheck_pass || '',
                    selfcheck_reply: row.selfcheck_reply || ''
                };
                this.showEdit = true;
            },

            toggleFormTarget: function (key) {
                var i = this.form.targets.indexOf(key);
                if (i === -1) this.form.targets.push(key);
                else this.form.targets.splice(i, 1);
            },

            isFormTarget: function (key) { return this.form.targets.indexOf(key) !== -1; },

            /* ── 模型白名单（精准开启的模型维度） ──────────────── */
            isFormModel: function (key) { return this.form.models.indexOf(key) !== -1; },

            toggleFormModel: function (key) {
                var i = this.form.models.indexOf(key);
                if (i === -1) this.form.models.push(key);
                else this.form.models.splice(i, 1);
            },

            removeFormModel: function (key) {
                var i = this.form.models.indexOf(key);
                if (i !== -1) this.form.models.splice(i, 1);
            },

            /** 手填模型 ID 或前缀通配（如 gpt-4*） */
            addFormModel: function () {
                var v = (this.modelInput || '').trim().toLowerCase();
                if (!v) return;
                if (this.form.models.indexOf(v) === -1) this.form.models.push(v);
                this.modelInput = '';
            },

            submitForm: function () {
                var self = this;
                var payload = {
                    name: this.form.name,
                    description: this.form.description,
                    content: this.form.content,
                    mode: this.form.mode,
                    enabled: this.form.enabled,
                    is_default: this.form.is_default,
                    targets: this.form.targets,
                    models: this.form.models,
                    selfcheck_on: this.form.selfcheck_on,
                    selfcheck_pass: this.form.selfcheck_pass,
                    selfcheck_reply: this.form.selfcheck_reply
                };

                this.busy = true;
                var p = this.form.id > 0
                    ? api.scoped.updatePersona(this.form.id, payload)
                    : api.scoped.createPersona(payload);

                p.then(function (res) {
                    self.busy = false;
                    if (S.report(res)) { self.showEdit = false; self.load(); }
                });
            },

            /* ── 行内操作 ───────────────────────────────────── */
            toggleEnabled: function (row) {
                var self = this;
                api.scoped.updatePersona(row.id, { enabled: !row.enabled }).then(function (res) {
                    if (S.report(res)) self.load();
                });
            },

            setDefault: function (row) {
                var self = this;
                if (this.isAdmin) {
                    api.admin.setDefaultPersona(row.id, true).then(function (res) {
                        if (S.report(res)) self.load();
                    });
                    return;
                }
                api.user.updatePersona(row.id, { is_default: true, enabled: true }).then(function (res) {
                    if (S.report(res)) self.load();
                });
            },

            remove: function (row) {
                var self = this;
                global.AigwConfirm('将删除思维「' + row.name + '」，其修订快照与靶点状态一并清理，不可恢复。', {
                    title: '删除思维', danger: true, confirmText: '删除'
                }).then(function (yes) {
                    if (!yes) return;
                    self.busy = true;
                    api.scoped.deletePersona(row.id).then(function (res) {
                        self.busy = false;
                        if (S.report(res)) self.load();
                    });
                });
            },

            viewBody: function (row) {
                var self = this;
                // 列表接口已带正文（管理台），用户端需要单独取回
                if (row.content) {
                    this.bodyTitle = row.name;
                    this.bodyContent = row.content;
                    this.showBody = true;
                    return;
                }
                if (!this.isAdmin) { S.toast('仅可查看本人自建思维的正文', 'warning'); return; }
                api.admin.persona(row.id).then(function (res) {
                    if (!res.ok) { S.report(res); return; }
                    self.bodyTitle = (res.data.persona || {}).name || row.name;
                    self.bodyContent = (res.data.persona || {}).content || '';
                    self.showBody = true;
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
            },

            /* ── 靶点开关（管理台） ────────────────────────── */
            openTargets: function (row) {
                var self = this;
                this.targetPersona = row;
                this.targetStates = [];
                this.showTargets = true;
                api.admin.persona(row.id).then(function (res) {
                    if (!res.ok) { S.report(res); return; }
                    self.targetStates = (res.data.targets || []).map(function (t) {
                        t.label = self.targetLabel(t.target);
                        t.enabled = !!t.enabled;
                        return t;
                    });
                });
            },

            toggleTarget: function (state) {
                var self = this;
                if (!state.in_scope) {
                    S.toast('该靶点不在本白名单内，请先在「编辑」里把它加入白名单', 'warning');
                    return;
                }
                this.targetBusy = state.target;
                api.admin.setPersonaTarget(this.targetPersona.id, state.target, !state.enabled).then(function (res) {
                    self.targetBusy = '';
                    if (!S.report(res)) return;
                    state.enabled = !state.enabled;
                    self.refreshStatus();
                });
            },

            /* ── 修订快照（管理台） ────────────────────────── */
            openRevisions: function (row) {
                var self = this;
                this.revPersona = row;
                this.revisions = [];
                this.revisionsLoading = true;
                this.showRevisions = true;
                api.admin.personaRevisions(row.id).then(function (res) {
                    self.revisionsLoading = false;
                    if (!res.ok) { S.report(res); return; }
                    self.revisions = (res.data.items || []).map(function (r) {
                        r.seal_short = (r.seal || '').slice(0, 12) || '—';
                        return r;
                    });
                });
            },

            restore: function (rev) {
                var self = this;
                global.AigwConfirm('将把思维正文还原到 v' + rev.version + '（快照 #' + rev.id + '）。'
                    + '当前版本会先自动留档，可再次还原回来。', {
                    title: '还原到快照', confirmText: '还原'
                }).then(function (yes) {
                    if (!yes) return;
                    self.busy = true;
                    api.admin.restorePersona(self.revPersona.id, rev.id).then(function (res) {
                        self.busy = false;
                        if (S.report(res)) { self.showRevisions = false; self.load(); }
                    });
                });
            },

            /* ── 预演（管理台） ────────────────────────────── */
            openPreview: function (row) {
                this.previewPersona = row;
                this.previewResult = null;
                this.showPreview = true;
                this.runPreview();
            },

            runPreview: function () {
                var self = this;
                this.previewLoading = true;
                api.admin.previewPersona({
                    persona_id: this.previewPersona.id,
                    sample_system: this.previewSample
                }).then(function (res) {
                    self.previewLoading = false;
                    if (!res.ok) { S.report(res); self.previewResult = null; return; }
                    self.previewResult = res.data || null;
                });
            },

            /* ── 自证（管理台） ────────────────────────────── */
            runSelfcheck: function () {
                var self = this;
                if (!this.checkText) { S.toast('请输入要测试的文本', 'warning'); return; }
                this.checking = true;
                api.admin.selfcheckPersona({ text: this.checkText }).then(function (res) {
                    self.checking = false;
                    if (!res.ok) { S.report(res); self.checkResult = null; return; }
                    self.checkResult = res.data || null;
                });
            },

            /* ── 用户端：平台开关（自选在哪些平台接受注入） ────── */
            togglePlatform: function (p) {
                var self = this;
                this.platformBusy = p.target;
                api.user.setPersonaPlatform(p.target, !p.enabled).then(function (res) {
                    self.platformBusy = '';
                    if (!S.report(res)) return;
                    p.enabled = !p.enabled;
                    var list = (res.data && res.data.platforms) || null;
                    if (list) self.platforms = list;
                });
            },

            /* ── 用户端：思维开关（自选哪几份思维对自己生效，默认全关） ── */
            togglePersonaEnable: function (p) {
                var self = this;
                var on = !p.user_on;
                this.enableBusy = p.id;
                api.user.setPersonaEnable(p.id, on).then(function (res) {
                    self.enableBusy = '';
                    if (!S.report(res)) return;
                    if (on) S.toast('已切换到「' + p.name + '」（同时只能开启一份）', 'success');
                    else S.toast('已关闭「' + p.name + '」');
                    self.load();
                });
            },

            /* ── 用户端：自证测试（走用户作用域，无需管理台权限） ── */
            runUserSelfcheck: function () {
                var self = this;
                if (!this.userCheckText) { S.toast('请输入要测试的文本', 'warning'); return; }
                this.userChecking = true;
                api.user.selfcheckPersona(this.userCheckText).then(function (res) {
                    self.userChecking = false;
                    if (!res.ok) { S.report(res); self.userCheckResult = null; return; }
                    self.userCheckResult = res.data || null;
                });
            }
        },

        template:
            '<div>' +
              '<div class="page-head">' +
                '<div class="page-head__main">' +
                  '<div class="topbar__title">思维注入</div>' +
                  '<div class="topbar__desc">网关在转发上游前改写 system 思维 —— 放在网关侧生效，不改动任何本地文件</div>' +
                '</div>' +
                '<div class="page-head__actions">' +
                  '<button v-if="isAdmin" type="button" class="btn btn--sm" :disabled="busy" @click="seed">一键灌入默认思维</button>' +
                  '<button type="button" class="btn btn--sm" :disabled="loading" @click="load">刷新</button>' +
                  '<button v-if="canManage" type="button" class="btn btn--sm btn--primary" @click="openCreate">新建思维</button>' +
                '</div>' +
              '</div>' +

              /* ── 系统（全局）思维：置顶 + 平台开关（用户端） ── */
              '<aigw-card v-if="!isAdmin" title="系统思维（全局）"' +
                          ' sub="由管理员维护；默认全部关闭，且同一时间只能开启一份 —— 开关打开 + 平台打开才会注入" class="mt-16">' +
                '<div v-if="!globals.length">' +
                  '<aigw-empty icon="layers" title="暂无系统思维" desc="管理员尚未配置系统思维" />' +
                '</div>' +
                '<template v-else>' +
                  '<div class="table__wrap">' +
                    '<table class="table">' +
                      '<thead><tr>' +
                        '<th>思维</th><th>注入位置</th><th>生效范围</th><th>版本</th>' +
                        '<th style="text-align:right">字节</th><th>状态</th><th>我的开关（单选）</th>' +
                      '</tr></thead>' +
                      '<tbody>' +
                        '<tr v-for="g in globals" :key="g.id">' +
                          '<td><div class="truncate">{{ g.name }}</div>' +
                            '<div class="text-faint truncate" style="font-size:12px">{{ g.description || "—" }}</div></td>' +
                          '<td><aigw-badge :tone="modeTone(g.mode)" dot>{{ modeShort(g.mode) }}</aigw-badge></td>' +
                          '<td class="text-soft" style="font-size:12px">' +
                            '{{ (g.targets && g.targets.length ? g.targets.length + " 个靶点" : "全部靶点") }} · ' +
                            '{{ (g.models && g.models.length ? g.models.length + " 个模型" : "全部模型") }}' +
                          '</td>' +
                          '<td class="text-soft nowrap">v{{ g.version }}</td>' +
                          '<td style="text-align:right" class="text-soft">{{ fmt.num(g.bytes) }}</td>' +
                          '<td><aigw-badge :tone="g.enabled ? \'success\' : \'muted\'" dot>' +
                            '{{ g.enabled ? (g.is_default ? "默认启用" : "启用") : "停用" }}</aigw-badge>' +
                            '<div class="text-faint" style="font-size:11.5px">管理员侧</div></td>' +
                          '<td class="nowrap">' +
                            '<aigw-switch :model-value="!!g.user_on" :disabled="enableBusy === g.id"' +
                                         ' @update:modelValue="togglePersonaEnable(g)" />' +
                          '</td>' +
                        '</tr>' +
                      '</tbody>' +
                    '</table>' +
                  '</div>' +
                  '<div v-if="!systemEnabled" class="note note--warn mt-16">' +
                    '<aigw-icon name="warning" :size="13" /> ' +
                    '管理员尚未开启「系统内置思维」，当前任何平台都不会注入它。' +
                  '</div>' +
                '</template>' +
                '<div class="field__hint mt-16">' +
                  '<b>平台开关</b>：选择在哪些平台上接受注入 —— <b>默认全部关闭</b>，只有打开的平台才会注入。' +
                  '上表的「我的开关」是<b>互斥单选</b>：开启一份会自动关掉其他，自证口令也跟着它走。' +
                '</div>' +
                '<div v-if="!platforms.length" class="text-faint mt-8" style="font-size:12.5px">暂无可用平台（先在「供应商」里启用上游）</div>' +
                '<div v-else class="grid grid--list mt-8">' +
                  '<div v-for="p in platforms" :key="p.target" class="modelrow">' +
                    '<div class="modelrow__main">' +
                      '<div class="modelrow__title truncate">{{ targetLabel(p.target) }}</div>' +
                      '<div class="modelrow__meta text-faint mono">{{ p.target }}</div>' +
                    '</div>' +
                    '<div class="modelrow__ops">' +
                      '<aigw-badge v-if="!p.enabled" tone="muted">已关闭</aigw-badge>' +
                      '<aigw-switch :model-value="p.enabled" :disabled="platformBusy === p.target"' +
                                   ' @update:modelValue="togglePlatform(p)" />' +
                    '</div>' +
                  '</div>' +
                '</div>' +
              '</aigw-card>' +

              /* ── 治理闸门提示 ── */
              '<div v-if="!isAdmin && !customAllowed" class="note note--warn mt-16">' +
                '<aigw-icon name="warning" :size="13" /> ' +
                '管理员已关闭「普通用户自定义思维」—— 你当前只能只读查看系统自带思维，' +
                '自建思维的新建 / 编辑 / 删除已停用。' +
              '</div>' +
              '<div v-else-if="isAdmin && !systemEnabled" class="note note--warn mt-16">' +
                '<aigw-icon name="warning" :size="13" /> ' +
                '系统内置思维总开关未开启（开箱默认）—— 网关不会注入任何系统思维。' +
                '需要生效请到「网关设置 → 功能开关中心 → 思维治理」打开「系统内置思维」。' +
              '</div>' +

              /* ── 诊断告警 ── */
              '<div v-if="warnings.length" class="mt-16">' +
                '<div v-for="(w, i) in warnings" :key="i" class="note note--warn" :class="{ \'mt-8\': i > 0 }">' +
                  '<aigw-icon name="warning" :size="13" /> {{ w }}' +
                '</div>' +
              '</div>' +

              /* ── 自证口令（用户端：跟随「当前开启的那一份思维」） ── */
              '<aigw-card v-if="!isAdmin" title="自证口令（一眼验证注入有没有生效）"' +
                          ' sub="口令跟随你当前开启的那一份思维；把口令原样发一次给网关会收到约定回执，且不消耗上游额度" class="mt-16">' +
                '<div v-if="!activePersona" class="note note--warn">' +
                  '<aigw-icon name="warning" :size="13" /> ' +
                  '你还没有开启任何思维。在下面「思维清单」里点某份思维的「开启」后，' +
                  '这里会立刻变成那份思维的自证口令。' +
                '</div>' +
                '<template v-else>' +
                  '<div class="field__hint" style="margin-bottom:8px">' +
                    '当前开启：<aigw-badge tone="success" dot>{{ activePersonaName }}</aigw-badge> ' +
                    '（{{ activePersona.layer === "system" ? "系统思维" : "本人思维" }}）' +
                  '</div>' +
                  '<div class="grid grid--2">' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">当前思维的自证口令</label>' +
                      '<div class="field__row">' +
                        '<input class="input mono" readonly :value="userPassphrase">' +
                        '<button type="button" class="btn" @click="copyText(userPassphrase)">复制</button>' +
                      '</div>' +
                      '<div class="field__hint">口令取自当前开启的那份思维。自证只校验链路与配置，不受「思维 / 平台开关」影响。</div>' +
                    '</div>' +
                    '<div class="field mb-0">' +
                      '<label class="field__label">本地试一下</label>' +
                      '<div class="field__row">' +
                        '<input class="input mono" v-model="userCheckText" placeholder="思维自检" @keyup.enter="runUserSelfcheck">' +
                        '<button type="button" class="btn btn--primary" :disabled="userChecking" @click="runUserSelfcheck">测试</button>' +
                      '</div>' +
                      '<div v-if="userCheckResult" class="field__hint">' +
                        '<aigw-badge :tone="userCheckResult.hit ? \'success\' : \'muted\'" dot>' +
                          '{{ userCheckResult.hit ? "命中" : "未命中" }}</aigw-badge> ' +
                        '<span v-if="userCheckResult.hit">回执：{{ userCheckResult.reply }} · 思维：{{ userCheckResult.persona_name }}</span>' +
                        '<span v-else>这段文本没有命中任何已启用思维的口令</span>' +
                      '</div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="codebox mt-16">' +
                    '<div class="codebox__bar">' +
                      '<span class="codebox__label">用你自己的 API Key 直接发（回执 usage 全 0，不占额度）</span>' +
                      '<button type="button" class="btn btn--sm" @click="copyText(selfcheckSnippet)">复制</button>' +
                    '</div>' +
                    '<pre class="codeblock">{{ selfcheckSnippet }}</pre>' +
                  '</div>' +
                '</template>' +
              '</aigw-card>' +

              /* ── 指标 ── */
              '<div class="grid grid--stats mt-16">' +
                '<aigw-stat label="思维总数" :value="fmt.num(summary.total)" tone="primary" icon="layers" />' +
                '<aigw-stat label="生效思维" :value="summary.activeName" :foot="summary.activeSub" tone="celadon" icon="flame" />' +
                '<aigw-stat label="启用靶点" :value="fmt.num(summary.targetOn)" tone="amber" icon="server" />' +
                '<aigw-stat label="累计注入" :value="fmt.num(summary.hits)" foot="仅统计已生效注入" tone="plum" icon="bolt" />' +
              '</div>' +

              /* ── 思维列表 ── */
              '<aigw-card title="思维清单" sub="默认全部关闭；同一时间只能开启一份（互斥单选），开启后自证口令会跟随它" flush class="mt-16">' +
                '<div v-if="loading"><aigw-loading /></div>' +
                '<div v-else-if="error"><aigw-empty icon="warning" title="加载失败" :desc="error" /></div>' +
                '<div v-else-if="!items.length">' +
                  '<aigw-empty icon="flame" title="还没有思维" ' +
                             ':desc="isAdmin ? \'可点「一键灌入默认思维」快速生成一份示例，或直接新建\' : \'新建一份思维，网关会在你的调用上注入\'" />' +
                '</div>' +
                '<div v-else class="table__wrap">' +
                  '<table class="table">' +
                    '<thead><tr>' +
                      '<th>思维</th><th>作用域</th><th>注入位置</th><th>版本</th>' +
                      '<th style="text-align:right">靶点</th>' +
                      '<th style="text-align:right">注入次数</th>' +
                      '<th>封条</th><th>状态</th>' +
                      '<th style="text-align:right">操作</th>' +
                    '</tr></thead>' +
                    '<tbody>' +
                      '<tr v-for="p in items" :key="p.id">' +
                        '<td>' +
                          '<div class="truncate">{{ p.name }}</div>' +
                          '<div class="text-faint truncate" style="font-size:12px">{{ p.description || "—" }}</div>' +
                        '</td>' +
                        '<td><aigw-badge :tone="p.user_id ? \'muted\' : \'primary\'">{{ scopeLabel(p) }}</aigw-badge></td>' +
                        '<td>' +
                          '<aigw-badge :tone="modeTone(p.mode)" dot>{{ p.mode_label || modeShort(p.mode) }}</aigw-badge>' +
                          '<div class="text-faint" style="font-size:11.5px;margin-top:2px">' +
                            '<aigw-icon name="models" :size="11" /> {{ modelScopeLabel(p) }}' +
                          '</div>' +
                        '</td>' +
                        '<td class="text-soft nowrap">v{{ p.version }}</td>' +
                        '<td style="text-align:right" class="text-soft nowrap">' +
                          '{{ fmt.num(p.target_on || 0) }} / {{ fmt.num(p.target_total || 0) }}' +
                        '</td>' +
                        '<td style="text-align:right" class="text-soft">{{ fmt.num(p.hit_count || 0) }}</td>' +
                        '<td class="nowrap">' +
                          '<span class="mono text-faint" style="font-size:12px" :title="p.seal">{{ p.seal_short || "—" }}</span>' +
                          '<aigw-badge v-if="!p.seal_ok" tone="danger" class="ml-auto">封条不符</aigw-badge>' +
                        '</td>' +
                        '<td class="nowrap">' +
                          '<template v-if="isAdmin">' +
                            '<aigw-badge v-if="p.is_default" tone="success" dot>默认</aigw-badge>' +
                            '<aigw-badge v-else-if="p.enabled" tone="primary" dot>启用</aigw-badge>' +
                            '<aigw-badge v-else tone="muted" dot>停用</aigw-badge>' +
                          '</template>' +
                          '<template v-else>' +
                            '<aigw-badge :tone="p.user_on ? \'success\' : \'muted\'" dot>' +
                              '{{ p.user_on ? "已开启" : "已关闭" }}</aigw-badge>' +
                          '</template>' +
                        '</td>' +
                        '<td style="text-align:right" class="nowrap">' +
                          '<button type="button" class="btn btn--sm" @click="viewBody(p)">正文</button> ' +
                          '<button v-if="canManage" type="button" class="btn btn--sm" @click="openEdit(p)">编辑</button> ' +
                          '<button v-if="canManage && !p.is_default" type="button" class="btn btn--sm" @click="setDefault(p)">设为默认</button> ' +
                          '<button v-if="isAdmin" type="button" class="btn btn--sm" @click="toggleEnabled(p)">' +
                            '{{ p.enabled ? "停用" : "启用" }}' +
                          '</button> ' +
                          '<button v-if="!isAdmin && canManage" type="button" class="btn btn--sm"' +
                                  ' :disabled="enableBusy === p.id" @click="togglePersonaEnable(p)">' +
                            '{{ p.user_on ? "关闭" : "开启" }}' +
                          '</button> ' +
                          '<button v-if="isAdmin" type="button" class="btn btn--sm" @click="openTargets(p)">靶点</button> ' +
                          '<button v-if="isAdmin" type="button" class="btn btn--sm" @click="openRevisions(p)">修订</button> ' +
                          '<button v-if="isAdmin" type="button" class="btn btn--sm" @click="openPreview(p)">预演</button> ' +
                          '<button v-if="canManage" type="button" class="btn btn--sm btn--danger" @click="remove(p)">删除</button>' +
                        '</td>' +
                      '</tr>' +
                    '</tbody>' +
                  '</table>' +
                '</div>' +
              '</aigw-card>' +

              /* ── 靶点矩阵 ── */
              '<aigw-card title="靶点矩阵" sub="每个已启用供应商是一个靶点；关闭后该供应商不再注入思维" flush class="mt-16">' +
                '<div v-if="!targetRows.length">' +
                  '<aigw-empty icon="server" title="暂无可用靶点" desc="先在「供应商」里启用上游，靶点会自动出现在这里" />' +
                '</div>' +
                '<div v-else class="table__wrap">' +
                  '<table class="table">' +
                    '<thead><tr>' +
                      '<th>靶点</th><th>归属思维</th><th>白名单</th><th>状态</th>' +
                      '<th style="text-align:right">注入次数</th><th>最近注入</th><th>最近错误</th>' +
                    '</tr></thead>' +
                    '<tbody>' +
                      '<tr v-for="(t, i) in targetRows" :key="t.target + \'-\' + t.persona_id + \'-\' + i">' +
                        '<td class="nowrap">{{ targetLabel(t.target) }}' +
                          '<span class="text-faint mono" style="font-size:11.5px"> · {{ t.target }}</span></td>' +
                        '<td class="truncate">' +
                          '{{ t.persona_name }} <aigw-badge v-if="t.is_default" tone="success">默认</aigw-badge>' +
                        '</td>' +
                        '<td><aigw-badge :tone="t.in_scope ? \'primary\' : \'muted\'" dot>' +
                          '{{ t.in_scope ? "命中" : "未声明" }}</aigw-badge></td>' +
                        '<td><aigw-badge :tone="t.enabled ? \'success\' : \'muted\'" dot>' +
                          '{{ t.enabled ? "已启用" : "已关闭" }}</aigw-badge></td>' +
                        '<td style="text-align:right" class="text-soft">{{ fmt.num(t.inject_count || 0) }}</td>' +
                        '<td class="text-soft nowrap">{{ t.last_inject_at ? fmt.ago(t.last_inject_at) : "从未" }}</td>' +
                        '<td class="truncate">' +
                          '<span v-if="t.last_error" class="badge badge--danger">{{ t.last_error }}</span>' +
                          '<span v-else class="text-faint">—</span>' +
                        '</td>' +
                      '</tr>' +
                    '</tbody>' +
                  '</table>' +
                '</div>' +
              '</aigw-card>' +

              /* ── 自证测试（管理台） ── */
              '<aigw-card v-if="isAdmin" title="自证测试" sub="给一段文本，检查是否会命中思维口令并返回约定回执" class="mt-16">' +
                '<div class="grid grid--2">' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">待测文本</label>' +
                    '<div class="field__row">' +
                      '<input class="input mono" v-model="checkText" placeholder="思维自检" @keyup.enter="runSelfcheck">' +
                      '<button type="button" class="btn btn--primary" :disabled="checking" @click="runSelfcheck">测试</button>' +
                    '</div>' +
                  '</div>' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">结果</label>' +
                    '<div v-if="!checkResult" class="text-faint" style="font-size:12.5px;padding-top:9px">尚未测试</div>' +
                    '<div v-else class="field__row" style="padding-top:6px">' +
                      '<aigw-badge :tone="checkResult.hit ? \'success\' : \'muted\'" dot>' +
                        '{{ checkResult.hit ? "命中" : "未命中" }}</aigw-badge>' +
                      '<span v-if="checkResult.hit" class="text-soft truncate">' +
                        '回执：{{ checkResult.reply }} · 思维：{{ checkResult.persona_name }}</span>' +
                    '</div>' +
                  '</div>' +
                '</div>' +
                '<div class="codebox mt-16">' +
                  '<div class="codebox__bar">' +
                    '<span class="codebox__label">网关侧自证（直接发口令即可拿到回执，不消耗上游额度）</span>' +
                    '<button type="button" class="btn btn--sm" @click="copyText(selfcheckSnippet)">复制</button>' +
                  '</div>' +
                  '<pre class="codeblock">{{ selfcheckSnippet }}</pre>' +
                '</div>' +
              '</aigw-card>' +

              /* ── 新建 / 编辑弹窗 ── */
              '<aigw-modal v-if="showEdit" :title="form.id ? \'编辑思维\' : \'新建思维\'" wide' +
                          ' :busy="busy" confirm-text="保存"' +
                          ' @close="showEdit = false" @confirm="submitForm">' +
                '<div class="grid grid--2">' +
                  '<div class="field">' +
                    '<label class="field__label">名称</label>' +
                    '<input class="input" v-model="form.name" placeholder="例如：破甲思维 / 客服助手" maxlength="64">' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">注入位置</label>' +
                    '<select class="select" v-model="form.mode">' +
                      '<option v-for="m in modes" :key="m.value" :value="m.value">{{ m.label }}</option>' +
                    '</select>' +
                  '</div>' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">描述</label>' +
                  '<input class="input" v-model="form.description" placeholder="一句话说明这份思维的用途" maxlength="255">' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">思维正文（system）</label>' +
                  '<textarea class="textarea mono" rows="12" v-model="form.content"' +
                            ' placeholder="你是本机部署的 AI 助手……"></textarea>' +
                  '<div class="field__hint">正文以注释锚点包裹后注入 system，重复注入会自动剥离旧块，不会堆叠。</div>' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">靶点白名单</label>' +
                  '<div v-if="!targets.length" class="text-faint" style="font-size:12.5px">暂无可用靶点（先在「供应商」里启用上游）</div>' +
                  '<div v-else class="wrap">' +
                    '<button v-for="t in targets" :key="t.value" type="button" class="btn btn--sm"' +
                            ' :class="{ \'btn--primary\': isFormTarget(t.value) }" @click="toggleFormTarget(t.value)">' +
                      '{{ t.label }}</button>' +
                  '</div>' +
                  '<div class="field__hint">不选 = 全部已启用供应商都注入；选中后仅注入选中的靶点。</div>' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">模型白名单（精准开启）</label>' +
                  '<div v-if="form.models.length" class="wrap">' +
                    '<span v-for="m in form.models" :key="m" class="badge badge--primary"' +
                          ' style="cursor:pointer" title="点击移除" @click="removeFormModel(m)">{{ m }} ✕</span>' +
                  '</div>' +
                  '<div class="text-faint" style="font-size:12.5px">已选 {{ form.models.length }} 项；不选 = 全部模型都注入。</div>' +
                  '<div v-if="models.length" class="wrap mt-8">' +
                    '<button v-for="m in models" :key="m.value" type="button" class="btn btn--sm"' +
                            ' :class="{ \'btn--primary\': isFormModel(m.value) }" @click="toggleFormModel(m.value)">' +
                      '{{ m.label }}</button>' +
                  '</div>' +
                  '<div v-else class="text-faint mt-8" style="font-size:12.5px">模型目录为空（先在「模型目录」里启用模型），也可直接手填</div>' +
                  '<div class="field__row mt-8">' +
                    '<input class="input mono" v-model="modelInput" placeholder="手填模型 ID 或前缀通配，如 gpt-4*"' +
                          ' @keyup.enter="addFormModel">' +
                    '<button type="button" class="btn" @click="addFormModel">添加</button>' +
                  '</div>' +
                  '<div class="field__hint">支持精确 ID 与尾部 * 前缀通配（gpt-4* 命中 gpt-4o / gpt-4-turbo）。</div>' +
                '</div>' +
                '<div class="grid grid--2">' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">自证口令</label>' +
                    '<input class="input mono" v-model="form.selfcheck_pass" placeholder="思维自检" maxlength="64">' +
                  '</div>' +
                  '<div class="field mb-0">' +
                    '<label class="field__label">自证回执</label>' +
                    '<input class="input mono" v-model="form.selfcheck_reply" placeholder="思维自检成功" maxlength="255">' +
                  '</div>' +
                '</div>' +
                '<div class="field__hint">改口令时，<b>上面的「思维正文」里的「## 自证」那一行要同步改</b>，' +
                '否则旧口令仍会由模型应答（自证有网关层与正文两套判据）。改完可跑 ' +
                '<code>php tools/check-selfcheck.php</code> 验证两处是否一致。</div>' +
                '<div class="field__row mt-16">' +
                  '<aigw-switch v-model="form.enabled" label="启用" />' +
                  '<aigw-switch v-model="form.is_default" label="设为默认思维（自动注入）" />' +
                  '<aigw-switch v-model="form.selfcheck_on" label="开启自证拦截" />' +
                '</div>' +
              '</aigw-modal>' +

              /* ── 靶点弹窗 ── */
              '<aigw-modal v-if="showTargets" title="靶点开关" wide hide-foot @close="showTargets = false">' +
                '<p class="text-soft">思维「{{ targetPersona && targetPersona.name }}」在各供应商上的注入开关。</p>' +
                '<div v-if="!targetStates.length" class="mt-16"><aigw-loading /></div>' +
                '<div v-else class="grid grid--list mt-16">' +
                  '<div v-for="t in targetStates" :key="t.target" class="modelrow">' +
                    '<div class="modelrow__main">' +
                      '<div class="modelrow__title truncate">{{ t.label }}</div>' +
                      '<div class="modelrow__meta text-faint mono">{{ t.target }}</div>' +
                      '<div class="modelrow__meta text-faint">' +
                        '注入 {{ fmt.num(t.inject_count || 0) }} 次 · 最近 ' +
                        '{{ t.last_inject_at ? fmt.ago(t.last_inject_at) : "从未" }}' +
                        '<span v-if="t.last_error" class="badge badge--danger" style="margin-left:6px">{{ t.last_error }}</span>' +
                      '</div>' +
                    '</div>' +
                    '<div class="modelrow__ops">' +
                      '<aigw-badge v-if="!t.in_scope" tone="muted">不在白名单</aigw-badge>' +
                      '<aigw-switch v-else :model-value="t.enabled" :disabled="targetBusy === t.target"' +
                                   ' @update:modelValue="toggleTarget(t)" />' +
                    '</div>' +
                  '</div>' +
                '</div>' +
              '</aigw-modal>' +

              /* ── 修订快照弹窗 ── */
              '<aigw-modal v-if="showRevisions" title="修订快照（改前自动备份）" wide hide-foot @close="showRevisions = false">' +
                '<p class="text-soft">思维「{{ revPersona && revPersona.name }}」的历史版本，最多保留最近 50 份。还原前当前版本也会自动留档。</p>' +
                '<div v-if="revisionsLoading" class="mt-16"><aigw-loading /></div>' +
                '<div v-else-if="!revisions.length" class="text-faint mt-16" style="font-size:12.5px">暂无历史快照</div>' +
                '<div v-else class="table__wrap mt-16">' +
                  '<table class="table">' +
                    '<thead><tr>' +
                      '<th>快照</th><th>版本</th><th>封条</th>' +
                      '<th style="text-align:right">字节</th><th>备注</th><th>时间</th>' +
                      '<th style="text-align:right">操作</th>' +
                    '</tr></thead>' +
                    '<tbody>' +
                      '<tr v-for="r in revisions" :key="r.id">' +
                        '<td class="mono text-soft">#{{ r.id }}</td>' +
                        '<td class="text-soft nowrap">v{{ r.version }}</td>' +
                        '<td class="mono text-faint" style="font-size:12px">{{ r.seal_short }}</td>' +
                        '<td style="text-align:right" class="text-soft">{{ fmt.num(r.bytes) }}</td>' +
                        '<td class="truncate">{{ r.note || "—" }}</td>' +
                        '<td class="text-soft nowrap">{{ fmt.time(r.created_at) }}</td>' +
                        '<td style="text-align:right" class="nowrap">' +
                          '<button type="button" class="btn btn--sm" :disabled="busy" @click="restore(r)">还原</button>' +
                        '</td>' +
                      '</tr>' +
                    '</tbody>' +
                  '</table>' +
                '</div>' +
              '</aigw-modal>' +

              /* ── 预演弹窗 ── */
              '<aigw-modal v-if="showPreview" title="注入预演（不写任何数据）" wide hide-foot @close="showPreview = false">' +
                '<div class="field">' +
                  '<label class="field__label">样例 system（模拟请求里原本的系统提示）</label>' +
                  '<div class="field__row">' +
                    '<input class="input mono" v-model="previewSample" placeholder="你是一个乐于助人的 AI 助手。">' +
                    '<button type="button" class="btn btn--primary" :disabled="previewLoading" @click="runPreview">重新预演</button>' +
                  '</div>' +
                '</div>' +
                '<div v-if="previewLoading" class="mt-16"><aigw-loading /></div>' +
                '<div v-else-if="!previewResult" class="text-faint mt-16" style="font-size:12.5px">暂无预演结果</div>' +
                '<div v-else>' +
                  '<div class="grid grid--stats mt-16">' +
                    '<div class="kv-item"><b>{{ previewResult.mode_label || modeShort(previewResult.mode) }}</b><span>注入位置</span></div>' +
                    '<div class="kv-item"><b>{{ fmt.num(previewResult.block_bytes) }}</b><span>注入块字节</span></div>' +
                    '<div class="kv-item"><b>{{ fmt.num(previewResult.system_bytes) }}</b><span>改写后 system 字节</span></div>' +
                    '<div class="kv-item"><b>{{ previewResult.idempotent && previewResult.idempotent.stable ? "稳定" : "会堆叠" }}</b><span>幂等</span></div>' +
                  '</div>' +
                  '<div class="field mt-16 mb-0">' +
                    '<label class="field__label">改写前</label>' +
                    '<div class="codebox"><pre class="codeblock">{{ previewResult.before }}</pre></div>' +
                  '</div>' +
                  '<div class="field mt-16 mb-0">' +
                    '<label class="field__label">改写后</label>' +
                    '<div class="codebox"><pre class="codeblock">{{ previewResult.system }}</pre></div>' +
                  '</div>' +
                '</div>' +
              '</aigw-modal>' +

              /* ── 正文查看弹窗 ── */
              '<aigw-modal v-if="showBody" :title="\'正文 · \' + bodyTitle" wide hide-foot @close="showBody = false">' +
                '<div class="flex items-center justify-between mb-8">' +
                  '<span class="text-faint" style="font-size:12.5px">{{ fmt.num(bodyContent.length) }} 字符</span>' +
                  '<button type="button" class="btn btn--sm" @click="copyText(bodyContent)">复制正文</button>' +
                '</div>' +
                '<div class="codebox"><pre class="codeblock">{{ bodyContent || "（空）" }}</pre></div>' +
              '</aigw-modal>' +
            '</div>'
    };

    global.AigwPagesRegister(pages);
})(window);
