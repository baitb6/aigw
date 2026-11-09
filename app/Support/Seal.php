<?php
/**
 * 思维封条：对注入正文取稳定指纹，用于检测「注入后被改写」。
 *
 * 设计取舍（源自 pojia-next 的封条思路，按网关场景简化）：
 *  - 口径先归一：CRLF/CR 统一为 LF、去掉首尾空白 —— 避免同一份思维因编辑器换行
 *    差异算出两个封条，导致误报「被改写」。
 *  - 只做 SHA256，不做加密：这里要的是「可验证」而不是「不可读」。
 *  - 不吞异常：hash 不会失败，所以本类不写 try/catch，调用方无需求助静默失败。
 */

declare(strict_types=1);

namespace App\Support;

final class Seal
{
    /** 归一化口径：统一换行 + 去首尾空白 */
    public static function normalize(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /** 正文 → 64 位十六进制封条 */
    public static function of(string $text): string
    {
        return hash('sha256', self::normalize($text));
    }

    /** 校验正文与封条是否一致（封条为空视为「未封」→ 不通过） */
    public static function matches(string $text, string $seal): bool
    {
        if ($seal === '') {
            return false;
        }
        return hash_equals($seal, self::of($text));
    }

    /** 短封条，用于列表与诊断展示 */
    public static function short(string $seal, int $len = 16): string
    {
        return $seal === '' ? '—' : substr($seal, 0, $len);
    }
}
