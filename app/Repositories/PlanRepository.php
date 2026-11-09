<?php
/**
 * 计划仓储（对应 aigw_plan）
 *
 * 参考 Operit 的 Plan Mode：计划按「用户 + 会话」隔离，先生成 DRAFT，
 * 用户确认后置 CONFIRMED，执行完毕置 DONE。
 *
 * ⚠️ 属主隔离：普通用户只能操作自己的行。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class PlanRepository
{
    private const TABLE = 'aigw_plan';

    public function __construct(private Database $db) {}

    public function ensureSchema(): void
    {
        Schema::ensure($this->db);
    }

    /** 取某会话当前活跃计划（最新一条未 DONE 的） */
    public function current(?int $userId, string $sessionKey): ?array
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)
            ->where('session_key', $sessionKey)
            ->where('status', '!=', 'DONE');
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        $row = $q->orderBy('id', 'DESC')->first();
        return $row === null ? null : $this->hydrate($row);
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

    public function create(array $data): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)->insert($this->pack($data));
    }

    public function update(int $id, array $data): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)->where('id', $id)->update($this->pack($data, false));
    }

    public function delete(int $id): int
    {
        $this->ensureSchema();

        return $this->db->table(self::TABLE)->where('id', $id)->delete();
    }

    /** 清理某会话的旧计划（保留最新一条，用于覆盖式保存） */
    public function deleteBySession(?int $userId, string $sessionKey): int
    {
        $this->ensureSchema();

        $q = $this->db->table(self::TABLE)->where('session_key', $sessionKey);
        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->delete();
    }

    /** @param bool $withDefaults false 时只写显式传入的键（用于局部更新） */
    private function pack(array $data, bool $withDefaults = true): array
    {
        $packed = [];

        $fields = [
            'title'       => fn ($v) => mb_substr((string) $v, 0, 255),
            'content'     => fn ($v) => (string) $v,
            'session_key' => fn ($v) => mb_substr((string) $v, 0, 80),
            'source'      => fn ($v) => in_array($v, ['manual', 'ai'], true) ? $v : 'manual',
        ];
        foreach ($fields as $key => $cast) {
            if (array_key_exists($key, $data)) {
                $packed[$key] = $cast($data[$key]);
            } elseif ($withDefaults) {
                $packed[$key] = $key === 'source' ? 'manual' : '';
            }
        }

        if (array_key_exists('status', $data)) {
            $packed['status'] = in_array($data['status'], ['DRAFT', 'CONFIRMED', 'DONE'], true)
                ? $data['status'] : 'DRAFT';
        } elseif ($withDefaults) {
            $packed['status'] = 'DRAFT';
        }

        if (array_key_exists('confirmed_at', $data)) {
            $packed['confirmed_at'] = (int) $data['confirmed_at'];
        }
        if (array_key_exists('user_id', $data)) {
            $packed['user_id'] = (int) $data['user_id'];
        }

        return $packed;
    }

    private function hydrate(array $row): array
    {
        $row['confirmed_at'] = (int) ($row['confirmed_at'] ?? 0);
        return $row;
    }
}
