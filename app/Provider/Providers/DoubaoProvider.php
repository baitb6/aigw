<?php
/**
 * 豆包原生适配器（还原 dev.doubao2api 网关协议）
 *
 * 协议要点（来自对 豆包.apk 的逆向分析，类 La/c;、Lb/f;、Lc/b;、Lc/m;、Lc/p;）：
 *  - 对话入口：POST https://www.doubao.com/chat/completion?<query>
 *    query 由 La/c;->t 生成：aid=497858 / device_platform=web / language=zh / pc_version=3.19.4 /
 *    pkg_type=release_version / real_aid=497858 / samantha_web=1 / use-olympus-account=1 /
 *    version_code=20800 + device_id / tea_uuid / web_id / fp / web_tab_id(UUID) / msToken(可选)
 *  - 请求体由 La/c;->e 生成：{client_meta, messages:[{local_message_id,content_block,message_status}], option, ext}
 *    其中 content 块由 La/c;->y 生成：block_type=10000 + content.text_block.text
 *    option 由 La/c;->f 生成（约 30 个字段），ext 含 use_deep_think / fp 等
 *  - messages + tools 会被扁平化成带 [system]: / [user]: / [assistant]: / [tool]: 前缀的纯文本 prompt，
 *    工具定义以中文「工具调用」模板拼接到 system 段（占位符 {tool_definitions}，见 Lb/f;）
 *  - 模型映射（Lc/m;）：doubao/doubao-pro → 0（不深度思考），doubao-think → 1，doubao-expert → 3
 *  - 响应块（Lc/b;）：block_type 10000→content.text_block.text；10025→content.search_query_result_block；
 *    10040→深度思考计数（首个即开启 reasoning）
 *  - 状态映射：401/403 → 未登录；429 → 限流
 *  - 错误标记：__DONE__ / __ERROR__ / __HTTP_ERROR__
 *  - 图片：POST /samantha/pages/upload_image（multipart）→ 返回 data.uri
 *          POST /alice/message/get_file_url（uris/type/format/expire_second）→ 返回 data.file_urls
 *
 * 登录方式：WEBVIEW_CALLBACK —— 复用 WebView 注入脚本 __aicodeStart / __aicodeGetParams 采集凭据。
 */

declare(strict_types=1);

namespace App\Provider\Providers;

use App\Provider\AbstractProvider;
use App\Provider\ErrorCategory;
use App\Provider\Dto\Account;
use App\Provider\Dto\ChatRequest;
use App\Provider\Dto\Chunk;
use App\Provider\Dto\CreditInfo;
use App\Provider\Dto\LoginSession;
use App\Repositories\AccountRepository;
use App\Repositories\ModelRepository;
use App\Repositories\ProviderRepository;
use App\Repositories\ProxyRepository;
use App\Support\HttpClient;
use App\Support\Logger;

class DoubaoProvider extends AbstractProvider
{
    /** 网关主机（SSE 对话入口，原生 HttpURLConnection / WebView fetch 均可） */
    private const HOST = 'https://www.doubao.com';
    private const HOST_API = 'https://api.doubao.com';

    /** 逆向得到的固定业务参数 */
    private const BOT_ID = '7338286299411103781';

    /** 对话入口（La/c;->t 生成的 query 拼在其后） */
    private const CHAT_PATH = '/chat/completion';

    /** 查询串固定参数（还原 La/c;->t） */
    private const QUERY_FIXED = [
        'aid'                => '497858',
        'device_platform'    => 'web',
        'language'           => 'zh',
        'pc_version'         => '3.19.4',
        'pkg_type'           => 'release_version',
        'real_aid'           => '497858',
        'region'             => '',
        'samantha_web'       => '1',
        'sys_region'         => '',
        'use-olympus-account' => '1',
        'version_code'       => '20800',
    ];

    /** 内容块类型：文本块（La/c;->y） */
    private const BLOCK_TYPE_TEXT = 10000;
    /**
     * 响应块类型（实测 www.doubao.com 真实 SSE，非 Lc/b; 的旧假设）：
     *  10000 → content.text_block.text（正文，增量）
     *  10025 → content.search_query_result_block（联网搜索结果）
     *  10040 → content.thinking_block（深度思考，正文在 .text，仅标题时表示"正在思考"）
     */
    private const BLOCK_TYPE_SEARCH = 10025;
    private const BLOCK_TYPE_THINK = 10040;

    /**
     * 真实响应事件名（实测 SSE）：
     *  SSE_HEARTBEAT / SSE_ACK / FULL_MSG_NOTIFY / STREAM_MSG_NOTIFY / STREAM_CHUNK / SSE_REPLY_END
     *  - STREAM_MSG_NOTIFY：首个正文块，载荷 content.content_block[]
     *  - STREAM_CHUNK：后续增量，载荷 patch_op[].patch_value.content_block[]
     *  - SSE_REPLY_END：结束，载荷 end_type（1=消息结束 2=回答结束 3=全部结束）
     */
    private const END_TYPE_FINAL = 3;

    /** 图片相关接口 */
    private const UPLOAD_IMAGE_PATH = '/samantha/pages/upload_image';
    private const FILE_URL_PATH = '/alice/message/get_file_url';

    /**
     * 豆包模型目录（**本地抽象档位，非上游模型名**）。
     *
     * ⚠️ 豆包上游**没有**模型目录接口（2026-10-01 实测：`/alice/basic/launch` 的
     * `model_list` 只有上游内部 `model_type` 整数 + 占位名 "Aoe"，`/alice/bot/get_bot_list`
     * 恒回 `系统错误`）。它的「模型」是客户端抽象档位，最终映射到请求体
     * `option.use_deep_think` / `ext.use_deep_think` 的档位值。故本表**就该是本地权威**，
     * 不同于 ZCode/Qoder（那两家上游有真目录，见 docs/qoder与zcode上游协议.md §十二/§十五）。
     *
     * 结构：model_id => [展示名, 上下文, 多模态, 推理, **深度思考档位**]。
     * ⚠️ 第 5 项（档位）即原 `MODEL_MAP` —— 两表合并为**单一权威**，杜绝
     * 「改了档位表漏改目录表」的静默漂移（生成 `use_deep_think` 时取值见 buildChatPayload）。
     */
    private const BUILTIN_MODELS = [
        'doubao'        => ['豆包', 32768, true, false, 0],
        'doubao-pro'    => ['豆包 Pro', 32768, true, false, 0],
        'doubao-think'  => ['豆包 深度思考', 32768, true, true, 1],
        'doubao-expert' => ['豆包 专家', 32768, true, true, 3],
        'doubao-image'  => ['豆包 图像生成', 32768, false, false, 0],
    ];

    private const DEFAULT_MODEL = 'doubao';

    /** 工具名白名单（还原 Lc/p;->a），命中则描述不做 120 字截断 */
    private const TOOL_ALLOWLIST = [
        'text_search', 'file_find', 'Read', 'Write', 'Edit', 'Shell', 'Bash', 'Glob', 'Grep',
        'WebFetch', 'Task', 'TodoWrite', 'read', 'write', 'edit', 'bash', 'glob', 'grep',
        'webfetch', 'task', 'todowrite', 'fs_read_file', 'fs_write_file', 'fs_edit_file',
        'exec_command', 'exec_shell', 'http_fetch', 'web_query',
    ];

    public function __construct(
        ProxyRepository $proxyRepository,
        private AccountRepository $accountRepository,
        private ProviderRepository $providerRepository,
        private ModelRepository $modelRepository,
    ) {
        parent::__construct($proxyRepository);
    }

    public function id(): string
    {
        return 'doubao';
    }

    public function displayName(): string
    {
        return '豆包';
    }

    /**
     * 私有通道把整段对话与工具**扁平化成一条文本 prompt**，图片块必然丢失 ——
     * 明确声明不支持，让调用方在请求前拿到 400，而不是拿到一段凭空回答。
     */
    public function supportsImageInput(): bool
    {
        return false;
    }

    public function loginType(): string
    {
        return 'WEBVIEW_CALLBACK';
    }

    public function hosts(): array
    {
        return $this->providerRepository->find($this->id())['hosts']
            ?? ['doubao.com', 'api.doubao.com'];
    }

    // ---------------------------------------------------------
    // 主机 / 凭据
    // ---------------------------------------------------------

    private function baseHost(Account $account): string
    {
        $host = (string) $account->cred('host', '');
        return $host !== '' ? rtrim($host, '/') : self::HOST;
    }

    private function sessionId(Account $account): string
    {
        return (string) $account->cred('session_id', $account->cred('sessionid', ''));
    }

    private function cookie(Account $account): string
    {
        return (string) $account->cred('cookie', '');
    }

    /**
     * 豆包网关请求头（还原 WebView fetch 的请求头集合）
     */
    private function chatHeaders(Account $account): array
    {
        $headers = [
            'Content-Type'     => 'application/json',
            'Accept'           => 'text/event-stream',
            'Origin'           => self::HOST,
            'Referer'          => self::HOST . '/chat/',
            'User-Agent'       => $this->userAgent($account),
            'Accept-Language'  => 'zh-CN,zh;q=0.9',
        ];

        $sessionId = $this->sessionId($account);
        if ($sessionId !== '') {
            $headers['x-session-id'] = $sessionId;
        }

        $cookie = $this->cookie($account);
        if ($cookie !== '') {
            $headers['Cookie'] = $cookie;
        }

        $token = (string) $account->cred('token', '');
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * 非流式 JSON 接口请求头（额度查询、凭据探活等）
     * 与 chatHeaders 的唯一差别：Accept 需为 application/json，而非 text/event-stream
     */
    private function apiHeaders(Account $account): array
    {
        $headers = $this->chatHeaders($account);
        $headers['Accept'] = 'application/json';
        return $headers;
    }

    private function userAgent(Account $account): string
    {
        $ua = (string) $account->cred('user_agent', '');
        return $ua !== ''
            ? $ua
            : 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
    }

    // ---------------------------------------------------------
    // 对话
    // ---------------------------------------------------------

    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk
    {
        $payload = $this->buildChatPayload($request, $this->fingerprint($account));
        $url = $this->baseHost($account) . self::CHAT_PATH . '?' . $this->buildQuery($account);

        $chunk = new Chunk(model: $request->model);
        $stream = $request->stream;

        $emitted = false;
        // 搜索结果块按帧累积：Chunk::raw 是「最后一帧快照」语义，直接写会被下一帧冲掉
        // （这是上一轮 Trae 推理留痕恒 0 的同一个坑），故用独立累加器，流末再落回 raw。
        $searchResults = [];
        // 推理状态机（仅 doubao-think / doubao-expert 会触发）：
        //   block_type=10040 出现且 is_finish=false → 进入思考区
        //   block_type=10040 且 is_finish=true     → 思考结束，其后才是正文
        // 实测依据：think 模式下 CHUNK_DELTA 把「内心独白」与「正文」混在同一条流里，
        // 唯一的分界就是 10040 的 is_finish 标记（帧序 4→161 独白，164 THINK FIN，165+ 正文）。
        $inThink = false;

        $info = $this->http()
            ->withHeaders($this->chatHeaders($account))
            ->timeout(HttpClient::STREAM_SAFE_TIMEOUT, 20)
            ->stream('POST', $url, json_encode($payload, JSON_UNESCAPED_UNICODE), function (string $data) use (&$chunk, &$emitted, &$searchResults, &$inThink, $onDelta) {
                $trimmed = trim($data);

                // 兼容旧式结束/错误标记（当前上游已不用，保留防御）
                if ($trimmed === '__DONE__' || $trimmed === '[DONE]') {
                    $chunk->completed = true;
                    return false;
                }
                if ($trimmed === '__ERROR__' || $trimmed === '__HTTP_ERROR__') {
                    throw new \RuntimeException('上游返回错误标记：' . $trimmed);
                }

                $decoded = json_decode($data, true);
                if (!is_array($decoded)) {
                    return null;
                }

                $this->assertNoError($decoded);

                // ── 结束事件：SSE_REPLY_END，载荷 {"end_type":N} ──────────────
                // 1=本条消息结束 2=本回答结束 3=整个回合结束。收到 3 才收口，
                // 否则会漏掉 3 之后可能补发的建议/扩展帧。
                if (isset($decoded['end_type'])) {
                    if ((int) $decoded['end_type'] === self::END_TYPE_FINAL) {
                        $chunk->completed = true;
                        return false;
                    }
                    return null;
                }

                $chunk->id = $chunk->id ?: (string) ($decoded['message_id'] ?? $decoded['id'] ?? '');

                // ── 纯文本增量通道 CHUNK_DELTA：{"text":"…"} ─────────────────
                // 实测这是「主力正文流」，但它同时承载思考区独白，
                // 必须靠 $inThink 门控区分（见上方状态机注释）。
                if (isset($decoded['text']) && is_string($decoded['text']) && $decoded['text'] !== '') {
                    $delta = $decoded['text'];
                    if ($inThink) {
                        $chunk->reasoningContent .= $delta;
                        // 思考区独白也要下发（保真）：此前只留痕不回调，
                        // 客户端在思考阶段全程收不到任何帧 = 白屏。
                        $rRaw = $decoded;
                        $rRaw['_aigw_kind'] = 'reasoning';
                        if ($onDelta('', $rRaw) === false) {
                            return false;
                        }
                        return null;
                    }
                    $chunk->content .= $delta;
                    $emitted = true;
                    if ($onDelta($delta, $decoded) === false) {
                        return false;
                    }
                    return null;
                }

                // ── 结构化载荷 ──────────────────────────────────────────────
                //  STREAM_MSG_NOTIFY : {"content":{"content_block":[...]}}
                //  STREAM_CHUNK      : {"patch_op":[{"patch_value":{"content_block":[...]}}]}
                //  FULL_MSG_NOTIFY   : {"message":{...}}  ← 用户消息回显，collectContentBlocks 刻意不读
                $blocks = $this->collectContentBlocks($decoded);
                if ($blocks === []) {
                    return null;
                }

                $chunk->raw = $decoded;

                $text = '';
                foreach ($blocks as $block) {
                    $blockType = (int) ($block['block_type'] ?? 0);

                    // 思考块：只切换状态机，不产出正文
                    if ($blockType === self::BLOCK_TYPE_THINK) {
                        $inThink = empty($block['is_finish']);
                        continue;
                    }

                    if ($blockType === self::BLOCK_TYPE_SEARCH) {
                        $search = $block['content']['search_query_result_block'] ?? null;
                        if (is_array($search)) {
                            $searchResults[] = $search;
                        }
                        continue;
                    }

                    // 带 parent_id 的 text_block 是「思考过程摘要」（含 Deep_Think 图标、
                    // summary 字段），归属思考区而非正文 —— 实测帧5/6 的 "用户" 即此类，
                    // 若计入正文会污染回复开头。
                    if ((string) ($block['parent_id'] ?? '') !== '') {
                        continue;
                    }

                    $blockText = $this->extractBlockText($block);
                    if ($blockText === '') {
                        continue;
                    }

                    if ($inThink) {
                        $chunk->reasoningContent .= $blockText;
                        // 思考块同样要下发（保真），与 CHUNK_DELTA 的 inThink 分支一致
                        $rRaw = $decoded;
                        $rRaw['_aigw_kind'] = 'reasoning';
                        if ($onDelta('', $rRaw) === false) {
                            return false;
                        }
                        continue;
                    }

                    $text .= $blockText;
                }

                if ($text !== '') {
                    $chunk->content .= $text;
                    $emitted = true;
                    if ($onDelta($text, $decoded) === false) {
                        return false;
                    }
                }

                $this->applyUsage($decoded, $chunk);

                return null;
            });

        if (!empty($info['error']) && empty($info['aborted'])) {
            Logger::warn("{$this->id()} 流式请求异常", $info);
        }

        // 搜索引用落回 raw（供调用方读取），不污染正文
        if ($searchResults !== []) {
            $raw = is_array($chunk->raw) ? $chunk->raw : [];
            $raw['search_query_result_block'] = count($searchResults) === 1 ? $searchResults[0] : $searchResults;
            $chunk->raw = $raw;
        }

        // ── 传输层失败必须抛出，不能降级成「空正文」 ─────────────────────
        // 否则 DNS/TLS/连接被拒 会被网关误判成「出口层抑制」→ 重采样 → CONTENT_BLOCKED。
        // ⚠️ 必须排除我们主动中断（回调返回 false 时 curl 报 errno=23，属预期）。
        $aborted = (bool) ($info['aborted'] ?? false);
        if (!$aborted && (int) ($info['errno'] ?? 0) !== 0 && (string) ($info['error'] ?? '') !== '' && $chunk->content === '') {
            throw new \RuntimeException($this->id() . ' 请求失败（传输层）：' . (string) $info['error']);
        }

        $status = (int) ($info['status'] ?? 0);
        $noBody = $chunk->content === '' && $chunk->reasoningContent === '' && empty($chunk->toolCalls) && $chunk->finishReason === '';
        if ($noBody && !$aborted && $status >= 400) {
            $upstreamBody = trim((string) ($info['body'] ?? ''));
            throw new \RuntimeException(
                $upstreamBody !== '' ? $upstreamBody : '（上游返回空正文）',
                $status
            );
        }

        if (!$stream && $chunk->content === '' && !$emitted) {
            throw new \RuntimeException('豆包返回了空响应');
        }

        // 上游没有 OpenAI 风格的 finish_reason 字段，正常情况下以 end_type=3 收口。
        // 若结束时还没定值（含被重采样掐断的路径）→ 一律补 stop，避免空值往下游传。
        if ($chunk->finishReason === '') {
            $chunk->finishReason = 'stop';
        }

        return $chunk;
    }

    /**
     * 收集一帧内所有助手产出内容块。
     *
     * 只认「助手输出」的两条路径：
     *   - patch_op[].patch_value.content_block[]    （STREAM_CHUNK 增量）
     *   - content.content_block[]                   （STREAM_MSG_NOTIFY 首帧）
     * 刻意**不**读 message.content_block —— 那是 FULL_MSG_NOTIFY 回显的用户消息
     * （文本形如 "[user]: …"），读进来会把用户提问当成模型回复。
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectContentBlocks(array $decoded): array
    {
        $blocks = [];

        if (isset($decoded['patch_op']) && is_array($decoded['patch_op'])) {
            foreach ($decoded['patch_op'] as $op) {
                $value = is_array($op) ? ($op['patch_value'] ?? null) : null;
                if (!is_array($value)) {
                    continue;
                }
                foreach (($value['content_block'] ?? []) as $block) {
                    if (is_array($block)) {
                        $blocks[] = $block;
                    }
                }
            }
        }

        if (isset($decoded['content']['content_block']) && is_array($decoded['content']['content_block'])) {
            foreach ($decoded['content']['content_block'] as $block) {
                if (is_array($block)) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * 从一个内容块取出文本。
     *  10000 → content.text_block.text
     *  10040 → content.thinking_block.text（只有 title 字段时表示"正在思考"，无正文，返回 ''）
     */
    private function extractBlockText(array $block): string
    {
        $content = $block['content'] ?? null;
        if (!is_array($content)) {
            return '';
        }

        foreach (['text_block', 'thinking_block'] as $key) {
            $inner = $content[$key] ?? null;
            if (is_array($inner) && isset($inner['text']) && is_string($inner['text'])) {
                return $inner['text'];
            }
        }

        return '';
    }

    /**
     * 还原请求体（La/c;->e）：client_meta + messages(local_message_id/content_block/message_status) + option + ext
     */
    private function buildChatPayload(ChatRequest $request, string $fp): array
    {
        $prompt = $this->flattenPrompt($request);

        $deepThink = (int) (self::BUILTIN_MODELS[$request->model][4] ?? 0);
        $needCreateConversation = true;
        $now = (int) (microtime(true) * 1000);
        $localConvId = 'local_' . $this->randHex(16);

        $contentBlock = [
            'block_type' => self::BLOCK_TYPE_TEXT,
            'content'    => [
                'text_block'    => [
                    'text'          => $prompt,
                    'icon_url'      => '',
                    'icon_url_dark' => '',
                    'summary'       => '',
                ],
                'pc_event_block' => '',
            ],
            'block_id'     => $this->randUuid(),
            'parent_id'    => '',
            'meta_info'    => [],
            'append_fields' => [],
        ];

        return [
            'client_meta' => [
                'local_conversation_id' => $localConvId,
                'conversation_id'       => '',
                'bot_id'                => self::BOT_ID,
                'last_section_id'       => '',
                'last_message_index'    => null,
            ],
            'messages' => [[
                'local_message_id' => $this->randUuid(),
                'content_block'    => [$contentBlock],
                'message_status'   => 0,
            ]],
            'option' => $this->buildOption($now, $deepThink, $needCreateConversation, $localConvId),
            'ext'    => [
                'use_deep_think'                 => (string) $deepThink,
                'fp'                             => $fp,
                'collection_id'                  => '',
                'commerce_credit_config_enable'  => '0',
                'sub_conv_firstmet_type'         => $needCreateConversation ? '1' : '0',
            ],
        ];
    }

    /**
     * 还原 option（La/c;->f）：约 30 个字段
     */
    private function buildOption(int $createTimeMs, int $needDeepThink, bool $needCreateConversation, string $localConvId): array
    {
        $option = [
            'send_message_scene'       => '',
            'create_time_ms'           => $createTimeMs,
            'collect_id'               => '',
            'is_audio'                 => false,
            'answer_with_suggest'      => false,
            'tts_switch'               => false,
            'need_deep_think'          => $needDeepThink,
            'click_clear_context'      => false,
            'from_suggest'             => false,
            'is_regen'                 => false,
            'is_replace'               => false,
            'disable_sse_cache'        => false,
            'select_text_action'       => '',
            'resend_for_regen'         => false,
            'scene_type'               => 0,
            'unique_key'               => $this->randUuid(),
            'start_seq'                => 0,
            'need_create_conversation' => $needCreateConversation,
            'regen_query_id'           => [],
            'edit_query_id'            => [],
            'regen_instruction'        => '',
            'no_replace_for_regen'     => false,
            'message_from'             => 0,
            'shared_app_name'          => '',
            'shared_app_id'            => '',
            'sse_recv_event_options'   => ['support_chunk_delta' => true],
            'is_ai_playground'         => false,
            'recovery_option'          => [
                'is_recovery'            => false,
                'req_create_time_sec'    => (int) ($createTimeMs / 1000),
                'append_sse_event_scene' => 0,
            ],
            'message_storage_type'     => 0,
        ];

        if ($needCreateConversation) {
            $option['local_conversation_id'] = $localConvId;
        }

        return $option;
    }

    /**
     * 还原查询串（La/c;->t）：固定参数 + 每请求身份参数，按键序用 & 连接
     */
    private function buildQuery(Account $account): string
    {
        $deviceId = (string) $account->cred('device_id', '');
        if ($deviceId === '') {
            $deviceId = $account->uid !== '' ? $account->uid : $this->randHex(16);
        }

        $webId = (string) $account->cred('web_id', $deviceId);
        $fp = $this->fingerprint($account);

        $params = self::QUERY_FIXED;
        $params['device_id'] = $deviceId;
        $params['fp'] = $fp;
        $params['tea_uuid'] = $deviceId;
        $params['web_id'] = $webId;
        $params['web_tab_id'] = $this->randUuid();

        $msToken = (string) $account->cred('ms_token', $account->cred('msToken', ''));
        if ($msToken !== '') {
            $params['msToken'] = $msToken;
        }

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    private function randHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * 浏览器指纹（La/c;->e/f 与查询串共用的 fp；优先取凭据，回退 UA）
     */
    private function fingerprint(Account $account): string
    {
        $fp = (string) $account->cred('fp', '');
        return $fp !== '' ? $fp : $this->userAgent($account);
    }

    private function randUuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    /**
     * 把 OpenAI 风格的 messages + tools 扁平化成豆包网关需要的纯文本 prompt。
     *
     * 结构：
     *   [system]: <系统提示>
     *   <工具调用说明模板（含 {tool_definitions}）>
     *   [user]: ...
     *   [assistant]: ...
     *   [tool]: ...
     */
    private function flattenPrompt(ChatRequest $request): string
    {
        $lines = [];
        $systemParts = [];

        foreach ($request->messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = $this->extractContent($message['content'] ?? '');

            if ($role === 'system') {
                if ($content !== '') {
                    $systemParts[] = $content;
                }
                continue;
            }

            $prefix = match ($role) {
                'assistant' => '[assistant]: ',
                'tool'      => '[tool]: ',
                default     => '[user]: ',
            };
            $lines[] = $prefix . $content;
        }

        $system = implode("\n\n", $systemParts);

        if (!empty($request->tools)) {
            $toolBlock = $this->toolInstructions($request->tools);
            $system = $system === '' ? $toolBlock : $system . "\n\n" . $toolBlock;
        }

        $out = '';
        if ($system !== '') {
            $out .= "[system]: " . $system . "\n\n";
        }

        return $out . implode("\n", $lines);
    }

    /**
     * 中文工具调用说明模板（还原 Apk Lb/f; 中拼接 tools 的文案，占位符 {tool_definitions}）
     * 工具签名渲染规则：`- 名称: 描述`，参数形如 `参数名!(类型)` / `参数名?(类型)`，枚举用 | 连接。
     */
    private function toolInstructions(array $tools): string
    {
        $definitions = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $function = $tool['function'] ?? $tool;
            if (!is_array($function)) {
                continue;
            }

            $name = (string) ($function['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $description = (string) ($function['description'] ?? '');
            if (!in_array($name, self::TOOL_ALLOWLIST, true) && mb_strlen($description) > 80) {
                $description = mb_substr($description, 0, 80) . '...';
            }

            $parameters = $function['parameters'] ?? [];
            $properties = is_array($parameters) ? ($parameters['properties'] ?? []) : [];
            $required = is_array($parameters) && is_array($parameters['required'] ?? null)
                ? $parameters['required']
                : [];

            $params = [];
            if (is_array($properties)) {
                foreach ($properties as $paramName => $schema) {
                    if (!is_array($schema)) {
                        $schema = [];
                    }
                    $type = (string) ($schema['type'] ?? 'string');
                    if ($type === 'array') {
                        $items = $schema['items']['type'] ?? 'string';
                        $type = $items . '[]';
                    }
                    if (!empty($schema['enum']) && is_array($schema['enum'])) {
                        $type = implode('|', array_map(static fn ($v) => (string) $v, $schema['enum']));
                    }

                    $flag = in_array((string) $paramName, $required, true) ? '!' : '?';
                    $line = $paramName . $flag . '(' . $type . ')';

                    $paramDesc = (string) ($schema['description'] ?? '');
                    if ($paramDesc !== '') {
                        $line .= ' ' . mb_substr($paramDesc, 0, 60);
                    }
                    $params[] = $line;
                }
            }

            $entry = '- ' . $name . ': ' . $description;
            if ($params !== []) {
                $entry .= "\n  " . implode("\n  ", $params);
            }
            $definitions[] = $entry;
        }

        $toolDefinitions = implode("\n", $definitions);

        $template = <<<'TPL'
你是一个工具调用助手。禁止使用内置联网搜索。

## 可用工具
{tool_definitions}

## 调用格式
<tool_call>
{"name": "工具名", "arguments": {"参数名": "参数值"}}
</tool_call>

多个工具可并行调用（输出多个<tool_call>块）。

## 规则
1. 必须使用上面列出的**精确工具名**，不要用别名或猜测的名字
2. 参数必须严格匹配工具签名中的字段名（!为必填，?为可选）
3. 需要调用工具时只输出<tool_call>块，不加解释文字
4. 不需要工具时直接用自然语言回答
TPL;

        return str_replace('{tool_definitions}', $toolDefinitions, $template);
    }

    private function extractContent($content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $out = '';
        foreach ($content as $part) {
            if (is_string($part)) {
                $out .= $part;
                continue;
            }
            if (is_array($part) && isset($part['text'])) {
                $out .= is_string($part['text']) ? $part['text'] : '';
            }
        }
        return $out;
    }

    private function applyUsage(array $decoded, Chunk $chunk): void
    {
        $usage = $decoded['usage'] ?? $decoded['token_usage'] ?? null;
        if (!is_array($usage)) {
            return;
        }
        $chunk->promptTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? $chunk->promptTokens);
        $chunk->completionTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? $chunk->completionTokens);
        $chunk->totalTokens = (int) ($usage['total_tokens'] ?? 0);
        $this->applyCacheUsage($chunk, $usage);
    }

    // ---------------------------------------------------------
    // 风控 / 错误
    // ---------------------------------------------------------

    /**
     * 上游返回体中的错误码（还原 Lb/f;->d 的状态映射：401/403→未登录，429→限流）
     */
    private function assertNoError(array $decoded): void
    {
        if (isset($decoded['error_code']) && (int) $decoded['error_code'] !== 0) {
            $raw = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            throw new \RuntimeException($raw !== false ? $raw : '上游返回错误');
        }
    }

    public function parseError(int $status, string $body): ?array
    {
        return parent::parseError($status, $body);
    }

    // ---------------------------------------------------------
    // 模型目录
    // ---------------------------------------------------------

    /**
     * 豆包模型列表：**以本地库为准**（豆包无上游模型目录，见 BUILTIN_MODELS 注释）。
     * 库内空时回退内置档位表，保证面板非空。
     */
    public function models(Account $account): array
    {
        $models = [];
        foreach ($this->modelRepository->all($this->id()) as $model) {
            if (!empty($model['enabled'])) {
                $models[] = (string) $model['model_id'];
            }
        }

        if (!empty($models)) {
            return $models;
        }

        return array_keys(self::BUILTIN_MODELS);
    }

    /**
     * 豆包模型目录：本地库优先 → 内置档位表兜底。
     *
     * ⚠️ 与 ZCode/Qoder 的「上游优先」**故意不同**：豆包上游没有模型目录接口，
     * 其「模型」是本地抽象档位（映射 use_deep_think）。因此本地库即权威，
     * 不该假装去拉上游。未收录 id（管理员自建）走中性回退：显示名用 id、能力仅 stream。
     */
    public function modelCatalog(Account $account): array
    {
        $rows = $this->modelRepository->all($this->id());

        $enabled = [];
        foreach ($rows as $row) {
            if (!empty($row['enabled'])) {
                $enabled[(string) $row['model_id']] = true;
            }
        }

        $ids = array_keys($enabled) ?: array_keys(self::BUILTIN_MODELS);

        $out = [];
        foreach ($ids as $modelId) {
            $meta = self::BUILTIN_MODELS[$modelId] ?? null;
            $display = $meta[0] ?? $modelId;
            $multimodal = (bool) ($meta[2] ?? false);
            $reasoning = (bool) ($meta[3] ?? false);

            $capabilities = ['stream'];
            if ($multimodal) {
                $capabilities[] = 'vision';
            }
            if ($reasoning) {
                $capabilities[] = 'reasoning';
            }

            $out[] = [
                'model_id'         => $modelId,
                'display_name'     => $display,
                'rate'             => null,
                'rate_source'      => '',
                'fee_level'        => null,
                'entitlement_cost' => null,
                'context_window'   => $meta[1] ?? null,
                'max_tokens'       => null,
                'multimodal'       => $multimodal,
                'reasoning'        => $reasoning,
                'capabilities'     => $capabilities,
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------
    // 额度
    // ---------------------------------------------------------

    public function credits(Account $account): CreditInfo
    {
        try {
            $url = $this->baseHost($account) . '/alice/user/info';
            $response = $this->http()
                ->withHeaders($this->apiHeaders($account))
                ->timeout(20, 10)
                ->get($url);
        } catch (\Throwable $e) {
            return CreditInfo::failure($e->getMessage());
        }

        if (!$response->ok()) {
            return CreditInfo::failure('上游返回 HTTP ' . $response->status());
        }

        $data = $response->json();
        if (!is_array($data)) {
            return CreditInfo::unknown();
        }

        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $balance = $payload['credits'] ?? $payload['balance'] ?? null;

        if ($balance === null) {
            return CreditInfo::unknown('豆包未返回额度字段');
        }

        return new CreditInfo(
            known: true,
            balance: (float) $balance,
            detail: '豆包账号额度',
        );
    }

    // ---------------------------------------------------------
    // 动作
    // ---------------------------------------------------------

    public function action(Account $account, string $action, array $params = []): array
    {
        return match ($action) {
            'test'        => $this->actionTest($account),
            'upload_image' => $this->actionUploadImage($account, $params),
            'file_url'    => $this->actionFileUrl($account, $params),
            default       => ['ok' => false, 'message' => "{$this->displayName()} 不支持动作：{$action}", 'data' => null],
        };
    }

    private function actionTest(Account $account): array
    {
        try {
            $chunk = $this->chat(
                $account,
                new ChatRequest(model: self::DEFAULT_MODEL, messages: [['role' => 'user', 'content' => '你好']], stream: false),
                static function (): void {}
            );
        } catch (\Throwable $e) {
            // 上游为 SSE-only，异常需归类后返回，交由框架按错误码处理
            $parsed = $this->parseError(0, $e->getMessage());
            return [
                'ok'      => false,
                'message' => $parsed['message'] ?? ('自检失败：' . $e->getMessage()),
                'data'    => ['error_code' => $parsed['code'] ?? ErrorCategory::UPSTREAM],
            ];
        }

        return ['ok' => true, 'message' => '连通正常', 'data' => ['reply' => $chunk->content]];
    }

    /**
     * 图片上传：POST /samantha/pages/upload_image（multipart，字段 data + file_type）
     * 返回 data.uri，用于后续 get_file_url。
     */
    private function actionUploadImage(Account $account, array $params): array
    {
        $binary = $params['data'] ?? $params['file'] ?? null;
        if (!is_string($binary) || $binary === '') {
            return ['ok' => false, 'message' => '缺少图片二进制数据（data）', 'data' => null];
        }

        $fileType = (string) ($params['file_type'] ?? 'image/png');
        $boundary = '----aicode' . str_replace('-', '', uuid());
        $body = $this->buildMultipart($boundary, [
            'file_type' => $fileType,
        ], 'data', 'image.png', $fileType, $binary);

        $url = $this->baseHost($account) . self::UPLOAD_IMAGE_PATH . '?';
        $headers = $this->chatHeaders($account);
        $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
        $headers['Accept'] = 'application/json';

        $response = $this->http()->withHeaders($headers)->timeout(120, 20)->postRaw($url, $body);

        if (!$response->ok()) {
            return ['ok' => false, 'message' => '上传失败 HTTP ' . $response->status(), 'data' => null];
        }

        $data = $response->json();
        $uri = is_array($data) ? ($data['data']['uri'] ?? $data['uri'] ?? null) : null;

        if (!is_string($uri) || $uri === '') {
            return ['ok' => false, 'message' => '上传未返回 uri', 'data' => $data];
        }

        return ['ok' => true, 'message' => '上传成功', 'data' => ['uri' => $uri]];
    }

    /**
     * 换取文件可访问地址：POST /alice/message/get_file_url
     */
    private function actionFileUrl(Account $account, array $params): array
    {
        $uris = $params['uris'] ?? ($params['uri'] ?? null);
        if (is_string($uris)) {
            $uris = [$uris];
        }
        if (!is_array($uris) || $uris === []) {
            return ['ok' => false, 'message' => '缺少 uris', 'data' => null];
        }

        $url = $this->baseHost($account) . self::FILE_URL_PATH;
        $headers = $this->chatHeaders($account);
        $headers['Accept'] = 'application/json';

        $response = $this->http()->withHeaders($headers)->timeout(60, 20)->postJson($url, [
            'uris'          => array_values($uris),
            'type'          => (string) ($params['type'] ?? 'image'),
            'format'        => (string) ($params['format'] ?? 'url'),
            'expire_second' => (int) ($params['expire_second'] ?? 3600),
        ]);

        if (!$response->ok()) {
            return ['ok' => false, 'message' => '换取地址失败 HTTP ' . $response->status(), 'data' => null];
        }

        $data = $response->json();
        $fileUrls = is_array($data) ? ($data['data']['file_urls'] ?? $data['file_urls'] ?? null) : null;

        return ['ok' => true, 'message' => '获取成功', 'data' => ['file_urls' => $fileUrls]];
    }

    /**
     * 手工构造 multipart/form-data 请求体（HttpClient 未内置 multipart 构造）
     */
    private function buildMultipart(string $boundary, array $fields, string $fileField, string $filename, string $mime, string $binary): string
    {
        $eol = "\r\n";
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $body .= $value . $eol;
        }

        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="' . $fileField . '"; filename="' . $filename . '"' . $eol;
        $body .= 'Content-Type: ' . $mime . $eol . $eol;
        $body .= $binary . $eol;
        $body .= '--' . $boundary . '--' . $eol;

        return $body;
    }

    // ---------------------------------------------------------
    // 凭据刷新 / 导入
    // ---------------------------------------------------------

    /**
     * 刷新凭据：返回需要合并进 credential 的字段字典。
     *
     * 豆包凭据为 cookie/session_id，无独立 refresh token 换取流程；
     * 这里仅做一次轻量探活，凭据有效则返回空数组（表示无需改动）。
     * 注意：绝不能返回 {ok,message} 之类结构，否则会被写入 credential 造成污染。
     */
    public function refreshToken(Account $account): array
    {
        if ($this->sessionId($account) === '' && $this->cookie($account) === '' && (string) $account->cred('token', '') === '') {
            return [];
        }

        try {
            $url = $this->baseHost($account) . '/alice/user/info';
            $response = $this->http()
                ->withHeaders($this->apiHeaders($account))
                ->timeout(20, 10)
                ->get($url);
        } catch (\Throwable $e) {
            Logger::warn("{$this->id()} 凭据探活异常", ['uid' => $account->uid, 'error' => $e->getMessage()]);
            return [];
        }

        if (!$response->ok()) {
            Logger::warn("{$this->id()} 凭据探活返回异常状态", ['uid' => $account->uid, 'status' => $response->status()]);
            return [];
        }

        $data = $response->json();
        $payload = is_array($data) ? (is_array($data['data'] ?? null) ? $data['data'] : $data) : [];

        // 上游若回传新的 session 标识，则回写以延长可用期
        $patch = [];
        $newSession = (string) ($payload['session_id'] ?? $payload['sessionid'] ?? '');
        if ($newSession !== '' && $newSession !== $this->sessionId($account)) {
            $patch['session_id'] = $newSession;
        }

        return $patch;
    }

    // ---------------------------------------------------------
    // 登录（WEBVIEW_CALLBACK：由 WebView 注入脚本采集后回调导入）
    // ---------------------------------------------------------

    public function startLogin(array $params = []): LoginSession
    {
        return new LoginSession(
            sessionId: uuid(),
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'waiting',
            message: '请在豆包 WebView 中完成登录，前端采集 __aicodeGetParams 结果后回传',
            authUrl: self::HOST . '/chat/',
            extra: ['bridge' => ['__aicodeStart', '__aicodeSamantha', '__aicodeGetParams', '__aicodeAbort']],
        );
    }

    public function pollLogin(string $sessionId, array $params = []): LoginSession
    {
        $credential = $params['credential'] ?? null;

        if (is_string($credential) && $credential !== '') {
            $decoded = json_decode($credential, true);
            $credential = is_array($decoded) ? $decoded : null;
        }

        if (is_array($credential) && $credential !== []) {
            $result = $this->ingestCredential($credential);
            $state = !empty($result['ok']) ? 'success' : 'failed';

            return new LoginSession(
                sessionId: $sessionId,
                providerKey: $this->id(),
                loginType: $this->loginType(),
                state: $state,
                message: (string) ($result['message'] ?? ''),
                accountUid: (string) ($result['account_uid'] ?? ''),
                nickname: (string) ($result['nickname'] ?? ''),
                error: $state === 'failed' ? (string) ($result['message'] ?? 'import_failed') : null,
            );
        }

        return new LoginSession(
            sessionId: $sessionId,
            providerKey: $this->id(),
            loginType: $this->loginType(),
            state: 'waiting',
            message: '等待 WebView 回传凭据',
        );
    }

    public function handleCallback(array $query): array
    {
        return $this->ingestCredential($query);
    }

    public function ingestCredential(array $credential): array
    {
        $cookie = trim((string) ($credential['cookie'] ?? ''));
        $token = trim((string) ($credential['token'] ?? $credential['access_token'] ?? $credential['accessToken'] ?? ''));
        $sessionId = trim((string) ($credential['session_id'] ?? $credential['sessionid'] ?? ''));
        $msToken = trim((string) ($credential['ms_token'] ?? $credential['msToken'] ?? ''));

        // 豆包以网页版 Cookie 作为登录态；msToken 亦可单独提供，但都为空时无法调用网关
        if ($cookie === '' && $msToken === '' && $token === '' && $sessionId === '') {
            return ['ok' => false, 'message' => '豆包凭据缺少 Cookie / msToken / session_id，请从网页版登录态复制'];
        }

        // 归一化字段，避免前端大小写不一致导致查询串/请求头取不到值
        if ($cookie !== '') {
            $credential['cookie'] = $cookie;
        }
        if ($msToken !== '') {
            $credential['ms_token'] = $msToken;
        }
        $deviceId = trim((string) ($credential['device_id'] ?? $credential['deviceId'] ?? ''));
        if ($deviceId !== '') {
            $credential['device_id'] = $deviceId;
        } else {
            unset($credential['device_id']);
        }
        $webId = trim((string) ($credential['web_id'] ?? $credential['webId'] ?? ''));
        if ($webId !== '') {
            $credential['web_id'] = $webId;
        } else {
            unset($credential['web_id']);
        }

        $uid = trim((string) ($credential['uid'] ?? $credential['user_id'] ?? ''));
        if ($uid === '') {
            $uid = 'db_' . substr(hash('sha256', implode('|', [$cookie, $msToken, $token, $sessionId])), 0, 16);
        }

        $host = (string) ($credential['host'] ?? '');
        if ($host === '') {
            $credential['host'] = self::HOST;
        }

        $meta = array_filter([
            'avatar'    => (string) ($credential['avatar'] ?? ''),
            'user_info' => is_array($credential['user_info'] ?? null) ? $credential['user_info'] : null,
        ], static fn ($v) => $v !== '' && $v !== null);

        $nickname = (string) ($credential['nickname'] ?? $credential['name'] ?? '');

        $accountId = $this->accountRepository->upsertCredential($this->id(), $uid, $credential, $meta, $nickname);

        return [
            'ok'          => true,
            'message'     => '豆包账号已保存',
            'account_uid' => $uid,
            'nickname'    => $nickname,
            'account_id'  => $accountId,
        ];
    }
}