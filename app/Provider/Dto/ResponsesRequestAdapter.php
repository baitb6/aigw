<?php

declare(strict_types=1);

namespace App\Provider\Dto;

use App\Support\ResponsesInclude;
use App\Support\ToolCalls;
use InvalidArgumentException;

/** 将 OpenAI Responses API 请求/响应转换为网关现有的 Chat Completions 内部形态。 */
final class ResponsesRequestAdapter
{
    /**
     * @return array{
     *     request: ChatRequest,
     *     input_messages: array,
     *     instructions: string,
     *     metadata: array,
     *     parallel_tool_calls: bool,
     *     store: bool,
     *     background: bool,
     *     include: list<string>,
     *     previous_response_id: string
     * }
     */
    public static function fromArray(array $payload): array
    {
        if (!isset($payload['model']) || !is_string($payload['model']) || trim($payload['model']) === '') {
            throw new InvalidArgumentException('缺少 model 参数');
        }
        $model = trim($payload['model']);
        if (!array_key_exists('input', $payload)) {
            throw new InvalidArgumentException('缺少 input 参数');
        }
        self::validateParameters($payload);

        $messages = self::messagesFromInput($payload['input']);
        if (array_key_exists('instructions', $payload) && !is_string($payload['instructions'])) {
            throw new InvalidArgumentException('instructions 必须是字符串');
        }
        $instructions = trim((string) ($payload['instructions'] ?? ''));
        // ★ 指令与请求绑定、不随 response 继承：单独留存，落库时**不得**混进
        //   context_messages，否则 previous_response_id 链路会把上一次的指令带下来。
        $inputMessages = $messages;
        if ($instructions !== '') {
            array_unshift($messages, ['role' => 'system', 'content' => $instructions]);
        }
        if ($messages === []) {
            throw new InvalidArgumentException('input 必须包含至少一条文本消息');
        }

        $chatPayload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => $payload['stream'] ?? false,
        ];
        foreach (['temperature', 'top_p'] as $key) {
            if (array_key_exists($key, $payload)) {
                $chatPayload[$key] = $payload[$key];
            }
        }
        if (array_key_exists('max_output_tokens', $payload)) {
            $chatPayload['max_tokens'] = $payload['max_output_tokens'];
        }

        $tools = self::tools($payload['tools'] ?? []);
        if ($tools !== []) {
            $chatPayload['tools'] = $tools;
        }
        $toolChoice = self::toolChoice($payload['tool_choice'] ?? null);
        if ($toolChoice !== null) {
            $chatPayload['tool_choice'] = $toolChoice;
        }
        if (array_key_exists('parallel_tool_calls', $payload)) {
            $chatPayload['parallel_tool_calls'] = $payload['parallel_tool_calls'];
        }

        $metadata = $payload['metadata'] ?? [];
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            throw new InvalidArgumentException('metadata 必须是对象');
        }

        // ── 生命周期字段：这里只做形状校验，落库/worker 编排交给 ResponsesService ──
        $store = $payload['store'] ?? true;
        $background = $payload['background'] ?? false;
        $include = ResponsesInclude::validate($payload['include'] ?? null);

        $previousResponseId = '';
        if (array_key_exists('previous_response_id', $payload) && $payload['previous_response_id'] !== null) {
            if (!is_string($payload['previous_response_id'])) {
                throw new InvalidArgumentException('previous_response_id 必须是字符串');
            }
            $previousResponseId = trim($payload['previous_response_id']);
        }

        // 语义护栏（对齐 OpenAI）：background 必须可检索，故 store 不能为 false
        if ($background && $store === false) {
            throw new InvalidArgumentException('background=true 时 store 不能为 false（后台结果需要可检索）');
        }

        return [
            'request'              => ChatRequest::fromArray($chatPayload),
            'input_messages'       => $inputMessages,
            'instructions'         => $instructions,
            'metadata'             => $metadata,
            'parallel_tool_calls'  => $payload['parallel_tool_calls'] ?? true,
            'store'                => $store,
            'background'           => $background,
            'include'              => $include,
            'previous_response_id' => $previousResponseId,
        ];
    }

    /**
     * @param array<int, array> $toolCalls
     * @param array{input_tokens:int,output_tokens:int,total_tokens:int} $usage
     */
    public static function response(
        string $responseId,
        string $model,
        string $text,
        array $toolCalls,
        string $finishReason,
        array $usage,
        array $metadata = [],
        ?array $error = null,
        bool $parallelToolCalls = true,
    ): array {
        $status = $error !== null ? 'failed' : ($finishReason === 'length' ? 'incomplete' : 'completed');
        $output = [];
        if ($text !== '') {
            $output[] = self::messageItem($responseId, $text, $status === 'completed' ? 'completed' : 'incomplete');
        }
        foreach (self::functionCallItems($responseId, $toolCalls) as $item) {
            $output[] = $item;
        }

        $response = [
            'id' => $responseId,
            'object' => 'response',
            'created_at' => time(),
            'status' => $status,
            'error' => $error,
            'incomplete_details' => $status === 'incomplete' ? ['reason' => 'max_output_tokens'] : null,
            'model' => $model,
            'output' => $output,
            'parallel_tool_calls' => $parallelToolCalls,
            'metadata' => $metadata,
            'usage' => $usage,
        ];
        return $response;
    }

    public static function messageItem(string $responseId, string $text, string $status = 'completed'): array
    {
        return [
            'id' => 'msg_' . self::idSuffix($responseId, 0),
            'type' => 'message',
            'status' => $status,
            'role' => 'assistant',
            'content' => [[
                'type' => 'output_text',
                'text' => $text,
                'annotations' => [],
            ]],
        ];
    }

    /** @param array<int, array> $toolCalls */
    public static function functionCallItems(string $responseId, array $toolCalls): array
    {
        $items = [];
        foreach (ToolCalls::mergeDeltas($toolCalls) as $index => $call) {
            $function = $call['function'] ?? $call['function_call'] ?? null;
            if (!is_array($function) || trim((string) ($function['name'] ?? '')) === '') {
                continue;
            }
            $arguments = $function['arguments'] ?? $function['partial_arguments'] ?? '';
            if (is_array($arguments)) {
                $arguments = json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $items[] = [
                'id' => 'fc_' . self::idSuffix($responseId, (int) $index + 1),
                'type' => 'function_call',
                'status' => 'completed',
                'arguments' => (string) $arguments,
                'call_id' => (string) ($call['id'] ?? ('call_' . self::idSuffix($responseId, (int) $index + 1))),
                'name' => (string) $function['name'],
            ];
        }
        return $items;
    }

    public static function event(string $type, int $sequenceNumber, array $fields = []): array
    {
        return ['type' => $type, 'sequence_number' => $sequenceNumber] + $fields;
    }

    private static function validateParameters(array $payload): void
    {
        foreach (['stream', 'parallel_tool_calls', 'store', 'background'] as $key) {
            if (array_key_exists($key, $payload) && !is_bool($payload[$key])) {
                throw new InvalidArgumentException($key . ' 必须是布尔值');
            }
        }
        foreach (['temperature' => [0.0, 2.0], 'top_p' => [0.0, 1.0]] as $key => [$min, $max]) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            if (!is_int($payload[$key]) && !is_float($payload[$key])) {
                throw new InvalidArgumentException($key . ' 必须是数字');
            }
            $value = (float) $payload[$key];
            if (!is_finite($value) || $value < $min || $value > $max) {
                throw new InvalidArgumentException($key . " 必须在 {$min} 到 {$max} 之间");
            }
        }
        if (array_key_exists('max_output_tokens', $payload)
            && (!is_int($payload['max_output_tokens']) || $payload['max_output_tokens'] <= 0)) {
            throw new InvalidArgumentException('max_output_tokens 必须是正整数');
        }
    }

    private static function messagesFromInput(mixed $input): array
    {
        if (is_string($input)) {
            return trim($input) === '' ? [] : [['role' => 'user', 'content' => $input]];
        }
        if (!is_array($input)) {
            throw new InvalidArgumentException('input 必须是字符串或输入项数组');
        }

        $messages = [];
        $functionCallIds = [];
        foreach ($input as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('input 中包含无效输入项');
            }
            $type = (string) ($item['type'] ?? 'message');
            if ($type === 'function_call') {
                $callId = self::requiredString($item, 'call_id', 'function_call 缺少 call_id');
                $name = self::requiredString($item, 'name', 'function_call 缺少 name');
                $arguments = $item['arguments'] ?? '';
                if (is_array($arguments)) {
                    $arguments = json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if (!is_string($arguments)) {
                    throw new InvalidArgumentException('function_call arguments 必须是字符串或对象');
                }
                $messages[] = [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => $callId,
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => $arguments],
                    ]],
                ];
                $functionCallIds[$callId] = true;
                continue;
            }
            if ($type === 'function_call_output') {
                $callId = self::requiredString($item, 'call_id', 'function_call_output 缺少 call_id');
                if (!isset($functionCallIds[$callId])) {
                    throw new InvalidArgumentException('function_call_output 找不到对应的 function_call：' . $callId);
                }
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => self::textContent($item['output'] ?? ''),
                ];
                continue;
            }
            if ($type !== 'message') {
                // 内置工具的 output item（web_search_call 等）通常由客户端在多轮回放时原样带回，
                // 而网关并不产生这类条目 —— 点名说明，免得调用方以为是网关「吞」了它。
                $hint = in_array($type, [
                    'web_search_call', 'file_search_call', 'computer_call',
                    'code_interpreter_call', 'image_generation_call', 'reasoning',
                ], true)
                    ? '（该类型是内置工具/推理的 output item，网关不产生此类条目，无需回放）'
                    : '';
                throw new InvalidArgumentException("暂不支持 input item 类型「{$type}」{$hint}；当前支持 message / function_call / function_call_output");
            }

            $role = (string) ($item['role'] ?? 'user');
            if (!in_array($role, ['user', 'assistant', 'system', 'developer'], true)) {
                throw new InvalidArgumentException('不支持的消息角色：' . $role);
            }
            $messages[] = [
                'role' => $role === 'developer' ? 'system' : $role,
                'content' => self::contentParts($item['content'] ?? ''),
            ];
        }
        return $messages;
    }

    /** data URL 的 base64 文本长度上限（20MB 文本 ≈ 15MB 原始图片，与 OpenAI 单图上限同量级） */
    private const MAX_IMAGE_DATA_CHARS = 20971520;

    private static function contentParts(mixed $content): string|array
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            throw new InvalidArgumentException('消息 content 必须是字符串或内容数组');
        }

        $parts = [];
        foreach ($content as $part) {
            if (!is_array($part)) {
                throw new InvalidArgumentException('消息中包含无效内容项');
            }
            $type = (string) ($part['type'] ?? 'input_text');
            if ($type === 'input_image') {
                $parts[] = self::imagePart($part);
                continue;
            }
            if (!in_array($type, ['input_text', 'output_text', 'text'], true)) {
                throw new InvalidArgumentException(self::contentTypeRejection($type));
            }
            $parts[] = ['type' => 'text', 'text' => (string) ($part['text'] ?? '')];
        }
        return $parts;
    }

    /** 内容块类型的拒绝话术（同样要求「说清缺什么」而不是只说「不支持」）。 */
    private static function contentTypeRejection(string $type): string
    {
        $reason = match ($type) {
            'input_file'  => '网关没有 file_id / 文件上传子系统，请改用 input_image（https 直链或 data URL）传图',
            'input_audio' => '网关不支持音频内容块，请改用 /v1/chat/completions 的音频链路',
            default       => '该类型不在支持范围内',
        };

        return "暂不支持的内容类型「{$type}」：{$reason}。当前支持 input_text / output_text / text / input_image";
    }

    /**
     * Response `input_image` → Chat Completions `image_url` 内容块。
     *
     * 只接受两类来源：`https://` 直链与 `data:image/<png|jpeg|webp|gif>;base64,...`。
     * 刻意拒绝 `file_id`（需要上传文件子系统）与 `http://`：后者既是明文传输，
     * 也是把网关当 SSRF 跳板的常见入口。
     */
    private static function imagePart(array $part): array
    {
        $url = $part['image_url'] ?? null;
        if (!is_string($url) || trim($url) === '') {
            // file_id 形式单独给明确提示，避免调用方以为只是漏填字段
            if (isset($part['file_id'])) {
                throw new InvalidArgumentException('input_image 的 file_id 形式暂不支持，请改用 image_url（https 直链或 data URL）');
            }
            throw new InvalidArgumentException('input_image 缺少 image_url');
        }
        $url = trim($url);

        if (preg_match('#^data:image/(png|jpe?g|webp|gif);base64,#i', $url, $m) === 1) {
            $data = substr($url, strlen($m[0]));
            if ($data === '' || strlen($data) > self::MAX_IMAGE_DATA_CHARS) {
                throw new InvalidArgumentException('input_image 的 data URL 为空或超过 20MB 上限');
            }
            if (base64_decode($data, true) === false) {
                throw new InvalidArgumentException('input_image 的 data URL 不是合法 base64');
            }
        } elseif (preg_match('#^https://#i', $url) !== 1) {
            throw new InvalidArgumentException('input_image 的 image_url 必须是 https 直链或 data:image/...;base64');
        }

        $image = ['url' => $url];
        // detail 只在调用方显式给出时才传：缺省即上游默认（auto），
        // 免得给不认识该字段的 OpenAI 兼容上游平添一个被拒的理由。
        if (array_key_exists('detail', $part)) {
            $detail = (string) $part['detail'];
            if (!in_array($detail, ['auto', 'low', 'high'], true)) {
                throw new InvalidArgumentException('input_image 的 detail 必须是 auto、low 或 high');
            }
            $image['detail'] = $detail;
        }

        return ['type' => 'image_url', 'image_url' => $image];
    }

    private static function textContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        $parts = self::contentParts($content);
        return is_string($parts)
            ? $parts
            : implode('', array_map(static fn(array $part): string => (string) ($part['text'] ?? ''), $parts));
    }

    private static function tools(mixed $tools): array
    {
        if ($tools === null || $tools === []) {
            return [];
        }
        if (!is_array($tools)) {
            throw new InvalidArgumentException('tools 必须是数组');
        }

        $out = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || ($tool['type'] ?? '') !== 'function') {
                $type = is_array($tool) ? (string) ($tool['type'] ?? '') : gettype($tool);
                throw new InvalidArgumentException(self::builtinToolRejection($type));
            }
            $name = trim((string) ($tool['name'] ?? ($tool['function']['name'] ?? '')));
            if ($name === '') {
                throw new InvalidArgumentException('function tool 缺少 name');
            }
            $source = is_array($tool['function'] ?? null) ? $tool['function'] : $tool;
            $function = [
                'name' => $name,
                'parameters' => is_array($source['parameters'] ?? null) ? $source['parameters'] : [],
            ];
            foreach (['description', 'strict'] as $key) {
                if (array_key_exists($key, $source)) {
                    $function[$key] = $key === 'strict' ? (bool) $source[$key] : (string) $source[$key];
                }
            }
            $out[] = ['type' => 'function', 'function' => $function];
        }
        return $out;
    }

    /**
     * 非 function 内置工具的拒绝话术：**说清为什么没有、能怎么替代**。
     *
     * Responses 的 `file_search` / `computer_use_preview` / `code_interpreter` /
     * `web_search` 都要求网关自己具备对应子系统（文件向量库、远程桌面、代码沙箱、
     * 联网检索）。这些都不是「顺手加几行」能补的，与其假装接受再静默忽略，
     * 不如在参数校验阶段就给出可操作的报错。
     */
    private static function builtinToolRejection(string $type): string
    {
        $reason = match (true) {
            str_starts_with($type, 'web_search')    => '网关暂无服务端联网检索子系统，可改用 type=function 的检索工具由客户端执行',
            str_starts_with($type, 'file_search')   => '网关暂无文件上传与向量检索子系统',
            str_starts_with($type, 'computer')      => '网关没有可托管的远程桌面/浏览器执行环境',
            str_starts_with($type, 'code_interpreter') => '网关没有代码执行沙箱',
            $type === 'image_generation'            => '网关不代理服务端图片生成',
            default                                 => '该类型不在支持范围内',
        };

        return "内置工具「{$type}」暂不支持：{$reason}。当前仅支持 type=function（由客户端执行）的工具";
    }

    private static function toolChoice(mixed $choice): mixed
    {
        if ($choice === null) {
            return null;
        }
        if (is_string($choice)) {
            if (!in_array($choice, ['auto', 'none', 'required'], true)) {
                throw new InvalidArgumentException('不支持的 tool_choice：' . $choice);
            }
            return $choice;
        }
        if (!is_array($choice) || ($choice['type'] ?? '') !== 'function') {
            throw new InvalidArgumentException('当前仅支持 function 类型的 tool_choice');
        }
        $name = trim((string) ($choice['name'] ?? ($choice['function']['name'] ?? '')));
        if ($name === '') {
            throw new InvalidArgumentException('function tool_choice 缺少 name');
        }
        return ['type' => 'function', 'function' => ['name' => $name]];
    }

    private static function requiredString(array $item, string $key, string $message): string
    {
        if (!isset($item[$key]) || !is_string($item[$key]) || trim($item[$key]) === '') {
            throw new InvalidArgumentException($message);
        }
        return trim($item[$key]);
    }

    private static function idSuffix(string $responseId, int $index): string
    {
        return substr(hash('sha256', $responseId . ':' . $index), 0, 24);
    }
}
