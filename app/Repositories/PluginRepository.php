<?php
/**
 * 插件仓储（对应 aigw_plugin）
 *
 * 参考 Operit 的 ToolPkg：插件由一份 `manifest.json` 描述
 * （toolpkg_id / version / api_version / main / subpackages / display_name / description），
 * 通过「安装 manifest → 启停 → 挂载其声明的能力」实现热插拔。
 *
 * 与 Operit 的差异：Operit 在 App 沙箱里跑插件 JS bundle（QuickJS/Compose DSL）；
 * 网关侧无浏览器/JS 运行时，故这里落地的是**元数据 + 声明式能力**：
 *  - 插件声明的 `tools` 会被登记为可调用的工具（工具执行交由已注册的 MCP/内置工具/HTTP 端点）
 *  - 插件声明的 `system_prompt` 会被注入对话系统提示
 * 真正的 JS 执行仍由 App 侧 QuickJS 沙箱承担，插件 bundle 由客户端按 plugin_id 拉取。
 *
 * ⚠️ 属主隔离：普通用户只能操作自己的行（user_id = 本人）；管理员 scope=null 不限。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class PluginRepository
{
    private const TABLE = 'aigw_plugin';

    /** 单用户插件上限 */
    public const MAX_PER_USER = 100;

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

        return array_map([$this, 'hydrate'], $q->orderBy('id', 'DESC')->get());
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

    /** 按 plugin_id 取（同属主内唯一） */
    public function findByPluginId(string $pluginId, ?int $userId): ?array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('plugin_id', $pluginId);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        $row = $q->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /** 启用中的插件（用于能力挂载） */
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

        return array_map([$this, 'hydrate'], $q->get());
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

    public function update(int $id, array $data): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)->where('id', $id)->update($this->packPartial($data));
    }

    public function delete(int $id): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)->where('id', $id)->delete();
    }

    /** 记录启停状态与最近装载结果 */
    public function setStatus(int $id, string $status, string $message = ''): void
    {
        $this->ensureSchema();

        $this->db->table(self::TABLE)->where('id', $id)->update([
            'status'         => $status,
            'status_message' => mb_substr($message, 0, 500),
        ]);
    }

    /** plugin_id 是否已存在（同属主内） */
    public function pluginIdExists(string $pluginId, ?int $userId, int $exceptId = 0): bool
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('plugin_id', $pluginId);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }
        if ($exceptId > 0) {
            $q->where('id', '!=', $exceptId);
        }

        return $q->first() !== null;
    }

    private function pack(array $data): array
    {
        $packed = [
            'plugin_id'      => mb_substr((string) ($data['plugin_id'] ?? ''), 0, 128),
            'name'           => mb_substr((string) ($data['name'] ?? ''), 0, 128),
            'description'    => mb_substr((string) ($data['description'] ?? ''), 0, 512),
            'version'        => mb_substr((string) ($data['version'] ?? ''), 0, 32),
            'api_version'    => mb_substr((string) ($data['api_version'] ?? ''), 0, 32),
            'entry'          => mb_substr((string) ($data['entry'] ?? ''), 0, 255),
            'subpackages'    => json_encode((array) ($data['subpackages'] ?? []), JSON_UNESCAPED_UNICODE),
            'manifest'       => json_encode((array) ($data['manifest'] ?? []), JSON_UNESCAPED_UNICODE),
            'source'         => ($data['source'] ?? 'upload') === 'builtin' ? 'builtin' : 'upload',
            'enabled'        => !empty($data['enabled']) ? 1 : 0,
            'status'         => in_array($data['status'] ?? '', ['OK', 'FAIL'], true) ? $data['status'] : 'OK',
            'status_message' => mb_substr((string) ($data['status_message'] ?? ''), 0, 512),
        ];

        if (array_key_exists('user_id', $data)) {
            $packed['user_id'] = (int) $data['user_id'];
        }

        return $packed;
    }

    /** 局部更新：只写显式传入的字段（避免 toggle 把 manifest 清空） */
    private function packPartial(array $data): array
    {
        $packed = [];
        foreach (['name', 'description', 'version', 'api_version', 'entry', 'source'] as $key) {
            if (array_key_exists($key, $data)) {
                $packed[$key] = mb_substr((string) $data[$key], 0, 512);
            }
        }
        if (array_key_exists('enabled', $data)) {
            $packed['enabled'] = !empty($data['enabled']) ? 1 : 0;
        }
        if (array_key_exists('status', $data)) {
            $packed['status'] = in_array($data['status'], ['OK', 'FAIL'], true) ? $data['status'] : 'OK';
        }
        if (array_key_exists('status_message', $data)) {
            $packed['status_message'] = mb_substr((string) $data['status_message'], 0, 512);
        }
        if (array_key_exists('manifest', $data)) {
            $packed['manifest'] = json_encode((array) $data['manifest'], JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('subpackages', $data)) {
            $packed['subpackages'] = json_encode((array) $data['subpackages'], JSON_UNESCAPED_UNICODE);
        }

        return $packed;
    }

    private function hydrate(array $row): array
    {
        $row['subpackages'] = $this->decodeArray($row['subpackages'] ?? null);
        $row['manifest'] = $this->decodeArray($row['manifest'] ?? null);
        $row['enabled'] = (int) ($row['enabled'] ?? 0) === 1;

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
