<?php
/**
 * 模型档案仓储（对应 aigw_model_profile）
 *
 * 参考 Operit 的 ModelConfig：用户可自定义多个 OpenAI 兼容端点档案
 * （endpoint / api_key / model / headers / params），并可做连通性测试。
 *
 * 与网关账号池的区别：账号池是「管理员托管的共享额度」；模型档案是
 * 「用户自己的私有端点配置」，用于直连自建服务。
 *
 * ⚠️ 属主隔离：普通用户只能操作自己的行；管理员 scope=null 不限。
 * ⚠️ api_key 经 Crypto 加密存储，出参只回 has_key。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Crypto;
use App\Support\Schema;

class ModelProfileRepository
{
    private const TABLE = 'aigw_model_profile';

    /** 单用户档案上限 */
    public const MAX_PER_USER = 50;

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

        return array_map([$this, 'hydrate'], $q->orderBy('is_default', 'DESC')->orderBy('id', 'DESC')->get());
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

        $makeDefault = !empty($data['is_default']);
        $data['is_default'] = false;
        if (!$makeDefault) {
            return $this->db->table(self::TABLE)->insert($this->pack($data));
        }
        return $this->db->transaction(function () use ($data): int {
            $userId = (int) ($data['user_id'] ?? 0);
            $this->lockOwner($userId);
            $id = $this->db->table(self::TABLE)->insert($this->pack($data));
            $this->setOwnerDefault($id, $userId);
            return $id;
        });
    }

    /** @param ?int $ownerId 归属过滤（审计 TOCTOU 修复），语义同 findForUser */
    public function update(int $id, array $data, ?int $ownerId = null): int
    {
        $this->ensureSchema();

        $scoped = function ($q) use ($ownerId) {
            if ($ownerId !== null) {
                $q->where('user_id', $ownerId);
            }
            return $q;
        };

        if (empty($data['is_default'])) {
            return $scoped($this->db->table(self::TABLE)->where('id', $id))->update($this->packPartial($data));
        }
        return $this->db->transaction(function () use ($id, $data, $ownerId, $scoped): int {
            $row = $scoped($this->db->table(self::TABLE)->where('id', $id))->first();
            if ($row === null) {
                return 0;
            }
            $userId = (int) $row['user_id'];
            $this->lockOwner($userId);
            $this->setOwnerDefault($id, $userId);
            return $scoped($this->db->table(self::TABLE)->where('id', $id))->update($this->packPartial($data));
        });
    }

    public function delete(int $id, ?int $ownerId = null): int
    {
        $this->ensureSchema();

        return $this->db->transaction(function () use ($id, $ownerId): int {
            $this->db->table('aigw_character_card')->where('model_profile_id', $id)
                ->update(['model_profile_id' => 0]);
            $q = $this->db->table(self::TABLE)->where('id', $id);
            if ($ownerId !== null) {
                $q->where('user_id', $ownerId);
            }
            return $q->delete();
        });
    }

    /** 记录连通测试结果 */
    public function recordTest(int $id, string $status, string $message): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->where('id', $id)->update([
            'last_status'     => $status,
            'last_message'    => mb_substr($message, 0, 500),
            'last_checked_at' => now_ms(),
        ]);
    }

    /** 把指定档案设为默认，其余取消（属主内唯一） */
    public function markDefault(int $id, ?int $userId): void
    {
        $this->ensureSchema();

        $this->db->transaction(function () use ($id, $userId): void {
            $q = $this->db->table(self::TABLE)->where('id', $id);
            if ($userId !== null) {
                $q->where('user_id', $userId);
            }
            $row = $q->first();
            if ($row === null) {
                throw new \RuntimeException('档案不存在');
            }
            $owner = (int) $row['user_id'];
            $this->lockOwner($owner);
            $this->setOwnerDefault($id, $owner);
        });
    }

    private function lockOwner(int $userId): void
    {
        if ($this->db->first('SELECT id FROM aigw_user WHERE id = ? FOR UPDATE', [$userId]) === null) {
            throw new \RuntimeException('档案属主不存在');
        }
    }

    private function setOwnerDefault(int $id, int $userId): void
    {
        $row = $this->db->table(self::TABLE)->where('id', $id)->where('user_id', $userId)->first();
        if ($row === null) {
            throw new \RuntimeException('档案不存在');
        }
        $this->db->table(self::TABLE)->where('user_id', $userId)->update(['is_default' => 0]);
        $this->db->table(self::TABLE)->where('id', $id)->where('user_id', $userId)->update(['is_default' => 1]);
    }

    /** 供 Service 内部取解密后的 key（不出现在对外响应里） */
    public function decryptKey(array $row): string
    {
        $enc = (string) ($row['_key_enc'] ?? '');
        if ($enc === '') {
            return '';
        }
        try {
            return Crypto::decrypt($enc);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function publicProfile(array $row): array
    {
        unset($row['_key_enc'], $row['api_key']);
        foreach ((array) ($row['custom_headers'] ?? []) as $name => $value) {
            if (preg_match('/authorization|api[-_]?key|token|secret|cookie|credential/i', (string) $name)) {
                $row['custom_headers'][$name] = '********';
            }
        }
        return $row;
    }

    private function pack(array $data): array
    {
        $packed = [
            'name'             => mb_substr((string) ($data['name'] ?? ''), 0, 64),
            'provider_type'    => in_array($data['provider_type'] ?? '', ['openai', 'anthropic', 'gemini', 'ollama', 'custom'], true)
                ? $data['provider_type'] : 'openai',
            'api_endpoint'     => mb_substr((string) ($data['api_endpoint'] ?? ''), 0, 512),
            'model_name'       => mb_substr((string) ($data['model_name'] ?? ''), 0, 160),
            'custom_headers'   => json_encode((array) ($data['custom_headers'] ?? []), JSON_UNESCAPED_UNICODE),
            'custom_params'    => json_encode((array) ($data['custom_params'] ?? []), JSON_UNESCAPED_UNICODE),
            'enable_tool_call' => !empty($data['enable_tool_call']) ? 1 : 0,
            'enable_vision'    => !empty($data['enable_vision']) ? 1 : 0,
            'is_default'       => !empty($data['is_default']) ? 1 : 0,
            'enabled'          => !isset($data['enabled']) || !empty($data['enabled']) ? 1 : 0,
        ];

        if (array_key_exists('user_id', $data)) {
            $packed['user_id'] = (int) $data['user_id'];
        }
        if (array_key_exists('api_key', $data)) {
            $key = (string) $data['api_key'];
            $packed['api_key'] = $key === '' ? '' : Crypto::encrypt($key);
        }

        return $packed;
    }

    /** 局部更新：只写显式传入的字段 */
    private function packPartial(array $data): array
    {
        $packed = [];
        if (array_key_exists('name', $data)) {
            $packed['name'] = mb_substr((string) $data['name'], 0, 64);
        }
        if (array_key_exists('provider_type', $data)) {
            $packed['provider_type'] = in_array($data['provider_type'], ['openai', 'anthropic', 'gemini', 'ollama', 'custom'], true)
                ? $data['provider_type'] : 'openai';
        }
        if (array_key_exists('api_endpoint', $data)) {
            $packed['api_endpoint'] = mb_substr((string) $data['api_endpoint'], 0, 512);
        }
        if (array_key_exists('model_name', $data)) {
            $packed['model_name'] = mb_substr((string) $data['model_name'], 0, 160);
        }
        if (array_key_exists('custom_headers', $data)) {
            $packed['custom_headers'] = json_encode((array) $data['custom_headers'], JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('custom_params', $data)) {
            $packed['custom_params'] = json_encode((array) $data['custom_params'], JSON_UNESCAPED_UNICODE);
        }
        foreach (['enable_tool_call', 'enable_vision', 'is_default', 'enabled'] as $boolKey) {
            if (array_key_exists($boolKey, $data)) {
                $packed[$boolKey] = !empty($data[$boolKey]) ? 1 : 0;
            }
        }
        if (array_key_exists('api_key', $data)) {
            $key = (string) $data['api_key'];
            $packed['api_key'] = $key === '' ? '' : Crypto::encrypt($key);
        }

        return $packed;
    }

    private function hydrate(array $row): array
    {
        $enc = (string) ($row['api_key'] ?? '');
        $row['has_key'] = $enc !== '';
        $row['api_key'] = '';
        $row['_key_enc'] = $enc;

        $row['custom_headers'] = $this->decodeArray($row['custom_headers'] ?? null);
        $row['custom_params'] = $this->decodeArray($row['custom_params'] ?? null);
        $row['enable_tool_call'] = (int) ($row['enable_tool_call'] ?? 0) === 1;
        $row['enable_vision'] = (int) ($row['enable_vision'] ?? 0) === 1;
        $row['is_default'] = (int) ($row['is_default'] ?? 0) === 1;
        $row['enabled'] = (int) ($row['enabled'] ?? 0) === 1;
        $row['last_checked_at'] = (int) ($row['last_checked_at'] ?? 0);

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
