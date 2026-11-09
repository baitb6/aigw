<?php
/**
 * 统一对话请求
 */

declare(strict_types=1);

namespace App\Provider\Dto;

use App\Support\JsonSchema;
use App\Support\Logger;
use App\Support\ToolMessageSequence;

class ChatRequest
{
    /** 上游可接受的最小 max_tokens（小于该值会被参数校验拒单） */
    private const MIN_MAX_TOKENS = 16;

    public array $enhancementOriginalMessages = [];
    public array $enhancementResultMessages = [];
    public array $enhancementWorldEntries = [];
    public array $enhancementSources = [];

    /** 跨供应商 fallback 深度：GatewayService::attemptFallback 递归调用 complete 时在克隆上 +1，用于防环 */
    public int $fallbackDepth = 0;

    public function __construct(
        public string $model,
        public array $messages,
        public bool $stream = false,
        public ?float $temperature = null,
        public ?float $topP = null,
        public ?int $maxTokens = null,
        public array $tools = [],
        public ?string $accountUid = null,
        /** @var array<string, mixed> 其余透传字段 */
        public array $extra = [],
        /** 客户端来源标识：workbuddy|workbuddy_intl|codebuddy_cli|codebuddy_cn_ide */
        public string $source = '',
        /** 会话粘性标识：同会话绑定同一账号 */
        public string $sessionKey = '',
        /**
         * 助手绑定的世界书条目 id 白名单（客户端随请求下发）。
         *
         * 非空时：本轮世界书**只激活这些 id**（其余即使命中关键词也不注入），
         * 用于实现「切到某助手只激活它绑定的世界书」。为空则沿用全局开关的全部
         * 启用条目。**不参与上游透传**——仅网关内部消费。
         *
         * @var list<int>
         */
        public array $worldbookIds = [],
        /**
         * 客户端提示词补丁残留检测结果（由 GatewayService 在注入前回填）。
         *
         * 非 null 且 `found=true` 表示：网关这次**没有**注入任何思维，
         * 但客户端带来的 system 里已有外部补丁写死的"思维"内容。
         * 用于把"到底谁注入的"回传给控制台，避免用户误判成网关没关干净。
         *
         * @var array{found:bool, markers:list<string>, bytes:int, preview:string}|null
         */
        public ?array $clientResidue = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        $known = ['model', 'messages', 'stream', 'temperature', 'top_p', 'max_tokens', 'tools', 'custom', 'extra', 'worldbook_ids'];
        $extra = [];
        foreach ($payload as $key => $value) {
            if (!in_array($key, $known, true)) {
                $extra[$key] = $value;
            }
        }

        // 世界书白名单：只接受正整数 id（客户端可能传字符串数组），非法项丢弃。
        $worldbookIds = [];
        $rawWorldbook = $payload['worldbook_ids'] ?? null;
        if (is_array($rawWorldbook)) {
            foreach ($rawWorldbook as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $worldbookIds[] = (int) $id;
                }
            }
        }

        return new self(
            model: (string) ($payload['model'] ?? ''),
            messages: is_array($payload['messages'] ?? null) ? $payload['messages'] : [],
            stream: (bool) ($payload['stream'] ?? false),
            temperature: isset($payload['temperature']) ? (float) $payload['temperature'] : null,
            topP: isset($payload['top_p']) ? (float) $payload['top_p'] : null,
            maxTokens: isset($payload['max_tokens']) ? (int) $payload['max_tokens'] : null,
            // ⚠️ 必须归一化工具 Schema：json_decode(...,true) 把 JSON 空对象 `{}` 降级成 `[]`，
            // 再编码出去 `"properties":[]` 在 JSON Schema 里非法，上游直接拒单
            // （实测报错 tools.N.function.parameters/properties: invalid JSON Schema）。
            tools: is_array($payload['tools'] ?? null) ? JsonSchema::normalizeTools($payload['tools']) : [],
            accountUid: isset($payload['custom']) && is_string($payload['custom']) ? $payload['custom'] : null,
            extra: array_merge($extra, is_array($payload['extra'] ?? null) ? $payload['extra'] : []),
            worldbookIds: $worldbookIds,
        );
    }

    /** system 提示词拼接（部分上游不支持 system 角色） */
    public function systemPrompt(): string
    {
        $parts = [];
        foreach ($this->messages as $message) {
            if (($message['role'] ?? '') === 'system') {
                $content = $message['content'] ?? '';
                $parts[] = is_array($content) ? $this->flattenContent($content) : (string) $content;
            }
        }
        return implode("\n\n", $parts);
    }

    public function flattenContent($content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $out = '';
        foreach ($content as $part) {
            if (is_array($part) && isset($part['text'])) {
                $out .= $part['text'];
            }
        }
        return $out;
    }

    /**
     * 注入提示词模板（system 内容），按模式改写 $messages
     *
     *  - replace：整体替换已存在的 system 内容（不存在则前置插入）；保留用户 system 之外的对话
     *  - prepend：在已有 system 前插入模板（无 system 则新建在最前）
     *  - append ：在末尾追加一条 system（无论是否已有 system）
     *
     * @param string $text 模板正文
     * @param string $mode prepend|append|replace
     */
    public function injectSystem(string $text, string $mode = 'prepend'): void
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return;
        }

        if ($mode === 'replace') {
            $replaced = false;
            $next = [];
            foreach ($this->messages as $message) {
                if (!$replaced && ($message['role'] ?? '') === 'system') {
                    $next[] = ['role' => 'system', 'content' => $text];
                    $replaced = true;
                    continue;
                }
                $next[] = $message;
            }
            if (!$replaced) {
                array_unshift($next, ['role' => 'system', 'content' => $text]);
            }
            $this->messages = $next;
            return;
        }

        if ($mode === 'append') {
            $this->messages[] = ['role' => 'system', 'content' => $text];
            return;
        }

        // prepend（默认）
        array_unshift($this->messages, ['role' => 'system', 'content' => $text]);
    }

    /** 转为上游 OpenAI 兼容格式 */
    public function toUpstreamPayload(array $overrides = []): array
    {
        // 出站前修工具消息序列：`assistant.tool_calls` 与 `tool` 响应必须一一配对，
        // 否则上游直接拒单（insufficient tool messages following tool_calls message）。
        // 畸形序列由客户端产生（流式中断 / 多轮折叠 / 一轮拆成多条 assistant），
        // 在网关统一修复，一处覆盖所有 provider 与所有客户端。
        [$messages, $repairs] = ToolMessageSequence::repair(is_array($overrides['messages'] ?? $this->extra['messages'] ?? $this->messages)
            ? ($overrides['messages'] ?? $this->extra['messages'] ?? $this->messages) : []);
        if ($repairs !== []) {
            Logger::warn('工具消息序列不合法，已在转发上游前修正', [
                'model'   => $this->model,
                'repairs' => $repairs,
            ]);
        }

        $payload = array_merge([
            'model'    => $this->model,
            'messages' => $messages,
            'stream'   => $this->stream,
        ], $this->extra, $overrides);
        $payload['messages'] = $messages;

        if ($this->temperature !== null) {
            $payload['temperature'] = $this->temperature;
        }
        if ($this->topP !== null) {
            $payload['top_p'] = $this->topP;
        }
        if ($this->maxTokens !== null) {
            // 上游对过小的 max_tokens 会拒单（实测 <16 触发参数校验失败）。
            // 客户端偶发传 0/1（"不限制输出"的误表达），出站前抬到最小可接受值。
            $payload['max_tokens'] = max(self::MIN_MAX_TOKENS, $this->maxTokens);
        }
        if (!empty($this->tools)) {
            $payload['tools'] = JsonSchema::normalizeTools($this->tools);
        }

        // 少数客户端用老式 functions 字段；同样要治空对象降级
        if (isset($payload['functions']) && is_array($payload['functions']) && $payload['functions'] !== []) {
            $payload['functions'] = JsonSchema::normalizeTools($payload['functions']);
        }

        if (is_array($payload['messages'] ?? null)) {
            foreach ($payload['messages'] as &$message) {
                unset($message['_aigw_source']);
            }
            unset($message);
        }
        return $payload;
    }
}