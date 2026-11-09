/* ============================================================
   通用组件库
   ============================================================ */
(function (global) {
    'use strict';

    var Vue = global.Vue;
    var S = global.AigwStore;
    var fmt = S.fmt;

    var components = {};

    /* ── 基础展示 ───────────────────────────────────────── */

    /** 徽标 */
    components.AigwBadge = {
        props: { tone: { type: String, default: 'muted' }, dot: Boolean },
        computed: {
            cls: function () {
                var m = {
                    success: 'badge--success', warning: 'badge--warning', danger: 'badge--danger',
                    primary: 'badge--primary', plum: 'badge--plum', amber: 'badge--amber',
                    celadon: 'badge--celadon', rust: 'badge--rust', clay: 'badge--clay',
                    muted: 'badge--muted'
                };
                return 'badge ' + (m[this.tone] || 'badge--muted');
            }
        },
        template:
            '<span :class="cls"><i v-if="dot" class="dot"></i><slot></slot></span>'
    };

    /** 指标卡 */
    components.AigwStat = {
        props: {
            label: String, value: [String, Number], foot: String,
            tone: { type: String, default: 'primary' }, icon: String
        },
        computed: {
            cls: function () { return 'stat stat--' + this.tone; }
        },
        template:
            '<div :class="cls">' +
              '<div class="stat__top">' +
                '<span class="stat__label"><aigw-icon v-if="icon" :name="icon" :size="14" />{{ label }}</span>' +
              '</div>' +
              '<div class="stat__value">{{ value }}</div>' +
              '<div v-if="foot" class="stat__foot">{{ foot }}</div>' +
            '</div>'
    };

    /** 加载中 */
    components.AigwLoading = {
        props: { text: { type: String, default: '载入中…' } },
        template: '<div class="loading"><span class="spinner"></span><span class="truncate">{{ text }}</span></div>'
    };

    /** 空状态 */
    components.AigwEmpty = {
        props: {
            icon: { type: String, default: 'empty' }, title: String, desc: String
        },
        template:
            '<div class="empty">' +
              '<div class="empty__icon"><aigw-icon :name="icon" :size="26" /></div>' +
              '<div class="empty__title">{{ title || "暂无数据" }}</div>' +
              '<div v-if="desc" class="empty__desc">{{ desc }}</div>' +
              '<div v-if="$slots.default" class="mt-16"><slot></slot></div>' +
            '</div>'
    };

    /** 卡片外壳 */
    components.AigwCard = {
        props: { title: String, sub: String, flush: Boolean },
        template:
            '<section class="card">' +
              '<header v-if="$slots.header" class="card__head card__head--custom"><slot name="header"></slot></header>' +
              '<header v-else-if="title || $slots.actions" class="card__head">' +
                '<div class="card__titles">' +
                  '<div class="card__title">{{ title }}</div>' +
                  '<div v-if="sub" class="card__sub">{{ sub }}</div>' +
                '</div>' +
                '<div v-if="$slots.actions" class="card__actions"><slot name="actions"></slot></div>' +
              '</header>' +
              '<div :class="flush ? \'card__body card__body--flush\' : \'card__body\'"><slot></slot></div>' +
            '</section>'
    };

    /** 分段控件 */
    components.AigwSegbar = {
        props: {
            modelValue: [String, Number],
            options: { type: Array, default: function () { return []; } }
        },
        emits: ['update:modelValue'],
        methods: {
            pick: function (v) { this.$emit('update:modelValue', v); }
        },
        template:
            '<div class="segbar">' +
              '<button v-for="o in options" :key="o.value" type="button" class="segbar__item"' +
                      ' :class="{ \'is-active\': o.value === modelValue }" @click="pick(o.value)">' +
                '<span class="truncate">{{ o.label }}</span>' +
              '</button>' +
            '</div>'
    };

    /** 开关 */
    components.AigwSwitch = {
        props: { modelValue: Boolean, label: String, disabled: Boolean },
        emits: ['update:modelValue'],
        methods: {
            onChange: function (e) { this.$emit('update:modelValue', e.target.checked); }
        },
        template:
            '<label class="switch">' +
              '<input type="checkbox" :checked="modelValue" :disabled="disabled" @change="onChange">' +
              '<span class="switch__track"></span>' +
              '<span v-if="label" class="truncate">{{ label }}</span>' +
            '</label>'
    };

    /** 进度条 */
    components.AigwBar = {
        props: { value: { type: Number, default: 0 }, color: { type: String, default: '' } },
        computed: {
            width: function () { return Math.max(0, Math.min(100, this.value)) + '%'; },
            style: function () { return this.color ? { background: this.color } : {}; }
        },
        template:
            '<div class="bar"><div class="bar__fill" :style="Object.assign({ width: width }, style)"></div></div>'
    };

    /** 模态框 */
    components.AigwModal = {
        props: {
            title: String, wide: Boolean,
            confirmText: { type: String, default: '确定' },
            cancelText: { type: String, default: '取消' },
            busy: Boolean,
            hideFoot: Boolean
        },
        emits: ['close', 'confirm'],
        mounted: function () {
            var self = this;
            this._esc = function (e) { if (e.key === 'Escape') self.$emit('close'); };
            window.addEventListener('keydown', this._esc);
            this._prev = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
        },
        unmounted: function () {
            window.removeEventListener('keydown', this._esc);
            document.body.style.overflow = this._prev || '';
        },
        template:
            '<div class="modal-mask" @click.self="$emit(\'close\')">' +
              '<div class="modal" :class="{ \'modal--wide\': wide }">' +
                '<header class="modal__head">' +
                  '<div class="modal__title truncate">{{ title }}</div>' +
                  '<button type="button" class="modal__close" @click="$emit(\'close\')" aria-label="关闭"><aigw-icon name="close" :size="18" /></button>' +
                '</header>' +
                '<div class="modal__body"><slot></slot></div>' +
                '<footer v-if="!hideFoot" class="modal__foot">' +
                  '<button type="button" class="btn" @click="$emit(\'close\')" :disabled="busy">{{ cancelText }}</button>' +
                  '<button type="button" class="btn btn--primary" @click="$emit(\'confirm\')" :disabled="busy">' +
                    '{{ busy ? "处理中…" : confirmText }}' +
                  '</button>' +
                '</footer>' +
              '</div>' +
            '</div>'
    };

    /** 确认对话框（Promise 化） */
    var confirmState = Vue.reactive({ open: false, title: '', message: '', confirmText: '确定', danger: false, busy: false });
    var confirmResolver = null;

    components.AigwConfirm = {
        setup: function () {
            return { st: confirmState };
        },
        methods: {
            close: function () {
                confirmState.open = false;
                if (confirmResolver) { confirmResolver(false); confirmResolver = null; }
            },
            ok: function () {
                confirmState.open = false;
                if (confirmResolver) { confirmResolver(true); confirmResolver = null; }
            }
        },
        template:
            '<div v-if="st.open" class="modal-mask" @click.self="close">' +
              '<div class="modal" style="max-width:420px">' +
                '<header class="modal__head"><div class="modal__title truncate">{{ st.title }}</div></header>' +
                '<div class="modal__body"><div class="note" :class="{ \'note--warn\': st.danger }">{{ st.message }}</div></div>' +
                '<footer class="modal__foot">' +
                  '<button type="button" class="btn" @click="close">取消</button>' +
                  '<button type="button" class="btn" :class="st.danger ? \'btn--danger\' : \'btn--primary\'" @click="ok">{{ st.confirmText }}</button>' +
                '</footer>' +
              '</div>' +
            '</div>'
    };

    /**
     * 弹窗公告（注册页 / 总览面板）
     * ------------------------------------------------------------
     * 由 store 状态驱动：页面进入时调 AigwStore.maybeShowNotice(target) 置位
     * noticeOpen，本组件只负责渲染与关闭。全站只需挂一个（见 shell.js）。
     *
     * 内容按纯文本渲染（逐行 <p>，Vue 自动转义），不解析 HTML —— 公告由管理员
     * 填写，仍按不可信输入处理，避免任何注入面。
     */
    components.AigwNotice = {
        setup: function () { return { st: S.state }; },
        computed: {
            notice: function () { return this.st.notice || {}; },
            title: function () { return String(this.notice.title || '').trim() || '公告'; },
            lines: function () {
                var c = String(this.notice.content || '');
                return c === '' ? [] : c.split(/\r?\n/);
            },
            toneClass: function () {
                var t = this.notice.type;
                if (t === 'success') return 'note--success';
                if (t === 'warning') return 'note--warn';
                if (t === 'danger') return 'note--danger';
                return 'note--info';
            },
            /* ── 「跳转加群」按钮（后台可控群号） ──
               群号与链接都由后台配置、经公开接口下发；两样都没有就不显示按钮，
               避免渲染出一个点了没反应的死按钮。 */
            groupQq: function () { return String(this.notice.group_qq || '').trim(); },
            groupUrl: function () { return String(this.notice.group_url || '').trim(); },
            showGroup: function () {
                return !!this.notice.group_enabled && (!!this.groupUrl || !!this.groupQq);
            },
            groupLabel: function () {
                return this.groupQq ? ('加入交流群 ' + this.groupQq) : '加入交流群';
            }
        },
        mounted: function () {
            var self = this;
            this._esc = function (e) {
                if (e.key === 'Escape' && self.st.noticeOpen) S.closeNotice();
            };
            window.addEventListener('keydown', this._esc);
        },
        unmounted: function () {
            window.removeEventListener('keydown', this._esc);
        },
        methods: {
            close: function () { S.closeNotice(); },
            /**
             * 点击「加入交流群」。
             *   有 https 链接 → 直接新窗口打开（QQ 群管理后台生成的 qm.qq.com/q/xxx 最可靠）
             *   只有群号     → 桌面端无法凭群号直接加群，故「复制群号 + 唤起手机 QQ」双管齐下：
             *                  手机装了 QQ 会弹加群页；桌面则提示已复制、去 QQ 搜索即可。
             * 在新窗口打开而不是当前页跳走，用户看完群还能回来继续看公告。
             */
            openGroup: function () {
                var url = this.groupUrl, qq = this.groupQq;
                if (url) {
                    window.open(url, '_blank', 'noopener,noreferrer');
                    return;
                }
                if (!qq) return;
                var done = function () { S.toast('群号 ' + qq + ' 已复制，打开 QQ 搜索即可加入', 'success'); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(qq).then(done, done);
                } else {
                    done();
                }
                try {
                    window.open('mqqapi://card/show_pslcard?src_type=internal&version=1&uin='
                        + encodeURIComponent(qq) + '&card_type=group&source=qrcode', '_blank');
                } catch (e) { /* 未安装 QQ：忽略，已复制群号 */ }
            }
        },
        template:
            '<div v-if="st.noticeOpen" class="modal-mask notice-mask" @click.self="close" role="dialog" aria-modal="true">' +
              '<div class="modal" style="max-width:460px">' +
                '<header class="modal__head">' +
                  '<div class="modal__title truncate">{{ title }}</div>' +
                  '<button type="button" class="modal__close" @click="close" aria-label="关闭"><aigw-icon name="close" :size="18" /></button>' +
                '</header>' +
                '<div class="modal__body">' +
                  '<div class="note note--sm" :class="toneClass">' +
                    '<p v-for="(line, i) in lines" :key="i" class="notice__line">' +
                      '<template v-if="line">{{ line }}</template><br v-else>' +
                    '</p>' +
                    '<p v-if="!lines.length" class="notice__line">（公告内容为空）</p>' +
                  '</div>' +
                '</div>' +
                '<footer class="modal__foot notice-foot">' +
                  '<button v-if="showGroup" type="button" class="btn w-full" @click="openGroup">' +
                    '<aigw-icon name="users" :size="16" /> {{ groupLabel }}' +
                  '</button>' +
                  '<button type="button" class="btn btn--primary w-full" @click="close">我知道了</button>' +
                '</footer>' +
              '</div>' +
            '</div>'
    };

    /** 提示条容器 */
    components.AigwToasts = {        setup: function () {
            return { list: S.toasts };
        },
        methods: {
            dismiss: function (id) { S.dismissToast(id); }
        },
        template:
            '<div class="toasts">' +
              '<div v-for="t in list" :key="t.id" class="toast" :class="\'toast--\' + t.type">' +
                '<div class="toast__body">' +
                  '<div v-if="t.title" class="toast__title">{{ t.title }}</div>' +
                  '<div class="toast__msg">{{ t.message }}</div>' +
                '</div>' +
                '<button type="button" class="toast__close" @click="dismiss(t.id)" aria-label="关闭"><aigw-icon name="close" :size="14" /></button>' +
              '</div>' +
            '</div>'
    };

    /* ── 业务组件 ───────────────────────────────────────── */

    /** 头像：支持图片地址 / 供应商品牌图标（失败回退字母色块） */
    components.AigwAvatar = {
        props: {
            name: String,
            src: { type: String, default: '' },
            providerKey: { type: String, default: '' },
            size: { type: Number, default: 40 },
            square: Boolean
        },
        data: function () { return { broken: false }; },
        computed: {
            resolvedSrc: function () {
                if (this.src) return this.src;
                if (this.providerKey && global.AigwLogos) return global.AigwLogos.of(this.providerKey);
                return '';
            },
            hasImage: function () { return !!this.resolvedSrc && !this.broken; },
            style: function () {
                return {
                    width: this.size + 'px', height: this.size + 'px',
                    background: this.hasImage ? 'transparent' : S.providerTone.avatar(this.name),
                    borderRadius: this.square ? '12px' : '50%',
                    fontSize: Math.round(this.size * 0.42) + 'px'
                };
            },
            text: function () { return S.providerTone.initials(this.name); }
        },
        watch: {
            resolvedSrc: function () { this.broken = false; }
        },
        methods: {
            onError: function () { this.broken = true; }
        },
        template:
            '<div class="avatar" :style="style">' +
              '<img v-if="hasImage" class="avatar__img" :src="resolvedSrc" :alt="name" @error="onError">' +
              '<template v-else>{{ text }}</template>' +
            '</div>'
    };

    /** 账号卡 */
    components.AigwAccountCard = {
        props: { account: { type: Object, required: true }, busy: Boolean },
        emits: ['toggle', 'refresh', 'remove', 'detail', 'models', 'cooldown', 'action'],
        computed: {
            toneColor: function () { return S.providerTone.line(this.account.provider_key); },
            badgeTone: function () {
                var m = { success: 'success', warning: 'warning', danger: 'danger', muted: 'muted' };
                return m[(this.account.state || {}).tone] || 'muted';
            },
            actionDefs: function () {
                var p = this.account.provider_key;
                if (p === 'trae' || p === 'trae_intl') {
                    return [
                        { action: 'checkin_status', label: '签到状态' },
                        { action: 'checkin', label: '签到' }
                    ];
                }
                if (p === 'codebuddy' || p === 'codebuddy_intl') {
                    return [
                        { action: 'checkin', label: '签到' },
                        { action: 'tasks', label: '新手任务' }
                    ];
                }
                if (p === 'qoder' || p === 'qoder_intl') {
                    return [
                        { action: 'checkin', label: '签到' }
                    ];
                }
                if (p === 'zcode' || p === 'zcode_intl') {
                    // 「领取额度」弹窗内含一键领取：验证码由服务端自动无痕求解
                    // （见 ZcodeProvider::solveCaptcha + App\Support\CaptchaSolver），
                    // 无需用户手输，故这里不再单独提供「设置验证码」入口。
                    return [
                        { action: 'claim_preview', label: '领取额度', dialog: 'claim' }
                    ];
                }
                if (p === 'loomy') {
                    return [
                        { action: 'onboarding_tasks', label: '新手任务' },
                        { action: 'complete_tasks', label: '完成任务' },
                        { action: 'redeem', label: '兑换', prompt: '请输入兑换码' },
                        { action: 'activation', label: '激活码', prompt: '请输入激活码' }
                    ];
                }
                if (p === 'agent2api' || p.indexOf('agent2api_') === 0) {
                    // Agent2API 网关：账号池、签到、插件目录都在网关侧，
                    // 这里只是把动作转发过去（见 Agent2ApiProvider::action）。
                    return [
                        { action: 'checkin', label: '网关签到' },
                        { action: 'health', label: '网关状态' },
                        { action: 'providers', label: '插件目录' }
                    ];
                }
                return [];
            },
            credits: function () {
                if (!this.account.credits_known) return '未知';
                return this.account.credits === null || this.account.credits === undefined
                    ? '未知' : fmt.num(this.account.credits, 2);
            },
            /** 今日消耗 Token（网关自计量，自然日 00:00 起） */
            meteredToday: function () {
                var m = this.account.metered;
                if (!m) return '—';
                return fmt.num(m.today_tokens || 0, 0);
            },
            /** 累计消耗 Token（网关自计量，全时段） */
            meteredTotal: function () {
                var m = this.account.metered;
                if (!m) return '—';
                return fmt.num(m.total_tokens || 0, 0);
            }
        },
        methods: {
            runAction: function (def) {
                var value = '';
                if (def.prompt) {
                    value = global.prompt(def.prompt) || '';
                    // allowEmpty：允许「留空」作为有效输入（如清除验证码），不能一律拦截。
                    if (!value && !def.allowEmpty) return;
                }
                this.$emit('action', {
                    action: def.action,
                    value: value,
                    dialog: def.dialog || '',
                    param: def.param || 'code',
                    allowEmpty: !!def.allowEmpty
                });
            }
        },
        template:
            '<div class="account" :class="{ \'is-off\': !account.enabled }" :style="{ \'--tone\': toneColor }">' +
              '<div class="account__head">' +
                '<aigw-avatar :name="account.nickname || account.uid || account.provider_name" :provider-key="account.provider_key" :size="34" />' +
                '<div class="account__title">' +
                  '<div class="account__name truncate">{{ account.nickname }}</div>' +
                  '<div class="account__sub truncate">{{ account.provider_name || account.provider_key }}</div>' +
                '</div>' +
                '<aigw-badge :tone="badgeTone" dot>{{ (account.state || {}).label || account.status }}</aigw-badge>' +
              '</div>' +

              '<dl class="account__meta">' +
                '<div v-if="account.owner_name"><dt>归属</dt><dd class="truncate">{{ account.owner_name }}</dd></div>' +
                '<div v-if="account.region"><dt>区域</dt><dd>{{ account.region.label }}</dd></div>' +
                '<div><dt>额度</dt><dd>{{ credits }}</dd></div>' +
                '<div v-if="account.metered" :title="\'今日调用 \' + fmt.num(account.metered.today_calls, 0) + \' 次\'">' +
                  '<dt>今日消耗</dt><dd>{{ meteredToday }}</dd></div>' +
                '<div v-if="account.metered" :title="\'累计调用 \' + fmt.num(account.metered.calls, 0) + \' 次\'">' +
                  '<dt>累计消耗</dt><dd>{{ meteredTotal }}</dd></div>' +
                '<div><dt>错误次数</dt><dd>{{ account.error_count }}</dd></div>' +
                '<div><dt>最近刷新</dt><dd>{{ fmt.ago(account.last_refresh_at) }}</dd></div>' +
                '<div><dt>冷却</dt><dd>{{ account.cooling_remain > 0 ? fmt.countdown(account.cooling_remain) : "无" }}</dd></div>' +
              '</dl>' +

              '<div v-if="account.last_error" class="note note--warn note--sm">' +
                '{{ account.last_error }}' +
              '</div>' +

              '<div class="account__actions">' +
                '<button type="button" class="btn btn--sm" :disabled="busy" @click="$emit(\'detail\')">详情</button>' +
                '<button type="button" class="btn btn--sm" :disabled="busy" @click="$emit(\'refresh\')">刷新额度</button>' +
                '<button type="button" class="btn btn--sm" :disabled="busy" @click="$emit(\'models\')">模型</button>' +
                '<button v-for="def in actionDefs" :key="def.action" type="button" class="btn btn--sm btn--amber" :disabled="busy" @click="runAction(def)">{{ def.label }}</button>' +
                '<button v-if="account.cooling_remain > 0" type="button" class="btn btn--sm btn--amber" :disabled="busy" @click="$emit(\'cooldown\')">解除冷却</button>' +
                '<button type="button" class="btn btn--sm" :disabled="busy" @click="$emit(\'toggle\')">' +
                  '{{ account.enabled ? "停用" : "启用" }}' +
                '</button>' +
                '<button type="button" class="btn btn--sm btn--danger" :disabled="busy" @click="$emit(\'remove\')">删除</button>' +
              '</div>' +
            '</div>'
    };

    /** 供应商卡 */
    components.AigwProviderCard = {
        // readonly：隐藏「供应商配置」类动作（自检/配置/启停/删除）——仅管理员可用
        // canLogin：是否显示「添加账号」——普通用户也可为自己的账号池添加（账号级操作按归属隔离）
        props: { provider: { type: Object, required: true }, busy: Boolean, readonly: Boolean, canLogin: Boolean },
        emits: ['toggle', 'edit', 'remove', 'test', 'models', 'login'],
        computed: {
            isCustom: function () { return this.provider.kind === 'custom'; },
            accountTotal: function () {
                var s = this.provider.accounts || {};
                return s.total !== undefined ? s.total : (s.count !== undefined ? s.count : 0);
            },
            accountReady: function () {
                var s = this.provider.accounts || {};
                return s.ready !== undefined ? s.ready : (s.usable !== undefined ? s.usable : '—');
            },
            rateLabel: function () {
                var p = this.provider;
                if (!p.rate_count) return '—';
                var min = fmt.num(p.rate_min, 2);
                var max = fmt.num(p.rate_max, 2);
                return (Number(p.rate_min) === Number(p.rate_max)) ? min : (min + '~' + max);
            }
        },
        template:
            '<div class="provider" :class="{ \'is-off\': !provider.enabled }">' +
              '<div class="provider__head">' +
                '<aigw-avatar :name="provider.display_name" :provider-key="provider.provider_key" :size="40" :square="true" />' +
                '<div class="provider__title">' +
                  '<div class="provider__name truncate">{{ provider.display_name }}</div>' +
                  '<div class="provider__key truncate">{{ provider.provider_key }}</div>' +
                '</div>' +
                '<aigw-badge :tone="provider.enabled ? \'success\' : \'muted\'" dot>' +
                  '{{ provider.enabled ? "已启用" : "已停用" }}' +
                '</aigw-badge>' +
              '</div>' +

              '<div class="wrap">' +
                '<aigw-badge tone="primary">{{ isCustom ? "自定义" : "内置" }}</aigw-badge>' +
                '<aigw-badge tone="clay">{{ provider.login_type }}</aigw-badge>' +
                '<aigw-badge v-if="!provider.adapter_ready" tone="danger">适配器缺失</aigw-badge>' +
              '</div>' +

              '<div class="provider__meta">' +
                '<div class="kv-item"><b>{{ accountTotal }}</b><span>账号</span></div>' +
                '<div class="kv-item"><b>{{ accountReady }}</b><span>可用</span></div>' +
                '<div class="kv-item"><b>{{ provider.model_count }}</b><span>模型</span></div>' +
                '<div class="kv-item"><b>{{ rateLabel }}</b><span>模型倍率</span></div>' +
              '</div>' +

              '<div class="provider__actions">' +
                '<button v-if="canLogin" type="button" class="btn btn--sm btn--primary" :disabled="busy" @click="$emit(\'login\')">添加账号</button>' +
                '<button v-if="!readonly" type="button" class="btn btn--sm" :disabled="busy" @click="$emit(\'test\')">自检</button>' +
                '<button v-if="!readonly" type="button" class="btn btn--sm" :disabled="busy" @click="$emit(\'edit\')">配置</button>' +
                '<button type="button" class="btn btn--sm" :disabled="busy" @click="$emit(\'models\')">模型</button>' +
                '<button v-if="!readonly" type="button" class="btn btn--sm" :disabled="busy" @click="$emit(\'toggle\')">' +
                  '{{ provider.enabled ? "停用" : "启用" }}' +
                '</button>' +
                '<button v-if="!readonly && isCustom" type="button" class="btn btn--sm btn--danger" :disabled="busy" @click="$emit(\'remove\')">删除</button>' +
              '</div>' +
            '</div>'
    };

    /** 趋势柱状图 */
    components.AigwTrend = {
        props: {
            data: { type: Array, default: function () { return []; } },
            valueKey: { type: String, default: 'total' },
            height: { type: Number, default: 116 },
            unit: { type: String, default: '次' },
            decimals: { type: Number, default: 0 }
        },
        computed: {
            max: function () {
                var m = 0;
                this.data.forEach(function (d) {
                    var v = Number(d && d[this.valueKey]) || 0;
                    if (v > m) m = v;
                }, this);
                return m || 1;
            }
        },
        methods: {
            label: function (d) { return fmt.dayLabel(d.date || d.day || ''); },
            barHeight: function (d) {
                var v = Number(d && d[this.valueKey]) || 0;
                return Math.max(3, Math.round(v / this.max * 100)) + '%';
            },
            tip: function (d) {
                var v = Number(d && d[this.valueKey]) || 0;
                return (d.date || d.day || '') + ' · ' + fmt.num(v, this.decimals) + (this.unit ? this.unit : '');
            }
        },
        template:
            '<div class="trend" :style="{ height: height + \'px\' }">' +
              '<div v-for="(d, i) in data" :key="i" class="trend__col">' +
                '<div class="trend__bar" :style="{ height: barHeight(d) }" :title="tip(d)"></div>' +
                '<div class="trend__label truncate">{{ label(d) }}</div>' +
              '</div>' +
            '</div>'
    };

    /** 折线 / 面积图 */
    components.AigwLine = {
        props: {
            data: { type: Array, default: function () { return []; } },
            valueKey: { type: String, default: 'value' },
            labelKey: { type: String, default: 'date' },
            height: { type: Number, default: 168 },
            unit: { type: String, default: '' },
            decimals: { type: Number, default: 0 },
            area: { type: Boolean, default: true }
        },
        computed: {
            W: function () { return 600; },
            H: function () { return this.height; },
            padL: function () { return 40; },
            padR: function () { return 12; },
            padT: function () { return 14; },
            padB: function () { return 26; },
            innerW: function () { return this.W - this.padL - this.padR; },
            innerH: function () { return this.H - this.padT - this.padB; },
            max: function () {
                var m = 0;
                this.data.forEach(function (d) {
                    var v = Number(d && d[this.valueKey]) || 0;
                    if (v > m) m = v;
                }, this);
                return m || 1;
            },
            points: function () {
                var self = this, n = this.data.length;
                var innerW = this.innerW, innerH = this.innerH;
                return this.data.map(function (d, i) {
                    var v = Number(d && d[self.valueKey]) || 0;
                    var x = self.padL + (n <= 1 ? innerW / 2 : (i / (n - 1)) * innerW);
                    var y = self.padT + innerH - (v / self.max) * innerH;
                    var lb = String((d && d[self.labelKey]) || '');
                    return {
                        x: Math.round(x * 100) / 100,
                        y: Math.round(y * 100) / 100,
                        label: lb,
                        tip: lb + ' · ' + fmt.num(v, self.decimals) + self.unit
                    };
                });
            },
            linePath: function () {
                return this.points.map(function (p, i) {
                    return (i ? 'L' : 'M') + p.x + ' ' + p.y;
                }).join(' ');
            },
            areaPath: function () {
                var pts = this.points;
                if (!pts.length) return '';
                var base = this.padT + this.innerH;
                var d = 'M' + pts[0].x + ' ' + base;
                pts.forEach(function (p) { d += ' L' + p.x + ' ' + p.y; });
                return d + ' L' + pts[pts.length - 1].x + ' ' + base + ' Z';
            },
            grid: function () {
                var self = this;
                return [1, 0.5, 0].map(function (r) {
                    return {
                        y: Math.round((self.padT + self.innerH * (1 - r)) * 100) / 100,
                        label: fmt.compact(self.max * r)
                    };
                });
            },
            axisLabels: function () {
                var pts = this.points, n = pts.length;
                if (!n) return [];
                var step = Math.max(1, Math.ceil(n / 6));
                var out = [];
                for (var i = 0; i < n; i += step) {
                    out.push({ x: pts[i].x, label: fmt.dayLabel(pts[i].label) });
                }
                var last = pts[n - 1];
                if (out.length && out[out.length - 1].x !== last.x) {
                    out.push({ x: last.x, label: fmt.dayLabel(last.label) });
                }
                return out;
            }
        },
        template:
            '<div class="linechart">' +
              '<svg :viewBox="\'0 0 \' + W + \' \' + H" preserveAspectRatio="xMidYMid meet" role="img">' +
                '<line v-for="(g, i) in grid" :key="\'g\' + i" class="linechart__grid" :x1="padL" :x2="W - padR" :y1="g.y" :y2="g.y" />' +
                '<text v-for="(g, i) in grid" :key="\'t\' + i" class="linechart__axis" :x="padL - 8" :y="g.y + 3" text-anchor="end">{{ g.label }}</text>' +
                '<path v-if="area && points.length > 1" class="linechart__area" :d="areaPath" />' +
                '<path v-if="points.length > 1" class="linechart__line" :d="linePath" />' +
                '<circle v-for="(p, i) in points" :key="\'p\' + i" class="linechart__dot" :cx="p.x" :cy="p.y" r="2.8"><title>{{ p.tip }}</title></circle>' +
                '<text v-for="(p, i) in axisLabels" :key="\'a\' + i" class="linechart__axis" :x="p.x" :y="H - 8" text-anchor="middle">{{ p.label }}</text>' +
              '</svg>' +
            '</div>'
    };

    /** 环形图（含图例） */
    components.AigwDonut = {
        props: {
            items: { type: Array, default: function () { return []; } },
            size: { type: Number, default: 116 },
            thickness: { type: Number, default: 13 },
            decimals: { type: Number, default: 0 },
            unit: { type: String, default: '' },
            caption: { type: String, default: '总计' }
        },
        computed: {
            total: function () {
                return this.items.reduce(function (s, it) {
                    return s + (Number(it && it.value) || 0);
                }, 0);
            },
            c: function () { return this.size / 2; },
            r: function () { return (this.size - this.thickness) / 2; },
            centerValue: function () { return fmt.num(this.total, this.decimals); },
            segments: function () {
                var self = this;
                var total = this.total || 1;
                var circ = 2 * Math.PI * this.r;
                var acc = 0;
                return this.items.map(function (it, i) {
                    var v = Number(it && it.value) || 0;
                    var len = (v / total) * circ;
                    var seg = {
                        label: (it && it.label) || '',
                        color: (it && it.color) || S.palette[i % S.palette.length],
                        dash: Math.max(0, len - 1.5) + ' ' + circ,
                        offset: -acc,
                        display: fmt.num(v, self.decimals) + self.unit
                    };
                    acc += len;
                    return seg;
                });
            }
        },
        template:
            '<div class="donut">' +
              '<div class="donut__wrap" :style="{ width: size + \'px\', height: size + \'px\' }">' +
                '<svg class="donut__svg" :width="size" :height="size" :viewBox="\'0 0 \' + size + \' \' + size">' +
                  '<circle class="donut__track" :cx="c" :cy="c" :r="r" :stroke-width="thickness" />' +
                  '<circle v-for="(s, i) in segments" :key="i" class="donut__seg" :cx="c" :cy="c" :r="r"' +
                          ' :stroke="s.color" :stroke-width="thickness"' +
                          ' :stroke-dasharray="s.dash" :stroke-dashoffset="s.offset" stroke-linecap="butt" />' +
                '</svg>' +
                '<div class="donut__center">' +
                  '<div class="donut__num">{{ centerValue }}</div>' +
                  '<div class="donut__cap">{{ caption }}</div>' +
                '</div>' +
              '</div>' +
              '<div class="legend">' +
                '<div v-for="(s, i) in segments" :key="\'l\' + i" class="legend__item">' +
                  '<span class="legend__swatch" :style="{ background: s.color }"></span>' +
                  '<span class="truncate">{{ s.label }}</span>' +
                  '<span class="legend__val">{{ s.display }}</span>' +
                '</div>' +
                '<div v-if="!segments.length" class="legend__item text-faint">暂无数据</div>' +
              '</div>' +
            '</div>'
    };

    /** 迷你走势线 */
    components.AigwSpark = {
        props: {
            data: { type: Array, default: function () { return []; } },
            width: { type: Number, default: 96 },
            height: { type: Number, default: 28 },
            color: { type: String, default: '' }
        },
        computed: {
            stroke: function () { return this.color || S.palette[0]; },
            max: function () {
                var m = 0;
                this.data.forEach(function (v) { v = Number(v) || 0; if (v > m) m = v; });
                return m || 1;
            },
            path: function () {
                var n = this.data.length, w = this.width, h = this.height, max = this.max;
                if (n < 2) return '';
                var step = w / (n - 1);
                return this.data.map(function (v, i) {
                    var x = i * step;
                    var y = h - (Number(v) || 0) / max * (h - 4) - 2;
                    return (i ? 'L' : 'M') + (Math.round(x * 10) / 10) + ' ' + (Math.round(y * 10) / 10);
                }).join(' ');
            }
        },
        template:
            '<svg class="spark" :width="width" :height="height" :viewBox="\'0 0 \' + width + \' \' + height">' +
              '<path :d="path" fill="none" :stroke="stroke" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />' +
            '</svg>'
    };

    /* ── 注册 ───────────────────────────────────────────── */

    /** 全局确认框 API */
    global.AigwConfirm = function (message, options) {
        options = options || {};
        return new Promise(function (resolve) {
            confirmState.title = options.title || '请确认';
            confirmState.message = message;
            confirmState.confirmText = options.confirmText || '确定';
            confirmState.danger = !!options.danger;
            confirmState.open = true;
            confirmResolver = resolve;
        });
    };

    global.AigwComponents = components;

    global.AigwInstallComponents = function (app) {
        Object.keys(components).forEach(function (name) {
            app.component(name, components[name]);
        });
    };
})(window);