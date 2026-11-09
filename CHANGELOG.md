# 发布说明

> **文档导航**：[项目总览](README.md) · [安装](docs/installation.md) · [部署](docs/deployment.md) · [配置](docs/configuration.md) · [架构](docs/architecture.md) · [API](docs/api.md) · [供应商](docs/providers.md) · [开发](docs/development.md) · [隐私说明](PRIVACY.md) · [第三方声明](THIRD-PARTY-NOTICES.md) · [许可证](LICENSE)

本文件记录对外发布的功能、变更与已知限制。版本号与 `public/index.php` 中的 `AIGW_VERSION` 保持一致。

格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，版本号遵循 `主版本.次版本.修订号`。

---

## 1.1.0 — 2026-11-09

### 新增

- **Agent2API 供应商适配**
  - `app/Provider/Providers/Agent2ApiProvider.php`：把 Agent2API 网关作为上游接入，支持多实例（`agent2api_*` 前缀）
  - `app/Support/Agent2ApiClient.php`：模型面 `Authorization: Bearer` 与面板面 `x-panel-token` 双鉴权，面板令牌缓存 6600s，401 自动重登一次
  - `bin/agent2api-sync.php`：把网关模型目录同步成本地供应商与模型（`split` / `aggregate` 两种模式）
  - `bin/agent2api-account.php`：直接写入某个供应商的账号凭据（AES-256-GCM 加密）
  - 后台供应商页新增三个动作：网关签到、网关状态、插件目录
- **高级路由**
  - 模型别名：`model_aliases` 把请求里的模型名映射到实际模型（只展开一层，防环）
  - 跨供应商兜底：`fallback_chain` 在上游疑似故障时按链降级重试，最多 2 层；限流、额度、登录态失效、上游 5xx 才触发，参数错误不触发
- **公开状态页**
  - `GET /v1/status`（免鉴权，可开关）与 `/status` 页面：按供应商汇总可用账号、模型数、今日调用量、成功率、平均耗时
- **设置页**
  - 「高级路由」卡片：状态页开关、别名与兜底链编辑，保存前做 JSON 结构校验

### 修复

- 状态页的今日调用量/成功率/平均耗时恒为 0：统计接口返回键是 `by_provider`，取值处写成 `byProvider`，异常被 `catch` 吞掉
- WorkBuddy 上游渠道抽样校验（`11-128`）被归类为 `CLIENT`（不重试）：改为 `UPSTREAM`，命中后自动换号或走跨供应商兜底
- 新增账号弹窗：把「内置 OAuth 授权」提到第一位并默认展开，手工填 Token 收进可选的折叠区，自带「校验并保存 Token」按钮

---

## 1.0.0 — 2026-11-02

**首个公开发布版本（Initial public release）。**

### 新增

- **OpenAI 兼容网关**
  - `POST /v1/chat/completions`，支持 `stream=true` 的 SSE 逐块输出
  - `POST /v1/responses`，含 `retrieve` / `delete` / `cancel` 与后台执行
  - `GET /v1/models`、`GET /v1/health`
  - 工具调用（`tools` / `tool_choice`）与工具消息序列自动修复
  - `max_tokens` 出站归一化、JSON Schema 空对象修复、OpenAI 风格错误体
- **多供应商账号池**
  - 内置 13 个上游配置（Trae / trae_intl、WorkBuddy / codebuddy_intl、Antigravity、豆包、
    ZCode / zcode_intl、Qoder / qoder_intl、商汤小浣熊、Loomy、自定义）
  - 四类登录方式：`WEBVIEW_CALLBACK`、`OAUTH_LOOPBACK`、`DEVICE_CODE`、`SMS_CODE`、`API_KEY`
  - 账号粘性会话、冷却与额度巡检、模型目录与能力标签
  - 凭据使用 `AIGW_APP_KEY` 派生的 **AES-256-GCM** 加密后落库
- **管理后台**
  - 仪表盘、账号管理（登录 / 模型同步 / 额度 / 动作）、网关密钥、用户与租户
  - 提示词模板、世界书、Persona、MCP、工作流、调用日志、系统设置
- **用户控制台**
  - `sk-user-*` 用户密钥与租户隔离，调用统计、模型接入说明、个人资料
- **异步任务与调度**
  - `bin/action-worker.php`、`research-worker.php`、`responses-worker.php`
  - `bin/scheduler.php`（签到 / Token 保活 / 积分巡检，幂等）
  - `bin/workflow-worker.php`（用户工作流按图执行）
- **安全与运维**
  - SSRF 白名单（`UrlGuard`）、登录限流（`LoginGuard`）、验证码、审计日志
  - CDN 回源令牌与真实客户端 IP 还原（`ClientIp`）
  - 生产部署模板：Nginx / Apache / PHP-FPM / php.ini / crontab
- **文档**
  - 文档中心与 8 篇中文文档：安装、部署、配置、架构、API、供应商、开发、总览
  - 本发布说明、[隐私说明](PRIVACY.md)、[第三方声明](THIRD-PARTY-NOTICES.md)

### 变更

- 首次公开版本，无历史变更记录。

### 修复

- 公开仓库版本移除硬编码的供应商客户端凭据，Antigravity 的 Google OAuth
  Client ID / Secret 改为仅从环境变量读取
- 安装器不再内置默认管理员口令，改为环境变量注入或随机生成
- `schema.sql` 不再预置管理员账号与口令哈希

### 已知限制

- 仓库**不含** `tests/`、`tools/`、`mobile/`、`storage/` 运行数据与数据库导出
- 「验证码求解」依赖未包含的 `tools/captcha-solver.js`，公开版本默认不可用
- 无 Composer / npm 依赖，也没有单元测试框架；回归验证以手工链路为主
- 内网穿透、反向代理与 CDN 场景需要按 [部署指南](docs/deployment.md) 配置真实 IP 与回源令牌

---

## 版本对照

| 版本 | 日期 | 说明 |
| --- | --- | --- |
| 1.0.0 | 2026-11-02 | 首个公开发布版本（AGPL-3.0） |

## 相关文档

- [安装指南](docs/installation.md) —— 从零部署
- [部署指南](docs/deployment.md) —— 生产运维与升级回滚
- [开发指南](docs/development.md) —— 二次开发与提交规范
- [隐私说明](PRIVACY.md) · [第三方声明](THIRD-PARTY-NOTICES.md) · [许可证](LICENSE)
- [文档中心](docs/README.md)
