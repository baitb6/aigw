<?php
/**
 * 管理台 · 账号池
 *  - 列表 / 详情 / 开关 / 删除 / 改备注
 *  - 手工添加凭据（API Key 型）
 *  - 登录流程 start / poll
 *  - 额度刷新（单个 / 批量）、动作调用、连通性自检
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Provider\Dto\Account;
use App\Provider\ErrorCategory;
use App\Provider\ProviderManager;
use App\Repositories\AccountRepository;
use App\Repositories\ActionTaskRepository;
use App\Repositories\ActivityLogRepository;
use App\Repositories\CheckinLogRepository;
use App\Repositories\LogRepository;
use App\Repositories\ProviderRepository;
use App\Repositories\TravelLogRepository;
use App\Repositories\UserRepository;
use App\Services\AccountPool;
use App\Support\Audit;
use App\Support\Logger;
use App\Support\Tenant;

class AccountController
{
    /** 支持成长中心（猫猫旅行 / 成长任务 / 连登 / 盲盒）的供应商：仅 CodeBuddy 国服 */
    private const GROWTH_PROVIDERS = ['codebuddy'];

    public function __construct(
        private AccountRepository $accountRepository,
        private ProviderRepository $providerRepository,
        private ProviderManager $providerManager,
        private AccountPool $accountPool,
        private CheckinLogRepository $checkinLogRepository,
        private TravelLogRepository $travelLogRepository,
        private ActivityLogRepository $activityLogRepository,
        private UserRepository $userRepository,
        private LogRepository $logRepository,
        private ActionTaskRepository $actionTaskRepository,
    ) {}

    /** GET /api/admin/accounts */
    public function index(Request $request): Response
    {
        $this->accountRepository->releaseExpiredCooldowns();

        $providerKey = trim((string) $request->query('provider', ''));
        $keyword = mb_strtolower(trim((string) $request->query('keyword', '')));
        $status = strtoupper(trim((string) $request->query('status', '')));

        $scope = Tenant::scope();
        // 管理员默认查看全部；可用 user_id 下钻查看指定用户的账号（普通用户锁死本人，忽略该参数）
        $isAdmin = Tenant::isAdmin();
        if ($scope === null) {
            $drill = (int) $request->query('user_id', 0);
            $scope = $drill > 0 ? $drill : null;
        }

        $accounts = $this->accountRepository->all($providerKey !== '' ? $providerKey : null, $scope);

        // ★ 停用即隐身：普通用户不得看到「已停用全局供应商」名下的账号。
        //   账号卡上会渲染 provider_name，不过滤等于把被停用的供应商名重新暴露出来。
        //   管理员（含下钻查看某用户）**不过滤** —— 下钻就是为了看全该用户的账号。
        if (!$isAdmin) {
            $accounts = $this->onlyVisibleProviderAccounts($accounts, $scope);
        }

        // 管理员视图附带账号归属，便于「谁添加的账号」一眼可辨
        $owners = $this->ownerNames();

        // 批量取网关侧自计量（一次聚合，避免逐账号查库）
        $meteredMap = $this->logRepository->usageByAccount(
            $providerKey !== '' ? $providerKey : null,
            $scope
        );

        $items = [];
        foreach ($accounts as $account) {
            if ($keyword !== '') {
                $haystack = mb_strtolower($account->uid . ' ' . $account->nickname);
                if (!str_contains($haystack, $keyword)) {
                    continue;
                }
            }

            $state = $this->stateOf($account);
            if ($status !== '' && $status !== $state['code']) {
                continue;
            }

            $row = $this->present($account, $state, $owners);

            // 网关侧自计量（真实消耗口径；上游不给派生 Key 用量时必须用它）
            $m = $meteredMap[$account->providerKey . "\x00" . $account->uid] ?? null;
            $row['metered'] = $m === null ? null : [
                'calls'        => (int) $m['calls'],
                'ok'           => (int) $m['ok'],
                'total_tokens' => (int) $m['total'],
                // 今日消耗（自然日 00:00 起），与上方全时段累计分开
                'today_calls'  => (int) ($m['today_calls'] ?? 0),
                'today_tokens' => (int) ($m['today_total'] ?? 0),
                'last_at'      => (string) $m['last_at'],
            ];

            $items[] = $row;
        }

        return Response::json([
            'ok'   => true,
            'data' => [
                'items'    => $items,
                // ★ 摘要与分组统计必须与 items 用**同一份过滤后的账号集合**，
                //   否则卡片上「账号总数」与列表条数对不上（静默漂移）。
                'summary'  => $this->accountRepository->summarize($accounts),
                'by_provider' => $this->accountRepository->groupByProvider($accounts),
                'total'    => count($items),
            ],
        ]);
    }

    /**
     * 只保留「其供应商在当前作用域可见」的账号。
     *
     * ★ 与 `ProviderRepository::allVisible()` 同源：后台停用某全局供应商后，
     *   `allVisible()` 已对普通用户隐藏它，这里把它名下的账号一并滤掉 ——
     *   否则账号卡渲染的 `provider_name` 会把被停用的供应商名重新暴露出来。
     *
     * 用户自建实例（user_id=本人）不受 enabled 门禁影响，故其账号照常可见、可管理。
     *
     * @param Account[] $accounts
     * @return Account[]
     */
    private function onlyVisibleProviderAccounts(array $accounts, ?int $scope): array
    {
        $visible = $this->providerRepository->allVisible($scope);

        return array_values(array_filter(
            $accounts,
            static fn(Account $a): bool => isset($visible[$a->providerKey])
        ));
    }

    /** GET /api/admin/account-pool/overview */
    public function overview(Request $request): Response
    {
        $scope = Tenant::scope();

        // ⚠️ 本接口同时挂在 /api/user/account-pool/overview 上，必须按作用域取供应商
        $providers = [];
        foreach ($this->providerRepository->allVisible($scope) as $key => $config) {
            $providers[$key] = [
                'provider_key' => $key,
                'display_name' => $config['display_name'],
                'login_type'   => $config['login_type'],
                'enabled'      => $config['enabled'],
                'kind'         => $config['kind'],
                'description'  => $this->loginHint($config['login_type'], $key),
            ];
        }

        // ★ total 必须按**同一份过滤后的供应商集合**重算。
        //   `$overview['total']` 来自 `AccountPool::overview()` → `ProviderManager::enabled()`，
        //   那是**全量视图**（含被停用的全局供应商及其账号数），直接下发会让普通用户从
        //   「账号总数」反推出「后台停用了某个供应商」。前端 `pool.providers` 与 total
        //   也不自洽（列表 3 个供应商、总数却算进 5 个）。
        return Response::json([
            'ok'   => true,
            'data' => [
                'total'     => $this->accountRepository->summarize(
                    $this->onlyVisibleProviderAccounts(
                        $this->accountRepository->all(null, $scope),
                        $scope
                    )
                ),
                'providers' => $providers,
            ],
        ]);
    }

    /**
     * GET /api/admin/accounts/credits/monitor
     *
     * 额度到期 / 续期监控：逐账号标记需要关注的异常，供前端「额度监控」卡片展示。
     * 关注的五类问题：
     *  - EMPTY         ：额度耗尽（冷却原因为「额度耗尽」或余额为 0）
     *  - EXPIRING      ：积分即将/已经到期（默认 7 天内，优先级高，需尽快用掉）
     *  - LOGIN_EXPIRING：登录凭据即将/已经过期（需要续期重新登录）
     *  - LOW           ：额度偏低（已知余额低于阈值）
     *  - STALE         ：额度长期未刷新（超过 stale_hours 未刷新，或从未刷新）
     */
    public function creditMonitor(Request $request): Response
    {
        $this->accountRepository->releaseExpiredCooldowns();

        $providerKey = trim((string) $request->query('provider', ''));
        $lowThreshold = (float) $request->query('low', 50);
        $staleHours = max(1, (int) $request->query('stale_hours', 24));
        $expiringDays = max(1, (int) $request->query('expiring_days', 7));
        $loginExpiringHours = max(1, (int) $request->query('expiring_hours', 72));

        $accounts = $this->accountRepository->all($providerKey !== '' ? $providerKey : null, Tenant::scope());

        // ★ 与账号池列表同源：普通用户不得在额度监控里看到「已停用全局供应商」名下的账号
        if (!Tenant::isAdmin()) {
            $accounts = $this->onlyVisibleProviderAccounts($accounts, Tenant::scope());
        }

        $now = now_ms();

        // 批量取网关侧自计量（一次聚合，避免逐账号查库）
        $meteredMap = $this->logRepository->usageByAccount(
            $providerKey !== '' ? $providerKey : null,
            Tenant::scope()
        );

        $items = [];
        $summary = ['EMPTY' => 0, 'EXPIRING' => 0, 'LOGIN_EXPIRING' => 0, 'LOW' => 0, 'STALE' => 0, 'OK' => 0, 'total' => 0];

        foreach ($accounts as $account) {
            if (!$account->enabledByUser) {
                continue;
            }

            $flags = [];

            $isQuotaCooling = $account->isCooling() && str_contains($account->reason, '额度');
            if ($isQuotaCooling || ($account->creditsKnown && $account->credits !== null && $account->credits <= 0)) {
                $flags[] = 'EMPTY';
            } elseif ($account->creditsKnown && $account->credits !== null && $account->credits < $lowThreshold) {
                $flags[] = 'LOW';
            }

            // 积分到期语义：积分包在 N 天内到期，优先使用，否则到期作废
            if ($account->isExpiringWithin($expiringDays)) {
                $flags[] = 'EXPIRING';
            }

            // 登录凭据（refresh token 等）到期
            $credExpiresAt = (int) $account->cred('expiresAt', 0);
            if ($credExpiresAt > 0 && $credExpiresAt <= $now + $loginExpiringHours * 3600 * 1000) {
                $flags[] = 'LOGIN_EXPIRING';
            }

            $lastRefresh = $account->lastRefreshAt;
            if ($lastRefresh <= 0 || ($now - $lastRefresh) > $staleHours * 3600 * 1000) {
                $flags[] = 'STALE';
            }

            if ($flags === []) {
                $summary['OK']++;
                continue;
            }

            foreach ($flags as $flag) {
                $summary[$flag]++;
            }

            $summary['total']++;

            $packs = (array) $account->meta('packs', []);
            $usage = $account->meta('usage', null);
            $metered = $meteredMap[$account->providerKey . "\x00" . $account->uid] ?? null;

            $items[] = [
                'id'              => $account->id,
                'provider_key'    => $account->providerKey,
                'provider_name'   => $this->providerDisplayName($account->providerKey),
                'account_uid'     => $account->uid,
                'nickname'        => $account->nickname !== '' ? $account->nickname : $account->uid,
                'avatar'          => $account->avatar(),
                'flags'           => $flags,
                'credits'         => $account->credits,
                'credits_known'   => $account->creditsKnown,
                'credits_detail'  => $account->creditsDetail,
                'packs_count'     => count($packs),
                'usage'           => $usage,
                // ⚠️ 与上游 `usage` 严格区分：这是**网关自计量**的真实消耗，
                //    上游不提供派生 Key 用量接口时唯一可信的口径（见 presentMetered 注释）。
                'metered'         => $metered === null ? null : [
                    'calls'        => (int) $metered['calls'],
                    'ok'           => (int) $metered['ok'],
                    'total_tokens' => (int) $metered['total'],
                    'today_calls'  => (int) ($metered['today_calls'] ?? 0),
                    'today_tokens' => (int) ($metered['today_total'] ?? 0),
                    'last_at'      => (string) $metered['last_at'],
                ],
                'reason'          => $account->reason,
                'last_refresh_at' => $lastRefresh,
                'stale_hours'     => $lastRefresh > 0 ? round(($now - $lastRefresh) / 3600000, 1) : null,
                'credits_expire_at'      => $account->creditsExpireAt,
                'credits_expire_in_days' => $account->expireDaysLeft(),
                'cred_expires_at' => $credExpiresAt,
                'cred_expires_in_hours' => $credExpiresAt > 0 ? round(($credExpiresAt - $now) / 3600000, 1) : null,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $rank = ['EMPTY' => 0, 'EXPIRING' => 1, 'LOGIN_EXPIRING' => 2, 'LOW' => 3, 'STALE' => 4];
            $aMin = min(array_map(static fn ($f) => $rank[$f] ?? 9, $a['flags']));
            $bMin = min(array_map(static fn ($f) => $rank[$f] ?? 9, $b['flags']));
            if ($aMin !== $bMin) {
                return $aMin <=> $bMin;
            }
            // 同优先级下，积分到期剩余天数越少越靠前
            $aDays = $a['credits_expire_in_days'] ?? PHP_INT_MAX;
            $bDays = $b['credits_expire_in_days'] ?? PHP_INT_MAX;
            return $aDays <=> $bDays;
        });

        return Response::json([
            'ok'   => true,
            'data' => [
                'items'     => $items,
                'summary'   => $summary,
                'thresholds' => [
                    'low'            => $lowThreshold,
                    'stale_hours'    => $staleHours,
                    'expiring_days'  => $expiringDays,
                    'login_expiring_hours' => $loginExpiringHours,
                ],
            ],
        ]);
    }

    /** GET /api/admin/accounts/{id} */
    public function show(Request $request): Response
    {
        $account = $this->findOrFail($request);
        if ($account instanceof Response) {
            return $account;
        }

        // 网关侧**自计量**真实用量（上游不提供派生 key 用量端点时的唯一真实口径）
        $metered = $this->logRepository->usageOfAccount($account->providerKey, $account->uid);

        return Response::json([
            'ok'   => true,
            'data' => array_merge($this->present($account, $this->stateOf($account), $this->ownerNames()), [
                'credential_preview' => $this->previewCredential($account),
                'meta'               => $account->meta,
                'metered'            => $this->presentMetered($metered, $account),
            ]),
        ]);
    }

    /**
     * 归一化「网关侧自计量」为前端可直接渲染的结构。
     *
     * ⚠️ 必须与上游 `meta.usage` 分开呈现（见 2026-10-01 血案）：
     *   上游那个 `usage` 来自 `billing/balance` 的订阅桶，对 ZCode 这类
     *   「派生 key 走独立计费池」的供应商**恒为满额**（used 恒 0），
     *   把它当真实消耗展示会让用户以为「用了额度但没变化」。
     *   这里给的是**本网关实际转发出去的 token**，是唯一可信的口径。
     */
    private function presentMetered(array $m, Account $account): array
    {
        $total = (int) ($m['total'] ?? 0);

        return [
            'calls'          => (int) ($m['calls'] ?? 0),
            'ok'             => (int) ($m['ok'] ?? 0),
            'failed'         => (int) ($m['failed'] ?? 0),
            'prompt_tokens'  => (int) ($m['prompt'] ?? 0),
            'completion_tokens' => (int) ($m['completion'] ?? 0),
            'total_tokens'   => $total,
            'cache_read_tokens'  => (int) ($m['cache_read'] ?? 0),
            'cache_write_tokens' => (int) ($m['cache_write'] ?? 0),
            'reasoning_tokens'   => (int) ($m['reasoning'] ?? 0),
            // 今日消耗（自然日 00:00 起）
            'today_calls'    => (int) ($m['today_calls'] ?? 0),
            'today_tokens'   => (int) ($m['today_total'] ?? 0),
            'first_at'       => (string) ($m['first_at'] ?? ''),
            'last_at'        => (string) ($m['last_at'] ?? ''),
            'source'         => 'gateway_metered',
            'note'           => $total > 0
                ? '本网关自计量的真实消耗（上游未提供派生 Key 用量接口）'
                : '本网关尚未记录到该账号的 token 消耗',
        ];
    }

    /**
     * POST /api/admin/accounts
     * 手工添加账号：{ provider_key, nickname, base_url?, api_key?, access_token?, credential? }
     */
    public function store(Request $request): Response
    {
        $payload = $request->json();
        $providerKey = trim((string) ($payload['provider_key'] ?? ''));

        if ($providerKey === '' || !$this->providerVisible($providerKey)) {
            return $this->fail('请选择有效的供应商');
        }

        $config = $this->providerRepository->find($providerKey);
        $loginType = (string) ($config['login_type'] ?? 'API_KEY');

        // API_KEY 型走 Provider 自己的落库逻辑，保证凭据结构一致
        if ($loginType === 'API_KEY') {
            $session = $this->providerManager->get($providerKey)->pollLogin(uuid(), [
                'provider_key' => $providerKey,
                'base_url'     => trim((string) ($payload['base_url'] ?? '')),
                'api_key'      => trim((string) ($payload['api_key'] ?? '')),
                'nickname'     => trim((string) ($payload['nickname'] ?? '')),
            ]);

            if ($session->state !== 'success') {
                return $this->fail($session->message ?: '添加失败');
            }

            Audit::ok('account.create', [
                'user_id'     => Tenant::writeId(),
                'target_type' => 'account',
                'target_id'   => (string) $session->accountUid,
                'summary'     => '添加账号（' . $providerKey . '）：' . (string) ($payload['nickname'] ?? $session->accountUid),
                'detail'      => ['provider_key' => $providerKey, 'login_type' => 'API_KEY'],
            ]);

            return Response::json([
                'ok'      => true,
                'message' => '账号已添加',
                'data'    => ['account_uid' => $session->accountUid],
            ]);
        }

        // 其他类型：只接受显式凭据（粘贴 Token）
        $credential = $payload['credential'] ?? null;
        if (!is_array($credential) || empty($credential)) {
            $token = trim((string) ($payload['access_token'] ?? ''));
            if ($token === '') {
                return $this->fail('请提供 access_token 或完整凭据 JSON');
            }
            $credential = ['accessToken' => $token];
        }

        // 交给 Provider 校验并落库：能识别 token 有效性、补齐派生字段、给出可读的失败原因
        try {
            $result = $this->providerManager->get($providerKey)->ingestCredential($credential);
        } catch (\Throwable $e) {
            return $this->fail('保存凭据失败：' . $e->getMessage());
        }

        if (!($result['ok'] ?? false)) {
            return $this->fail((string) ($result['message'] ?? '保存凭据失败'));
        }

        Audit::ok('account.create', [
            'user_id'     => Tenant::writeId(),
            'target_type' => 'account',
            'target_id'   => (string) ($result['account_uid'] ?? ''),
            'summary'     => '添加账号（' . $providerKey . '）：' . (string) ($result['account_uid'] ?? ''),
            'detail'      => ['provider_key' => $providerKey, 'login_type' => $loginType],
        ]);

        return Response::json([
            'ok'      => true,
            'message' => (string) ($result['message'] ?? '账号已保存'),
            'data'    => [
                'id'          => $result['account_id'] ?? null,
                'account_uid' => $result['account_uid'] ?? '',
            ],
        ]);
    }

    /** PUT /api/admin/accounts/{id} */
    public function update(Request $request): Response
    {
        $account = $this->findOrFail($request);
        if ($account instanceof Response) {
            return $account;
        }

        $payload = $request->json();
        $data = [];

        if (array_key_exists('nickname', $payload)) {
            $data['nickname'] = mb_substr(trim((string) $payload['nickname']), 0, 64);
        }
        if (array_key_exists('enabled', $payload)) {
            $this->accountRepository->setEnabled($account->id, !empty($payload['enabled']));
        }

        if (!empty($data)) {
            $this->accountRepository->updateStatus($account->id, $data);
        }

        Audit::ok('account.update', [
            'user_id'     => Tenant::writeId(),
            'target_type' => 'account',
            'target_id'   => (string) $account->uid,
            'summary'     => '修改账号「' . ($account->nickname !== '' ? $account->nickname : $account->uid) . '」',
            'detail'      => ['provider_key' => $account->providerKey, 'fields' => array_keys($data)],
        ]);

        return Response::json(['ok' => true, 'message' => '账号已更新']);
    }

    /** DELETE /api/admin/accounts/{id} */
    public function destroy(Request $request): Response
    {
        $account = $this->findOrFail($request);
        if ($account instanceof Response) {
            return $account;
        }

        $this->accountRepository->delete($account->id);
        Logger::info('账号已删除', ['provider' => $account->providerKey, 'uid' => $account->uid]);

        Audit::ok('account.delete', [
            'user_id'     => Tenant::writeId(),
            'target_type' => 'account',
            'target_id'   => (string) $account->uid,
            'summary'     => '删除账号「' . ($account->nickname !== '' ? $account->nickname : $account->uid) . '」（' . $account->providerKey . '）',
            'detail'      => ['provider_key' => $account->providerKey],
        ]);

        return Response::json(['ok' => true, 'message' => '账号已删除']);
    }

    /** POST /api/admin/accounts/{id}/toggle */
    public function toggle(Request $request): Response
    {
        $account = $this->findOrFail($request);
        if ($account instanceof Response) {
            return $account;
        }

        $enabled = !$account->enabledByUser;
        $this->accountRepository->setEnabled($account->id, $enabled);

        Audit::ok('account.toggle', [
            'user_id'     => Tenant::writeId(),
            'target_type' => 'account',
            'target_id'   => (string) $account->uid,
            'summary'     => ($enabled ? '启用' : '停用') . '账号「' . ($account->nickname !== '' ? $account->nickname : $account->uid) . '」',
            'detail'      => ['provider_key' => $account->providerKey, 'enabled' => $enabled],
        ]);

        return Response::json([
            'ok'      => true,
            'message' => $enabled ? '账号已启用' : '账号已停用',
            'data'    => ['enabled' => $enabled],
        ]);
    }

    /** POST /api/admin/accounts/{id}/cooldown/clear */
    public function clearCooldown(Request $request): Response
    {
        $account = $this->findOrFail($request);
        if ($account instanceof Response) {
            return $account;
        }

        $this->accountRepository->clearCooldown($account->id);
        return Response::json(['ok' => true, 'message' => '冷却已解除']);
    }

    /** POST /api/admin/accounts/{id}/refresh */
    public function refresh(Request $request): Response
    {
        $account = $this->findOrFail($request);
        if ($account instanceof Response) {
            return $account;
        }

        $result = $this->accountPool->refreshCredits($account);

        return Response::json([
            'ok'      => (bool) $result['ok'],
            'message' => (string) $result['message'],
            'data'    => [
                'balance' => $result['balance'] ?? null,
                'known'   => $result['known'] ?? false,
                'packs'   => $result['packs'] ?? [],
            ],
        ], $result['ok'] ? 200 : 400);
    }

    /** POST /api/admin/accounts/refresh-all */
    public function refreshAll(Request $request): Response
    {
        $providerKey = trim((string) $request->input('provider', ''));

        // 指定供应商时必须存在，否则 AccountPool 内部 providerManager->get() 会抛异常；
        // 之前不校验会直接 500，或（账户为空时）返回“刷新完成：成功 0 个”的幻影成功。
        if ($providerKey !== '' && !$this->providerVisible($providerKey)) {
            return $this->fail('供应商不存在', 404);
        }

        try {
            $result = $this->accountPool->refreshAll($providerKey !== '' ? $providerKey : null, Tenant::scope());
        } catch (\Throwable $e) {
            return $this->fail('刷新失败：' . $e->getMessage());
        }

        Audit::ok('account.refresh_all', [
            'user_id'     => Tenant::writeId(),
            'target_type' => 'account_pool',
            'target_id'   => $providerKey,
            'summary'     => '批量刷新额度（' . ($providerKey !== '' ? $providerKey : '全部供应商') . '）：' . (string) $result['message'],
            'detail'      => ['provider' => $providerKey, 'data' => $result['data'] ?? null],
        ]);

        return Response::json([
            'ok'      => (bool) $result['ok'],
            'message' => (string) $result['message'],
            'data'    => $result['data'],
        ]);
    }

    /** POST /api/admin/accounts/checkin-all  一键签到全部（或指定供应商的）账号 */
    public function checkinAll(Request $request): Response
    {
        $providerKey = trim((string) $request->input('provider', ''));

        if ($providerKey !== '' && !$this->providerVisible($providerKey)) {
            return $this->fail('供应商不存在', 404);
        }

        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $result = $this->accountPool->checkinAll($providerKey !== '' ? $providerKey : null, 'manual', $force, Tenant::scope());
        } catch (\Throwable $e) {
            return $this->fail('签到失败：' . $e->getMessage());
        }

        Audit::ok('account.checkin_all', [
            'user_id'     => Tenant::writeId(),
            'target_type' => 'account_pool',
            'target_id'   => $providerKey,
            'summary'     => '批量签到（' . ($providerKey !== '' ? $providerKey : '全部供应商') . '）：' . (string) $result['message'],
            'detail'      => ['provider' => $providerKey, 'force' => $force],
        ]);

        return Response::json([
            'ok'      => (bool) $result['ok'],
            'message' => (string) $result['message'],
            'data'    => $result['data'],
        ]);
    }

    /**
     * POST /api/admin/accounts/travel-all  一键猫猫旅行（全部或指定供应商的账号）
     * 参数：provider（可选）、force（可选，忽略每日幂等/禁用状态）
     */
    public function travelAll(Request $request): Response
    {
        $providerKey = trim((string) $request->input('provider', ''));

        if ($providerKey !== '' && !$this->providerVisible($providerKey)) {
            return $this->fail('供应商不存在', 404);
        }

        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $result = $this->accountPool->travelAll($providerKey !== '' ? $providerKey : null, 'manual', $force, Tenant::scope());
        } catch (\Throwable $e) {
            return $this->fail('猫猫旅行失败：' . $e->getMessage());
        }

        Audit::ok('account.travel_all', [
            'user_id'     => Tenant::writeId(),
            'target_type' => 'account_pool',
            'target_id'   => $providerKey,
            'summary'     => '批量猫猫旅行（' . ($providerKey !== '' ? $providerKey : '全部供应商') . '）：' . (string) $result['message'],
            'detail'      => ['provider' => $providerKey, 'force' => $force],
        ]);

        return Response::json([
            'ok'      => (bool) $result['ok'],
            'message' => (string) $result['message'],
            'data'    => $result['data'],
        ]);
    }

    /**
     * GET /api/admin/accounts/checkin-logs  签到日志（分页倒序）
     * 参数：provider / uid / limit
     */
    public function checkinLogs(Request $request): Response
    {
        $providerKey = trim((string) $request->query('provider', ''));
        $uid = trim((string) $request->query('uid', ''));
        $limit = (int) $request->query('limit', 50);

        return Response::json([
            'ok'   => true,
            'data' => [
                'items'   => $this->checkinLogRepository->recent($limit, $providerKey !== '' ? $providerKey : null, $uid !== '' ? $uid : null, Tenant::scope()),
                'summary' => $this->checkinLogRepository->todaySummary(Tenant::scope()),
            ],
        ]);
    }

    /**
     * GET /api/admin/accounts/travel-logs  猫猫旅行日志（分页倒序）
     * 参数：provider / uid / limit
     */
    public function travelLogs(Request $request): Response
    {
        $providerKey = trim((string) $request->query('provider', ''));
        $uid = trim((string) $request->query('uid', ''));
        $limit = (int) $request->query('limit', 50);

        return Response::json([
            'ok'   => true,
            'data' => [
                'items'   => $this->travelLogRepository->recent($limit, $providerKey !== '' ? $providerKey : null, $uid !== '' ? $uid : null, Tenant::scope()),
                'summary' => $this->travelLogRepository->todaySummary(Tenant::scope()),
            ],
        ]);
    }

    /**
     * POST /api/admin/accounts/activity-all  一键每日活跃注入（WorkBuddy 国际版）
     * 参数：provider（可选）、force（可选，忽略每日幂等）
     */
    public function activityAll(Request $request): Response
    {
        $providerKey = trim((string) $request->input('provider', ''));

        if ($providerKey !== '' && !$this->providerVisible($providerKey)) {
            return $this->fail('供应商不存在', 404);
        }

        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $result = $this->accountPool->activityAll($providerKey !== '' ? $providerKey : null, 'manual', $force, Tenant::scope());
        } catch (\Throwable $e) {
            return $this->fail('每日活跃注入失败：' . $e->getMessage());
        }

        Audit::ok('account.activity_all', [
            'user_id'     => Tenant::writeId(),
            'target_type' => 'account_pool',
            'target_id'   => $providerKey,
            'summary'     => '批量每日活跃注入（' . ($providerKey !== '' ? $providerKey : '全部供应商') . '）：' . (string) $result['message'],
            'detail'      => ['provider' => $providerKey, 'force' => $force],
        ]);

        return Response::json([
            'ok'      => (bool) $result['ok'],
            'message' => (string) $result['message'],
            'data'    => $result['data'],
        ]);
    }

    /**
     * POST /api/admin/accounts/tasks-all  一键成长中心领奖（WorkBuddy 国服：旅行到站领奖 + 成长任务领奖 + 开盲盒）
     * 参数：provider（可选）、force（可选）
     */
    public function tasksAll(Request $request): Response
    {
        $providerKey = trim((string) $request->input('provider', ''));

        if ($providerKey !== '' && !$this->providerVisible($providerKey)) {
            return $this->fail('供应商不存在', 404);
        }

        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $result = $this->accountPool->tasksAll($providerKey !== '' ? $providerKey : null, 'manual', $force, Tenant::scope());
        } catch (\Throwable $e) {
            return $this->fail('成长中心领奖失败：' . $e->getMessage());
        }

        Audit::ok('account.tasks_all', [
            'user_id'     => Tenant::writeId(),
            'target_type' => 'account_pool',
            'target_id'   => $providerKey,
            'summary'     => '批量成长中心领奖（' . ($providerKey !== '' ? $providerKey : '全部供应商') . '）：' . (string) $result['message'],
            'detail'      => ['provider' => $providerKey, 'force' => $force],
        ]);

        return Response::json([
            'ok'      => (bool) $result['ok'],
            'message' => (string) $result['message'],
            'data'    => $result['data'],
        ]);
    }

    /**
     * GET /api/admin/accounts/growth?provider=codebuddy
     *
     * 成长中心「账号清单」——**只读数据库，不碰上游**，因此毫秒级返回。
     *
     * 🔴 为什么把快照从这个接口里拆出去（2026-10-04 性能修复）：
     *   旧版在这里逐个账号拉快照，每个账号要打 7 个上游端点；账号一多就是
     *   `7×N` 次串行请求全压在**一个** HTTP 请求里 → 页面从头到尾只有一个转圈，
     *   10 个账号要等 20 秒以上，且中途没有任何反馈。
     *   现在前端先拿这份清单**立刻渲染账号卡片**，再并发（上限 4）拉各自的快照，
     *   每张卡各自转圈、各自填数据 —— 首屏不再被最慢的账号拖住。
     *
     * 单账号快照见 growthAccount()（GET /accounts/{id}/growth）。
     */
    public function growth(Request $request): Response
    {
        $providerKey = trim((string) $request->query('provider', ''));

        if ($providerKey !== '' && !$this->providerVisible($providerKey)) {
            return $this->fail('供应商不存在', 404);
        }

        $accounts = $this->accountRepository->all($providerKey !== '' ? $providerKey : null, Tenant::scope());

        $items = [];

        foreach ($accounts as $account) {
            if (!$account->enabledByUser) {
                continue;
            }

            if (!in_array($account->providerKey, self::GROWTH_PROVIDERS, true)) {
                continue;
            }

            $items[] = [
                'id'            => $account->id,
                'provider_key'  => $account->providerKey,
                'provider_name' => $this->providerDisplayName($account->providerKey),
                'account_uid'   => $account->uid,
                'nickname'      => $account->nickname !== '' ? $account->nickname : $account->uid,
                'credits'       => $account->credits,
                'credits_known' => $account->creditsKnown,
            ];
        }

        return Response::json([
            'ok'   => true,
            'data' => [
                'items'    => $items,
                'total'    => count($items),
                'readonly' => true,
            ],
        ]);
    }

    /**
     * GET /api/admin/accounts/{id}/growth
     *
     * 单账号成长中心快照：连登 / 补签卡 / 抽奖次数 / 猫档案 / 旅行状态 /
     * 任务清单 / 盲盒可开次数。**只读**，不接取、不领奖、不派猫。
     *
     * 参数：force=1 跳过 45 秒快照缓存，强制回源（页面「刷新」用）。
     */
    public function growthAccount(Request $request): Response
    {
        $account = $this->findOrFail($request);
        if ($account instanceof Response) {
            return $account;
        }

        if (!in_array($account->providerKey, self::GROWTH_PROVIDERS, true)) {
            return $this->fail('该账号的供应商不支持成长中心');
        }

        $force = filter_var($request->query('force', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $provider = $this->providerManager->get($account->providerKey);

            if (!method_exists($provider, 'growthSnapshot')) {
                return $this->fail('该供应商不支持成长中心');
            }

            $snapshot = $provider->growthSnapshot($account, $force);
        } catch (\Throwable $e) {
            return $this->fail('读取成长中心失败：' . $e->getMessage());
        }

        $ok = (bool) ($snapshot['ok'] ?? false);

        return Response::json([
            'ok'      => $ok,
            'message' => (string) ($snapshot['message'] ?? ''),
            'data'    => [
                'id'            => $account->id,
                'provider_key'  => $account->providerKey,
                'provider_name' => $this->providerDisplayName($account->providerKey),
                'account_uid'   => $account->uid,
                'nickname'      => $account->nickname !== '' ? $account->nickname : $account->uid,
                'credits'       => $account->credits,
                'credits_known' => $account->creditsKnown,
                'ok'            => $ok,
                'message'       => (string) ($snapshot['message'] ?? ''),
                'growth'        => $snapshot['data'] ?? null,
            ],
        ], $ok ? 200 : 400);
    }

    /**
     * POST /api/admin/accounts/{id}/growth  单账号成长中心动作
     *
     * body: { action: 'claim_task'|'accept_task'|'travel'|'open_box'|'redeem'|
     *                'lottery_draw'|'use_makeup'|'claim_gift'|'claim_compensation',
     *         params: { task_code?, tier?, target_date?, location? } }
     *
     * 与旧的 `tasks-all` 整池盲批量不同：这里**只对指定账号跑指定的一项**，
     * 用户在页面上点哪一项就执行哪一项，结果原样回显。
     */
    public function growthAction(Request $request): Response
    {
        $account = $this->findOrFail($request);
        if ($account instanceof Response) {
            return $account;
        }

        $action = trim((string) $request->input('action', ''));

        if ($action === '' || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $action)) {
            return $this->fail('action 参数不合法');
        }

        if (!in_array($account->providerKey, self::GROWTH_PROVIDERS, true)) {
            return $this->fail('该账号的供应商不支持成长中心');
        }

        $params = (array) $request->input('params', []);

        // 长耗时动作（代做对话计数型任务要等 90~150s 结算）异步化：
        // 立即回 task_id，真正执行交给 bin/action-worker.php，前端轮询结果。
        if ($this->isLongRunningAction($account->providerKey, $action, $params)) {
            // 成长中心代做是按 task_code 区分的，复用键必须带上它
            return $this->submitAsyncAction($account, $action, $params, $action === 'drive_task');
        }

        try {
            $provider = $this->providerManager->get($account->providerKey);

            if (!method_exists($provider, 'growthAction')) {
                return $this->fail('该供应商不支持成长中心动作');
            }

            $result = $provider->growthAction($account, $action, $params);
        } catch (\Throwable $e) {
            return $this->fail('成长中心动作失败：' . $e->getMessage());
        }

        $ok = (bool) ($result['ok'] ?? false);
        $message = (string) ($result['message'] ?? '');

        Audit::ok('account.growth_action', [
            'user_id'     => Tenant::writeId(),
            'target_type' => 'account',
            'target_id'   => (string) $account->uid,
            'summary'     => '成长中心动作 ' . $action . '（' . ($account->nickname !== '' ? $account->nickname : $account->uid) . '）：' . $message,
            'detail'      => ['provider_key' => $account->providerKey, 'action' => $action, 'params' => $params],
            'status'      => $ok ? 200 : 400,
        ]);

        return Response::json([
            'ok'      => $ok,
            'message' => $message,
            'data'    => $result['data'] ?? null,
        ], $ok ? 200 : 400);
    }

    /**
     * GET /api/admin/accounts/activity-logs  每日活跃注入日志（分页倒序）
     * 参数：provider / uid / limit
     */
    public function activityLogs(Request $request): Response
    {
        $providerKey = trim((string) $request->query('provider', ''));
        $uid = trim((string) $request->query('uid', ''));
        $limit = (int) $request->query('limit', 50);

        return Response::json([
            'ok'   => true,
            'data' => [
                'items'   => $this->activityLogRepository->recent($limit, $providerKey !== '' ? $providerKey : null, $uid !== '' ? $uid : null, Tenant::scope()),
                'summary' => $this->activityLogRepository->todaySummary(Tenant::scope()),
            ],
        ]);
    }

    /**
     * POST /api/admin/accounts/{id}/action  { action: test|checkin|... }
     *
     * ⚠️ 长耗时动作（checkin/claim —— ZCode 领额度要驱动浏览器过无痕验证，9~75s）
     *    会被**异步化**：立即返回 task_id，真正执行交给 bin/action-worker.php。
     *    原因见方法底部注释（CDN 回源超时 524 血案）。
     */
    public function action(Request $request): Response
    {
        $account = $this->findOrFail($request);
        if ($account instanceof Response) {
            return $account;
        }

        $action = trim((string) $request->input('action', ''));
        if ($action === '') {
            return $this->fail('缺少 action 参数');
        }

        // 动作名只允许小写字母/数字/下划线，避免把任意字符串（含路径分隔符、空格）
        // 透传给适配器；具体动作由各适配器自行判定是否支持。
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $action)) {
            return $this->fail('action 参数不合法');
        }

        $params = (array) $request->input('params', []);

        // ── 长耗时动作：异步化 ────────────────────────────────────────────
        // 这些动作要驱动真实浏览器求解验证码，实测 9~75s（最坏含重试 150s）。
        // 线上 CDN（EdgeOne）回源超时**默认仅 15s**，同步执行必然 524。
        // 改为「提交即返回 task_id → 后台 worker 执行 → 前端轮询」。
        if ($this->isLongRunningAction($account->providerKey, $action, $params)) {
            return $this->submitAsyncAction($account, $action, $params, $action === 'drive_task');
        }

        try {
            $provider = $this->providerManager->get($account->providerKey);
            $result = $provider->action($account, $action, $params);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // 领取/签到类动作落库，便于在「签到日志」中追溯是否领取成功
        if ($action === 'checkin' || $action === 'claim') {
            $ok = (bool) ($result['ok'] ?? false);
            $message = (string) ($result['message'] ?? '');
            $this->checkinLogRepository->record([
                'provider_key'   => $account->providerKey,
                'account_uid'    => $account->uid,
                'nickname'       => $account->nickname,
                'status'         => $ok ? (str_contains($message, '已') && str_contains($message, '领') ? 'ALREADY' : 'SUCCESS') : 'FAILED',
                'message'        => $message,
                'credits_before' => $account->creditsKnown ? $account->credits : null,
                'credits_after'  => isset($result['credits']) ? (float) $result['credits'] : null,
                'trigger'        => 'manual',
            ]);

            if ($ok) {
                $this->accountRepository->markCheckedIn($account->id, now_ms());
            }
        }

        Audit::ok('account.action', [
            'user_id'     => Tenant::writeId(),
            'target_type' => 'account',
            'target_id'   => (string) $account->uid,
            'summary'     => '对账号「' . ($account->nickname !== '' ? $account->nickname : $account->uid) . '」执行动作 ' . $action
                . '（' . (($result['ok'] ?? false) ? '成功' : '失败') . '）',
            'detail'      => ['provider_key' => $account->providerKey, 'action' => $action, 'message' => (string) ($result['message'] ?? '')],
            'status'      => ($result['ok'] ?? false) ? 200 : 400,
        ]);

        return Response::json([
            'ok'      => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'data'    => $result['data'] ?? null,
        ], ($result['ok'] ?? false) ? 200 : 400);
    }

    /**
     * 该动作是否需要异步执行（内部走真实浏览器 / 上游长耗时）。
     *
     * 判据刻意写死而非配置化：**只有确认会超时**的动作才该异步，
     * 否则会把本可以秒回的 `test` / `sync_models` 也拖成轮询，白白变慢。
     */
    private function isLongRunningAction(string $providerKey, string $action, array $params = []): bool
    {
        // CodeBuddy 成长中心「代做」：对话计数型要发真实对话并**等上游结算 90~150s**
        // 才能领奖，绝不能挂在 HTTP 请求里（线上 CDN 回源超时默认仅 15s）。
        // automation_1 建完即 completed，秒级完成，同步跑反而少一次轮询。
        if ($action === 'drive_task') {
            $code = (string) ($params['task_code'] ?? '');

            return $providerKey === 'codebuddy'
                && ($code === 'chat_5' || str_starts_with($code, 'Model_chat_'));
        }

        if (!in_array($action, ['checkin', 'claim'], true)) {
            return false;
        }

        // ZCode 领额度：captchaConfig + 无痕验证（驱动 Edge/Chrome）+ claim，
        // 实测单次 9~21s，最坏 75s×2 重试。必须异步。
        return str_starts_with($providerKey, 'zcode');
    }

    /**
     * 异步任务的「复用键」是否与本次请求一致。
     *
     * 目前只比 task_code —— 需要它的只有成长中心代做（一次只代做一个任务）。
     * 其他异步动作（ZCode 领额度）没有区分维度，维持原语义不变。
     */
    private function sameDedupeParams(array $existing, array $params): bool
    {
        $prev = $existing['params'] ?? [];

        if (is_string($prev)) {
            $prev = json_decode($prev, true) ?: [];
        }

        return (string) ($prev['task_code'] ?? '') === (string) ($params['task_code'] ?? '');
    }

    /**
     * 异步提交长耗时动作：建任务 → 拉起后台 worker → 立即返回 task_id。
     *
     * 本方法必须**毫秒级返回**（一次 INSERT + 一次 proc_open），
     * 否则 524 只是被搬到了这里，没被真正解决。
     *
     * @param bool $paramsSensitive 复用键是否要连 params 一起比。
     *        成长中心「代做」必须开：一次只代做**一个** task_code，
     *        若只按 (账号, 动作) 去重，点完 chat_5 再点 Model_chat_X 会复用
     *        前一个任务、拿到前一个任务的结果（张冠李戴）。
     */
    private function submitAsyncAction(Account $account, string $action, array $params, bool $paramsSensitive = false): Response
    {
        // 幂等：30s 内同账号同动作已有活跃/刚完成任务 → 直接复用，避免连点重复烧验证码
        $existing = $this->actionTaskRepository->recentForAccount($account->id, $action, 30);

        if ($existing !== null && (!$paramsSensitive || $this->sameDedupeParams($existing, $params))) {
            return Response::json([
                'ok'      => true,
                'message' => '该操作正在执行中（已复用进行中的任务）',
                'data'    => [
                    'async'    => true,
                    'task_id'  => (string) $existing['task_id'],
                    'status'   => (string) $existing['status'],
                    'reused'   => true,
                ],
            ]);
        }

        $created = $this->actionTaskRepository->create([
            'user_id'      => Tenant::writeId(),
            'provider_key' => $account->providerKey,
            'account_id'   => $account->id,
            'account_uid'  => $account->uid,
            'action'       => $action,
            'params'       => $params,
        ]);

        // ★ 2026-10-02 三修：改用详细版，拿到**结构化失败原因**（code + reason + php + sapi）。
        //   背景：线上出现「CLI 诊断全绿，但 web 点领取仍报『后台任务未能启动』，且
        //   action-worker.log 里连一行痕迹都没有」—— 因为旧版三条提前返回只写 Logger，
        //   不写 worker 日志；且 CLI 与 FPM 是两套环境（disable_functions / open_basedir
        //   只对 FPM 生效）。现在失败原因既写进 worker 日志，也**原样透出给用户**。
        $spawn    = \App\Support\BackgroundProcess::startActionWorkerDetailed($created['task_id']);
        $started  = $spawn['ok'];
        $startInfo = [
            'code'   => (string) ($spawn['code'] ?? ''),
            'reason' => (string) ($spawn['reason'] ?? ''),
            'sapi'   => (string) ($spawn['sapi'] ?? ''),
            'php'    => (string) ($spawn['php'] ?? ''),
            'launcher' => (string) ($spawn['launcher'] ?? ''),
        ];

        // ★ 启动确认（**乐观提示，不是判决**）。
        //
        //   2026-10-02 三修关键修正 —— 原实现是**致命竞态**：
        //     spawn 成功后做 1.2s 轮询，超时就 `$started = false` → 紧接着
        //     `finish(FAILED, WORKER_START_FAILED)` → **把任务判死**。
        //   但 worker 是独立进程，生产上「冷启动 + 首次 DB 连接」可能 >1.2s：
        //     worker 稍慢 → web 把任务标 FAILED → worker 终于起来时
        //     `markRunning()` 要求 status=PENDING（已是 FAILED）→ 抢锁失败 → exit(0)
        //     → **任务永久废掉、从未执行**，用户只看到「未能启动」。本地已复现该竞态。
        //
        //   现在：**spawn 成功（ok=true）就绝不因确认超时而杀任务**。
        //     3s 内看到 RUNNING/DONE/FAILED → 返回 confirmed=true（前端体验更好）；
        //     没看到 → 只记一条提示性日志，**保留 PENDING**，让 worker 自己推进
        //     （worker 端 `claimForRun()` 已能抢回被误杀的、从未执行过的任务）。
        //     真正的兜底交给 `actionTask()` 的双阈值收口（PENDING 60s / RUNNING 300s）。
        $confirmed = false;
        if ($started) {
            $confirmed = $this->confirmWorkerStarted($created['task_id']);
            if (!$confirmed) {
                $startInfo['code']   = 'WORKER_NOT_CONFIRMED';
                $startInfo['reason'] = 'spawn 成功，但 3 秒内尚未进入 RUNNING —— '
                    . 'worker 冷启动较慢或正在启动中；任务保持 PENDING，由后台自行推进'
                    . '（若 60 秒后仍未完成，会按 PENDING_STALE_SECONDS 收口）。'
                    . '若最终失败，请查 storage/logs/action-worker.log。';
                @file_put_contents(
                    \App\Support\BackgroundProcess::workerLogFile(),
                    '[' . date('Y-m-d H:i:s') . '] ⚠ 启动未在 3s 内确认  task=' . $created['task_id']
                        . '  sapi=' . PHP_SAPI . '  code=WORKER_NOT_CONFIRMED'
                        . '（**不是失败**：任务保持 PENDING，不杀任务）' . PHP_EOL,
                    FILE_APPEND | LOCK_EX
                );
            }
        }

        Audit::ok('account.action_async', [
            'user_id'     => Tenant::writeId(),
            'target_type' => 'account',
            'target_id'   => (string) $account->uid,
            'summary'     => '异步提交动作 ' . $action . '（' . $account->providerKey . '）',
            'detail'      => [
                'task_id'        => $created['task_id'],
                'worker_started' => $started,
                'confirmed'      => $confirmed,
            ],
        ]);

        if (!$started) {
            // 只有 **spawn 本身就失败**（proc_open 被禁 / 无 PHP-CLI / 脚本不可达）才判死 ——
            // 这种情况 worker 确实不会来，必须显式告知，绝不能静默返回一个永远不会完成的 task_id。
            $detail = '【' . $startInfo['code'] . '】' . $startInfo['reason']
                . '（sapi=' . $startInfo['sapi'] . ($startInfo['php'] !== '' ? '，php=' . $startInfo['php'] : '') . '）'
                . '｜诊断：bash tools/action-diagnose.php --run，或看 storage/logs/action-worker.log';

            $this->actionTaskRepository->finish(
                $created['task_id'],
                false,
                $detail,
                [],
                'WORKER_START_FAILED'
            );

            return Response::json([
                'ok'      => false,
                'message' => '后台任务未能启动：' . $startInfo['reason'],
                'data'    => [
                    'async'      => true,
                    'task_id'    => $created['task_id'],
                    'status'     => ActionTaskRepository::STATUS_FAILED,
                    'error_code' => 'WORKER_START_FAILED',
                    'detail'     => $detail,
                    'diagnosis'  => $startInfo,
                ],
            ], 500);
        }

        return Response::json([
            'ok'      => true,
            'message' => '任务已提交，正在后台执行（预计 10~60 秒）',
            'data'    => [
                'async'     => true,
                'task_id'   => $created['task_id'],
                'status'    => ActionTaskRepository::STATUS_PENDING,
                'reused'    => false,
                // confirmed=false 只表示「3s 内还没看到 RUNNING」，**不是失败**：
                // 任务仍在 PENDING，worker 会自行推进（前端照常轮询即可）。
                'confirmed' => $confirmed,
            ],
        ]);
    }

    /**
     * 确认 worker 真的接手了任务（状态 PENDING → RUNNING）。
     *
     * 为什么要这么做：`proc_open` 启动成功 ≠ worker 活着。
     * （shell 只保证「进程被 exec 了」，秒死也返回成功。）
     * 这里给 worker 一点启动时间（默认 3.0s，分 15 次 × 200ms 轮询），
     * 只要它完成了抢锁（status → RUNNING 且 worker_pid 已写入），就算确认。
     *
     * ⚠️ 时间预算：本函数最坏 3s，加上 INSERT + proc_open，提交接口总耗时 < 3.5s，
     *    仍远低于 CDN 回源超时（15s），不会把 524 引回来。
     *
     * ★ 2026-10-02 三修：**从 1.2s 放宽到 3s**。
     *   1.2s 对「FPM 冷启动 + 首次 DB 连接」的 worker 来说太紧 —— 生产上
     *   有相当比例的任务在 1.2s 内还没进入 RUNNING，导致**误判**（旧代码据此杀任务）。
     *   既然现在「确认超时不再杀任务」（见 submitAsyncAction 注释），
     *   这里放宽只是让**提示更准**，代价是提交接口多等最多 1.8s，仍远在 15s 预算内。
     *
     * @return bool true = 已确认在跑（或已完成）；false = 还没确认（**不代表失败**）
     */
    private function confirmWorkerStarted(string $taskId): bool
    {
        $deadline = microtime(true) + 3.0;

        while (microtime(true) < $deadline) {
            usleep(200000); // 200ms

            $task = $this->actionTaskRepository->findByTaskId($taskId);
            if ($task === null) {
                return false; // 任务行都没了，异常
            }

            $status = (string) ($task['status'] ?? '');

            // 已进入 RUNNING 且有 pid：worker 确实接手了
            if ($status === ActionTaskRepository::STATUS_RUNNING) {
                return true;
            }
            // 已完成/已失败：判定为「已确认」需 **worker_pid > 0**，
            // 否则可能是别的路径写的 FAILED（如 web 自己收口），不能当确认。
            if (in_array($status, [ActionTaskRepository::STATUS_DONE, ActionTaskRepository::STATUS_FAILED], true)
                && (int) ($task['worker_pid'] ?? 0) > 0) {
                return true;
            }
        }

        return false; // 3s 后仍未确认（**任务保持 PENDING，不杀**）
    }

    /**
     * GET /api/admin/accounts/action-task?task_id=xxx  查询异步任务结果
     *
     * ★ 属主隔离：普通用户只能查自己的任务（Tenant::scope()）；
     *   管理员不限。缺了这一步就是**越权读**——凭 task_id 可看他人领取结果。
     */
    public function actionTask(Request $request): Response
    {
        $taskId = trim((string) $request->query('task_id', ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $taskId)) {
            return $this->fail('task_id 不合法');
        }

        $task = $this->actionTaskRepository->findForUser($taskId, Tenant::scope());
        if ($task === null) {
            return $this->fail('任务不存在', 404);
        }

        $status = (string) $task['status'];
        $done   = in_array($status, [ActionTaskRepository::STATUS_DONE, ActionTaskRepository::STATUS_FAILED], true);

        // 卡死看护：超过存活时长仍未完成 → 标记失败，避免前端无限轮询。
        // ★ 双阈值：PENDING 卡住说明 worker 根本没起来，用短阈值（60s）；
        //   RUNNING 才是「在跑但可能被杀」，用长阈值（300s）。
        //   —— 2026-10-02「卡在正在执行中」故障：旧代码一律 300s，用户干等 5 分钟。
        $startedAt  = (int) ($task['started_at'] ?? 0);
        $createdAt  = (int) (strtotime((string) $task['created_at']) * 1000);
        $reference  = $startedAt > 0 ? $startedAt : $createdAt;

        $limit = ($status === ActionTaskRepository::STATUS_PENDING)
            ? ActionTaskRepository::PENDING_STALE_SECONDS * 1000
            : ActionTaskRepository::STALE_SECONDS * 1000;

        $stale = !$done && (now_ms() - $reference > $limit);

        if ($stale) {
            $isPending = $status === ActionTaskRepository::STATUS_PENDING;
            $this->actionTaskRepository->finish(
                $taskId,
                false,
                $isPending
                    ? '后台任务未能启动（worker 未接管）。请检查 proc_open 是否被禁用、'
                        . 'PHP-CLI 是否可用；详见 storage/logs/action-worker.log。'
                    : '任务超时未完成（后台进程可能已退出），请重试。',
                [],
                $isPending ? 'WORKER_NOT_STARTED' : 'STALE'
            );
            $status = ActionTaskRepository::STATUS_FAILED;
            $done   = true;
            $task['message'] = $isPending
                ? '后台任务未能启动（worker 未接管）。请检查 proc_open 是否被禁用、'
                    . 'PHP-CLI 是否可用；详见 storage/logs/action-worker.log。'
                : '任务超时未完成（后台进程可能已退出），请重试。';
            $task['error_code'] = $isPending ? 'WORKER_NOT_STARTED' : 'STALE';
        }

        return Response::json([
            'ok'      => true,
            'message' => (string) ($task['message'] ?? ''),
            'data'    => [
                'task_id'     => $taskId,
                'status'      => $status,
                'done'        => $done,
                'ok'          => (bool) ($task['ok'] ?? false),
                'message'     => (string) ($task['message'] ?? ''),
                'error_code'  => (string) ($task['error_code'] ?? ''),
                'result'      => $task['result'] ?? [],
                'account_uid' => (string) ($task['account_uid'] ?? ''),
                'action'      => (string) ($task['action'] ?? ''),
                'elapsed_ms'  => $done && $startedAt > 0
                    ? max(0, (int) ($task['finished_at'] ?? 0) - $startedAt)
                    : (now_ms() - $reference),
            ],
        ]);
    }

    /**
     * GET /api/admin/accounts/worker-env  后台任务运行环境自检（web/FPM 视角）
     *
     * ★ 2026-10-02 三修：为什么必须有这个接口 ——
     *   线上出现「root 跑 CLI 诊断 10 项全绿，但浏览器点领取就是『后台任务未能启动』」。
     *   原因是 **CLI 与 PHP-FPM 是两套环境**：
     *     · 宝塔「禁用函数(disable_functions)」**只对 FPM 生效**
     *     · `.user.ini` 的 `open_basedir`（宝塔「防跨站攻击」）**只对 FPM 生效**
     *       → FPM 下 `is_executable('/www/server/php/82/bin/php')`（站点树外）返回 false
     *       → `phpBinary()` 返回空 → worker 起不来。
     *   在 CLI 里跑诊断**永远看不到这两项 FPM 侧限制**。本接口在 **FPM 上下文**里跑同一套
     *   自检，把真实制约当场暴露给管理台。
     */
    public function workerEnvCheck(Request $request): Response
    {
        $check = \App\Support\BackgroundProcess::selfCheck();

        $lines = [];
        foreach ($check['blockers'] as $b) {
            $lines[] = '· ' . $b;
        }
        $msg = $check['ready']
            ? '后台任务环境正常（sapi=' . $check['sapi'] . '，php-cli=' . $check['php_cli'] . '）'
            : "后台任务环境**不可用**（sapi={$check['sapi']}）：\n" . implode("\n", $lines);

        return Response::json([
            'ok'      => true,
            'message' => $msg,
            'data'    => $check,
        ]);
    }

    /**
     * POST /api/admin/accounts/{id}/models
     * 拉取该账号可用模型并可选同步到模型目录
     */
    public function models(Request $request): Response
    {
        $account = $this->findOrFail($request);
        if ($account instanceof Response) {
            return $account;
        }

        try {
            $models = $this->providerManager->get($account->providerKey)->models($account);
        } catch (\Throwable $e) {
            return $this->fail('拉取模型失败：' . $e->getMessage());
        }

        return Response::json([
            'ok'      => true,
            'message' => sprintf('共获取 %d 个模型', count($models)),
            'data'    => ['models' => $models],
        ]);
    }

    // ---------------------------------------------------------
    // 登录流程
    // ---------------------------------------------------------

    /** POST /api/admin/auth/{provider}/start */
    public function startLogin(Request $request): Response
    {
        $providerKey = (string) $request->routeParam('provider', '');
        if (!$this->providerVisible($providerKey)) {
            return $this->fail('供应商不存在', 404);
        }

        try {
            $session = $this->providerManager->get($providerKey)->startLogin((array) $request->json());
        } catch (\Throwable $e) {
            return $this->fail('发起登录失败：' . $e->getMessage());
        }

        return Response::json(['ok' => true, 'data' => $session->toArray()]);
    }

    /** POST /api/admin/auth/{provider}/poll */
    public function pollLogin(Request $request): Response
    {
        $providerKey = (string) $request->routeParam('provider', '');
        if (!$this->providerVisible($providerKey)) {
            return $this->fail('供应商不存在', 404);
        }

        $sessionId = trim((string) $request->input('session_id', ''));

        try {
            $session = $this->providerManager->get($providerKey)
                ->pollLogin($sessionId !== '' ? $sessionId : uuid(), (array) $request->json());
        } catch (\Throwable $e) {
            return $this->fail('轮询登录失败：' . $e->getMessage());
        }

        return Response::json(['ok' => true, 'data' => $session->toArray()]);
    }

    /**
     * POST /api/admin/auth/{provider}/callback
     * 粘贴回调地址兜底：回环回调页（127.0.0.1:51120/authorize 等）在生产环境往往打不开，
     * 用户可把浏览器地址栏里的完整回调 URL 贴回来，这里解析出 query 后交给 Provider::handleCallback。
     */
    public function submitCallback(Request $request): Response
    {
        $providerKey = (string) $request->routeParam('provider', '');
        if (!$this->providerVisible($providerKey)) {
            return $this->fail('供应商不存在', 404);
        }

        $raw = trim((string) $request->input('redirect_url', ''));
        if ($raw === '') {
            $raw = trim((string) $request->input('callback_url', ''));
        }
        if ($raw === '') {
            return $this->fail('请粘贴回调地址');
        }

        $query = $this->parseCallbackUrl($raw);
        if ($query === []) {
            return $this->fail('无法从回调地址中解析出参数，请确认复制的是完整链接');
        }

        // 回环回调可能不带 provider 标识，这里补上，便于 Provider 内部识别来源
        if (!isset($query['provider']) && !isset($query['provider_key'])) {
            $query['provider'] = $providerKey;
        }

        // 管理台发起授权时已知当前会话，显式带上以免回调里的 loginTraceID 命中历史会话
        $sessionId = trim((string) $request->input('session_id', ''));
        if ($sessionId !== '') {
            $query['session_id'] = $sessionId;
        }

        try {
            $result = $this->providerManager->get($providerKey)->handleCallback($query);
        } catch (\Throwable $e) {
            Logger::error('提交回调地址失败', ['provider' => $providerKey, 'error' => $e->getMessage()]);
            return $this->fail('提交回调失败：' . $e->getMessage());
        }

        $ok = (bool) ($result['ok'] ?? false);

        return Response::json([
            'ok'      => $ok,
            'message' => (string) ($result['message'] ?? ($ok ? '授权已完成' : '授权失败')),
            'data'    => $result['data'] ?? null,
        ], $ok ? 200 : 400);
    }

    /**
     * 从用户粘贴的内容里解析回调参数，兼容三种写法：
     *   1) 完整 URL        http://localhost:51121/oauth-callback?code=xxx&state=yyy
     *   2) 带 ? 的路径     /authorize?loginTraceID=xxx&refreshToken=yyy
     *   3) 纯 query 串     code=xxx&state=yyy
     * 同时兼容参数落在 #fragment 上的情形。
     */
    private function parseCallbackUrl(string $raw): array
    {
        $raw = trim($raw, " \t\n\r\"'<>");

        $query = parse_url($raw, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            $hash = strpos($raw, '#');
            if ($hash !== false) {
                $fragment = substr($raw, $hash + 1);
                if (str_contains($fragment, '=')) {
                    $raw = $fragment;
                }
            }
            if (str_starts_with($raw, '?')) {
                $raw = substr($raw, 1);
            }
            $query = $raw;
        }

        $params = [];
        parse_str($query, $params);

        return array_filter($params, static fn ($v) => $v !== '' && $v !== null);
    }

    // ---------------------------------------------------------
    // 内部工具
    // ---------------------------------------------------------

    private function findOrFail(Request $request): Account|Response
    {
        $id = (int) $request->routeParam('id', '0');
        if ($id <= 0) {
            return $this->fail('账号 ID 无效', 400);
        }

        $account = $this->accountRepository->find($id);
        if ($account === null) {
            return $this->fail('账号不存在', 404);
        }

        // 防 IDOR：普通用户只能操作自己的账号；跨属主一律按“不存在”处理，避免暴露他人账号是否存在
        $scope = Tenant::scope();
        if ($scope !== null && $account->userId !== $scope) {
            return $this->fail('账号不存在', 404);
        }

        return $account;
    }

    /** 账号状态归一化（前端着色依据） */
    private function stateOf(Account $account): array
    {
        if (!$account->enabledByUser) {
            return ['code' => 'DISABLED', 'label' => '已停用', 'tone' => 'muted'];
        }
        if ($account->status === 'DISABLED') {
            return ['code' => 'SESSION_DEAD', 'label' => '登录失效', 'tone' => 'danger'];
        }
        if ($account->isCooling()) {
            return [
                'code'    => 'COOLING',
                'label'   => $account->reason !== '' ? $account->reason : '冷却中',
                'tone'    => 'warning',
                'remain'  => $account->coolingRemainSeconds(),
            ];
        }
        if ($account->status === 'ERROR') {
            return ['code' => 'ERROR', 'label' => '错误 ' . $account->errorCount . ' 次', 'tone' => 'warning'];
        }

        return ['code' => 'READY', 'label' => '可用', 'tone' => 'success'];
    }

    private function present(Account $account, array $state, array $owners = []): array
    {
        $row = [
            'id'              => $account->id,
            'provider_key'    => $account->providerKey,
            'provider_name'   => $this->providerDisplayName($account->providerKey),
            'account_uid'     => $account->uid,
            'nickname'        => $account->nickname !== '' ? $account->nickname : $account->uid,
            'avatar'          => $account->avatar(),
            'status'          => $account->status,
            'state'           => $state,
            'reason'          => $account->reason,
            'enabled'         => $account->enabledByUser,
            'user_id'         => $account->userId,
            'credits'         => $account->credits,
            'credits_known'   => $account->creditsKnown,
            'credits_detail'  => $account->creditsDetail,
            'error_count'     => $account->errorCount,
            'last_error'      => $account->lastError,
            'cooling_until'   => $account->coolingUntil,
            'cooling_remain'  => $account->coolingRemainSeconds(),
            'last_refresh_at' => $account->lastRefreshAt,
            'credits_expire_at'      => $account->creditsExpireAt,
            'credits_expire_in_days' => $account->expireDaysLeft(),
            'backing_off'     => $account->isBackingOff(),
            'next_retry_at'   => $account->nextRetryAt,
            'checkin_at'      => $account->checkinAt,
            'checked_in_today' => $account->isCheckedInToday(),
            'meta'            => $account->meta,
            'region'          => $this->regionOf($account),
        ];

        // 归属展示名仅在管理员视图提供（普通用户只见本人账号，无需归属信息）
        if ($owners !== []) {
            $row['owner_name'] = $owners[$account->userId] ?? ('用户 #' . $account->userId);
        }

        return $row;
    }

    /**
     * 账号归属用户映射：user_id → 展示名（仅管理员视图；0 表示管理员/全局）
     *
     * @return array<int,string>
     */
    private function ownerNames(): array
    {
        if (!Tenant::isAdmin()) {
            return [];
        }

        $map = [0 => '管理员 / 全局'];
        foreach ($this->userRepository->all() as $user) {
            $id = (int) ($user['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string) ($user['nickname'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($user['username'] ?? ''));
            }
            if ($name === '') {
                $name = trim((string) ($user['email'] ?? ''));
            }
            $map[$id] = $name !== '' ? $name : ('用户 #' . $id);
        }

        return $map;
    }

    /**
     * 账号所属区域（仅带 domain 的账号，或国际版供应商）
     *
     * domain 含 workbuddy.ai / trae.ai → 国际版，否则视为国服；无 domain 则不展示区域。
     * 与 CodeBuddyProvider::hostForDomain / TraeProvider 的区域判定保持一致。
     *
     * @return array{code:string,label:string,domain:string}|null
     */
    private function regionOf(Account $account): ?array
    {
        $domain = strtolower(trim((string) $account->cred('domain', '')));
        $intlProvider = in_array($account->providerKey, ['codebuddy_intl', 'trae_intl'], true);

        if ($domain === '' && !$intlProvider) {
            return null;
        }

        $global = $intlProvider
            || str_contains($domain, '.workbuddy.ai')
            || $domain === 'workbuddy.ai'
            || str_contains($domain, '.trae.ai')
            || $domain === 'trae.ai';

        return [
            'code'   => $global ? 'global' : 'cn',
            'label'  => $global ? '国际版' : '国服',
            'domain' => $domain,
        ];
    }

    /**
     * 当前作用域下该供应商是否可见（管理员恒 true；普通用户仅全局 + 本人自建）。
     *
     * ⚠️ 本控制器同时挂在 /api/user/* 上：若用 `exists()` 判断，普通用户可以拿
     *    别人自建的 provider_key 当参数用（建号 / 发起登录 / 批量刷新），
     *    报错差异还会变成「供应商是否存在」的探测口。统一走 visible()。
     */
    private function providerVisible(string $key): bool
    {
        return $key !== '' && $this->providerRepository->visible($key, Tenant::scope());
    }

    private function providerDisplayName(string $key): string
    {
        $config = $this->providerRepository->find($key);
        return (string) ($config['display_name'] ?? $key);
    }

    /** 凭据脱敏预览：只展示 key 名与是否已设置 */
    private function previewCredential(Account $account): array
    {
        $out = [];
        foreach ($account->credential as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $string = (string) $value;
            $out[$key] = match (true) {
                $string === '' => '',
                str_contains(mb_strtolower($key), 'token'),
                str_contains(mb_strtolower($key), 'key'),
                str_contains(mb_strtolower($key), 'cookie'),
                str_contains(mb_strtolower($key), 'secret') => mask_secret($string, 6, 4),
                default => mb_substr($string, 0, 120),
            };
        }
        return $out;
    }

    // 豆包与 Trae 同为 WEBVIEW_CALLBACK，但凭据形态不同（网页 Cookie vs accessToken），
    // 仅按 loginType 会给出错误引导，这里先按 provider_key 区分
    private function loginHint(string $loginType, string $providerKey = ''): string
    {
        if ($providerKey === 'doubao') {
            return '粘贴网页版登录 Cookie（至少含 sessionid、msToken），不是 accessToken';
        }

        return match ($loginType) {
            'WEBVIEW_CALLBACK' => '打开授权链接完成登录，回调后自动入池',
            'SMS_CODE'         => '输入手机号获取验证码登录',
            'DEVICE_CODE'      => '浏览器打开授权页确认，支持回填 Token',
            'OAUTH_LOOPBACK'   => 'Google OAuth 授权，回调后自动入池',
            'API_KEY'          => '填写 Base URL 与 API Key，保存即生效',
            default            => '手动填写凭据',
        };
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
