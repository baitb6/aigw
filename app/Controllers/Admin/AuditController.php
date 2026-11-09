<?php
/**
 * 管理台 · 用户行为审计
 *
 * 把「谁在什么时候对什么做了什么、结果如何、从哪来」以列表 / 统计 / CSV 三种方式呈现。
 * 数据来源是 aigw_audit_log（由各控制器在关键动作处写入）。
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\AuditRepository;
use App\Support\Csv;
use App\Support\Cache;
use App\Support\Tenant;

class AuditController
{
    /** 统计缓存时长（秒）——同调用日志页，挡掉「改一次筛选重算一遍」 */
    private const STATS_CACHE_TTL = 30;

    public function __construct(private AuditRepository $auditRepository) {}

    /**
     * GET /api/admin/audit
     * 分页 + 筛选，附「总体统计」与「筛选项字典」。
     */
    public function index(Request $request): Response
    {
        $scope = Tenant::scope();
        $filters = $this->readFilters($request);

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $result = $this->auditRepository->paginate($filters, $page, $perPage, $scope);

        // 归属用户展示名（列表里显示「谁」而不是裸 ID）
        $labels = $this->auditRepository->userLabels(array_column($result['items'], 'user_id'));
        foreach ($result['items'] as $i => $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $result['items'][$i]['user_label'] = $uid > 0
                ? ($labels[$uid] ?? ('用户 #' . $uid))
                : ($row['actor_type'] === 'admin' ? '管理员' : '全局 / 系统');
            $result['items'][$i]['category_label'] = self::categoryLabel((string) ($row['category'] ?? ''));
            $result['items'][$i]['action_label'] = self::actionLabel((string) ($row['action'] ?? ''));
            $result['items'][$i]['actor_label'] = self::actorLabel((string) ($row['actor_type'] ?? ''));
        }

        $days = max(1, min(90, (int) $request->query('days', 7)));

        return Response::json([
            'ok'   => true,
            'data' => array_merge($result, [
                // 走缓存：翻页不该把 5 个窗口聚合重算一遍（这是本页变卡的主因）
                'stats'   => $this->statsCached($days, $scope),
                'options' => $this->auditRepository->actionOptions($scope),
                'days'    => $days,
            ]),
        ]);
    }

    /** GET /api/admin/audit/stats?days=7 */
    public function stats(Request $request): Response
    {
        $days = max(1, min(90, (int) $request->query('days', 7)));
        $scope = Tenant::scope();
        $force = filter_var($request->query('force', false), FILTER_VALIDATE_BOOLEAN);

        return Response::json([
            'ok'   => true,
            'data' => $this->statsCached($days, $scope, $force),
        ]);
    }

    /**
     * 带缓存的行为统计。
     *
     * 为什么单独抽出来：**列表接口内部也在调它**（index() 会把 stats 一起下发），
     * 于是「翻一页」就等于「把 5 个窗口聚合重算一遍」—— 这正是「用户行为」
     * 随流水量变卡的主因。列表与统计共用同一份缓存后，翻页只读缓存。
     *
     * @return array<string,mixed>
     */
    private function statsCached(int $days, ?int $scope, bool $force = false): array
    {
        $cacheKey = 'days=' . $days . ';scope=' . ($scope === null ? 'all' : (string) $scope);

        $hit = $force ? null : Cache::read('audit_stats', $cacheKey, self::STATS_CACHE_TTL);

        if ($hit !== null) {
            return $hit['value'] + ['cache' => ['cached' => true, 'age' => $hit['age']]];
        }

        $data = $this->auditRepository->stats($days, $scope);

        Cache::write('audit_stats', $cacheKey, $data);

        return $data + ['cache' => ['cached' => false, 'age' => 0]];
    }

    /** GET /api/admin/audit/options —— 筛选下拉字典 */
    public function options(Request $request): Response
    {
        return Response::json([
            'ok'   => true,
            'data' => $this->auditRepository->actionOptions(Tenant::scope()),
        ]);
    }

    /**
     * GET /api/admin/audit/timeline?user_id=123&limit=100
     * 某个用户的完整行为时间线（管理台「用户管理 → 行为记录」下钻用）。
     */
    public function timeline(Request $request): Response
    {
        $scope = Tenant::scope();
        $userId = (int) $request->query('user_id', 0);

        // 普通用户只能看自己
        if ($scope !== null) {
            $userId = $scope;
        }
        if ($userId <= 0) {
            return $this->fail('缺少 user_id');
        }

        $limit = (int) $request->query('limit', 100);
        $items = $this->auditRepository->timeline($userId, $limit);

        foreach ($items as $i => $row) {
            $items[$i]['category_label'] = self::categoryLabel((string) ($row['category'] ?? ''));
            $items[$i]['action_label'] = self::actionLabel((string) ($row['action'] ?? ''));
            $items[$i]['actor_label'] = self::actorLabel((string) ($row['actor_type'] ?? ''));
        }

        return Response::json([
            'ok'   => true,
            'data' => ['items' => $items, 'total' => count($items)],
        ]);
    }

    /** GET /api/admin/audit/export —— 按当前筛选导出 CSV（带 BOM） */
    public function export(Request $request): Response
    {
        $filters = $this->readFilters($request);
        $limit = (int) $request->query('limit', 5000);
        $rows = $this->auditRepository->exportRows($filters, $limit, Tenant::scope());

        $labels = $this->auditRepository->userLabels(array_column($rows, 'user_id'));

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, [
            '时间', '归属用户', '操作者', '身份', '分类', '动作', '摘要',
            '对象类型', '对象ID', '结果', 'IP', '方法', '路径', 'User-Agent', '明细',
        ]);

        foreach ($rows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            // CWE-1236：单元格公式注入防护（UA / 路径 / 摘要等字段用户可控）
            fputcsv($fh, Csv::row([
                (string) ($row['created_at'] ?? ''),
                $uid > 0 ? ($labels[$uid] ?? ('用户 #' . $uid)) : '全局 / 系统',
                (string) ($row['actor_name'] ?? ''),
                self::actorLabel((string) ($row['actor_type'] ?? '')),
                self::categoryLabel((string) ($row['category'] ?? '')),
                self::actionLabel((string) ($row['action'] ?? '')),
                (string) ($row['summary'] ?? ''),
                (string) ($row['target_type'] ?? ''),
                (string) ($row['target_id'] ?? ''),
                (int) ($row['status'] ?? 0) >= 400 ? '失败(' . (int) $row['status'] . ')' : '成功',
                (string) ($row['ip'] ?? ''),
                (string) ($row['method'] ?? ''),
                (string) ($row['path'] ?? ''),
                (string) ($row['ua'] ?? ''),
                (string) ($row['detail'] ?? ''),
            ]));
        }

        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        return (new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="audit-logs-' . date('Ymd-His') . '.csv"',
            'Cache-Control'       => 'no-store',
        ]));
    }

    /** POST /api/admin/audit/prune  { days } */
    public function prune(Request $request): Response
    {
        $days = (int) $request->input('days', 90);
        if ($days <= 0) {
            return $this->fail('保留天数必须大于 0');
        }
        $days = min(3650, $days);

        $deleted = $this->auditRepository->purge($days);

        return Response::json([
            'ok'      => true,
            'message' => sprintf('已清理 %d 条超过 %d 天的审计记录', $deleted, $days),
        ]);
    }

    /** 读筛选参数 */
    private function readFilters(Request $request): array
    {
        $status = trim((string) $request->query('status', ''));
        if ($status !== '' && !in_array($status, ['ok', 'failed'], true)) {
            $status = '';
        }

        return [
            'user_id'    => (int) $request->query('user_id', 0),
            'category'   => (string) $request->query('category', ''),
            'action'     => (string) $request->query('action', ''),
            'actor_type' => (string) $request->query('actor_type', ''),
            'ip'         => (string) $request->query('ip', ''),
            'status'     => $status,
            'keyword'    => (string) $request->query('keyword', ''),
            'since'      => (string) $request->query('since', ''),
            'until'      => (string) $request->query('until', ''),
        ];
    }

    private static function categoryLabel(string $category): string
    {
        return match ($category) {
            'auth'     => '登录与账户',
            'persona'  => '思维',
            'template' => '提示词模板',
            'account'  => '账号池',
            'admin'    => '用户管控',
            'gateway'  => '网关设置',
            'model'    => '模型目录',
            'provider' => '供应商',
            'audit'    => '审计',
            default    => $category !== '' ? $category : '其他',
        };
    }

    private static function actionLabel(string $action): string
    {
        return match ($action) {
            'login.success'      => '登录成功',
            'login.fail'         => '登录失败',
            'login.locked'       => '登录被锁定',
            'logout'             => '退出登录',
            'register.success'   => '注册',
            'password.change'    => '修改密码',
            'profile.update'     => '更新资料',
            'apikey.regenerate'  => '重置本人 Key',
            'persona.create'     => '新建思维',
            'persona.update'     => '编辑思维',
            'persona.delete'     => '删除思维',
            'persona.enable'     => '开关思维',
            'persona.platform'   => '平台开关',
            'persona.default'    => '默认思维',
            'persona.target'     => '靶点开关',
            'persona.restore'    => '还原思维',
            'persona.selfcheck'  => '自证自测',
            'template.create'    => '新建模板',
            'template.update'    => '编辑模板',
            'template.delete'    => '删除模板',
            'account.create'     => '添加账号',
            'account.update'     => '修改账号',
            'account.delete'     => '删除账号',
            'account.toggle'     => '启停账号',
            'account.action'     => '账号动作',
            'account.refresh_all'  => '批量刷新',
            'account.checkin_all'  => '批量签到',
            'account.travel_all'   => '批量旅行',
            'account.activity_all' => '批量活跃',
            'account.tasks_all'    => '批量领奖',
            'user.update'        => '管理员改资料',
            'user.toggle'        => '管理员启停用户',
            'user.regenerate_key' => '管理员重置 Key',
            'user.delete'        => '管理员删除用户',
            'settings.update'    => '修改网关设置',
            'settings.regenerate_key' => '重置网关 Key',
            'proxy.update'       => '修改网络代理',
            default              => $action,
        };
    }

    private static function actorLabel(string $actorType): string
    {
        return match ($actorType) {
            'admin'  => '管理员',
            'user'   => '普通用户',
            'guest'  => '未登录访客',
            'system' => '系统',
            default  => $actorType !== '' ? $actorType : '未知',
        };
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
