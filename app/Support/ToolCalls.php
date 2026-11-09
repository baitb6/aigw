<?php
/**
 * 工具调用（tool_calls）**增量分片 → 完整调用**的合并。
 *
 * ── 为什么必须有它（2026-10-04 实测血案：自定义供应商「无法调用工具」）──
 * OpenAI 兼容上游在**流式**下不是「一帧一个完整工具调用」，而是把一次调用
 * 拆成 N 帧增量：
 *
 *   data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_00_x","type":"function",
 *                     "function":{"arguments":"","name":"list_dir"}}]}}]}
 *   data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{"}}]}}]}
 *   data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"\""}}]}}]}
 *   …（一次调用实测 10 帧，每帧只带参数的一个片段）
 *
 * 流式下原样透传是**对的**（客户端按 `index` 自行累积，OpenAI 就这么定义的）。
 * 但**非流式**响应必须给出「一次调用一条完整记录」：Provider 侧用
 * `array_merge` 把各帧分片攒进 `Chunk::$toolCalls`，若直接归一化下发，
 * 客户端拿到的就是 10 条**残缺调用**：
 *
 *   tool_calls[0] = {index:0, id:"call_00_x", function:{name:"list_dir"}}       ← 没有参数
 *   tool_calls[1] = {index:0, function:{arguments:"{"}}                        ← 没有 id/name
 *   tool_calls[2] = {index:0, function:{arguments:"\""}}
 *   … 共 10 条，arguments 被劈成 10 段，任何客户端都无法反序列化
 *
 * 后果：客户端（OpenAI SDK / Codex / Claude Code / Dify 等）看到一堆同名 index、
 * 无 name、参数不完整的"工具调用"，只能判定为无法执行 —— 表现为**模型调不出工具**，
 * 而调用日志是 SUCCESS、finish_reason=tool_calls，**看起来一切正常**。
 *
 * 同一形状还出现在 Trae（私有协议，字段名是 `function_call`，
 * 第 1 帧带 id/name/空参数、第 2 帧带整段参数且 id/name 为空）。
 *
 * ── 合并规则 ─────────────────────────────────────────────────
 * 1. **按 `index` 分组**（OpenAI 规范里 index 就是"第几次调用"，并行调用时是 0/1/2…）；
 * 2. `id` / `type` / `function.name` 取**首个非空值**（增量帧里它们只在首帧出现，
 *    不能拼接：拼出来的名字没人认识）；
 * 3. `function.arguments` **按帧序拼接**（增量语义）；同时兼容两类真实上游：
 *    · 增量分片：逐段追加（本次实测的自定义供应商 / Trae）；
 *    · 累积快照：后一帧是"到目前为止的完整参数"，比已累积的更长且以它开头 → 整体替换。
 *    两种语义都不做判断地硬拼，会让快照型上游输出 `{"path": "."}{"path": "."}` 这种坏 JSON。
 * 4. **无 `index` 的分片帧**（少数实现省略 index）：只有参数、没有 id/name 时，
 *    追加到**上一条**调用；带 id/name 的（完整调用）自成一条。
 *    旧行为是每条各占一条 → 同样是"调不出来"。
 *
 * 本函数**幂等**：合并过的完整调用再跑一次结果不变 —— 因此流式路径
 * （每帧只有一个分片）调用它属于无害透传，非流式路径（整串分片）才真正生效。
 */

declare(strict_types=1);

namespace App\Support;

final class ToolCalls
{
    /**
     * 把增量分片形态的 tool_calls 合并成完整调用。
     *
     * 入参为 OpenAiSse / TraeProvider 累积的原始分片数组
     * （`function` 与 `function_call` 两种键名都接受）；
     * 返回「一个 index 一条、arguments 已合并」的列表，键名沿用首次出现的那个。
     *
     * @param array<int, mixed> $calls
     * @return array<int, array<string, mixed>>
     */
    public static function mergeDeltas(array $calls): array
    {
        $merged = [];      // 合并结果（保序）
        $positions = [];   // index => 在 $merged 中的下标
        $lastPos = null;   // 上一条调用（供无 index 的分片帧追加）
        $auto = 0;         // 无 index 时的自增序号

        foreach ($calls as $call) {
            if (!is_array($call)) {
                continue;
            }

            // 兼容 OpenAI 的 `function` 与 Trae 的 `function_call`
            $key = isset($call['function']) && is_array($call['function'])
                ? 'function'
                : (isset($call['function_call']) && is_array($call['function_call']) ? 'function_call' : null);
            if ($key === null) {
                continue; // 没有函数体：既不是调用也不是分片，丢掉（与归一化口径一致）
            }
            $fn = $call[$key];

            $hasIndex = isset($call['index']) && is_numeric($call['index']);
            $identifies = self::nonEmptyString($call['id'] ?? null) !== ''
                || self::nonEmptyString($fn['name'] ?? null) !== '';

            // ── 定位这条分片归属哪一次调用 ──────────────────────────────
            if ($hasIndex) {
                $index = (int) $call['index'];
                if (!array_key_exists($index, $positions)) {
                    $merged[] = ['index' => $index, $key => []];
                    $positions[$index] = count($merged) - 1;
                }
                $pos = $positions[$index];
            } elseif (!$identifies && $lastPos !== null) {
                // 无 index、且自身不携带 id/name → 只能是上一条调用的参数增量
                $pos = $lastPos;
            } else {
                while (array_key_exists($auto, $positions)) {
                    $auto++;
                }
                $merged[] = ['index' => $auto, $key => []];
                $positions[$auto] = count($merged) - 1;
                $pos = $positions[$auto];
                $auto++;
            }
            $lastPos = $pos;

            // ── 合并字段 ────────────────────────────────────────────────
            $id = self::nonEmptyString($call['id'] ?? null);
            if ($id !== '' && self::nonEmptyString($merged[$pos]['id'] ?? null) === '') {
                $merged[$pos]['id'] = $id;
            }
            $type = self::nonEmptyString($call['type'] ?? null);
            if ($type !== '' && self::nonEmptyString($merged[$pos]['type'] ?? null) === '') {
                $merged[$pos]['type'] = $type;
            }

            $name = self::nonEmptyString($fn['name'] ?? null);
            if ($name !== '' && self::nonEmptyString($merged[$pos][$key]['name'] ?? null) === '') {
                $merged[$pos][$key]['name'] = $name;
            }

            // 参数：`arguments` 是标准增量；`partial_arguments`（Trae 自有字段）同样可能承载分片。
            // 两者都在时按出现顺序各拼一次，避免漏掉任一侧的内容。
            foreach (['arguments', 'partial_arguments'] as $argKey) {
                $fragment = self::asFragment($fn[$argKey] ?? null);
                if ($fragment === '') {
                    continue;
                }
                $current = $merged[$pos][$key][$argKey] ?? '';
                $merged[$pos][$key][$argKey] = self::mergeFragment(
                    is_string($current) ? $current : '',
                    $fragment
                );
            }
        }

        // 清理：空数组的函数体、空字符串字段都不下发（客户端会把它当成非法调用）
        $out = [];
        foreach ($merged as $item) {
            $body = $item['function'] ?? $item['function_call'] ?? [];
            $body = array_filter($body, static fn($v) => $v !== '' && $v !== null && $v !== []);
            if ($body === []) {
                continue; // 只有 index 没有函数体 → 无法执行，丢掉
            }
            $item['function'] = $body;
            unset($item['function_call']);
            $item = array_filter($item, static fn($v) => $v !== '' && $v !== null);
            $out[] = $item;
        }

        return $out;
    }

    /** 参数分片归一：字符串直接用；数组形态（少数上游）转 JSON 字符串 */
    private static function asFragment(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value) && $value !== []) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded === false ? '' : $encoded;
        }
        return '';
    }

    /**
     * 拼接参数分片：兼容「增量」与「累积快照」两种上游语义。
     *
     * - 已累积为空 → 直接取分片；
     * - 分片比已累积更长、且以已累积内容开头 → 判定为快照，整体替换；
     * - 其余 → 视作增量分片，追加。
     *
     * ⚠️ 不做「分片与已累积相同就跳过」的去重：增量语义下连续两个相同分片
     *   是**合法**的（例如 JSON 里连续两个引号），去重会把参数改坏。
     */
    private static function mergeFragment(string $acc, string $fragment): string
    {
        if ($acc === '') {
            return $fragment;
        }
        if (strlen($fragment) > strlen($acc) && str_starts_with($fragment, $acc)) {
            return $fragment;
        }
        return $acc . $fragment;
    }

    private static function nonEmptyString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * 收尾原因（finish_reason）归一：**本次确实调用了工具时，必须回 `tool_calls`**。
     *
     * OpenAI 规范里 `finish_reason` 是客户端判断"这轮到底是什么结果"的依据，
     * 工具调用的正确取值就是 `tool_calls`。而部分上游（实测 Trae 私有协议：
     * done 事件不带 `finish_reason`，被兜底成 `stop`）会在**已经下发工具帧之后**
     * 报 `stop`/空 —— 严格按规范实现的客户端（Trae Work / Codex / 各 SDK）
     * 会因此认定"这轮只是普通回答"，把前面的 tool_calls 丢掉，
     * 表现为「模型调用了却当没调用」。
     *
     * 只改「空」与「stop」两种：`length`（撞 max_tokens）、`content_filter`
     * 是**真实**的生成结果，不能被工具调用掩盖。
     *
     * 同类归一已在 `ZcodeProvider`（Anthropic 通道收口）里存在，这里提到公共处，
     * 让所有通道口径一致。
     */
    public static function finishReason(string $reason, array $toolCalls): string
    {
        if ($toolCalls === []) {
            return $reason;
        }
        if ($reason === '' || $reason === 'stop') {
            return 'tool_calls';
        }
        return $reason;
    }
}
