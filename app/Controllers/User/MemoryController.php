<?php
/**
 * 用户端记忆控制器（/api/user/memory/*）
 *
 * 参考 Operit 的记忆库：列出 / 新增 / 修改 / 删除 / 检索 / 清空，
 * 以及供对话注入的「召回」接口。
 *
 * 属主隔离：全部经 Tenant::scope()；写入用 Tenant::writeId()。
 */

declare(strict_types=1);

namespace App\Controllers\User;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\MemoryRepository;
use App\Services\MemoryService;
use App\Support\Tenant;

class MemoryController
{
    public function __construct(
        private MemoryRepository $repository,
        private MemoryService $service,
    ) {}

    /** GET /api/user/memory —— 列表（支持 ?q= 检索） */
    public function index(Request $request): Response
    {
        $this->repository->ensureSchema();
        $query = trim((string) $request->query('q', ''));
        $limit = max(1, min(100, (int) $request->query('limit', 100)));
        $offset = max(0, (int) $request->query('offset', 0));
        $items = $this->repository->list(Tenant::scope(), '', MemoryRepository::MAX_PER_USER);
        if ($query !== '') {
            $items = $this->repository->rank($items, $query, count($items));
        }
        $hasMore = $offset + $limit < count($items);

        return Response::json([
            'ok'   => true,
            'data' => [
                'items' => array_slice($items, $offset, $limit),
                'has_more' => $hasMore,
                'offset' => $offset,
                'page_size' => $limit,
                'total' => $this->repository->countForUser(Tenant::scope()),
                'limit' => MemoryRepository::MAX_PER_USER,
            ],
        ]);
    }

    /** POST /api/user/memory —— 新增/覆盖 */
    public function store(Request $request): Response
    {
        $payload = $request->json();
        $title = trim((string) ($payload['title'] ?? ''));
        $content = trim((string) ($payload['content'] ?? ''));

        if ($title === '') {
            return $this->fail('标题不能为空');
        }
        if ($content === '') {
            return $this->fail('内容不能为空');
        }

        $tags = $this->normalizeTags($payload['tags'] ?? []);
        $importance = isset($payload['importance']) ? (float) $payload['importance'] : 1.0;

        $memory = $this->service->save(
            Tenant::scope(),
            mb_substr($title, 0, 255),
            $content,
            $tags,
            $importance,
        );

        return Response::json(['ok' => true, 'data' => ['memory' => $memory]]);
    }

    /** PUT /api/user/memory/{id} */
    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        $scope = Tenant::scope();
        $memory = $this->repository->findForUser($id, $scope);

        if ($memory === null) {
            return $this->fail('记忆不存在', 404);
        }

        $payload = $request->json();
        $data = [];
        if (array_key_exists('title', $payload)) {
            $data['title'] = mb_substr(trim((string) $payload['title']), 0, 255);
        }
        if (array_key_exists('content', $payload)) {
            $data['content'] = (string) $payload['content'];
        }
        if (array_key_exists('tags', $payload)) {
            $data['tags'] = implode(',', $this->normalizeTags($payload['tags']));
        }
        if (array_key_exists('importance', $payload)) {
            $data['importance'] = (float) $payload['importance'];
        }

        if ($data === []) {
            return $this->fail('没有可更新的字段');
        }

        // 正文/标题变化时重算向量
        if (isset($data['title']) || isset($data['content'])) {
            $vector = $this->service->embed(
                ($data['title'] ?? $memory['title']) . "\n" . ($data['content'] ?? $memory['content'])
            );
            $data['embedding'] = $vector;
            $data['embed_model'] = $vector === [] ? '' : (string) env('MEMORY_EMBEDDING_MODEL', '');
        }

        $this->repository->update($id, $data, Tenant::scope());

        return Response::json([
            'ok'   => true,
            'data' => ['memory' => $this->repository->findForUser($id, $scope)],
        ]);
    }

    /** DELETE /api/user/memory/{id} */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->routeParam('id', '0');
        if ($this->repository->findForUser($id, Tenant::scope()) === null) {
            return $this->fail('记忆不存在', 404);
        }

        $this->repository->delete($id, Tenant::scope());

        return Response::json(['ok' => true, 'message' => '已删除']);
    }

    /** POST /api/user/memory/recall —— 混合召回（供对话注入） */
    public function recall(Request $request): Response
    {
        $payload = $request->json();
        $query = trim((string) ($payload['query'] ?? ''));
        $topK = isset($payload['top_k']) ? max(1, min(20, (int) $payload['top_k'])) : 5;

        if ($query === '') {
            return $this->fail('query 不能为空');
        }

        return Response::json([
            'ok'   => true,
            'data' => [
                'items'   => $this->service->recall(Tenant::scope(), $query, $topK),
                'context' => $this->service->toContextPrompt(Tenant::scope(), $query, $topK),
            ],
        ]);
    }

    /** POST /api/user/memory/clear —— 清空本人记忆 */
    public function clear(Request $request): Response
    {
        $deleted = $this->repository->deleteAll(Tenant::scope());

        return Response::json(['ok' => true, 'message' => "已清空 {$deleted} 条记忆"]);
    }

    private function normalizeTags($tags): array
    {
        if (is_string($tags)) {
            $tags = preg_split('/[,，]/', $tags) ?: [];
        }
        if (!is_array($tags)) {
            return [];
        }

        $out = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $out[] = mb_substr($tag, 0, 64);
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 12);
    }

    private function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => ['message' => $message]], $status);
    }
}
