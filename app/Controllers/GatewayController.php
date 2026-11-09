<?php
/**
 * 对外网关控制器（OpenAI 兼容）
 *  - POST /v1/chat/completions  流式 / 非流式
 *  - GET  /v1/models
 *  - GET  /v1/health
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Provider\Dto\ChatRequest;
use App\Provider\Dto\ResponsesRequestAdapter;
use App\Provider\ErrorCategory;
use App\Repositories\GatewayRepository;
use App\Repositories\LogRepository;
use App\Repositories\ResponsesRepository;
use App\Services\GatewayService;
use App\Services\PersonaInjector;
use App\Services\ResponsesService;
use App\Support\ClientIp;
use App\Support\Logger;
use App\Support\Tenant;
use App\Support\ToolCalls;

class GatewayController
{
    /**
     * 请求体上限（字节），超限在**解析前**直接回 413。
     *
     * 与参考实现 workbuddy2api-hub 的 `MAX_PAYLOAD_BYTES = 16 * 1024 * 1024` 对齐：
     * 上游对超大请求体直接拒单（413），若不拦在最前面，整份 JSON（含内联 base64
     * 图片）会被先 `json_decode` 进内存，既浪费内存又把「体积问题」误报成
     * 参数/上游故障。默认 16MB，可用 AIGW_MAX_PAYLOAD_BYTES 覆盖。
     */
    private const MAX_PAYLOAD_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private GatewayService $gatewayService,
        private GatewayRepository $gatewayRepository,
        private PersonaInjector $personaInjector,
        private LogRepository $logRepository,
        private ResponsesService $responsesService,
        private \App\Repositories\ProviderRepository $providerRepository,
        private \App\Repositories\AccountRepository $accountRepository,
        private \App\Repositories\ModelRepository $modelRepository,
    ) {}

    /**
     * 对话补全（OpenAI /v1/chat/completions 兼容）
     */
    public function chatCompletions(Request $request): Response
    {
        // ── 执行时间上限：在代码里显式设定，不依赖 php.ini 的当前值 ──────────
        // php.ini 的 `max_execution_time` 是 **SAPI 启动时**读取并冻结的：
        // 改完 php.ini 必须重启 PHP 才生效，在跑的 worker 会一直沿用**启动那一刻的旧值**。
        // 实测踩过（2026-09-30）：php.ini 已写成 300，但旧 worker 仍是 60 ——
        // 表现为「配置明明改了、长请求还是被在 60s 掐断」，且掐断时 PHP 侧
        // 无任何报错（Windows 下 max_execution_time 计**墙钟**，与 Linux 只计 CPU 不同）。
        //
        // 网关长尾来自「租约排队 + 上游重采样」（实测最慢 215.8s），必须放宽。
        // 按约定 14 三层对齐：nginx 300s = PHP 300s ≥ 业务预算（120s/45s）。
        // 这里在入口显式设成与 nginx 同值，使 PHP 这层**不再随「忘了重启」静默退化**。
        @set_time_limit(Response::MAX_EXECUTION_SECONDS);

        // 请求体超限：在**解析前**拦下，避免把整份超大 JSON 读进内存再报参数错
        if (($tooLarge = $this->guardPayloadSize($request)) !== null) {
            return $tooLarge;
        }

        $payload = $request->json();

        $model = trim((string) ($payload['model'] ?? ''));
        $messages = $payload['messages'] ?? null;

        if ($model === '') {
            return Response::json(
                GatewayService::errorPayload('缺少 model 参数', 'invalid_request_error', 'missing_model'),
                400
            );
        }
        if (!is_array($messages) || empty($messages)) {
            return Response::json(
                GatewayService::errorPayload('messages 必须是非空数组', 'invalid_request_error', 'invalid_messages'),
                400
            );
        }

        // 总开关：关闭后对外对话能力整体不可用
        if (!$this->switchEnabled('gateway_enabled')) {
            return $this->switchOff('网关总开关已关闭，暂时无法调用。', 'gateway_disabled');
        }
        if (!$this->switchEnabled('chat_enabled')) {
            return $this->switchOff('对话补全能力已被管理员关闭。', 'chat_disabled');
        }

        $chatRequest = ChatRequest::fromArray($payload);

        $chatRequest->source = $this->resolveSource($request);
        $chatRequest->sessionKey = $this->resolveSessionKey($request, $payload);
        $clientIp = $this->clientIp($request);

        // 思维自证：整条消息恰好是口令时直接回执，不打上游、不占账号额度。
        // 用途是「一眼验证思维链路是否真的通」，而不是让模型自己猜。
        $selfcheck = $this->personaInjector->selfcheck($chatRequest->messages, Tenant::scope());
        if ($selfcheck['hit']) {
            return $this->selfcheckResponse($chatRequest, $selfcheck, $clientIp);
        }

        // 流式能力开关：请求了 stream 但被关闭 → 明确拒绝，避免客户端误以为在流式
        if ($chatRequest->stream && !$this->switchEnabled('stream_enabled')) {
            return $this->switchOff('流式输出已被管理员关闭，请改用非流式调用。', 'stream_disabled');
        }

        // 工具调用开关：请求携带 tools 但被关闭 → 拒绝，避免下游工具无法执行
        if (!empty($chatRequest->tools) && !$this->switchEnabled('tools_enabled')) {
            return $this->switchOff('工具调用（tools）已被管理员关闭。', 'tools_disabled');
        }

        // 非流式：聚合并一次性返回
        if (!$chatRequest->stream) {
            return $this->nonStreaming($chatRequest, $clientIp);
        }

        return $this->streaming($chatRequest, $clientIp);
    }

    /** OpenAI Responses API 兼容入口。 */
    public function responses(Request $request): Response
    {
        $payload = $request->json();

        try {
            $adapted = ResponsesRequestAdapter::fromArray($payload);
        } catch (\InvalidArgumentException $e) {
            return $this->responsesError($e->getMessage(), 'invalid_responses_request');
        }

        if (!$this->switchEnabled('gateway_enabled')) {
            return $this->switchOff('网关总开关已关闭，暂时无法调用。', 'gateway_disabled');
        }
        if (!$this->switchEnabled('chat_enabled')) {
            return $this->switchOff('对话补全能力已被管理员关闭。', 'chat_disabled');
        }

        /** @var ChatRequest $chatRequest */
        $chatRequest = $adapted['request'];
        $chatRequest->source = $this->resolveSource($request);
        $chatRequest->sessionKey = $this->resolveSessionKey($request, $payload);

        if ($chatRequest->stream && !$this->switchEnabled('stream_enabled')) {
            return $this->switchOff('流式输出已被管理员关闭，请改用非流式调用。', 'stream_disabled');
        }
        if ($chatRequest->tools !== [] && !$this->switchEnabled('tools_enabled')) {
            return $this->switchOff('工具调用（tools）已被管理员关闭。', 'tools_disabled');
        }
        if ($adapted['background'] && $chatRequest->stream) {
            return $this->responsesError('background=true 与 stream=true 不能同时使用', 'invalid_responses_request');
        }

        // ── 拼接上下文：previous_response_id 的累计消息在前，本次 input 在后 ──
        try {
            $messages = $adapted['input_messages'];
            if ($adapted['previous_response_id'] !== '') {
                $messages = array_merge(
                    $this->responsesService->previousContext($adapted['previous_response_id'], Tenant::scope()),
                    $messages
                );
            }
        } catch (\InvalidArgumentException $e) {
            return $this->responsesError($e->getMessage(), 'invalid_previous_response_id');
        }

        // instructions 只作用于本次请求，不进 context_messages（不随 response 继承）
        if ($adapted['instructions'] !== '') {
            array_unshift($messages, ['role' => 'system', 'content' => $adapted['instructions']]);
        }
        $chatRequest->messages = $messages;

        // ── 图片能力护栏 ──────────────────────────────────────────────────
        // 部分适配器（Antigravity / Doubao / Zcode）在协议层就把内容扁平化或转成
        // Anthropic 块，图片会**静默丢失**并换来一段凭空回答。与其如此，不如在
        // 请求前明确报错；supportsImageInput() 返回 null（无法判定）时放行。
        if ($this->messagesHaveImage($messages)
            && $this->gatewayService->supportsImageInput($chatRequest->model) === false) {
            return $this->responsesError(
                "模型 {$chatRequest->model} 所在供应商暂不保留图片输入，请改用支持视觉的模型或移除图片",
                'unsupported_image_input'
            );
        }

        $clientIp = $this->clientIp($request);
        $responseId = ResponsesService::newResponseId();
        $job = $this->buildResponsesJob($adapted, $chatRequest, $responseId);

        // ── background：落 queued 记录 + 拉起 CLI worker，请求立即返回 ──
        if ($adapted['background']) {
            $submit = $this->responsesService->submitBackground(Tenant::writeId(), $job);
            $row = $this->responsesService->find($submit['response_id'], Tenant::scope());

            return Response::json(
                $row['response_object'] ?? [
                    'id'     => $submit['response_id'],
                    'object' => 'response',
                    'status' => ResponsesRepository::STATUS_FAILED,
                ],
                200
            );
        }

        // 同步路径：store 落库后再返回，GET / DELETE 立即可用
        return $chatRequest->stream
            ? $this->responsesStreaming($job, $clientIp)
            : $this->responsesNonStreaming($job, $clientIp);
    }

    /**
     * 由 adapter 结果与最终消息构造一次可复用的「作业」描述。
     *
     * 它同时被同步执行、background worker、以及落库的 request_payload 使用 ——
     * 三条路径共用同一份输入描述，才能保证「同步与后台跑出来是同一个结果」。
     */
    private function buildResponsesJob(array $adapted, ChatRequest $chatRequest, string $responseId): array
    {
        /** @var ChatRequest $chatRequest */
        return [
            'response_id'          => $responseId,
            'previous_response_id' => $adapted['previous_response_id'],
            'model'                => $chatRequest->model,
            'messages'             => $chatRequest->messages,
            'temperature'          => $chatRequest->temperature,
            'top_p'                => $chatRequest->topP,
            'max_tokens'           => $chatRequest->maxTokens,
            'tools'                => $chatRequest->tools,
            'tool_choice'          => $chatRequest->extra['tool_choice'] ?? null,
            'parallel_tool_calls'  => $adapted['parallel_tool_calls'],
            'metadata'             => $adapted['metadata'],
            'include'              => $adapted['include'],
            'store'                => $adapted['store'],
            'background'           => false,
            'source'               => $chatRequest->source,
            'session_key'          => $chatRequest->sessionKey,
        ];
    }

    /** Responses 协议错误响应（统一 error 结构 + invalid_request_error） */
    private function responsesError(string $message, string $code): Response
    {
        return Response::json(
            GatewayService::errorPayload($message, 'invalid_request_error', $code),
            400
        );
    }

    /** 消息序列里是否含有图片内容块（adapter 已把 input_image 归一成 image_url）。 */
    private function messagesHaveImage(array $messages): bool
    {
        foreach ($messages as $message) {
            $content = is_array($message) ? ($message['content'] ?? null) : null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $part) {
                if (is_array($part) && in_array((string) ($part['type'] ?? ''), ['image_url', 'image'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function responsesNonStreaming(array $job, string $clientIp): Response
    {
        try {
            $result = $this->responsesService->execute($job, $clientIp);
        } catch (\Throwable $ex) {
            $status = (int) $ex->getCode();
            $status = ($status >= 400 && $status < 600) ? $status : 500;
            $errPayload = GatewayService::errorPayloadFor($ex);

            if (!empty($job['store'])) {
                $this->persistResponses($job, [
                    'response'         => ResponsesRequestAdapter::response(
                        (string) $job['response_id'],
                        (string) $job['model'],
                        '',
                        [],
                        'stop',
                        ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
                        (array) $job['metadata'],
                        $errPayload['error'],
                        (bool) $job['parallel_tool_calls'],
                    ),
                    'context_messages' => [],
                    'model'            => (string) $job['model'],
                ]);
            }

            return Response::json($errPayload, $status);
        }

        if (!empty($job['store'])) {
            $this->persistResponses($job, $result);
        }

        return Response::json($result['response']);
    }

    /** 落库一条同步 store 的响应记录（失败不抛出，避免影响已生成的回答） */
    private function persistResponses(array $job, array $result): void
    {
        try {
            $this->responsesService->save(Tenant::writeId(), [
                'response_id'          => (string) $job['response_id'],
                'previous_response_id' => (string) ($job['previous_response_id'] ?? ''),
                'status'               => (string) ($result['response']['status'] ?? 'completed'),
                'model'                => (string) ($result['model'] ?? $job['model']),
                'response_object'      => $result['response'],
                'context_messages'     => $result['context_messages'],
                'request_payload'      => [],
                'background'           => false,
                'finished_at'          => now_ms(),
            ]);
        } catch (\Throwable $e) {
            Logger::warn('Responses 落库失败', [
                'response_id' => $job['response_id'] ?? '',
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /** GET /v1/responses/{responseId} —— 读取已存储的响应对象 */
    public function responseRetrieve(Request $request): Response
    {
        $responseId = (string) $request->routeParam('responseId', '');
        $row = $this->responsesService->find($responseId, Tenant::scope());
        if ($row === null) {
            return Response::json(
                GatewayService::errorPayload('response 不存在', 'invalid_request_error', 'response_not_found'),
                404
            );
        }

        return Response::json($row['response_object'] ?? []);
    }

    /** DELETE /v1/responses/{responseId} */
    public function responseDelete(Request $request): Response
    {
        $responseId = (string) $request->routeParam('responseId', '');
        $deleted = $this->responsesService->delete($responseId, Tenant::scope());
        if (!$deleted) {
            return Response::json(
                GatewayService::errorPayload('response 不存在', 'invalid_request_error', 'response_not_found'),
                404
            );
        }

        return Response::json([
            'id'      => $responseId,
            'object'  => 'response.deleted',
            'deleted' => true,
        ]);
    }

    /**
     * POST /v1/responses/{responseId}/cancel
     *
     * queued → 立即 cancelled；in_progress → 登记取消意向，worker 写终态前复核并丢弃结果。
     */
    public function responseCancel(Request $request): Response
    {
        $responseId = (string) $request->routeParam('responseId', '');
        $object = $this->responsesService->cancel($responseId, Tenant::scope());
        if ($object === null) {
            return Response::json(
                GatewayService::errorPayload('response 不存在', 'invalid_request_error', 'response_not_found'),
                404
            );
        }

        return Response::json($object);
    }

    private function responsesStreaming(array $job, string $clientIp): Response
    {
        return Response::stream(function () use ($job, $clientIp): void {
            $responseId = (string) $job['response_id'];
            $chatRequest = $this->responsesService->buildChatRequest($job);
            $metadata = (array) $job['metadata'];
            $parallel = (bool) $job['parallel_tool_calls'];

            $messageItem = ResponsesRequestAdapter::messageItem($responseId, '', 'in_progress');
            $sequence = 0;
            $text = '';
            $messageStarted = false;
            $emit = function (string $type, array $fields = []) use (&$sequence): void {
                $event = ResponsesRequestAdapter::event($type, $sequence++, $fields);
                echo 'event: ' . $type . "\n";
                echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                $this->flush();
            };
            $base = ResponsesRequestAdapter::response(
                $responseId,
                $chatRequest->model,
                '',
                [],
                'stop',
                ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
                $metadata,
                null,
                $parallel,
            );
            $base['status'] = 'in_progress';

            $emit('response.created', ['response' => $base]);
            $emit('response.in_progress', ['response' => $base]);

            $persist = function (array $response, string $contextText, array $toolCalls) use ($job): void {
                if (empty($job['store'])) {
                    return;
                }
                $this->persistResponses($job, [
                    'response'         => $response,
                    'context_messages' => $this->responsesService->extendContext(
                        (array) $job['messages'],
                        $contextText,
                        $toolCalls
                    ),
                    'model'            => (string) ($response['model'] ?? $job['model']),
                ]);
            };

            try {
                $result = $this->gatewayService->complete(
                    $chatRequest,
                    function (string $delta, array $raw = []) use (&$text, &$messageStarted, $emit, $messageItem): void {
                        if (($raw['_aigw_kind'] ?? '') === 'reasoning' || $delta === '') {
                            return;
                        }
                        if (!$messageStarted) {
                            $messageStarted = true;
                            $emit('response.output_item.added', ['output_index' => 0, 'item' => $messageItem]);
                            $part = ['type' => 'output_text', 'text' => '', 'annotations' => []];
                            $emit('response.content_part.added', ['item_id' => $messageItem['id'], 'output_index' => 0, 'content_index' => 0, 'part' => $part]);
                        }
                        $text .= $delta;
                        $emit('response.output_text.delta', [
                            'item_id' => $messageItem['id'],
                            'output_index' => 0,
                            'content_index' => 0,
                            'delta' => $delta,
                        ]);
                    },
                    $clientIp,
                    static function (): void {},
                    static function (): void {},
                );
                $chunk = $result['chunk'];
                if ($messageStarted) {
                    $emit('response.output_text.done', [
                        'item_id' => $messageItem['id'],
                        'output_index' => 0,
                        'content_index' => 0,
                        'text' => $text,
                    ]);
                    $donePart = ['type' => 'output_text', 'text' => $text, 'annotations' => []];
                    $emit('response.content_part.done', ['item_id' => $messageItem['id'], 'output_index' => 0, 'content_index' => 0, 'part' => $donePart]);
                    $itemStatus = $chunk->finishReason === 'length' ? 'incomplete' : 'completed';
                    $doneMessage = ResponsesRequestAdapter::messageItem($responseId, $text, $itemStatus);
                    $emit('response.output_item.done', ['output_index' => 0, 'item' => $doneMessage]);
                }

                $toolItems = ResponsesRequestAdapter::functionCallItems($responseId, $chunk->toolCalls);
                foreach ($toolItems as $index => $item) {
                    $outputIndex = $index + ($messageStarted ? 1 : 0);
                    $added = $item;
                    $added['status'] = 'in_progress';
                    $added['arguments'] = '';
                    $emit('response.output_item.added', ['output_index' => $outputIndex, 'item' => $added]);
                    if ($item['arguments'] !== '') {
                        $emit('response.function_call_arguments.delta', [
                            'item_id' => $item['id'],
                            'output_index' => $outputIndex,
                            'delta' => $item['arguments'],
                        ]);
                    }
                    $emit('response.function_call_arguments.done', [
                        'item_id' => $item['id'],
                        'output_index' => $outputIndex,
                        'arguments' => $item['arguments'],
                    ]);
                    $emit('response.output_item.done', ['output_index' => $outputIndex, 'item' => $item]);
                }

                $outputTokens = $chunk->completionTokens ?: (int) ceil(mb_strlen($text) / 1.6);
                $final = ResponsesRequestAdapter::response(
                    $responseId,
                    (string) $result['model'],
                    $text,
                    $chunk->toolCalls,
                    $chunk->finishReason !== '' ? $chunk->finishReason : 'stop',
                    [
                        'input_tokens' => $chunk->promptTokens,
                        'output_tokens' => $outputTokens,
                        'total_tokens' => $chunk->promptTokens + $outputTokens,
                    ],
                    $metadata,
                    null,
                    $parallel,
                );
                $terminal = $final['status'] === 'incomplete' ? 'response.incomplete' : 'response.completed';
                $emit($terminal, ['response' => $final]);
                $persist($final, $text, $chunk->toolCalls);
            } catch (\Throwable $ex) {
                Logger::warn('Responses API 流式失败', ['error' => $ex->getMessage()]);
                $errPayload = GatewayService::errorPayloadFor($ex);
                $error = $errPayload['error'];
                $failed = ResponsesRequestAdapter::response(
                    $responseId,
                    $chatRequest->model,
                    $text,
                    [],
                    'stop',
                    ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
                    $metadata,
                    $error,
                    $parallel,
                );
                $emit('response.failed', ['response' => $failed]);
                $persist($failed, $text, []);
            }
            echo "data: [DONE]\n\n";
            $this->flush();
        });
    }

    /**
     * 思维自证命中：直接以上游不参与的方式回一条固定回执。
     *
     * 回执内容完全来自思维配置（selfcheck_reply），网关不做任何改写 ——
     * 这样「回什么」由操作者决定，客户端只需比对是否与配置一致即可判定链路通断。
     *
     * ⚠️ **回执的载体必须跟随请求的 stream 参数**：
     * 客户端带 `"stream": true` 时它跑的是 SSE 解析器，只认 `data:` 帧。
     * 若此时仍回一份普通 JSON，解析器读不到任何帧 → 表现为「输入口令毫无反应」，
     * 让人误以为思维没生效。所以流式请求必须以 chunk 帧回执。
     *
     * @param array{hit:bool, reply:string, persona_id:int, persona_name:string} $selfcheck
     */
    private function selfcheckResponse(ChatRequest $chatRequest, array $selfcheck, string $clientIp): Response
    {
        $providerKey = '';
        try {
            [$providerKey] = $this->gatewayService->resolveModel($chatRequest->model);
        } catch (\Throwable $e) {
            // 自证不该因为模型没配好而失败：解析不到供应商时照常回执，只是日志里留空
            $providerKey = '';
        }

        $requestId = 'chatcmpl-selfcheck-' . bin2hex(random_bytes(8));

        $this->logRepository->record([
            'request_id'   => 'chatcmpl-' . bin2hex(random_bytes(12)),
            'provider_key' => $providerKey,
            'model'        => $chatRequest->model,
            // 自证回执不经过 resolveModel()，原始 model 与上同行一致，但列仍要填齐，
            // 免得列表页出现「原始模型为空」的行被误读成"没记录到客户端请求"。
            'requested_model' => $chatRequest->model,
            'status'       => 'SUCCESS',
            'streaming'    => $chatRequest->stream,
            'source'       => $chatRequest->source,
            'session_key'  => $chatRequest->sessionKey,
            'client_ip'    => $clientIp,
            'persona_id'   => $selfcheck['persona_id'],
            'persona_hit'  => 1,
            'user_id'      => Tenant::writeId(),
        ]);

        // 非标准字段，仅在自证回执上出现，便于排障时确认命中的是哪份思维
        $persona = [
            'id'   => $selfcheck['persona_id'],
            'name' => $selfcheck['persona_name'],
            'hit'  => true,
        ];

        // 流式请求 → 用 SSE chunk 帧回执，否则客户端什么都看不到
        if ($chatRequest->stream) {
            return Response::stream(function () use ($requestId, $chatRequest, $selfcheck, $persona) {
                $head = [
                    'id'      => $requestId,
                    'object'  => 'chat.completion.chunk',
                    'created' => time(),
                    'model'   => $chatRequest->model,
                ];

                // 首帧即携带完整回执文本（自证是本地回显，无需分片）
                echo 'data: ' . json_encode($head + [
                    'choices' => [[
                        'index'         => 0,
                        'delta'         => ['role' => 'assistant', 'content' => $selfcheck['reply']],
                        'finish_reason' => null,
                    ]],
                    'aigw_persona' => $persona,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                $this->flush();

                // 收尾帧：finish_reason=stop + usage 全 0（不占上游额度）
                echo 'data: ' . json_encode($head + [
                    'choices' => [[
                        'index'         => 0,
                        'delta'         => new \stdClass(),
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                $this->flush();

                echo "data: [DONE]\n\n";
                $this->flush();
            });
        }

        return Response::json([
            'id'      => $requestId,
            'object'  => 'chat.completion',
            'created' => time(),
            'model'   => $chatRequest->model,
            'choices' => [[
                'index'         => 0,
                'message'       => ['role' => 'assistant', 'content' => $selfcheck['reply']],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            'aigw_persona' => $persona,
        ]);
    }

    /**
     * 流式：以 SSE 逐块下发 OpenAI chunk
     */
    private function streaming(ChatRequest $chatRequest, string $clientIp): Response
    {
        return Response::stream(function () use ($chatRequest, $clientIp) {
            $sentRole = false;
            $finishReason = 'stop';
            $model = $chatRequest->model;
            $requestId = '';
            // 上游是否已在自己某一帧里下发过 finish_reason（且该帧已被原样透传）。
            // 置位后收尾不再补 finish 帧，避免与上游帧重复（见下方收尾注释）。
            $upstreamFinishSent = false;

            $heartbeat = (bool) config('gateway.stream_heartbeat', true);
            if ($heartbeat) {
                // 首帧心跳，避免部分客户端等待超时
                echo ': ' . str_repeat(' ', 2048) . "\n\n";
                $this->flush();
            }

            try {
                $result = $this->gatewayService->complete(
                    $chatRequest,
                    function (string $delta, array $raw) use (&$sentRole, &$model, &$requestId, &$finishReason, &$upstreamFinishSent) {
                        $requestId = $requestId !== '' ? $requestId : (string) ($raw['id'] ?? '');
                        $model = (string) ($raw['model'] ?? $model);

                        if (isset($raw['choices'][0]['finish_reason']) && $raw['choices'][0]['finish_reason']) {
                            $finishReason = (string) $raw['choices'][0]['finish_reason'];
                        }

                        // ── 保真优先：上游原始 data 行原样下发 ────────────────────
                        // Provider 在 `_aigw_raw` 里带上了这一帧的**原始 data 行**。
                        // 有它就原样 echo —— 上游自定义字段（id / model / usage /
                        // system_fingerprint / extensions / 各家私有键）一个不丢，
                        // 帧边界与上游完全一致。
                        //
                        // ⚠️ 为什么不再自己重组 chunk：`id`/`created`/`model` 全部由
                        //    网关自造，上游的自定义字段被整片丢弃。实测表现为
                        //    「客户端拿到的响应 id 与上游对不上、扩展字段消失」，
                        //    对接需要精确回溯上游帧的 IDE / Agent 时无法接受。
                        // ⚠️ 只在**流式**用原始行：非流式路径由下面的聚合分支处理。
                        // ⚠️ 只在**该帧本身是 OpenAI 形态**时才原样回显：判据是
                        //    `choices` 为数组。Doubao / Trae / Antigravity 走的是私有
                        //    协议（如 Doubao 的 `{"text":…,"end_type":…}`、Antigravity
                        //    的 Gemini `candidates[]`），把它们的原始行直接丢给 OpenAI
                        //    客户端会解析失败 —— 这些必须走下面的归一化分支。
                        //    换言之：**能保真的地方保真，异构协议照旧归一化**，两者共存。
                        $rawLine = $raw['_aigw_raw'] ?? null;
                        if (is_string($rawLine) && $rawLine !== ''
                            && is_array($raw['choices'] ?? null)) {
                            // 该帧自带 finish_reason → 已经原样交给客户端了，收尾不再补
                            if (!empty($raw['choices'][0]['finish_reason'])) {
                                $upstreamFinishSent = true;
                            }
                            echo 'data: ' . $rawLine . "\n\n";
                            $this->flush();
                            return;
                        }

                        // ── 纯 usage 帧兜底：上游不带 choices 的私有形态 ──────────
                        // 标准 OpenAI 收尾帧是 `{"choices":[],"usage":{...}}`，
                        // `choices` 是**空数组**、能命中上方保真回显，无需走到这里。
                        // 但部分私有协议只在顶层回 `{"usage":{...}}`（无 choices）——
                        // 保真判据 `is_array($raw['choices'])` 为假会被跳过，若继续
                        // 往下走会当作空 content 帧下发、usage 被丢弃，客户端仍显示
                        // 「累计未知」。这里显式组一帧 OpenAI 规范的 usage-only chunk。
                        if (($raw['_aigw_kind'] ?? '') === 'usage') {
                            $usage = $raw['usage'] ?? null;
                            if (!is_array($usage) || $usage === []) {
                                return;
                            }
                            echo 'data: ' . json_encode([
                                'id'      => $requestId !== '' ? $requestId : 'chatcmpl-stream',
                                'object'  => 'chat.completion.chunk',
                                'created' => time(),
                                'model'   => $model,
                                'choices' => [],
                                'usage'   => $usage,
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                            $this->flush();
                            return;
                        }

                        // ── 推理帧：下发 delta.reasoning_content ─────────────────
                        // 与正文同渠道（`$onDelta`，约定 22），但字段独立 ——
                        // 此前整帧被网关吞掉，客户端在思考阶段全程零帧（白屏），
                        // 且完全拿不到模型思维（对接 Claude Code 类客户端时功能缺失）。
                        // 这里按 OpenAI 生态通行写法下发 `reasoning_content`
                        // （DeepSeek / Qwen / GLM / vLLM 均用此字段名）。
                        if (($raw['_aigw_kind'] ?? '') === 'reasoning') {
                            $reasoning = $this->extractReasoning($raw);
                            if ($reasoning === '') {
                                return;
                            }
                            $choice = ['index' => 0, 'delta' => []];
                            if (!$sentRole) {
                                $sentRole = true;
                                $choice['delta']['role'] = 'assistant';
                            }
                            $choice['delta']['reasoning_content'] = $reasoning;
                            $choice['finish_reason'] = null;

                            echo 'data: ' . json_encode([
                                'id'      => $requestId !== '' ? $requestId : 'chatcmpl-stream',
                                'object'  => 'chat.completion.chunk',
                                'created' => time(),
                                'model'   => $model,
                                'choices' => [$choice],
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                            $this->flush();
                            return;
                        }

                        // ── 工具调用帧：原样转成 OpenAI 规范的 delta.tool_calls ──────
                        // 上游（zcode 走 Anthropic/OpenAI 双通道）把工具调用放在
                        // `delta.tool_calls` 里、content 全程为空。此前这里只组装
                        // `delta.content`，工具调用被整帧丢弃 → 客户端只收到一个
                        // `finish_reason=tool_calls` 的空收尾帧，界面没有任何输出，
                        // 表现为「日志成功（SUCCESS）却没有任何回答」。
                        //
                        // ⚠️ **必须同时认「顶层 tool_calls」形态** —— 与思维帧同一个坑：
                        //    Trae 是私有协议，工具调用帧长这样（**无 choices**）：
                        //      `{"response":"","reasoning_content":"…","tool_calls":[…]}` /
                        //      `{"tool_calls":[{"function":{"name":"…","arguments":"…"}}]}`
                        //    早期只读 `choices[0].delta.tool_calls` → 顶层形态取不到 →
                        //    落到下方 `if ($delta === '') return;` 被静默丢弃 →
                        //    Agent 型客户端（Trae Work / Claude Code）**看不到任何工具步骤**，
                        //    与"看不到思考过程"是同一类缺陷。
                        $toolCalls = $raw['choices'][0]['delta']['tool_calls'] ?? null;
                        if (!is_array($toolCalls) || $toolCalls === []) {
                            // 私有协议：工具调用直接在顶层
                            $topCalls = $raw['tool_calls'] ?? null;
                            if (is_array($topCalls) && $topCalls !== []) {
                                $toolCalls = $topCalls;
                            }
                        }
                        if (is_array($toolCalls) && $toolCalls !== []) {
                            $choice = ['index' => 0, 'delta' => [], 'finish_reason' => null];
                            if (!$sentRole) {
                                $sentRole = true;
                                $choice['delta']['role'] = 'assistant';
                            }
                            $choice['delta']['tool_calls'] = $this->normalizeToolCalls($toolCalls);
                            if ($delta !== '') {
                                $choice['delta']['content'] = $delta;
                            }
                            echo 'data: ' . json_encode([
                                'id'      => $requestId !== '' ? $requestId : 'chatcmpl-stream',
                                'object'  => 'chat.completion.chunk',
                                'created' => time(),
                                'model'   => $model,
                                'choices' => [$choice],
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                            $this->flush();
                            return;
                        }

                        // 纯工具调用帧（content 为空）：已由上分支处理，避免多发空 content 帧
                        if ($delta === '' && ($raw['_aigw_kind'] ?? '') === 'tool_calls') {
                            return;
                        }

                        $choice = ['index' => 0, 'delta' => []];
                        if (!$sentRole) {
                            $sentRole = true;
                            $choice['delta']['role'] = 'assistant';
                        }
                        $choice['delta']['content'] = $delta;
                        $choice['finish_reason'] = null;

                        echo 'data: ' . json_encode([
                            'id'      => $requestId !== '' ? $requestId : 'chatcmpl-stream',
                            'object'  => 'chat.completion.chunk',
                            'created' => time(),
                            'model'   => $model,
                            'choices' => [$choice],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                        $this->flush();
                    },
                    $clientIp,
                    // 排队等租约时的心跳：占用者还在跑（实测 14~94s），本请求在排队。
                    // 不发心跳的话 nginx（fastcgi_read_timeout 默认 60s）会把连接判空闲掐断，
                    // 用户看到的是「发不出去」。这里以 SSE 注释帧维持连接。
                    function (int $waitedMs, $account) use (&$requestId, &$model) {
                        echo ': waiting' . str_repeat(' ', 1000) . "\n\n";
                        $this->flush();
                    },
                    // 推理期进度心跳：推理模型在推理阶段只下发 reasoning_content、
                    // 正文全程为空 → Provider 的 onDelta 一次都不触发 → 客户端从发请求
                    // 到正文出现**全程白屏**（实测「首字节 = 总耗时」：22.6s/40.5s/56.9s）。
                    // 这里以 SSE 注释帧告知客户端「链路活着、正在生成」，
                    // 标准 OpenAI 客户端一律忽略 `:` 注释行，不改变 chunk 语义。
                    function (int $atMs, string $phase) use (&$requestId, &$model) {
                        echo ': thinking' . str_repeat(' ', 1000) . "\n\n";
                        $this->flush();
                    }
                );

                $requestId = $result['request_id'];
                $model = $result['model'];
            } catch (\Throwable $e) {
                Logger::warn('流式对话失败', ['error' => $e->getMessage()]);

                // 已建立 SSE 连接，错误也以事件形式回传，符合 OpenAI 流式错误惯例。
                //
                // ⚠️ 与**非流式路径共用** `errorPayloadFor()`，两条通道必须给出同一个
                //    `error.code`（业务码，取自 ErrorCategory，与调用日志 error_code 同源）。
                //    历史缺陷：这里下发的是 `(string) $e->getCode()` —— HTTP 状态码字符串
                //    （如 "503"），而非流式固定 `'upstream_error'`，同一条错误两个通道两个值，
                //    客户端既无法据以分支处理，也无法与日志对齐排查。
                echo 'data: ' . json_encode(
                    GatewayService::errorPayloadFor($e),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) . "\n\n";
                $this->flush();
                echo "data: [DONE]\n\n";
                $this->flush();
                return;
            }

            // 结束帧。
            // ⚠️ finish_reason 以 `$result['chunk']->finishReason` 为**权威**：
            // 闭包里的 $finishReason 只在「带 finish_reason 且触发了 onDelta」的帧上更新，
            // 而工具调用的收尾帧（`finish_reason=tool_calls` + 空 content + 无 tool_calls）
            // 不会触发回调 → 闭包值仍是初始的 'stop'，会让客户端误判「模型没在调工具」，
            // 从而丢弃前面收到的 tool_calls、表现为「调用了却当没调用」。
            $finalReason = (string) ($result['chunk']->finishReason ?? '');
            if ($finalReason === '') {
                $finalReason = $finishReason;
            }
            if ($finalReason === '') {
                $finalReason = 'stop';
            }

            // ── 保真优先：上游已自己下发过带 finish_reason 的帧就不再补 ──────
            // 补一个 finish 帧会与上游的那一帧**重复**，部分客户端（严格按
            // OpenAI 规范校验的）会把重复收尾当成协议错误。
            // 但注意：上游「delta 为空 + 仅带 finish_reason」的那一帧
            // 在 Provider 层因 `$text === ''` 不会触发回调（见 parseOpenAiSse），
            // 所以绝大多数情况下这里仍会照常补发 —— 这是符合规范的默认行为。
            // 只有在**上游把 finish_reason 与正文放进同一帧**（因而已被原样透传）
            // 时，才跳过补发。
            if (!$upstreamFinishSent) {
                echo 'data: ' . json_encode([
                    'id'      => $requestId !== '' ? $requestId : 'chatcmpl-stream',
                    'object'  => 'chat.completion.chunk',
                    'created' => time(),
                    'model'   => $model,
                    'choices' => [[
                        'index'         => 0,
                        'delta'         => new \stdClass(),
                        'finish_reason' => $finalReason,
                    ]],
                ] + $this->personaNotice($result ?? null, $chatRequest), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                $this->flush();
            }

            echo "data: [DONE]\n\n";
            $this->flush();
        });
    }

    /**
     * 从一帧里取出推理增量文本（各上游字段名不统一）。
     *
     * 覆盖四类真实来源：
     *   · OpenAI 兼容系（DeepSeek / Qwen / GLM / vLLM）：`delta.reasoning_content`；
     *   · 部分实现：`delta.reasoning`；
     *   · Anthropic 系（zcode 主通道）：`delta.thinking`（thinking_delta 帧）；
     *   · Gemini 系（Antigravity）：`part.thought`（此处上游已归一到 text 字段）。
     *
     * ⚠️ 返回空串表示"这一帧没有可下发的思维"，调用方应直接跳过，
     *    **不能**下发一个空 reasoning_content 帧 —— 部分客户端会把它渲染成
     *    一个空的"思考中"气泡，反而制造噪声。
     */
    /**
     * 工具调用帧归一化：把各私有协议的形状统一成 OpenAI 规范。
     *
     * ⚠️ 入参可能是**单帧分片**（流式）或**整串分片**（非流式，见
     * `nonStreaming()` 里传的 `$chunk->toolCalls`）。两种都要先经
     * `ToolCalls::mergeDeltas()` 合并成「一次调用一条」，否则非流式下
     * 一次调用会被拆成 N 条残缺记录 —— 客户端无法执行，症状是
     * 「模型调不出工具」而日志却是 SUCCESS（2026-10-04 自定义供应商实测）。
     *
     * ⚠️ **字段名不同是致命差异**（2026-09-30 实测抓包）：
     *   Trae   `tool_calls[].function_call.{name,arguments,partial_arguments}`
     *   OpenAI `tool_calls[].function.{name,arguments}`
     * 直接把 Trae 的帧丢给 OpenAI 客户端 → 客户端读 `function.name` 得到 undefined，
     * 「工具调用列表」是空的 → Agent 型客户端（Trae Work）**看不到任何执行步骤**。
     *
     * 同时遵循 OpenAI **流式增量**语义：
     *   - 首帧带 `id` + `type` + `function.name`（有的还带首段 arguments）
     *   - 后续帧只带 `index` + `function.arguments` 增量，id/name 省略
     * 故这里**只在有值时才下发相应字段**，不硬塞空字符串
     * （硬塞 `id:""` 会让部分严格实现的客户端把工具调用判为非法）。
     */
    private function normalizeToolCalls(array $calls): array
    {
        // ── 先合并「增量分片」，再归一化形状 ─────────────────────────────────
        // 上游（OpenAI 兼容通道）一次调用会拆成 N 帧：首帧带 `id`/`type`/`function.name`、
        // 后续帧只带 `function.arguments` 的一段。流式下原样透传是**对的**（客户端按
        // `index` 自行累积）；但非流式响应必须给出「一次调用一条完整记录」——
        // Provider 侧 `array_merge` 攒出来的 `$chunk->toolCalls` 是**分片列表**，
        // 直接归一化会给客户端 N 条残缺调用（arguments 被劈成 N 段、多数条目没有
        // name/id），任何客户端都无法执行 → 表现为「模型调不出工具」，
        // 而日志是 SUCCESS、finish_reason=tool_calls，看起来一切正常。
        //
        // 实测（2026-10-04，自定义供应商 → new-api 中转 → deepseek-flash）：
        // 一次 list_dir 调用被拆成 10 帧，非流式响应里成了 10 条 tool_calls，
        // 参数分别是 `{`、`"`、`path`、`"`、`: `、`"`、`.`、`"`、`}`。
        //
        // 合并对**流式无害**（单帧单分片 = 透传，且 ToolCalls::mergeDeltas 幂等），
        // 对**非流式是必需** —— 详见 `App\Support\ToolCalls`。
        $calls = ToolCalls::mergeDeltas($calls);

        $out = [];
        $auto = 0;
        foreach ($calls as $call) {
            if (!is_array($call)) {
                continue;
            }
            // 兼容两种形状：OpenAI 的 `function` 与 Trae 的 `function_call`
            $fn = $call['function'] ?? $call['function_call'] ?? null;
            if (!is_array($fn)) {
                continue;
            }

            $item = ['index' => isset($call['index']) ? (int) $call['index'] : $auto++];

            $id   = $call['id'] ?? null;
            $type = (string) ($call['type'] ?? '');
            if (is_string($id) && $id !== '') {
                $item['id']   = $id;
                $item['type'] = $type !== '' ? $type : 'function';
            }

            $func = [];
            $name = $fn['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $func['name'] = $name;
                // name 属于首帧信息：若 id 缺失也要给出 type（否则客户端无法识别为函数调用）
                if (!isset($item['id'])) {
                    $item['type'] = $type !== '' ? $type : 'function';
                }
            }

            // 参数：优先 arguments，回退 partial_arguments（增量分片）；
            // 数组形态要转成 JSON 字符串（OpenAI 的 arguments 恒为字符串）。
            $args = $fn['arguments'] ?? null;
            if ($args === null || $args === '') {
                $args = $fn['partial_arguments'] ?? '';
            }
            if (is_array($args)) {
                $args = (string) json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (is_string($args) && $args !== '') {
                $func['arguments'] = $args;
            }

            if ($func !== []) {
                $item['function'] = $func;
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * 从一帧里提取推理/思维文本（多协议兼容）。
     *
     * ⚠️ **必须同时认「顶层字段」形态** —— 这是踩过的坑（2026-09-30）：
     * Trae / Doubao / Antigravity / Zcode(Anthropic) 走的是**私有协议**，
     * 它们的思维帧长这样（**没有 `choices`**）：
     *   Trae       `{"response":"","reasoning_content":"我们需要",...}`
     *   Doubao     `{"text":"…","end_type":…}`（`$inThink` 时 text 即思维）
     *   Antigravity`{"candidates":[{"content":{"parts":[{"thought":…,"text":…}]}}]}`
     *   Zcode      `{"type":"content_block_delta","delta":{"type":"thinking_delta","thinking":…}}`
     * 早期实现只认 OpenAI 形态的 `choices[0].delta.reasoning_content`，
     * 于是私有协议 Provider 回调的思维帧到这里**全部提取为空** →
     * 被调用方以 `if ($reasoning === '') return;` 静默丢弃 →
     * 实测 Trae Work 一次请求上游发了 **213 个思维帧、6556 个 reasoning token**，
     * 客户端却一个字节都收不到，表现为「只有正文、看不到任何思考过程」。
     *
     * 排查方法：`AIGW_DUMP_SSE=… php tools/dump-sse.php "…" trae/deepseek-v4.1-flash`
     * 抓上游原始帧，直接看思维帧的**顶层 key 名**。
     */
    private function extractReasoning(array $raw): string
    {
        // ① OpenAI 兼容形态：choices[0].delta.reasoning_content / reasoning / thinking
        $delta = $raw['choices'][0]['delta'] ?? null;
        if (is_array($delta)) {
            foreach (['reasoning_content', 'reasoning', 'thinking'] as $key) {
                $value = $delta[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        // ② 私有协议形态：思维在**顶层** `reasoning_content`
        //    （Trae：`{"response":"","reasoning_content":"…"}`，无 choices）
        $top = $raw['reasoning_content'] ?? null;
        if (is_string($top) && $top !== '') {
            return $top;
        }

        // ③ Anthropic 形态：顶层 delta.thinking / content_block.thinking
        $topDelta = $raw['delta'] ?? null;
        if (is_array($topDelta)) {
            foreach (['thinking', 'text'] as $key) {
                $value = $topDelta[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }
        $block = $raw['content_block'] ?? null;
        if (is_array($block) && is_string($block['thinking'] ?? null)) {
            return (string) $block['thinking'];
        }

        // ④ Gemini / Antigravity 形态：candidates[].content.parts[].thought=true 时 text 即思维
        $parts = $raw['candidates'][0]['content']['parts'] ?? null;
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (!is_array($part) || empty($part['thought'])) {
                    continue;
                }
                $value = $part['text'] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        // ⑤ Doubao 形态：`{"text":"…"}`（私有无 choices 协议）。
        //    ⚠️ 本方法**只在调用方已确认 `_aigw_kind === 'reasoning'` 时**才会执行
        //    （Controller 的流式与非流式分支都是先判 kind 再调本方法），
        //    因此这里取顶层 `text` 不会把正文误当思维 —— Provider 已用状态机
        //    （`$inThink` / `thought` / `thinking_delta`）判定过它属于思考区。
        if (!isset($raw['choices']) && !isset($raw['response'])) {
            $text = $raw['text'] ?? null;
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * 流式收尾帧上附带的思维来源说明（非标准字段，OpenAI 客户端会忽略）。
     *
     * 只有两种需要知会用户的情形：
     *  ① 网关本次确实注入了思维 → 告知是哪一份、哪一层；
     *  ② 网关没注入，但客户端补丁的残留被检出 → 明确声明"不是网关注入的"。
     * 两者都不成立时返回空数组，不污染正常响应。
     *
     * @param array|null $result complete() 的返回值
     * @return array<string, mixed>
     */
    private function personaNotice(?array $result, ChatRequest $chatRequest): array
    {
        $persona = is_array($result) ? ($result['persona'] ?? null) : null;
        if (is_array($persona) && !empty($persona['applied'])) {
            return ['aigw_persona' => [
                'id'      => (int) $persona['persona_id'],
                'name'    => (string) $persona['persona_name'],
                'mode'    => (string) $persona['mode'],
                'version' => (int) $persona['version'],
                'layer'   => (string) ($persona['layer'] ?? ''),
                'hit'     => false,
            ]];
        }

        $residue = $chatRequest->clientResidue;
        if (is_array($residue) && ($residue['found'] ?? false)) {
            return ['aigw_persona' => [
                'id'      => 0,
                'name'    => '',
                'applied' => false,
                'layer'   => 'client',
                'hit'     => true,
                'source'  => 'client_patch',
                'message' => '网关本次未注入任何思维；请求中的"思维"来自客户端提示词补丁'
                    . '（特征：' . implode('/', $residue['markers']) . '）。'
                    . '要彻底去掉请还原被改过的客户端配置文件。',
                'markers' => $residue['markers'],
                'bytes'   => (int) $residue['bytes'],
            ]];
        }

        return [];
    }

    /**
     * 非流式：一次性返回完整 completion
     */
    private function nonStreaming(ChatRequest $chatRequest, string $clientIp): Response
    {
        try {
            $content = '';
            // 推理正文与上游 id 单独累积（保真）：
            // 非流式路径此前回调只收一个参数，`$raw` 被**静默丢弃** ——
            // 于是 reasoning_content 完全不进响应、上游 id 也拿不到，
            // 与流式路径行为不一致（同一模型流式有思维、非流式没有）。
            $reasoning = '';
            $upstreamId = '';

            $result = $this->gatewayService->complete(
                $chatRequest,
                function (string $delta, array $raw = []) use (&$content, &$reasoning, &$upstreamId) {
                    if (($raw['_aigw_kind'] ?? '') === 'reasoning') {
                        // 思维**不并入正文**，单独走 message.reasoning_content
                        $reasoning .= $this->extractReasoning($raw);
                        return;
                    }
                    if ($upstreamId === '' && is_string($raw['id'] ?? null) && $raw['id'] !== '') {
                        $upstreamId = (string) $raw['id'];
                    }
                    $content .= $delta;
                },
                $clientIp
            );

            $chunk = $result['chunk'];
            $promptTokens = $chunk->promptTokens;
            $completionTokens = $chunk->completionTokens;
            if ($completionTokens === 0) {
                $completionTokens = (int) ceil(mb_strlen($content) / 1.6);
            }

            // ── 工具调用：非流式也必须回传 message.tool_calls ────────────────
            // 与流式同理：工具调用时 content 天然为空，只回 content 会让客户端
            // 收到「finish_reason=tool_calls + 空 content」的响应，表现为「没回答」。
            // 工具调用以 $chunk->toolCalls 为权威（Provider 侧统一累积，与上游协议无关）。
            $message = ['role' => 'assistant', 'content' => $content];
            if (!empty($chunk->toolCalls)) {
                $message['tool_calls'] = $this->normalizeToolCalls($chunk->toolCalls);
            }
            // 思维单独成字段（OpenAI 生态通行写法），**不并入 content** ——
            // 并入会污染正文、且客户端无法区分"思考"与"回答"。
            if ($reasoning === '' && $chunk->reasoningContent !== '') {
                // 上游未逐帧回调思维（如 Doubao 思考块被状态机归并）→ 用留痕值兜底，
                // 保证"流式拿得到、非流式也拿得到"。
                $reasoning = (string) $chunk->reasoningContent;
            }
            if ($reasoning !== '') {
                $message['reasoning_content'] = $reasoning;
            }

            $payload = [
                // 保真：优先用上游自己的响应 id；拿不到才回落到网关生成的 request_id
                'id'      => $upstreamId !== '' ? $upstreamId : $result['request_id'],
                'object'  => 'chat.completion',
                'created' => time(),
                'model'   => $result['model'],
                'choices' => [[
                    'index'         => 0,
                    'message'       => $message,
                    'finish_reason' => $chunk->finishReason !== '' ? $chunk->finishReason : 'stop',
                ]],
                'usage' => [
                    'prompt_tokens'     => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens'      => $promptTokens + $completionTokens,
                ],
            ];

            // 本次实际注入的思维（非标准字段，OpenAI 客户端会忽略）：
            // 有了它，"这次调用到底有没有带上思维"不用去翻数据库就能确认。
            $persona = $result['persona'] ?? null;
            if (is_array($persona) && !empty($persona['applied'])) {
                $payload['aigw_persona'] = [
                    'id'      => (int) $persona['persona_id'],
                    'name'    => (string) $persona['persona_name'],
                    'mode'    => (string) $persona['mode'],
                    'version' => (int) $persona['version'],
                    // system=系统内置（全局）思维；user=调用者自建思维
                    'layer'   => (string) ($persona['layer'] ?? ''),
                    'hit'     => false,
                ];
            } elseif ($chatRequest->clientResidue !== null && ($chatRequest->clientResidue['found'] ?? false)) {
                // 网关没注入，但请求里确实带着"思维" —— 来源是客户端提示词补丁。
                // 显式回传，否则用户会以为「网关思维没关干净」而反复排查网关侧开关。
                $payload['aigw_persona'] = [
                    'id'      => 0,
                    'name'    => '',
                    'applied' => false,
                    'layer'   => 'client',
                    'hit'     => true,
                    'source'  => 'client_patch',
                    'message' => '网关本次未注入任何思维；请求中的"思维"来自客户端提示词补丁'
                        . '（特征：' . implode('/', $chatRequest->clientResidue['markers']) . '）。'
                        . '要彻底去掉请还原被改过的客户端配置文件。',
                    'markers' => $chatRequest->clientResidue['markers'],
                    'bytes'   => (int) $chatRequest->clientResidue['bytes'],
                ];
            }

            return Response::json($payload);
        } catch (\Throwable $e) {
            $status = (int) $e->getCode();
            $status = ($status >= 400 && $status < 600) ? $status : 500;

            // 与非流式共用同一构造入口：`error.code` 必须是**业务码**（ErrorCategory），
            // 不能硬编码 'upstream_error' —— 那样参数错/限流/池空/内容拦截全长一个样，
            // 与调用日志的 error_code 也对不上（约定 9：异常分类必须带业务码）。
            return Response::json(GatewayService::errorPayloadFor($e), $status);
        }
    }

    /**
     * 模型列表（OpenAI /v1/models 格式）
     *
     * id 默认下发「供应商key/模型ID」（如 trae/Doubao-Seed-Evolving）：
     * 上游多个平台存在大量同名模型，裸名既分不清平台、又会在列表里去重后互相吞掉。
     * 带前缀后 id 唯一，且该写法可直接用于请求的 model 字段（resolveModel 已支持）。
     * 由全局开关 models_qualified_id 控制，关闭则回落为裸模型名。
     */
    public function models(Request $request): Response
    {
        if (!$this->switchEnabled('gateway_enabled')) {
            return $this->switchOff('网关总开关已关闭，暂时无法调用。', 'gateway_disabled');
        }
        if (!$this->switchEnabled('models_enabled')) {
            return $this->switchOff('模型列表能力已被管理员关闭。', 'models_disabled');
        }

        return Response::json([
            'object' => 'list',
            'data'   => $this->gatewayService->listModels(
                $this->switchEnabled('models_expose_providers'),
                $this->switchEnabled('models_qualified_id'),
                // 普通用户密钥（scope=uid）：只下发本人名下账号对应的模型；
                // 全局密钥/管理台（scope=null）仍下发完整目录。
                Tenant::scope()
            ),
        ]);
    }

    /**
     * 健康检查
     */
    public function health(Request $request): Response
    {
        $gatewayReady = $this->switchEnabled('gateway_enabled');
        $modelsReady = $gatewayReady && $this->switchEnabled('models_enabled');

        return Response::json([
            'status'  => $gatewayReady ? 'ok' : 'disabled',
            'name'    => (string) config('app.name', 'AI 聚合网关'),
            'version' => defined('AIGW_VERSION') ? AIGW_VERSION : '1.0.0',
            'models'  => $modelsReady ? count($this->gatewayService->listModels()) : 0,
        ]);
    }

    /**
     * GET /v1/status —— 公开状态页数据源（免鉴权）。
     *
     * 只暴露**聚合概览**：每供应商的启用状态 / 可用账号数 / 启用模型数 /
     * 今日调用与成功率 / 平均耗时。不含任何账号明细、凭据或日志正文。
     * 页面本体是 public/status.html（GET /status）。
     */
    public function statusSummary(Request $request): Response
    {
        if (!$this->switchEnabled('status_page_enabled')) {
            return Response::json(['ok' => false, 'error' => ['message' => '状态页未开启']], 404);
        }

        // 今日（本地时区零点起）按供应商聚合的调用量/成功率/耗时；口径与数据大屏一致
        $byProvider = [];
        try {
            foreach (($this->logRepository->stats(1)['by_provider'] ?? []) as $row) {
                $byProvider[(string) ($row['provider_key'] ?? '')] = $row;
            }
        } catch (\Throwable) {
            // 统计表异常不拖垮状态页：按空数据处理
        }

        $out = [];
        foreach ($this->providerRepository->all() as $p) {
            $key = (string) ($p['provider_key'] ?? '');
            if ($key === '') {
                continue;
            }

            $total = 0;
            $usable = 0;
            foreach ($this->accountRepository->all($key) as $account) {
                $total++;
                if ($account->isUsable()) {
                    $usable++;
                }
            }

            $st = $byProvider[$key] ?? null;
            $calls = $st ? (int) ($st['calls'] ?? 0) : 0;
            $okCalls = $st ? (int) ($st['success_calls'] ?? 0) : 0;

            $out[] = [
                'key'           => $key,
                'name'          => (string) ($p['display_name'] ?? $key),
                'enabled'       => (bool) ($p['enabled'] ?? false),
                'accounts'      => ['total' => $total, 'usable' => $usable],
                'models'        => count($this->modelRepository->enabledModelIds($key)),
                'calls_today'   => $calls,
                'ok_rate_today' => $calls > 0 ? round($okCalls / $calls * 100, 1) : null,
                'avg_ms_today'  => ($st && isset($st['avg_duration'])) ? (int) round((float) $st['avg_duration']) : null,
            ];
        }

        // 按今日调用量降序：活跃的供应商排前面，一眼看到主力线路
        usort($out, static fn($x, $y) => ($y['calls_today'] ?? 0) <=> ($x['calls_today'] ?? 0));

        return Response::json([
            'ok'           => true,
            'app'          => (string) config('app.name', 'AI 聚合网关'),
            'version'      => defined('AIGW_VERSION') ? AIGW_VERSION : '1.0.0',
            'generated_at' => date('c'),
            'providers'    => $out,
        ])->withHeader('Access-Control-Allow-Origin', '*');
    }

    /** 读取功能开关（缺省按开启处理，避免老库未初始化时误伤） */
    private function switchEnabled(string $key): bool
    {
        return (bool) $this->gatewayRepository->get($key, true);
    }

    /** 功能开关关闭时的 OpenAI 风格错误响应（503 服务不可用） */
    private function switchOff(string $message, string $code): Response
    {
        return Response::json(
            GatewayService::errorPayload($message, 'gateway_error', $code),
            503
        );
    }

    /**
     * 请求体大小护栏：超限直接回 413，不进入解析与调度。
     *
     * 判据优先用 `Content-Length` 头（零成本，真实客户端都会带）；
     * 头缺失/不可信时才回退到已读入的原始体字节数（`rawBody()` 有缓存，
     * 后续 `json()` 复用同一份，不会二次读取）。
     *
     * 返回 null 表示放行；否则返回 413 响应。
     */
    private function guardPayloadSize(Request $request): ?Response
    {
        $limit = max(1, (int) env('AIGW_MAX_PAYLOAD_BYTES', self::MAX_PAYLOAD_BYTES));

        $declared = (int) (string) $request->header('content-length', '0');
        $size = $declared > 0 ? $declared : strlen($request->rawBody());

        if ($size <= $limit) {
            return null;
        }

        return Response::json(
            GatewayService::errorPayload(
                '请求体过大（' . $size . ' 字节，上限 ' . $limit . ' 字节），请精简对话历史、'
                . '移除大附件或改用外链图片（避免内联 base64）后重试',
                'gateway_error',
                ErrorCategory::PAYLOAD_TOO_LARGE
            ),
            413
        );
    }

    /**
     * 解析客户端来源标识（用于四来源 Token 统计）
     */
    private function resolveSource(Request $request): string
    {
        $allowed = ['workbuddy', 'workbuddy_intl', 'codebuddy_cli', 'codebuddy_cn_ide'];
        foreach (['x-source', 'x-client-source', 'x-aigw-source'] as $header) {
            $val = strtolower(trim((string) $request->header($header)));
            if ($val !== '' && in_array($val, $allowed, true)) {
                return $val;
            }
        }

        $ua = strtolower((string) $request->header('user-agent'));
        if ($ua !== '') {
            if (str_contains($ua, 'workbuddy')) {
                return (str_contains($ua, 'intl') || str_contains($ua, 'international'))
                    ? 'workbuddy_intl'
                    : 'workbuddy';
            }
            if (str_contains($ua, 'codebuddy') || str_contains($ua, 'buddy')) {
                return str_contains($ua, 'ide') ? 'codebuddy_cn_ide' : 'codebuddy_cli';
            }
        }

        return '';
    }

    /**
     * 解析会话粘性标识（用于同会话绑定同一账号）
     */
    private function resolveSessionKey(Request $request, array $payload): string
    {
        foreach (['x-session-id', 'x-conversation-id', 'session-id'] as $header) {
            $val = trim((string) $request->header($header));
            if ($val !== '') {
                return mb_substr($val, 0, 80);
            }
        }
        foreach (['session_key', 'session_id', 'conversation_id'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field]) && trim($payload[$field]) !== '') {
                return mb_substr(trim($payload[$field]), 0, 80);
            }
        }

        return '';
    }

    private function clientIp(Request $request): string
    {
        return ClientIp::resolve($request);
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
