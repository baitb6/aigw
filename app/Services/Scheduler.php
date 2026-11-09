<?php
/**
 * 定时任务调度器
 *
 * 项目本身不依赖常驻进程，这里提供一个「幂等且可重复触发」的执行入口：
 *  - HTTP：POST /api/admin/scheduler/run（可被外部计划任务/宝塔定时访问）
 *  - CLI ：php bin/scheduler.php
 *
 * 调度内容（按设置的时间点/间隔判定，可重复安全触发）：
 *  1. 每日 checkin_hours（默认 9、21 点）签到 + 余额查询解冻
 *  2. 每日 checkin_hours（默认 9、21 点）猫猫旅行（与签到同时点）
 *  3. 每日 refresh_hour（默认 22 点）全账号 Token 保活续期
 *  4. 每 credit_patrol_minutes（默认 15 分钟）积分到期巡检，刷新余额与到期日
 *  5. 每日一次清理过期调用日志、签到日志与旅行日志（保留 N 天）
 *
 * 每次执行都会记录执行摘要，便于前端展示「上次执行时间 / 结果」。
 */

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CheckinLogRepository;
use App\Repositories\ActivityLogRepository;
use App\Repositories\ActionTaskRepository;
use App\Repositories\GatewayRepository;
use App\Repositories\LogRepository;
use App\Repositories\ResponsesRepository;
use App\Repositories\TravelLogRepository;
use App\Support\Logger;

class Scheduler
{
    public function __construct(
        private GatewayRepository $gatewayRepository,
        private AccountPool $accountPool,
        private CheckinLogRepository $checkinLogRepository,
        private TravelLogRepository $travelLogRepository,
        private ActivityLogRepository $activityLogRepository,
        private LogRepository $logRepository,
        private ActionTaskRepository $actionTaskRepository,
        private ResponsesRepository $responsesRepository,
    ) {}

    /**
     * 执行一次调度
     *
     * @param bool $force 忽略时间点/开关，立即执行全部任务（手动触发时使用）
     * @return array{ok:bool, ran:bool, message:string, data:array}
     */
    public function run(bool $force = false): array
    {
        $settings = $this->gatewayRepository->current();

        $autoCheckin = $force || (bool) ($settings['auto_checkin'] ?? false);
        $autoRefresh = $force || (bool) ($settings['auto_refresh'] ?? false);
        $patrolEnabled = $force || (bool) ($settings['credit_monitor_enabled'] ?? true);
        $travelEnabled = $force || (bool) ($settings['travel_enabled'] ?? true);
        $activityEnabled = $force || (bool) ($settings['activity_enabled'] ?? false);
        $tasksEnabled = $force || (bool) ($settings['tasks_enabled'] ?? true);
        $streakEnabled = $force || (bool) ($settings['streak_enabled'] ?? true);

        if (!$autoCheckin && !$autoRefresh && !$patrolEnabled && !$travelEnabled && !$activityEnabled && !$tasksEnabled && !$streakEnabled) {
            return [
                'ok'      => true,
                'ran'     => false,
                'message' => '定时任务未启用（签到、Token 保活、积分巡检、猫猫旅行、每日活跃、成长中心领奖、连登管家均为关闭状态）',
                'data'    => [],
            ];
        }

        $now       = now_ms();
        $hour      = (int) date('G');
        $hourKey   = (int) date('YmdH');
        $dayKey    = (int) date('Ymd');
        $result    = [];
        $watermark = [];

        // 0) 启动核验：跨天后的首次执行，对当日尚未签到的账号补签一次（幂等）
        if ($autoCheckin) {
            $lastCheckinDay = $this->dayKeyOf((int) ($settings['scheduler_last_checkin_at'] ?? 0));
            if ($force || $lastCheckinDay !== $dayKey) {
                $result['startup_checkin'] = $this->accountPool->verifyStartupCheckins();
            }
        }

        // 1) 每日签到（时间点匹配 + 当日水位幂等）
        if ($autoCheckin && $this->dueAtHour((int) ($settings['scheduler_last_checkin_at'] ?? 0), $hourKey, $force)) {
            $checkinHours = $this->parseHours((string) ($settings['checkin_hours'] ?? '9,21'));
            if ($force || in_array($hour, $checkinHours, true)) {
                $result['checkin'] = $this->accountPool->checkinAll(null, $force ? 'manual' : 'scheduled');
                // 签到后查询余额，解冻因额度为 0 而冷却的账号
                $result['checkin_refresh'] = $this->accountPool->refreshAll();
                $watermark['scheduler_last_checkin_at'] = now_ms();
            }
        }

        // 2) 每日猫猫旅行（与签到同一时间点，每日幂等由 travel_log 保证）
        if ($travelEnabled && $this->dueAtHour((int) ($settings['scheduler_last_travel_at'] ?? 0), $hourKey, $force)) {
            $checkinHours = $this->parseHours((string) ($settings['checkin_hours'] ?? '9,21'));
            if ($force || in_array($hour, $checkinHours, true)) {
                $result['travel'] = $this->accountPool->travelAll(null, $force ? 'manual' : 'scheduled');
                $watermark['scheduler_last_travel_at'] = now_ms();
            }
        }

        // 2.1) 每日活跃注入（WorkBuddy 国际版，与签到同一时间点，每日幂等由 activity_log 保证）
        if ($activityEnabled && $this->dueAtHour((int) ($settings['scheduler_last_activity_at'] ?? 0), $hourKey, $force)) {
            $checkinHours = $this->parseHours((string) ($settings['checkin_hours'] ?? '9,21'));
            if ($force || in_array($hour, $checkinHours, true)) {
                $result['activity'] = $this->accountPool->activityAll(null, $force ? 'manual' : 'scheduled');
                $watermark['scheduler_last_activity_at'] = now_ms();
            }
        }

        // 2.3) 成长中心领奖（WorkBuddy 国服：旅行到站领奖 + 成长任务领奖 + 开盲盒）
        //      按 tasks_interval_minutes（默认 60 分钟）间隔轮询，解决「旅行结束不会自动确认、任务不做」的问题
        //
        // 🔴 `tasks_chat_seeds`：为「对话计数型」任务（chat_5 / Model_chat_*）补发多少条对话。
        //    实测结算延迟 90~150s，故 Provider 侧会在发完后 sleep 再领奖 —— 本步可能耗时较长，
        //    但 Scheduler 在 CLI/计划任务里跑，不受 15s CDN 回源超时约束（见 §R）。
        $tasksIntervalMinutes = max(1, (int) ($settings['tasks_interval_minutes'] ?? 60));
        $tasksIntervalMs      = $tasksIntervalMinutes * 60000;
        $lastTasks            = (int) ($settings['scheduler_last_tasks_at'] ?? 0);
        if ($tasksEnabled && ($force || $now - $lastTasks >= $tasksIntervalMs)) {
            $seeds = max(0, (int) ($settings['tasks_chat_seeds'] ?? 0));
            $result['tasks'] = $this->accountPool->tasksAll(null, $force ? 'manual' : 'scheduled', false, null, $seeds);
            $watermark['scheduler_last_tasks_at'] = now_ms();
        }

        // 2.4) 连登管家（WorkBuddy 国服：补签 → 兑换连登奖励 → 抽奖 → 开盲盒 → 礼包/补偿）
        //      按 streak_interval_minutes（默认 60 分钟）间隔轮询；上游接口幂等，
        //      已办理的档位返回 duplicate/claimed，不会重复发放。
        $streakIntervalMinutes = max(1, (int) ($settings['streak_interval_minutes'] ?? 60));
        $streakIntervalMs      = $streakIntervalMinutes * 60000;
        $lastStreak            = (int) ($settings['scheduler_last_streak_at'] ?? 0);
        if ($streakEnabled && ($force || $now - $lastStreak >= $streakIntervalMs)) {
            $result['streak'] = $this->accountPool->streakAll(null, $force ? 'manual' : 'scheduled');
            $watermark['scheduler_last_streak_at'] = now_ms();
        }

        // 3) 每日 Token 保活（时间点匹配 + 当日水位幂等）
        if ($autoRefresh && $force) {
            $result['keepalive'] = $this->accountPool->keepaliveAll();
            $watermark['scheduler_last_refresh_at'] = now_ms();
        } elseif ($autoRefresh) {
            $refreshHour = (int) ($settings['refresh_hour'] ?? 22);
            if ($hour === $refreshHour && $this->dueAtHour((int) ($settings['scheduler_last_refresh_at'] ?? 0), $hourKey, false)) {
                $result['keepalive'] = $this->accountPool->keepaliveAll();
                $watermark['scheduler_last_refresh_at'] = now_ms();
            }
        }

        // 4) 积分到期巡检（按间隔判定）
        $patrolMinutes = max(1, (int) ($settings['credit_patrol_minutes'] ?? 15));
        $patrolMs      = $patrolMinutes * 60000;
        $lastPatrol    = (int) ($settings['scheduler_last_patrol_at'] ?? 0);
        if ($patrolEnabled && ($force || $now - $lastPatrol >= $patrolMs)) {
            $result['patrol'] = $this->accountPool->refreshAll();
            $watermark['scheduler_last_patrol_at'] = now_ms();
        }

        // 5) 日志清理（每天一次）
        $lastPrune = (int) ($settings['scheduler_last_prune_at'] ?? 0);
        if ($force || $this->dayKeyOf($lastPrune) !== $dayKey) {
            $result['prune'] = $this->pruneLogs($settings);
            $watermark['scheduler_last_prune_at'] = now_ms();
        }

        // 6) 异步任务看护（每次执行都跑，开销极小）
        //    worker 进程被 OOM/重启杀掉时任务会永远停在 PENDING/RUNNING，
        //    前端就会无限轮询。这里把超过存活时长的任务标 FAILED 收口。
        try {
            $result['reap_tasks'] = $this->actionTaskRepository->reapStale();
        } catch (\Throwable $e) {
            Logger::warn('异步任务看护失败：' . $e->getMessage());
        }

        // 6.1) Responses background 任务看护（同 action task：queued 短阈值 / in_progress 长阈值）
        try {
            $result['reap_responses'] = $this->responsesRepository->reapStale();
        } catch (\Throwable $e) {
            Logger::warn('Responses 任务看护失败：' . $e->getMessage());
        }

        if ($watermark !== []) {
            $watermark['scheduler_last_run'] = now_ms();
        }

        $summary = [];
        foreach ($result as $key => $item) {
            if (is_array($item) && isset($item['message'])) {
                $summary[] = (string) $item['message'];
            } elseif (is_int($item)) {
                $summary[] = $key . ' 清理 ' . $item . ' 条';
            }
        }
        $message = implode('；', array_filter($summary)) ?: '本次没有到点的任务';

        if ($watermark === []) {
            // 未到点：仅记录探测时间，避免外部误判为「从未执行」
            return [
                'ok'      => true,
                'ran'     => false,
                'message' => $message,
                'data'    => $result,
            ];
        }

        $watermark['scheduler_last_log'] = mb_substr($message, 0, 2000);
        $this->gatewayRepository->update($watermark);

        Logger::info('定时任务执行完成', ['message' => $message]);

        return [
            'ok'      => true,
            'ran'     => true,
            'message' => $message,
            'data'    => $result,
        ];
    }

    /**
     * 清理过期日志：调用日志 + 签到日志 + 旅行日志
     */
    private function pruneLogs(array $settings): int
    {
        $callKeep   = max(1, (int) ($settings['log_keep_days'] ?? 30));
        $checkinKeep = max(1, (int) ($settings['checkin_log_keep_days'] ?? 30));

        $deleted = $this->logRepository->prune($callKeep);
        $deleted += $this->checkinLogRepository->prune($checkinKeep);
        $deleted += $this->travelLogRepository->prune($checkinKeep);
        $deleted += $this->activityLogRepository->prune($checkinKeep);

        // 异步任务表：与签到日志同寿命（它本质是「动作执行留痕」，
        // 保留窗口一致即可）。不清理会随每次领取单调增长。
        $deleted += $this->actionTaskRepository->prune($checkinKeep);

        // Responses 响应对象：store 默认开启，会随调用量单调增长；
        // 与签到日志同寿命清理（background 记录也一样）。
        $deleted += $this->responsesRepository->prune($checkinKeep);

        // 行数上限保护：按天清理之外，再按 log_max_rows 截断，防止高频调用把表撑爆
        $deleted += $this->logRepository->enforceRowLimit((int) ($settings['log_max_rows'] ?? 200000));

        // 正文体积治理：只保留最近 log_body_max_rows 行的正文，更早的旧日志清空正文、
        // 保留全部统计列 —— 大屏指标不受影响，库体积却不再随调用量线性膨胀。
        $this->logRepository->enforceBodyRowLimit((int) ($settings['log_body_max_rows'] ?? 20000));

        return $deleted;
    }

    /**
     * 时间点水位判定：同一小时内未执行过，则视为到点（force 恒为真）
     */
    private function dueAtHour(int $lastAt, int $hourKey, bool $force): bool
    {
        if ($force) {
            return true;
        }
        return $this->hourKeyOf($lastAt) !== $hourKey;
    }

    private function hourKeyOf(int $ms): int
    {
        if ($ms <= 0) {
            return 0;
        }
        return (int) date('YmdH', (int) floor($ms / 1000));
    }

    private function dayKeyOf(int $ms): int
    {
        if ($ms <= 0) {
            return 0;
        }
        return (int) date('Ymd', (int) floor($ms / 1000));
    }

    /**
     * 解析签到时间点字符串，如 "9,21" → [9, 21]
     *
     * @return int[]
     */
    private function parseHours(string $raw): array
    {
        $hours = [];
        foreach (explode(',', $raw) as $part) {
            $hour = (int) trim($part);
            if ($hour >= 0 && $hour <= 23) {
                $hours[] = $hour;
            }
        }
        return $hours !== [] ? array_values(array_unique($hours)) : [9, 21];
    }

    /**
     * 定时任务状态（供前端展示）
     */
    public function status(): array
    {
        $settings = $this->gatewayRepository->current();

        return [
            'auto_checkin'       => (bool) ($settings['auto_checkin'] ?? false),
            'auto_refresh'       => (bool) ($settings['auto_refresh'] ?? false),
            'credit_monitor_enabled' => (bool) ($settings['credit_monitor_enabled'] ?? true),
            'travel_enabled'     => (bool) ($settings['travel_enabled'] ?? true),
            'activity_enabled'   => (bool) ($settings['activity_enabled'] ?? false),
            'tasks_enabled'      => (bool) ($settings['tasks_enabled'] ?? true),
            'streak_enabled'     => (bool) ($settings['streak_enabled'] ?? true),
            'activity_rate_limit_ms' => (int) ($settings['activity_rate_limit_ms'] ?? 1500),
            'tasks_interval_minutes' => (int) ($settings['tasks_interval_minutes'] ?? 60),
            'tasks_chat_seeds'   => (int) ($settings['tasks_chat_seeds'] ?? 0),
            'streak_interval_minutes' => (int) ($settings['streak_interval_minutes'] ?? 60),
            'travel_location'    => (string) ($settings['travel_location'] ?? '古镇客栈'),
            'checkin_hours'      => (string) ($settings['checkin_hours'] ?? '9,21'),
            'refresh_hour'       => (int) ($settings['refresh_hour'] ?? 22),
            'credit_patrol_minutes' => (int) ($settings['credit_patrol_minutes'] ?? 15),
            'checkin_log_keep_days' => (int) ($settings['checkin_log_keep_days'] ?? 30),
            'last_run'           => (int) ($settings['scheduler_last_run'] ?? 0),
            'last_run_ago_ms'    => max(0, now_ms() - (int) ($settings['scheduler_last_run'] ?? 0)),
            'last_log'           => (string) ($settings['scheduler_last_log'] ?? ''),
            'last_checkin_at'    => (int) ($settings['scheduler_last_checkin_at'] ?? 0),
            'last_travel_at'     => (int) ($settings['scheduler_last_travel_at'] ?? 0),
            'last_activity_at'   => (int) ($settings['scheduler_last_activity_at'] ?? 0),
            'last_tasks_at'      => (int) ($settings['scheduler_last_tasks_at'] ?? 0),
            'last_refresh_at'    => (int) ($settings['scheduler_last_refresh_at'] ?? 0),
            'last_patrol_at'     => (int) ($settings['scheduler_last_patrol_at'] ?? 0),
            'checkin_today'      => $this->checkinLogRepository->todaySummary(),
            'travel_today'       => $this->travelLogRepository->todaySummary(),
            'activity_today'     => $this->activityLogRepository->todaySummary(),
            'endpoint'           => '/api/admin/scheduler/run',
            'cron_hint'          => 'php ' . AIGW_ROOT . '/bin/scheduler.php',
        ];
    }
}
