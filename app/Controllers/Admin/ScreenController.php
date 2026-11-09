<?php
/**
 * 管理台 · 数据大屏
 *
 * 三块大屏共用一个聚合入口，避免前端为大屏单独发十几次请求：
 *   1. 调用量大屏       —— 所有调用（窗口 + 全时段）、按时段/供应商/模型/账号/来源分布
 *   2. 注册登录大屏     —— 注册数、登录数、失败/锁定、来源 IP、最近明细
 *   3. 指标大屏         —— 累计调用 / 成功率 / 平均耗时 / 消耗 Token（含全时段与窗口两套口径）
 *
 * 数据来源：
 *   - 调用类：LogRepository（aigw_call_log）
 *   - 认证类：AuditRepository::authStats()（aigw_audit_log）
 *   - 用户类：UserRepository::statusCounts()（aigw_user）
 *   - 资源类：AccountRepository / ProviderRepository
 *
 * 明细列表**不在这里重复实现** —— 直接复用 /api/admin/logs（它已经带思维正文脱敏，
 * 再写一份必然两边漂移）。本控制器只负责「聚合 + 排行 + 分布」。
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\AuditRepository;
use App\Repositories\LogRepository;
use App\Repositories\ProviderRepository;
use App\Repositories\UserRepository;
use App\Support\Cache;
use App\Support\Tenant;

class ScreenController
{
    /**
     * 大屏结果缓存时长（秒）。
     *
     * 大屏是**趋势与排行**视图，不是实时监控：60 秒的陈旧看不出差别，
     * 但能把「每次打开都要跑 20+ 个聚合、随数据量线性变慢」变成读一个文件。
     * 需要实时数字时带 ?force=1。
     */
    private const SCREEN_CACHE_TTL = 60;

    public function __construct(
        private LogRepository $logRepository,
        private AuditRepository $auditRepository,
        private UserRepository $userRepository,
        private AccountRepository $accountRepository,
        private ProviderRepository $providerRepository,
    ) {}

    /**
     * GET /api/admin/screens?days=7
     *
     * 一次返回三块大屏的全部数据。前端按 tab 选择展示哪块，不做二次请求 ——
     * 大屏就是要「一屏一拉，整屏刷新」。
     */
    public function index(Request $request): Response
    {
        $days = max(1, min(90, (int) $request->query('days', 7)));
        $scope = Tenant::scope();
        $force = filter_var($request->query('force', false), FILTER_VALIDATE_BOOLEAN);

        // ── 结果缓存（60 秒）────────────────────────────────────────────
        // 本接口一次要跑 20+ 个聚合查询；其中 allTime 是**全表**扫描（含 4 个
        // COUNT(DISTINCT)），stats 的 8 个子查询各自扫一遍窗口范围 —— 全部随
        // 数据量线性增长。实测 10 万行约 300ms，1000 万行就是几十秒。
        // 而大屏/趋势/排行榜本来就只关心分钟级新鲜度，故整包缓存：
        // 命中时无论表多大都是「读一个文件 + json_decode」。
        // 需要实时数据时带 ?force=1。
        $cacheKey = 'days=' . $days . ';scope=' . ($scope === null ? 'all' : (string) $scope);

        $cached = $force
            ? null
            : Cache::read('screens', $cacheKey, self::SCREEN_CACHE_TTL);

        if ($cached !== null) {
            $data = $cached['value'];
            $data['cache'] = ['cached' => true, 'age' => $cached['age'], 'ttl' => self::SCREEN_CACHE_TTL];

            return Response::json(['ok' => true, 'data' => $data]);
        }

        $data = $this->build($days, $scope);

        Cache::write('screens', $cacheKey, $data);

        $data['cache'] = ['cached' => false, 'age' => 0, 'ttl' => self::SCREEN_CACHE_TTL];

        return Response::json(['ok' => true, 'data' => $data]);
    }

    /**
     * 组装三块大屏的全部数据（**纯计算**，不含缓存逻辑）。
     *
     * @return array<string,mixed>
     */
    private function build(int $days, ?int $scope): array
    {
        // ── 调用类（窗口 + 全时段两套口径） ────────────────────────
        $stats   = $this->logRepository->stats($days, $scope);
        $allTime = $this->logRepository->allTime($scope);
        $hourly  = $this->logRepository->hourly($days, $scope);
        $buckets = $this->logRepository->durationBuckets($days, $scope);
        $errorTop = $this->logRepository->errorTop($days, 10, $scope);

        // ── 认证类（注册 / 登录） ─────────────────────────────────
        $auth = $this->auditRepository->authStats($days, $scope);

        // ── 用户 / 资源类 ─────────────────────────────────────────
        $userCounts = $this->userRepository->statusCounts();

        $providerNames = [];
        $providers = [];
        foreach ($this->providerRepository->all() as $key => $config) {
            $providerNames[$key] = $config['display_name'];
            $providers[] = [
                'provider_key' => $key,
                'display_name' => $config['display_name'],
                'kind'         => $config['kind'],
                'enabled'      => $config['enabled'],
                'login_type'   => $config['login_type'],
            ];
        }

        // 给调用统计里的 provider_key 补上中文名，前端不用自己维护映射
        foreach ($stats['by_provider'] as $i => $p) {
            $stats['by_provider'][$i]['display_name'] = $providerNames[$p['provider_key']] ?? $p['provider_key'];
        }

        // ── 汇总指标卡（大屏顶部那块） ────────────────────────────
        $metrics = [
            // 累计口径（开站至今）
            'total_calls'             => $allTime['calls'],
            'total_success_calls'     => $allTime['success_calls'],
            'total_failed_calls'      => $allTime['failed_calls'],
            'total_stream_calls'      => $allTime['stream_calls'],
            'total_success_rate'      => $allTime['success_rate'],
            'total_avg_duration'      => $allTime['avg_duration'],
            'total_avg_first_token'   => $allTime['avg_first_token'],
            'total_tokens'            => $allTime['total_tokens'],
            'total_prompt_tokens'     => $allTime['prompt_tokens'],
            'total_completion_tokens' => $allTime['completion_tokens'],
            'total_cache_read_tokens' => $allTime['cache_read_tokens'],
            'total_cache_write_tokens' => $allTime['cache_write_tokens'],
            'total_reasoning_tokens'  => $allTime['reasoning_tokens'],
            'total_cost'              => $allTime['cost'],
            'first_call_at'           => $allTime['first_at'],
            'last_call_at'            => $allTime['last_at'],

            // 窗口口径（最近 N 天）
            'window_calls'            => $stats['overall']['calls'],
            'window_success_rate'     => $stats['overall']['success_rate'],
            'window_avg_duration'     => $stats['overall']['avg_duration'],
            'window_tokens'           => $stats['overall']['total_tokens'],
            'window_cost'             => $stats['overall']['cost'],

            // 用户 / 资源
            'users_total'             => $userCounts['total'],
            'users_active'            => $userCounts['active'],
            'users_disabled'          => $userCounts['disabled'],
            'providers_total'         => count($providers),
            'providers_enabled'       => count(array_filter($providers, fn($p) => !empty($p['enabled']))),
            'active_callers'          => $allTime['users'],
            'active_accounts'         => $allTime['accounts'],
            'active_models'           => $allTime['models'],
        ];

        return [
            'days'    => $days,
            'metrics' => $metrics,

            // 1) 调用量大屏
            'calls' => [
                'overall'     => $stats['overall'],
                'all_time'    => $allTime,
                'by_provider' => $stats['by_provider'],
                'by_model'    => $stats['by_model'],
                'by_account'  => $stats['by_account'],
                'by_source'   => $stats['by_source'],
                'by_user'     => $scope === null ? $this->logRepository->byUser($days, 20) : [],
                'daily'       => $stats['daily'],
                'hourly'      => $hourly,
                'buckets'     => $buckets,
                'top_models'  => $stats['top_models'],
                'error_top'   => $errorTop,
            ],

            // 2) 注册 / 登录大屏
            'auth' => $auth,

            // 3) 指标大屏所需的资源清单
            'providers' => $providers,
        ];
    }
}
