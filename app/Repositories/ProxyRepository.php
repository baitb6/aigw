<?php
/**
 * 代理设置仓储（对应 LV1/z）
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Crypto;

class ProxyRepository
{
    private const TABLE = 'aigw_proxy_settings';

    private ?array $cache = null;

    public function __construct(private Database $db) {}

    public function current(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $row = $this->db->table(self::TABLE)->where('id', 1)->first();
        if ($row === null) {
            $this->db->statement('INSERT INTO `' . self::TABLE . '` (`id`) VALUES (1)');
            $row = $this->db->table(self::TABLE)->where('id', 1)->first() ?? [];
        }

        $excluded = $row['excluded_provider_ids'] ?? null;
        if (is_string($excluded)) {
            $excluded = json_decode($excluded, true);
        }

        $password = Crypto::decrypt($row['password_enc'] ?? null);

        $this->cache = [
            'enabled'               => (bool) ($row['enabled'] ?? false),
            'host'                  => (string) ($row['host'] ?? ''),
            'port'                  => (int) ($row['port'] ?? 0),
            'username'              => (string) ($row['username'] ?? ''),
            'password'              => (string) ($password ?? ''),
            'excluded_provider_ids' => is_array($excluded) ? $excluded : [],
        ];

        return $this->cache;
    }

    public function update(array $data): void
    {
        $payload = [];

        if (array_key_exists('enabled', $data)) {
            $payload['enabled'] = $data['enabled'] ? 1 : 0;
        }
        if (array_key_exists('host', $data)) {
            $payload['host'] = (string) $data['host'];
        }
        if (array_key_exists('port', $data)) {
            $payload['port'] = (int) $data['port'];
        }
        if (array_key_exists('username', $data)) {
            $payload['username'] = (string) $data['username'];
        }
        if (array_key_exists('password', $data)) {
            $payload['password_enc'] = Crypto::encrypt((string) $data['password']) ?? '';
        }
        if (array_key_exists('excluded_provider_ids', $data)) {
            $payload['excluded_provider_ids'] = json_encode(array_values((array) $data['excluded_provider_ids']), JSON_UNESCAPED_UNICODE);
        }

        if (empty($payload)) {
            return;
        }

        $existing = $this->db->table(self::TABLE)->where('id', 1)->first();
        if ($existing === null) {
            $this->db->statement('INSERT INTO `' . self::TABLE . '` (`id`) VALUES (1)');
        }

        $this->db->table(self::TABLE)->where('id', 1)->update($payload);
        $this->cache = null;
    }

    /** 对外展示时隐藏密码明文 */
    public function toSafeArray(): array
    {
        $current = $this->current();
        return [
            'enabled'               => $current['enabled'],
            'host'                  => $current['host'],
            'port'                  => $current['port'],
            'username'              => $current['username'],
            'password_set'          => $current['password'] !== '',
            'excluded_provider_ids' => $current['excluded_provider_ids'],
        ];
    }
}