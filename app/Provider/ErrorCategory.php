<?php
/**
 * 账号错误分类（对应 LY1/j）
 * 决定调度策略：冷却 / 重登 / 直接失败
 */

declare(strict_types=1);

namespace App\Provider;

final class ErrorCategory
{
    /** 额度耗尽 → 长冷却 */
    public const QUOTA = 'QUOTA';
    /** 软限流 → 短暂冷却 */
    public const SOFT_RATE = 'SOFT_RATE';
    /** 登录失效 → 需重新登录 */
    public const SESSION_DEAD = 'SESSION_DEAD';
    /** 资源不存在 → 切换账号 */
    public const NOT_FOUND = 'NOT_FOUND';
    /** 客户端错误 → 不重试，直接回给调用方 */
    public const CLIENT = 'CLIENT';
    /** 上游服务异常 → 重试其他账号 */
    public const UPSTREAM = 'UPSTREAM';
    /** 模型级限流（429 code=6004）→ 仅冷却「账号+模型」，不冻结整个账号 */
    public const MODEL_RATE = 'MODEL_RATE';
    /** 上下文超长（11115 / context_length_exceeded）→ 换号无意义，直接回 400 */
    public const CONTEXT_TOO_LONG = 'CONTEXT_TOO_LONG';
    /**
     * 内容审核拦截（sensitive content / content policy）→ 换号无意义，直接回 400。
     *
     * ⚠️ 这类错误**绝不能**归到 UPSTREAM：UPSTREAM 会走 `markError` 给账号累加错误数，
     * 于是"某条提示词被审核拒绝"会被记成"这个账号有问题"，连续几次就把**完全健康**的
     * 账号打进冷却。而且上游是在 SSE 里发 error 事件、异常不带 HTTP 状态码（code=0），
     * 不显式识别就会掉进 `$status >= 500 || $status === 0` 分支被误判为上游故障。
     */
    public const CONTENT_BLOCKED = 'CONTENT_BLOCKED';
    /**
     * 传输层/基建故障（DNS 解析失败、连接被拒、TLS 握手失败、上游自报网络错误）。
     *
     * ⚠️ 这类**绝不能**归到 CONTENT_BLOCKED。历史上 `parseOpenAiSse` 只把 curl 错误写进
     * 日志、返回空 Chunk，网关便把它当成「上游出口安全层抑制了正文」，重采样 9 次后
     * 抛「内容被拦截」回 400 —— 明明是域名解析不了，却报成用户内容违规。
     * 也**绝不能**用来惩罚账号：错了的域名换多少个账号都解析不出来。
     * 归为 NETWORK：可重试（网络抖动能恢复），但不计账号错误数、不冷却。
     */
    public const NETWORK = 'NETWORK';
    /**
     * 模型不可用（Trae 业务码 4023）→ 换号无意义，直接回 400。
     *
     * ⚠️ 上游 `config_info_list` 里所有 `usage=custom_model` 的条目都是**自定义模型模板**，
     * 它们 `config_switch=true` / `is_invisible_to_user=false` / `display_name` 非空，
     * 会通过 `TraeProvider::modelCatalog()` 的三条过滤规则混进模型列表（实测
     * `custom_model_gemini` 显示为 "Gemini-3.1-Pro-Preview"），但真正调用时上游回
     * `event:error {"code":4023,"message":"We're sorry, something went wrong..."}`。
     *
     * ⚠️ **绝不能归 UPSTREAM**：SSE error 事件抛异常时 `$status=0`，不显式识别就会掉进
     * `$status >= 500 || $status === 0` 分支 → `markError` 累加错误数 → 连续几次把
     * **完全健康**的账号打进 COOLING；账号冷却后候选集为空，用户侧就只会看到
     * 「账号池中没有可用账号」——把「选了个没开通的模型」误报成「账号池挂了」。
     * 实测正是这条链把 uid=2051059620009290 的日志刷成 UPSTREAM 连环失败。
     *
     * 判据：上游客户端错误码，与账号健康/内容审核都无关，不重试、不惩罚账号。
     * 同一账号下 `custom_model_gpt-5` 可用而 `custom_model_gemini` 报 4023，
     * 说明是**模型对当前账号未开通**而非前缀问题，故只按模型维度处理。
     */
    public const MODEL_UNAVAILABLE = 'MODEL_UNAVAILABLE';
    /**
     * 推理预算不足：推理模型把全部 max_tokens 花在思考上，正文一个 token 都没轮到。
     *
     * ⚠️ **绝不能归 CONTENT_BLOCKED**。两者对用户意味着完全不同的动作：
     *   · 本分类 → 提高 `max_tokens` 就能拿到正文（成本问题，可自行修复）；
     *   · CONTENT_BLOCKED → 只能改提示词/题材（内容问题）。
     * 历史上 hy3（codebuddy 推理模型）因两者混同，把「预算不够」报成
     * 「内容被上游安全策略拦截」，用户去反复改提示词，永远修不好。
     *
     * 实测（2026-09-30，`tools/probe-hy3-budget.php`）：同一账号同一提示词，
     *   max_tokens=512  → 正文 0 字，completion=512、reasoning=512、finish=length；
     *   max_tokens=8192 → 正文 1562 / 1884 字，2/2 通过。
     *
     * 与账号健康、上游服务都无关，**不重试、不惩罚账号、不冷却**，回 400 并给可执行提示。
     */
    public const REASONING_BUDGET = 'REASONING_BUDGET';
    /**
     * 上游节点级故障（上游自身某个推理节点执行失败）→ 与账号健康无关。
     *
     * 实测 Qoder 国内版：`qoder [FAIL]node:oa_qwen-plus-main msg:Execution failed: null（上游 code=400）`。
     * 该错误由上游以 HTTP 200 + `event:error` 信封下发（`code=400`），网关侧抛异常时
     * `$status=0`，不显式识别就会掉进 `$status >= 500 || $status === 0` 兜底分支被记成 UPSTREAM，
     * 进而走 `markError` 累加账号错误数 —— **把一个纯上游节点故障算成「这个账号有问题」**。
     *
     * 危害极大且已被实测：同一账号下 `gmodel`/`kmodel_latest`/`qmodel_38max` 等 13 个模型
     * 全部正常，**仅 `qfmodel` 命中坏节点**；但调用 `qfmodel` 一次就把账号打成 ERROR，
     * 随后**整个 Qoder 供应商的所有模型**都开始报「账号池中没有可用账号」——
     * 即「选错一个模型 → 拖垮整个供应商」。
     *
     * 判据：上游原文里的节点执行失败签名（`[FAIL]node:` / `msg:Execution failed`），
     * 与账号令牌、额度、内容审核都无关，**不惩罚账号、不冷却、不计数**。
     */
    public const UPSTREAM_NODE = 'UPSTREAM_NODE';
    /**
     * 上游风控拦截（豆包/火山 shark_admin，业务码 710022004）→ **账号级**，换号可恢复。
     *
     * 实测原文（HTTP 200 + SSE，抛异常时 code=0）：
     *   {"error_code":710022004,"error_msg":"rate limited",
     *    "extra":{"decision":"{\"from\":\"shark_admin\",\"type\":\"verify\",
     *              \"subtype\":\"semantic_reasoning\",\"verify_scene\":\"doubao_message_web\"}"}}
     *
     * ⚠️ **绝不能归 CONTENT_BLOCKED**（历史错误，2026-09-30 踩过）：
     *   · CONTENT_BLOCKED 是 isRequestFault=true → 网关 `break` **不换号**，
     *     并回「请调整提示词后重试」。
     *   · 但 710022004 对方是**账号/会话维度**的风控标记：实测同一账号连
     *     「你好」这种无害请求也被拦（`tools/probe-doubao-risk.php`），
     *     而**匿名请求（不带 cookie）返回的是 710012001「登录已过期」而非风控码**
     *     —— 说明端点未封 IP，被标记的是这个登录会话本身。
     *   · 于是"改提示词"对它完全无效：用户会陷入"反复改措辞却永远修不好"，
     *     而真正能恢复的动作（换账号 / 等该账号风控解除）根本没被执行。
     *
     * ⚠️ 也**绝不能归 SOFT_RATE**：它的 error_msg 恰好写着 "rate limited"，
     *   若按文案匹配会被 429 分支抢走 —— 那样虽然也会冷却账号，但语义错位
     *   （报成"限流"而非"风控"），且丢掉了"可换号恢复"这一关键调度动作。
     *
     * 归为 RISK_CONTROL：**可换号重试**（isRetryable=true）、**冷却本账号**
     * （affectsAccount=true，避免持续用被标记的号硬撞）、**非请求侧故障**
     * （isRequestFault=false，因为改内容无用）。
     */
    public const RISK_CONTROL = 'RISK_CONTROL';
    /**
     * 请求体过大（上游 413 / request entity too large）→ 换号无意义，直接回 413。
     *
     * ⚠️ **绝不能归 UPSTREAM**：413 是**请求侧**的体积问题，换多少个账号都一样。
     * 若掉进 `$status >= 500` 之外的 4xx 兜底会被记成 CLIENT（语义偏差），
     * 更糟的是若上游把它包在 200 信封里、抛异常时 code=0，就会掉进
     * `$status >= 500 || $status === 0` 分支 → `markError` 累加错误数 →
     * 一份超大请求就把**完全健康**的账号打进冷却。
     *
     * 判据：HTTP 413，或文案里的 request entity too large / payload too large。
     * 归为 PAYLOAD_TOO_LARGE：不重试、不惩罚账号，回 413 并提示精简请求体。
     */
    public const PAYLOAD_TOO_LARGE = 'PAYLOAD_TOO_LARGE';
    /** 上游返回空响应；无明确安全拦截证据，不换号、不惩罚账号。 */
    public const EMPTY_RESPONSE = 'EMPTY_RESPONSE';
    /** 未知 */
    public const UNKNOWN = 'UNKNOWN';

    /** 可通过切换账号自动恢复的分类 */
    public static function isRetryable(string $code): bool
    {
        return in_array($code, [
            self::QUOTA, self::SOFT_RATE, self::SESSION_DEAD,
            self::NOT_FOUND, self::UPSTREAM, self::MODEL_RATE, self::NETWORK,
            self::UPSTREAM_NODE, self::RISK_CONTROL,
        ], true);
    }

    /**
     * 是否需要把账号置为不可用（冷却或禁用）。
     *
     * ⚠️ RISK_CONTROL 计入此类：风控是**该账号被上游标记**，继续用它只会持续撞墙。
     * 冷却它、让调度层换其他账号，才是正确动作。
     */
    public static function affectsAccount(string $code): bool
    {
        return in_array($code, [self::QUOTA, self::SOFT_RATE, self::SESSION_DEAD, self::RISK_CONTROL], true);
    }

    /**
     * 是否为「与账号无关、由请求内容本身导致」的失败：
     * 换账号、等冷却都不会好，重试纯属浪费额度。
     */
    public static function isRequestFault(string $code): bool
    {
        return in_array($code, [
            self::CLIENT, self::CONTEXT_TOO_LONG, self::CONTENT_BLOCKED,
            self::MODEL_UNAVAILABLE, self::REASONING_BUDGET, self::PAYLOAD_TOO_LARGE,
        ], true);
    }

    public static function label(string $code): string
    {
        return match ($code) {
            self::QUOTA            => '额度耗尽',
            self::SOFT_RATE        => '触发限流',
            self::SESSION_DEAD     => '登录已失效',
            self::NOT_FOUND        => '资源不存在',
            self::CLIENT           => '请求参数错误',
            self::UPSTREAM         => '上游服务异常',
            self::MODEL_RATE       => '模型级限流',
            self::CONTEXT_TOO_LONG => '上下文超长',
            self::CONTENT_BLOCKED  => '内容审核拦截',
            self::REASONING_BUDGET => '推理预算不足',
            self::MODEL_UNAVAILABLE => '模型不可用',
            self::PAYLOAD_TOO_LARGE => '请求体过大',
            self::UPSTREAM_NODE    => '上游节点故障',
            self::RISK_CONTROL     => '上游风控拦截',
            self::NETWORK          => '网络/基建故障',
            self::EMPTY_RESPONSE   => '上游空响应',
            default                => '未知错误',
        };
    }
}