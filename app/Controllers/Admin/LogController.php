<?php
/**
 * 管理台 · 调用日志与统计
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\LogRepository;
use App\Repositories\ModelRepository;
use App\Repositories\ProviderRepository;
use App\Repositories\UserRepository;
use App\Support\Csv;
use App\Support\Cache;
use App\Support\PersonaMark;
use App\Support\Tenant;

class LogController
{
    /**
     * 统计缓存时长（秒）。
     *
     * 调用日志页每次翻页/改筛选都会带一次 stats（8 个窗口聚合，随数据量线性变慢）。
     * 30 秒足够「卡片跟手」，又能把这批重算从每次请求降为每分钟一次。
     */
    private const STATS_CACHE_TTL = 30;

    public function __construct(
        private LogRepository $logRepository,
        private ProviderRepository $providerRepository,
        private ModelRepository $modelRepository,
        private UserRepository $userRepository,
    ) {}

    /** GET /api/admin/logs */
    public function index(Request $request): Response
    {
        $filters = $this->filtersFromRequest($request);

        // 用户归属筛选：管理员可传 user_id（精确）或 user（ID/邮箱/昵称关键词）；
        // 普通用户会话被强制锁定本人，请求参数一律忽略。
        $userFilter = $this->resolveUserFilter($request);
        if ($userFilter['user_ids'] !== null) {
            $filters['user_ids'] = $userFilter['user_ids'];
        }

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        $result = $this->logRepository->paginate($filters, $page, $perPage, $userFilter['scope']);

        // 补齐前端需要的派生字段（success / stream / status_code / tokens）
        // 费用按模型倍率换算：cost = tokens × model_rate ÷ 1000（倍率来自模型目录同步采集）
        $rates = $this->modelRepository->rateMap();

        // 查看者决定思维正文的可见性（管理员 = 全文，普通用户 = 仅名称标签）。
        // ⚠️ 判据必须是「查看者是不是管理员」，**不能**用 resolveUserScope() 是否为 null ——
        //    管理员用 ?user_id=N 下钻时 scope 也非 null，拿它当判据会把管理台自己误脱敏。
        // 列表预览也必须过这道闸：request_preview 是正文前 200 字，
        // 而思维注入块恰好就在最前面 —— 不拦就等于照旧泄露。
        $viewerIsAdmin = Tenant::isAdmin();

        // 归属用户信息：一次 IN 查询补全，避免每行回表查用户名
        $userMap = $this->userMapForItems($result['items']);

        // 正文（request_body / response_body）在仓储层已被剔除并换成长度摘要 ——
        // 列表不再携带全文，见 LogRepository::paginate() 的说明。
        // 完整内容（含思维注入正文）由 GET /api/admin/logs/detail?id=N 单条拉取。
        $result['items'] = array_map(function (array $row) use ($rates, $viewerIsAdmin, $userMap): array {
            if (!$viewerIsAdmin && trim((string) ($row['request_preview'] ?? '')) !== '') {
                $row['request_preview'] = $this->redactText(
                    (string) $row['request_preview'],
                    (int) ($row['persona_id'] ?? 0)
                );
            }
            $row = $this->decorateUser($row, $userMap);
            $row['success'] = strtoupper((string) ($row['status'] ?? '')) === 'SUCCESS';
            $row['stream'] = !empty($row['streaming']);
            $row['status_code'] = (int) ($row['error_code'] ?? 0);
            $tokens = (int) ($row['prompt_tokens'] ?? 0) + (int) ($row['completion_tokens'] ?? 0);
            $row['tokens'] = $tokens;
            $rate = $rates[($row['provider_key'] ?? '') . '::' . ($row['model'] ?? '')] ?? 1.0;
            $row['rate'] = $rate;
            $row['cost'] = round($tokens * $rate / 1000, 4);
            return $row;
        }, $result['items']);

        return Response::json([
            'ok'   => true,
            'data' => array_merge($result, [
                // ⚠️ 本接口同时挂在 /api/user/logs 上：普通用户只能看到自己可见的供应商，
                //    否则会把别人自建实例的 key / 名字一起列出来（2026-10-02 血案）。
                'providers' => array_values(array_map(
                    fn($p) => ['provider_key' => $p['provider_key'], 'display_name' => $p['display_name']],
                    $this->providerRepository->allVisible(Tenant::scope())
                )),
                // 按关键词筛到的用户（供前端显示「命中哪几位」，空数组 = 没命中任何人）
                'user_matches' => $userFilter['matches'],
            ]),
        ]);
    }

    /**
     * GET /api/admin/logs 与 /export 共用的筛选条件解析。
     *
     * 前端传 success=1/0，后端仓储按 status 过滤；两者都兼容。
     * 抽成一处：曾经分页与导出各抄一份，改一处忘一处就会「列表和导出的结果对不上」。
     */
    private function filtersFromRequest(Request $request): array
    {
        $status = trim((string) $request->query('status', ''));
        if ($status === '') {
            $success = trim((string) $request->query('success', ''));
            if ($success === '1' || strtolower($success) === 'true') {
                $status = 'SUCCESS';
            } elseif ($success === '0' || strtolower($success) === 'false') {
                $status = 'FAILED';
            }
        }

        return [
            'provider' => (string) $request->query('provider', ''),
            'model'    => (string) $request->query('model', ''),
            'status'   => $status,
            'keyword'  => (string) $request->query('keyword', ''),
            'since'    => (string) $request->query('since', ''),
            'until'    => (string) $request->query('until', ''),
        ];
    }

    /**
     * 解析「按用户筛选」，统一成仓储能用的作用域。
     *
     * 🔴 安全前提：普通用户会话（Tenant::scope() 非 null）**一律锁定本人**，
     * 请求里带的 user / user_id 参数全部忽略 —— 否则这就是一个越权读他人日志的口子。
     *
     * 管理员两种入口：
     *  - user_id=3        → 按 ID 精确（详情页「查看调用日志」下钻、旧链接）
     *  - user=关键词       → ID / 邮箱 / 昵称 / 用户名，解析成 id 集合；
     *                       解析不到人时返回空数组（上层据此筛出空结果，而不是当没筛）
     *
     * @return array{scope:?int, user_ids:?array, matches:array}
     */
    private function resolveUserFilter(Request $request): array
    {
        $scope = Tenant::scope();
        if ($scope !== null) {
            return ['scope' => $scope, 'user_ids' => null, 'matches' => []];
        }

        // ① 精确 ID（保留旧参数，兼容已下钻的链接）
        $rawId = trim((string) $request->query('user_id', ''));
        if ($rawId !== '' && ctype_digit($rawId) && (int) $rawId > 0) {
            $id = (int) $rawId;
            $user = $this->userRepository->find($id);
            return [
                'scope'     => null,
                'user_ids'  => [$id],
                'matches'   => $user === null ? [] : [$this->userBrief($user)],
            ];
        }

        // ② 关键词（ID / 邮箱 / 昵称 / 用户名）
        $keyword = trim((string) $request->query('user', ''));
        if ($keyword === '') {
            return ['scope' => null, 'user_ids' => null, 'matches' => []];
        }

        $ids = $this->userRepository->searchIds($keyword, 500);
        $matches = [];
        foreach ($this->userRepository->mapByIds($ids) as $row) {
            $matches[] = $this->userBrief($row);
        }

        return ['scope' => null, 'user_ids' => $ids, 'matches' => $matches];
    }

    /** 结果集里出现过的 user_id → 批量取用户（一次 IN 查询） */
    private function userMapForItems(array $items): array
    {
        $ids = [];
        foreach ($items as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid > 0) {
                $ids[] = $uid;
            }
        }

        return $this->userRepository->mapByIds($ids);
    }

    /** 用户精简信息（列表/导出展示用，绝不带 password / api_key） */
    private function userBrief(array $user): array
    {
        $id = (int) ($user['id'] ?? 0);
        $nickname = (string) ($user['nickname'] ?? '');
        $email = (string) ($user['email'] ?? '');
        $username = (string) ($user['username'] ?? '');

        $label = $nickname !== '' ? $nickname : ($email !== '' ? $email : ($username !== '' ? $username : ('用户 #' . $id)));

        return [
            'id'       => $id,
            'nickname' => $nickname,
            'email'    => $email,
            'username' => $username,
            'label'    => $label,
        ];
    }

    /**
     * 给日志行补「归属用户」字段。
     *
     * user_id = 0 是**全局密钥**（管理员自己的 Key）产生的调用，不是缺失数据，
     * 所以在 UI 上要显示为「全局」，不能显示成空白 — 否则会被误读成「这条没有归属」。
     */
    private function decorateUser(array $row, array $userMap): array
    {
        $uid = (int) ($row['user_id'] ?? 0);

        if ($uid <= 0) {
            $row['user'] = null;
            $row['user_label'] = '全局（管理员密钥）';
            return $row;
        }

        $user = $userMap[$uid] ?? null;
        $brief = $user === null ? null : $this->userBrief($user);
        $row['user'] = $brief;
        $row['user_label'] = $brief === null
            ? ('用户 #' . $uid . '（已注销）')
            : ($brief['label'] . ' #' . $uid);

        return $row;
    }

    /**
     * GET /api/admin/logs/detail?id=N
     *
     * 单条调用日志的完整详情 —— 管理员看**完整正文**（含思维注入全文，不做截断）；
     * 普通用户（含 /api/user/logs/detail 复用本方法）只看到**思维名称标签**，
     * 注入块正文不下发 —— 那属于操作者的内部配置，普通用户不该看到。
     */
    public function detail(Request $request): Response
    {
        $id = (int) $request->query('id', 0);
        if ($id <= 0) {
            return $this->fail('缺少日志 id');
        }

        $row = $this->logRepository->findDetail($id, $this->resolveUserScope($request));
        if ($row === null) {
            return $this->fail('日志不存在或无权查看', 404);
        }

        // 查看者脱敏：判据是「查看者是不是管理员」，与管理台的下钻参数无关
        // （管理员带 ?user_id=N 时作用域同样收窄，但仍应看到全文）。
        if (!Tenant::isAdmin()) {
            foreach (['request_body', 'response_body'] as $key) {
                if ((string) ($row[$key] ?? '') !== '') {
                    $row[$key] = $this->redactText((string) $row[$key], (int) ($row['persona_id'] ?? 0));
                }
            }
            $row['persona_redacted'] = true;
        } else {
            $row['persona_redacted'] = false;
        }

        // 派生字段与列表保持一致，前端详情弹窗可直接复用同一套渲染
        $rates = $this->modelRepository->rateMap();
        $row['success'] = strtoupper((string) ($row['status'] ?? '')) === 'SUCCESS';
        $row['stream'] = !empty($row['streaming']);
        $row['status_code'] = (int) ($row['error_code'] ?? 0);
        $tokens = (int) ($row['prompt_tokens'] ?? 0) + (int) ($row['completion_tokens'] ?? 0);
        $row['tokens'] = $tokens;
        $rate = $rates[($row['provider_key'] ?? '') . '::' . ($row['model'] ?? '')] ?? 1.0;
        $row['rate'] = $rate;
        $row['cost'] = round($tokens * $rate / 1000, 4);

        // 思维归属标签仍给出来（哪一份思维），但正文按查看者角色决定是否保留
        $row['persona_label'] = (int) ($row['persona_id'] ?? 0) > 0
            ? $this->logRepository->personaLabel((int) $row['persona_id'])
            : '';

        // 调用归属用户（「这条是谁发的」），详情里也一并给出
        $row = $this->decorateUser($row, $this->userMapForItems([$row]));

        return Response::json(['ok' => true, 'data' => $row]);
    }

    /**
     * 把留痕文本里的思维注入块正文换成名称标签（锚点成对保留）。
     *
     * 为什么在**读取侧**做：正文本来就完整落库（管理员要看全文），
     * 不能为了普通用户而在写入侧砍掉。谁看、看到多少，属展示层职责。
     *
     * 锚点保留的意义：普通用户仍能一眼看出「这次到底注没注入」——
     * 只是看不到正文。标签自带「是哪一份」，如【系统内置色情写作】。
     */
    private function redactText(string $text, int $personaId): string
    {
        if ($text === '' || !PersonaMark::hasBlock($text)) {
            return $text;
        }

        // 复用仓库的标签解析（带进程内缓存），拿不到名字时它自己退化成【思维 #id】
        $label = $this->logRepository->personaLabel($personaId);

        // 半块说明必须用 HIDDEN_NOTE：正文其实完整在库里，只是对当前查看者隐藏，
        // 说成「超出留痕上限」是事实错误（那是截断场景的措辞）。
        return PersonaMark::redact($text, $label, PersonaMark::HIDDEN_NOTE);
    }

    /**
     * GET /api/admin/logs/export
     * 按当前筛选条件导出为 CSV（带 BOM，Excel 可直接识别中文）
     */
    public function export(Request $request): Response
    {
        $filters = $this->filtersFromRequest($request);

        // 导出必须与列表用**同一套**用户筛选，否则「列表筛出来的」和「导出的」不是一批数据
        $userFilter = $this->resolveUserFilter($request);
        if ($userFilter['user_ids'] !== null) {
            $filters['user_ids'] = $userFilter['user_ids'];
        }

        $limit = (int) $request->query('limit', 5000);
        $rows  = $this->logRepository->exportRows($filters, $limit, $userFilter['scope']);
        $rates = $this->modelRepository->rateMap();
        $userMap = $this->userMapForItems($rows);

        $fh = fopen('php://temp', 'r+');
        // UTF-8 BOM
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, [
            '时间', '用户', '用户ID', '供应商', '模型', '结果', '状态码', '耗时(ms)', '首字耗时(ms)',
            '输入Token', '输出Token', '总Token', '倍率', '费用', '流式',
            '账号', '请求ID', '来源IP', '错误',
        ]);

        foreach ($rows as $row) {
            $tokens = (int) ($row['prompt_tokens'] ?? 0) + (int) ($row['completion_tokens'] ?? 0);
            $rate   = $rates[($row['provider_key'] ?? '') . '::' . ($row['model'] ?? '')] ?? 1.0;
            $cost   = round($tokens * $rate / 1000, 4);
            $ok     = strtoupper((string) ($row['status'] ?? '')) === 'SUCCESS';
            $account = (string) ($row['account_nickname'] ?? '');
            if ($account === '') {
                $account = (string) ($row['account_uid'] ?? '');
            }

            $row  = $this->decorateUser($row, $userMap);
            $uid  = (int) ($row['user_id'] ?? 0);
            $userLabel = $uid > 0
                ? (string) ($row['user_label'] ?? ('用户 #' . $uid))
                : '全局（管理员密钥）';

            // CWE-1236：单元格公式注入防护（模型名 / UA / 错误信息等字段可被诱导）
            fputcsv($fh, Csv::row([
                (string) ($row['created_at'] ?? ''),
                $userLabel,
                $uid > 0 ? (string) $uid : '0',
                (string) ($row['provider_key'] ?? ''),
                (string) ($row['model'] ?? ''),
                $ok ? '成功' : '失败',
                (string) ($row['error_code'] ?? ''),
                (string) ($row['duration_ms'] ?? ''),
                (string) ($row['first_token_ms'] ?? ''),
                (string) ($row['prompt_tokens'] ?? 0),
                (string) ($row['completion_tokens'] ?? 0),
                (string) $tokens,
                (string) $rate,
                (string) $cost,
                !empty($row['streaming']) ? 'SSE' : '同步',
                $account,
                (string) ($row['request_id'] ?? ''),
                (string) ($row['client_ip'] ?? ''),
                (string) ($row['error'] ?? ''),
            ]));
        }

        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        $filename = 'call-logs-' . date('Ymd-His') . '.csv';

        return (new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store',
        ]));
    }

    /** GET /api/admin/stats?days=7 */
    public function stats(Request $request): Response
    {
        $days = max(1, min(90, (int) $request->query('days', 7)));
        $force = filter_var($request->query('force', false), FILTER_VALIDATE_BOOLEAN);

        // 统计跟着用户筛选一起收窄：否则「列表只剩这个用户的 5 条」而上面的卡片
        // 还是全站总量，一眼看过去就是自相矛盾的数据。
        $userFilter = $this->resolveUserFilter($request);

        // 缓存理由与大屏同：这一组是 8 个窗口聚合查询，随数据量线性变慢，
        // 而调用日志页每次「翻页/改筛选」都会带一次 —— 属于典型的重复重算。
        $cacheKey = 'days=' . $days
            . ';scope=' . ($userFilter['scope'] === null ? 'all' : (string) $userFilter['scope'])
            . ';ids=' . (is_array($userFilter['user_ids']) ? implode(',', $userFilter['user_ids']) : '-');

        $hit = $force ? null : Cache::read('log_stats', $cacheKey, self::STATS_CACHE_TTL);

        if ($hit !== null) {
            return Response::json([
                'ok'   => true,
                'data' => $hit['value'] + ['cache' => ['cached' => true, 'age' => $hit['age']]],
            ]);
        }

        $data = $this->logRepository->stats($days, $userFilter['scope'], $userFilter['user_ids']);

        Cache::write('log_stats', $cacheKey, $data);

        return Response::json([
            'ok'   => true,
            'data' => $data + ['cache' => ['cached' => false, 'age' => 0]],
        ]);
    }

    /** POST /api/admin/logs/prune */
    public function prune(Request $request): Response
    {
        // 夹取到 1~3650 天，避免 days<=0 时仓储静默不删却回报“已清理 0 条”的幻影成功。
        $days = (int) $request->input('days', 30);
        if ($days <= 0) {
            return $this->fail('保留天数必须大于 0');
        }
        $days = min(3650, $days);

        $deleted = $this->logRepository->prune($days);

        return Response::json([
            'ok'      => true,
            'message' => sprintf('已清理 %d 条超过 %d 天的日志', $deleted, $days),
        ]);
    }

    /**
     * 单条日志详情的数据归属范围：普通用户锁定本人；管理员默认全部，
     * 可用 user_id 下钻查看指定用户的某条日志。
     *
     * 列表/导出/统计的用户筛选走 resolveUserFilter()（支持关键词、多命中），
     * 详情是「按 id 取一条」，只需要单值作用域，所以保留这个精简版本。
     */
    private function resolveUserScope(Request $request): ?int
    {
        $scope = Tenant::scope();
        if ($scope !== null) {
            return $scope;
        }

        $userId = (int) $request->query('user_id', 0);
        return $userId > 0 ? $userId : null;
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }

    /**
     * 内容预览处理 —— **已废弃，保留为恒等函数**。
     *
     * 历史上这里做过 4000 字硬截断，后来放开为原样返回；再后来正文整体
     * 从列表接口移出（见 LogRepository::paginate），列表只给长度摘要与
     * 200 字预览。完整正文走 GET /api/admin/logs/detail。
     */
    private static function clipPreview(string $text): string
    {
        return $text;
    }

    /** POST /api/admin/logs/clear */
    public function clear(Request $request): Response
    {
        $deleted = $this->logRepository->clearAll();

        return Response::json([
            'ok'      => true,
            'message' => sprintf('已清空 %d 条日志', $deleted),
        ]);
    }
}
