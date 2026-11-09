/* ============================================================
   页面组件（续 7）：成长中心
   ------------------------------------------------------------
   WorkBuddy 国服（CodeBuddy）的「猫猫旅行 + 成长任务」可视化：
     - 先只读拉取快照（连登 / 补签卡 / 抽奖次数 / 猫档案 / 旅行状态 /
       任务清单 / 盲盒可开次数），页面看得见每一件事；
     - 再按项点击执行：派出旅行、领旅行奖、接任务、领任务奖、开盲盒、
       兑换连登档位、抽奖、补签、领新手礼包 / 活动补偿。
   与旧的「成长任务」批量按钮的区别：那个是整池盲跑，这个看得见、
   选得中、点得动，且每一步的结果原样回显。
   ============================================================ */
(function (global) {
    'use strict';

    var S = global.AigwStore;
    var api = global.AigwApi;
    var fmt = S.fmt;
    var pages = {};

    /** accept_status → 中文标签 */
    var TASK_STATUS_LABEL = {
        not_accepted: '未接取',
        accepted: '已接取',
        in_progress: '进行中',
        completed: '可领取',
        claimed: '已领取'
    };

    /** accept_status → 徽章语义色 */
    var TASK_STATUS_TONE = {
        not_accepted: 'muted',
        accepted: 'amber',
        in_progress: 'amber',
        completed: 'success',
        claimed: 'muted'
    };

    /** 旅行状态 → 中文标签 / 语义色 */
    var TRAVEL_LABEL = { idle: '空闲', traveling: '在途', arrived: '已到站' };
    var TRAVEL_TONE  = { idle: 'muted', traveling: 'amber', arrived: 'success' };

    /** 连登档位展示名 */
    var TIER_LABEL = { '7d': '7 天档', '14d': '14 天档', '28d': '28 天档' };

    /**
     * 单账号快照的并发上限。
     *
     * 每个账号要打 7 个上游端点（后端已并行成一次往返，约 1~2 秒）。并发拉取
     * 让「10 个账号」从串行的约 20 秒降到约 3 波、5~6 秒，且**首屏立刻可用**。
     * 上限取 4 而不是更大：php-cgi 的 worker 池有限，占满会让站点其它请求排队。
     */
    var GROWTH_CONCURRENCY = 4;

    pages.PageGrowth = {
        data: function () {
            return {
                loading: true, busy: '', error: '',
                items: [], total: 0,
                provider: 'codebuddy',
                onlyActionable: false,
                expanded: {},
                lastAt: 0,
                // 快照加载进度
                doneCount: 0,
                forceNext: false,
                // 每账号动作进行中的标记：{ accountId: actionKey }
                running: {},
                // 每账号最近一次动作结果：{ accountId: {ok, message, at} }
                results: {},
                // 每账号长耗时动作的进度文案：{ accountId: string }
                progress: {},
                // 每账号选中的旅行地点名：{ accountId: locationName }
                pickedLocation: {}
            };
        },

        computed: {
            /** 快照拉取成功的账号数 */
            okCount: function () {
                return this.items.filter(function (a) { return a.ok; }).length;
            },

            /** 已读取完成的账号数（进度显示用） */
            loadedCount: function () {
                return this.items.filter(function (a) { return a.loaded; }).length;
            },

            /** 是否还有账号在读取 */
            pendingCount: function () {
                return this.items.filter(function (a) { return a.loading; }).length;
            },

            /**
             * 汇总卡片。
             *
             * ⚠️ 只统计**已读取完成**的账号：并行拉取期间数据是陆续到达的，
             * 把「还没读到的」当成 0 会让数字先低后跳，看起来像在乱跳。
             */
            summaryCards: function () {
                var streakMax = 0, claimable = 0, boxTotal = 0, cards = 0, chances = 0;

                this.items.forEach(function (a) {
                    if (!a.loaded) return;

                    var g = a.growth || {};
                    var days = (g.streak && g.streak.days) || 0;
                    if (days > streakMax) streakMax = days;
                    claimable += (g.tasks || []).filter(function (t) {
                        return t.accept_status === 'completed' && !t.locked;
                    }).length;
                    boxTotal += (g.box && g.box.affordable) || 0;
                    cards += (g.makeup_cards && g.makeup_cards.balance) || 0;
                    chances += (g.lottery && g.lottery.balance) || 0;
                });

                var progress = this.loadedCount + ' / ' + this.total;

                return [
                    { label: '已读取账号', value: progress, tone: this.pendingCount ? 'amber' : 'primary', icon: 'accounts' },
                    { label: '可用账号', value: this.okCount, tone: 'celadon', icon: 'check' },
                    { label: '待领取任务', value: claimable, tone: claimable ? 'celadon' : 'muted', icon: 'check' },
                    { label: '最长连登', value: streakMax + ' 天', tone: 'amber', icon: 'flame' },
                    { label: '可开盲盒', value: boxTotal, tone: 'plum', icon: 'money' },
                    { label: '补签卡', value: cards, tone: 'clay', icon: 'timer' },
                    { label: '抽奖次数', value: chances, tone: 'rust', icon: 'spark' }
                ];
            }
        },

        mounted: function () { this.load(); },

        methods: {
            load: function () {
                var self = this;
                this.loading = true;
                this.error = '';

                // 第一步：只拿账号清单（后端不碰上游，毫秒级）→ 立刻把卡片渲染出来
                api.scoped.growth(this.provider).then(function (res) {
                    self.loading = false;
                    if (!res.ok) { self.error = res.error || res.message || '加载失败'; return; }

                    var d = res.data || {};

                    self.items = (d.items || []).map(function (a) {
                        return Object.assign({}, a, {
                            loaded: false, loading: false, ok: false, message: '', growth: null
                        });
                    });
                    self.total = d.total || self.items.length;
                    self.lastAt = Date.now();

                    // 第二步：并发拉每个账号的快照，各自填各自的卡片
                    self.loadSnapshots();
                });
            },

            /**
             * 并发拉取每个账号的快照（上限 GROWTH_CONCURRENCY），
             * 每张卡到达即刷新，不再让整页等最慢的那个账号。
             *
             * @param {boolean} force 跳过服务端 45 秒缓存（点「刷新」时为 true）
             */
            loadSnapshots: function (force) {
                var self = this;
                var ids = this.items.map(function (a) { return a.id; });

                this.doneCount = 0;

                if (!ids.length) return;

                // 先整体标记「读取中」，让每张卡都有转圈反馈
                this.items = this.items.map(function (a) {
                    return Object.assign({}, a, { loading: true, loaded: false });
                });

                var idx = 0;

                var mark = function (id, patch) {
                    var arr = self.items.slice();
                    for (var i = 0; i < arr.length; i++) {
                        if (arr[i].id === id) {
                            arr[i] = Object.assign({}, arr[i], patch);
                            break;
                        }
                    }
                    self.items = arr;
                };

                var next = function () {
                    if (idx >= ids.length) return;
                    var id = ids[idx++];

                    api.scoped.growthAccount(id, !!force).then(function (res) {
                        var d = (res && res.data) || {};

                        mark(id, {
                            loading:    false,
                            loaded:     true,
                            ok:         !!d.ok,
                            message:    d.message || (res && (res.message || res.error)) || '',
                            growth:     d.growth || null,
                            nickname:   d.nickname || '',
                            credits:    d.credits,
                            credits_known: d.credits_known
                        });

                        self.doneCount++;
                        self.applyDefaultExpand();
                        next();
                    });
                };

                for (var i = 0; i < Math.min(GROWTH_CONCURRENCY, ids.length); i++) {
                    next();
                }
            },

            /** 就地更新某一账号的字段（不改动其它卡，避免整页重渲染） */
            patchItem: function (id, patch) {
                var arr = this.items.slice();
                for (var i = 0; i < arr.length; i++) {
                    if (arr[i].id === id) {
                        arr[i] = Object.assign({}, arr[i], patch);
                        break;
                    }
                }
                this.items = arr;
            },

            /**
             * 只重新读取**一个**账号的快照。
             *
             * 动作执行完用它代替整页 load()：改一个账号却把所有账号重拉一遍
             * 是最典型的「越用越卡」来源（N 个账号 × 7 个上游请求）。
             */
            reloadOne: function (id, force) {
                var self = this;

                this.patchItem(id, { loading: true });

                return api.scoped.growthAccount(id, force !== false).then(function (res) {
                    var d = (res && res.data) || {};

                    self.patchItem(id, {
                        loading:  false,
                        loaded:   true,
                        ok:       !!d.ok,
                        message:  d.message || (res && (res.message || res.error)) || '',
                        growth:   d.growth || null,
                        nickname: d.nickname || '',
                        credits:  d.credits,
                        credits_known: d.credits_known
                    });
                });
            },

            /**
             * 默认展开：首次加载时把「有待领取任务」的账号展开。
             * 用户手动展开过的集合（this.expanded）优先，不覆盖。
             */
            applyDefaultExpand: function () {
                if (Object.keys(this.expanded).length > 0) return;

                var ex = {};

                this.items.forEach(function (a) {
                    var g = a.growth || {};
                    var has = (g.tasks || []).some(function (t) {
                        return t.accept_status === 'completed' && !t.locked;
                    });
                    if (has) ex[a.id] = true;
                });

                this.expanded = ex;
            },

            /** 刷新：跳过服务端缓存，强制回源 */
            refresh: function () {
                var self = this;
                this.loading = true;
                this.error = '';
                api.scoped.growth(this.provider).then(function (res) {
                    self.loading = false;
                    if (!res.ok) { self.error = res.error || res.message || '加载失败'; return; }

                    var d = res.data || {};
                    self.items = (d.items || []).map(function (a) {
                        return Object.assign({}, a, {
                            loaded: false, loading: false, ok: false, message: '', growth: null
                        });
                    });
                    self.total = d.total || self.items.length;
                    self.lastAt = Date.now();
                    self.loadSnapshots(true);
                });
            },

            toggle: function (id) {
                var ex = Object.assign({}, this.expanded);
                if (ex[id]) { delete ex[id]; } else { ex[id] = true; }
                this.expanded = ex;
            },

            isExpanded: function (id) { return !!this.expanded[id]; },

            expandAll: function () {
                var ex = {};
                this.items.forEach(function (a) { ex[a.id] = true; });
                this.expanded = ex;
            },

            collapseAll: function () { this.expanded = {}; },

            /** 该账号当前展示的任务（可选「只看可操作」） */
            tasksOf: function (a) {
                var list = (a.growth && a.growth.tasks) || [];
                if (!this.onlyActionable) return list;
                return list.filter(function (t) {
                    return !t.locked && (t.accept_status === 'completed' || t.accept_status === 'not_accepted');
                });
            },

            /** 该账号可领取的任务数 */
            claimableOf: function (a) {
                return ((a.growth && a.growth.tasks) || []).filter(function (t) {
                    return t.accept_status === 'completed' && !t.locked;
                }).length;
            },

            /** 任务卡片副标题（避免在模板里拼长表达式，易漏转义） */
            taskCardSub: function (a) {
                var total = ((a.growth && a.growth.tasks) || []).length;
                return '共 ' + total + ' 项，可领取 ' + this.claimableOf(a) + ' 项'
                    + (this.onlyActionable ? '（已过滤）' : '');
            },

            taskStatusLabel: function (s) { return TASK_STATUS_LABEL[s] || (s || '—'); },
            taskStatusTone: function (s) { return TASK_STATUS_TONE[s] || 'muted'; },
            travelLabel: function (s) { return TRAVEL_LABEL[s] || (s || '未知'); },
            travelTone: function (s) { return TRAVEL_TONE[s] || 'muted'; },
            tierLabel: function (t) { return TIER_LABEL[t] || t; },

            /** 任务是否可领取 */
            canClaim: function (t) { return !t.locked && t.accept_status === 'completed'; },
            /** 任务是否可接取 */
            canAccept: function (t) { return !t.locked && t.accept_status === 'not_accepted'; },

            /**
             * 任务能否被网关「代做」。
             *
             * 只有两类：automation_1（建自动化任务，秒级）与对话计数型
             * （chat_5 / Model_chat_*，要发真实对话并等上游结算 90~150s）。
             * 其余任务的完成判据在客户端、上游没有写接口，点了也只会得到
             * 「无法代做」——不如不给按钮，避免误导。
             */
            canDrive: function (t) {
                if (t.locked || t.accept_status === 'claimed') return false;
                var c = t.task_code || '';
                if (c === 'automation_1' || c === 'chat_5') return true;
                // Model_chat_* 里 GLM-5.2 实测连发 8+ 次仍不计数（上游判据不在
                // /v2/chat/completions 的模型字段上），给了按钮也只会白烧额度。
                if (c.indexOf('Model_chat_') === 0) return c !== 'Model_chat_GLM5.2';
                return false;
            },

            /** 代做是否长耗时（决定按钮文案与进度提示） */
            driveIsSlow: function (t) {
                var c = t.task_code || '';
                return c === 'chat_5' || c.indexOf('Model_chat_') === 0;
            },

            /** 连登某档位状态：available / claimed / locked */
            tierStatus: function (a, tier) {
                var r = (a.growth && a.growth.redemption) || {};
                return r['tier_' + tier + '_status'] || 'locked';
            },

            tierStatusLabel: function (a, tier) {
                var s = this.tierStatus(a, tier);
                return { available: '可兑换', claimed: '本月已领', locked: '天数不足' }[s] || s;
            },

            /** 补签卡可用且昨日漏签时才允许补签 */
            canUseMakeup: function (a) {
                var g = a.growth || {};
                var cards = (g.makeup_cards && g.makeup_cards.balance) || 0;
                var dates = (g.streak && g.streak.makeup_dates) || [];
                return cards > 0 && dates.length > 0;
            },

            /** 昨日日期（CST 口径，补签判据固定盯昨日） */
            yesterday: function () {
                var d = new Date(Date.now() + 8 * 3600 * 1000);
                d.setUTCDate(d.getUTCDate() - 1);
                return d.toISOString().slice(0, 10);
            },

            makeupTarget: function (a) {
                var dates = ((a.growth && a.growth.streak) || {}).makeup_dates || [];
                return dates.length ? String(dates[0]).slice(0, 10) : this.yesterday();
            },

            isRunning: function (id, key) {
                var cur = this.running[id];
                return key ? cur === key : !!cur;
            },

            resultOf: function (id) { return this.results[id] || null; },

            progressOf: function (id) { return this.progress[id] || ''; },

            /** 设置/清除某账号的进行中标记 */
            setRunning: function (id, key) {
                var next = Object.assign({}, this.running);
                if (key) { next[id] = key; } else { delete next[id]; }
                this.running = next;
            },

            /** 设置/清除某账号的进度文案（长耗时动作轮询期间显示） */
            setProgress: function (id, text) {
                var next = Object.assign({}, this.progress);
                if (text) { next[id] = text; } else { delete next[id]; }
                this.progress = next;
            },

            /** 记录某账号最近一次动作结果 */
            record: function (id, ok, message) {
                this.results = Object.assign({}, this.results, {
                    [id]: { ok: !!ok, message: message || '', at: Date.now() }
                });
            },

            /**
             * 执行一个成长中心动作。
             *
             * @param {object} a 账号行
             * @param {string} action 动作名
             * @param {object} params 参数
             * @param {string|null} key 进行中标记；传 null 表示**调用方自行管理**
             *        标记（批量场景，否则会被 run 的防重入闸挡住自己的子调用）
             * @param {boolean} silent 静默（批量时不逐条弹提示）
             */
            run: function (a, action, params, key, silent) {
                var self = this;

                if (key !== null) {
                    if (this.running[a.id]) return Promise.resolve(null);
                    this.setRunning(a.id, key || action);
                }

                return api.scoped.growthAction(a.id, action, params).then(function (res) {
                    if (key !== null) self.setRunning(a.id, '');
                    self.record(a.id, !!(res && res.ok), (res && (res.message || res.error)) || '');

                    if (!silent) S.report(res);

                    return res;
                });
            },

            /** 猫猫旅行：一步推进（无猫领养 / 空闲派出 / 到站领奖） */
            travel: function (a) {
                var self = this;
                var loc = this.locationOf(a);
                var params = loc ? { location: loc } : {};
                this.run(a, 'travel', params, 'travel').then(function (res) {
                    if (res && res.ok) self.reloadOne(a.id);
                });
            },

            /** 该账号可选的旅行地点（来自快照 travel_config） */
            locationsOf: function (a) {
                return (a.growth && a.growth.travel_config) || [];
            },

            /** 该账号当前选中的旅行地点名（未选则为空 → 由后端用默认地点） */
            locationOf: function (a) {
                var picked = this.pickedLocation[a.id];
                if (picked) return picked;
                var list = this.locationsOf(a);
                return list.length ? list[0].name : '';
            },

            setLocation: function (a, name) {
                this.pickedLocation = Object.assign({}, this.pickedLocation, { [a.id]: name });
            },

            /** 当前选中旅行地点的完整配置（用于展示描述） */
            locationInfo: function (a) {
                var name = this.locationOf(a);
                var list = this.locationsOf(a);
                for (var i = 0; i < list.length; i++) {
                    if (list[i].name === name) return list[i];
                }
                return null;
            },

            /** 猫档案头像（缩略图优先，回退静态图） */
            buddyImage: function (a) {
                var b = (a.growth && a.growth.buddy) || null;
                return b ? (b.thumbnail_url || b.base_static_url || '') : '';
            },

            /** 旅行卡副标题 */
            travelSub: function (a) {
                var t = (a.growth && a.growth.travel) || null;
                if (!t) return '暂无旅行状态';
                if (t.state === 'traveling') {
                    return t.duration_hours ? ('在途 · 约 ' + t.duration_hours + ' 小时') : '在途';
                }
                if (t.state === 'arrived') return '已到站，可领取奖励';
                if (t.daily_limit_reached) return '今日已派出过';
                return '空闲，可派出';
            },

            /** 打开任务深链（workbuddy:// 协议，桌面端唤起客户端） */
            openTaskLink: function (url) {
                if (!url) return;
                global.location.href = url;
            },

            claimTask: function (a, t) {
                var self = this;
                this.run(a, 'claim_task', { task_code: t.task_code }, 'task:' + t.task_code).then(function (res) {
                    if (res && res.ok) self.reloadOne(a.id);
                });
            },

            acceptTask: function (a, t) {
                var self = this;
                this.run(a, 'accept_task', { task_code: t.task_code }, 'task:' + t.task_code).then(function (res) {
                    if (res && res.ok) self.reloadOne(a.id);
                });
            },

            /** 领取该账号全部「可领取」的任务（逐条串行，结果汇总） */
            claimAll: function (a) {
                var self = this;
                var list = ((a.growth && a.growth.tasks) || []).filter(function (t) {
                    return self.canClaim(t);
                });
                if (!list.length) { S.toast('该账号当前没有可领取的任务', 'info'); return; }

                var done = 0, failed = 0;
                this.setRunning(a.id, 'claim-all');

                var step = function (i) {
                    if (i >= list.length) {
                        self.setRunning(a.id, '');
                        self.record(
                            a.id,
                            failed === 0,
                            '批量领奖完成：成功 ' + done + ' 项' + (failed ? ('，失败 ' + failed + ' 项') : '')
                        );
                        S.toast('批量领奖完成：成功 ' + done + ' 项' + (failed ? ('，失败 ' + failed + ' 项') : ''), failed ? 'warning' : 'success');
                        self.reloadOne(a.id);
                        return;
                    }

                    // key=null：标记由本方法统一管理（run 的防重入闸会挡住自己的子调用）
                    self.run(a, 'claim_task', { task_code: list[i].task_code }, null, true).then(function (res) {
                        if (res && res.ok) { done++; } else { failed++; }
                        step(i + 1);
                    });
                };

                step(0);
            },

            openBox: function (a) {
                var self = this;
                this.run(a, 'open_box', {}, 'box').then(function (res) {
                    if (res && res.ok) self.reloadOne(a.id);
                });
            },

            redeem: function (a, tier) {
                var self = this;
                this.run(a, 'redeem', { tier: tier }, 'redeem:' + tier).then(function (res) {
                    if (res && res.ok) self.reloadOne(a.id);
                });
            },

            draw: function (a) {
                var self = this;
                this.run(a, 'lottery_draw', {}, 'draw').then(function (res) {
                    if (res && res.ok) self.reloadOne(a.id);
                });
            },

            useMakeup: function (a) {
                var self = this;
                var date = this.makeupTarget(a);
                global.AigwConfirm('将对 ' + date + ' 使用一张补签卡，确定继续吗？', {
                    title: '使用补签卡', confirmText: '补签'
                }).then(function (yes) {
                    if (!yes) return;
                    self.run(a, 'use_makeup', { target_date: date }, 'makeup').then(function (res) {
                        if (res && res.ok) self.reloadOne(a.id);
                    });
                });
            },

            /**
             * 代做：网关替用户把客户端该做的事做掉，随后自动领奖。
             *
             * 两种耗时形态：
             *   - automation_1          → 后端同步返回（秒级）
             *   - chat_5 / Model_chat_* → 后端**异步化**（发对话 + 等上游结算 90~150s），
             *                             立即回 task_id，这里轮询到完成为止
             */
            driveTask: function (a, t) {
                var self = this;
                if (this.running[a.id]) return;

                var code = t.task_code;
                var key = 'drive:' + code;

                this.setRunning(a.id, key);
                this.setProgress(a.id, this.driveIsSlow(t)
                    ? '正在提交代做（对话计数型要等上游结算，约 2~3 分钟）…'
                    : '正在代做…');

                api.scoped.growthAction(a.id, 'drive_task', { task_code: code }).then(function (res) {
                    var d = (res && res.data) || {};

                    // 长耗时：后端已受理并异步执行 → 轮询
                    if (d.async && d.task_id) {
                        self.setProgress(a.id, d.reused
                            ? '已有代做任务在执行，等待结果…'
                            : '已受理，后台正在代做（对话计数型约需 2~3 分钟）…');
                        self.pollDrive(a.id, String(d.task_id), code);
                        return;
                    }

                    self.setRunning(a.id, '');
                    self.setProgress(a.id, '');
                    self.record(a.id, !!(res && res.ok), (res && (res.message || res.error)) || '');
                    S.report(res);
                    if (res && res.ok) self.reloadOne(a.id);
                });
            },

            /** 轮询异步代做任务（2s 一次，最长 300s，与后端 RUNNING 存活时长对齐） */
            pollDrive: function (id, taskId, code) {
                var self = this;
                var tries = 0;
                var maxTries = 150;
                var errStreak = 0;

                function finish(ok, message) {
                    self.setRunning(id, '');
                    self.setProgress(id, '');
                    self.record(id, ok, message);
                    S.toast(ok ? ('代做完成：' + message) : ('代做未成功：' + message), ok ? 'success' : 'warning');
                    self.reloadOne(id);
                }

                var timer = setInterval(function () {
                    tries++;
                    if (tries > maxTries) {
                        finish(false, '代做超时未返回结果，请稍后点「刷新」查看任务状态');
                        return;
                    }

                    api.scoped.accountTask(taskId).then(function (res) {
                        if (!res || !res.ok) {
                            errStreak++;
                            if (errStreak >= 5) {
                                finish(false, '无法查询代做结果：' + ((res && (res.message || res.error)) || '网络异常'));
                            }
                            return;
                        }
                        errStreak = 0;

                        var d = (res && res.data) || {};

                        if (!d.done) {
                            var sec = Math.round((d.elapsed_ms || 0) / 1000);
                            self.setProgress(id, sec < 40
                                ? ('正在代做（第 ' + sec + ' 秒）…')
                                : ('正在等待上游结算（第 ' + sec + ' 秒；对话计数型结算需 90~150 秒）…'));
                            return;
                        }

                        clearInterval(timer);
                        finish(!!d.ok, d.message || '');
                    });
                }, 2000);
            },

            claimGift: function (a) {
                var self = this;
                this.run(a, 'claim_gift', {}, 'gift').then(function (res) {
                    if (res && res.ok) self.reloadOne(a.id);
                });
            },

            claimCompensation: function (a) {
                var self = this;
                this.run(a, 'claim_compensation', {}, 'comp').then(function (res) {
                    if (res && res.ok) self.reloadOne(a.id);
                });
            },

            /** 全部账号一键推进旅行（仍是逐账号串行，但结果可见） */
            travelAllAccounts: function () {
                var self = this;
                var list = this.items.filter(function (a) { return a.ok; });
                if (!list.length) { S.toast('没有可操作的账号', 'warning'); return; }

                var ok = 0, fail = 0, i = 0;
                var step = function () {
                    if (i >= list.length) {
                        S.toast('猫猫旅行完成：成功 ' + ok + '，失败 ' + fail, fail ? 'warning' : 'success');
                        self.loadSnapshots(true);
                        return;
                    }
                    var acc = list[i];
                    var loc = self.locationOf(acc);
                    self.run(acc, 'travel', loc ? { location: loc } : {}, 'travel', true).then(function (res) {
                        if (res && res.ok) { ok++; } else { fail++; }
                        i++;
                        step();
                    });
                };
                step();
            },

            fmtTime: function (ms) { return ms ? fmt.time(ms) : '—'; }
        },

        template:
            '<div>' +
              '<div class="page-head">' +
                '<div class="page-head__main">' +
                  '<div class="flex items-center" style="gap:12px;flex-wrap:wrap">' +
                    '<aigw-switch v-model="onlyActionable" label="只看可操作（可领取 / 可接取）" />' +
                    '<span class="text-faint" style="font-size:12px">' +
                      '快照只读，点按钮才会真正执行。' +
                      '<span v-if="lastAt"> 上次读取 {{ fmtTime(lastAt) }}</span>' +
                    '</span>' +
                  '</div>' +
                '</div>' +
                '<div class="page-head__actions">' +
                  '<label class="field__label" style="margin:0 6px 0 0">供应商</label>' +
                  '<select class="input" style="width:150px" v-model="provider" @change="load">' +
                    '<option value="codebuddy">CodeBuddy 国服</option>' +
                    '<option value="">全部（仅国服可读）</option>' +
                  '</select>' +
                  '<button type="button" class="btn btn--sm" :disabled="pendingCount > 0" @click="refresh">刷新</button>' +
                  '<button type="button" class="btn btn--sm btn--amber" @click="travelAllAccounts">全部账号推进旅行</button>' +
                  '<button type="button" class="btn btn--sm" @click="expandAll">全部展开</button>' +
                  '<button type="button" class="btn btn--sm" @click="collapseAll">全部折叠</button>' +
                '</div>' +
              '</div>' +

              '<div class="grid grid--stats" style="margin-bottom:16px">' +
                '<aigw-stat v-for="c in summaryCards" :key="c.label" :label="c.label" :value="c.value" :tone="c.tone" :icon="c.icon" />' +
              '</div>' +

              '<div v-if="pendingCount > 0" class="note note--sm note--info" style="margin-bottom:16px">' +
                '<span class="spinner" style="margin-right:6px"></span>' +
                '正在并发读取各账号成长数据（{{ loadedCount }} / {{ total }} 完成，剩余 {{ pendingCount }} 个）——' +
                '已读取完的账号可以立即操作，不必等全部读完。' +
              '</div>' +

              '<div v-if="loading" class="loading"><span class="spinner"></span><span>正在读取成长中心…</span></div>' +
              '<div v-else-if="error" class="note note--warn">{{ error }}</div>' +
              '<aigw-empty v-else-if="!items.length" icon="flame" title="没有可读取的账号"' +
                          ' desc="成长中心仅支持 CodeBuddy 国服（codebuddy）账号，且账号需处于启用状态" />' +

              '<div v-else>' +
              '<section v-for="a in items" :key="a.id" class="card">' +
                '<header class="card__head card__head--custom">' +
                  '<div class="grouphead">' +
                    '<button type="button" class="grouphead__toggle" @click="toggle(a.id)"' +
                            ' :aria-expanded="isExpanded(a.id) ? \'true\' : \'false\'">' +
                      '<aigw-icon :name="isExpanded(a.id) ? \'chevronUp\' : \'chevronDown\'" :size="16" />' +
                    '</button>' +
                    '<div class="grouphead__main">' +
                      '<div class="grouphead__title">' +
                        '<span class="grouphead__name">{{ a.nickname }}</span>' +
                        '<span class="mono text-faint" style="font-size:11.5px">{{ a.provider_key }}</span>' +
                        '<aigw-badge v-if="a.growth && a.growth.buddy" tone="plum">' +
                          '{{ a.growth.buddy.name }} · {{ a.growth.buddy.rarity }}' +
                        '</aigw-badge>' +
                        '<aigw-badge v-else-if="a.ok" tone="muted">未领养</aigw-badge>' +
                        '<aigw-badge v-if="a.growth && a.growth.travel" :tone="travelTone(a.growth.travel.state)" dot>' +
                          '旅行：{{ travelLabel(a.growth.travel.state) }}' +
                        '</aigw-badge>' +
                      '</div>' +
                      '<div class="grouphead__sub">' +
                        '<span v-if="a.loading" class="text-faint">正在读取该账号的成长数据…</span>' +
                        '<template v-else-if="a.ok">' +
                          '<span v-if="a.growth && a.growth.streak">连登 {{ a.growth.streak.days }} 天</span>' +
                          '<span v-if="a.growth && a.growth.makeup_cards"> · 补签卡 {{ a.growth.makeup_cards.balance }}/{{ a.growth.makeup_cards.max }}</span>' +
                          '<span v-if="a.growth && a.growth.lottery"> · 抽奖次数 {{ a.growth.lottery.balance }}</span>' +
                          '<span v-if="a.growth && a.growth.box"> · 盲盒可开 {{ a.growth.box.affordable }}</span>' +
                          '<span v-if="claimableOf(a)"> · 待领取 {{ claimableOf(a) }} 项</span>' +
                        '</template>' +
                        '<span v-else class="text-faint">{{ a.message }}</span>' +
                      '</div>' +
                    '</div>' +
                    '<div class="grouphead__actions">' +
                      '<button type="button" class="btn btn--sm btn--amber"' +
                              ' :disabled="isRunning(a.id) || !a.ok" @click="travel(a)">' +
                        '{{ isRunning(a.id, \'travel\') ? \'推进中…\' : \'猫猫旅行\' }}' +
                      '</button>' +
                      '<button type="button" class="btn btn--sm btn--celadon"' +
                              ' :disabled="isRunning(a.id) || !claimableOf(a)" @click="claimAll(a)">' +
                        '{{ isRunning(a.id, \'claim-all\') ? \'领取中…\' : (\'领取全部（\' + claimableOf(a) + \'）\') }}' +
                      '</button>' +
                    '</div>' +
                  '</div>' +
                '</header>' +

                '<div class="card__body" v-show="isExpanded(a.id)">' +
                  '<div v-if="resultOf(a.id)" class="note note--sm"' +
                       ' :class="resultOf(a.id).ok ? \'note--success\' : \'note--warn\'" style="margin-bottom:12px">' +
                    '最近一次操作（{{ fmtTime(resultOf(a.id).at) }}）：{{ resultOf(a.id).message }}' +
                  '</div>' +

                  '<div v-if="progressOf(a.id)" class="note note--sm note--info" style="margin-bottom:12px">' +
                    '<span class="spinner" style="margin-right:6px"></span>{{ progressOf(a.id) }}' +
                  '</div>' +

                  '<div v-if="a.loading" class="loading">' +
                    '<span class="spinner"></span><span>正在读取该账号的成长数据…</span>' +
                  '</div>' +

                  '<template v-else-if="a.ok">' +
                    '<div class="grid grid--2" style="margin-bottom:16px">' +
                      '<aigw-card title="我的猫猫" :sub="a.growth && a.growth.buddy ? a.growth.buddy.soul_desc : \'\'">' +
                        '<div v-if="a.growth && a.growth.buddy" class="buddycard">' +
                          '<div class="buddycard__avatar">' +
                            '<img v-if="buddyImage(a)" class="buddycard__img" :src="buddyImage(a)" :alt="a.growth.buddy.name">' +
                            '<span v-else class="buddycard__fallback">{{ (a.growth.buddy.name || \'喵\').slice(0, 1) }}</span>' +
                          '</div>' +
                          '<div class="buddycard__main">' +
                            '<div class="buddycard__name">' +
                              '{{ a.growth.buddy.name }}' +
                              '<aigw-badge tone="plum">{{ a.growth.buddy.rarity }}</aigw-badge>' +
                            '</div>' +
                            '<div v-if="a.growth.buddy.personality" class="buddycard__desc">{{ a.growth.buddy.personality }}</div>' +
                          '</div>' +
                        '</div>' +
                        '<aigw-empty v-else icon="flame" title="尚未领养猫猫" desc="点「猫猫旅行」即可自动领养一只" />' +
                      '</aigw-card>' +

                      '<aigw-card title="猫猫旅行" :sub="travelSub(a)">' +
                        '<template v-if="a.growth && a.growth.travel">' +
                          '<div class="flex items-center" style="gap:10px;flex-wrap:wrap">' +
                            '<aigw-badge :tone="travelTone(a.growth.travel.state)" dot>旅行：{{ travelLabel(a.growth.travel.state) }}</aigw-badge>' +
                            '<span v-if="a.growth.travel.daily_limit_reached" class="text-faint" style="font-size:12px">今日已派出过</span>' +
                            '<span v-if="a.growth.travel.reward_credit" class="text-faint" style="font-size:12px">到站奖励 {{ a.growth.travel.reward_credit }} 积分</span>' +
                          '</div>' +
                          '<div v-if="locationsOf(a).length" class="field mt-8 mb-0">' +
                            '<label class="field__label">目的地</label>' +
                            '<select class="select" :value="locationOf(a)" @change="setLocation(a, $event.target.value)">' +
                              '<option v-for="l in locationsOf(a)" :key="l.id" :value="l.name">' +
                                '{{ l.name }}（{{ l.reward_credit_min }}~{{ l.reward_credit_max }} 积分 · {{ l.duration_hours_min }}~{{ l.duration_hours_max }} 小时）' +
                              '</option>' +
                            '</select>' +
                            '<div v-if="locationInfo(a) && locationInfo(a).description" class="field__hint">{{ locationInfo(a).description }}</div>' +
                          '</div>' +
                          '<div class="mt-8">' +
                            '<button type="button" class="btn btn--sm btn--amber"' +
                                    ' :disabled="isRunning(a.id)" @click="travel(a)">' +
                              '{{ isRunning(a.id, \'travel\') ? \'推进中…\' : \'推进旅行\' }}' +
                            '</button>' +
                          '</div>' +
                        '</template>' +
                        '<aigw-empty v-else icon="flame" title="暂无旅行状态" desc="账号读取完成后会显示旅行进度" />' +
                      '</aigw-card>' +
                    '</div>' +

                    '<div class="grid grid--2" style="margin-bottom:16px">' +
                      '<aigw-card title="连登与奖励" :sub="a.growth && a.growth.streak ? (\'已连登 \' + a.growth.streak.days + \' 天 · 下一档还差 \' + (a.growth.streak.next_tier_remaining || 0) + \' 天\') : \'\'">' +
                        '<div v-if="a.growth && a.growth.redemption && a.growth.redemption.tiers && a.growth.redemption.tiers.length">' +
                          '<div v-for="t in a.growth.redemption.tiers" :key="t.tier" class="modelrow" style="margin-bottom:8px">' +
                            '<div class="modelrow__main">' +
                              '<div class="modelrow__name">{{ tierLabel(t.tier) }}（连登 {{ t.days }} 天）</div>' +
                              '<div class="modelrow__sub">' +
                                '积分 +{{ t.credit }} · 能量 +{{ t.energy }} · 补签卡 +{{ t.cards }} · 抽奖 +{{ t.chances }}' +
                              '</div>' +
                            '</div>' +
                            '<div class="modelrow__side">' +
                              '<aigw-badge :tone="tierStatus(a, t.tier) === \'available\' ? \'success\' : \'muted\'">' +
                                '{{ tierStatusLabel(a, t.tier) }}' +
                              '</aigw-badge>' +
                              '<button type="button" class="btn btn--sm btn--primary" style="margin-left:8px"' +
                                      ' :disabled="isRunning(a.id) || tierStatus(a, t.tier) !== \'available\'"' +
                                      ' @click="redeem(a, t.tier)">兑换</button>' +
                            '</div>' +
                          '</div>' +
                        '</div>' +
                        '<aigw-empty v-else icon="flame" title="暂无连登档位" desc="连登奖励按 7 / 14 / 28 天分档" />' +
                        '<div class="note note--sm mt-8" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">' +
                          '<span>补签卡 {{ (a.growth.makeup_cards || {}).balance || 0 }} 张</span>' +
                          '<button type="button" class="btn btn--sm" :disabled="isRunning(a.id) || !canUseMakeup(a)"' +
                                  ' @click="useMakeup(a)">' +
                            '{{ isRunning(a.id, \'makeup\') ? \'补签中…\' : (\'对 \' + makeupTarget(a) + \' 补签\') }}' +
                          '</button>' +
                          '<span class="text-faint" v-if="!canUseMakeup(a)">（需有补签卡且存在漏签日期）</span>' +
                        '</div>' +
                      '</aigw-card>' +

                      '<aigw-card title="盲盒与抽奖" :sub="a.growth && a.growth.box ? (\'积分余额 \' + a.growth.box.balance + \' · 每次消耗 \' + a.growth.box.cost_per_open) : \'\'">' +
                        '<div class="grid grid--stats" style="margin-bottom:12px">' +
                          '<div class="stat stat--plum">' +
                            '<div class="stat__top"><div class="stat__label">可开盲盒</div></div>' +
                            '<div class="stat__value">{{ (a.growth.box || {}).affordable || 0 }}</div>' +
                          '</div>' +
                          '<div class="stat stat--rust">' +
                            '<div class="stat__top"><div class="stat__label">抽奖次数</div></div>' +
                            '<div class="stat__value">{{ (a.growth.lottery || {}).balance || 0 }}</div>' +
                          '</div>' +
                        '</div>' +
                        '<div class="flex items-center" style="gap:8px;flex-wrap:wrap">' +
                          '<button type="button" class="btn btn--sm btn--celadon"' +
                                  ' :disabled="isRunning(a.id) || !((a.growth.box || {}).affordable)" @click="openBox(a)">' +
                            '{{ isRunning(a.id, \'box\') ? \'开启中…\' : \'开一次盲盒\' }}' +
                          '</button>' +
                          '<button type="button" class="btn btn--sm btn--primary"' +
                                  ' :disabled="isRunning(a.id) || !((a.growth.lottery || {}).balance)" @click="draw(a)">' +
                            '{{ isRunning(a.id, \'draw\') ? \'抽奖中…\' : \'抽一次奖\' }}' +
                          '</button>' +
                          '<button type="button" class="btn btn--sm" :disabled="isRunning(a.id)" @click="claimGift(a)">' +
                            '{{ isRunning(a.id, \'gift\') ? \'领取中…\' : \'领新手礼包\' }}' +
                          '</button>' +
                          '<button type="button" class="btn btn--sm" :disabled="isRunning(a.id)" @click="claimCompensation(a)">' +
                            '{{ isRunning(a.id, \'comp\') ? \'领取中…\' : \'领活动补偿\' }}' +
                          '</button>' +
                        '</div>' +
                      '</aigw-card>' +
                    '</div>' +

                    '<aigw-card title="成长任务" :sub="taskCardSub(a)">' +
                      '<div v-if="tasksOf(a).length">' +
                        '<div v-for="t in tasksOf(a)" :key="t.task_code" class="modelrow" style="margin-bottom:8px">' +
                          '<div class="modelrow__main">' +
                            '<div class="modelrow__name">' +
                              '{{ t.title || t.task_code }}' +
                              '<span class="mono text-faint" style="font-size:11px;margin-left:6px">{{ t.task_code }}</span>' +
                            '</div>' +
                            '<div class="modelrow__sub">' +
                              '<span v-if="t.task_desc || t.description">{{ t.task_desc || t.description }}</span>' +
                              '<span v-if="t.reward_credit"> · 奖励 {{ t.reward_credit }} 积分</span>' +
                              '<span v-if="t.reward_energy"> · {{ t.reward_energy }} 能量</span>' +
                              '<span v-if="t.progress && t.progress.target"> · 进度 {{ t.progress.current }}/{{ t.progress.target }}</span>' +
                            '</div>' +
                          '</div>' +
                          '<div class="modelrow__side">' +
                            '<aigw-badge :tone="t.locked ? \'muted\' : taskStatusTone(t.accept_status)" dot>' +
                              '{{ t.locked ? \'未开放\' : taskStatusLabel(t.accept_status) }}' +
                            '</aigw-badge>' +
                            '<button v-if="t.jump_url" type="button" class="btn btn--sm btn--ghost" style="margin-left:8px"' +
                                    ' title="在 WorkBuddy 客户端中打开" @click="openTaskLink(t.jump_url)">' +
                              '去客户端' +
                            '</button>' +
                            '<button v-if="canClaim(t)" type="button" class="btn btn--sm btn--celadon" style="margin-left:8px"' +
                                    ' :disabled="isRunning(a.id)" @click="claimTask(a, t)">' +
                              '{{ isRunning(a.id, \'task:\' + t.task_code) ? \'领取中…\' : \'领取奖励\' }}' +
                            '</button>' +
                            '<button v-else-if="canDrive(t)" type="button" class="btn btn--sm btn--primary" style="margin-left:8px"' +
                                    ' :disabled="isRunning(a.id)"' +
                                    ' :title="driveIsSlow(t) ? \'网关替你发真实对话并等待上游结算后自动领奖（约 2~3 分钟）\' : \'网关替你创建自动化任务并自动领奖（数秒）\'"' +
                                    ' @click="driveTask(a, t)">' +
                              '{{ isRunning(a.id, \'drive:\' + t.task_code) ? \'代做中…\' : (driveIsSlow(t) ? \'代做（慢）\' : \'代做\') }}' +
                            '</button>' +
                            '<button v-else-if="canAccept(t)" type="button" class="btn btn--sm" style="margin-left:8px"' +
                                    ' :disabled="isRunning(a.id)" @click="acceptTask(a, t)">' +
                              '{{ isRunning(a.id, \'task:\' + t.task_code) ? \'接取中…\' : \'接取\' }}' +
                            '</button>' +
                            '<span v-else-if="t.accept_status === \'claimed\'" class="text-faint" style="margin-left:8px;font-size:12px">已领取</span>' +
                            '<span v-else class="text-faint" style="margin-left:8px;font-size:12px">需在客户端完成</span>' +
                          '</div>' +
                        '</div>' +
                      '</div>' +
                      '<aigw-empty v-else icon="check" title="没有符合条件的任务"' +
                                  ' :desc="onlyActionable ? \'关闭「只看可操作」可查看全部任务\' : \'该账号暂无成长任务下发\'" />' +
                    '</aigw-card>' +
                  '</template>' +
                  '<div v-else class="note note--warn">{{ a.message || \'读取成长数据失败\' }}</div>' +
                '</div>' +
              '</section>' +
              '</div>' +
            '</div>'
    };

    global.AigwPagesRegister(pages);
})(window);
