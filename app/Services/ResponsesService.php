<?php

declare(strict_types=1);

namespace App\Services;

use App\Provider\Dto\ChatRequest;
use App\Provider\Dto\ResponsesRequestAdapter;
use App\Repositories\ResponsesRepository;
use App\Support\BackgroundProcess;
use App\Support\Logger;
use App\Support\ResponsesInclude;
use App\Support\Tenant;
use App\Support\ToolCalls;
use InvalidArgumentException;

/**
 * Responses API 状态服务：负责 store / previous_response_id / background 生命周期。
 *
 * 职责边界（**刻意把数据库与协议适配隔开**）：
 *   · `ResponsesRequestAdapter` 只做协议形状转换，绝不碰数据库；
 *   · 本服务负责「哪些字段要落库、什么时候落、previous 链怎么恢复」；
 *   · `GatewayController` 只做 HTTP 编排。
 *
 * 之所以要有它，是因为 `store` / `background` / `include` 这些参数**只描述生命周期**，
 * 与「怎么把 Responses 转成 Chat Completions」无关 —— 混进 adapter 会让后者同时
 * 依赖 DB 与租户上下文，任何一次纯逻辑单测都得起数据库。
 */
final class ResponsesService
{
    /**
     * `include` 允许值见 `App\Support\ResponsesInclude::ALLOWLIST`（严格 allowlist，
     * 未知值 400，不做静默忽略）。
     */
    public static function validateInclude(mixed $include): array
    {
        return ResponsesInclude::validate($include);
    }

    public function __construct(
        private ResponsesRepository $repository,
        private GatewayService $gateway,
    ) {}

    public static function newResponseId(): string
    {
        return 'resp_' . bin2hex(random_bytes(12));
    }

    /**
     * 落库一条响应对象（同步 store 与 background 提交共用）。
     *
     * @param array<string,mixed> $data
     */
    public function save(int $userId, array $data): void
    {
        $this->repository->create($userId, $data);
    }

    /** 对外读取（含租户隔离） */
    public function find(string $responseId, ?int $userId): ?array
    {
        return $this->repository->findForTenant($responseId, $userId);
    }

    /** 对外删除 */
    public function delete(string $responseId, ?int $userId): bool
    {
        return $this->repository->deleteForTenant($responseId, $userId) > 0;
    }

    /**
     * 取 `previous_response_id` 指向的上下文消息。
     *
     * ⚠️ 必须按属主查：否则用户 A 只要猜到/拿到用户 B 的 response_id，
     *    就能把 B 的整段对话当成自己的上下文继续提问（越权读取）。
     */
    public function previousContext(string $previousResponseId, ?int $userId): array
    {
        $row = $this->repository->findForTenant($previousResponseId, $userId);
        if ($row === null) {
            throw new InvalidArgumentException('previous_response_id 不存在或不属于当前 API Key');
        }

        $messages = $row['context_messages'] ?? [];
        if (!is_array($messages) || $messages === []) {
            throw new InvalidArgumentException('previous_response_id 没有可复用的上下文');
        }

        return $messages;
    }

    /**
     * 执行一次 Responses 请求（非流式），产出完整 Responses 对象。
     *
     * @param array<string,mixed> $job
     * @return array{response:array,text:string,context_messages:array,model:string}
     */
    public function execute(array $job, string $clientIp = ''): array
    {
        $request = $this->buildChatRequest($job);

        $text = '';
        $result = $this->gateway->complete(
            $request,
            static function (string $delta, array $raw = []) use (&$text): void {
                // 只收普通正文帧：reasoning / tool_calls / usage 特殊帧不能污染正文
                if (($raw['_aigw_kind'] ?? '') === '' && $delta !== '') {
                    $text .= $delta;
                }
            },
            $clientIp
        );

        $chunk = $result['chunk'];
        $outputTokens = $chunk->completionTokens ?: (int) ceil(mb_strlen($text) / 1.6);
        $responseId = (string) ($job['response_id'] ?? self::newResponseId());
        $finishReason = $chunk->finishReason !== '' ? $chunk->finishReason : 'stop';
        $model = (string) $result['model'];

        $response = ResponsesRequestAdapter::response(
            $responseId,
            $model,
            $text,
            $chunk->toolCalls,
            $finishReason,
            [
                'input_tokens'  => $chunk->promptTokens,
                'output_tokens' => $outputTokens,
                'total_tokens'  => $chunk->promptTokens + $outputTokens,
            ],
            (array) ($job['metadata'] ?? []),
            null,
            (bool) ($job['parallel_tool_calls'] ?? true),
        );

        return [
            'response'         => $response,
            'text'             => $text,
            'model'            => $model,
            'context_messages' => $this->extendContext(
                (array) ($job['messages'] ?? []),
                $text,
                $chunk->toolCalls
            ),
        ];
    }

    /**
     * 把本轮 assistant 产出追加到上下文消息尾部，形成可供 previous_response_id
     * 恢复的完整对话。
     *
     * 注意：`instructions` 刻意**不进入** context_messages —— 它与请求绑定、
     * 不随 response 继承；若把它当历史存下来，链路第二次就会继承上一次的
     * 指令（OpenAI 语义里不会）。
     */
    public function extendContext(array $messages, string $text, array $toolCalls): array
    {
        if ($text !== '') {
            $messages[] = ['role' => 'assistant', 'content' => $text];
        }
        $calls = ToolCalls::mergeDeltas($toolCalls);
        if ($calls !== []) {
            $normalized = [];
            foreach ($calls as $call) {
                $function = $call['function'] ?? null;
                if (!is_array($function) || trim((string) ($function['name'] ?? '')) === '') {
                    continue;
                }
                $normalized[] = [
                    'id'       => (string) ($call['id'] ?? ''),
                    'type'     => 'function',
                    'function' => [
                        'name'      => (string) $function['name'],
                        'arguments' => (string) ($function['arguments'] ?? ''),
                    ],
                ];
            }
            if ($normalized !== []) {
                $messages[] = [
                    'role'       => 'assistant',
                    'content'    => '',
                    'tool_calls' => $normalized,
                ];
            }
        }
        return $messages;
    }

    /** 由 job 还原一次 ChatRequest（worker 与同步路径共用同一构造逻辑） */
    public function buildChatRequest(array $job): ChatRequest
    {
        $payload = [
            'model'    => (string) ($job['model'] ?? ''),
            'messages' => is_array($job['messages'] ?? null) ? $job['messages'] : [],
            'stream'   => false,
        ];

        foreach (['temperature', 'top_p', 'max_tokens', 'tools', 'tool_choice', 'parallel_tool_calls'] as $key) {
            if (array_key_exists($key, $job) && $job[$key] !== null) {
                $payload[$key] = $job[$key];
            }
        }

        $request = ChatRequest::fromArray($payload);
        $request->source = (string) ($job['source'] ?? '');
        $request->sessionKey = (string) ($job['session_key'] ?? '');

        return $request;
    }

    /**
     * 提交后台任务：先落 queued 记录，再拉起独立 CLI worker。
     *
     * @return array{ok:bool,response_id:string,reason:string}
     */
    public function submitBackground(int $userId, array $job): array
    {
        $responseId = (string) $job['response_id'];

        $base = ResponsesRequestAdapter::response(
            $responseId,
            (string) ($job['model'] ?? ''),
            '',
            [],
            'stop',
            ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            (array) ($job['metadata'] ?? []),
            null,
            (bool) ($job['parallel_tool_calls'] ?? true),
        );
        $base['status'] = ResponsesRepository::STATUS_QUEUED;
        $base['background'] = true;

        $this->save($userId, [
            'response_id'          => $responseId,
            'previous_response_id' => (string) ($job['previous_response_id'] ?? ''),
            'status'               => ResponsesRepository::STATUS_QUEUED,
            'model'                => (string) ($job['model'] ?? ''),
            'response_object'      => $base,
            'context_messages'     => [],
            'request_payload'      => $job,
            'background'           => true,
        ]);

        $started = BackgroundProcess::startResponsesWorker($responseId);
        if (!$started['ok']) {
            $failed = $base;
            $failed['status'] = ResponsesRepository::STATUS_FAILED;
            $failed['error'] = [
                'code'    => 'worker_start_failed',
                'type'    => 'server_error',
                'message' => '后台 worker 启动失败：' . $started['reason'],
            ];
            $this->repository->finish($responseId, [
                'status'          => ResponsesRepository::STATUS_FAILED,
                'response_object' => $failed,
            ]);

            return ['ok' => false, 'response_id' => $responseId, 'reason' => $started['reason']];
        }

        return ['ok' => true, 'response_id' => $responseId, 'reason' => ''];
    }

    /**
     * 执行一个 background 任务（由 bin/responses-worker.php 调用）。
     *
     * @return array{ok:bool,message:string}
     */
    public function run(string $responseId): array
    {
        $row = $this->repository->findInternal($responseId);
        if ($row === null) {
            return ['ok' => false, 'message' => '响应记录不存在'];
        }

        if ($this->repository->claim($responseId, getmypid() ?: 0) < 1) {
            return ['ok' => false, 'message' => '任务已被其他 worker 接管或已取消'];
        }

        // worker 是独立进程，必须自己恢复租户作用域，否则模型/账号查询会落到全局
        Tenant::setRequestScope((int) $row['user_id'] ?: null);

        $job = (array) ($row['request_payload'] ?? []);
        $responseId = (string) $row['response_id'];

        try {
            $result = $this->execute($job);
            $response = $result['response'];

            // 执行期间被请求取消 → 结果作废，终态写 cancelled
            $latest = $this->repository->findInternal($responseId);
            if (($latest['cancel_requested'] ?? false) === true) {
                $response['status'] = ResponsesRepository::STATUS_CANCELLED;
                $this->repository->finish($responseId, [
                    'status'          => ResponsesRepository::STATUS_CANCELLED,
                    'response_object' => $response,
                ]);
                return ['ok' => false, 'message' => '已取消'];
            }

            $this->repository->finish($responseId, [
                'status'           => (string) $response['status'],
                'response_object'  => $response,
                'context_messages' => $result['context_messages'],
                'model'            => $result['model'],
            ]);

            return ['ok' => true, 'message' => (string) $response['status']];
        } catch (\Throwable $e) {
            Logger::warn('Responses background 执行失败', [
                'response_id' => $responseId,
                'error'       => $e->getMessage(),
            ]);

            $failed = (array) ($row['response_object'] ?? []);
            $failed['status'] = ResponsesRepository::STATUS_FAILED;
            $failed['error'] = GatewayService::errorPayloadFor($e)['error'];

            $this->repository->finish($responseId, [
                'status'          => ResponsesRepository::STATUS_FAILED,
                'response_object' => $failed,
            ]);

            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            Tenant::reset();
        }
    }

    /**
     * 取消：queued 立即收口为 cancelled；in_progress 只登记意向，
     * worker 在写终态前复核该标记并丢弃结果。
     */
    public function cancel(string $responseId, ?int $userId): ?array
    {
        $row = $this->repository->findForTenant($responseId, $userId);
        if ($row === null) {
            return null;
        }

        if ($row['status'] === ResponsesRepository::STATUS_QUEUED) {
            $this->repository->cancelQueued($responseId, $userId);
        } elseif (in_array($row['status'], [
            ResponsesRepository::STATUS_IN_PROGRESS,
            ResponsesRepository::STATUS_COMPLETED,
            ResponsesRepository::STATUS_INCOMPLETE,
        ], true)) {
            $this->repository->requestCancel($responseId, $userId);
        }

        $updated = $this->repository->findForTenant($responseId, $userId);
        if ($updated === null) {
            return null;
        }

        $object = (array) ($updated['response_object'] ?? []);
        if ($updated['status'] === ResponsesRepository::STATUS_CANCELLED) {
            $object['status'] = ResponsesRepository::STATUS_CANCELLED;
        }
        return $object;
    }
}