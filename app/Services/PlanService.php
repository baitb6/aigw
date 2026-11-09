<?php
/**
 * 计划模式服务（参考 Operit 的 Plan Mode）
 *
 * 语义：
 *   1. 用户开启计划模式后，AI 只「制定计划」不改文件（禁写工具）；
 *   2. 计划以 Markdown 存于网关（按用户 + 会话隔离）；
 *   3. 用户确认后，网关把计划作为上下文回灌，AI 才开始实施；
 *   4. 实施完成调用 complete 置 DONE 并清除。
 *
 * 与 Operit 的差异：Operit 落计划到 Android 文件系统并钩住 prompt 组装；
 * 这里落库（aigw_plan），计划注入由客户端在构建请求时读取。
 */

declare(strict_types=1);

namespace App\Services;

use App\Provider\Dto\ChatRequest;
use App\Repositories\PlanRepository;

class PlanService
{
    /**
     * 计划模式下被禁止的工具（对齐 Operit 的 PLAN_MODE_BLOCKED_TOOL_NAMES）。
     * 这些是「直接改文件」的工具；计划阶段只允许只读/查询类工具。
     */
    public const BLOCKED_TOOLS = [
        'write_file',
        'apply_edit',
        'delete_file',
        'create_file',
        'move_file',
        'copy_file',
    ];

    /** 计划生成提示词（对齐 Operit 的 planGenerationPrompt 要点） */
    private const PLAN_GENERATION_PROMPT = <<<'TXT'
你是一名严谨的任务规划专家。请为用户的需求制定一份清晰、可执行的实施计划。

要求：
1. 先用一两句话说明目标与总体思路；
2. 然后以 Markdown 有序列表给出具体步骤，每步要可操作、可验证；
3. 若涉及文件改动，注明将改动哪些文件及原因；
4. 只输出计划本身，不要提问、不要输出无关解释；
5. 不要在计划阶段真的执行改动。
TXT;

    public function __construct(
        private PlanRepository $repository,
        private GatewayService $gateway,
    ) {}

    /** 读取当前活跃计划 */
    public function current(?int $userId, string $sessionKey): ?array
    {
        return $this->repository->current($userId, $sessionKey);
    }

    /** 保存（覆盖）某会话的计划 */
    public function save(?int $userId, string $sessionKey, string $title, string $content, string $source = 'manual'): array
    {
        $this->repository->deleteBySession($userId, $sessionKey);

        $id = $this->repository->create([
            'user_id'     => $userId ?? 0,
            'session_key' => $sessionKey,
            'title'       => $title,
            'content'     => $content,
            'status'      => 'DRAFT',
            'source'      => $source,
        ]);

        return $this->repository->findForUser($id, $userId) ?? [];
    }

    /**
     * 用 LLM 生成计划（不落库，交由调用方决定保存）。
     */
    public function generate(string $requirement, string $model = ''): string
    {
        $model = $model !== '' ? $model : $this->pickModel();
        if ($model === '') {
            throw new \RuntimeException('没有可用的模型来生成计划');
        }

        $request = ChatRequest::fromArray([
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => self::PLAN_GENERATION_PROMPT],
                ['role' => 'user', 'content' => $requirement],
            ],
            'stream'   => false,
        ]);

        $content = '';
        $this->gateway->complete($request, function (string $delta) use (&$content): void {
            $content .= $delta;
        });

        // 去掉思维链标签（对齐 Operit 的 THINK_TAG 清理）
        $content = preg_replace('#<think(?:ing)?>[\s\S]*?</think(?:ing)?>#i', '', $content) ?? $content;

        return trim($content);
    }

    /** 确认执行：置 CONFIRMED，返回可注入上下文 */
    public function confirm(int $id, ?int $userId): ?array
    {
        $plan = $this->repository->findForUser($id, $userId);
        if ($plan === null) {
            return null;
        }

        $this->repository->update($id, [
            'status'       => 'CONFIRMED',
            'confirmed_at' => now_ms(),
        ]);

        return $this->repository->findForUser($id, $userId);
    }

    /** 标记完成并清除 */
    public function complete(?int $userId, string $sessionKey): bool
    {
        $plan = $this->repository->current($userId, $sessionKey);
        if ($plan === null) {
            return false;
        }

        $this->repository->delete((int) $plan['id']);
        return true;
    }

    /** 删除当前计划 */
    public function clear(?int $userId, string $sessionKey): int
    {
        return $this->repository->deleteBySession($userId, $sessionKey);
    }

    /**
     * 渲染计划为可注入 system 的提示块。
     */
    public function toPromptBlock(?array $plan): string
    {
        if ($plan === null) {
            return '';
        }

        $status = (string) ($plan['status'] ?? 'DRAFT');
        if ($status === 'CONFIRMED') {
            $header = '## 已确认的实施计划\n用户已确认以下计划，请严格按步骤实施：';
        } else {
            $header = '## 当前计划（尚未确认）\n这是你之前制定的计划。除非用户确认执行，否则不要实际改动文件，只做只读操作与方案讨论：';
        }

        return $header . "\n\n" . (string) ($plan['content'] ?? '');
    }

    /** 过滤工具清单（计划阶段禁用写类工具） */
    public function filterTools(array $schemas): array
    {
        return array_values(array_filter($schemas, static function (array $schema): bool {
            $name = (string) ($schema['function']['name'] ?? '');
            return !in_array($name, self::BLOCKED_TOOLS, true);
        }));
    }

    private function pickModel(): string
    {
        try {
            foreach ($this->gateway->listModels() as $model) {
                $id = (string) ($model['id'] ?? '');
                if ($id !== '') {
                    return $id;
                }
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }
}
