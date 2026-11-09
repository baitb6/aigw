<?php
/**
 * 提示词模板仓储
 *
 * 作用域约定（与 Tenant 一致）：
 *  - user_id = 0  → 管理员维护的全局模板，对所有网关调用生效
 *  - user_id > 0  → 某普通用户自建模板，仅对该用户自己的调用生效
 *
 * 每个作用域内至多有一个 is_default=1 的模板，作为「自动注入」使用的模板；
 * 若没有默认模板，则回退到第一个启用中的模板。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

class PromptTemplateRepository
{
    private const TABLE = 'aigw_prompt_template';

    private const MODES = ['prepend', 'append', 'replace'];

    public function __construct(private Database $db) {}

    /**
     * 列出某作用域（user_id）下的模板
     *
     * @param int|null $userId null=全部（管理员视图），int=该用户 + 全局
     * @param bool     $includeGlobal 是否把全局模板（user_id=0）一并返回
     * @return list<array>
     */
    public function all(?int $userId = null, bool $includeGlobal = false): array
    {
        $query = $this->db->table(self::TABLE);

        if ($userId !== null) {
            if ($includeGlobal && $userId > 0) {
                $query->whereRaw('(`user_id` = ? OR `user_id` = 0)', [$userId]);
            } else {
                $query->where('user_id', $userId);
            }
        }

        $rows = $query->orderBy('is_default', 'DESC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        return array_map(fn(array $row) => $this->normalize($row), $rows);
    }

    public function find(int $id): ?array
    {
        $row = $this->db->table(self::TABLE)->where('id', $id)->first();
        return $row ? $this->normalize($row) : null;
    }

    /** 当前作用域下「自动注入」应使用的模板（默认模板优先，否则首个启用的模板） */
    public function active(?int $userId): ?array
    {
        $owner = $userId ?? 0;

        $query = $this->db->table(self::TABLE)
            ->where('user_id', $owner)
            ->where('enabled', 1)
            ->orderBy('is_default', 'DESC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC');

        $row = $query->first();
        return $row ? $this->normalize($row) : null;
    }

    public function effective(?int $userId): ?array
    {
        return ($userId === null ? null : $this->active($userId)) ?? $this->active(0);
    }

    public function create(array $data): int
    {
        $payload = $this->sanitize($data);
        $payload['user_id'] = (int) ($data['user_id'] ?? 0);
        $payload['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $payload['created_at'] = date('Y-m-d H:i:s');

        $id = $this->db->table(self::TABLE)->insert($payload);

        if (!empty($payload['is_default'])) {
            $this->makeSoleDefault($id, (int) $payload['user_id']);
        }

        return $id;
    }

    public function update(int $id, array $data): void
    {
        $current = $this->find($id);
        if ($current === null) {
            return;
        }

        $payload = $this->sanitize($data);
        if (empty($payload)) {
            return;
        }

        $this->db->table(self::TABLE)->where('id', $id)->update($payload);

        if (!empty($payload['is_default'])) {
            $this->makeSoleDefault($id, (int) $current['user_id']);
        }
    }

    public function delete(int $id): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->delete();
    }

    /** 校验模板归属（防止跨用户越权操作） */
    public function ownedBy(int $id, ?int $userId): bool
    {
        $row = $this->find($id);
        if ($row === null) {
            return false;
        }
        if ($userId === null) {
            return true;
        }
        return (int) $row['user_id'] === $userId;
    }

    /** 仅保留一个默认模板（同一作用域内） */
    private function makeSoleDefault(int $id, int $userId): void
    {
        $this->db->statement(
            'UPDATE `' . self::TABLE . '` SET `is_default` = 0 WHERE `user_id` = ? AND `id` <> ?',
            [$userId, $id]
        );
    }

    private function sanitize(array $data): array
    {
        $payload = [];

        if (array_key_exists('name', $data)) {
            $payload['name'] = mb_substr(trim((string) $data['name']), 0, 64);
        }
        if (array_key_exists('description', $data)) {
            $payload['description'] = mb_substr(trim((string) $data['description']), 0, 255);
        }
        if (array_key_exists('content', $data)) {
            $payload['content'] = (string) $data['content'];
        }
        if (array_key_exists('mode', $data)) {
            $mode = trim((string) $data['mode']);
            $payload['mode'] = in_array($mode, self::MODES, true) ? $mode : 'prepend';
        }
        if (array_key_exists('enabled', $data)) {
            $payload['enabled'] = !empty($data['enabled']) ? 1 : 0;
        }
        if (array_key_exists('is_default', $data)) {
            $payload['is_default'] = !empty($data['is_default']) ? 1 : 0;
        }
        if (array_key_exists('sort_order', $data)) {
            $payload['sort_order'] = (int) $data['sort_order'];
        }

        return $payload;
    }

    private function normalize(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'user_id'     => (int) $row['user_id'],
            'name'        => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''),
            'content'     => (string) ($row['content'] ?? ''),
            'mode'        => in_array(($row['mode'] ?? 'prepend'), self::MODES, true) ? (string) $row['mode'] : 'prepend',
            'enabled'     => (bool) $row['enabled'],
            'is_default'  => (bool) ($row['is_default'] ?? false),
            'sort_order'  => (int) ($row['sort_order'] ?? 0),
        ];
    }
}