<?php
/**
 * Provider 契约
 * 对应原 App 的 LY1/m 接口，每个上游一个实现，新增供应商零侵入
 */

declare(strict_types=1);

namespace App\Provider;

use App\Provider\Dto\Account;
use App\Provider\Dto\ChatRequest;
use App\Provider\Dto\Chunk;
use App\Provider\Dto\CreditInfo;
use App\Provider\Dto\LoginSession;
use App\Support\HttpClient;

interface Provider
{
    /**
     * 供应商唯一标识：trae / loomy / codebuddy / antigravity / custom
     */
    public function id(): string;

    /**
     * 展示名称
     */
    public function displayName(): string;

    /**
     * 登录方式枚举
     */
    public function loginType(): string;

    /**
     * 主机白名单，用于代理排除与凭据归属判断
     *
     * @return string[]
     */
    public function hosts(): array;

    /**
     * 是否能在**协议层**保留图片输入（OpenAI `image_url` 内容块）。
     *
     * 为什么必须是显式能力位而不是「默认都行」：
     *   部分供应商把 messages 扁平化成纯文本 prompt（Antigravity / Doubao），
     *   或转成 Anthropic 消息块（Zcode 主通道）—— 图片块会被**静默丢弃**，
     *   上游照样回一段像模像样的文字，用户以为图片已被理解。
     *   调用方（Responses 适配层）据此在**请求前**明确报错，而不是让图片悄悄消失。
     *
     * 返回 false 不代表该供应商不能用图片，而是「本网关当前这条协议链路不保证」。
     */
    public function supportsImageInput(): bool;

    /**
     * 对话（流式）
     *
     * @param callable(string $deltaContent, array $rawChunk): (bool|null) $onDelta
     *        回调返回 false 中断流
     * @return Chunk 汇总结果（含 usage 与 finish_reason）
     */
    public function chat(Account $account, ChatRequest $request, callable $onDelta): Chunk;

    /**
     * 拉取该账号可用模型列表
     *
     * @return string[]
     */
    public function models(Account $account): array;

    /**
     * 拉取该账号可用模型目录（含上游倍率与详情，用于同步落库）
     *
     * 每项结构：
     *  - model_id:string         上游模型标识（对话请求使用）
     *  - display_name:string     展示名
     *  - rate:?float             上游倍率（未采集为 null）
     *  - rate_source:string      倍率来源字段名
     *  - fee_level:?int          上游计费等级
     *  - entitlement_cost:?float 上游权益消耗
     *  - context_window:?int     上下文窗口
     *  - max_tokens:?int         最大输出
     *  - multimodal:bool         是否多模态
     *  - reasoning:bool          是否推理模型
     *  - capabilities:string[]   能力标签
     *
     * @return list<array<string,mixed>>
     */
    public function modelCatalog(Account $account): array;

    /**
     * 刷新/查询额度
     */
    public function credits(Account $account): CreditInfo;

    /**
     * 扩展动作：checkin（签到） / tasks（新手任务） / redeem（兑换）
     *
     * @return array{ok:bool, message:string, data:mixed}
     */
    public function action(Account $account, string $action, array $params = []): array;

    /**
     * 刷新 Token（快过期时调用）
     *
     * @return array 新的凭据字段，空数组表示无需刷新
     */
    public function refreshToken(Account $account): array;

    /**
     * 解析上游原始错误为统一错误分类
     *
     * @return array{code:string, message:string}|null code 取值见 ErrorCategory
     */
    public function parseError(int $status, string $body): ?array;

    /**
     * 构造底层 HTTP 客户端（已应用代理配置与超时）
     */
    public function http(): HttpClient;

    /**
     * 发起登录流程，返回前端需要展示的指令
     */
    public function startLogin(array $params = []): LoginSession;

    /**
     * 轮询/完成登录流程
     */
    public function pollLogin(string $sessionId, array $params = []): LoginSession;

    /**
     * 从回调参数中提取凭据并落库（WEBVIEW_CALLBACK / OAUTH_LOOPBACK 用）
     *
     * @return array{ok:bool, message:string, account_uid?:string}
     */
    public function handleCallback(array $query): array;

    /**
     * 导入一份手工提供的显式凭据（如从浏览器复制的 accessToken）并落库。
     *
     * 由支持该能力的 Provider 覆写；默认不支持。
     *
     * @param array<string,mixed> $credential
     * @return array{ok:bool, message:string, account_uid?:string, nickname?:string, account_id?:int}
     */
    public function ingestCredential(array $credential): array;
}