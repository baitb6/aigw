/* ============================================================
   页面组件
   ============================================================ */
(function (global) {
    'use strict';

    var Vue = global.Vue;
    var S = global.AigwStore;
    var api = global.AigwApi;
    var fmt = S.fmt;
    var pages = {};

    /* ============================================================
       用户登录页（账号端 —— 不含任何管理员入口）
       ============================================================ */
    pages.PageLogin = {
        data: function () {
            return {
                mode: 'login',      // login | register | forgot
                allowRegister: false,
                captchaEnabled: false,
                // 服务端下发的「注册页是否必须展示图形验证码」判据（含发码前校验这一路）
                registerCaptchaNeeded: false,
                captcha: '',
                captchaImg: '',
                username: '',
                password: '',
                confirm: '',
                nickname: '',
                email: '',
                // 注册邮箱域名白名单（后台可配；空 = 不限）
                emailDomains: [],
                // 邮箱验证码（后台开启「注册需邮箱验证」且 SMTP 可用时才出现）
                mailVerify: false,
                mailReady: false,
                emailCode: '',
                codeSending: false,
                codeCountdown: 0,
                // 找回密码：服务端开关 + SMTP 就绪时才展示入口
                resetEnabled: false,
                // 找回密码表单（复用 email / emailCode / password / confirm）
                resetDone: false,
                showPwd: false,
                busy: false,
                error: ''
            };
        },
        mounted: function () {
            var self = this;
            api.user.registerConfig().then(function (res) {
                if (res.ok && res.data) {
                    self.allowRegister = !!res.data.allow_register;
                    self.captchaEnabled = !!res.data.captcha_enabled;
                    self.registerCaptchaNeeded = !!res.data.register_captcha_needed;
                    self.mailReady = !!res.data.mail_ready;
                    self.mailVerify = !!res.data.mail_verify;
                    self.resetEnabled = !!res.data.password_reset_enabled;
                    // 注册邮箱域名白名单（逗号分隔字符串）→ 数组，供提示与本地预校验
                    self.emailDomains = String(res.data.register_email_domains || '')
                        .split(/[,;，；\s]+/).map(function (d) { return d.trim().toLowerCase(); })
                        .filter(function (d) { return !!d; });
                    if (self.captchaEnabled || self.registerCaptchaNeeded) self.refreshCaptcha();
                    if (self.allowRegister && self.wantsRegister()) self.switchMode('register');
                }
            });
            // 注册页弹窗公告（后台可配；同一版本只弹一次，见 store.maybeShowNotice）
            S.maybeShowNotice('register');
        },
        computed: {
            // 站点品牌（后台可配置，经 /api/site-config 下发；未取到用兜底值）
            brandName: function () {
                return (S.state.brand && S.state.brand.name) || 'AI 聚合网关';
            },
            brandDesc: function () {
                return (S.state.brand && S.state.brand.desc) || '多供应商账号池 · 统一 OpenAI 兼容出口';
            }
        },
        methods: {
            // 是否由站点首页「注册」按钮跳入（?register=1，或旧链接 #/register）
            wantsRegister: function () {
                var q = location.search || '';
                var h = location.hash || '';
                return /[?&]register(=|&|$)/.test(q) || h === '#/register';
            },
            switchMode: function (m) {
                if (this.mode === m) return;
                this.mode = m;
                this.error = '';
                this.password = '';
                this.confirm = '';
                this.emailCode = '';
                this.resetDone = false;
                // 图形验证码是一次性消费的：切模式后上一张必然已作废，必须换新图，
                // 否则用户在登录/注册间来回切一次就会撞「验证码已过期」。
                if (this.captchaEnabled || this.registerCaptchaNeeded) this.refreshCaptcha();
            },
            // 当前模式需要用到图形验证码吗？
            // 登录提交看 captchaEnabled；注册/找回的发码看 registerCaptchaNeeded
            // （找回密码发码同样走 /api/user/email-code，受 mail_code_captcha 约束）。
            needsCaptcha: function () {
                if (this.mode === 'login') return this.captchaEnabled;
                return this.registerCaptchaNeeded;
            },
            // 邮箱域名是否被后台白名单允许（空数组 = 不限）。
            // ⚠️ 这里只是**提前提示**，真正的拦截在服务端；前端可被绕过，别把它当防线。
            emailDomainAllowed: function (email) {
                if (!this.emailDomains.length) return true;
                var m = String(email || '').trim().toLowerCase().match(/@([^\s@]+)$/);
                if (!m) return true; // 邮箱本身格式不对时交给后面的格式校验报错，不抢它的报错
                var domain = m[1];
                return this.emailDomains.some(function (rule) {
                    return domain === rule || domain.slice(-(rule.length + 1)) === '.' + rule;
                });
            },
            // 白名单提示文案（未配置时返回空串）
            emailDomainHint: function () {
                if (!this.emailDomains.length) return '';
                return '仅支持 ' + this.emailDomains.map(function (d) { return '@' + d; }).join(' / ') + ' 邮箱';
            },
            // 刷新图形验证码（接口返回 SVG，直接作为图片源加载）
            refreshCaptcha: function () {
                this.captcha = '';
                this.captchaImg = api.captchaUrl();
            },
            // 发送邮箱验证码（注册 / 找回密码两种场景共用；改密码在「我的账户」页单独处理）
            sendEmailCode: function () {
                var self = this;
                if (this.codeSending || this.codeCountdown > 0) return;
                var email = (this.email || '').trim();
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    this.error = '请先填写正确的邮箱地址';
                    return;
                }
                // 域名白名单只约束**注册**口子。找回密码必须豁免 —— 存量用户里
                // 一定有人是换策略之前用别的域名注册的，拦住他们等于账号不可恢复。
                var purpose = this.mode === 'forgot' ? 'reset' : 'register';
                if (purpose === 'register' && !this.emailDomainAllowed(email)) {
                    this.error = this.emailDomainHint();
                    return;
                }
                // 图形验证码前置：没有它服务端一定拒绝，直接在本地拦下，少一次往返
                if (this.registerCaptchaNeeded && !(this.captcha || '').trim()) {
                    this.error = '请先输入右侧图形验证码';
                    return;
                }
                this.error = '';
                this.codeSending = true;
                api.user.emailCode(email, this.captcha, purpose).then(function (res) {
                    self.codeSending = false;
                    if (!res.ok) {
                        self.error = (res.error && res.error.message) || res.error || res.message || '验证码发送失败';
                        // 服务端要求图形验证码（或开关刚被打开）时同步显示出来
                        if (!self.captchaEnabled && self.error.indexOf('验证码') !== -1 && self.error.indexOf('邮箱') === -1) {
                            self.captchaEnabled = true;
                            self.registerCaptchaNeeded = true;
                        }
                        // 图形验证码一次性消费：无论成败都必须换一张，否则下次必然过期
                        if (self.captchaEnabled || self.registerCaptchaNeeded) self.refreshCaptcha();
                        return;
                    }
                    var cooldown = Number((res.data && res.data.cooldown) || 60);
                    S.toast('验证码已发送，请查收邮件', 'success');
                    self.startCountdown(cooldown > 0 ? cooldown : 60);
                    // 同上：图形验证码已被消费，立刻刷新，避免下一步踩「已过期」
                    if (self.captchaEnabled || self.registerCaptchaNeeded) self.refreshCaptcha();
                });
            },
            // 60 秒重发倒计时
            startCountdown: function (seconds) {
                var self = this;
                this.codeCountdown = seconds;
                if (this._codeTimer) clearInterval(this._codeTimer);
                this._codeTimer = setInterval(function () {
                    self.codeCountdown -= 1;
                    if (self.codeCountdown <= 0) {
                        clearInterval(self._codeTimer);
                        self._codeTimer = null;
                        self.codeCountdown = 0;
                    }
                }, 1000);
            },
            codeButtonText: function () {
                if (this.codeSending) return '发送中…';
                if (this.codeCountdown > 0) return this.codeCountdown + ' 秒后重发';
                return '获取验证码';
            },
            submit: function () {
                if (this.busy) return;
                this.error = '';
                if (this.mode === 'register') return this.doRegister();
                if (this.mode === 'forgot') return this.doReset();
                return this.doLogin();
            },
            doLogin: function () {
                var self = this;
                if (!this.username || !this.password) { this.error = '请输入邮箱与密码'; return; }
                if (this.captchaEnabled && !this.captcha) { this.error = '请输入图形验证码'; return; }
                this.busy = true;
                api.user.login(self.username, self.password, self.captcha).then(function (res) {
                    self.busy = false;
                    if (!res.ok) {
                        self.error = res.error || res.message || '登录失败';
                        if (!self.captchaEnabled && self.error.indexOf('验证码') !== -1) self.captchaEnabled = true;
                        if (self.captchaEnabled) self.refreshCaptcha();
                        return;
                    }
                    var profile = res.data || {};
                    if (profile.user) profile = profile.user;
                    S.setSession('user', profile);
                    S.toast('欢迎回来，' + (profile.nickname || profile.username || self.username), 'success');
                    self.password = '';
                    S.navigate('dashboard');
                });
            },
            doRegister: function () {
                var self = this;
                var email = (this.email || '').trim();
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { this.error = '请输入正确的邮箱地址'; return; }
                if (!this.emailDomainAllowed(email)) { this.error = this.emailDomainHint(); return; }
                if ((this.password || '').length < 8) { this.error = '密码至少 8 位'; return; }
                if (this.password !== this.confirm) { this.error = '两次输入的密码不一致'; return; }
                if (this.mailVerify && !(this.emailCode || '').trim()) { this.error = '请输入邮箱验证码'; return; }
                // 与服务端 register() 对齐：有邮箱验证时注册不再校验图形验证码
                // （图形验证码已在「获取验证码」那一步消费掉了，此处再要求必然报已过期）
                if (this.captchaEnabled && !this.mailVerify && !this.captcha) { this.error = '请输入图形验证码'; return; }
                this.busy = true;
                api.user.register({
                    email: email,
                    password: this.password,
                    nickname: (this.nickname || '').trim(),
                    captcha: this.captcha,
                    email_code: (this.emailCode || '').trim()
                }).then(function (res) {
                    self.busy = false;
                    if (!res.ok) {
                        self.error = res.error || res.message || '注册失败';
                        if (!self.captchaEnabled && self.error.indexOf('验证码') !== -1) self.captchaEnabled = true;
                        if (self.captchaEnabled) self.refreshCaptcha();
                        return;
                    }
                    var profile = res.data || {};
                    if (profile.user) profile = profile.user;
                    S.setSession('user', profile);
                    S.toast('注册成功，已自动登录', 'success');
                    self.password = '';
                    self.confirm = '';
                    S.navigate('dashboard');
                });
            },
            // 找回密码：邮箱 + 验证码 + 新密码（未登录态）
            doReset: function () {
                var self = this;
                var email = (this.email || '').trim();
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { this.error = '请输入正确的邮箱地址'; return; }
                if (!(this.emailCode || '').trim()) { this.error = '请输入邮箱验证码'; return; }
                if ((this.password || '').length < 8) { this.error = '新密码至少 8 位'; return; }
                if (this.password !== this.confirm) { this.error = '两次输入的密码不一致'; return; }
                this.busy = true;
                api.user.resetPassword({
                    email: email,
                    email_code: (this.emailCode || '').trim(),
                    new_password: this.password
                }).then(function (res) {
                    self.busy = false;
                    if (!res.ok) {
                        self.error = res.error || res.message || '重置失败';
                        return;
                    }
                    // 不自动登录：重置接口不随身发会话（少一层越权面），
                    // 也让用户用新密码走一遍正常登录，确认自己记得住。
                    self.resetDone = true;
                    self.password = '';
                    self.confirm = '';
                    S.toast(res.message || '密码已重置，请使用新密码登录', 'success');
                });
            }
        },
        template:
            '<div class="login-wrap">' +
              '<form class="login-card" @submit.prevent="submit">' +
                '<div class="login-card__mark" style="cursor:default;user-select:none"><aigw-icon name="leaf" :size="22" /></div>' +
                '<h1 class="login-card__title">{{ brandName }}</h1>' +
                '<p class="login-card__desc">{{ brandDesc }}</p>' +

                '<div class="segbar mt-16" style="display:flex">' +
                  '<button type="button" class="segbar__item" style="flex:1" :class="{ \'is-active\': mode===\'login\' }" @click="switchMode(\'login\')">登 录</button>' +
                  '<button v-if="allowRegister" type="button" class="segbar__item" style="flex:1" :class="{ \'is-active\': mode===\'register\' }" @click="switchMode(\'register\')">注 册</button>' +
                '</div>' +

                '<template v-if="mode===\'login\'">' +
                  '<div class="field mt-16">' +
                    '<label class="field__label">邮箱</label>' +
                    '<input class="input" v-model="username" autocomplete="username" placeholder="请输入注册邮箱">' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">密码</label>' +
                    '<input class="input" :type="showPwd ? \'text\' : \'password\'" v-model="password"' +
                           ' autocomplete="current-password" placeholder="请输入密码">' +
                    '<div class="field__hint">' +
                      '<label class="switch" style="margin-top:4px">' +
                        '<input type="checkbox" v-model="showPwd"><span class="switch__track"></span>' +
                        '<span>显示密码</span>' +
                      '</label>' +
                    '</div>' +
                  '</div>' +
                  '<div class="field" v-if="captchaEnabled">' +
                    '<label class="field__label">图形验证码</label>' +
                    '<div class="field__row">' +
                      '<input class="input" v-model="captcha" autocomplete="off" maxlength="6" placeholder="请输入右侧验证码">' +
                      '<img class="captcha-img" :src="captchaImg" @click="refreshCaptcha" alt="验证码" title="看不清？点击刷新">' +
                    '</div>' +
                    '<div class="field__hint">看不清？点击图片刷新</div>' +
                  '</div>' +
                  // 找不到入口是「忘记密码」最常见的失败形态 —— 放在密码框正下方，
                  // 而不是塞在页脚小字里。
                  '<div v-if="resetEnabled" class="mt-8" style="text-align:right">' +
                    '<a href="javascript:;" class="text-faint" style="font-size:12px"' +
                      ' @click.prevent="switchMode(\'forgot\')">忘记密码？</a>' +
                  '</div>' +
                '</template>' +

                '<template v-else-if="mode===\'register\'">' +
                  '<div class="field mt-16">' +
                    '<label class="field__label">邮箱</label>' +
                    '<input class="input" v-model="email" type="email" autocomplete="email" placeholder="用于登录与找回密码">' +
                    '<div class="field__hint" v-if="emailDomains.length">{{ emailDomainHint() }}</div>' +
                  '</div>' +
                  '<div class="field" v-if="registerCaptchaNeeded">' +
                    '<label class="field__label">图形验证码</label>' +
                    '<div class="field__row">' +
                      '<input class="input" v-model="captcha" autocomplete="off" maxlength="6" placeholder="获取邮箱验证码前请先输入">' +
                      '<img class="captcha-img" :src="captchaImg" @click="refreshCaptcha" alt="验证码" title="看不清？点击刷新">' +
                    '</div>' +
                    '<div class="field__hint">看不清？点击图片刷新；获取邮箱验证码前后会自动换新</div>' +
                  '</div>' +
                  '<div class="field" v-if="mailVerify">' +
                    '<label class="field__label">邮箱验证码</label>' +
                    '<div class="field__row">' +
                      '<input class="input" v-model="emailCode" autocomplete="off" maxlength="6" inputmode="numeric" placeholder="请输入邮件中的 6 位验证码">' +
                      '<button type="button" class="btn" style="white-space:nowrap"' +
                             ' :disabled="codeSending || codeCountdown > 0" @click="sendEmailCode">' +
                        '<span>{{ codeButtonText() }}</span>' +
                      '</button>' +
                    '</div>' +
                    '<div class="field__hint">验证码会发送到上方邮箱，有效期以站点设置为准</div>' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">昵称（可选）</label>' +
                    '<input class="input" v-model="nickname" autocomplete="nickname" placeholder="展示名称">' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">密码</label>' +
                    '<input class="input" :type="showPwd ? \'text\' : \'password\'" v-model="password"' +
                           ' autocomplete="new-password" placeholder="至少 8 位">' +
                  '</div>' +
                  '<div class="field">' +
                    '<label class="field__label">确认密码</label>' +
                    '<input class="input" :type="showPwd ? \'text\' : \'password\'" v-model="confirm"' +
                           ' autocomplete="new-password" placeholder="再次输入密码">' +
                    '<div class="field__hint">' +
                      '<label class="switch" style="margin-top:4px">' +
                        '<input type="checkbox" v-model="showPwd"><span class="switch__track"></span>' +
                        '<span>显示密码</span>' +
                      '</label>' +
                    '</div>' +
                  '</div>' +
                '</template>' +

                // ── 找回密码 ───────────────────────────────────────────
                // 走的是 /api/user/email-code（purpose=reset）+ /api/user/reset-password，
                // 域名白名单在服务端对本用途已豁免，所以这里**不显示**白名单提示，
                // 否则会误导存量用户以为自己的邮箱不被接受。
                '<template v-else>' +
                  '<div v-if="resetDone" class="mt-16">' +
                    '<div class="note note--sm">密码已重置，请使用新密码登录。' +
                      '其他设备上原有的登录状态已全部失效。</div>' +
                    '<button type="button" class="btn btn--primary w-full mt-16"' +
                            ' @click="switchMode(\'login\')">返回登录</button>' +
                  '</div>' +
                  '<template v-else>' +
                    '<p class="text-faint mt-16" style="font-size:11.5px">' +
                      '输入注册邮箱，我们会发送一封包含验证码的邮件。' +
                    '</p>' +
                    '<div class="field mt-8">' +
                      '<label class="field__label">注册邮箱</label>' +
                      '<input class="input" v-model="email" type="email" autocomplete="email" placeholder="请输入注册时使用的邮箱">' +
                    '</div>' +
                    '<div class="field" v-if="registerCaptchaNeeded">' +
                      '<label class="field__label">图形验证码</label>' +
                      '<div class="field__row">' +
                        '<input class="input" v-model="captcha" autocomplete="off" maxlength="6" placeholder="获取邮箱验证码前请先输入">' +
                        '<img class="captcha-img" :src="captchaImg" @click="refreshCaptcha" alt="验证码" title="看不清？点击刷新">' +
                      '</div>' +
                      '<div class="field__hint">看不清？点击图片刷新；获取邮箱验证码前后会自动换新</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">邮箱验证码</label>' +
                      '<div class="field__row">' +
                        '<input class="input" v-model="emailCode" autocomplete="off" maxlength="6" inputmode="numeric" placeholder="请输入邮件中的 6 位验证码">' +
                        '<button type="button" class="btn" style="white-space:nowrap"' +
                               ' :disabled="codeSending || codeCountdown > 0" @click="sendEmailCode">' +
                          '<span>{{ codeButtonText() }}</span>' +
                        '</button>' +
                      '</div>' +
                      '<div class="field__hint">由于安全原因，无论邮箱是否注册都会提示「已发送」，请以是否收到邮件为准</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">新密码</label>' +
                      '<input class="input" :type="showPwd ? \'text\' : \'password\'" v-model="password"' +
                             ' autocomplete="new-password" placeholder="至少 8 位">' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">确认新密码</label>' +
                      '<input class="input" :type="showPwd ? \'text\' : \'password\'" v-model="confirm"' +
                             ' autocomplete="new-password" placeholder="再次输入新密码">' +
                      '<div class="field__hint">' +
                        '<label class="switch" style="margin-top:4px">' +
                          '<input type="checkbox" v-model="showPwd"><span class="switch__track"></span>' +
                          '<span>显示密码</span>' +
                        '</label>' +
                      '</div>' +
                    '</div>' +
                  '</template>' +
                '</template>' +

                '<div v-if="error" class="note note--warn note--sm mt-8">{{ error }}</div>' +

                '<button v-if="!(mode===\'forgot\' && resetDone)" type="submit" class="btn btn--primary w-full mt-16" :disabled="busy">' +
                  '{{ busy ? (mode===\'register\' ? "注册中…" : (mode===\'forgot\' ? "重置中…" : "登录中…"))' +
                            ' : (mode===\'register\' ? "注 册" : (mode===\'forgot\' ? "重置密码" : "登 录")) }}' +
                '</button>' +

                '<p v-if="mode===\'forgot\'" class="text-faint mt-16" style="font-size:11.5px;text-align:center;margin-bottom:0">' +
                  '<a href="javascript:;" @click.prevent="switchMode(\'login\')">返回登录</a>' +
                '</p>' +
                '<p v-else-if="mode===\'login\'" class="text-faint mt-16" style="font-size:11.5px;text-align:center;margin-bottom:0">' +
                  '登录后仅能管理本人账号与调用日志' +
                '</p>' +
              '</form>' +
            '</div>'
    };

    /* ============================================================
       后台登录页（管理端 —— 独立入口，无注册）
       ============================================================ */
    pages.PageAdminLogin = {
        data: function () {
            return {
                captchaEnabled: false,
                captcha: '',
                captchaImg: '',
                username: '',
                password: '',
                showPwd: false,
                busy: false,
                error: ''
            };
        },
        mounted: function () {
            var self = this;
            api.user.registerConfig().then(function (res) {
                if (res.ok && res.data) {
                    self.captchaEnabled = !!res.data.captcha_enabled;
                    if (self.captchaEnabled) self.refreshCaptcha();
                }
            });
        },
        computed: {
            brandName: function () {
                return (S.state.brand && S.state.brand.name) || 'AI 聚合网关';
            }
        },
        methods: {
            refreshCaptcha: function () {
                this.captcha = '';
                this.captchaImg = api.captchaUrl();
            },
            submit: function () {
                var self = this;
                if (this.busy) return;
                this.error = '';
                if (!this.username || !this.password) { this.error = '请输入管理员账号与密码'; return; }
                if (this.captchaEnabled && !this.captcha) { this.error = '请输入图形验证码'; return; }
                this.busy = true;
                api.admin.login(this.username, this.password, this.captcha).then(function (res) {
                    self.busy = false;
                    if (!res.ok) {
                        self.error = res.error || res.message || '登录失败';
                        if (!self.captchaEnabled && self.error.indexOf('验证码') !== -1) self.captchaEnabled = true;
                        if (self.captchaEnabled) self.refreshCaptcha();
                        return;
                    }
                    var profile = res.data || {};
                    if (profile.admin) profile = profile.admin;
                    S.setSession('admin', profile);
                    S.toast('欢迎回来，' + (profile.nickname || profile.username || self.username), 'success');
                    self.password = '';
                    S.navigate('users');
                });
            }
        },
        template:
            '<div class="login-wrap">' +
              '<form class="login-card" @submit.prevent="submit">' +
                '<div class="login-card__mark" style="cursor:default;user-select:none"><aigw-icon name="shield" :size="22" /></div>' +
                '<h1 class="login-card__title">管理后台</h1>' +
                '<p class="login-card__desc">{{ brandName }} · 运维管理入口</p>' +

                '<div class="field mt-16">' +
                  '<label class="field__label">管理员账号</label>' +
                  '<input class="input" v-model="username" autocomplete="username" placeholder="请输入管理员账号">' +
                '</div>' +
                '<div class="field">' +
                  '<label class="field__label">密码</label>' +
                  '<input class="input" :type="showPwd ? \'text\' : \'password\'" v-model="password"' +
                         ' autocomplete="current-password" placeholder="请输入密码">' +
                  '<div class="field__hint">' +
                    '<label class="switch" style="margin-top:4px">' +
                      '<input type="checkbox" v-model="showPwd"><span class="switch__track"></span>' +
                      '<span>显示密码</span>' +
                    '</label>' +
                  '</div>' +
                '</div>' +
                '<div class="field" v-if="captchaEnabled">' +
                  '<label class="field__label">图形验证码</label>' +
                  '<div class="field__row">' +
                    '<input class="input" v-model="captcha" autocomplete="off" maxlength="6" placeholder="请输入右侧验证码">' +
                    '<img class="captcha-img" :src="captchaImg" @click="refreshCaptcha" alt="验证码" title="看不清？点击刷新">' +
                  '</div>' +
                  '<div class="field__hint">看不清？点击图片刷新</div>' +
                '</div>' +

                '<div v-if="error" class="note note--warn note--sm mt-8">{{ error }}</div>' +

                '<button type="submit" class="btn btn--primary w-full mt-16" :disabled="busy">' +
                  '{{ busy ? "登录中…" : "登 录" }}' +
                '</button>' +

                '<p class="text-faint mt-16" style="font-size:11.5px;text-align:center;margin-bottom:0">' +
                  '管理员登录入口（请妥善保管账号与密码）' +
                '</p>' +
              '</form>' +
            '</div>'
    };

    /* ============================================================
       总览面板
       ============================================================ */
    pages.PageDashboard = {
        data: function () {
            return { loading: true, days: 7, metric: 'calls', board: null, credits: null, error: '', busy: false };
        },
        computed: {
            isAdmin: function () { return S.state.role !== 'user'; },
            userApiKey: function () { return (S.state.user && S.state.user.api_key) || ''; },
            ov: function () { return (this.board && this.board.overview) || {}; },
            gateway: function () { return (this.board && this.board.gateway) || {}; },
            providers: function () { return (this.board && this.board.providers) || []; },
            trend: function () { return (this.board && this.board.trend) || []; },
            byProvider: function () { return (this.board && this.board.by_provider) || []; },
            topModels: function () { return (this.board && this.board.top_models) || []; },
            byModel: function () { return (this.board && this.board.by_model) || []; },
            recent: function () { return (this.board && this.board.recent) || []; },
            creditSummary: function () { return (this.credits && this.credits.summary) || {}; },
            creditItems: function () { return (this.credits && this.credits.items) || []; },
            metricOptions: function () {
                return [
                    { value: 'calls', label: '调用次数' },
                    { value: 'tokens', label: 'Token 用量' },
                    { value: 'cost', label: '预估费用' }
                ];
            },
            metricUnit: function () {
                if (this.metric === 'cost') return '';
                return this.metric === 'tokens' ? ' Token' : ' 次';
            },
            metricDecimals: function () { return this.metric === 'cost' ? 4 : 0; },
            metricTotal: function () {
                var key = this.metric === 'tokens' ? 'total_tokens' : this.metric;
                var sum = 0;
                this.trend.forEach(function (d) { sum += Number(d[key]) || 0; });
                return sum;
            },
            successRate: function () { return Number(this.ov.success_rate || 0); },
            rateTone: function () {
                var r = this.successRate;
                if (r >= 98) return 'celadon';
                if (r >= 90) return 'amber';
                return 'rust';
            },
            baseUrl: function () {
                return this.gateway.base_url || location.origin;
            },
            maxProviderTotal: function () {
                var m = 0;
                this.byProvider.forEach(function (p) { if (Number(p.total) > m) m = Number(p.total); });
                return m;
            }
        },
        watch: {
            days: function () { this.load(); }
        },
        mounted: function () {
            this.load();
            // 总览面板弹窗公告（后台可配；同一版本只弹一次，见 store.maybeShowNotice）
            S.maybeShowNotice('dashboard');
        },
        methods: {
            tone: function (name) { return S.providerTone.key(name); },
            load: function () {
                var self = this;
                this.loading = true;
                api.scoped.dashboard(this.days).then(function (res) {
                    self.loading = false;
                    if (!res.ok) { self.error = res.error || '加载失败'; return; }
                    self.error = '';
                    self.board = res.data || {};
                });
                api.scoped.creditMonitor().then(function (res) {
                    if (res.ok) { self.credits = res.data || {}; }
                });
            },
            flagLabel: function (flag) {
                return { EMPTY: '额度耗尽', LOW: '额度偏低', STALE: '久未刷新', EXPIRING: '凭据将过期' }[flag] || flag;
            },
            flagTone: function (flag) {
                return { EMPTY: 'danger', LOW: 'warning', STALE: 'muted', EXPIRING: 'amber' }[flag] || 'muted';
            },
            copy: function (text, label) {
                if (!text) { S.toast('内容为空，无法复制', 'warning'); return; }
                var done = function () { S.toast((label || '内容') + '已复制到剪贴板', 'success'); };
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
            checkinAll: function () {
                var self = this;
                this.busy = true;
                api.scoped.checkinAll('').then(function (res) {
                    self.busy = false;
                    S.report(res);
                    self.load();
                });
            },
            barWidth: function (v) {
                return this.maxProviderTotal ? Math.round(Number(v) / this.maxProviderTotal * 100) : 0;
            },
            rateRange: function (p) {
                if (!p || !p.rate_count) return '—';
                var min = fmt.num(p.rate_min, 2);
                var max = fmt.num(p.rate_max, 2);
                return (Number(p.rate_min) === Number(p.rate_max)) ? min : (min + '~' + max);
            },
            statTone: function (name) { return S.providerTone.key(name); }
        },
        template:
            '<div>' +
              '<div v-if="loading"><aigw-loading /></div>' +
              '<div v-else-if="error" class="card">' +
                '<aigw-empty icon="warning" title="加载失败" :desc="error" />' +
              '</div>' +

              '<div v-else>' +
                '<div class="page-head">' +
                  '<div class="page-head__main">' +
                    '<div class="topbar__title">总览</div>' +
                    '<div class="topbar__desc">账号池与调用概况</div>' +
                  '</div>' +
                  '<div class="page-head__actions">' +
                    '<button type="button" class="btn btn--sm" :disabled="busy" @click="load">刷新</button>' +
                    '<button type="button" class="btn btn--sm btn--amber" :disabled="busy" @click="checkinAll">' +
                      '{{ busy ? "签到中…" : "一键签到所有账号" }}' +
                    '</button>' +
                  '</div>' +
                '</div>' +

                '<div class="grid grid--stats">' +
                  '<aigw-stat label="账号总数" :value="fmt.num((ov.accounts && ov.accounts.total) || 0)" tone="primary" icon="accounts"' +
                            ' :foot="\'供应商 \' + (ov.enabled_providers || 0) + \' / \' + (ov.providers || 0) + \' 已启用\'" />' +
                  '<aigw-stat label="累计调用" :value="fmt.compact(ov.calls)" tone="amber" icon="logs"' +
                            ' :foot="\'窗口 \' + days + \' 天\'" />' +
                  '<aigw-stat label="成功率" :value="fmt.pct(ov.success_rate)" :tone="rateTone" icon="check"' +
                            ' :foot="\'失败 \' + fmt.num(ov.failed_calls) + \' 次\'" />' +
                  '<aigw-stat label="消耗 Token" :value="fmt.compact(ov.total_tokens)" tone="plum" icon="dashboard"' +
                            ' :foot="\'模型 \' + (ov.models || 0) + \' 个\'" />' +
                  '<aigw-stat label="预估费用" :value="fmt.num(ov.cost, ov.cost ? (ov.cost < 1 ? 4 : 2) : 0)" tone="clay" icon="money"' +
                            ' foot="Token × 倍率 ÷ 1000" />' +
                  '<aigw-stat label="平均耗时" :value="fmt.duration(ov.avg_duration)" tone="celadon" icon="timer"' +
                            ' foot="上游响应均值" />' +
                '</div>' +

                '<div class="grid grid--2 mt-16">' +
                  '<aigw-card v-if="isAdmin" title="网关接入信息" sub="把 Base URL 填到任意 OpenAI 兼容客户端">' +
                    '<div class="field">' +
                      '<label class="field__label">Base URL</label>' +
                      '<div class="field__row">' +
                        '<input class="input mono" :value="baseUrl + \'/v1\'" readonly>' +
                        '<button type="button" class="btn btn--sm" @click="copy(baseUrl + \'/v1\', \'Base URL\')">复制</button>' +
                      '</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">API Key</label>' +
                      '<div class="field__row">' +
                        '<input class="input mono" :value="gateway.api_key_masked || \'（未设置）\'" readonly>' +
                        '<button type="button" class="btn btn--sm" @click="go(\'settings\')">前往设置</button>' +
                      '</div>' +
                      '<div class="field__hint">' +
                        '默认模型：<b>{{ gateway.default_model || "未设置" }}</b> ｜ ' +
                        '默认供应商：<b>{{ gateway.default_provider || "自动选择" }}</b>' +
                      '</div>' +
                    '</div>' +
                    '<div class="wrap">' +
                      '<aigw-badge :tone="gateway.api_key_set ? \'success\' : \'danger\'" dot>' +
                        '{{ gateway.api_key_set ? "API Key 已设置" : "API Key 未设置" }}' +
                      '</aigw-badge>' +
                      '<aigw-badge :tone="gateway.allow_no_key ? \'warning\' : \'celadon\'" dot>' +
                        '{{ gateway.allow_no_key ? "允许无 Key 调用" : "强制鉴权" }}' +
                      '</aigw-badge>' +
                      '<aigw-badge :tone="gateway.only_usable_models ? \'primary\' : \'muted\'" dot>' +
                        '{{ gateway.only_usable_models ? "仅暴露可用模型" : "全部模型可见" }}' +
                      '</aigw-badge>' +
                    '</div>' +
                    '<div class="note mt-16">' +
                      '调用示例：<span class="mono">POST {{ baseUrl }}/v1/chat/completions</span>' +
                    '</div>' +
                  '</aigw-card>' +

                  '<aigw-card v-else title="我的接入信息" sub="把专属 API Key 填入任意 OpenAI 兼容客户端">' +
                    '<div class="field">' +
                      '<label class="field__label">Base URL</label>' +
                      '<div class="field__row">' +
                        '<input class="input mono" :value="baseUrl + \'/v1\'" readonly>' +
                        '<button type="button" class="btn btn--sm" @click="copy(baseUrl + \'/v1\', \'Base URL\')">复制</button>' +
                      '</div>' +
                    '</div>' +
                    '<div class="field">' +
                      '<label class="field__label">我的 API Key</label>' +
                      '<div class="field__row">' +
                        '<input class="input mono" :value="userApiKey || \'（未生成）\'" readonly>' +
                        '<button type="button" class="btn btn--sm" @click="copy(userApiKey, \'API Key\')">复制</button>' +
                      '</div>' +
                      '<div class="field__hint">调用仅会路由到你自己添加的账号</div>' +
                    '</div>' +
                    '<div class="wrap">' +
                      '<aigw-badge tone="success" dot>独立 API Key</aigw-badge>' +
                      '<aigw-badge tone="celadon" dot>账号池隔离</aigw-badge>' +
                      '<aigw-badge tone="muted" dot>仅本人调用可见</aigw-badge>' +
                    '</div>' +
                    '<div class="note mt-16">' +
                      '调用示例：<span class="mono">POST {{ baseUrl }}/v1/chat/completions</span>' +
                    '</div>' +
                  '</aigw-card>' +

                  '<aigw-card title="用量趋势" sub="按天统计的调用量、Token 与费用">' +
                    '<template #actions>' +
                      '<div class="trend-actions">' +
                        '<aigw-segbar v-model="days" :options="[' +
                          '{ value: 7, label: \'7 天\' }, { value: 14, label: \'14 天\' }, { value: 30, label: \'30 天\' }]" />' +
                        '<button type="button" class="btn btn--sm" @click="load">刷新</button>' +
                      '</div>' +
                    '</template>' +
                    '<div class="page-head">' +
                      '<div class="page-head__main"><aigw-segbar v-model="metric" :options="metricOptions" /></div>' +
                      '<div class="page-head__actions">' +
                        '<span class="text-faint nowrap" style="font-size:11.5px">' +
                          '合计 {{ metric === \'cost\' ? fmt.num(metricTotal, metricTotal ? (metricTotal < 1 ? 4 : 2) : 0) : fmt.compact(metricTotal) }}{{ metricUnit }}' +
                        '</span>' +
                      '</div>' +
                    '</div>' +
                    '<aigw-trend v-if="trend.length" :data="trend" :value-key="metric"' +
                                ' :unit="metricUnit" :decimals="metricDecimals" />' +
                    '<aigw-empty v-else icon="logs" title="暂无调用记录" desc="网关收到请求后这里会显示趋势" />' +
                  '</aigw-card>' +
                '</div>' +

                '<div class="grid grid--2 mt-16">' +
                  '<aigw-card title="供应商分布" sub="窗口内的调用量对比">' +
                    '<div v-if="byProvider.length">' +
                      '<div v-for="p in byProvider" :key="p.provider_key" style="margin-bottom:14px">' +
                        '<div class="topbar__head" style="margin-bottom:6px">' +
                          '<div class="wrap">' +
                            '<aigw-avatar :name="p.display_name || p.provider_key" :provider-key="p.provider_key" :size="24" />' +
                            '<b style="font-size:13px">{{ p.display_name || p.provider_key }}</b>' +
                            '<aigw-badge :tone="tone(p.provider_key)">{{ fmt.num(p.total) }} 次</aigw-badge>' +
                            '<aigw-badge tone="muted">倍率 {{ fmt.num(p.rate, 2) }}</aigw-badge>' +
                            '<aigw-badge tone="clay">费用 {{ fmt.num(p.cost, 4) }}</aigw-badge>' +
                          '</div>' +
                          '<span class="text-faint mono nowrap" style="font-size:11.5px">{{ fmt.pct(p.success_rate) }}</span>' +
                        '</div>' +
                        '<aigw-bar :value="barWidth(p.total)" :color="undefined" />' +
                      '</div>' +
                    '</div>' +
                    '<aigw-empty v-else icon="providers" title="暂无供应商调用" />' +
                  '</aigw-card>' +

                  '<aigw-card title="热门模型" sub="窗口内调用次数排行">' +
                    '<div v-if="topModels.length" class="table__wrap">' +
                      '<table class="table">' +
                        '<thead><tr><th>模型</th><th style="text-align:right">调用</th>' +
                        '<th style="text-align:right">Token</th><th style="text-align:right">倍率</th>' +
                        '<th style="text-align:right">费用</th></tr></thead>' +
                        '<tbody>' +
                          '<tr v-for="m in topModels" :key="m.model">' +
                            '<td class="mono truncate">{{ m.model }}</td>' +
                            '<td style="text-align:right"><b>{{ fmt.num(m.total) }}</b></td>' +
                            '<td style="text-align:right" class="text-soft">{{ fmt.compact(m.tokens) }}</td>' +
                            '<td style="text-align:right" class="text-soft">{{ fmt.num(m.rate, 2) }}</td>' +
                            '<td style="text-align:right" class="text-soft">{{ fmt.num(m.cost, 4) }}</td>' +
                          '</tr>' +
                        '</tbody>' +
                      '</table>' +
                    '</div>' +
                    '<aigw-empty v-else icon="models" title="暂无模型调用" />' +
                  '</aigw-card>' +
                '</div>' +

                '<aigw-card title="模型用量排行" sub="按 Token 用量排序，含提示词 / 补全拆分与费用" class="mt-16">' +
                  '<div v-if="byModel.length" class="table__wrap">' +
                    '<table class="table">' +
                      '<thead><tr><th>模型</th><th>供应商</th>' +
                        '<th style="text-align:right">调用</th>' +
                        '<th style="text-align:right">提示词</th>' +
                        '<th style="text-align:right">补全</th>' +
                        '<th style="text-align:right">Token</th>' +
                        '<th style="text-align:right">费用</th></tr></thead>' +
                      '<tbody>' +
                        '<tr v-for="m in byModel" :key="m.provider_key + \'::\' + m.model">' +
                          '<td class="mono truncate" style="max-width:220px">{{ m.model }}</td>' +
                          '<td class="text-soft">{{ m.provider_key }}</td>' +
                          '<td style="text-align:right"><b>{{ fmt.num(m.calls) }}</b></td>' +
                          '<td style="text-align:right" class="text-soft">{{ fmt.compact(m.prompt_tokens) }}</td>' +
                          '<td style="text-align:right" class="text-soft">{{ fmt.compact(m.completion_tokens) }}</td>' +
                          '<td style="text-align:right" class="text-soft">{{ fmt.compact(m.total_tokens) }}</td>' +
                          '<td style="text-align:right" class="text-soft">{{ fmt.num(m.cost, 4) }}</td>' +
                        '</tr>' +
                      '</tbody>' +
                    '</table>' +
                  '</div>' +
                  '<aigw-empty v-else icon="models" title="暂无模型用量" desc="产生调用后这里会按 Token 用量排行" />' +
                '</aigw-card>' +

                '<aigw-card title="额度监控" sub="额度到期 / 续期与刷新异常提醒" class="mt-16">' +
                  '<template #actions>' +
                    '<aigw-badge tone="danger" dot>耗尽 {{ creditSummary.EMPTY || 0 }}</aigw-badge>' +
                    '<aigw-badge tone="warning" dot>偏低 {{ creditSummary.LOW || 0 }}</aigw-badge>' +
                    '<aigw-badge tone="muted" dot>久未刷新 {{ creditSummary.STALE || 0 }}</aigw-badge>' +
                    '<aigw-badge tone="amber" dot>将过期 {{ creditSummary.EXPIRING || 0 }}</aigw-badge>' +
                  '</template>' +
                  '<div v-if="creditItems.length" class="table__wrap">' +
                    '<table class="table">' +
                      '<thead><tr>' +
                        '<th>账号</th><th>供应商</th><th>问题</th>' +
                        '<th style="text-align:right">剩余额度</th>' +
                        '<th style="text-align:right">上次刷新</th>' +
                        '<th style="text-align:right">凭据剩余</th>' +
                      '</tr></thead>' +
                      '<tbody>' +
                        '<tr v-for="c in creditItems" :key="c.id">' +
                          '<td class="truncate" style="max-width:200px"><b>{{ c.nickname }}</b></td>' +
                          '<td class="text-soft">{{ c.provider_name }}</td>' +
                          '<td>' +
                            '<span v-for="f in c.flags" :key="f" style="margin-right:4px">' +
                              '<aigw-badge :tone="flagTone(f)" dot>{{ flagLabel(f) }}</aigw-badge>' +
                            '</span>' +
                          '</td>' +
                          '<td style="text-align:right" class="text-soft">' +
                            '{{ c.credits_known ? fmt.num(c.credits, 2) : "未知" }}' +
                          '</td>' +
                          '<td style="text-align:right" class="text-soft">' +
                            '{{ c.last_refresh_at ? (c.stale_hours + " 小时前") : "从未" }}' +
                          '</td>' +
                          '<td style="text-align:right" class="text-soft">' +
                            '{{ c.cred_expires_in_hours != null ? (c.cred_expires_in_hours + " 小时") : "—" }}' +
                          '</td>' +
                        '</tr>' +
                      '</tbody>' +
                    '</table>' +
                  '</div>' +
                  '<aigw-empty v-else icon="check" title="账号额度健康" desc="没有需要关注的额度或续期问题" />' +
                '</aigw-card>' +

                '<aigw-card title="供应商速览" :sub="isAdmin ? \'点击进入账号池管理\' : \'你已接入账号的供应商\'" class="mt-16">' +
                  '<template #actions>' +
                    '<button v-if="isAdmin" type="button" class="btn btn--sm" @click="go(\'providers\')">管理供应商</button>' +
                  '</template>' +
                  '<div v-if="providers.length" class="grid grid--cards">' +
                    '<div v-for="p in providers" :key="p.provider_key" class="provider"' +
                         ' :class="{ \'is-off\': !p.enabled }">' +
                      '<div class="provider__head">' +
                        '<aigw-avatar :name="p.display_name" :provider-key="p.provider_key" :size="38" :square="true" />' +
                        '<div class="provider__title">' +
                          '<div class="provider__name truncate">{{ p.display_name }}</div>' +
                          '<div class="provider__key truncate">{{ p.provider_key }}</div>' +
                        '</div>' +
                        '<aigw-badge :tone="p.enabled ? \'success\' : \'muted\'" dot>' +
                          '{{ p.enabled ? "启用" : "停用" }}' +
                        '</aigw-badge>' +
                      '</div>' +
                      '<div class="provider__meta">' +
                        '<div class="kv-item"><b>{{ (p.accounts && p.accounts.total) || 0 }}</b><span>账号</span></div>' +
                        '<div class="kv-item"><b>{{ (p.accounts && p.accounts.usable) || 0 }}</b><span>可用</span></div>' +
                        '<div class="kv-item"><b>{{ p.model_count || 0 }}</b><span>模型</span></div>' +
                        '<div class="kv-item"><b>{{ rateRange(p) }}</b><span>模型倍率</span></div>' +
                      '</div>' +
                    '</div>' +
                  '</div>' +
                  '<aigw-empty v-else icon="providers" title="还没有供应商" :desc="isAdmin ? \'先到供应商页添加自定义上游\' : \'添加账号后这里会显示可用供应商\'" />' +
                '</aigw-card>' +

                '<aigw-card title="最近调用" sub="最新 8 条请求记录" class="mt-16" flush>' +
                  '<template #actions>' +
                    '<button type="button" class="btn btn--sm" @click="go(\'logs\')">查看全部日志</button>' +
                  '</template>' +
                  '<div v-if="recent.length" class="table__wrap">' +
                    '<table class="table">' +
                      '<thead><tr>' +
                        '<th>时间</th><th>供应商</th><th>模型</th><th>状态</th>' +
                        '<th style="text-align:right">耗时</th><th style="text-align:right">Token</th>' +
                      '</tr></thead>' +
                      '<tbody>' +
                        '<tr v-for="(r, i) in recent" :key="i">' +
                          '<td class="text-soft nowrap">{{ fmt.time(r.created_at) }}</td>' +
                          '<td>{{ r.provider_key }}</td>' +
                          '<td class="mono truncate" style="max-width:220px">{{ r.model }}</td>' +
                          '<td>' +
                            '<aigw-badge :tone="r.success ? \'success\' : \'danger\'" dot>' +
                              '{{ r.success ? (r.status_code || "OK") : (r.status_code || "失败") }}' +
                            '</aigw-badge>' +
                          '</td>' +
                          '<td style="text-align:right" class="text-soft">{{ fmt.duration(r.duration_ms) }}</td>' +
                          '<td style="text-align:right" class="text-soft">' +
                            '{{ fmt.num((r.prompt_tokens || 0) + (r.completion_tokens || 0)) }}' +
                          '</td>' +
                        '</tr>' +
                      '</tbody>' +
                    '</table>' +
                  '</div>' +
                  '<aigw-empty v-else icon="logs" title="暂无调用记录" desc="通过网关发起一次请求即可看到" />' +
                '</aigw-card>' +
              '</div>' +
            '</div>'
    };

    global.AigwPages = pages;
    global.AigwPagesRegister = function (more) {
        Object.keys(more || {}).forEach(function (k) { pages[k] = more[k]; });
    };
})(window);