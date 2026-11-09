<?php
/**
 * 提示词模板管理
 *  - 管理台：全局模板（user_id=0）全量 CRUD
 *  - 用户端：仅本人自建模板（user_id>0）CRUD，且可查看只读的全局模板
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Middleware\UserAuthMiddleware;
use App\Repositories\PromptTemplateRepository;
use App\Support\Audit;
use App\Support\Tenant;

class PromptTemplateController
{
    private const MODES = ['prepend', 'append', 'replace'];

    public function __construct(private PromptTemplateRepository $promptTemplateRepository) {}

    // ---------------------------------------------------------
    // 管理台：全局模板
    // ---------------------------------------------------------

    /** GET /api/admin/prompt-templates —— 全局模板列表 */
    public function index(Request $request): Response
    {
        return Response::json([
            'ok'   => true,
            'data' => [
                'items' => array_values(array_filter(
                    $this->promptTemplateRepository->all(0),
                    fn($t) => (int) $t['user_id'] === 0
                )),
                'modes' => $this->modeOptions(),
            ],
        ]);
    }

    /** POST /api/admin/prompt-templates */
    public function store(Request $request): Response
    {
        $payload = $request->json();

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return Response::json(['ok' => false, 'error' => ['message' => '模板名称不能为空']], 400);
        }

        $mode = trim((string) ($payload['mode'] ?? 'prepend'));
        if (!in_array($mode, self::MODES, true)) {
            return Response::json(['ok' => false, 'error' => ['message' => '未知的注入位置：' . $mode]], 400);
        }

        $id = $this->promptTemplateRepository->create([
            'user_id'     => 0,
            'name'        => $name,
            'description' => (string) ($payload['description'] ?? ''),
            'content'     => (string) ($payload['content'] ?? ''),
            'mode'        => $mode,
            'enabled'     => array_key_exists('enabled', $payload) ? !empty($payload['enabled']) : true,
            'is_default'  => !empty($payload['is_default']),
            'sort_order'  => (int) ($payload['sort_order'] ?? 0),
        ]);

        Audit::ok('template.create', [
            'target_type' => 'template',
            'target_id'   => (string) $id,
            'summary'     => '新建全局提示词模板「' . $name . '」',
            'detail'      => ['mode' => $mode],
        ]);

        return Response::json(['ok' => true, 'message' => '模板已创建', 'data' => ['id' => $id]]);
    }

    /** PUT /api/admin/prompt-templates/{id} */
    public function update(Request $request): Response
    {
        $templateId = (int) $request->routeParam('id', '0');
        $current = $this->promptTemplateRepository->find($templateId);
        if ($current === null || (int) $current['user_id'] !== 0) {
            return Response::json(['ok' => false, 'error' => ['message' => '模板不存在']], 404);
        }

        $payload = $request->json();
        $data = $this->extractUpdatable($payload, $unsupported);
        if ($unsupported !== null) {
            return Response::json(['ok' => false, 'error' => ['message' => $unsupported]], 400);
        }
        if (empty($data)) {
            return Response::json(['ok' => false, 'error' => ['message' => '没有需要更新的字段']], 400);
        }

        $this->promptTemplateRepository->update($templateId, $data);

        Audit::ok('template.update', [
            'target_type' => 'template',
            'target_id'   => (string) $templateId,
            'summary'     => '编辑全局提示词模板「' . (string) ($current['name'] ?? ('#' . $templateId)) . '」',
            'detail'      => ['fields' => array_keys($data)],
        ]);

        return Response::json(['ok' => true, 'message' => '模板已保存']);
    }

    /** DELETE /api/admin/prompt-templates/{id} */
    public function destroy(Request $request): Response
    {
        $templateId = (int) $request->routeParam('id', '0');
        $current = $this->promptTemplateRepository->find($templateId);
        if ($current === null || (int) $current['user_id'] !== 0) {
            return Response::json(['ok' => false, 'error' => ['message' => '模板不存在']], 404);
        }

        $this->promptTemplateRepository->delete($templateId);

        Audit::ok('template.delete', [
            'target_type' => 'template',
            'target_id'   => (string) $templateId,
            'summary'     => '删除全局提示词模板「' . (string) ($current['name'] ?? ('#' . $templateId)) . '」',
        ]);

        return Response::json(['ok' => true, 'message' => '模板已删除']);
    }

    // ---------------------------------------------------------
    // 用户端：本人自建模板
    // ---------------------------------------------------------

    /** GET /api/user/prompt-templates —— 本人模板 + 全局模板（只读） */
    public function userIndex(Request $request): Response
    {
        $uid = $this->currentUserId();
        if ($uid === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '账号不存在']], 404);
        }

        $mine = $this->promptTemplateRepository->all($uid);

        $global = array_values(array_filter(
            $this->promptTemplateRepository->all(0),
            fn($t) => (int) $t['user_id'] === 0
        ));

        return Response::json([
            'ok'   => true,
            'data' => [
                'items'  => $mine,
                'global' => $global,
                'modes'  => $this->modeOptions(),
            ],
        ]);
    }

    /** POST /api/user/prompt-templates */
    public function userStore(Request $request): Response
    {
        $uid = $this->currentUserId();
        if ($uid === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '账号不存在']], 404);
        }

        $payload = $request->json();

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return Response::json(['ok' => false, 'error' => ['message' => '模板名称不能为空']], 400);
        }

        $mode = trim((string) ($payload['mode'] ?? 'prepend'));
        if (!in_array($mode, self::MODES, true)) {
            return Response::json(['ok' => false, 'error' => ['message' => '未知的注入位置：' . $mode]], 400);
        }

        $id = $this->promptTemplateRepository->create([
            'user_id'     => $uid,
            'name'        => $name,
            'description' => (string) ($payload['description'] ?? ''),
            'content'     => (string) ($payload['content'] ?? ''),
            'mode'        => $mode,
            'enabled'     => array_key_exists('enabled', $payload) ? !empty($payload['enabled']) : true,
            'is_default'  => !empty($payload['is_default']),
            'sort_order'  => (int) ($payload['sort_order'] ?? 0),
        ]);

        Audit::ok('template.create', [
            'user_id'     => $uid,
            'target_type' => 'template',
            'target_id'   => (string) $id,
            'summary'     => '新建本人提示词模板「' . $name . '」',
            'detail'      => ['mode' => $mode],
        ]);

        return Response::json(['ok' => true, 'message' => '模板已创建', 'data' => ['id' => $id]]);
    }

    /** PUT /api/user/prompt-templates/{id} */
    public function userUpdate(Request $request): Response
    {
        $uid = $this->currentUserId();
        if ($uid === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '账号不存在']], 404);
        }

        $templateId = (int) $request->routeParam('id', '0');
        if (!$this->promptTemplateRepository->ownedBy($templateId, $uid)) {
            return Response::json(['ok' => false, 'error' => ['message' => '模板不存在']], 404);
        }

        $payload = $request->json();
        $data = $this->extractUpdatable($payload, $unsupported);
        if ($unsupported !== null) {
            return Response::json(['ok' => false, 'error' => ['message' => $unsupported]], 400);
        }
        if (empty($data)) {
            return Response::json(['ok' => false, 'error' => ['message' => '没有需要更新的字段']], 400);
        }

        $this->promptTemplateRepository->update($templateId, $data);

        Audit::ok('template.update', [
            'user_id'     => $uid,
            'target_type' => 'template',
            'target_id'   => (string) $templateId,
            'summary'     => '编辑本人提示词模板「' . (string) ($this->promptTemplateRepository->find($templateId)['name'] ?? ('#' . $templateId)) . '」',
            'detail'      => ['fields' => array_keys($data)],
        ]);

        return Response::json(['ok' => true, 'message' => '模板已保存']);
    }

    /** DELETE /api/user/prompt-templates/{id} */
    public function userDestroy(Request $request): Response
    {
        $uid = $this->currentUserId();
        if ($uid === null) {
            return Response::json(['ok' => false, 'error' => ['message' => '账号不存在']], 404);
        }

        $templateId = (int) $request->routeParam('id', '0');
        if (!$this->promptTemplateRepository->ownedBy($templateId, $uid)) {
            return Response::json(['ok' => false, 'error' => ['message' => '模板不存在']], 404);
        }

        $this->promptTemplateRepository->delete($templateId);

        Audit::ok('template.delete', [
            'user_id'     => $uid,
            'target_type' => 'template',
            'target_id'   => (string) $templateId,
            'summary'     => '删除本人提示词模板「' . (string) ($this->promptTemplateRepository->find($templateId)['name'] ?? ('#' . $templateId)) . '」',
        ]);

        return Response::json(['ok' => true, 'message' => '模板已删除']);
    }

    // ---------------------------------------------------------

    /**
     * 提取可更新字段，并校验 mode；非法 mode 通过 &$unsupported 回传错误
     * 返回 ['unsupported' => ?string, 'data' => array] 结构改用引用方式实现
     */
    private function extractUpdatable(array $payload, ?string &$unsupported): array
    {
        $data = [];

        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                $unsupported = '模板名称不能为空';
                return [];
            }
            $data['name'] = $name;
        }
        if (array_key_exists('description', $payload)) {
            $data['description'] = (string) $payload['description'];
        }
        if (array_key_exists('content', $payload)) {
            $data['content'] = (string) $payload['content'];
        }
        if (array_key_exists('mode', $payload)) {
            $mode = trim((string) $payload['mode']);
            if (!in_array($mode, self::MODES, true)) {
                $unsupported = '未知的注入位置：' . $mode;
                return [];
            }
            $data['mode'] = $mode;
        }
        if (array_key_exists('enabled', $payload)) {
            $data['enabled'] = !empty($payload['enabled']);
        }
        if (array_key_exists('is_default', $payload)) {
            $data['is_default'] = !empty($payload['is_default']);
        }
        if (array_key_exists('sort_order', $payload)) {
            $data['sort_order'] = (int) $payload['sort_order'];
        }

        return $data;
    }

    private function modeOptions(): array
    {
        return [
            ['value' => 'prepend', 'label' => '前置追加（保留原 system）'],
            ['value' => 'append', 'label' => '后置追加（保留原 system）'],
            ['value' => 'replace', 'label' => '替换原 system'],
        ];
    }

    private function currentUserId(): ?int
    {
        $session = UserAuthMiddleware::current() ?? [];
        $uid = (int) ($session['id'] ?? 0);
        return $uid > 0 ? $uid : null;
    }
}