<?php
/**
 * 世界书仓储（对应 aigw_worldbook）
 *
 * 参考 Operit 的 WorldBookEntry：条目由「关键词/正则」触发激活，
 * 或在 always_active 时常驻注入；按 priority 排序注入上下文。
 *
 * ⚠️ 属主隔离：普通用户只能操作自己的行（user_id = 本人）；管理员 scope=null 不限。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class WorldBookRepository
{
    private const TABLE = 'aigw_worldbook';

    /** 单用户条目上限 */
    public const MAX_PER_USER = 500;

    public function __construct(private Database $db) {}

    public function ensureSchema(): void
    {
        Schema::ensure($this->db);
    }

    public function list(?int $userId): array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return array_map([$this, 'hydrate'], $q->orderBy('priority', 'DESC')->orderBy('id', 'DESC')->get());
    }

    public function findForUser(int $id, ?int $userId): ?array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('id', $id);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        $row = $q->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /** 启用中的条目（用于上下文注入），按 priority 降序 */
    public function enabledForUser(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('enabled', 1);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return array_map(
            [$this, 'hydrate'],
            $q->orderBy('priority', 'DESC')->orderBy('id', 'ASC')->get()
        );
    }

    public function countForUser(?int $userId): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return (int) $q->count();
    }

    public function create(array $data): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)->insert($this->pack($data));
    }

    /** @param ?int $userId 归属过滤（审计 TOCTOU 修复），语义同 findForUser */
    public function update(int $id, array $data, ?int $userId = null): int
    {
        $this->ensureSchema();

        $packed = $this->packPartial($data);
        if ($packed === []) {
            return 0;
        }

        $q = $this->db->table(self::TABLE)->where('id', $id);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->update($packed);
    }

    public function delete(int $id, ?int $userId = null): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('id', $id);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->delete();
    }

    public function deleteAll(?int $userId): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->delete();
    }

    /** 记录命中（用于统计与排序参考） */
    public function markHit(int $id): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->where('id', $id)->update([
            'last_hit_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->table(self::TABLE)->where('id', $id)->increment('hit_count');
    }

    private function pack(array $data): array
    {
        $packed = [
            'name'            => mb_substr((string) ($data['name'] ?? ''), 0, 128),
            'content'         => (string) ($data['content'] ?? ''),
            'keywords'        => mb_substr((string) ($data['keywords'] ?? ''), 0, 512),
            'is_regex'        => !empty($data['is_regex']) ? 1 : 0,
            'case_sensitive'  => !empty($data['case_sensitive']) ? 1 : 0,
            'always_active'   => !empty($data['always_active']) ? 1 : 0,
            'enabled'         => array_key_exists('enabled', $data) ? (!empty($data['enabled']) ? 1 : 0) : 1,
            'priority'        => (int) ($data['priority'] ?? 50),
            'scan_depth'      => max(1, min(64, (int) ($data['scan_depth'] ?? 4))),
            'inject_target'   => in_array($data['inject_target'] ?? '', ['system', 'user', 'assistant'], true)
                ? $data['inject_target'] : 'system',
            'inject_position' => in_array($data['inject_position'] ?? '', ['prepend', 'append', 'at_depth'], true)
                ? $data['inject_position'] : 'append',
            'insertion_depth' => max(0, (int) ($data['insertion_depth'] ?? 0)),
            'group_name'      => mb_substr((string) ($data['group_name'] ?? ''), 0, 64),
        ];

        if (array_key_exists('user_id', $data)) {
            $packed['user_id'] = (int) $data['user_id'];
        }

        return $packed;
    }

    /**
     * 局部更新用：只打包**显式传入**的字段，未传字段保持原值。
     *
     * 与 pack() 的区别：pack() 供 create() 使用，会把缺失字段填成默认值；
     * 若局部更新（如仅切换 enabled）误用 pack()，会把 name/content/keywords
     * 等未传字段一并清空 —— 造成数据丢失。故此处逐字段判存在。
     */
    private function packPartial(array $data): array
    {
        $packed = [];

        if (array_key_exists('name', $data)) {
            $packed['name'] = mb_substr((string) $data['name'], 0, 128);
        }
        if (array_key_exists('content', $data)) {
            $packed['content'] = (string) $data['content'];
        }
        if (array_key_exists('keywords', $data)) {
            $packed['keywords'] = mb_substr((string) $data['keywords'], 0, 512);
        }
        foreach (['is_regex', 'case_sensitive', 'always_active', 'enabled'] as $boolKey) {
            if (array_key_exists($boolKey, $data)) {
                $packed[$boolKey] = !empty($data[$boolKey]) ? 1 : 0;
            }
        }
        if (array_key_exists('priority', $data)) {
            $packed['priority'] = (int) $data['priority'];
        }
        if (array_key_exists('scan_depth', $data)) {
            $packed['scan_depth'] = max(1, min(64, (int) $data['scan_depth']));
        }
        if (array_key_exists('inject_target', $data)) {
            $packed['inject_target'] = in_array($data['inject_target'], ['system', 'user', 'assistant'], true)
                ? $data['inject_target'] : 'system';
        }
        if (array_key_exists('inject_position', $data)) {
            $packed['inject_position'] = in_array($data['inject_position'], ['prepend', 'append', 'at_depth'], true)
                ? $data['inject_position'] : 'append';
        }
        if (array_key_exists('insertion_depth', $data)) {
            $packed['insertion_depth'] = max(0, (int) $data['insertion_depth']);
        }
        if (array_key_exists('group_name', $data)) {
            $packed['group_name'] = mb_substr((string) $data['group_name'], 0, 64);
        }

        return $packed;
    }

    private function hydrate(array $row): array
    {
        $row['is_regex'] = (int) ($row['is_regex'] ?? 0) === 1;
        $row['case_sensitive'] = (int) ($row['case_sensitive'] ?? 0) === 1;
        $row['always_active'] = (int) ($row['always_active'] ?? 0) === 1;
        $row['enabled'] = (int) ($row['enabled'] ?? 0) === 1;
        $row['priority'] = (int) ($row['priority'] ?? 50);
        $row['scan_depth'] = (int) ($row['scan_depth'] ?? 4);
        $row['insertion_depth'] = (int) ($row['insertion_depth'] ?? 0);
        $row['hit_count'] = (int) ($row['hit_count'] ?? 0);

        return $row;
    }
}
