<?php
/**
 * 角色卡仓储（对应 aigw_character_card）
 *
 * 参考 Operit 的 CharacterCard：一张角色卡定义一个对话人格（设定 / 开场白 /
 * 绑定模型），同一时刻属主内只有一张 is_active = 1。
 *
 * 导入/导出兼容 SillyTavern 的字段命名（name / description / personality /
 * first_mes / mes_example / scenario），便于从社区角色卡直接导入。
 *
 * ⚠️ 属主隔离：普通用户只能操作自己的行；管理员 scope=null 不限。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class CharacterCardRepository
{
    private const TABLE = 'aigw_character_card';

    /** 单用户角色卡上限 */
    public const MAX_PER_USER = 300;

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

        return array_map([$this, 'hydrate'], $q->orderBy('is_active', 'DESC')->orderBy('id', 'DESC')->get());
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

    /** 当前激活的角色卡 */
    public function active(?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('is_active', 1);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        $row = $q->orderBy('id', 'DESC')->first();
        if ($row === null) {
            $row = $this->db->table(self::TABLE)->where('user_id', $userId)
                ->where('is_default', 1)->orderBy('id', 'DESC')->first();
        }
        return $row === null ? null : $this->hydrate($row);
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

    public function nameExists(string $name, ?int $userId, int $exceptId = 0): bool
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('name', $name);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }
        if ($exceptId > 0) {
            $q->where('id', '!=', $exceptId);
        }

        return $q->first() !== null;
    }

    public function create(array $data): int
    {
        $this->ensureSchema();

        return $this->db->transaction(function () use ($data): int {
            $ownerId = (int) ($data['user_id'] ?? 0);
            if (!empty($data['is_default'])) {
                $this->db->table(self::TABLE)->where('user_id', $ownerId)->update(['is_default' => 0]);
            }
            return $this->db->table(self::TABLE)->insert($this->pack($data));
        });
    }

    /** @param ?int $userId 归属过滤（审计 TOCTOU 修复），语义同 findForUser */
    public function update(int $id, array $data, ?int $userId = null): int
    {
        $this->ensureSchema();

        return $this->db->transaction(function () use ($id, $data, $userId): int {
            $q = $this->db->table(self::TABLE)->where('id', $id);
            if ($userId !== null) {
                $q->where('user_id', $userId);
            }
            $card = $q->first();
            if ($card === null) {
                return 0;
            }
            if (!empty($data['is_default'])) {
                $this->db->table(self::TABLE)->where('user_id', (int) $card['user_id'])
                    ->where('id', '!=', $id)->update(['is_default' => 0]);
            }
            $u = $this->db->table(self::TABLE)->where('id', $id);
            if ($userId !== null) {
                $u->where('user_id', $userId);
            }
            return $u->update($this->packPartial($data));
        });
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

    /** 设为激活（属主内唯一） */
    public function activate(int $id, ?int $userId): void
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }
        $q->update(['is_active' => 0]);

        $this->db->table(self::TABLE)->where('id', $id)->update(['is_active' => 1]);
    }

    /** 取消激活 */
    public function deactivate(?int $userId): void
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('is_active', 1);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }
        $q->update(['is_active' => 0]);
    }

    public function deleteAll(?int $userId): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return (int) $q->delete();
    }

    private function pack(array $data): array
    {
        $packed = [
            'name'                   => mb_substr((string) ($data['name'] ?? ''), 0, 128),
            'description'            => (string) ($data['description'] ?? ''),
            'character_setting'      => (string) ($data['character_setting'] ?? ''),
            'opening_statement'      => (string) ($data['opening_statement'] ?? ''),
            'other_content_chat'     => (string) ($data['other_content_chat'] ?? ''),
            'other_content_voice'    => (string) ($data['other_content_voice'] ?? ''),
            'advanced_custom_prompt' => (string) ($data['advanced_custom_prompt'] ?? ''),
            'tags'                   => json_encode((array) ($data['tags'] ?? []), JSON_UNESCAPED_UNICODE),
            'marks'                  => mb_substr((string) ($data['marks'] ?? ''), 0, 255),
            'avatar'                 => (string) ($data['avatar'] ?? ''),
            'model_profile_id'       => (int) ($data['model_profile_id'] ?? 0),
            'is_default'             => !empty($data['is_default']) ? 1 : 0,
            'is_active'              => !empty($data['is_active']) ? 1 : 0,
            'source'                 => ($data['source'] ?? 'manual') === 'import' ? 'import' : 'manual',
        ];

        if (array_key_exists('user_id', $data)) {
            $packed['user_id'] = (int) $data['user_id'];
        }

        return $packed;
    }

    /** 局部更新：只写显式传入的字段 */
    private function packPartial(array $data): array
    {
        $packed = [];
        foreach (['description', 'character_setting', 'opening_statement', 'other_content_chat',
                  'other_content_voice', 'advanced_custom_prompt', 'avatar'] as $key) {
            if (array_key_exists($key, $data)) {
                $packed[$key] = (string) $data[$key];
            }
        }
        if (array_key_exists('name', $data)) {
            $packed['name'] = mb_substr((string) $data['name'], 0, 128);
        }
        if (array_key_exists('marks', $data)) {
            $packed['marks'] = mb_substr((string) $data['marks'], 0, 255);
        }
        if (array_key_exists('tags', $data)) {
            $packed['tags'] = json_encode((array) $data['tags'], JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('model_profile_id', $data)) {
            $packed['model_profile_id'] = (int) $data['model_profile_id'];
        }
        foreach (['is_default', 'is_active'] as $boolKey) {
            if (array_key_exists($boolKey, $data)) {
                $packed[$boolKey] = !empty($data[$boolKey]) ? 1 : 0;
            }
        }

        return $packed;
    }

    private function hydrate(array $row): array
    {
        $row['tags'] = $this->decodeArray($row['tags'] ?? null);
        $row['model_profile_id'] = (int) ($row['model_profile_id'] ?? 0);
        $row['is_default'] = (int) ($row['is_default'] ?? 0) === 1;
        $row['is_active'] = (int) ($row['is_active'] ?? 0) === 1;

        return $row;
    }

    private function decodeArray($value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
