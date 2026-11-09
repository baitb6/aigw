# 架构说明

> **文档导航**：[总目录](README.md) · [安装](installation.md) · [部署](deployment.md) · [配置](configuration.md) · [架构](architecture.md) · [API](api.md) · [供应商](providers.md) · [开发](development.md)

理解本项目的三条主线：**单入口请求链路**、**Controller → Service → Repository 分层**、
**Provider 适配上游协议**。读完后建议继续看 [开发指南](development.md) 动手新增接口。

**本文导读**：[总览](#1-总览) · [请求生命周期](#2-请求生命周期) · [分层职责](#3-分层职责) · [供应商适配层](#4-供应商适配层) · [数据模型](#5-数据模型) · [关键链路](#6-关键链路) · [控制台前端](#7-控制台前端) · [安全边界](#8-安全边界)

---

## 1. 总览

```text
浏览器 / App / OpenAI SDK
          │
          ▼
┌──────────────────────────────────────────────────────────┐
│ public/index.php        （唯一 Web 入口）                 │
│   ├─ 固定 session 存储到 storage/sessions                │
│   ├─ bootstrap/autoload.php（PSR-4 + 全局 helper）        │
│   └─ new App\Core\Application(AIGW_ROOT)->run()          │
└──────────────────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────────────────┐
│ Core\Application                                         │
│   ├─ Config::load()：读取 .env → $_ENV，加载 config/*.php │
│   ├─ Container：反射自动装配的单例容器                    │
│   ├─ Router：加载 routes/*.php，按 method+path 匹配       │
│   └─ dispatch：中间件洋葱 → Controller@method             │
└──────────────────────────────────────────────────────────┘
          │
   ┌──────┴─────────┬───────────────┬──────────────┐
   ▼                ▼               ▼              ▼
Middleware       Controller      Service       Repository
(CORS/鉴权/限流)  (Admin/User)    (业务编排)     (SQL 访问)
                                     │
                                     ▼
                                 Provider 适配器
                                     │
                                     ▼
                                  上游 HTTP API
```

## 2. 请求生命周期

1. `public/index.php` 定义常量并固定 session 目录，加载自动加载器与全局函数。
2. `Application` 构造函数加载 `.env` 与 `config/` 下的配置数组。
3. `run()` 中 `registerRoutes()` 遍历 `routes/*.php` 并 `require`，每个文件通过闭包里的 `$router` 注册路由。
4. `Router::dispatch()` 用正则匹配 `{param}` 占位，收集路由参数，执行中间件管道。
5. 中间件按注册顺序形成「洋葱」：先是全局分组中间件，再是路由级中间件，最后是控制器方法。
6. `Container::call()` 依据控制器方法的类型提示自动注入依赖，并把 `Request` 实例优先复用（保证路由参数不丢失）。
7. 控制器返回 `Response`，由 `Application` 统一 `send`。

异常兜底：`Application::renderException()` 在 `AIGW_APP_DEBUG=false` 时只返回通用文案，避免泄漏路径与 SQL 细节。

### 路由分组

| 文件 | 前缀 | 说明 |
| --- | --- | --- |
| `routes/gateway.php` | `/v1`、`/authorize`、`/oauth-callback` | 对外 OpenAI 兼容接口、OAuth 回调 |
| `routes/user.php` | `/api/user/*`、`/api/app/*`、`/api/site-config` 等 | 用户控制台与 App 接口 |
| `routes/admin.php` | `/api/admin/*` | 管理后台接口 |

非接口路径的 GET 未命中时会回吐 SPA 外壳（`/admin*` → `admin.html`，其余 → `index.html`），支持前端 hash 路由刷新。

## 3. 分层职责

| 层 | 目录 | 职责 |
| --- | --- | --- |
| 内核 | `app/Core` | 容器、路由、配置、数据库连接、请求/响应对象 |
| 中间件 | `app/Middleware` | CORS、管理端会话鉴权、用户会话鉴权、网关 Bearer 鉴权、限流 |
| 控制器 | `app/Controllers` | 参数校验、调用服务、组织响应；`Admin/*` 与 `User/*` 按端划分 |
| 服务 | `app/Services` | 业务编排：网关转发、账号池、上下文增强、MCP、工作流、子代理、语音等 |
| 仓储 | `app/Repositories` | 纯 SQL 数据访问，统一使用 `aigw_` 表前缀 |
| 适配器 | `app/Provider` | 上游协议适配，实现 `Provider` 契约 |
| 支撑 | `app/Support` | HTTP 客户端、加密、验证码、邮件、日志、JSON Schema、SSRF 守卫等 |

### 核心服务一览

| 服务 | 职责 |
| --- | --- |
| `GatewayService` | 对话主流程：选账号、注入上下文、转发上游、SSE 输出、用量与日志 |
| `AccountPool` | 账号选择、分组、状态、额度与冷却判定 |
| `ContextEnhancerService` | 世界书 + 记忆 + 思维注入的统一增强入口 |
| `PersonaInjector` | Persona 文本拼装、供应商/模型目标匹配 |
| `ResponsesService` | `/v1/responses` 生命周期：存储、后台执行、检索、取消 |
| `McpService` | 用户 MCP 服务器配置与工具桥接 |
| `WorkflowService` / `ResearchService` / `SubagentService` | 工作流、深度研究、子代理团队编排 |
| `Scheduler` | 定时任务：自动签到、Token 保活、积分巡检等 |
| `RateLimiter` | 基于数据库的按维度限流 |
| `ModelCatalogSync` | 拉取上游模型目录并落库（倍率、上下文窗口、能力标签） |

## 4. 供应商适配层

`App\Provider\Provider` 是统一契约，核心方法：

- `id()` / `displayName()` / `loginType()` / `hosts()`
- `supportsImageInput()`：协议层是否保留 `image_url` 内容块
- `chat()`：流式对话，通过 `onDelta` 回调推送增量
- `models()` / `modelCatalog()`：模型列表与详细目录
- `credits()` / `action()` / `refreshToken()`
- `startLogin()` / `pollLogin()` / `handleCallback()` / `ingestCredential()`
- `parseError()`

`ProviderManager` 负责 key → 适配器实例映射。同一适配器类可服务多个区域 key（如 `trae` / `trae_intl`），为避免容器单例共享导致区域串味，管理器对每个 key **克隆独立实例**并注入实际 key。

详见 [providers.md](providers.md)。

## 5. 数据模型

`database/schema.sql` 共 29 张表，按用途分组：

| 分组 | 表 |
| --- | --- |
| 身份与会话 | `aigw_admin`、`aigw_user`、`aigw_login_lockout`、`aigw_session_affinity` |
| 网关与配额 | `aigw_gateway_settings`、`aigw_rate_limit`、`aigw_proxy_settings`、`aigw_response` |
| 供应商与账号 | `aigw_provider`、`aigw_account`、`aigw_model`、`aigw_account_model_cooldown` |
| 运营日志 | `aigw_call_log`、`aigw_checkin_log`、`aigw_travel_log`、`aigw_activity_log`、`aigw_audit_log` |
| Persona | `aigw_persona`、`aigw_persona_revision`、`aigw_persona_target`、`aigw_persona_user_target`、`aigw_persona_user_enable` |
| 扩展能力 | `aigw_prompt_template`、`aigw_mcp_server`、`aigw_memory`、`aigw_workflow`、`aigw_workflow_task`、`aigw_action_task`、`aigw_speech_log` |

所有表带 `aigw_` 前缀（可在 `config/database.php` 调整）。用户私有数据（账号、思维、模板、MCP、记忆、工作流等）通过 `App\Support\Tenant` 的请求作用域做归属隔离。

## 6. 关键链路

### 6.1 对话请求（流式）

1. `GatewayAuthMiddleware` 校验 Bearer：
   - `sk-user-*` → 解析用户并 `Tenant::setRequestScope(uid)`
   - 全局 `sk-aigw-*` / 免 Key 模式 → 不限制作用域
2. `RateLimitMiddleware` 按维度限流。
3. `GatewayService` 解析请求体为 `ChatRequest`（未知字段进入 `extra`，`custom` 指定账号，`worldbook_ids` 指定世界书白名单）。
4. `ContextEnhancerService` 按助手配置注入世界书、记忆与 Persona。
5. `AccountPool` 选择可用账号：过滤禁用/冷却/额度耗尽，按分组与权重轮转。
6. 调用对应 `Provider::chat()`，增量通过 `onDelta` 回调转成 OpenAI `chat.completion.chunk` SSE 帧。
7. 结束后写 `aigw_call_log`，回填 usage（prompt/completion/total/cache）与 finish_reason。

### 6.2 工具调用

工具 Schema 在 `ChatRequest::fromArray()` 中经 `JsonSchema::normalizeTools()` 归一化（防止 JSON 空对象被解码为数组导致上游拒单）。`Support\ToolCalls` 与 `ToolMessageSequence` 负责多轮工具消息的顺序与合并，避免上游因 `tool` 消息位置不合法而报错。

### 6.3 Responses API

`/v1/responses` 由 `Provider\Dto\ResponsesRequestAdapter` 把 Responses 请求（`input`、`instructions`、`max_output_tokens`、function tools 等）转换为内部 `ChatRequest`，再由 `ResponsesService` 负责：

- 流式：输出 `response.created`、`response.output_text.delta`、`response.function_call_arguments.delta/done`、`response.completed` 等事件，`sequence_number` 递增，终态后 `data: [DONE]`
- 非流式：汇总正文与工具调用后一次性返回
- `background: true`：落 `queued` 记录后立即返回，由 `bin/responses-worker.php` 执行，`GET /v1/responses/{id}` 轮询

### 6.4 异步任务

耗时动作（ZCode 领额度、深度研究、后台 Responses）不放在 HTTP 生命周期里：

- Web 接口写入任务表 → `Support\BackgroundProcess` 用 `proc_open` 脱离父进程启动 `bin/<worker>.php <id>`
- cron 周期性执行 `--due` 兜底扫描，防止进程启动失败导致任务卡死
- `proc_open` 被禁用时会降级为同步路径并给出明确提示

### 6.5 定时调度

`bin/scheduler.php` 为幂等脚本，按 `scheduler_last_*` 水位判断是否已执行；`bin/workflow-worker.php` 扫描到期工作流并按拓扑序执行。

## 7. 控制台前端

`public/` 下为免构建的 Vue 3 单页：

| 文件 | 说明 |
| --- | --- |
| `index.html` / `admin.html` | 用户端 / 管理端外壳 |
| `assets/js/api.js` | 统一请求封装与错误处理 |
| `assets/js/store*.js` | 状态管理 |
| `assets/js/pages*.js` | 各功能页 |
| `assets/js/components.js` / `icons.js` / `logos.js` | 通用组件与图标 |
| `assets/css/app.css` | 主题与布局（明暗双主题） |
| `assets/vendor/vue.global.prod.js` | Vue 3 运行时 |

前端通过 `/api/site-config` 获取站点配置与自定义登录入口路径。

## 8. 安全边界

- `Support\UrlGuard`：拦截自定义供应商 `base_url` 的 SSRF（默认禁止私网，需显式开启 `AIGW_ALLOW_PRIVATE_UPSTREAM`）
- `Support\ClientIp`：只在可信代理或回源令牌校验通过时才采信客户端 IP 头
- `Support\LoginGuard`：登录失败锁定（管理员 / 用户 / 找回密码分维度）
- `Support\Captcha` / `CaptchaGuard`：图形验证码与限流
- `Support\Audit`：管理操作审计
- `Crypto`：凭据 AES-256-GCM；`Mask` 系列辅助函数统一脱敏输出
