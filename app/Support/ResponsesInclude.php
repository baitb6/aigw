<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Responses API `include` 参数的严格 allowlist。
 *
 * 单独抽成 Support 而不是塞进 Dto 或 Service，是为了让「协议形状校验」
 * 与「数据库生命周期」两侧都能复用，且不产生 Dto → Service 的反向依赖。
 */
final class ResponsesInclude
{
    /**
     * 允许值（对齐 OpenAI Responses 规范）。
     *
     * ⚠️ 本网关当前只产出文本 message 与 function_call 两类 output item，
     *    因此除 `message.output_text.logprobs`（依赖上游是否回传 logprobs）外，
     *    其余条目在本实现下**不会产生额外字段**。接受 ≠ 伪造：
     *    这里只做 allowlist 校验（未知值 400），绝不凭空造出结构相似但语义虚构的数据。
     */
    public const ALLOWLIST = [
        'code_interpreter_call.outputs',
        'computer_call_output.output.image_url',
        'file_search_call.results',
        'message.input_image.image_url',
        'message.output_text.logprobs',
        'reasoning.encrypted_content',
        'web_search_call.action.sources',
    ];

    /** @return list<string> */
    public static function validate(mixed $include): array
    {
        if ($include === null) {
            return [];
        }
        if (!is_array($include) || ($include !== [] && !array_is_list($include))) {
            throw new InvalidArgumentException('include 必须是字符串数组');
        }

        $out = [];
        foreach ($include as $value) {
            if (!is_string($value) || !in_array($value, self::ALLOWLIST, true)) {
                throw new InvalidArgumentException(
                    'include 不支持的值：' . (is_string($value) ? $value : gettype($value))
                    . '；允许值：' . implode(', ', self::ALLOWLIST)
                );
            }
            $out[] = $value;
        }

        return array_values(array_unique($out));
    }
}