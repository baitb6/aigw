<?php
/**
 * 流式响应汇总结果
 */

declare(strict_types=1);

namespace App\Provider\Dto;

class Chunk
{
    public function __construct(
        public string $id = '',
        public string $model = '',
        public string $content = '',
        public string $finishReason = '',
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
        /**
         * 推理 token 数（reasoning 模型专属，如 deepseek-v4 / glm-5 系）。
         *
         * ⚠️ 该计数**不计入** content 字数：上游常把大量 token 花在 reasoning_content 上，
         * 表现为「completion_tokens 很大、可见内容却很短」。若不单独记录，
         * 调用日志里就只能看到「输出被截断」的假象，无法判断钱花在哪。
         */
        public int $reasoningTokens = 0,
        /** @var array<int, array> tool_calls 累积 */
        public array $toolCalls = [],
        public ?array $raw = null,
        public bool $completed = false,
        /**
         * 上游单独下发的推理内容（Trae 命名事件的 reasoning_content / Gemini 的 thought 分片）。
         *
         * ⚠️ 必须独立成字段，**不能塞进 $raw**：$raw 按"最后一帧快照"语义，每帧都被
         * `$chunk->raw = $decoded;` 整体覆盖，往 $raw 里累积的推理会被下一帧冲掉 ——
         * 表现为"推理留痕恒 0 字"（历史 bug，已由本字段修正）。
         *
         * 该内容**不并入正文**，仅用于排查「模型是否接受了注入」与
         * 「正文是否被上游出口安全层抑制」（见 docs/用户行为审计与内容留痕.md §10）。
         */
        public string $reasoningContent = '',
    ) {}

    public function total(): int
    {
        return $this->totalTokens ?: ($this->promptTokens + $this->completionTokens);
    }
}