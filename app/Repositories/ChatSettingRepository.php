<?php
/**
 * 对话增强设置仓储（对应 aigw_chat_setting）
 *
 * 按「用户 + 会话」保存三项请求前改写能力的开关：
 *   · Context Limiter（上下文楼层裁剪）
 *   · Thinking Guidance（思考引导，给非推理模型注入 <think>）
 *   · WorldBook（世界书注入开关）
 *
 * 未保存过时按默认值返回（楼层裁剪默认开、思考引导默认关、世界书默认开），
 * 保证「不配置也能用」，且用户一旦改过就以库里的值为准。
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class ChatSettingRepository
{
    private const TABLE = 'aigw_chat_setting';

    /** 默认设置（与建表默认值一致） */
    public const DEFAULTS = [
        'limiter_enabled'   => true,
        'floor_limit'       => 5,
        'thinking_enabled'  => false,
        'thinking_lang'     => 'zh',
        'worldbook_enabled' => true,
    ];

    /** 允许的楼层档位（对齐 Operit 的 FLOOR_OPTIONS） */
    public const FLOOR_OPTIONS = [3, 5, 8, 10, 15, 20, 30, 50, 100];

    public function __construct(private Database $db) {}

    public function ensureSchema(): void
    {
        Schema::ensure($this->db);
    }

    /**
     * 取某会话的设置；不存在则返回默认值（不落库）。
     */
    public function get(?int $userId, string $sessionKey): array
    {
        $this->ensureSchema();

        $row = $this->db->table(self::TABLE)
            ->where('user_id', $userId ?? 0)
            ->where('session_key', $sessionKey)
            ->first();

        if ($row === null && $sessionKey !== 'default') {
            return $this->get($userId, 'default');
        }
        return $row === null ? self::DEFAULTS : $this->hydrate($row);
    }

    /**
     * 局部保存设置（仅写显式传入的键）。
     *
     * @param array<string,mixed> $data
     */
    public function save(?int $userId, string $sessionKey, array $data): array
    {
        $this->ensureSchema();

        $owner = $userId ?? 0;
        $row = $this->db->table(self::TABLE)
            ->where('user_id', $owner)
            ->where('session_key', $sessionKey)
            ->first();

        $packed = $this->pack($data);

        if ($row === null) {
            $this->db->table(self::TABLE)->insert($packed + $this->pack($this->get($userId, $sessionKey)) + [
                'user_id'     => $owner,
                'session_key' => $sessionKey,
            ]);
        } elseif ($packed !== []) {
            $this->db->table(self::TABLE)
                ->where('user_id', $owner)
                ->where('session_key', $sessionKey)
                ->update($packed);
        }

        return $this->get($userId, $sessionKey);
    }

    /** 归一化入参：只保留白名单字段并做类型/范围钳制 */
    private function pack(array $data): array
    {
        $out = [];

        if (array_key_exists('limiter_enabled', $data)) {
            $out['limiter_enabled'] = !empty($data['limiter_enabled']) ? 1 : 0;
        }
        if (array_key_exists('floor_limit', $data)) {
            $out['floor_limit'] = $this->clampFloor((int) $data['floor_limit']);
        }
        if (array_key_exists('thinking_enabled', $data)) {
            $out['thinking_enabled'] = !empty($data['thinking_enabled']) ? 1 : 0;
        }
        if (array_key_exists('thinking_lang', $data)) {
            $out['thinking_lang'] = ((string) $data['thinking_lang']) === 'en' ? 'en' : 'zh';
        }
        if (array_key_exists('worldbook_enabled', $data)) {
            $out['worldbook_enabled'] = !empty($data['worldbook_enabled']) ? 1 : 0;
        }

        return $out;
    }

    /** 楼层数钳制到合法区间（1 ~ 100） */
    private function clampFloor(int $n): int
    {
        if ($n < 1) {
            return self::DEFAULTS['floor_limit'];
        }
        return min($n, 100);
    }

    private function hydrate(array $row): array
    {
        return [
            'limiter_enabled'   => (bool) ($row['limiter_enabled'] ?? true),
            'floor_limit'       => $this->clampFloor((int) ($row['floor_limit'] ?? 5)),
            'thinking_enabled'  => (bool) ($row['thinking_enabled'] ?? false),
            'thinking_lang'     => ((string) ($row['thinking_lang'] ?? 'zh')) === 'en' ? 'en' : 'zh',
            'worldbook_enabled' => (bool) ($row['worldbook_enabled'] ?? true),
        ];
    }
}
