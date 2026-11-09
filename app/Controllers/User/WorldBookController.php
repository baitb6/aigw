<?php
/**
 * 用户端世界书控制器（/api/user/worldbook/*）
 *
 * 参考 Operit 的世界书管理：条目增删改查、启停、导入、批量操作，
 * 以及「测试激活」——给定输入预览哪些条目会被命中并注入。
 *
 * ⚠️ 属主隔离：所有操作经 Tenant::scope()。
 */

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\WorldBookRepository;
use App\Services\WorldBookService;
use App\Support\Tenant;

class WorldBookController
{
    public function __construct(
        private WorldBookRepository $repository,
        private WorldBookService $service,
        private \App\Repositories\ChatSettingRepository $settings,
        private \App\Repositories\PromptTemplateRepository $templates,
        private ?\App\Services\ContextEnhancerService $contextEnhancer = null,
    ) {}

    /** GET /api/user/worldbook */
    public function index(Request $request): Response
    {
        $scope = Tenant::scope();

        return Response::json([
            'ok'   => true,
            'data' => [
                'items' => $this->repository->list($scope),
                'count' => $this->repository->countForUser($scope),
                'limit' => WorldBookRepository::MAX_PER_USER,
            ],
        ]);
    }

    /** POST /api/user/worldbook */
    public function store(Request $request): Response
    {
        $payload = $request->json();
        $name = trim((string) ($payload['name'] ?? ''));
        $content = (string) ($payload['content'] ?? '');

        if ($name === '') {
            return $this->fail('条目名称不能为空');
        }
        if (trim($content) === '') {
            return $this->fail('条目正文不能为空');
        }

        $scope = Tenant::scope();
        if ($this->repository->countForUser($scope) >= WorldBookRepository::MAX_PER_USER) {
            return $this->fail('世界书条目已达上限');
        }

        $data = $this->extract($payload);
        $data['user_id'] = Tenant::writeId();

        $id = $this->repository->create($data);

        return Response::json([
            'ok'   => true,
            'data' => ['entry' => $this->repository->findForUser($id, $scope)],
        ]);
    }

    /** PUT /api/user/worldbook/{id} */
    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $scope = Tenant::scope();

        if ($this->repository->findForUser($id, $scope) === null) {
            return $this->fail('条目不存在', 404);
        }

        $this->repository->update($id, $this->extract($request->json()), Tenant::scope());

        return Response::json([
            'ok'   => true,
            'data' => ['entry' => $this->repository->findForUser($id, $scope)],
        ]);
    }

    /** DELETE /api/user/worldbook/{id} */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');

        if ($this->repository->findForUser($id, Tenant::scope()) === null) {
            return $this->fail('条目不存在', 404);
        }

        $this->repository->delete($id, Tenant::scope());

        return Response::json(['ok' => true, 'message' => '已删除']);
    }

    /** DELETE /api/user/worldbook —— 清空 */
    public function clear(Request $request): Response
    {
        $count = $this->repository->deleteAll(Tenant::scope());

        return Response::json(['ok' => true, 'message' => "已清空 {$count} 条"]);
    }

    /**
     * POST /api/user/worldbook/test —— 用一段输入预览激活结果。
     *
     * 入参：`{input: string, recent: [string, ...]}`
     */
    public function test(Request $request): Response
    {
        $payload = $request->json();
        $input = (string) ($payload['input'] ?? '');
        $recent = [];
        if (is_array($payload['recent'] ?? null)) {
            $recent = array_values(array_map('strval', $payload['recent']));
        }

        $onlyIds = is_array($payload['worldbook_ids'] ?? null)
            ? array_values(array_filter(array_map('intval', $payload['worldbook_ids']), static fn (int $id): bool => $id > 0))
            : [];
        $scope = Tenant::scope();
        $setting = $this->settings->get($scope, trim((string) ($payload['session_key'] ?? '')) ?: 'default');
        $result = !empty($setting['worldbook_enabled'])
            ? $this->service->buildInjection($scope, $input, $recent, $onlyIds)
            : ['activated' => [], 'entries' => [], 'system' => '', 'user' => '', 'assistant' => ''];
        $messages = null;
        if (is_array($payload['messages'] ?? null)) {
            $chat = \App\Provider\Dto\ChatRequest::fromArray([
                'messages' => $payload['messages'],
                'session_key' => $payload['session_key'] ?? 'default',
                'worldbook_ids' => $onlyIds,
            ]);
            $template = $this->templates->effective($scope);
            if ($template !== null) {
                $chat->injectSystem((string) $template['content'], (string) $template['mode']);
            }
            if (!empty($payload['enhance_context'])) {
                if ($this->contextEnhancer === null) {
                    return $this->fail('上下文增强服务不可用', 503);
                }
                $this->contextEnhancer->apply($chat, $scope, !empty($payload['model_is_reasoning']));
            }
            $messages = array_map(static function (array $message): array {
                unset($message['_aigw_source']);
                return $message;
            }, $chat->messages);
        }

        return Response::json([
            'ok'   => true,
            'data' => [
                'messages' => $messages,
                'activated' => $result['activated'],
                'entries'   => $result['entries'],
                'preview'   => [
                    'system'    => $result['system'],
                    'user'      => $result['user'],
                    'assistant' => $result['assistant'],
                ],
            ],
        ]);
    }

    /**
     * POST /api/user/worldbook/import —— 批量导入（Operit / SillyTavern 兼容）。
     *
     * 入参：`{entries: [{name|comment, content, keywords|key, ...}, ...]}`
     * 兼容字段：key(字符串或数组) / constant(常驻) / order(优先级) / disable(禁用) / use_regex
     */
    public function import(Request $request): Response
    {
        $payload = $request->json();
        $entries = $payload['entries'] ?? null;

        if (!is_array($entries) || $entries === []) {
            return $this->fail('entries 必须是非空数组');
        }

        $scope = Tenant::scope();
        $userId = Tenant::writeId();
        $imported = 0;
        $skipped = 0;

        foreach ($entries as $raw) {
            if (!is_array($raw)) {
                $skipped++;
                continue;
            }
            if ($this->repository->countForUser($scope) >= WorldBookRepository::MAX_PER_USER) {
                break;
            }

            $name = trim((string) ($raw['name'] ?? $raw['comment'] ?? ''));
            $content = (string) ($raw['content'] ?? '');
            if ($name === '' || trim($content) === '') {
                $skipped++;
                continue;
            }

            $keywords = $raw['keywords'] ?? $raw['key'] ?? [];
            if (is_array($keywords)) {
                $keywords = implode(',', array_map('strval', $keywords));
            }

            $data = $this->extract([
                'name'            => $name,
                'content'         => $content,
                'keywords'        => (string) $keywords,
                'is_regex'        => !empty($raw['is_regex']) || !empty($raw['use_regex']),
                'case_sensitive'  => !empty($raw['case_sensitive']),
                'always_active'   => !empty($raw['always_active']) || !empty($raw['constant']),
                'enabled'         => array_key_exists('enabled', $raw) ? !empty($raw['enabled']) : empty($raw['disable']),
                'scan_depth'      => $raw['scan_depth'] ?? 4,
                'insertion_depth' => $raw['insertion_depth'] ?? 0,
                'priority'        => $raw['priority'] ?? $raw['order'] ?? 50,
                'inject_target'   => $raw['inject_target'] ?? 'system',
                'inject_position' => $raw['inject_position'] ?? 'append',
                'group_name'      => (string) ($raw['group_name'] ?? ''),
            ]);
            $data['user_id'] = $userId;

            $this->repository->create($data);
            $imported++;
        }

        return Response::json([
            'ok'   => true,
            'data' => ['imported' => $imported, 'skipped' => $skipped],
        ]);
    }

    /** 白名单字段抽取 */
    private function extract(array $payload): array
    {
        $data = [];
        foreach (['name', 'content', 'keywords', 'inject_target', 'inject_position', 'group_name'] as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = (string) $payload[$key];
            }
        }
        foreach (['priority', 'scan_depth', 'insertion_depth'] as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = (int) $payload[$key];
            }
        }
        foreach (['is_regex', 'case_sensitive', 'always_active', 'enabled'] as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = !empty($payload[$key]);
            }
        }

        return $data;
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
