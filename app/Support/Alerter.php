<?php
/**
 * 失败告警 Webhook 推送（新功能，2026-11）
 *
 * 触发点：LogRepository::record() 落库一条**失败**调用日志之后。
 * 收敛到这一个 choke point，所有失败路径（换号耗尽 / 上游故障 / 超时熔断）
 * 都天然覆盖，不必在 GatewayService 的十余个 throw 点逐个埋钩。
 *
 * 设计约束：
 *  - 挂在写日志的热路径上 → 必须**极快失败**：3s 超时、全程 try/catch 静默降级；
 *  - 防告警风暴：按「供应商 + 错误码」分桶节流（默认 300s 一桶，可调）；
 *  - SSRF 防护：webhook 地址属管理员可控但仍走 OutboundGuard
 *    （协议白名单 + 内网拒绝 + DNS 锁定），防止管理端配置被滥用于探测内网；
 *  - 载荷用 {msgtype:"text", text:{content}} 形态 —— 企业微信 / 钉钉
 *    自定义机器人原生兼容，其它系统可按 JSON 自行解析。
 */

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;
use App\Repositories\GatewayRepository;

final class Alerter
{
    /** 默认节流窗口（秒）：同供应商同错误码在该窗口内只推一次 */
    private const DEFAULT_COOLDOWN = 300;

    /** webhook 请求超时（秒）：宁丢勿堵，绝不允许拖慢网关主链路 */
    private const TIMEOUT = 3;

    /**
     * 调用失败时的入口。任何异常都必须内部消化，不得冒泡到主链路。
     *
     * @param array $log 落库的日志字段（provider_key/model/error_code/error/user_id 等）
     */
    public static function onCallFailed(array $log): void
    {
        try {
            $settings = (new GatewayRepository(Database::instance()))->current();

            if (empty($settings['alert_webhook_enabled'])) {
                return;
            }
            $url = trim((string) ($settings['alert_webhook_url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                return;
            }

            $provider = (string) ($log['provider_key'] ?? 'unknown');
            $errorCode = (string) ($log['error_code'] ?? 'UNKNOWN');

            // 告警风暴节流：同桶在冷却窗口内只推一次
            $cooldown = max(30, (int) ($settings['alert_cooldown_seconds'] ?? self::DEFAULT_COOLDOWN));
            if (self::throttled($provider, $errorCode, $cooldown)) {
                return;
            }

            $appName = (string) ($settings['app_name'] ?? config('app.name', 'AI 聚合网关'));
            $content = implode("\n", [
                '🔴【' . $appName . '】调用失败告警',
                '供应商：' . $provider,
                '模型：' . (string) ($log['model'] ?? '-'),
                '错误码：' . $errorCode,
                '错误：' . mb_substr((string) ($log['error'] ?? ''), 0, 200),
                '时间：' . date('Y-m-d H:i:s'),
                '（同供应商同错误码 ' . $cooldown . 's 内不再重复推送）',
            ]);

            $response = (new HttpClient())
                ->withOutboundGuard()
                ->timeout(self::TIMEOUT, 2)
                ->postJson($url, [
                    'msgtype' => 'text',
                    'text'    => ['content' => $content],
                ]);

            if (!$response->ok()) {
                Logger::warn('告警 webhook 推送失败', [
                    'status' => $response->status(),
                    'error'  => $response->error(),
                ]);
            }
        } catch (\Throwable $e) {
            // 静默降级：告警通道坏掉绝不能影响正常请求
            Logger::warn('告警推送异常（已忽略）', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 分桶节流。返回 true 表示「窗口内已推过，本次跳过」。
     * 哨兵文件存 storage/tmp/alerts/，写失败时退化为不节流（多推几条无害）。
     */
    private static function throttled(string $provider, string $errorCode, int $cooldown): bool
    {
        $dir = AIGW_ROOT . '/storage/tmp/alerts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $marker = $dir . '/' . md5($provider . '|' . $errorCode) . '.ts';

        $last = is_file($marker) ? (int) @filemtime($marker) : 0;
        if ($last > 0 && (time() - $last) < $cooldown) {
            return true;
        }
        @touch($marker);

        return false;
    }
}
