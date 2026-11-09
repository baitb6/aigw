<?php
/**
 * 环境变量仓储（对应 aigw_env_var）
 *
 * 参考 Operit 的 EnvironmentVariableRead/Write：用户可持久化一组键值，
 * 在工具/插件/MCP 子进程执行时作为环境注入。
 *
 * ⚠️ 属主隔离：普通用户只能操作自己的行；管理员 scope=null 不限。
 * ⚠️ 标记为 is_secret 的值经 Crypto 加密存储，出参不回明文（只回 has_value）。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Crypto;
use App\Support\Schema;

class EnvVarRepository
{
    private const TABLE = 'aigw_env_var';

    /** 单用户变量上限 */
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

        return array_map([$this, 'hydrate'], $q->orderBy('name', 'ASC')->get());
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

    public function findByName(string $name, ?int $userId): ?array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('name', $name);
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

    /** 覆盖写入（同名 upsert） */
    public function set(string $name, string $value, bool $isSecret, string $description, ?int $userId, int $writeId): int
    {
        $this->ensureSchema();

        $existing = $this->findByName($name, $userId);
        $packed = [
            'name'        => mb_substr($name, 0, 128),
            'value'       => $isSecret ? Crypto::encrypt($value) : $value,
            'is_secret'   => $isSecret ? 1 : 0,
            'description' => mb_substr($description, 0, 255),
        ];

        if ($existing !== null) {
            $this->db->table(self::TABLE)->where('id', (int) $existing['id'])->update($packed);
            return (int) $existing['id'];
        }

        $packed['user_id'] = $writeId;
        return $this->db->table(self::TABLE)->insert($packed);
    }

    public function delete(int $id): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)->where('id', $id)->delete();
    }

    /** 清空本人全部变量 */
    public function deleteAll(?int $userId): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return (int) $q->delete();
    }

    /**
     * 取解密后的键值对（供工具/子进程注入环境时使用）。
     *
     * @return array<string,string>
     */
    public function resolved(?int $userId): array
    {
        $out = [];
        foreach ($this->list($userId) as $row) {
            $out[(string) $row['name']] = $row['_plain_value'];
        }

        return $out;
    }

    private function hydrate(array $row): array
    {
        $isSecret = (int) ($row['is_secret'] ?? 0) === 1;
        $raw = (string) ($row['value'] ?? '');

        // 敏感值：解密后仅供内部使用，出参不回明文
        $plain = $raw;
        if ($isSecret && $raw !== '') {
            try {
                $plain = Crypto::decrypt($raw);
            } catch (\Throwable $e) {
                $plain = '';
            }
        }

        $row['is_secret'] = $isSecret;
        $row['has_value'] = $raw !== '';
        $row['value'] = $isSecret ? '' : $raw;
        $row['_plain_value'] = $plain;

        return $row;
    }
}
