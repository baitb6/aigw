<?php
/**
 * 思维注入块的锚点与「正文脱敏」工具
 * ============================================================================
 * 背景：调用日志的「请求详情」会把改写后的 messages 原文落库（`log_request_body`
 * 打开时），而注入块里就是完整思维正文。这份正文不该出现在日志/详情/导出里 ——
 * 它既是体量最大的一段文本（动辄 8~10KB，把详情框刷满），也是操作者自己的内部配置。
 *
 * 于是约定：**留痕里的注入块只保留名称，正文换成标签**
 *
 *     <!-- ops-notes:begin -->
 *     【系统内置破甲】
 *     <!-- ops-notes:end -->
 *
 * 锚点成对保留 —— 排查时「这次到底注没注入」靠的就是锚点在不在，标签自带是哪一份。
 *
 * 两个落点（必须同时有，见 docs/用户行为审计与内容留痕.md）：
 *   ① 写入侧 `LogRepository::record()`：新数据的 request_body 天生不带正文；
 *   ② 读取侧 `LogController`：历史数据（改造前落的库）在读出来时替换，不用等清理。
 * 另有 `tools/redact-log-bodies.php` 可把历史行真正改写掉（默认试运行）。
 *
 * ⚠️ 脱敏只作用于**日志里的文本副本**，不改动发往上游的 messages —— 注入本身不受影响。
 * ============================================================================
 */

declare(strict_types=1);

namespace App\Support;

final class PersonaMark
{
    /**
     * 注入块锚点。刻意不带 "PERSONA / 思维" 字样、也不写 id/名称/封条 ——
     * 实测模型会把「这是一个被注入的人格」这类自述标记当成"有人往我提示词里塞东西"，
     * 然后在回答里原文引用并宣称不受约束。中性标记是更稳的做法。
     */
    public const BEGIN = '<!-- ops-notes:begin -->';
    public const END   = '<!-- ops-notes:end -->';

    /** 旧版本用过的标记：剥离时要一并清掉，否则历史消息里的旧块会残留堆叠 */
    public const LEGACY_BEGIN = '<!-- AIGW-PERSONA:BEGIN';
    public const LEGACY_END   = '<!-- AIGW-PERSONA:END -->';

    /** 块内容的匹配式（新老标记都认，非贪婪，逐块替换） */
    private const PATTERN = '/(' . '<!-- ops-notes:begin -->' . '|' . '<!-- AIGW-PERSONA:BEGIN' . ')'
        . '(.*?)'
        . '(' . '<!-- ops-notes:end -->' . '|' . '<!-- AIGW-PERSONA:END -->' . ')'
        . '\s*/s';

    /** 文本里是否已有注入块（新标记或旧标记） */
    public static function hasBlock(string $text): bool
    {
        return str_contains($text, self::BEGIN) || str_contains($text, self::LEGACY_BEGIN);
    }

    /**
     * 半块的收尾提示。留痕按 `log_content_max_chars` 截断，注入块动辄 8~10KB，
     * 所以库里**大量历史行只有 begin 锚点、没有 end 锚点**（正文把 2000 字额度吃完了）。
     * 这种情形下没法成对替换，只能从 begin 起整段换掉，并说明"后面本来还有"。
     */
    private const TRUNCATED_NOTE = '（以下正文超出留痕上限，未记录）';

    /**
     * 读取侧中性化时的说明。与 TRUNCATED_NOTE 区分开 ——
     * 正文其实完整躺在库里（管理员看得到），只是对当前查看者隐藏。
     * 复用截断说明会让普通用户以为"日志没记全"，是事实错误。
     */
    public const HIDDEN_NOTE = '（思维正文仅管理员可见）';

    /**
     * 文本是否已处于「脱敏态」：每个注入块的正文都只剩一行标签。
     *
     * ⚠️ 不能拿 `strip()` 的结果反推 —— 半块（只有 begin 锚点）也会被 strip 清干净，
     * 于是「带完整正文的半块」会被误判成已脱敏。这里直接看锚点后紧跟的是什么。
     *
     * ⚠️ 标签式必须带 `/u`：`[^】]` 在无 `/u` 时按**字节**匹配，
     * 汉字与 】 的首字节会互相咬住，已脱敏的行会被误判成"还带正文"。
     */
    public static function isRedacted(string $text): bool
    {
        if (!self::hasBlock($text)) {
            return true; // 没有注入块，谈不上需要脱敏
        }

        // 非法 UTF-8（截断留痕可能切断多字节字符）时退化为朴素判定，不因匹配失败而报错
        $pattern = preg_match('//u', $text) === 1
            ? '/^【[^】]{1,80}】/u'
            : '/^【.{1,240}?】/s';

        foreach ([self::BEGIN, self::LEGACY_BEGIN] as $begin) {
            $offset = 0;
            while (($pos = strpos($text, $begin, $offset)) !== false) {
                $tail = ltrim(substr($text, $pos + strlen($begin)), "\r\n");
                if (preg_match($pattern, $tail) !== 1) {
                    return false;
                }
                $offset = $pos + strlen($begin);
            }
        }

        return true;
    }

    /**
     * 按作用域生成标签。管理员作用域（user_id=0）→【系统内置X】；普通用户自建 →【本人自建X】。
     * 名称缺失时退化成带 id 的通用标签，保证「是哪一份」仍可辨认。
     */
    public static function label(string $name, int $scopeUserId = 0, int $personaId = 0): string
    {
        $name = trim($name);
        $prefix = $scopeUserId > 0 ? '本人自建' : '系统内置';

        if ($name === '') {
            return $personaId > 0 ? "【思维 #{$personaId}】" : "【{$prefix}思维】";
        }

        return "【{$prefix}{$name}】";
    }

    /**
     * 把文本里每个注入块的正文替换成标签（锚点保留）。
     *
     * 两种情况：
     *   ① 成对锚点（发往上游的完整 system）→ 只换块内内容；
     *   ② 只有 begin 锚点 → 从 begin 起整段换成标签 + `$truncatedNote`。
     *      半块的成因有两种，靠调用方传入的 `$truncatedNote` 说明：
     *        · 留痕被 `log_content_max_chars` 截断（工具脚本清理历史行）；
     *        · 读取侧中性化时正文被隐藏（普通用户看自己的日志）。
     *      不区分会让后者显示成「超出留痕上限，未记录」——那是事实错误。
     *
     * 幂等：已经是标签态的块再跑一遍结果不变；没有块时原样返回。
     */
    public static function redact(string $text, string $label, ?string $truncatedNote = null): string
    {
        if ($text === '' || !self::hasBlock($text)) {
            return $text;
        }

        $note = $truncatedNote ?? self::TRUNCATED_NOTE;

        $paired = 0;
        $out = preg_replace_callback(self::PATTERN, function () use (&$paired, $label) {
            $paired++;
            return self::BEGIN . "\n" . $label . "\n" . self::END . "\n";
        }, $text);

        $out = (string) $out;

        // 半块：end 锚点不在这段文本里（成因见方法注释，由 $note 说明）。
        // 已经是标签态（begin 后紧跟标签行）时不再动，保证幂等。
        if ($paired === 0) {
            foreach ([self::BEGIN, self::LEGACY_BEGIN] as $begin) {
                $pos = strpos($out, $begin);
                if ($pos === false) {
                    continue;
                }
                $head = substr($out, 0, $pos + strlen($begin));
                $tail = ltrim(substr($out, $pos + strlen($begin)), "\r\n");
                if (str_starts_with($tail, $label)) {
                    continue; // 已脱敏
                }
                $out = $head . "\n" . $label . "\n" . $note . "\n";
                break;
            }
        }

        return $out;
    }

    /**
     * 剥离文本里已存在的注入块（幂等：重复注入不会堆叠）。
     *
     * 与抛弃版不同的是：**连成对锚点一起剥掉**，剥离后不留空行。
     * 只落了开始标记（块被上游/截断切断）时，从开始标记起全丢，
     * 避免半个块流进上游。
     *
     * @return array{0:string,1:int} [剥离后的文本, 剥离掉的块数]
     */
    public static function strip(string $text): array
    {
        $count = 0;
        $clean = preg_replace_callback(self::PATTERN, function () use (&$count) {
            $count++;
            return '';
        }, $text);

        // 只落了开始标记（块被截断）时，从开始标记起全丢
        if ($count === 0) {
            $pos = null;
            foreach ([self::BEGIN, self::LEGACY_BEGIN] as $begin) {
                $p = strpos($text, $begin);
                if ($p !== false && ($pos === null || $p < $pos)) {
                    $pos = $p;
                }
            }
            if ($pos !== null) {
                $clean = substr($text, 0, $pos);
                $count = 1;
            }
        }

        return [trim((string) $clean), $count];
    }
}
