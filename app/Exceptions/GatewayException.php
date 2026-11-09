<?php
/**
 * 网关业务异常：一个异常同时携带 **HTTP 状态码** 与 **业务码**。
 *
 * 为什么要专门一个类（约定 9「异常分类必须带业务码」）：
 *   `\RuntimeException` 只有一个 code 字段，历史上被用来塞 HTTP 状态码，
 *   于是对外错误体的 `error.code` **无从表达"到底哪一类错"**，且两个通道口径还不一致：
 *     · 非流式路径（GatewayController::nonStreaming）：硬编码 `'upstream_error'`
 *       —— 参数错、限流、池空、内容拦截全都是这一个值；
 *     · 流式路径（GatewayController::streaming）：直接用 `(string) $e->getCode()`
 *       —— 变成 `"503"` / `"400"` 这种 HTTP 码字符串。
 *   同一条错误、两个通道给出**不同**的 code，客户端（Trae Work / Claude Code 等
 *   Agent 型客户端）既无法据此分支处理，也无法与调用日志的 `error_code` 对齐排查。
 *
 * 本类把两者分开：
 *   · `getCode()`        —— HTTP 状态码（**向后兼容**既有 catch 与 status 计算）
 *   · `businessCode()`   —— 与调用日志 `aigw_call_log.error_code` **同源**的分类码
 *                           （取自 `ErrorCategory` 常量）
 *
 * ⚠️ 与 `LogRepository` 里记的 `error_code` 必须**同源**：否则日志里是 `RISK_CONTROL`、
 *    回给客户端却是 `upstream_error`，两边对不上，排查时无法互证（约定 15/25）。
 */

declare(strict_types=1);

namespace App\Exceptions;

final class GatewayException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $httpStatus,
        private readonly string $businessCode,
    ) {
        parent::__construct($message, $httpStatus);
    }

    /**
     * 业务码：与 `aigw_call_log.error_code` 同源（`ErrorCategory` 常量）。
     */
    public function businessCode(): string
    {
        return $this->businessCode;
    }
}
