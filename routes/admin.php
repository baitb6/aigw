<?php
/**
 * 管理台路由（/api/admin/*）
 * 由 Application::registerRoutes() 自动加载
 *
 * @var \App\Core\Router $router
 */

declare(strict_types=1);

use App\Controllers\Admin\AccountController;
use App\Controllers\Admin\AuditController;
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\LogController;
use App\Controllers\Admin\ModelController;
use App\Controllers\Admin\PersonaController;
use App\Controllers\Admin\ProviderController;
use App\Controllers\Admin\PromptTemplateController;
use App\Controllers\Admin\SchedulerController;
use App\Controllers\Admin\ScreenController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\UserController;
use App\Controllers\MailController;
use App\Middleware\AdminAuthMiddleware;
use App\Middleware\CorsMiddleware;

// ── 无需登录（仅 CORS） ───────────────────────────────────────────
// 登录入口路径可通过 AIGW_ADMIN_LOGIN_PATH 自定义（隐藏默认入口，降低扫描爆破）
$router->post(admin_login_path(), AuthController::class . '@login', [CorsMiddleware::class]);

// ── 需要登录 ──────────────────────────────────────────────────────
$router->group('', [CorsMiddleware::class, AdminAuthMiddleware::class], function ($router) {
    // 会话
    $router->post('/api/admin/logout', AuthController::class . '@logout');
    $router->get('/api/admin/me', AuthController::class . '@me');
    $router->post('/api/admin/profile', AuthController::class . '@updateProfile');
    $router->post('/api/admin/password', AuthController::class . '@changePassword');

    // 仪表盘
    $router->get('/api/admin/dashboard', DashboardController::class . '@index');

    // 网关设置
    $router->get('/api/admin/settings', SettingsController::class . '@show');
    $router->post('/api/admin/settings', SettingsController::class . '@update');
    $router->post('/api/admin/settings/regenerate-key', SettingsController::class . '@regenerateApiKey');

    // 邮件（SMTP）设置与测试发信 —— 端口不限，80 端口亦可发信
    $router->get('/api/admin/mail/config', MailController::class . '@show');
    $router->post('/api/admin/mail/test', MailController::class . '@test');
    $router->post('/api/admin/mail/test-connect', MailController::class . '@testConnect');

    // 代理设置
    $router->get('/api/admin/proxy', SettingsController::class . '@proxy');
    $router->post('/api/admin/proxy', SettingsController::class . '@updateProxy');

    // 提示词模板（全局）
    $router->get('/api/admin/prompt-templates', PromptTemplateController::class . '@index');
    $router->post('/api/admin/prompt-templates', PromptTemplateController::class . '@store');
    $router->put('/api/admin/prompt-templates/{id}', PromptTemplateController::class . '@update');
    $router->delete('/api/admin/prompt-templates/{id}', PromptTemplateController::class . '@destroy');

    // 思维注入（全局思维）
    // 注意：字面量路径必须先注册，否则会被 /{id} 的通配吃掉
    $router->get('/api/admin/personas', PersonaController::class . '@index');
    $router->get('/api/admin/personas/status', PersonaController::class . '@status');
    $router->post('/api/admin/personas', PersonaController::class . '@store');
    $router->post('/api/admin/personas/seed', PersonaController::class . '@seed');
    $router->post('/api/admin/personas/preview', PersonaController::class . '@preview');
    $router->post('/api/admin/personas/selfcheck', PersonaController::class . '@selfcheck');
    $router->get('/api/admin/personas/{id}', PersonaController::class . '@show');
    $router->put('/api/admin/personas/{id}', PersonaController::class . '@update');
    $router->delete('/api/admin/personas/{id}', PersonaController::class . '@destroy');
    $router->post('/api/admin/personas/{id}/default', PersonaController::class . '@setDefault');
    $router->post('/api/admin/personas/{id}/target', PersonaController::class . '@target');
    $router->get('/api/admin/personas/{id}/revisions', PersonaController::class . '@revisions');
    $router->post('/api/admin/personas/{id}/restore', PersonaController::class . '@restore');

    // 供应商
    $router->get('/api/admin/providers', ProviderController::class . '@index');
    $router->post('/api/admin/providers/custom', ProviderController::class . '@storeCustom');
    $router->put('/api/admin/providers/{key}', ProviderController::class . '@update');
    $router->delete('/api/admin/providers/{key}', ProviderController::class . '@destroyCustom');
    $router->post('/api/admin/providers/{key}/test', ProviderController::class . '@test');

    // 账号池
    $router->get('/api/admin/accounts', AccountController::class . '@index');
    $router->post('/api/admin/accounts', AccountController::class . '@store');
    $router->post('/api/admin/accounts/refresh-all', AccountController::class . '@refreshAll');
    $router->post('/api/admin/accounts/checkin-all', AccountController::class . '@checkinAll');
    $router->get('/api/admin/accounts/checkin-logs', AccountController::class . '@checkinLogs');
    $router->post('/api/admin/accounts/travel-all', AccountController::class . '@travelAll');
    $router->get('/api/admin/accounts/travel-logs', AccountController::class . '@travelLogs');
    $router->post('/api/admin/accounts/activity-all', AccountController::class . '@activityAll');
    $router->get('/api/admin/accounts/activity-logs', AccountController::class . '@activityLogs');
    $router->post('/api/admin/accounts/tasks-all', AccountController::class . '@tasksAll');
    $router->get('/api/admin/accounts/credits/monitor', AccountController::class . '@creditMonitor');
    $router->get('/api/admin/account-pool/overview', AccountController::class . '@overview');
    // ★ 异步动作任务查询：必须注册在 /accounts/{id} **之前**，
    //   否则会被 {id} 通配吞掉（"action-task" 会被当成账号 id）。
    $router->get('/api/admin/accounts/action-task', AccountController::class . '@actionTask');
    // ★ 成长中心（猫猫旅行 / 成长任务的可视化页面）：同样必须先于 /accounts/{id} 注册，
    //   否则 "growth" 会被 {id} 通配吞掉当成账号 id。
    $router->get('/api/admin/accounts/growth', AccountController::class . '@growth');
    // ★ 后台任务运行环境自检（2026-10-02）—— 在 **web/FPM 上下文**里跑，
    //   暴露 CLI 诊断看不到的 FPM 侧限制（disable_functions / open_basedir）。
    //   同样必须先于 /accounts/{id} 注册。
    $router->get('/api/admin/accounts/worker-env', AccountController::class . '@workerEnvCheck');
    $router->get('/api/admin/accounts/{id}', AccountController::class . '@show');
    $router->put('/api/admin/accounts/{id}', AccountController::class . '@update');
    $router->delete('/api/admin/accounts/{id}', AccountController::class . '@destroy');
    $router->post('/api/admin/accounts/{id}/toggle', AccountController::class . '@toggle');
    $router->post('/api/admin/accounts/{id}/refresh', AccountController::class . '@refresh');
    $router->post('/api/admin/accounts/{id}/action', AccountController::class . '@action');
    $router->post('/api/admin/accounts/{id}/models', AccountController::class . '@models');
    $router->post('/api/admin/accounts/{id}/cooldown/clear', AccountController::class . '@clearCooldown');
    // 成长中心单账号快照（只读，页面并发拉取用）
    $router->get('/api/admin/accounts/{id}/growth', AccountController::class . '@growthAccount');
    // 成长中心单项动作（猫猫旅行 / 领任务奖 / 开盲盒 / 兑换连登 / 抽奖 / 补签 …）
    $router->post('/api/admin/accounts/{id}/growth', AccountController::class . '@growthAction');

    // 登录流程（浏览器授权 / 短信 / 设备码）
    $router->post('/api/admin/auth/{provider}/start', AccountController::class . '@startLogin');
    $router->post('/api/admin/auth/{provider}/poll', AccountController::class . '@pollLogin');
    // 粘贴回调地址兜底：回环回调页打不开时，把地址栏完整 URL 贴回来完成入库
    $router->post('/api/admin/auth/{provider}/callback', AccountController::class . '@submitCallback');

    // 模型
    $router->get('/api/admin/models', ModelController::class . '@index');
    $router->post('/api/admin/models', ModelController::class . '@store');
    $router->post('/api/admin/models/toggle-provider', ModelController::class . '@toggleProvider');
    $router->post('/api/admin/models/sync', ModelController::class . '@sync');
    $router->put('/api/admin/models/{id}', ModelController::class . '@update');
    $router->delete('/api/admin/models/{id}', ModelController::class . '@destroy');

    // 定时任务
    $router->get('/api/admin/scheduler', SchedulerController::class . '@status');
    $router->post('/api/admin/scheduler', SchedulerController::class . '@update');
    $router->post('/api/admin/scheduler/run', SchedulerController::class . '@run');

    // 调用日志
    $router->get('/api/admin/logs', LogController::class . '@index');
    // 单条完整详情（含完整正文与思维注入正文，压缩态自动解压）——
    // 必须注册在 /api/admin/logs/{id} 形式的通配之前，避免被当成 id 吞掉
    $router->get('/api/admin/logs/detail', LogController::class . '@detail');
    $router->get('/api/admin/logs/stats', LogController::class . '@stats');
    $router->get('/api/admin/logs/export', LogController::class . '@export');
    $router->post('/api/admin/logs/prune', LogController::class . '@prune');
    $router->post('/api/admin/logs/clear', LogController::class . '@clear');

    // 数据大屏（调用量 / 注册登录 / 累计指标）—— 三块屏共用一个聚合入口
    $router->get('/api/admin/screens', ScreenController::class . '@index');

    // 用户行为审计（谁在何时对什么做了什么）
    // 字面量路径先注册，避免被通配吃掉的同类问题
    $router->get('/api/admin/audit', AuditController::class . '@index');
    $router->get('/api/admin/audit/stats', AuditController::class . '@stats');
    $router->get('/api/admin/audit/options', AuditController::class . '@options');
    $router->get('/api/admin/audit/timeline', AuditController::class . '@timeline');
    $router->get('/api/admin/audit/export', AuditController::class . '@export');
    $router->post('/api/admin/audit/prune', AuditController::class . '@prune');

    // 普通用户管理
    $router->get('/api/admin/users', UserController::class . '@index');
    $router->get('/api/admin/users/{id}', UserController::class . '@show');
    $router->put('/api/admin/users/{id}', UserController::class . '@update');
    $router->delete('/api/admin/users/{id}', UserController::class . '@destroy');
    $router->post('/api/admin/users/{id}/toggle', UserController::class . '@toggle');
    $router->post('/api/admin/users/{id}/regenerate-key', UserController::class . '@regenerateKey');
});
