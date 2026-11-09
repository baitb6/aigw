<?php
/**
 * 凭据加密：AES-256-GCM，主密钥来自 .env（AIGW_APP_KEY）
 * 对应原 App 的 AndroidKeyStore 方案，网页版改为服务端密钥
 */

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    private static function key(): string
    {
        $raw = (string) config('app.key', '');
        if ($raw === '') {
            throw new RuntimeException('缺少加密主密钥，请在 .env 中配置 AIGW_APP_KEY');
        }

        // 支持 base64: 前缀或原始字符串，统一派生为 32 字节
        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            if ($decoded !== false && strlen($decoded) >= 32) {
                return substr($decoded, 0, 32);
            }
        }

        return hash('sha256', $raw, true);
    }

    /**
     * 加密明文，返回 base64(iv + tag + ciphertext)
     */
    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

        if ($ciphertext === false) {
            throw new RuntimeException('凭据加密失败');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * 解密 base64(iv + tag + ciphertext)
     */
    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= 12 + self::TAG_LENGTH) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, self::TAG_LENGTH);
        $ciphertext = substr($raw, 12 + self::TAG_LENGTH);

        $plain = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    /**
     * 加解密整个关联数组（用于凭据 JSON）
     */
    public static function encryptArray(array $data): ?string
    {
        return self::encrypt(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function decryptArray(?string $payload): array
    {
        $plain = self::decrypt($payload);
        if ($plain === null) {
            return [];
        }
        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 生成新的主密钥（安装向导用）
     */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }
}