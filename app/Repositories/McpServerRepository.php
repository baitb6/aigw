<?php
/**
 * MCP 服务器仓储（对应 aigw_mcp_server）
 *
 * 参考 Operit 的 MCPRepository：持久化 MCP 服务配置（remote / local 两种传输），
 * 并缓存“工具清单”避免每次都要向 MCP 服务查询。
 *
 * ⚠️ 属主隔离：普通用户只能操作自己的行（user_id = 本人）；管理员 scope=null 不限。
 * ⚠️ bearer_token 为敏感凭据，读写均经 Crypto 加解密（与账号池凭据同一套）。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Crypto;
use App\Support\Schema;

class McpServerRepository
{
    private const TABLE = 'aigw_mcp_server';

    /** 工具清单缓存时长（ms）—— 参考 Operit 的 1 天缓存 */
    public const TOOLS_CACHE_MS = 86400000;

    public function __construct(private Database $db) {}

    public function ensureSchema(): void
    {
        Schema::ensure($this->db);
    }

    /** 列表：管理员看全部，普通用户只看自己 */
    public function list(?int $userId): array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return array_map([$this, 'hydrate'], $q->orderBy('id', 'DESC')->get());
    }

    /** 按 id + 属主取一条（对外接口必须走这个，防止越权） */
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

    /** 启用中的服务（用于工具注册） */
    public function enabledForUser(?int $userId): array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('enabled', 1);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return array_map([$this, 'hydrate'], $q->get());
    }

    public function create(array $data): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)->insert($this->pack(array_replace([
            'name' => '',
            'transport' => 'remote',
            'connection_type' => 'httpStream',
            'endpoint' => '',
            'command' => '',
            'args' => [],
            'env' => [],
            'headers' => [],
            'enabled' => true,
        ], $data)));
    }

    /** @param ?int $userId 归属过滤（审计 TOCTOU 修复），语义同 findForUser */
    public function update(int $id, array $data, ?int $userId = null): int
    {
        $this->ensureSchema();

        $packed = $this->pack($data);
        if (array_intersect(array_keys($data), ['name', 'transport', 'connection_type', 'endpoint', 'command', 'args', 'env', 'headers', 'bearer_token', 'enabled']) !== []) {
            $packed = array_replace($packed, [
                'tools' => '[]',
                'tools_cached_at' => 0,
                'last_status' => '',
                'last_message' => '',
            ]);
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

    /** 写入工具清单缓存 + 连通状态 */
    public function cacheTools(int $id, array $tools, string $status, string $message = ''): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->where('id', $id)->update([
            'tools'           => json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'tools_cached_at' => now_ms(),
            'last_status'     => $status,
            'last_message'    => mb_substr($message, 0, 500),
        ]);
    }

    /** 校验服务名唯一（同属主下） */
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

    /** 入库前打包（凭据加密 + JSON 序列化） */
    private function pack(array $data): array
    {
        $packed = [
            'name'            => mb_substr((string) ($data['name'] ?? ''), 0, 64),
            'transport'       => in_array($data['transport'] ?? '', ['remote', 'local'], true)
                ? $data['transport'] : 'remote',
            'connection_type' => in_array($data['connection_type'] ?? '', ['httpStream', 'sse'], true)
                ? $data['connection_type'] : 'httpStream',
            'endpoint'        => mb_substr((string) ($data['endpoint'] ?? ''), 0, 512),
            'command'         => mb_substr((string) ($data['command'] ?? ''), 0, 255),
            'args'            => json_encode((array) ($data['args'] ?? []), JSON_UNESCAPED_UNICODE),
            'env'             => json_encode((array) ($data['env'] ?? []), JSON_UNESCAPED_UNICODE),
            'headers'         => json_encode((array) ($data['headers'] ?? []), JSON_UNESCAPED_UNICODE),
            'enabled'         => !empty($data['enabled']) ? 1 : 0,
        ];

        // PATCH only writes submitted fields; explicit empty values still clear configuration.
        $packed = array_intersect_key($packed, $data);

        if (array_key_exists('user_id', $data)) {
            $packed['user_id'] = (int) $data['user_id'];
        }

        // bearer_token：非空才加密覆盖；空字符串表示“清空”
        if (array_key_exists('bearer_token', $data)) {
            $token = (string) $data['bearer_token'];
            $packed['bearer_token'] = $token === '' ? '' : Crypto::encrypt($token);
        }

        return $packed;
    }

    /** 出库后整形（凭据解密 + JSON 反序列化；凭据只回传“是否已配置”，不回明文） */
    private function hydrate(array $row): array
    {
        $enc = (string) ($row['bearer_token'] ?? '');
        $row['has_token'] = $enc !== '';
        $row['bearer_token'] = '';
        $row['_token_enc'] = $enc;

        $row['args'] = $this->decodeArray($row['args'] ?? null);
        $row['env'] = $this->decodeArray($row['env'] ?? null);
        $row['headers'] = $this->decodeArray($row['headers'] ?? null);

        $tools = json_decode((string) ($row['tools'] ?? ''), true);
        $row['tools'] = is_array($tools) ? $tools : [];

        $row['enabled'] = (int) ($row['enabled'] ?? 0) === 1;
        $row['tools_cached_at'] = (int) ($row['tools_cached_at'] ?? 0);

        return $row;
    }

    /** 供 Service 内部取用解密后的 token（不出现在对外响应里） */
    public function decryptToken(array $row): string
    {
        $enc = (string) ($row['_token_enc'] ?? '');
        if ($enc === '') {
            return '';
        }
        try {
            return Crypto::decrypt($enc);
        } catch (\Throwable $e) {
            return '';
        }
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
