<?php
/**
 * CSV 导出单元格安全处理（CWE-1236 公式注入防护）
 * ------------------------------------------------------------
 * 以 `=` `+` `-` `@` 或制表符 / 回车开头的内容，会被 Excel / WPS /
 * LibreOffice 在打开 CSV 时当作**公式**执行（典型如
 * `=cmd|' /C calc'!A0`、`=HYPERLINK(...)`、`=WEBSERVICE(...)`）。
 * 攻击者只要在「模型名 / User-Agent / 错误信息 / 请求 ID」等字段里塞入
 * 这样的内容，管理员导出 CSV 并打开即可被触发。
 *
 * 处理策略：
 *   1. 对每个单元格判断「去掉前导空白后的首字符」是否落在危险集合；
 *   2. 命中则在最前面加一个单引号 `'`。Excel 会把带前导单引号的内容
 *      当纯文本，且该单引号本身不会显示在单元格里；
 *   3. 纯数字（含 +/- 号、科学计数法）直接放行，避免把正常的负数、
 *      费用、耗时等数值列错误地变成文本。
 *
 * 说明：本类只做「内容前缀」处理，CSV 结构（引号包裹 / 转义）仍由
 * fputcsv() 负责，二者互不冲突。
 */

declare(strict_types=1);

namespace App\Support;

final class Csv
{
    /** 会被表格软件当作公式起始的危险前缀（ltrim 后判定） */
    private const RISKY_PREFIXES = ['=', '+', '-', '@'];

    /**
     * 把单元格值转为安全的 CSV 文本
     */
    public static function cell(mixed $value): string
    {
        $s = (string) $value;
        if ($s === '') {
            return $s;
        }

        // 纯数字（含正负号、小数、科学计数法）不是公式，原样放行
        if (is_numeric($s)) {
            return $s;
        }

        // 首字符本身就是制表符 / 回车时也需转义：部分表格软件会先剥掉前导
        // 空白再解析公式，若只 trim 后判断就会漏掉 "\t=1+1" 这类绕过
        $first = $s[0];
        if ($first === "\t" || $first === "\r" || $first === "\n") {
            return "'" . $s;
        }

        $probe = ltrim($s);
        if ($probe === '') {
            return $s;
        }

        if (in_array($probe[0], self::RISKY_PREFIXES, true)) {
            return "'" . $s;
        }

        return $s;
    }

    /**
     * 批量处理一整行
     *
     * @param  array<int,mixed> $cells
     * @return array<int,string>
     */
    public static function row(array $cells): array
    {
        return array_map([self::class, 'cell'], $cells);
    }
}
