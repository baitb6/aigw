<?php
/**
 * 管理台 · 定时任务（自动签到 / 自动刷新额度）
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\GatewayRepository;
use App\Services\Scheduler;

class SchedulerController
{
    public function __construct(
        private Scheduler $scheduler,
        private GatewayRepository $gatewayRepository,
    ) {}

    /** GET /api/admin/scheduler —— 定时任务状态 */
    public function status(Request $request): Response
    {
        return Response::json([
            'ok'   => true,
            'data' => $this->scheduler->status(),
        ]);
    }

    /** POST /api/admin/scheduler —— 更新开关与时间点设置 */
    public function update(Request $request): Response
    {
        $payload = $request->json();
        $data = [];

        foreach (['auto_checkin', 'auto_refresh', 'credit_monitor_enabled', 'travel_enabled', 'activity_enabled', 'tasks_enabled', 'streak_enabled'] as $boolKey) {
            if (array_key_exists($boolKey, $payload)) {
                $data[$boolKey] = !empty($payload[$boolKey]) ? 1 : 0;
            }
        }

        // 猫猫旅行派出地点（固定偏好，默认「古镇客栈」）
        if (array_key_exists('travel_location', $payload)) {
            $location = trim((string) $payload['travel_location']);
            $data['travel_location'] = mb_substr($location !== '' ? $location : '古镇客栈', 0, 64);
        }

        if (array_key_exists('travel_rate_limit_ms', $payload)) {
            $ms = (int) $payload['travel_rate_limit_ms'];
            if ($ms < 0 || $ms > 60000) {
                return Response::json(['ok' => false, 'error' => ['message' => '旅行账号间隔需为 0-60000 毫秒']], 400);
            }
            $data['travel_rate_limit_ms'] = $ms;
        }

        if (array_key_exists('activity_rate_limit_ms', $payload)) {
            $ms = (int) $payload['activity_rate_limit_ms'];
            if ($ms < 0 || $ms > 60000) {
                return Response::json(['ok' => false, 'error' => ['message' => '活跃注入账号间隔需为 0-60000 毫秒']], 400);
            }
            $data['activity_rate_limit_ms'] = $ms;
        }

        // 签到时间点：接受 "9,21" / [9,21] 两种写法，归一化并去重排序
        if (array_key_exists('checkin_hours', $payload)) {
            $hours = [];
            $raw = $payload['checkin_hours'];
            foreach (is_array($raw) ? $raw : explode(',', (string) $raw) as $part) {
                $hour = (int) trim((string) $part);
                if ($hour >= 0 && $hour <= 23) {
                    $hours[] = $hour;
                }
            }
            $hours = array_values(array_unique($hours));
            sort($hours);
            if (empty($hours)) {
                return Response::json(['ok' => false, 'error' => ['message' => '签到时间点需为 0-23 之间的整数']], 400);
            }
            $data['checkin_hours'] = implode(',', $hours);
        }

        if (array_key_exists('refresh_hour', $payload)) {
            $hour = (int) $payload['refresh_hour'];
            if ($hour < 0 || $hour > 23) {
                return Response::json(['ok' => false, 'error' => ['message' => 'Token 保活时间点需为 0-23 之间的整数']], 400);
            }
            $data['refresh_hour'] = $hour;
        }

        if (array_key_exists('credit_patrol_minutes', $payload)) {
            $minutes = (int) $payload['credit_patrol_minutes'];
            if ($minutes < 1 || $minutes > 1440) {
                return Response::json(['ok' => false, 'error' => ['message' => '积分巡检间隔需为 1-1440 分钟']], 400);
            }
            $data['credit_patrol_minutes'] = $minutes;
        }

        if (array_key_exists('tasks_interval_minutes', $payload)) {
            $minutes = (int) $payload['tasks_interval_minutes'];
            if ($minutes < 1 || $minutes > 1440) {
                return Response::json(['ok' => false, 'error' => ['message' => '成长中心领奖间隔需为 1-1440 分钟']], 400);
            }
            $data['tasks_interval_minutes'] = $minutes;
        }

        // 对话计数型任务（chat_5 / Model_chat_*）每轮代发对话条数。
        // 🔴 上限 20：上游结算延迟 90~150s，条数越多本轮耗时越长；且真实用户也不该被代刷过多。
        if (array_key_exists('tasks_chat_seeds', $payload)) {
            $seeds = (int) $payload['tasks_chat_seeds'];
            if ($seeds < 0 || $seeds > 20) {
                return Response::json(['ok' => false, 'error' => ['message' => '成长任务代发对话条数需为 0-20']], 400);
            }
            $data['tasks_chat_seeds'] = $seeds;
        }

        if (array_key_exists('streak_interval_minutes', $payload)) {
            $minutes = (int) $payload['streak_interval_minutes'];
            if ($minutes < 1 || $minutes > 1440) {
                return Response::json(['ok' => false, 'error' => ['message' => '连登管家间隔需为 1-1440 分钟']], 400);
            }
            $data['streak_interval_minutes'] = $minutes;
        }

        if (array_key_exists('checkin_log_keep_days', $payload)) {
            $days = (int) $payload['checkin_log_keep_days'];
            if ($days < 1 || $days > 365) {
                return Response::json(['ok' => false, 'error' => ['message' => '签到日志保留天数需为 1-365 天']], 400);
            }
            $data['checkin_log_keep_days'] = $days;
        }

        if (empty($data)) {
            return Response::json(['ok' => false, 'error' => ['message' => '没有需要更新的字段']], 400);
        }

        $this->gatewayRepository->update($data);

        return Response::json([
            'ok'      => true,
            'message' => '定时任务设置已保存',
            'data'    => $this->scheduler->status(),
        ]);
    }

    /** POST /api/admin/scheduler/run —— 立即执行一次（忽略开关） */
    public function run(Request $request): Response
    {
        $result = $this->scheduler->run(true);

        return Response::json([
            'ok'      => true,
            'message' => $result['message'],
            'data'    => array_merge($this->scheduler->status(), ['result' => $result['data']]),
        ]);
    }
}