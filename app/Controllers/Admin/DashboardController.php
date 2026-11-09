<?php
/**
 * 管理台 · 概览（仪表盘）
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\GatewayRepository;
use App\Repositories\LogRepository;
use App\Repositories\ModelRepository;
use App\Repositories\ProviderRepository;
use App\Support\Cache;
use App\Support\Tenant;

class DashboardController
{
    public function __construct(
        private AccountRepository $accountRepository,
        private ProviderRepository $providerRepository,
        private ModelRepository $modelRepository,
        private LogRepository $logRepository,
        private GatewayRepository $gatewayRepository,
    ) {}

    /** GET /api/admin/dashboard */
    public function index(Request $request): Response
    {
        $days = (int) $request->query('days', 7);
        $days = max(1, min(90, $days));

        $scope = Tenant::scope();

        // 性能优化：stats() 是 8 条随数据量线性变慢的窗口聚合，仪表盘只需分钟级
        // 新鲜度 → 缓存 60s（与 ScreenController 的数据大屏同一范式）。
        $stats = Cache::remember(
            'dashboard_stats',
            'days=' . $days . ';scope=' . (string) ($scope ?? 0),
            60,
            fn(): array => $this->logRepository->stats($days, $scope)
        )['value'];
        $settings = $this->gatewayRepository->current();

        // ⚠️ 供应商清单必须按作用域取：本接口同时挂在 /api/user/dashboard 上，
        //    用 all() 会把别人自建供应商的名字与 key 一并下发给普通用户（2026-10-02 血案）。
        $visible = $this->providerRepository->allVisible($scope);

        $nameMap = [];
        foreach ($visible as $key => $config) {
            $nameMap[$key] = $config['display_name'];
        }

        // 性能优化（消除 N+1）：旧实现循环内逐供应商 summary()+all() ——
        // 每个供应商一次全表扫描 + 逐行 AES-GCM 解密凭据，P 个供应商 = 2P 次查询。
        // 改为各取一次全量后在 PHP 侧分组，口径不变（仍用 summarize()，键集合不变）。
        $allAccounts = $this->accountRepository->all(null, $scope);
        $accountsByProvider = [];
        foreach ($allAccounts as $account) {
            $accountsByProvider[$account->providerKey][] = $account;
        }
        $allModels = $this->modelRepository->all();
        $modelsByProvider = [];
        foreach ($allModels as $m) {
            $modelsByProvider[$m['provider_key']][] = $m;
        }

        $providers = [];
        foreach ($visible as $key => $config) {
            $summary = $this->accountRepository->summarize($accountsByProvider[$key] ?? []);
            $models = $modelsByProvider[$key] ?? [];
            $rates = [];
            foreach ($models as $m) {
                if ($m['rate'] !== null) {
                    $rates[] = (float) $m['rate'];
                }
            }
            $providers[] = [
                'provider_key'  => $key,
                'display_name'  => $config['display_name'],
                'enabled'       => $config['enabled'],
                'kind'          => $config['kind'],
                'login_type'    => $config['login_type'],
                'default_model' => $config['default_model'],
                'rate_count'    => count($rates),
                'rate_min'      => $rates ? min($rates) : null,
                'rate_max'      => $rates ? max($rates) : null,
                'rate_avg'      => $rates ? round(array_sum($rates) / count($rates), 4) : null,
                'accounts'      => $summary,
                'model_count'   => count($models),
            ];
        }

        // 供应商分布：补齐前端使用的 total / success_rate / display_name
        $byProvider = array_map(function (array $r) use ($nameMap): array {
            $calls = (int) $r['calls'];
            return [
                'provider_key'  => (string) $r['provider_key'],
                'display_name'  => $nameMap[$r['provider_key']] ?? (string) $r['provider_key'],
                'calls'         => $calls,
                'total'         => $calls,
                'success_calls' => (int) $r['success_calls'],
                'success_rate'  => $calls > 0 ? round((int) $r['success_calls'] / $calls * 100, 1) : 0.0,
                'total_tokens'  => (int) $r['total_tokens'],
                'tokens'        => (int) $r['total_tokens'],
                'rate'          => (float) ($r['rate'] ?? 1.0),
                'cost'          => (float) ($r['cost'] ?? 0.0),
                'avg_duration'  => (int) $r['avg_duration'],
            ];
        }, $stats['by_provider']);

        // 热门模型：补齐 total / tokens / rate / cost
        $topModels = array_map(fn(array $r): array => [
            'model'        => (string) $r['model'],
            'provider_key' => (string) $r['provider_key'],
            'calls'        => (int) $r['calls'],
            'total'        => (int) $r['calls'],
            'total_tokens' => (int) $r['total_tokens'],
            'tokens'       => (int) $r['total_tokens'],
            'rate'         => (float) ($r['rate'] ?? 1.0),
            'cost'         => (float) ($r['cost'] ?? 0.0),
        ], $stats['top_models']);

        // 按 Token 排行的模型榜（用量深化）
        $byModel = array_map(fn(array $r): array => [
            'model'             => (string) $r['model'],
            'provider_key'      => (string) $r['provider_key'],
            'calls'             => (int) $r['calls'],
            'prompt_tokens'     => (int) $r['prompt_tokens'],
            'completion_tokens' => (int) $r['completion_tokens'],
            'total_tokens'      => (int) $r['total_tokens'],
            'tokens'            => (int) $r['total_tokens'],
            'rate'              => (float) ($r['rate'] ?? 1.0),
            'cost'              => (float) ($r['cost'] ?? 0.0),
        ], $stats['by_model'] ?? []);

        // 最近调用：补齐 success / stream / status_code
        $recent = array_map(function (array $r): array {
            $r['success'] = strtoupper((string) ($r['status'] ?? '')) === 'SUCCESS';
            $r['stream'] = !empty($r['streaming']);
            $r['status_code'] = (int) ($r['error_code'] ?? 0);
            return $r;
        }, $this->logRepository->recent(8, $scope));

        return Response::json([
            'ok'   => true,
            'data' => [
                'overview' => [
                    // 复用上面已取回的全量集合，不再二次全表扫描+解密
                    'accounts'      => $this->accountRepository->summarize($allAccounts),
                    'providers'     => count($providers),
                    'enabled_providers' => count($this->providerRepository->enabledKeys($scope)),
                    'calls'         => $stats['overall']['calls'],
                    'success_calls' => $stats['overall']['success_calls'],
                    'failed_calls'  => $stats['overall']['failed_calls'],
                    'total_tokens'  => $stats['overall']['total_tokens'],
                    'cost'          => (float) ($stats['overall']['cost'] ?? 0.0),
                    'avg_duration'  => $stats['overall']['avg_duration'],
                    'success_rate'  => $stats['overall']['success_rate'],
                    'models'        => count($allModels),
                ],
                'providers'  => $providers,
                'trend'      => $stats['daily'],
                'by_provider'=> $byProvider,
                'top_models' => $topModels,
                'by_model'   => $byModel,
                'recent'     => $recent,
                'gateway'    => $scope === null ? [
                    'base_url'           => (string) config('gateway.base_url', 'http://ai.com'),
                    'api_key_masked'     => mask_secret((string) $settings['api_key'], 10, 4),
                    'api_key_set'        => (string) $settings['api_key'] !== '',
                    'allow_no_key'       => (bool) $settings['allow_no_key'],
                    'default_provider'   => (string) $settings['default_provider'],
                    'default_model'      => (string) $settings['default_model'],
                    'only_usable_models' => (bool) $settings['only_usable_models'],
                ] : null,
                'days' => $days,
            ],
        ]);
    }
}