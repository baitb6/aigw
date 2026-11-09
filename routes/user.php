<?php
/**
 * 用户端路由（/api/user/*）
 * 由 Application::registerRoutes() 自动加载
 *
 * @var \App\Core\Router $router
 */

declare(strict_types=1);

use App\Controllers\Admin\AccountController;
use App\Controllers\Admin\AuditController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\LogController;
use App\Controllers\Admin\ModelController;
use App\Controllers\Admin\PersonaController;
use App\Controllers\Admin\ProviderController;
use App\Controllers\Admin\PromptTemplateController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\CaptchaController;
use App\Controllers\MailController;
use App\Controllers\SiteController;
use App\Controllers\User\UserAuthController;
use App\Controllers\User\McpController;
use App\Controllers\User\MemoryController;
use App\Controllers\User\WorkflowController;
use App\Controllers\User\SpeechController;
use App\Controllers\User\WorldBookController;
use App\Controllers\User\ToolsController;
use App\Controllers\User\PlanController;
use App\Controllers\User\ResearchController;
use App\Controllers\User\ChatSettingController;
use App\Controllers\User\PluginController;
use App\Controllers\User\EnvironmentController;
use App\Controllers\User\ModelProfileController;
use App\Controllers\User\CharacterCardController;
use App\Controllers\User\SubagentController;
use App\Controllers\GatewayController;
use App\Middleware\CorsMiddleware;
use App\Middleware\UserAuthMiddleware;

// ── 无需登录（仅 CORS） ───────────────────────────────────────────
$router->get('/api/site-config', SiteController::class . '@config', [CorsMiddleware::class]);
// 健康检查（监控探活；公开、零敏感信息，db 异常时回 503）
$router->get('/api/health', SiteController::class . '@health', [CorsMiddleware::class]);
$router->get('/api/captcha', CaptchaController::class . '@image', [CorsMiddleware::class]);
// 真实客户端 IP 诊断（CDN 场景部署自查；只回显请求者自己的 IP）
$router->get('/api/client-ip', SiteController::class . '@clientIp', [CorsMiddleware::class]);
$router->get('/api/user/register-config', UserAuthController::class . '@registerConfig', [CorsMiddleware::class]);
// 邮箱验证码（注册/找回密码/改密码；限流在控制器内按邮箱 + IP 双维度执行）
// purpose=change 时**必须已登录**，收件地址强制取会话邮箱，不接受前端传值
$router->post('/api/user/email-code', MailController::class . '@sendUserCode', [CorsMiddleware::class]);
$router->post('/api/user/register', UserAuthController::class . '@register', [CorsMiddleware::class]);
$router->post('/api/user/login', UserAuthController::class . '@login', [CorsMiddleware::class]);
// 找回密码（未登录态，凭邮箱验证码证明身份；防爆破走 LoginGuard 的 reset 维度）
$router->post('/api/user/reset-password', UserAuthController::class . '@resetPassword', [CorsMiddleware::class]);

// ── 需要登录 ──────────────────────────────────────────────────────
$router->group('', [CorsMiddleware::class, UserAuthMiddleware::class], function ($router) {
    $router->post('/api/user/logout', UserAuthController::class . '@logout');
    $router->get('/api/user/me', UserAuthController::class . '@me');
    // App 使用网站登录会话调用现有网关，服务端按当前用户隔离账号池；无需向客户端下发 API Key。
    $router->get('/api/app/models', GatewayController::class . '@models');
    $router->post('/api/app/chat/completions', GatewayController::class . '@chatCompletions');
    $router->post('/api/user/profile', UserAuthController::class . '@updateProfile');
    $router->post('/api/user/password', UserAuthController::class . '@changePassword');
    $router->post('/api/user/api-key/regenerate', UserAuthController::class . '@regenerateApiKey');

    // 总览面板（仅统计本人数据）
    $router->get('/api/user/dashboard', DashboardController::class . '@index');

    // 账号池总览（仅本人账号）
    $router->get('/api/user/account-pool/overview', AccountController::class . '@overview');

    // 供应商列表（仅返回可用元数据，账号统计按本人聚合）
    $router->get('/api/user/providers', ProviderController::class . '@userIndex');
    // 自建 / 修改 / 删除自定义供应商（仅本人实例，key 形如 custom_u<uid>_xxx）
    $router->post('/api/user/providers/custom', ProviderController::class . '@userStoreCustom');
    $router->put('/api/user/providers/{key}', ProviderController::class . '@userUpdate');
    $router->delete('/api/user/providers/{key}', ProviderController::class . '@userDestroyCustom');
    // 连通性自检（仅本人账号；作用域校验在控制器内）
    $router->post('/api/user/providers/{key}/test', ProviderController::class . '@userTest');

    // 模型目录（作用域感知：全局模型 + 本人私有模型；可同步 / 增删改）
    // 注意：字面量路径必须先注册，否则会被 /{id} 的通配吃掉
    $router->get('/api/user/models', ModelController::class . '@userIndex');
    $router->post('/api/user/models', ModelController::class . '@store');
    $router->post('/api/user/models/sync', ModelController::class . '@sync');
    $router->post('/api/user/models/toggle-provider', ModelController::class . '@toggleProvider');
    $router->put('/api/user/models/{id}', ModelController::class . '@update');
    $router->delete('/api/user/models/{id}', ModelController::class . '@destroy');

    // 网关设置（只读接入信息：网关地址与本人 API Key）
    $router->get('/api/user/settings', SettingsController::class . '@userShow');

    // 提示词模板（本人自建；全局模板只读）
    $router->get('/api/user/prompt-templates', PromptTemplateController::class . '@userIndex');
    $router->post('/api/user/prompt-templates', PromptTemplateController::class . '@userStore');
    $router->put('/api/user/prompt-templates/{id}', PromptTemplateController::class . '@userUpdate');
    $router->delete('/api/user/prompt-templates/{id}', PromptTemplateController::class . '@userDestroy');

    // 思维注入（本人自建；全局思维只读）
    // 注意：字面量路径必须先注册，否则会被 /{id} 的通配吃掉
    $router->get('/api/user/personas', PersonaController::class . '@userIndex');
    $router->post('/api/user/personas', PersonaController::class . '@userStore');
    $router->post('/api/user/personas/platform', PersonaController::class . '@userSetPlatform');
    $router->post('/api/user/personas/enable', PersonaController::class . '@userSetEnable');
    $router->post('/api/user/personas/selfcheck', PersonaController::class . '@userSelfcheck');
    $router->put('/api/user/personas/{id}', PersonaController::class . '@userUpdate');
    $router->delete('/api/user/personas/{id}', PersonaController::class . '@userDestroy');

    // 我的账户（属主隔离，复用管理台控制器；作用域由 Tenant 决定）
    $router->get('/api/user/accounts', AccountController::class . '@index');
    $router->post('/api/user/accounts', AccountController::class . '@store');
    $router->post('/api/user/accounts/refresh-all', AccountController::class . '@refreshAll');
    $router->post('/api/user/accounts/checkin-all', AccountController::class . '@checkinAll');
    $router->get('/api/user/accounts/checkin-logs', AccountController::class . '@checkinLogs');
    $router->post('/api/user/accounts/travel-all', AccountController::class . '@travelAll');
    $router->get('/api/user/accounts/travel-logs', AccountController::class . '@travelLogs');
    $router->post('/api/user/accounts/activity-all', AccountController::class . '@activityAll');
    $router->get('/api/user/accounts/activity-logs', AccountController::class . '@activityLogs');
    $router->post('/api/user/accounts/tasks-all', AccountController::class . '@tasksAll');
    $router->get('/api/user/accounts/credits/monitor', AccountController::class . '@creditMonitor');
    // ★ 异步动作任务查询：必须注册在 /accounts/{id} **之前**，否则会被 {id} 通配吞掉
    $router->get('/api/user/accounts/action-task', AccountController::class . '@actionTask');
    // ★ 成长中心可视化（必须先于 /accounts/{id} 注册，否则被 {id} 通配吞掉）
    $router->get('/api/user/accounts/growth', AccountController::class . '@growth');
    $router->get('/api/user/accounts/{id}', AccountController::class . '@show');
    $router->put('/api/user/accounts/{id}', AccountController::class . '@update');
    $router->delete('/api/user/accounts/{id}', AccountController::class . '@destroy');
    $router->post('/api/user/accounts/{id}/toggle', AccountController::class . '@toggle');
    $router->post('/api/user/accounts/{id}/refresh', AccountController::class . '@refresh');
    $router->post('/api/user/accounts/{id}/action', AccountController::class . '@action');
    $router->post('/api/user/accounts/{id}/models', AccountController::class . '@models');
    $router->post('/api/user/accounts/{id}/cooldown/clear', AccountController::class . '@clearCooldown');
    $router->get('/api/user/accounts/{id}/growth', AccountController::class . '@growthAccount');
    $router->post('/api/user/accounts/{id}/growth', AccountController::class . '@growthAction');

    // 添加账号的登录流程（浏览器授权 / 短信 / 设备码 / API Key）
    $router->post('/api/user/auth/{provider}/start', AccountController::class . '@startLogin');
    $router->post('/api/user/auth/{provider}/poll', AccountController::class . '@pollLogin');
    $router->post('/api/user/auth/{provider}/callback', AccountController::class . '@submitCallback');

    // 调用日志（仅本人调用）
    $router->get('/api/user/logs', LogController::class . '@index');
    // 单条完整详情（正文完整保留，作用域由 Tenant::scope() 锁定为本人）
    $router->get('/api/user/logs/detail', LogController::class . '@detail');
    $router->get('/api/user/logs/stats', LogController::class . '@stats');
    $router->get('/api/user/logs/export', LogController::class . '@export');

    // 我的操作记录（仅本人；作用域由 Tenant::scope() 锁定）
    $router->get('/api/user/audit', AuditController::class . '@index');
    $router->get('/api/user/audit/timeline', AuditController::class . '@timeline');

    // ── MCP 接入（Operit 生态；属主隔离）────────────────────────────
    // 注意：字面量路径必须先注册，否则会被 /{id} 的通配吃掉
    $router->get('/api/user/mcp', McpController::class . '@index');
    $router->post('/api/user/mcp', McpController::class . '@store');
    $router->post('/api/user/mcp/test', McpController::class . '@test');
    // Agent 侧：汇总网关托管的 MCP 工具 + 代理调用（必须早于 /{id}）
    $router->get('/api/user/mcp/tools', McpController::class . '@tools');
    $router->post('/api/user/mcp/invoke', McpController::class . '@invoke');
    $router->put('/api/user/mcp/{id}', McpController::class . '@update');
    $router->delete('/api/user/mcp/{id}', McpController::class . '@destroy');
    $router->post('/api/user/mcp/{id}/refresh', McpController::class . '@refresh');

    // ── 记忆（向量记忆；属主隔离）──────────────────────────────────
    $router->get('/api/user/memory', MemoryController::class . '@index');
    $router->post('/api/user/memory', MemoryController::class . '@store');
    $router->post('/api/user/memory/recall', MemoryController::class . '@recall');
    $router->post('/api/user/memory/clear', MemoryController::class . '@clear');
    $router->put('/api/user/memory/{id}', MemoryController::class . '@update');
    $router->delete('/api/user/memory/{id}', MemoryController::class . '@destroy');

    // ── 工作流（调度与执行；属主隔离）──────────────────────────────
    $router->get('/api/user/workflows', WorkflowController::class . '@index');
    $router->post('/api/user/workflows', WorkflowController::class . '@store');
    $router->post('/api/user/workflows/validate', WorkflowController::class . '@validate');
    $router->put('/api/user/workflows/{id}', WorkflowController::class . '@update');
    $router->delete('/api/user/workflows/{id}', WorkflowController::class . '@destroy');
    $router->post('/api/user/workflows/{id}/run', WorkflowController::class . '@run');
    $router->get('/api/user/workflows/{id}/tasks', WorkflowController::class . '@tasks');

    // ── 语音（STT/TTS 服务端代理，Key 不下发客户端）────────────────
    $router->get('/api/user/speech/capabilities', SpeechController::class . '@capabilities');
    $router->post('/api/user/speech/stt', SpeechController::class . '@stt');
    $router->post('/api/user/speech/tts', SpeechController::class . '@tts');
    $router->get('/api/user/speech/logs', SpeechController::class . '@logs');

    // 世界书（关键词/正则激活 + 上下文注入）
    $router->get('/api/user/worldbook', WorldBookController::class . '@index');
    $router->post('/api/user/worldbook', WorldBookController::class . '@store');
    $router->post('/api/user/worldbook/test', WorldBookController::class . '@test');
    $router->post('/api/user/worldbook/import', WorldBookController::class . '@import');
    $router->delete('/api/user/worldbook', WorldBookController::class . '@clear');
    $router->put('/api/user/worldbook/{id}', WorldBookController::class . '@update');
    $router->delete('/api/user/worldbook/{id}', WorldBookController::class . '@destroy');

    // 内置工具包（网关侧执行，供 Agent 调用）
    $router->get('/api/user/tools', ToolsController::class . '@index');
    $router->post('/api/user/tools/invoke', ToolsController::class . '@invoke');

    // 计划模式（计划生成 / 确认 / 查询）
    $router->get('/api/user/plan', PlanController::class . '@show');
    $router->post('/api/user/plan', PlanController::class . '@save');
    $router->post('/api/user/plan/generate', PlanController::class . '@generate');
    $router->post('/api/user/plan/confirm', PlanController::class . '@confirm');
    $router->delete('/api/user/plan', PlanController::class . '@destroy');

    // 深度研究（多轮规划 + 分层执行 + 汇总）
    $router->post('/api/user/research', ResearchController::class . '@start');
    $router->get('/api/user/research/{taskId}', ResearchController::class . '@status');

    // 对话增强设置（楼层裁剪 / 思考引导 / 世界书开关；按会话隔离）
    $router->get('/api/user/chat-settings', ChatSettingController::class . '@show');
    $router->post('/api/user/chat-settings', ChatSettingController::class . '@save');

    // ── 插件（Operit ToolPkg 生态；manifest 热插拔）──────────────────
    // 注意：字面量路径必须先注册，否则会被 /{id} 的通配吃掉
    $router->get('/api/user/plugins', PluginController::class . '@index');
    $router->post('/api/user/plugins', PluginController::class . '@store');
    $router->post('/api/user/plugins/validate', PluginController::class . '@validate');
    $router->get('/api/user/plugins/capabilities', PluginController::class . '@capabilities');
    $router->get('/api/user/plugins/{id}', PluginController::class . '@show');
    $router->put('/api/user/plugins/{id}', PluginController::class . '@update');
    $router->delete('/api/user/plugins/{id}', PluginController::class . '@destroy');
    $router->post('/api/user/plugins/{id}/toggle', PluginController::class . '@toggle');

    // ── 环境配置（Operit SoftwareSettings 生态）──────────────────────
    $router->get('/api/user/env/variables', EnvironmentController::class . '@index');
    $router->post('/api/user/env/variables', EnvironmentController::class . '@store');
    $router->delete('/api/user/env/variables', EnvironmentController::class . '@clear');
    $router->put('/api/user/env/variables/{id}', EnvironmentController::class . '@update');
    $router->delete('/api/user/env/variables/{id}', EnvironmentController::class . '@destroy');
    $router->get('/api/user/env/packages', EnvironmentController::class . '@packages');
    $router->get('/api/user/env/info', EnvironmentController::class . '@info');

    // ── 模型档案（Operit ModelConfig 生态；用户自建端点）──────────────
    $router->get('/api/user/model-profiles', ModelProfileController::class . '@index');
    $router->post('/api/user/model-profiles', ModelProfileController::class . '@store');
    $router->put('/api/user/model-profiles/{id}', ModelProfileController::class . '@update');
    $router->delete('/api/user/model-profiles/{id}', ModelProfileController::class . '@destroy');
    $router->post('/api/user/model-profiles/{id}/test', ModelProfileController::class . '@test');
    $router->post('/api/user/model-profiles/{id}/default', ModelProfileController::class . '@setDefault');

    // ── 角色卡（Operit CharacterCard 生态；SillyTavern 兼容导入）──────
    $router->get('/api/user/character-cards', CharacterCardController::class . '@index');
    $router->post('/api/user/character-cards', CharacterCardController::class . '@store');
    $router->post('/api/user/character-cards/import', CharacterCardController::class . '@import');
    $router->get('/api/user/character-cards/{id}', CharacterCardController::class . '@show');
    $router->put('/api/user/character-cards/{id}', CharacterCardController::class . '@update');
    $router->delete('/api/user/character-cards/{id}', CharacterCardController::class . '@destroy');
    $router->get('/api/user/character-cards/{id}/export', CharacterCardController::class . '@export');
    $router->post('/api/user/character-cards/{id}/activate', CharacterCardController::class . '@activate');

    // ── 子代理（参考 Operit 被动式 Subagent） ──────────────────────
    // 字面量路径必须先于 {runId} 注册
    $router->post('/api/user/subagent/run', SubagentController::class . '@run');
    $router->get('/api/user/subagent/runs', SubagentController::class . '@index');
    $router->get('/api/user/subagent/runs/{runId}', SubagentController::class . '@show');
});
