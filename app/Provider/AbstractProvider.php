<?php
/**
 * Provider 抽象基类：沉淀 SSE 解析、错误分类、代理应用等公共逻辑
 */

declare(strict_types=1);

namespace App\Provider;

use App\Provider\Dto\Account;
use App\Provider\Dto\ChatRequest;
use App\Provider\Dto\Chunk;
use App\Provider\Dto\CreditInfo;
use App\Provider\Dto\LoginSession;
use App\Repositories\ProxyRepository;
use App\Support\HttpClient;
use App\Support\Logger;
use App\Support\ToolCalls;

abstract class AbstractProvider implements Provider
{
    public function __construct(
        protected ProxyRepository $proxyRepository,
    ) {}

    public function displayName(): string
    {
        return ucfirst($this->id());
    }

    public function loginType(): string
    {
        return 'NONE';
    }

    public function hosts(): array
    {
        return [];
    }

    /**
     * 默认认为供应商按 OpenAI 形态原样转发 messages，图片块得以保留。
     *
     * ⚠️ 把内容**扁平化成文本**（Antigravity / Doubao）或转成 **Anthropic 消息块**
     *    （Zcode 主通道）的实现必须覆写为 false —— 否则图片会被静默丢弃。
     */
    public function supportsImageInput(): bool
    {
        return true;
    }

    /**
     * 已应用全局代理与超时的 HTTP 客户端
     */
    public function http(): HttpClient
    {
        $client = new HttpClient();
        $proxy = $this->proxyRepository->current();

        if ($proxy !== null && !empty($proxy['enabled'])) {
            $excluded = $proxy['excluded_provider_ids'] ?? [];
            if (!in_array($this->id(), $excluded, true)) {
                $client = $client->withProxy(
                    $proxy['host'] . ':' . $proxy['port'],
                    $proxy['username'] ?: null,
                    $proxy['password'] ?: null
                );
            }
        }

        return $client;
    }

    // ---------------------------------------------------------
    // SSE 解析
    // ---------------------------------------------------------

    /**
     * 解析 OpenAI 兼容的 SSE 流
     *
     * @param callable(string, array): (bool|null) $onDelta 每个增量内容回调
     */
    protected function parseOpenAiSse(
        string $url,
        array $payload,
        array $headers,
        Account $account,
        callable $onDelta,
        ?callable $onFirstByte = null
    ): Chunk {
        $chunk = new Chunk(model: (string) ($payload['model'] ?? ''));
        $firstByteSeen = false;

        $client = $this->http()->withHeaders($headers)->timeout(HttpClient::STREAM_SAFE_TIMEOUT, 20);

        $info = $client->stream('POST', $url, json_encode($payload, JSON_UNESCAPED_UNICODE), function (string $data) use (&$chunk, $onDelta, &$firstByteSeen, $onFirstByte) {
            if ($data === '[DONE]') {
                $chunk->completed = true;
                return false;
            }

            $decoded = json_decode($data, true);
            if (!is_array($decoded)) {
                return null;
            }

            // 上游以 SSE 事件形式回传错误（限流/上下文超长/鉴权失败等）时中断流并抛出，
            // 保留完整 JSON，交由 parseError() 分类（含 code=6004 / 11115 等业务码）。
            //
            // ⚠️ `code` 可能是**字符串**（如 Qoder 的 `"invalid_model_error"` /
            // `"invalid_request_error"`）。早期判据用 `(int) $code` 做白名单，
            // 非数字字符串会被转成 **0** → 命中 `[0, 200]` → 错误帧被**静默吞掉**，
            // 流继续读到结束、正文全程为空，最终被上层判成「连续空内容，内容被拦截」——
            // 用户看到的是风控提示，真实原因却是「模型名不对」。
            // 实测：`qoder_intl` 走新版协议调 `qfmodel` 回
            //   `event: error {"code":"invalid_model_error","message":"Unsupported model \"qfmodel\""}`
            // 被吞后误报 CONTENT_BLOCKED。
            // 现判据：数字 code 才走白名单；非数字（字符串型业务码）一律视为错误。
            $errCode = $decoded['code'] ?? null;
            $isBenignCode = is_numeric($errCode)
                ? in_array((int) $errCode, [0, 200], true)
                : false;   // 'invalid_model_error' 这类字符串码 → 不是健康码
            if (isset($decoded['error'])
                || ($errCode !== null && isset($decoded['message']) && !$isBenignCode)) {
                $raw = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                if ($raw === false) {
                    $raw = is_string($decoded['error'] ?? null)
                        ? (string) $decoded['error']
                        : (string) ($decoded['message'] ?? '上游返回错误');
                }
                throw new \RuntimeException($raw);
            }

            if (!$firstByteSeen) {
                $firstByteSeen = true;
                if ($onFirstByte) {
                    $onFirstByte();
                }
            }

            $chunk->raw = $decoded;
            $chunk->id = $chunk->id ?: (string) ($decoded['id'] ?? '');

            // 本帧是否已通过 $onDelta 下发过内容（正文/思维/工具调用）。
            // 用于避免「同一帧既带正文又带 usage」时被下发两次。
            $forwarded = false;

            // 兼容 OpenAI 与 Gemini 风格
            $choices = $decoded['choices'] ?? [];
            if (is_array($choices)) {
                foreach ($choices as $choice) {
                    $delta = $choice['delta'] ?? $choice['message'] ?? [];
                    $text = '';

                    if (is_array($delta)) {
                        // ── 推理增量：先累积留痕，下发与否在解析完 content 后决定 ────
                        // 推理模型把思维放在 `delta.reasoning_content`（DeepSeek/Qwen/GLM 系）
                        // 或 `delta.reasoning`（部分 OpenAI 兼容实现）。
                        //
                        // ⚠️ 早期实现**完全丢弃**该字段（只写进 Chunk 留痕、从不回调），
                        //    触发链是「首个 chunk 只有 reasoning → 本函数的 $text 恒为空 →
                        //    $onDelta 一次都不触发 → 网关侧不产出任何帧」，
                        //    客户端从发请求到正文出现**全程白屏**（实测首字节=总耗时
                        //    22.6s/40.5s/56.9s），且丢失了模型思维这一保真信息。
                        //
                        // ⚠️ **下发必须与 content 互斥**：上游存在「同一帧里既有
                        //    reasoning_content 又有 content」的形态（思维收尾与正文首帧
                        //    合并，DeepSeek/GLM 系实测有）。若两者各回调一次，
                        //    保真路径会把该帧原样回显一次（含两者）、再补一个纯 content 帧
                        //    → 客户端看到**思维与正文各重复一遍**。
                        //    故：有 content 时整帧交给正文分支（原样回显即已含思维）；
                        //        纯思维帧才单独以 `_aigw_kind=reasoning` 下发。
                        $reasoning = $delta['reasoning_content'] ?? $delta['reasoning'] ?? '';
                        if (is_string($reasoning) && $reasoning !== '') {
                            $chunk->reasoningContent .= $reasoning;
                        }

                        $content = $delta['content'] ?? '';
                        if (is_string($content)) {
                            $text = $content;
                        } elseif (is_array($content)) {
                            foreach ($content as $part) {
                                if (is_array($part) && isset($part['text'])) {
                                    $text .= $part['text'];
                                }
                            }
                        }

                        // 纯思维帧（本帧无正文）→ 单独下发，保真不丢帧。
                        // 约定：`_aigw_kind=reasoning`（与 tool_calls 同机制）。
                        if ($text === '' && is_string($reasoning) && $reasoning !== '') {
                            $rRaw = $decoded;
                            $rRaw['_aigw_kind'] = 'reasoning';
                            $rRaw['_aigw_raw']  = $data;
                            if ($onDelta('', $rRaw) === false) {
                                return false;
                            }
                            $forwarded = true;
                        }

                        if (!empty($delta['tool_calls'])) {
                            $chunk->toolCalls = array_merge($chunk->toolCalls, $delta['tool_calls']);
                            // ⚠️ 工具调用帧**必须**经 $onDelta 转发给网关，否则整条链路静默：
                            // 上游以 finish_reason=tool_calls 收尾、content 全程为空，
                            // 若只把 tool_calls 攒进 $chunk 而不回调，网关侧就收不到任何 delta 信号，
                            // 最终客户端只拿到一个「finish_reason=tool_calls」的空收尾帧 ——
                            // 表现为「日志显示成功（SUCCESS），但界面一片空白、没有任何回答」。
                            // 这正是 2026-09-30 zcode/glm-5.3-flash 那条记录的成因。
                            // 约定：$onDelta 的第二个参数（$raw）带 tool_calls 即为工具调用帧，
                            // 网关与 Controller 据此组 OpenAI 规范的 delta.tool_calls 下发。
                            $toolRaw = $decoded;
                            $toolRaw['_aigw_kind'] = 'tool_calls';
                            // 上游原始 data 行：保真路径优先原样回显（见 GatewayController::streaming）
                            $toolRaw['_aigw_raw']  = $data;
                            $result = $onDelta('', $toolRaw);
                            if ($result === false) {
                                return false;
                            }
                            $forwarded = true;
                        }
                    } elseif (is_string($delta)) {
                        $text = $delta;
                    }

                    if ($text !== '') {
                        $chunk->content .= $text;
                        // `_aigw_raw` = 上游这一帧的原始 data 行。
                        // 保真路径（GatewayController）优先原样回显它，避免网关重组
                        // chunk 时丢掉上游自定义字段（id/model/usage/extensions…）。
                        // ⚠️ 必须用副本、不能直接改 $decoded —— 它同时被
                        //    `$chunk->raw = $decoded` 引用，塞私有键会污染留痕。
                        $textRaw = $decoded;
                        $textRaw['_aigw_raw'] = $data;
                        $result = $onDelta($text, $textRaw);
                        if ($result === false) {
                            return false;
                        }
                        $forwarded = true;
                    }

                    if (!empty($choice['finish_reason'])) {
                        $chunk->finishReason = (string) $choice['finish_reason'];
                    }
                }
            }

            // usage 兼容多字段命名
            $usage = $decoded['usage'] ?? null;
            if (is_array($usage)) {
                $chunk->promptTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? $chunk->promptTokens);
                $chunk->completionTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? $chunk->completionTokens);
                $chunk->totalTokens = (int) ($usage['total_tokens'] ?? 0);
                $chunk->reasoningTokens = (int) ($usage['reasoning_tokens'] ?? ($usage['completion_tokens_details']['reasoning_tokens'] ?? $chunk->reasoningTokens));
                $this->applyCacheUsage($chunk, $usage);
            }
            foreach (['notify_usage', 'cn_credits_remain_info', 'ide_credits'] as $usageKey) {
                if (isset($decoded[$usageKey]) && is_array($decoded[$usageKey])) {
                    $u = $decoded[$usageKey];
                    $chunk->promptTokens = (int) ($u['prompt_tokens'] ?? $u['input_tokens'] ?? $chunk->promptTokens);
                    $chunk->completionTokens = (int) ($u['completion_tokens'] ?? $u['output_tokens'] ?? $chunk->completionTokens);
                    $chunk->reasoningTokens = (int) ($u['reasoning_tokens'] ?? $chunk->reasoningTokens);
                    $this->applyCacheUsage($chunk, $u);
                }
            }

            // ── 纯 usage 帧必须下发，否则客户端永远看不到 token 消耗 ─────────
            // OpenAI 系在 `stream_options.include_usage=true` 时，会在正文结束后
            // 单独补一个 `{"choices":[],"usage":{...}}` 的收尾帧（**没有任何正文**）。
            // 早期实现只把 usage 攒进 $chunk（供网关记账/日志），从不经 $onDelta 下发，
            // 于是网关侧收不到该帧 → 客户端 `rounds[].usage` 恒为空 →
            // 界面一直显示「累计未知 / cache 未报告」，用户以为模型没上报消耗。
            //
            // ⚠️ 判据用 `$forwarded`：同一帧里已带正文/思维/工具调用时绝不重复下发
            //    （否则保真回显会把这帧连同 usage 发一遍、这里再发一遍，客户端重复计数）。
            if (!$forwarded && is_array($usage) && $usage !== []) {
                $usageRaw = $decoded;
                $usageRaw['_aigw_kind'] = 'usage';
                // 上游原始 data 行：保真路径优先原样回显（见 GatewayController::streaming）。
                $usageRaw['_aigw_raw'] = $data;
                if ($onDelta('', $usageRaw) === false) {
                    return false;
                }
            }

            return null;
        });

        if (!empty($info['error'])) {
            Logger::warn("{$this->id()} 流式请求异常", $info);
        }

        // ── 传输层失败必须抛出，不能降级成「空正文」 ─────────────────────
        // 早期实现只把 curl 错误写进日志、然后返回一个空 Chunk。后果是：
        // 网关把「DNS 解析失败 / 连接被拒 / TLS 握手失败」误判成
        // 「上游出口安全层抑制了正文」→ 原地重采样 9 次 → 最终抛 CONTENT_BLOCKED
        // 回 400。于是一个**基建故障**被当成「内容被拦截」报给用户，
        // 既误导排查方向，也让账号池误以为遇到了内容问题。
        //
        // ⚠️ 必须排除「我们主动中断」：重采样机制靠回调返回 false 提前掐流，
        // 此时 curl 报 errno=23（Failure writing output to destination）——
        // 那是**预期行为**，若当成故障抛出，整个拒绝检测/重采样就废了（踩过）。
        $aborted = (bool) ($info['aborted'] ?? false);
        $hasCurlError = (int) ($info['errno'] ?? 0) !== 0 && (string) ($info['error'] ?? '') !== '';
        $status = (int) ($info['status'] ?? 0);
        $noBody = $chunk->content === '' && empty($chunk->toolCalls) && $chunk->finishReason === '';

        if ($hasCurlError && !$aborted && $chunk->content === '') {
            throw new \RuntimeException(sprintf(
                '%s 请求失败（传输层）：%s',
                $this->id(),
                (string) $info['error']
            ));
        }

        // 有 HTTP 错误码但一个字都没收到：把状态码与响应体交给 parseError 分类。
        // 2xx 的空正文不在此列（那是真正的"出口层抑制"，交由网关重采样处置）。
        // 主动中断时也不会走到这（中止时 status 多为 200），加 !$aborted 只是防御。
        //
        // ⚠️ 异常消息必须是**上游原始响应体**：限流/风控类错误上游回的是普通 JSON
        // （如 WorkBuddy 429 → {"code":6004,"msg":"您的使用量已超出频率限制…"}），
        // 业务码 6004 是**唯一**能把「模型级限流」与「网络抖动/账号故障」区分开的证据。
        // parseError 会对该消息做 json_decode + extractBusinessCode，若掺入自定义前缀
        // 会让 json_decode 失败、只能退化到正则兜底。HTTP 状态由异常的 code 单独携带，
        // 无需在文案里重复。早先只拼「HTTP 429 且无正文」，导致限流被当成 NETWORK 无限重试。
        if ($noBody && !$aborted && $status >= 400) {
            $upstreamBody = trim((string) ($info['body'] ?? ''));
            throw new \RuntimeException(
                $upstreamBody !== '' ? $upstreamBody : '（上游返回空正文）',
                $status
            );
        }

        // ── 2xx + 非 SSE 响应体 = 上游用"业务码"表达失败，必须抛出 ───────────
        // 有些上游（实测 Loomy 讯飞）鉴权/会话失效时**不用 HTTP 状态码表达**，
        // 而是回 HTTP 200 + 普通 JSON：{"code":"100002","desc":"缺少 token"}，
        // 且响应体没有 `data:` 前缀 → 全被 HttpClient 归入 rawBody、一个 SSE 帧都不产生。
        //
        // 上面那条 `$status >= 400` 分支接不住它（200 < 400），于是 parseOpenAiSse
        // 静默返回空 Chunk。网关层只能看到「HTTP 200 + 零帧 + 空正文」→ 判成
        // 「上游出口安全层抑制」→ 原地重采样 N 次 → 最终报 CONTENT_BLOCKED 400。
        // 于是一个**鉴权/会话故障**被包装成「内容被拦截」报给用户，方向彻底带偏。
        // （历史证据：storage/logs/app-2026-09-30.log 连续 40+ 条 glm-5.3-flash 空内容重采样）
        //
        // 判据收紧：只在「一个 data: 帧都没收到」且「体是带失败业务码的 JSON」时才抛，
        // 避免把正常 SSE 的尾部残片误判成错误体。
        if ($noBody && !$aborted && $status > 0 && $status < 400) {
            $upstreamBody = trim((string) ($info['body'] ?? ''));
            if ($upstreamBody !== '' && $this->looksLikeBusinessError($upstreamBody)) {
                throw new \RuntimeException($upstreamBody, $status);
            }
        }

        // 上游在 SSE 里自报「网络错误」类 finish_reason（实测智谱/GLM 网关会以
        // HTTP 200 + finish_reason=network_error + 空正文 的形态返回）。
        // 这类也是传输/服务侧故障，不能当"出口层抑制"重采样 —— 必须交给 parseError。
        if ($chunk->content === '' && empty($chunk->toolCalls)
            && in_array(strtolower($chunk->finishReason), ['network_error', 'upstream_error', 'server_error'], true)) {
            throw new \RuntimeException(sprintf(
                '%s 上游报告 %s（无正文）',
                $this->id(),
                $chunk->finishReason
            ), 502);
        }

        if (!$chunk->completed && $chunk->finishReason === '' && $chunk->content !== '') {
            $chunk->finishReason = 'stop';
        }

        // 已经下发过工具调用，却报 stop / 空收尾 → 归一为 `tool_calls`。
        // 否则严格按 OpenAI 规范实现的客户端会把前面的工具帧当成"普通回答的一部分"丢掉，
        // 表现为「模型调用了工具却没有任何执行」（详见 App\Support\ToolCalls::finishReason）。
        $chunk->finishReason = ToolCalls::finishReason($chunk->finishReason, $chunk->toolCalls);

        return $chunk;
    }

    // ---------------------------------------------------------
    // 错误分类（子类可按上游特征覆盖）
    // ---------------------------------------------------------

    public function parseError(int $status, string $body): ?array
    {
        $lower   = strtolower($body);
        $decoded = json_decode($body, true);
        $decoded = is_array($decoded) ? $decoded : null;
        $bizCode = $this->extractBusinessCode($decoded, $body);

        // 传输/基建故障：**必须先于 isContentBlocked 判断**。
        // 实测「网络错误，错误id：…，请稍后重试」（智谱 code=1234）与
        // `Could not resolve host: xxx`（curl errno=6）都是传输层问题，
        // 却因文案里含「错误/blocked」之类字眼可能被下游误读成内容拦截。
        // 归为 NETWORK：可重试、不计账号错误数、不回「内容被拦截」误导用户。
        if ($this->isNetworkFailure($lower, $status)) {
            return ['code' => ErrorCategory::NETWORK, 'message' => $this->extractMessage($body)];
        }

        // 推理预算不足：**必须先于 isContentBlocked 判断**。
        // 网关在「推理吃满 max_tokens、正文为空」时抛的文案里含「内容审核」字样
        // （为了向用户解释"这不是那个问题"），若按文案匹配会被 isContentBlocked 抢走，
        // 于是"预算不够"再次被报成"内容违规" —— 正是本分类要修的病。
        if ($this->isReasoningBudgetShort($lower)) {
            return ['code' => ErrorCategory::REASONING_BUDGET, 'message' => $this->extractMessage($body)];
        }

        // 上游风控拦截（豆包/火山 shark_admin，710022004）：**必须先于 isContentBlocked**。
        // 它的 error_msg 是 "rate limited"、性质是"上游拒绝"，极易被两条现成分支抢走：
        //   · 按文案 → 429 限流分支（SOFT_RATE）；
        //   · 按"上游拒绝" → CONTENT_BLOCKED。
        // 但实测（tools/probe-doubao-risk.php）它是**账号/会话级**风控：同账号连"你好"
        // 也拦、匿名请求则回"登录已过期" → 与内容无关、与 IP 无关，就是该号被标记。
        // 故必须独立归 RISK_CONTROL（可换号 + 冷却本账号），否则用户会一直"改提示词"。
        if ($this->isRiskControl($lower, $bizCode)) {
            return ['code' => ErrorCategory::RISK_CONTROL, 'message' => $this->extractMessage($body)];
        }

        // 内容审核：请求内容被上游安全策略拒绝，与账号健康无关。
        // 必须先于状态码判断 —— 上游多在 SSE 里发 error 事件，抛异常时 code=0，
        // 落到下面 `$status >= 500 || $status === 0` 就会被误判成 UPSTREAM。
        if ($this->isContentBlocked($lower, $bizCode)) {
            return ['code' => ErrorCategory::CONTENT_BLOCKED, 'message' => $this->extractMessage($body)];
        }

        // 上下文超长：换号无法解决，直接判定为客户端错误（由网关回 400）
        if ($this->isContextTooLong($lower, $bizCode)) {
            return ['code' => ErrorCategory::CONTEXT_TOO_LONG, 'message' => $this->extractMessage($body)];
        }

        // 请求体过大（413）：与账号健康无关的**请求侧**体积问题，换号无意义。
        // 必须先于 4xx 兜底 —— 否则会被记成 CLIENT（语义偏差）；
        // 若上游把它包在 200 信封里（抛异常时 code=0），还会掉进
        // `$status >= 500 || $status === 0` 分支累加账号错误数、误伤健康账号。
        if ($this->isPayloadTooLarge($lower, $status)) {
            return ['code' => ErrorCategory::PAYLOAD_TOO_LARGE, 'message' => $this->extractMessage($body)];
        }

        // 工具/参数 Schema 非法：请求侧的格式问题，与账号健康无关。
        // 同样必须早于状态码判断 —— 上游在 SSE error 事件里抛异常时 code=0，
        // 落到 `$status >= 500 || $status === 0` 会被记成 UPSTREAM，
        // 于是「客户端发来的 tools 结构不合法」就被算成「这个账号有问题」，
        // 连打几次把**完全健康**的账号推进冷却（实测踩过）。
        if ($this->isInvalidRequest($lower, $bizCode, $status)) {
            return ['code' => ErrorCategory::CLIENT, 'message' => $this->extractMessage($body)];
        }

        // 模型不可用：上游明确回「该模型对当前账号未开通/不存在」类业务码
        // （Trae 4023）。**换号无意义**（是模型维度的问题，账号池里换谁都一样），
        // 更**绝不能**归 UPSTREAM —— SSE error 事件抛出时 code=0，会掉进
        // `$status >= 500 || $status === 0` 分支累加账号错误数，把健康账号打进冷却，
        // 用户侧最终只看到「账号池中没有可用账号」，把选错模型误报成账号池故障。
        if ($this->isModelUnavailable($lower, $bizCode)) {
            return ['code' => ErrorCategory::MODEL_UNAVAILABLE, 'message' => $this->extractMessage($body)];
        }

        // 上游节点级故障：上游某个推理节点自身执行失败，与账号健康无关。
        // **必须先于状态码兜底判断** —— 这类错误同样以 HTTP 200 + `event:error`
        // 下发（`code=400`），抛异常时 `$status=0`，不显式识别就会掉进
        // `$status >= 500 || $status === 0` 被记成 UPSTREAM → `markError` 累加错误数。
        //
        // 实测危害（2026-09-30，Qoder 国内版）：账号下 13 个模型全部可用，
        // **仅 `qfmodel` 命中坏节点 `oa_qwen-plus-main`**；调用它一次即把账号打成 ERROR，
        // 此后整个 Qoder 供应商所有模型都报「账号池中没有可用账号」——
        // 「选错一个模型」直接拖垮整个供应商，必须按节点维度隔离。
        if ($this->isUpstreamNodeFailure($lower)) {
            return ['code' => ErrorCategory::UPSTREAM_NODE, 'message' => $this->extractMessage($body)];
        }

        // 模型级限流：上游业务码 6004（可能 HTTP 200 + 业务码，故先于状态码判断）
        if ($bizCode === 6004) {
            return [
                'code'           => ErrorCategory::MODEL_RATE,
                'message'        => $this->extractMessage($body),
                // 冷却时长优先取结构化字段，其次解析文案里的「将在 … 重置」绝对时间。
                // WorkBuddy 实测：msg = "您的使用量已超出频率限制，将在 2026-09-30 17:47:56 UTC+8 重置…"
                // —— 没有 reset_time 字段，只有自然语言，不解析就只能退化为默认软冷却，
                // 导致冷却窗口短于上游真实重置点，到期后继续撞 429。
                'retry_after_ms' => $this->extractRetryAfterMs($decoded)
                    ?: $this->extractResetAtFromText($this->extractMessage($body)),
            ];
        }

        // 额度类
        foreach (['insufficient', 'quota exceeded', 'exceeded your current quota', 'credit', 'balance', '额度', '余额不足', '积分不足', 'no available quota'] as $needle) {
            if (str_contains($lower, $needle)) {
                return ['code' => ErrorCategory::QUOTA, 'message' => $this->extractMessage($body)];
            }
        }

        // 登录失效
        if ($status === 401) {
            foreach (['token expired', 'invalid token', 'unauthorized', 'unauthenticated', 'session', '登录', '未授权'] as $needle) {
                if (str_contains($lower, $needle)) {
                    return ['code' => ErrorCategory::SESSION_DEAD, 'message' => $this->extractMessage($body)];
                }
            }
            return ['code' => ErrorCategory::SESSION_DEAD, 'message' => $this->extractMessage($body)];
        }
        if ($status === 403) {
            return ['code' => ErrorCategory::SESSION_DEAD, 'message' => $this->extractMessage($body)];
        }

        // 限流：429 优先按「模型级限流」处理。
        // 上游把可恢复用量限制回在 429 上（WorkBuddy code=6004、通用 rate limit），
        // 这类问题**换账号/换模型即可继续**，与「账号本身被限流」是两码事：
        //   · MODEL_RATE  → 只冷却「本账号+本模型」，账号其余模型照常可用；
        //   · SOFT_RATE   → 冷整个账号，代价大且会误伤。
        // 因此这里默认归 MODEL_RATE，把账号级软冷却留给
        // 「无模型上下文/上游只表态账号被限」的场景（由网关侧兜底）。
        if ($status === 429 || str_contains($lower, 'rate limit') || str_contains($lower, 'too many requests') || str_contains($lower, '频繁')) {
            $retryAfter = $this->extractRetryAfterMs($decoded)
                ?: $this->extractResetAtFromText($this->extractMessage($body));

            if ($retryAfter > 0) {
                return [
                    'code'           => ErrorCategory::MODEL_RATE,
                    'message'        => $this->extractMessage($body),
                    'retry_after_ms' => $retryAfter,
                ];
            }

            return ['code' => ErrorCategory::SOFT_RATE, 'message' => $this->extractMessage($body)];
        }

        // 资源不存在
        if ($status === 404) {
            return ['code' => ErrorCategory::NOT_FOUND, 'message' => $this->extractMessage($body)];
        }

        // 客户端错误：不重试
        if ($status >= 400 && $status < 500) {
            return ['code' => ErrorCategory::CLIENT, 'message' => $this->extractMessage($body)];
        }

        if ($status >= 500 || $status === 0) {
            return ['code' => ErrorCategory::UPSTREAM, 'message' => $this->extractMessage($body)];
        }

        return null;
    }

    /**
     * 判断一个「非 SSE 响应体」是否是上游用业务码表达的错误信封。
     *
     * 用途：2xx + 无任何 `data:` 帧时，区分「真·空响应（出口层抑制）」与
     *       「上游把错误塞在普通 JSON 里」（如 Loomy `{"code":"100002","desc":"缺少 token"}`）。
     *
     * 判据要**窄**（约定 17）：只认「有失败的 code 字段」或「有 error 对象/明确错误文案」，
     * 不认泛化短文本，否则正常心跳/注释会被误判成错误。
     * ⚠️ code 必须按**字符串**比白名单（约定 18）：`"100002"` 这类前导非数字串
     *    用 `(int)` 转换语义会失真。
     */
    protected function looksLikeBusinessError(string $body): bool
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return false;
        }

        // 明确含 error 对象 / 错误文案
        if (isset($decoded['error']) || isset($decoded['error_msg']) || isset($decoded['errorCode'])) {
            return true;
        }

        // 含 code 字段且不是"成功码"
        $code = $decoded['code'] ?? null;
        if ($code === null) {
            return false;
        }
        $codeStr = is_scalar($code) ? (string) $code : '';
        if ($codeStr === '') {
            return false;
        }
        $okCodes = ['0', '200', '000000', '0000'];
        if (in_array($codeStr, $okCodes, true)) {
            return false;
        }
        // 数字码同样只在非 0 时算失败
        if (is_numeric($codeStr) && (int) $codeStr === 0) {
            return false;
        }

        return true;
    }

    /**
     * 从上游错误体中提取业务错误码（兼容多种信封结构）
     */
    protected function extractBusinessCode(?array $decoded, string $body): int
    {
        if (is_array($decoded)) {
            $candidates = [
                $decoded['code'] ?? null,
                $decoded['error_code'] ?? null,
                $decoded['errorCode'] ?? null,
                $decoded['error']['code'] ?? null,
                $decoded['data']['code'] ?? null,
                $decoded['ResponseMetadata']['Error']['Code'] ?? null,
            ];
            foreach ($candidates as $candidate) {
                if ($candidate !== null && is_numeric($candidate) && (int) $candidate !== 0) {
                    return (int) $candidate;
                }
            }
        }

        // 文本兜底：仅匹配形如 "code":6004 / code=6004 / code: 6004 的显式写法
        if (preg_match('/code\D{0,3}(6004|11115)/i', $body, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * 是否为「推理预算不足」。
     *
     * 只认网关自己抛出的**结构化指纹**，不做模糊文案匹配 ——
     * 因为该文案为解释「不是内容问题」而含「内容审核」字样，
     * 任何模糊匹配都会再次被 isContentBlocked 吸走，病根重犯。
     *
     * 指纹：`推理预算不足：模型把全部 N tokens 用于思考`
     *      （见 GatewayService::complete 的预算耗尽兜底分支）。
     */
    protected function isReasoningBudgetShort(string $lower): bool
    {
        return str_contains($lower, '推理预算不足')
            || str_contains($lower, 'reasoning budget exhausted');
    }

    /**
     * 是否属于「内容审核拦截」错误。
     *
     * 特征：提示词/回复被上游安全策略拒绝（而非账号或服务出问题）。
     * 典型文案：`sensitive content`（Trae 实测命中）、`content policy`、
     * `moderation`、`safety system`、`内容审核`、`违规内容`、`含敏感内容`。
     *
     * 判定后归为 CONTENT_BLOCKED：**不重试、不惩罚账号**，直接回 400 并给出明确文案。
     */
    protected function isContentBlocked(string $lower, int $bizCode = 0): bool
    {
        // ⚠️ 豆包/火山风控（shark_admin / error_code=710022004）**不在这里判**：
        // 它虽然也是"上游拒绝"，但性质是**账号/会话级**风控（换号可恢复），
        // 而 CONTENT_BLOCKED 是 isRequestFault=true（不换号、改提示词）。
        // 两者调度动作完全相反，必须分开 —— 见 isRiskControl()。
        // 若在此处按文案收下，会因 error_msg="rate limited" 先被本方法抢走，
        // 用户就被引向"反复改措辞"这条永远修不好的路（2026-09-30 实测）。
        foreach ([
            'sensitive content', 'sensitive_content', 'content blocked', 'content_blocked',
            'content policy', 'content_policy', 'content filter', 'flagged as',
            'moderation', 'safety system', 'safety check', 'prohibited content',
            'blocked by', 'violates', 'policy violation',
            '内容审核', '内容不合规', '违规内容', '含敏感内容', '敏感内容',
            '内容被拦截', '内容违规', '风控拦截', '安全审核',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 是否属于「上游风控拦截」——账号/会话被上游风控系统标记。
     *
     * 实测形态（豆包/火山 shark_admin，HTTP 200 + SSE，抛异常时 code=0）：
     *   {"error_code":710022004,"error_msg":"rate limited",
     *    "extra":{"decision":"{\"from\":\"shark_admin\",\"type\":\"verify\",
     *              \"subtype\":\"semantic_reasoning\",\"verify_scene\":\"doubao_message_web\"}"}}
     *
     * ⚠️ 它与「内容审核」的关键区别（`tools/probe-doubao-risk.php` 实测）：
     *   · 同一账号连「你好」这种**完全无害**的请求也被拦 → 不是内容问题；
     *   · **匿名请求（不带 cookie）返回 710012001「登录已过期」而非风控码**
     *     → 端点未封 IP，被标记的是这个**登录会话/账号**本身。
     *   故归 RISK_CONTROL：可换号重试 + 冷却本账号，而非"改提示词"。
     *
     * ⚠️ 判据必须排在 429 之前：它的 error_msg 写着 "rate limited"，
     *   只按文案会被 `str_contains($lower,'rate limit')` 抢成 SOFT_RATE，
     *   于是"账号被风控"被报成"触发限流"，语义错位且丢掉了换号动作。
     *   parseError 中本方法已排在状态码/限流分支之前。
     */
    protected function isRiskControl(string $lower, int $bizCode = 0): bool
    {
        if ($bizCode === 710022004) {
            return true;
        }

        // 结构化指纹：风控系统标识（即使 error_code 被上游改写也能识别）
        foreach (['710022004', 'shark_admin', 'semantic_reasoning'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 是否属于「模型不可用」——该模型对当前账号未开通 / 已下线。
     *
     * 主判据是上游业务码（最权威）：
     *   · Trae `4023` —— 实测 `custom_model_gemini` / `custom_model_kimi` /
     *     `custom_model_claude` / `custom_model_deepseek_v4` / `custom_model_1M*` /
     *     `custom_model_placeholder` 全部命中；文案是泛化的
     *     `We're sorry, something went wrong. Please try again later or contact our
     *     support team for assistance.`（**不能靠文案判断**，与真·上游 500 完全同形）。
     *   对照：模型名压根不存在时是 `4001`，本账号未开通的 `custom_model_gpt-5`
     *   反而可用 —— 说明 4023 是「模型维度、与账号无关」的失败。
     *
     * 归为 MODEL_UNAVAILABLE：不重试、不计账号错误数、不冷却，直接回 400 并提示换模型。
     */
    protected function isModelUnavailable(string $lower, int $bizCode = 0): bool
    {
        if ($bizCode === 4023) {
            return true;
        }

        foreach ([
            'model not available', 'model is not available', 'model unavailable',
            'model not found', 'model does not exist', 'no such model',
            'unsupported model', 'model not supported', 'not enabled for',
            '模型不存在', '模型未开通', '模型不可用', '不支持该模型',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 是否属于「上游节点级故障」——上游自己的推理节点执行失败，与本账号健康无关。
     *
     * 实测形态（Qoder 国内版，2026-09-30）：
     *   `qoder [FAIL]node:oa_qwen-plus-main msg:Execution failed: null（上游 code=400）`
     * 上游以 HTTP 200 + `event:error` 信封下发，抛异常时 `$status=0`。
     *
     * ⚠️ **绝不能归 UPSTREAM**：UPSTREAM 在 `AccountPool::penalize()` 里走 `markError`
     * 累加账号错误数，连续几次就把**完全健康**的账号推进 COOLING；
     * 而实测同一账号下 13 个模型全好、只有 `qfmodel` 命中坏节点 ——
     * 一次失败就把整个 Qoder 供应商打到「账号池中没有可用账号」。
     * 归 UPSTREAM_NODE：可重试换账号（同模型在别的账号/节点可能正常），但不计账号错误数。
     *
     * 判据取**上游节点执行失败**的精确签名，避免吃掉真正的业务码错误：
     *   · 上游失败标记 `[FAIL]` 后紧跟 `node:`（本网关原文透传，形态稳定）；
     *   · `Execution failed` / `节点执行失败`。
     */
    protected function isUpstreamNodeFailure(string $lower): bool
    {
        // ① 上游节点失败标记：[FAIL]node:xxx
        if (preg_match('/\[fail\]\s*node\s*:/i', $lower)) {
            return true;
        }

        // ② 节点执行失败文案（中英）
        foreach (['execution failed', '节点执行失败'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 是否属于「传输层 / 基建故障」——与内容、账号都无关的失败。
     *
     * 覆盖三类实测形态：
     *   ① curl 层错误：`Could not resolve host: api2-v2.qoder.com.cn`（errno=6 域名解析失败）、
     *      `Connection refused`、`SSL: no alternative certificate subject name matches`、
     *      `Operation timed out`、`Failure writing output to destination`；
     *   ② 上游自报网络错误：智谱/GLM `{"code":"1234","message":"网络错误，错误id：…，请稍后重试"}`
     *      （HTTP 200 或 500 皆可能，且 finish_reason=network_error）；
     *   ③ 网关封装文案：`× 请求失败（传输层）：…` / `× 上游报告 network_error（无正文）`。
     *
     * 归为 NETWORK：可重试（网络抖动会自愈），但**不计账号错误数**（换号也解析不出域名）。
     */
    protected function isNetworkFailure(string $lower, int $status = 0): bool
    {
        foreach ([
            // curl 错误原文
            'could not resolve host', 'couldn\'t resolve host', 'name or service not known',
            'connection refused', 'connection reset', 'connection timed out',
            'operation timed out', 'timed out after',
            'ssl:', 'ssl connect error', 'certificate', 'peer certificate',
            'failure writing output to destination',
            'empty reply from server', 'transfer closed',
            // 传输层封装文案（本网关自己抛的）
            // ⚠️ 这里**不能**放裸「无正文」：它是通用措辞，任何 reason 都会命中。
            // 历史上 `parseOpenAiSse` 抛「HTTP 429 且无正文」时，429 限流（业务码 6004）
            // 就被这条抢占、误判成 NETWORK —— NETWORK 虽可重试但不计账号、不冷却模型，
            // 于是一次模型级限流被当成网络抖动反复重试，用户侧表现为"调不动且无提示"。
            // 现异常消息改为直接携带上游 JSON 正文，故此处按精确形态匹配即可。
            // 下面三条覆盖 parseOpenAiSse 抛出的三类「上游自报错误」（结尾带“（无正文）”），
            // 保持它们原有的 NETWORK 归类；判据保持窄，避免吃掉真正的业务码错误。
            '请求失败（传输层）', '上游报告 network_error',
            '上游报告 upstream_error', '上游报告 server_error',
            // 上游自报网络错误（中英）
            '网络错误', 'network error', 'network_error', '服务暂时不可用', 'service temporarily unavailable',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 是否属于「请求自身格式非法」——与账号健康无关的请求侧故障。
     *
     * 特征：客户端发来的请求体结构不合上游规范（工具/参数 Schema 非法、
     * 参数校验失败、必填字段缺失等）。换账号、等冷却都不会好，重试纯属浪费额度，
     * 更不能计入账号错误数（否则一份畸形请求就能把健康账号打进冷却）。
     *
     * 典型文案（Trae 实测命中，业务码 4027）：
     *   `tool call failed: tools.16.function.parameters/properties:
     *    invalid JSON Schema; check the schema keywords and references`
     *
     * ⚠️ 判据要足够窄，避免把「上游自己 500」也吃进来：
     * 只认显式的 schema / 参数校验措辞，不认泛化的 "invalid" / "failed"。
     */
    protected function isInvalidRequest(string $lower, int $bizCode = 0, int $status = 0): bool
    {
        // Trae：工具调用参数校验失败的业务码（最权威的判据）
        if ($bizCode === 4027) {
            return true;
        }

        // 上游明确 5xx / 503 时，文案里出现 "json schema" 之类多半是它自己的内部错误，
        // 不能仅凭措辞判成客户端问题（否则会漏掉真正的上游故障）。
        if ($status >= 500 || ($status === 0 && $bizCode === 0 && $lower === '')) {
            return false;
        }

        foreach ([
            'invalid json schema', 'json schema', 'jsonschema',
            'check the schema keywords', 'schema validation',
            'validation error', 'validationexception', 'failed to validate',
            'cannot unmarshal', 'unmarshal', 'json parse error',
            'invalid parameter', 'parameters is required', 'missing required',
            '请求参数', '参数校验', '参数错误', '格式错误',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 是否属于「上下文超长」错误
     */
    protected function isContextTooLong(string $lower, int $bizCode): bool
    {
        if ($bizCode === 11115) {
            return true;
        }

        foreach ([
            'context_length_exceeded', 'context length exceed', 'maximum context length',
            'context length is too long', 'context too long', 'prompt is too long',
            'reduce the length', 'too many tokens', 'exceed max tokens',
            '上下文长度', '上下文超长', '超出上下文', '输入过长',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 是否属于「请求体过大」错误（HTTP 413 / request entity too large）。
     *
     * 与账号健康无关：这是**请求侧**的体积问题，换号、换模型都不会好，
     * 重试纯属浪费额度。归为 PAYLOAD_TOO_LARGE：不重试、不惩罚账号，
     * 由网关回 413 并提示精简请求体（历史消息/附件/base64 图片）。
     *
     * 判据保持窄：只认 413 状态码或显式的体积超限措辞，
     * 不认泛化的 "too large"（避免吃掉真正的上下文超长等错误）。
     */
    protected function isPayloadTooLarge(string $lower, int $status = 0): bool
    {
        if ($status === 413) {
            return true;
        }

        foreach ([
            'request entity too large', 'payload too large', 'content too large',
            'request body too large', 'body too large', 'entity too large',
            '请求体过大', '请求实体过大', '报文过大',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 从上游错误体中解析「多久后可重试」的毫秒数（用于模型级限流的冷却时长）。
     * 兼容毫秒时间戳 / 秒时间戳 / 剩余秒数 / retry_after 等写法。
     */
    protected function extractRetryAfterMs(?array $decoded): int
    {
        if (!is_array($decoded)) {
            return 0;
        }

        $values = [
            $decoded['retry_after_ms'] ?? null,
            $decoded['reset_time'] ?? null,
            $decoded['resetTime'] ?? null,
            $decoded['reset_at'] ?? null,
            $decoded['resetAt'] ?? null,
            $decoded['next_reset'] ?? null,
            $decoded['data']['reset_time'] ?? null,
            $decoded['data']['resetTime'] ?? null,
            $decoded['error']['reset_time'] ?? null,
        ];

        $now = now_ms();
        foreach ($values as $value) {
            if ($value === null || $value === '' || !is_numeric($value)) {
                continue;
            }
            $n = (float) $value;
            if ($n > 1e12) {
                return max(0, (int) $n - $now);       // 毫秒时间戳
            }
            if ($n > 1e9) {
                return max(0, (int) ($n * 1000) - $now); // 秒时间戳
            }
            return max(0, (int) ($n * 1000));         // 视为剩余秒数
        }

        if (isset($decoded['retry_after']) && is_numeric($decoded['retry_after'])) {
            return max(0, (int) ((float) $decoded['retry_after'] * 1000));
        }

        return 0;
    }

    /**
     * 从上游文案里解析「限流重置时间」并换算成剩余毫秒。
     *
     * 上游常把重置点写在自然语言里而不给结构化字段。WorkBuddy 实测原文：
     *   `您的使用量已超出频率限制，将在 2026-09-30 17:47:56 UTC+8 重置，您也可以切换其他模型继续使用。`
     *
     * 支持的形态：
     *   · 绝对时刻 + 时区：`2026-09-30 17:47:56 UTC+8`、`2026-09-30 17:47:56 +08:00`
     *   · 绝对时刻（本地时间）：`2026-09-30 17:47:56`、`2026/09/30 17:47`
     *   · 相对时长：`2 小时后重置`、`30 分钟后`、`retry in 45 seconds`、`重置时间：60s`
     *
     * 返回「从现在起的剩余毫秒」，解析不到或已过期返回 0。
     * 上界钳到 24h，避免上游给一个荒谬的远期时间把模型冻结太久。
     */
    protected function extractResetAtFromText(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $text = trim($text);
        $now  = (int) floor(now_ms() / 1000);

        // ① 绝对时刻（UTC+8 / UTC-5 显式时区）
        // 关键：必须用 gmmktime 把「墙上时间」先当作 UTC，再减时区偏移，得到真实 Unix 时间戳。
        // 若改用 mktime（按服务器本地时区解释），当服务器本身就是 UTC+8 时会被重复偏移一次。
        if (preg_match(
            '/(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})[ T](\d{1,2}):(\d{2})(?::(\d{2}))?\s*UTC\s*([+-])(\d{1,2})/i',
            $text,
            $m
        ) === 1) {
            $offset = ((int) $m[8]) * 3600;
            if ($m[7] === '-') {
                $offset = -$offset;
            }
            $wall = gmmktime((int) $m[4], (int) $m[5], (int) ($m[6] ?? 0), (int) $m[2], (int) $m[3], (int) $m[1]);
            if ($wall !== false) {
                return $this->clampRetryMs(($wall - $offset - $now) * 1000);
            }
        }

        // ② 绝对时刻（带 +08:00 / +0800 数字时区）
        if (preg_match(
            '/(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})[ T](\d{1,2}):(\d{2})(?::(\d{2}))?\s*([+-])(\d{2}):?(\d{2})/',
            $text,
            $m
        ) === 1) {
            $offset = ((int) $m[8]) * 3600 + ((int) $m[9]) * 60;
            if ($m[7] === '-') {
                $offset = -$offset;
            }
            $wall = gmmktime((int) $m[4], (int) $m[5], (int) ($m[6] ?? 0), (int) $m[2], (int) $m[3], (int) $m[1]);
            if ($wall !== false) {
                return $this->clampRetryMs(($wall - $offset - $now) * 1000);
            }
        }

        // ③ 绝对时刻（无时区 → 按服务器本地时区理解）
        if (preg_match(
            '/(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})[ T](\d{1,2}):(\d{2})(?::(\d{2}))?/',
            $text,
            $m
        ) === 1) {
            $wall = mktime((int) $m[4], (int) $m[5], (int) ($m[6] ?? 0), (int) $m[2], (int) $m[3], (int) $m[1]);
            if ($wall !== false) {
                return $this->clampRetryMs(($wall - $now) * 1000);
            }
        }

        // ④ 相对时长：N 小时 / 分钟 / 秒（中英）
        // ⚠️ 正则结尾用 (?![a-zA-Z]) 而非 \b：/u 模式下中文属 \w，「小时」与「后」之间
        // 不存在词边界，用 \b 会导致「2 小时后重置」整条匹配失败（实测踩过）。
        // 同时**不收单字母 h/m/s**，避免把「HTTP」之类误吞成时长。
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:小时|小時|个小时|個小時|hours?|hrs?)(?![a-zA-Z])/ui', $text, $m) === 1) {
            return $this->clampRetryMs((int) ((float) $m[1] * 3600 * 1000));
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:分钟|分鐘|minutes?|mins?)(?![a-zA-Z])/ui', $text, $m) === 1) {
            return $this->clampRetryMs((int) ((float) $m[1] * 60 * 1000));
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:秒|秒钟|秒鐘|seconds?|secs?)(?![a-zA-Z])/ui', $text, $m) === 1) {
            return $this->clampRetryMs((int) ((float) $m[1] * 1000));
        }

        return 0;
    }

    /** 冷却时长归一：小于 1 秒视为 0（已过期/解析失败），上界 24 小时 */
    private function clampRetryMs(int $ms): int
    {
        if ($ms <= 1000) {
            return 0;
        }

        return min($ms, 86400000);
    }

    protected function extractMessage(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $candidates = [
                $decoded['error']['message'] ?? null,
                $decoded['message'] ?? null,
                $decoded['msg'] ?? null,
                $decoded['error'] ?? null,
                $decoded['error_description'] ?? null,
            ];
            foreach ($candidates as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    return mb_substr($candidate, 0, 300);
                }
            }
        }

        $plain = trim(strip_tags($body));
        if ($plain === '') {
            return '上游返回了空响应';
        }

        return mb_substr($plain, 0, 300);
    }

    /**
     * 从上游 usage 中提取缓存读写 Token（兼容 OpenAI / Anthropic 风格命名）
     */
    protected function applyCacheUsage(Chunk $chunk, array $usage): void
    {
        $details = is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];

        $read = $usage['cache_read_input_tokens']
            ?? $usage['cache_read_tokens']
            ?? $details['cached_tokens']
            ?? $usage['cached_tokens']
            ?? null;
        if ($read !== null) {
            $chunk->cacheReadTokens = (int) $read;
        }

        $write = $usage['cache_creation_input_tokens']
            ?? $usage['cache_write_tokens']
            ?? $usage['cache_creation_tokens']
            ?? $details['cache_creation_tokens']
            ?? null;
        if ($write !== null) {
            $chunk->cacheWriteTokens = (int) $write;
        }
    }

    // ---------------------------------------------------------
    // 默认实现（子类按需覆盖）
    // ---------------------------------------------------------
    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        throw new \RuntimeException($this->displayName() . ' 尚未实现对话能力');
    }

    public function models(Account $account): array
    {
        return [];
    }

    /**
     * 默认：把 models() 的结果包装为目录结构（不含上游倍率）。
     * 支持采集倍率的供应商覆写本方法。
     */
    public function modelCatalog(Account $account): array
    {
        $out = [];
        foreach ($this->models($account) as $modelId) {
            $modelId = trim((string) $modelId);
            if ($modelId === '') {
                continue;
            }
            $out[] = [
                'model_id'         => $modelId,
                'display_name'     => $modelId,
                'rate'             => null,
                'rate_source'      => '',
                'fee_level'        => null,
                'entitlement_cost' => null,
                'context_window'   => null,
                'max_tokens'       => null,
                'multimodal'       => false,
                'reasoning'        => false,
                'capabilities'     => ['stream'],
            ];
        }
        return $out;
    }

    public function credits(Account $account): CreditInfo
    {
        return CreditInfo::unknown();
    }

    public function action(Account $account, string $action, array $params = []): array
    {
        return ['ok' => false, 'message' => "{$this->displayName()} 不支持动作：{$action}", 'data' => null];
    }

    /**
     * 凭据保活：返回需要合并进凭据的更新补丁；无更新返回空数组。
     *
     * 约定：以 __ 开头的键为保留控制键，由 AccountPool::keepaliveAll 解释、
     * 不会写入凭据，用于表达保活过程中的状态：
     *   - __session_dead (bool)  登录已失效，需标记禁用并重新登录
     *   - __message (string)     保活结果说明（写入保活日志）
     *   - __relogin_url (string) 失效时自动生成的重新登录授权链接
     */
    public function refreshToken(Account $account): array
    {
        return [];
    }

    public function startLogin(array $params = []): LoginSession
    {
        return new LoginSession(
            sessionId: uuid(),
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'failed',
            message: $this->displayName() . ' 不支持自助登录，请手动填写凭据',
            error: 'login_not_supported'
        );
    }

    public function pollLogin(string $sessionId, array $params = []): LoginSession
    {
        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'failed',
            error: 'login_not_supported'
        );
    }

    public function handleCallback(array $query): array
    {
        return ['ok' => false, 'message' => $this->displayName() . ' 不支持回调授权'];
    }

    public function ingestCredential(array $credential): array
    {
        return ['ok' => false, 'message' => $this->displayName() . ' 不支持导入凭据，请在账号池直接填写凭据'];
    }
}
