<?php
/**
 * 账号池调度服务（对应原 App 的账号轮换与降级策略）
 *
 * 策略：
 *  1. 额度低的账号优先排序由 AccountRepository::usable() 负责
 *  2. 错误按 ErrorCategory 分类：QUOTA 长冷却 / SOFT_RATE 短冷却 / SESSION_DEAD 禁用
 *  3. 单次请求按顺序尝试多个账号，直到成功或账号耗尽
 */

declare(strict_types=1);

namespace App\Services;

use App\Provider\Dto\Account;
use App\Provider\ErrorCategory;
use App\Provider\ProviderManager;
use App\Repositories\AccountRepository;
use App\Repositories\ActivityLogRepository;
use App\Repositories\CheckinLogRepository;
use App\Repositories\GatewayRepository;
use App\Repositories\ModelCooldownRepository;
use App\Repositories\ProviderRepository;
use App\Repositories\TravelLogRepository;
use App\Support\Logger;

class AccountPool
{
    /** 各供应商的“签到”动作名（不具备签到能力的供应商不在此表中，将被跳过） */
    private const CHECKIN_ACTIONS = [
        'trae'           => 'checkin',
        'trae_intl'      => 'checkin',
        'codebuddy'      => 'checkin',
        'codebuddy_intl' => 'checkin',
        'qoder'          => 'checkin',
        'qoder_intl'     => 'checkin',
    ];

    /** 支持「成长中心 / 猫猫旅行」的供应商（国际版接口返回空数据，不参与） */
    private const TRAVEL_PROVIDERS = ['codebuddy'];

    /** 支持「每日活跃注入」的供应商（国际版每日活跃自动发放积分，需当日有一次对话） */
    private const ACTIVITY_PROVIDERS = ['codebuddy_intl'];

    /** 支持「成长中心自动领奖」的供应商（旅行到站领奖 + 成长任务领奖 + 开盲盒，国际版接口返回空数据，不参与） */
    private const TASKS_PROVIDERS = ['codebuddy'];

    /** 支持「连登管家」的供应商（补签 + 连登奖励兑换 + 抽奖 + 盲盒 + 礼包/补偿，国际版成长域返回空数据，不参与） */
    private const STREAK_PROVIDERS = ['codebuddy'];

    public function __construct(
        private AccountRepository $accountRepository,
        private GatewayRepository $gatewayRepository,
        private ProviderManager $providerManager,
        private ProviderRepository $providerRepository,
        private CheckinLogRepository $checkinLogRepository,
        private ModelCooldownRepository $modelCooldownRepository,
        private TravelLogRepository $travelLogRepository,
        private ActivityLogRepository $activityLogRepository,
    ) {}

    /**
     * 租约续期节流状态（进程内）：账号 id → 上次续期时刻 / 是否仍持有。
     * 只在单次请求生命周期内有意义，故无需持久化。
     *
     * @var array<int,int>
     */
    private array $leaseRenewedAt = [];

    /** @var array<int,bool> */
    private array $leaseRenewHeld = [];

    /**
     * 本进程当前持有的租约指纹：账号 id → 当初 acquireLease 写入的到期时间戳。
     * 续期时用它做 CAS 比对，是「能识别出租约被抢」的关键（只比对库里的当前值等于没校验）。
     *
     * @var array<int,int>
     */
    private array $heldLeaseUntil = [];

    /**
     * 冷却清理节流：两次全表清理之间至少间隔这么多秒。
     * 用 storage/tmp 下的哨兵文件 mtime 做跨进程水位，读写失败时静默放行
     * （退化为每次清理，与旧行为一致，不会出错）。
     */
    private const COOLDOWN_SWEEP_INTERVAL = 30;

    private static function throttleCooldownSweep($accountRepository, $modelCooldownRepository): void
    {
        $marker = AIGW_ROOT . '/storage/tmp/cooldown-sweep.ts';
        $now = time();
        $last = is_file($marker) ? (int) @filemtime($marker) : 0;

        if ($last > 0 && ($now - $last) < self::COOLDOWN_SWEEP_INTERVAL) {
            return;
        }
        // 先落哨兵再清理：并发请求下宁可漏清一轮，也不让多个进程同时跑 UPDATE
        @touch($marker);

        $accountRepository->releaseExpiredCooldowns();
        $modelCooldownRepository->releaseExpired();
    }

    /**
     * 取出该供应商下可调度账号（已按调度模式、额度与错误数排序）
     *
     * 调度模式（网关设置 schedule_mode）：
     *  - balance ：额度优先（默认）
     *  - rotation：轮询，最少使用优先，多账号均匀分摊（平局时临期积分优先）
     *  - pinned  ：固定账号优先，失效时按额度兜底
     *  - expiry  ：按积分到期日分层，临期积分优先消耗，避免作废
     *
     * 处于「指数退避」等待窗口（next_retry_at 未到）的账号视为未就绪，暂不参与调度。
     * 若指定模型，则同时剔除「该账号+该模型」处于限流冷却中的账号（其他模型仍可用）。
     *
     * @return Account[]
     */
    public function candidates(string $providerKey, ?string $preferredUid = null, ?string $model = null, ?int $userId = null): array
    {
        // 顺手释放到期冷却，避免账号被永久占用。
        //
        // 性能优化：这是两条 UPDATE/DELETE，旧实现**每次对话请求**都跑一遍。
        // 冷却到期的可见性晚几秒完全无感（cooldown_until 本身也是秒级粒度），
        // 改为按文件时间戳节流：每 30s 至多清一次，高并发下省掉绝大部分写操作。
        self::throttleCooldownSweep($this->accountRepository, $this->modelCooldownRepository);

        $settings = $this->gatewayRepository->current();
        $mode     = (string) ($settings['schedule_mode'] ?? 'balance');
        $pinned   = (string) ($settings['pinned_account_uid'] ?? '');

        $accounts = $this->accountRepository->usable($providerKey, $mode, $pinned, $userId);

        // 模型级限流隔离：仅剔除该模型被冷却的账号
        if ($model !== null && $model !== '') {
            $cooled = $this->modelCooldownRepository->coolingMap($providerKey, $model);
            if ($cooled !== []) {
                $accounts = array_values(array_filter(
                    $accounts,
                    fn(Account $a) => !isset($cooled[$a->uid])
                ));
            }
        }

        if ($preferredUid === null || $preferredUid === '') {
            return $accounts;
        }

        // 指定账号优先（客户端可强制走某账号）
        $preferred = [];
        $rest = [];
        foreach ($accounts as $account) {
            if ($account->uid === $preferredUid) {
                $preferred[] = $account;
            } else {
                $rest[] = $account;
            }
        }

        return array_merge($preferred, $rest);
    }

    /**
     * 查询「供应商 + 模型」维度正在限流冷却中的账号映射。
     *
     * 供网关在「候选账号为空」时区分两种成因：
     *   · 账号池整体不可用（真的没号/全被禁用）→ 提示去管理台处理；
     *   · 仅本模型被上游限流（账号健康、换模型即可）→ 提示换模型。
     * 不区分会把一次正常的模型级限流报成"账号池挂了"，误导排查方向。
     *
     * @return array<string,int> uid => 冷却截止毫秒时间戳
     */
    public function coolingAccountsForModel(string $providerKey, string $model): array
    {
        $map = [];
        foreach ($this->modelCooldownRepository->active(500, $providerKey) as $row) {
            if ((string) ($row['model'] ?? '') === $model) {
                $map[(string) $row['account_uid']] = (int) $row['cooling_until'];
            }
        }

        return $map;
    }

    /**
     * 按错误分类对账号执行降级动作
     *
     * 熔断冷却策略：
     *  - 429 软限流（SOFT_RATE）      → 短冷却
     *  - 404 资源不存在（NOT_FOUND）  → 软冷却（避免持续命中异常账号）
     *  - 余额不足（QUOTA）           → 硬冷却至次日 04:00（额度重置），且不短于基础冷却
     *  - 上游异常（UPSTREAM）        → 计错误数，越限进入冷却
     *  - 登录失效（SESSION_DEAD）    → 直接禁用，等待重新登录
     *  - 模型级限流（MODEL_RATE）    → 仅冷却「账号+模型」，不动账号状态
     *  - 上下文超长（CONTEXT_TOO_LONG）→ 客户端问题，不降级
     *  - 客户端错误（CLIENT）        → 与账号无关，不降级
     * 可恢复类错误额外写入指数退避门闸（next_retry_at），供调度层跳过。
     *
     * @param string      $model        本次请求模型（模型级限流隔离需要）
     * @param int         $retryAfterMs 上游给出的重置窗口（毫秒，0=未知）
     */
    public function penalize(Account $account, string $code, string $message, ?string $model = null, int $retryAfterMs = 0): void
    {
        $settings = $this->gatewayRepository->current();

        switch ($code) {
            case ErrorCategory::QUOTA:
                // 余额不足 → 硬冷却至次日 04:00（不短于配置的基础冷却时长）
                $until = max($this->nextQuotaResetAt(), now_ms() + (int) $settings['quota_cooldown_ms']);
                $this->accountRepository->markQuotaExhaustedUntil($account, $message, $until);
                return;

            case ErrorCategory::MODEL_RATE:
                // 模型级限流：只冷却「账号+模型」，冷却时长优先取上游重置时间
                $this->coolModel($account, $model, $message, $settings, $retryAfterMs);
                return;

            case ErrorCategory::CONTEXT_TOO_LONG:
                // 上下文超长与账号无关，换号无效，直接回 400
                return;

            case ErrorCategory::PAYLOAD_TOO_LARGE:
                // 请求体过大（413）与账号健康无关：是**请求侧**的体积问题，
                // 换号、换模型都不会好。计错误数只会误伤健康账号。
                // 上层已据此回 413 并提示精简请求体。
                return;

            case ErrorCategory::CONTENT_BLOCKED:
                // 内容审核拦截与账号无关：同样的内容换个账号照样被拒，
                // 绝不能计错误数（否则一次违规提问就把健康账号推进冷却）。
                return;

            case ErrorCategory::REASONING_BUDGET:
                // 推理预算不足与账号健康、上游服务都无关：是**请求参数**（max_tokens 太小）
                // 的问题，换号、等冷却都不会好。计错误数只会误伤健康账号。
                // 上层已据此回 400 并给出「提高 max_tokens」的可执行提示。
                return;

            case ErrorCategory::MODEL_UNAVAILABLE:
                // 模型不可用（Trae 4023）与账号健康无关：是模型维度的问题，
                // 换号、等冷却都不会好。计错误数只会把健康账号推入 COOLING，
                // 随后候选集为空、用户只看到「账号池中没有可用账号」，
                // 把「选了个未开通的模型」误报成「账号池挂了」。
                return;

            case ErrorCategory::NETWORK:
                // 传输/基建故障（DNS、连接、TLS）与账号健康无关：
                // 域名解析不了的场景，换哪个账号都一样。计错误数只会误伤健康账号。
                return;

            case ErrorCategory::UPSTREAM_NODE:
                // 上游节点级故障（某推理节点自身执行失败）与账号健康无关：
                // 实测同账号 13 个模型全好、仅 1 个命中坏节点，若计错误数
                // 就会「选错一个模型 → 账号被打成 ERROR → 整个供应商所有模型全挂」。
                // 不计错误数、不冷却、不写退避门闸，仅靠上层换账号再试。
                return;

            case ErrorCategory::RISK_CONTROL:
                // 上游风控（豆包 shark_admin / 710022004）：**该账号被上游标记**，
                // 继续用它只会持续撞墙（实测同账号连"你好"都拦）。
                // 与 CONTENT_BLOCKED 的本质区别：那个是"内容问题、换号无用"，
                // 这个是"账号问题、换号可恢复"。
                // 动作：软冷却本账号（让调度换其他号），退避门闸一并生效。
                // reason 用专用标记，排查时一眼区分"风控"与"限流"。
                $this->accountRepository->markRiskControl(
                    $account,
                    $message,
                    (int) $settings['soft_cooldown_ms']
                );
                break;

            case ErrorCategory::SOFT_RATE:
                $this->accountRepository->markSoftRateLimited(
                    $account,
                    $message,
                    (int) $settings['soft_cooldown_ms']
                );
                break;

            case ErrorCategory::NOT_FOUND:
                // 404 → 软冷却，避免持续命中
                $this->accountRepository->markNotFound(
                    $account,
                    $message,
                    (int) $settings['soft_cooldown_ms']
                );
                break;

            case ErrorCategory::SESSION_DEAD:
                // 登录失效需人工重登，无需退避
                $this->accountRepository->markSessionDead($account, $message);
                return;

            case ErrorCategory::UPSTREAM:
                $this->accountRepository->markError(
                    $account,
                    $message,
                    (int) $settings['max_error_count'],
                    (int) $settings['soft_cooldown_ms']
                );
                break;

            case ErrorCategory::EMPTY_RESPONSE:
                return;

            case ErrorCategory::CLIENT:
                // 客户端错误与账号无关，不降级
                return;
        }

        // 连续失败 → 指数退避（仅作调度门闸，不改变可用状态）
        $this->applyBackoff($account, $settings);
    }

    /**
     * 写入「账号+模型」级限流冷却。
     * 冷却时长 = max(配置基础冷却, 上游重置窗口)，且封顶 6 小时，避免异常时间戳。
     *
     * @param array<string, mixed> $settings
     */
    private function coolModel(Account $account, ?string $model, string $message, array $settings, int $retryAfterMs): void
    {
        if ($model === null || $model === '') {
            // 无法定位模型时退化为账号级软冷却
            $this->accountRepository->markSoftRateLimited($account, $message, (int) $settings['soft_cooldown_ms']);
            return;
        }

        $base  = max(1000, (int) $settings['soft_cooldown_ms']);
        $delay = max($base, $retryAfterMs);
        $delay = (int) min(21600000, $delay);

        $this->modelCooldownRepository->cool(
            $account->providerKey,
            $account->uid,
            $model,
            now_ms() + $delay,
            $message
        );

        Logger::info('账号模型级限流冷却', [
            'uid'        => $account->uid,
            'provider'   => $account->providerKey,
            'model'      => $model,
            'cooldown_ms' => $delay,
        ]);
    }

    /**
     * 写入指数退避门闸：延迟随连续错误数翻倍增长，并封顶 15 分钟。
     *
     * @param array<string, mixed> $settings
     */
    private function applyBackoff(Account $account, array $settings): void
    {
        $base    = max(500, (int) ($settings['backoff_base_ms'] ?? 2000));
        $attempt = max(1, $account->errorCount + 1);
        $delay   = (int) min(900000, $base * (2 ** min($attempt - 1, 10)));

        $this->accountRepository->markBackoff($account->id, now_ms() + $delay);
    }

    /**
     * 计算「下一次额度重置时刻」：最近的一个 04:00（若已过则取次日）。
     * 用于余额不足的硬冷却终点。
     */
    private function nextQuotaResetAt(): int
    {
        $now   = now_ms();
        $today = strtotime(date('Y-m-d') . ' 04:00:00');
        if ($today === false) {
            return $now + 86400000;
        }

        $todayMs = $today * 1000;
        return $todayMs > $now ? $todayMs : $todayMs + 86400000;
    }

    /**
     * 尝试占用账号「在途租约」，避免同一账号被并发请求重复占用。
     * 返回 false 表示该账号当前正被其他请求占用，应跳过。
     */
    public function tryAcquire(Account $account): bool
    {
        return $this->acquireLease($account) > 0;
    }

    /**
     * 「免排队」哨兵返回值：>0 表示已持有，但不落库、不做任何 CAS。
     *
     * 用于白名单供应商（`lease_bypass_providers`）—— 这些上游已验证可承受同账号并发，
     * 故跳过租约互斥。用固定正数而非常量 PHP_INT_MAX，便于日志分辨。
     */
    private const LEASE_BYPASS_HELD = 1;

    /**
     * 该账号所属供应商是否在「免排队」白名单内。
     *
     * 命中则**完全跳过**在途租约的 acquire/renew/release 三步：
     * 既不打库（省一次 UPDATE 与一次 SELECT），也不会污染 CAS 指纹。
     *
     * 匹配规则（含前缀通配 `custom*`）统一由 `GatewayRepository::providerListMatches`
     * 定义，**此处不得另写一份** —— 两处实现漂移会造成「管理台显示已加、实际仍排队」。
     */
    private function leaseBypassed(Account $account): bool
    {
        $list = $this->bypassProviders();
        if ($list === []) {
            return false;
        }

        return GatewayRepository::providerListMatches((string) $account->providerKey, $list);
    }

    /**
     * 读取免排队供应商白名单（已归一为小写数组）。
     * current() 已把该列归一成数组；这里再兜一层字符串形态，防老库/直连场景。
     *
     * @return list<string>
     */
    private function bypassProviders(): array
    {
        $value = $this->gatewayRepository->current()['lease_bypass_providers'] ?? '';
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn($v) => strtolower(trim((string) $v)),
                $value
            ), static fn($v) => $v !== ''));
        }

        return \App\Repositories\GatewayRepository::normalizeProviderList((string) $value);
    }

    /**
     * 占用租约并返回本进程的租约到期时间戳（0=失败）。
     *
     * 调用方需记住该值：后续 `renewLeaseFor` 用它做 CAS 比对，
     * 才能可靠地判断「租约是否还属于自己」。
     */
    public function acquireLease(Account $account): int
    {
        // 免排队供应商：直接放行，不打库、不记指纹 ——
        // 后续 renew/release 也会按同一白名单短路，故不需 heldLeaseUntil。
        if ($this->leaseBypassed($account)) {
            return self::LEASE_BYPASS_HELD;
        }

        $ttl = (int) ($this->gatewayRepository->current()['lease_ttl_ms'] ?? 120000);
        $until = $this->accountRepository->acquireLease($account->id, $ttl);

        if ($until > 0) {
            $this->heldLeaseUntil[$account->id] = $until;
            // 重置续期节流状态（新一次占用 = 新的租约）
            $this->leaseRenewedAt[$account->id] = 0;
            $this->leaseRenewHeld[$account->id] = true;
        }

        return $until;
    }

    /**
     * 释放在途租约。
     *
     * 走 CAS（用本进程当初 acquireLease 记住的值）：若租约已被他人抢占，
     * 则只清自己的进程内状态、**不动库里的他人租约**，避免把并发守护一起抹掉。
     */
    public function release(Account $account): void
    {
        // 免排队供应商从不持有库租约，必须**静默返回** ——
        // 若往下走，$expect 将为 0，而 releaseLease(id, 0) 是**强制清零**语义，
        // 会把这个账号上「他人正在持有的真租约」一并抹掉（撞 CAS 铁律）。
        if ($this->leaseBypassed($account)) {
            return;
        }

        $expect = $this->heldLeaseUntil[$account->id] ?? 0;
        $this->accountRepository->releaseLease($account->id, $expect);
        unset($this->heldLeaseUntil[$account->id], $this->leaseRenewedAt[$account->id], $this->leaseRenewHeld[$account->id]);
    }

    /**
     * 续期在途租约（仅当仍是自己那条）。
     *
     * 为什么需要：`lease_ttl_ms` 默认 120s，而上游 `chat()` 超时上限是 600s，
     * 实测已有 **94s** 的慢调用。若不续期，长调用的租约会在请求还没结束时就过期，
     * 并发请求便会在该窗口穿透、与前者**同时使用同一个账号**（对单账号供应商尤其危险，
     * 会直接撞上游并发限制）。
     *
     * @return bool 是否仍持有租约（false = 已被释放/抢占，长调用应视为失去独占）
     */
    public function renewLeaseNow(Account $account): bool
    {
        // 免排队供应商：无库租约可续，视为「仍持有」（与 renewLeaseFor 语义一致）
        if ($this->leaseBypassed($account)) {
            return true;
        }

        $key = $account->id;
        // 以「本进程当初写入的值」作为 CAS 条件，这是能识别抢占的关键。
        $expect = $this->heldLeaseUntil[$key] ?? 0;
        if ($expect <= 0) {
            return false;
        }

        $ttl = (int) ($this->gatewayRepository->current()['lease_ttl_ms'] ?? 120000);
        $newUntil = $this->accountRepository->renewLease($key, $ttl, $expect);
        $ok = $newUntil > 0;

        if ($ok) {
            // ⚠️ 必须用仓库返回的**真实写入值**回写指纹，不能在本地重算 now_ms()+ttl：
            // 两次取 now 有几毫秒差 → 指纹与库里永远错开 → 下次 CAS 恒失败（隔次续期失效）。
            $this->heldLeaseUntil[$key] = $newUntil;
        }

        return $ok;
    }

    /**
     * 带节流的续期，供流式回调按帧调用。
     *
     * 每帧都打库代价不可接受，故按 TTL 的 1/3 节流：
     * 既保证租约在过期前被刷新，又把写库压到「每 TTL 周期最多 3 次」。
     *
     * @return bool 是否仍持有租约
     */
    public function renewLeaseFor(Account $account): bool
    {
        // 免排队供应商：无库租约可续，恒返回「仍持有」——
        // 调用方用它判断「长调用是否失去独占」，而免排队本就不需独占。
        if ($this->leaseBypassed($account)) {
            return true;
        }

        $now = now_ms();
        $key = $account->id;

        $ttl = (int) ($this->gatewayRepository->current()['lease_ttl_ms'] ?? 120000);
        $interval = max(5000, (int) floor($ttl / 3));
        $last = $this->leaseRenewedAt[$key] ?? 0;

        // 热路径零 DB：节流窗口内直接沿用上次结论
        if ($last > 0 && ($now - $last) < $interval) {
            return $this->leaseRenewHeld[$key] ?? false;
        }

        $ok = $this->renewLeaseNow($account);
        $this->leaseRenewedAt[$key] = $now;
        $this->leaseRenewHeld[$key] = $ok;

        return $ok;
    }

    /**
     * 调用成功后恢复账号状态并回写额度（同时清除错误退避门闸与模型级冷却）
     */
    public function reward(Account $account, ?string $model = null): void
    {
        $this->accountRepository->markSuccess($account);
        $this->accountRepository->clearBackoff($account->id);

        if ($model !== null && $model !== '') {
            $this->modelCooldownRepository->clear($account->providerKey, $account->uid, $model);
        }
    }

    /**
     * 刷新单个账号额度
     */
    public function refreshCredits(Account $account): array
    {
        $provider = $this->providerManager->get($account->providerKey);

        try {
            $info = $provider->credits($account);
        } catch (\Throwable $e) {
            Logger::warn('刷新额度异常', [
                'provider' => $account->providerKey,
                'uid'      => $account->uid,
                'error'    => $e->getMessage(),
            ]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if ($info->error !== null) {
            // 登录失效 → 直接禁用
            if (str_contains($info->error, '失效') || str_contains($info->error, '过期')) {
                $this->accountRepository->markSessionDead($account, $info->error);
            }

            return ['ok' => false, 'message' => $info->error];
        }

        $this->accountRepository->updateStatus($account->id, [
            'credits'         => $info->known ? $info->balance : null,
            'credits_known'   => $info->known,
            'credits_detail'  => $info->detail,
            'last_refresh_at' => now_ms(),
        ]);

        // 积分到期语义：记录最近到期时间，供「额度监控」高亮与优先排序
        if ($info->known && $info->expireAt > 0) {
            $this->accountRepository->setCreditsExpire($account->id, $info->expireAt);
        }

        // 快照用量汇总与权益包明细到 meta，供账号详情展示（无需二次请求上游）
        $metaPatch = [];
        if ($info->usage !== null) {
            $metaPatch['usage'] = $info->usage;
        }
        if (!empty($info->packs)) {
            $metaPatch['packs'] = $info->packs;
        }
        if ($metaPatch !== []) {
            $this->accountRepository->patchMeta($account, $metaPatch);
        }

        if (!empty($info->credentialPatch)) {
            $this->accountRepository->patchCredential($account, $info->credentialPatch);
        }

        // 额度恢复且有余额 → 解除冷却
        if ($info->known && ($info->balance ?? 0) > 0 && $account->isCooling()) {
            $this->accountRepository->clearCooldown($account->id);
        }

        return [
            'ok'      => true,
            'message' => $info->detail ?: '额度已刷新',
            'balance' => $info->balance,
            'known'   => $info->known,
            'packs'   => $info->packs,
        ];
    }

    /**
     * 批量刷新某供应商（或全部）的账号额度
     */
    public function refreshAll(?string $providerKey = null, ?int $userId = null): array
    {
        $accounts = $this->accountsForScope($providerKey, $userId);

        $ok = 0;
        $failed = 0;
        $details = [];

        foreach ($accounts as $account) {
            if (!$account->enabledByUser) {
                continue;
            }

            $result = $this->refreshCredits($account);
            if ($result['ok']) {
                $ok++;
            } else {
                $failed++;
            }

            $details[] = [
                'uid'      => $account->uid,
                'provider' => $account->providerKey,
                'ok'       => $result['ok'],
                'message'  => $result['message'],
            ];
        }

        return [
            'ok'      => $failed === 0,
            'message' => sprintf('刷新完成：成功 %d 个，失败 %d 个', $ok, $failed),
            'data'    => $details,
        ];
    }

    /**
     * 每日 Token 保活：对全部（或指定供应商的）已启用账号执行一次 refreshToken。
     * 用于避免长期不活跃导致 refresh token 失效。
     */
    public function keepaliveAll(?string $providerKey = null, ?int $userId = null): array
    {
        $accounts = $this->accountsForScope($providerKey, $userId);

        $ok = 0;
        $failed = 0;
        $refreshed = 0;
        $details = [];

        foreach ($accounts as $account) {
            if (!$account->enabledByUser) {
                continue;
            }

            try {
                $provider = $this->providerManager->get($account->providerKey);
                $patch = $provider->refreshToken($account);
            } catch (\Throwable $e) {
                $failed++;
                $details[] = [
                    'uid'      => $account->uid,
                    'provider' => $account->providerKey,
                    'ok'       => false,
                    'message'  => $e->getMessage(),
                ];
                continue;
            }

            // Provider 可通过以 __ 开头的保留控制键表达状态（见 AbstractProvider::refreshToken），
            // 这些键仅供调度层解释，不写入凭据。
            $sessionDead = (bool) ($patch['__session_dead'] ?? false);
            $notice      = (string) ($patch['__message'] ?? '');
            $credentialPatch = array_filter(
                $patch,
                static fn ($key) => is_string($key) && !str_starts_with($key, '__'),
                ARRAY_FILTER_USE_KEY
            );

            if ($sessionDead) {
                $message = $notice !== '' ? $notice : '登录已失效，请重新登录';
                $this->accountRepository->markSessionDead($account, $message);
                $failed++;
                $details[] = [
                    'uid'         => $account->uid,
                    'provider'    => $account->providerKey,
                    'ok'          => false,
                    'message'     => $message,
                    'relogin_url' => (string) ($patch['__relogin_url'] ?? ''),
                ];
                continue;
            }

            if (!empty($credentialPatch)) {
                $this->accountRepository->patchCredential($account, $credentialPatch);
                $refreshed++;
            }
            $ok++;
            $details[] = [
                'uid'      => $account->uid,
                'provider' => $account->providerKey,
                'ok'       => true,
                'message'  => $notice !== ''
                    ? $notice
                    : (empty($credentialPatch) ? '无需刷新' : 'Token 已续期'),
            ];
        }

        return [
            'ok'      => $failed === 0,
            'message' => sprintf('Token 保活完成：成功 %d 个（其中续期 %d 个），失败 %d 个', $ok, $refreshed, $failed),
            'data'    => $details,
        ];
    }

    /**
     * 一键签到：对全部（或指定供应商的）已启用账号执行签到
     *
     * 不具备签到能力的供应商（如 loomy / antigravity / custom）会被跳过并计入 skipped。
     * 具备幂等：非强制模式下，本地已记录「今日已签到」的账号直接跳过，避免重复请求上游；
     * 每次结果都会写入签到日志，供管理台查看与 30 天保留。
     *
     * @param string $trigger 触发来源：manual|scheduled|startup
     */
    public function checkinAll(?string $providerKey = null, string $trigger = 'manual', bool $force = false, ?int $userId = null): array
    {
        $accounts = $this->accountsForScope($providerKey, $userId);

        $ok = 0;
        $already = 0;
        $failed = 0;
        $skipped = 0;
        $details = [];

        foreach ($accounts as $account) {
            if (!$account->enabledByUser) {
                continue;
            }

            $action = self::CHECKIN_ACTIONS[$account->providerKey] ?? '';
            if ($action === '') {
                $skipped++;
                $details[] = [
                    'uid'      => $account->uid,
                    'provider' => $account->providerKey,
                    'ok'       => null,
                    'message'  => '该供应商不支持签到',
                ];
                continue;
            }

            // 今日幂等：本地已签到过则不再请求上游（强制模式除外）
            if (!$force && $account->isCheckedInToday()) {
                $already++;
                $ok++;
                $details[] = [
                    'uid'      => $account->uid,
                    'provider' => $account->providerKey,
                    'ok'       => true,
                    'message'  => '今日已签到（本地记录）',
                ];
                continue;
            }

            try {
                $provider = $this->providerManager->get($account->providerKey);
                $result = $provider->action($account, $action, []);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => $e->getMessage()];
            }

            $resultOk = (bool) ($result['ok'] ?? false);
            $message = (string) ($result['message'] ?? '');
            $alreadyToday = $resultOk && str_contains($message, '已签到');

            $status = 'FAILED';
            if ($resultOk) {
                $status = $alreadyToday ? 'ALREADY' : 'SUCCESS';
            }

            if ($resultOk) {
                $ok++;
                if ($alreadyToday) {
                    $already++;
                }
                // 无论上游返回「已签到」还是「签到成功」，今日打卡记忆均写入
                $this->accountRepository->markCheckedIn($account->id, now_ms());
            } else {
                $failed++;
            }

            // 记录积分变化（上游若返回则取用）
            $creditsAfter = isset($result['credits']) ? (float) $result['credits'] : null;

            $this->checkinLogRepository->record([
                'provider_key'   => $account->providerKey,
                'account_uid'    => $account->uid,
                'nickname'       => $account->nickname,
                'status'         => $status,
                'message'        => $message,
                'credits_before' => $account->creditsKnown ? $account->credits : null,
                'credits_after'  => $creditsAfter,
                'trigger'        => $trigger,
            ]);

            $details[] = [
                'uid'      => $account->uid,
                'provider' => $account->providerKey,
                'ok'       => $resultOk,
                'message'  => $message,
            ];
        }

        return [
            'ok'      => $failed === 0,
            'message' => sprintf('签到完成：成功 %d 个（含已签到 %d 个），失败 %d 个，跳过 %d 个', $ok, $already, $failed, $skipped),
            'data'    => $details,
        ];
    }

    /**
     * 启动核验：当日尚未签到的账号补签一次（幂等，不强制重复请求）。
     */
    public function verifyStartupCheckins(?string $providerKey = null): array
    {
        return $this->checkinAll($providerKey, 'startup', false);
    }

    /**
     * 猫猫旅行批量执行：对全部（或指定供应商的）已启用账号推进旅行状态
     *
     * 行为（对齐参考实现）：
     *  - 仅 codebuddy（国服）参与；国际版账号被跳过（成长域接口返回空数据）。
     *  - 账号间按 travel_rate_limit_ms（默认 800ms）限速，避免触发上游风控。
     *  - 自然日幂等：今日已到站领奖（arrived）或领养门槛未达（blocked）的账号直接跳过，
     *    除非 force=true；失败不写入日志，以便下一轮重试。
     *  - 每次结果写入 aigw_travel_log，供管理台展示与 30 天保留。
     *
     * @param string $trigger 触发来源：manual|scheduled
     */
    public function travelAll(?string $providerKey = null, string $trigger = 'manual', bool $force = false, ?int $userId = null): array
    {
        $settings = $this->gatewayRepository->current();
        $location = (string) ($settings['travel_location'] ?? '古镇客栈');
        $rateMs   = max(0, (int) ($settings['travel_rate_limit_ms'] ?? 800));

        $accounts = $this->accountsForScope($providerKey, $userId);

        $ok = 0;
        $failed = 0;
        $skipped = 0;
        $details = [];

        foreach ($accounts as $account) {
            if (!$account->enabledByUser) {
                continue;
            }

            if (!in_array($account->providerKey, self::TRAVEL_PROVIDERS, true)) {
                $skipped++;
                $details[] = [
                    'uid'      => $account->uid,
                    'provider' => $account->providerKey,
                    'ok'       => null,
                    'message'  => '该供应商不支持猫猫旅行',
                ];
                continue;
            }

            // 自然日幂等：到站已领奖 / 领养门槛未达，当日不再重复请求（强制模式除外）
            $todayState = $this->travelLogRepository->todayState($account->providerKey, $account->uid);
            if (!$force && in_array($todayState, ['arrived', 'blocked'], true)) {
                $skipped++;
                $details[] = [
                    'uid'      => $account->uid,
                    'provider' => $account->providerKey,
                    'ok'       => true,
                    'message'  => $todayState === 'arrived' ? '今日已领旅行奖励' : '领养门槛未达（今日已尝试）',
                ];
                continue;
            }

            // 账号间限速
            if ($rateMs > 0) {
                usleep($rateMs * 1000);
            }

            try {
                $provider = $this->providerManager->get($account->providerKey);
                $result = $provider->action($account, 'travel', ['location' => $location]);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
            }

            $resultOk = (bool) ($result['ok'] ?? false);
            $message  = (string) ($result['message'] ?? '');
            $data     = is_array($result['data'] ?? null) ? $result['data'] : [];
            $state    = (string) ($data['state'] ?? '');

            if ($resultOk) {
                $ok++;
            } else {
                $failed++;
            }

            // 仅记录已推进/明确终态的结果；纯失败不落库，便于下一轮重试
            if ($resultOk && $state !== '') {
                $reward = $data['reward'] ?? null;
                $this->travelLogRepository->record([
                    'provider_key' => $account->providerKey,
                    'account_uid'  => $account->uid,
                    'state'        => $state,
                    'location'     => (string) ($data['location'] ?? ($state === 'idle' ? '' : $location)),
                    'reward'       => $reward !== null && (int) $reward > 0 ? (string) (int) $reward : '',
                    'message'      => $message,
                ]);
            }

            $details[] = [
                'uid'      => $account->uid,
                'provider' => $account->providerKey,
                'ok'       => $resultOk,
                'message'  => $message,
            ];
        }

        return [
            'ok'      => $failed === 0,
            'message' => sprintf('猫猫旅行完成：推进 %d 个，失败 %d 个，跳过 %d 个', $ok, $failed, $skipped),
            'data'    => $details,
        ];
    }

    /**
     * 每日活跃注入批量执行：对全部（或指定供应商的）已启用账号注入当日活跃
     *
     * 行为：
     *  - 仅 codebuddy_intl（WorkBuddy 国际版）参与；其他供应商被跳过。
     *  - 账号间按 activity_rate_limit_ms（默认 1500ms）限速，降低触发上游风控的概率。
     *  - 自然日幂等：今日已成功注入的账号直接跳过（除非 force=true），
     *    即「今天调用过一次之后，之后再调用跳过今天已经调用过的」。
     *  - 结果写入 aigw_activity_log（SUCCESS/FAILED），失败下一轮可重试。
     *
     * @param string $trigger 触发来源：manual|scheduled
     */
    public function activityAll(?string $providerKey = null, string $trigger = 'manual', bool $force = false, ?int $userId = null): array
    {
        $settings = $this->gatewayRepository->current();
        $rateMs   = max(0, (int) ($settings['activity_rate_limit_ms'] ?? 1500));

        $accounts = $this->accountsForScope($providerKey, $userId);

        $ok = 0;
        $failed = 0;
        $skipped = 0;
        $details = [];

        foreach ($accounts as $account) {
            if (!$account->enabledByUser) {
                continue;
            }

            if (!in_array($account->providerKey, self::ACTIVITY_PROVIDERS, true)) {
                $skipped++;
                $details[] = [
                    'uid'      => $account->uid,
                    'provider' => $account->providerKey,
                    'ok'       => null,
                    'message'  => '该供应商不支持每日活跃注入',
                ];
                continue;
            }

            // 自然日幂等：今日已成功注入则跳过（强制模式除外）
            if (!$force && $this->activityLogRepository->doneToday($account->providerKey, $account->uid)) {
                $skipped++;
                $details[] = [
                    'uid'      => $account->uid,
                    'provider' => $account->providerKey,
                    'ok'       => true,
                    'message'  => '今日已注入活跃（跳过）',
                ];
                continue;
            }

            // 账号间限速
            if ($rateMs > 0) {
                usleep($rateMs * 1000);
            }

            try {
                $provider = $this->providerManager->get($account->providerKey);
                $result = $provider->action($account, 'activity', []);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
            }

            $resultOk = (bool) ($result['ok'] ?? false);
            $message  = (string) ($result['message'] ?? '');
            $data     = is_array($result['data'] ?? null) ? $result['data'] : [];
            $model    = (string) ($data['model'] ?? '');

            if ($resultOk) {
                $ok++;
            } else {
                $failed++;
            }

            $this->activityLogRepository->record([
                'provider_key' => $account->providerKey,
                'account_uid'  => $account->uid,
                'status'       => $resultOk ? 'SUCCESS' : 'FAILED',
                'model'        => $model,
                'message'      => $message,
            ]);

            $details[] = [
                'uid'      => $account->uid,
                'provider' => $account->providerKey,
                'ok'       => $resultOk,
                'message'  => $message,
            ];
        }

        return [
            'ok'      => $failed === 0,
            'message' => sprintf('每日活跃注入完成：成功 %d 个，失败 %d 个，跳过 %d 个', $ok, $failed, $skipped),
            'data'    => $details,
        ];
    }

    /**
     * 成长中心批量领奖：对全部（或指定供应商的）已启用账号自动领取成长中心奖励
     *
     * 行为（对齐 WorkBuddy 国服「成长中心」页面）：
     *  - 仅 codebuddy（国服）参与；国际版账号被跳过（成长域接口返回空数据）。
     *  - 依次自动完成：猫猫旅行到站领奖（arrived → claim）、成长任务领奖（completed → claim）、
     *    Buddy 盲盒（remaining>0 → open），解决「旅行到站不会自动确认、任务不做」的问题。
     *  - 账号间按 travel_rate_limit_ms（默认 800ms）限速，避免触发上游风控。
     *  - 不做自然日跳过：上游接口本身幂等（已领取返回 already_claimed、已派出返回 daily_limit_reached），
     *    轮询频率由调度水位 scheduler_last_tasks_at + tasks_interval_minutes 控制。
     *
     * @param string $trigger 触发来源：manual|scheduled
     */
    public function tasksAll(?string $providerKey = null, string $trigger = 'manual', bool $force = false, ?int $userId = null, int $seeds = 0): array
    {
        $settings = $this->gatewayRepository->current();
        $rateMs   = max(0, (int) ($settings['travel_rate_limit_ms'] ?? 800));

        // 对话种子条数：显式传参优先，否则读全局配置（tasks_chat_seeds，默认 0 = 不代发对话）
        if ($seeds <= 0) {
            $seeds = max(0, (int) ($settings['tasks_chat_seeds'] ?? 0));
        }

        $accounts = $this->accountsForScope($providerKey, $userId);

        $ok = 0;
        $failed = 0;
        $skipped = 0;
        $empty = 0;
        $details = [];

        foreach ($accounts as $account) {
            if (!$account->enabledByUser) {
                continue;
            }

            if (!in_array($account->providerKey, self::TASKS_PROVIDERS, true)) {
                $skipped++;
                continue;
            }

            // 账号间限速
            if ($rateMs > 0) {
                usleep($rateMs * 1000);
            }

            try {
                $provider = $this->providerManager->get($account->providerKey);
                $result = $provider->action($account, 'tasks', ['seeds' => $seeds]);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
            }

            $resultOk = (bool) ($result['ok'] ?? false);
            $message  = (string) ($result['message'] ?? '');
            $items    = is_array($result['data']['items'] ?? null) ? $result['data']['items'] : [];

            // 统计口径区分「真的领到东西」与「本次无内容」：
            // 旧实现把「成长中心没有可领取的内容」也计入 ok，导致日志出现
            // 「成功 1 个」的**假成功**，掩盖了「三段分支因没解信封而全部空转」的故障
            // （2026-10-02 事故：连续数日「成功 1 个」，实际一件奖品都没领到）。
            if (!$resultOk) {
                $failed++;
            } elseif ($items === []) {
                $empty++;
            } else {
                $ok++;
            }

            $details[] = [
                'uid'      => $account->uid,
                'provider' => $account->providerKey,
                'ok'       => $resultOk,
                'message'  => $message,
            ];
        }

        return [
            'ok'      => $failed === 0,
            'message' => sprintf('成长中心领奖完成：领取 %d 个，无内容 %d 个，失败 %d 个，跳过 %d 个', $ok, $empty, $failed, $skipped),
            'data'    => $details,
        ];
    }

    /**
     * 连登管家（国服成长域）：批量把每个账号「可做的连登相关事项」一次性做掉。
     *
     *  - 仅 codebuddy（国服）参与；国际版账号被跳过（成长域接口返回空数据）。
     *  - 单账号内部闭环：补签（有补签卡 + 有可补日期）→ 兑换 available 的连登档位
     *    → 抽奖（有次数）→ 开盲盒（可开）→ 领一次性礼包/活动补偿。
     *  - 账号间按 travel_rate_limit_ms（默认 800ms）限速，避免触发上游风控。
     *  - 上游接口本身幂等（已领返回 duplicate/claimed），轮询频率由调度水位
     *    scheduler_last_streak_at + streak_interval_minutes 控制。
     *
     * @param string $trigger 触发来源：manual|scheduled
     */
    public function streakAll(?string $providerKey = null, string $trigger = 'manual', bool $force = false, ?int $userId = null): array
    {
        $settings = $this->gatewayRepository->current();
        $rateMs   = max(0, (int) ($settings['travel_rate_limit_ms'] ?? 800));

        $accounts = $this->accountsForScope($providerKey, $userId);

        $ok = 0;
        $failed = 0;
        $skipped = 0;
        $empty = 0;
        $details = [];

        foreach ($accounts as $account) {
            if (!$account->enabledByUser) {
                continue;
            }

            if (!in_array($account->providerKey, self::STREAK_PROVIDERS, true)) {
                $skipped++;
                continue;
            }

            // 账号间限速
            if ($rateMs > 0) {
                usleep($rateMs * 1000);
            }

            try {
                $provider = $this->providerManager->get($account->providerKey);
                $result = $provider->action($account, 'streak');
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => $e->getMessage(), 'data' => null];
            }

            $resultOk = (bool) ($result['ok'] ?? false);
            $message  = (string) ($result['message'] ?? '');
            $items    = is_array($result['data']['items'] ?? null) ? $result['data']['items'] : [];

            // 口径同 tasksAll：区分「真的领到东西」与「本次无内容」，避免假成功掩盖故障
            if (!$resultOk) {
                $failed++;
            } elseif ($items === []) {
                $empty++;
            } else {
                $ok++;
            }

            $details[] = [
                'uid'      => $account->uid,
                'provider' => $account->providerKey,
                'ok'       => $resultOk,
                'message'  => $message,
            ];
        }

        return [
            'ok'      => $failed === 0,
            'message' => sprintf('连登管家完成：办理 %d 个，无内容 %d 个，失败 %d 个，跳过 %d 个', $ok, $empty, $failed, $skipped),
            'data'    => $details,
        ];
    }

    /**
     * 尝试刷新账号 Token（快过期时），返回是否有更新
     */
    public function ensureFreshToken(Account $account): Account
    {
        $provider = $this->providerManager->get($account->providerKey);

        try {
            $patch = $provider->refreshToken($account);
        } catch (\Throwable $e) {
            Logger::warn('刷新 Token 异常', [
                'provider' => $account->providerKey,
                'uid'      => $account->uid,
                'error'    => $e->getMessage(),
            ]);
            return $account;
        }

        if (empty($patch)) {
            return $account;
        }

        $this->accountRepository->patchCredential($account, $patch);

        // 同步更新内存中的凭据，避免本次请求重复刷新
        $account->credential = array_merge($account->credential, $patch);

        return $account;
    }

    /**
     * 批量方法共用的账号取数：按作用域取「其供应商可见」的账号。
     *
     * ★ 为什么批量入口也要过滤（2026-10-03）：后台停用某全局供应商后，
     *   账号池列表已对它隐身，但 `refreshAll / checkinAll / travelAll / activityAll /
     *   tasksAll` 仍按 `all()` 全量取数 —— 表现为「列表里 8 个账号，批量刷新却报成功 12 个」
     *   的**数字漂移**，用户无从解释，也等于从统计口径把停用信息漏了出去。
     *
     * 管理员 / CLI / 定时任务（userId = null）→ 不过滤，行为与历史完全一致。
     *
     * ⚠️ 定时任务（Scheduler）传的是 null，会照旧处理全部账号 —— 这是**刻意的**：
     *    调度器负责给所有账号保活/签到，停用只影响「对用户展示与用户可操作性」，
     *    不应打断后台保活，否则一次误停用就会让账号 token 过期、无法恢复。
     *
     * @return Account[]
     */
    private function accountsForScope(?string $providerKey, ?int $userId): array
    {
        $accounts = $this->accountRepository->all($providerKey, $userId);

        if ($userId === null) {
            return $accounts;
        }

        $visible = $this->providerRepository->allVisible($userId);

        return array_values(array_filter(
            $accounts,
            static fn(Account $a): bool => isset($visible[$a->providerKey])
        ));
    }

    /**
     * 账号池概览（含供应商摘要）
     *
     * ⚠️ 必须按作用域过滤供应商（2026-10-03）：`ProviderManager::enabled()` 是
     *    **全量视图**，含被停用的全局供应商与别人自建的实例。本方法被
     *    `/api/user/account-pool/overview` 复用，直接下发会把「后台停用了谁」
     *    和「别人建了什么」一起暴露给普通用户。供应商清单统一走 `allVisible()`。
     */
    public function overview(?int $userId = null): array
    {
        $this->accountRepository->releaseExpiredCooldowns();

        $visible = $this->providerRepository->allVisible($userId);

        $byProvider = [];
        foreach ($this->providerManager->enabled() as $key => $provider) {
            // 只保留当前作用域可见的供应商
            if (!isset($visible[$key])) {
                continue;
            }

            $byProvider[$key] = [
                'provider_key' => $key,
                'display_name' => $provider->displayName(),
                'login_type'   => $provider->loginType(),
                'summary'      => $this->accountRepository->summary($key, $userId),
            ];
        }

        return [
            'total'    => $this->accountRepository->summary(null, $userId),
            'by_provider' => $byProvider,
        ];
    }
}