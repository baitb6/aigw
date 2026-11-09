<?php
/**
 * 管理台账号仓储：登录校验、密码修改、登录痕迹
 */

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Schema;

class AdminRepository
{
    private const TABLE = 'aigw_admin';

    /**
     * 账号不存在时的 dummy bcrypt 哈希（时序对齐用，内容随机、无对应口令）。
     * 成本因子与 PASSWORD_DEFAULT 当前默认一致（10）。
     */
    private const DUMMY_HASH = '$2y$10$d7j369NH..foAIoXLKhqrOusE0orrYVsD2A3vt4iOXev2rSJfsDqC';

    private bool $schemaChecked = false;

    public function __construct(private Database $db) {}

    /**
     * 老库升级：幂等补齐 session_epoch 列（改密踢会话，对齐用户端）
     */
    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $this->schemaChecked = true;

        Schema::ensure($this->db);
        Schema::ensureColumns($this->db, self::TABLE, Schema::ADMIN_COLUMNS);
    }

    public function findByUsername(string $username): ?array
    {
        return $this->db->table(self::TABLE)->where('username', $username)->first();
    }

    public function find(int $id): ?array
    {
        return $this->db->table(self::TABLE)->where('id', $id)->first();
    }

    /**
     * 校验账号密码，成功返回记录，失败返回 null
     */
    public function attempt(string $username, string $password): ?array
    {
        $this->ensureSchema();

        $admin = $this->findByUsername($username);
        if ($admin === null) {
            // 时序侧信道修复（审计 L-1）：账号不存在时也执行一次 dummy verify，
            // 抹平「存在与否」的响应耗时差，避免被用于枚举有效管理员账号名。
            password_verify($password, self::DUMMY_HASH);
            return null;
        }

        if (!password_verify($password, (string) $admin['password'])) {
            return null;
        }

        return $admin;
    }

    /**
     * 改密：抬会话纪元（session_epoch + 1），使该账号其他端旧会话立即失效。
     * 本人当前会话由调用方随后 refreshEpoch() 刷新水位，不会被误踢。
     */
    public function updatePassword(int $id, string $plainPassword): void
    {
        $this->ensureSchema();

        $this->db->statement(
            'UPDATE `' . self::TABLE . '` SET `password` = ?, `session_epoch` = `session_epoch` + 1 WHERE `id` = ?',
            [password_hash($plainPassword, PASSWORD_DEFAULT), $id]
        );
    }

    /** 取账号当前会话纪元（改密后由控制器刷新本人会话水位用） */
    public function epoch(int $id): ?int
    {
        try {
            $value = $this->db->scalar(
                'SELECT `session_epoch` FROM `' . self::TABLE . '` WHERE `id` = ?',
                [$id]
            );
            return $value === null ? null : (int) $value;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function touchLogin(int $id, string $ip): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->update([
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ]);
    }

    public function updateProfile(int $id, string $nickname): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->update(['nickname' => $nickname]);
    }
}