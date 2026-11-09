# API 参考

> **文档导航**：[总目录](README.md) · [安装](installation.md) · [部署](deployment.md) · [配置](configuration.md) · [架构](architecture.md) · [API](api.md) · [供应商](providers.md) · [开发](development.md)

所有接口返回 JSON（流式接口为 `text/event-stream`）。编码统一 UTF-8。
认证方式与环境准备见 [安装指南 § 11](installation.md#11-首次登录与初始化检查清单)；
供应商能力差异见 [供应商适配](providers.md)。

**本文导读**：[认证](#1-认证) · [OpenAI 兼容接口](#2-openai-兼容接口) · [公共接口](#3-公共接口无需登录) · [用户端接口](#4-用户端接口需登录) · [管理后台接口](#5-管理后台接口需管理员登录) · [状态码](#6-状态码约定)

## 1. 认证

| 场景 | 方式 |
| --- | --- |
| OpenAI 兼容接口 `/v1/*` | `Authorization: Bearer <API Key>` |
| 用户控制台 `/api/user/*` | 登录会话 Cookie（`aigw_session`） |
| 管理后台 `/api/admin/*` | 登录会话 Cookie（`aigw_session`） |

网关 API Key 分两类：

- `sk-aigw-*`：管理端生成的全局密钥，可访问全部账号
- `sk-user-*`：用户独立密钥，自动锁定租户作用域，只能访问自己的账号与数据

错误体为 OpenAI 风格：

```json
{
  "error": {
    "message": "用户 API Key 无效或账号已被禁用。",
    "type": "invalid_request_error",
    "code": "invalid_api_key"
  }
}
```

## 2. OpenAI 兼容接口

### 2.1 健康检查

```http
GET /v1/health
```

无需鉴权，可用于探活与部署自检。

### 2.2 模型列表

```http
GET /v1/models
Authorization: Bearer sk-aigw-xxx
```

返回 OpenAI `list` 结构，仅包含当前作用域可见且可用的模型。

### 2.3 对话补全

```http
POST /v1/chat/completions
Authorization: Bearer sk-aigw-xxx
Content-Type: application/json
```

请求体：

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `model` | string | 是 | 模型标识 |
| `messages` | array | 是 | OpenAI 消息数组，支持 system/user/assistant/tool 与多模态内容块 |
| `stream` | bool | 否 | 是否流式，默认 `false` |
| `temperature` | number | 否 | 采样温度 |
| `top_p` | number | 否 | 核采样 |
| `max_tokens` | int | 否 | 最大输出 token |
| `tools` | array | 否 | 函数工具定义（会自动归一化 JSON Schema） |
| `custom` | string | 否 | 指定账号 UID（`custom_xxx` 自定义供应商场景） |
| `worldbook_ids` | int[] | 否 | 本次请求启用的世界书 ID 白名单 |
| `thinking` | bool | 否 | 部分供应商的深度思考开关（经 `extra` 透传） |
| `reasoning_effort` | string | 否 | 部分供应商的思考强度（`low` / `medium` / `high` / `none`） |
| 其他 | any | 否 | 未识别字段进入 `extra` 原样透传 |

示例：

```bash
curl -N http://your-domain/v1/chat/completions \
  -H "Authorization: Bearer sk-aigw-xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-4o-mini",
    "stream": true,
    "messages": [{"role": "user", "content": "你好"}]
  }'
```

流式响应为 `data: {...}` 帧，`object` 为 `chat.completion.chunk`；非流式为 `chat.completion`，包含 `choices`、`usage`（prompt/completion/total/cache）与 `finish_reason`。

### 2.4 Responses API

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | `/v1/responses` | 创建响应，支持 `stream`、`background`、function tools |
| GET | `/v1/responses/{responseId}` | 检索已存储的响应 |
| DELETE | `/v1/responses/{responseId}` | 删除响应 |
| POST | `/v1/responses/{responseId}/cancel` | 取消后台执行中的响应 |

请求体主要字段：

| 字段 | 说明 |
| --- | --- |
| `model` | 模型标识 |
| `input` | 字符串或消息数组 |
| `instructions` | 系统指令 |
| `temperature` / `top_p` | 采样参数 |
| `max_output_tokens` | 最大输出（正整数） |
| `tools` | function 工具数组 |
| `tool_choice` | 工具选择策略 |
| `parallel_tool_calls` | 是否并行工具调用（布尔） |
| `stream` | 是否流式（布尔） |
| `background` | 是否后台执行（布尔） |
| `metadata` | JSON 对象 |

流式事件序列：`response.created` → `response.output_text.delta` / `response.function_call_arguments.delta` → `response.function_call_arguments.done` → `response.completed`，每帧带递增 `sequence_number`，终态后发送 `data: [DONE]`。

工具续轮：客户端把上一轮返回的 `function_call` 与 `function_call_output` 回传，服务端会转换为 assistant `tool_calls` 与 `tool` 消息，并校验 `call_id` 匹配。

图片输入：`input_image` 会映射为 Chat 的 `image_url` 内容块；对协议层不支持图片的上游（Antigravity / Doubao / ZCode）返回 `400 unsupported_image_input`，而不是静默丢弃。

## 3. 公共接口（无需登录）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/site-config` | 站点名称、注册开关、自定义登录入口等 |
| GET | `/api/captcha` | 图形验证码 |
| GET | `/api/client-ip` | 诊断真实客户端 IP（仅回显请求者自身） |
| GET | `/api/user/register-config` | 注册配置（是否开放、是否需验证码） |
| POST | `/api/user/email-code` | 发送邮箱验证码（注册/找回/改密） |
| POST | `/api/user/register` | 注册 |
| POST | `/api/user/login` | 登录 |
| POST | `/api/user/reset-password` | 邮箱验证码重置密码 |
| GET | `/authorize`、`/oauth-callback` | 上游 OAuth / WebView 回调落地 |

## 4. 用户端接口（需登录）

会话相关：

| 方法 | 路径 |
| --- | --- |
| POST | `/api/user/logout` |
| GET | `/api/user/me` |
| POST | `/api/user/profile` |
| POST | `/api/user/password` |
| POST | `/api/user/api-key/regenerate` |

App 专用（复用网关能力，按当前登录用户隔离账号池）：

| 方法 | 路径 |
| --- | --- |
| GET | `/api/app/models` |
| POST | `/api/app/chat/completions` |

功能模块（均为 `/api/user/` 前缀）：

| 模块 | 主要接口 |
| --- | --- |
| 总览 | `GET /dashboard`、`GET /account-pool/overview` |
| 供应商 | `GET /providers`、`POST /providers/custom`、`PUT/DELETE /providers/{key}`、`POST /providers/{key}/test` |
| 模型 | `GET/POST /models`、`POST /models/sync`、`PUT/DELETE /models/{id}` |
| 账号 | `GET/POST /accounts`、`POST /accounts/refresh-all`、`checkin-all`、`travel-all`、`activity-all`、`POST /accounts/{id}/action`、`POST /accounts/{id}/cooldown/clear` |
| 登录适配 | `POST /auth/{provider}/start`、`poll`、`callback` |
| 日志审计 | `/logs`、`/logs/detail`、`/logs/stats`、`/logs/export`、`/audit`、`/audit/timeline` |
| MCP | `GET/POST /mcp`、`POST /mcp/test`、`GET /mcp/tools`、`POST /mcp/invoke`、`POST /mcp/{id}/refresh` |
| 记忆 | `GET/POST /memory`、`POST /memory/recall`、`POST /memory/clear` |
| 工作流 | `GET/POST /workflows`、`POST /workflows/validate`、`POST /workflows/{id}/run`、`GET /workflows/{id}/tasks` |
| 语音 | `GET /speech/capabilities`、`POST /speech/stt`、`POST /speech/tts`、`GET /speech/logs` |
| 世界书 | `GET/POST /worldbook`、`POST /worldbook/test`、`POST /worldbook/import` |
| 工具 | `GET /tools`、`POST /tools/invoke` |
| 计划 | `GET/POST /plan`、`POST /plan/generate`、`POST /plan/confirm` |
| 深度研究 | `POST /research`、`GET /research/{taskId}` |
| 会话设置 | `GET/POST /chat-settings` |
| 插件 | `GET/POST /plugins`、`POST /plugins/validate`、`GET /plugins/capabilities`、`POST /plugins/{id}/toggle` |
| 运行环境 | `/env/variables`、`/env/packages`、`/env/info` |
| 模型档案 | `GET/POST /model-profiles`、`POST /model-profiles/{id}/test`、`default` |
| 角色卡 | `GET/POST /character-cards`、`POST /character-cards/import`、`GET /character-cards/{id}/export`、`POST /character-cards/{id}/activate` |
| 子代理 | `POST /subagent/run`、`GET /subagent/runs`、`GET /subagent/runs/{runId}` |
| 提示词模板 | `GET/POST /prompt-templates`、`PUT/DELETE /prompt-templates/{id}` |
| 思维注入 | `GET/POST /personas`、`POST /personas/platform`、`enable`、`selfcheck` |

## 5. 管理后台接口（需管理员登录）

登录入口默认 `POST /api/admin/login`，可用 `AIGW_ADMIN_LOGIN_PATH` 改为不易猜到的路径。

| 模块 | 主要接口 |
| --- | --- |
| 会话 | `POST /logout`、`GET /me`、`POST /profile`、`POST /password` |
| 仪表盘 | `GET /dashboard` |
| 网关设置 | `GET/POST /settings`、`POST /settings/regenerate-key` |
| 邮件 | `GET /mail/config`、`POST /mail/test`、`POST /mail/test-connect` |
| 代理 | `GET/POST /proxy` |
| 提示词模板（全局） | `GET/POST /prompt-templates`、`PUT/DELETE /prompt-templates/{id}` |
| 思维注入（全局） | `GET/POST /personas`、`status`、`seed`、`preview`、`selfcheck`、`{id}/default`、`{id}/target`、`{id}/revisions`、`{id}/restore` |
| 供应商 | `GET /providers`、`POST /providers/custom`、`PUT/DELETE /providers/{key}`、`POST /providers/{key}/test` |
| 账号池 | `GET/POST /accounts`、`refresh-all`、`checkin-all`、`travel-all`、`activity-all`、`tasks-all`、`credits/monitor`、`{id}/action`、`{id}/models`、`{id}/cooldown/clear` |
| 账号登录 | `POST /auth/{provider}/start`、`poll`、`callback` |
| 模型 | `GET/POST /models`、`POST /models/sync`、`POST /models/toggle-provider`、`PUT/DELETE /models/{id}` |
| 定时任务 | `GET/POST /scheduler`、`POST /scheduler/run` |
| 日志 | `/logs`、`/logs/detail`、`/logs/stats`、`/logs/export`、`/logs/prune`、`/logs/clear` |
| 审计 | `/audit`、`/audit/stats`、`/audit/options`、`/audit/timeline`、`/audit/export`、`/audit/prune` |
| 用户管理 | `GET /users`、`GET/PUT/DELETE /users/{id}`、`POST /users/{id}/toggle`、`POST /users/{id}/regenerate-key` |
| 屏幕/监控 | `GET /screens` |

## 6. 状态码约定

| 状态码 | 含义 |
| --- | --- |
| 200 | 成功 |
| 204 | CORS 预检成功 |
| 400 | 参数校验失败 / 不支持的输入 |
| 401 | 未登录或密钥无效 |
| 403 | 无权限 / 回源令牌校验失败 |
| 404 | 资源或路径不存在 |
| 405 | 方法不允许 |
| 409 | 状态冲突（如任务已在执行） |
| 429 | 触发限流 |
| 500 | 服务端异常（非 debug 时返回通用文案） |
