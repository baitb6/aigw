# AI 聚合网关（AIGW）· 后端

<p>
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/MySQL-8.0%2B-4479A1?logo=mysql&logoColor=white" alt="MySQL 8.0+">
  <img src="https://img.shields.io/badge/Composer-不需要-success" alt="无需 Composer">
  <img src="https://img.shields.io/badge/OpenAI-兼容接口-10a37f" alt="OpenAI 兼容">
  <img src="https://img.shields.io/badge/文档-8_篇-blue" alt="8 篇中文文档">
  <img src="https://img.shields.io/badge/License-AGPL--3.0-663399" alt="License AGPL-3.0">
</p>

把多个 AI 上游（Trae、WorkBuddy、Antigravity、豆包、ZCode、Qoder、商汤小浣熊、Loomy、自定义 OpenAI 兼容服务等）的账号池聚合成一个**OpenAI 兼容网关**，并附带管理后台与用户控制台。

- 纯 PHP 8.2 实现，**不依赖 Composer / 框架**，自带轻量 MVC、DI 容器与路由
- 对外暴露 `/v1/chat/completions`、`/v1/responses`（含生命周期端点）、`/v1/models`
- 多账号轮转、额度与冷却管理、签到/任务/兑换、Token 保活
- 思维注入（Persona）、世界书、助手记忆、MCP、插件、子代理、工作流、深度研究、语音转写等扩展能力
- 凭据以 AES-256-GCM 加密入库；用户 API Key 按租户隔离

> 本仓库为**后端源码 + 控制台前端静态资源**。不包含客户端 App、测试脚本、探针工具、数据库导出与任何真实账号口令。

## 功能特性

| 模块 | 说明 |
| --- | --- |
| OpenAI 兼容层 | `chat/completions`、`responses`、`models`、`health`；流式 SSE 与非流式均支持 |
| 账号池 | 多账号分组、模型级冷却、错误计数冷却、会话粘性、额度巡检 |
| 账号运营 | 自动签到、新手任务、兑换、旅行/活跃任务、Token 刷新（含后台异步 worker） |
| 供应商适配 | 内置 9 类上游适配器 + 自定义 OpenAI 兼容供应商，新增供应商零侵入 |
| Agent2API 接入 | 把 Agent2API 网关（含其插件）当作一个上游供应商接入，模型目录自动同步 |
| 思维注入 | 平台/用户级 Persona，按供应商与模型下发，支持预览、自检、版本回滚 |
| 上下文增强 | 世界书注入、助手记忆检索、指令拼接 |
| Agent 扩展 | MCP 服务器托管、插件、子代理团队、工作流调度、深度研究任务 |
| 控制台 | 用户端 `/console`、管理端 `/admin`，Vue 3 单页（免构建） |
| 安全 | 凭据加密、登录锁定、验证码、限流、SSRF 防护、CDN 回源令牌、审计日志 |

## 技术栈

- **运行时**：PHP 8.2（CLI + FPM）、MySQL 8.0
- **架构**：自研 PSR-4 自动加载、反射 DI 容器、洋葱模型中间件、`Controller@method` 路由
- **前端**：Vue 3 global build + 原生 JS 分模块，无打包步骤
- **可选**：Node ≥ 20.10 与 Chrome（仅「验证码求解」能力需要）
- **后台任务**：`proc_open` 拉起 CLI worker + cron 兜底，无常驻进程

## 目录结构

```text
app/
  Controllers/          控制器（Admin / User / 公共入口）
  Core/                 内核：Application、Router、Container、Config、Database
  Core/Http/            Request / Response
  Exceptions/          业务异常
  Middleware/           鉴权、CORS、限流
  Provider/             上游适配器契约、DTO、各供应商实现
  Repositories/         数据访问层（SQL）
  Services/             业务服务（网关、账号池、Persona、MCP、工作流等）
  Support/              支撑组件（HTTP 客户端、加密、验证码、邮件、日志、Schema 等）
bin/                    CLI 入口：调度器与后台 worker
bootstrap/              自动加载与全局辅助函数
config/                 应用 / 数据库 / 网关配置
database/               schema.sql 与安装脚本 install.php
deploy/                 nginx、php-fpm、php.ini、crontab 参考配置
docs/                   架构、API、配置、部署、开发文档
error/                  错误页模板
public/                 唯一 Web 入口 index.php、控制台 SPA、静态资源
routes/                 路由文件（启动时自动加载）
storage/                运行时目录（会话、日志、临时文件）
```

## 快速开始

> 完整步骤（目录权限、PHP-FPM、计划任务、本地开发环境、排障）见 [安装指南](docs/installation.md)；
> 生产运维（CDN 回源、备份升级、安全加固）见 [部署指南](docs/deployment.md)。

### 1. 环境要求

- PHP **8.2+**，扩展：`pdo_mysql`、`openssl`、`curl`、`mbstring`、`json`、`session`、`gd`（验证码图片）
- MySQL **8.0+**
- Web 服务器：Nginx（推荐，参考 [deploy/nginx-aigw.conf](deploy/nginx-aigw.conf)）或 Apache（根目录已有 `.htaccess`）
- 可选：`proc_open` 未被禁用（异步任务、验证码求解需要）

### 2. 初始化项目

```bash
# 1) 拉取代码
git clone https://github.com/ikooc/aigw-backend.git
cd aigw-backend

# 2) 复制配置模板并填写数据库等信息
cp .env.example .env
#    必须修改：AIGW_APP_URL / AIGW_DB_* / AIGW_APP_KEY
#    生成主密钥：php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"

# 3) 建库建表并初始化管理员
#    管理员口令优先读 AIGW_INSTALL_ADMIN_PASSWORD，未设置则随机生成并打印
AIGW_INSTALL_ADMIN_PASSWORD='你的强口令' php database/install.php
```

安装脚本会：

1. 按 `.env` 的数据库配置建库、执行 `database/schema.sql` 建表
2. 初始化管理员账号（默认用户名 `admin`）
3. 生成网关全局 API Key（`sk-aigw-...`）
4. 若 `.env` 不存在则基于 `.env.example` 生成，并写入随机主密钥

> `--force` 会 **DROP 重建全部表**；库中已有业务数据时必须额外设置 `AIGW_ALLOW_DESTRUCTIVE=1` 才会执行。

### 3. 配置 Web 服务器

- **Nginx**：整站替换为 [deploy/nginx-aigw.conf](deploy/nginx-aigw.conf)，站点目录指向**项目根目录**（不是 `public/`），入口由 rewrite 转到 `public/index.php`
- **Apache**：站点根指向项目根，仓库根目录的 `.htaccess` 已包含等价重写规则
- 确保 `storage/` 可写（`chmod -R 775 storage && chown -R www:www storage`）

### 4. 访问

| 入口 | 地址 |
| --- | --- |
| 用户控制台 | `/console` |
| 管理后台 | `/admin`，登录接口默认 `/api/admin/login`（可用 `AIGW_ADMIN_LOGIN_PATH` 隐藏） |
| 网关健康检查 | `GET /v1/health` |
| OpenAI 兼容接口 | `POST /v1/chat/completions`、`POST /v1/responses` |

```bash
curl -s http://your-domain/v1/health
curl -s http://your-domain/v1/models -H "Authorization: Bearer sk-aigw-xxxx"
```

## 接入 Agent2API 网关

本项目内置 `agent2api` 适配器：把 [Agent2API](https://github.com/aimod-cc/Agent2API) 网关当成一个上游供应商，
它自己的账号池、插件（提供商增强 / 网页提供商）产出的模型，都会以 `供应商/模型` 的形式出现在本网关的模型目录里。

```bash
# 1) 同步模型目录（split 模式：网关里每个上游供应商生成一个本网关供应商）
php bin/agent2api-sync.php \
  --base=http://127.0.0.1:3065 --key=sk-a2a-xxx \
  --panel-user=admin --panel-pass='面板密码' --mode=split

# 2) 给每个生成的供应商写一条账号（否则会报「当前没有可用账号」）
php bin/agent2api-account.php --provider=agent2api_autoclaw \
  --key=sk-a2a-xxx --base=http://127.0.0.1:3065 \
  --panel-user=admin --panel-pass='面板密码'
```

- 鉴权分两套：模型调用走 `Authorization: Bearer sk-a2a-*`，管理动作走面板令牌 `x-panel-token`（自动登录取 token，401 自动重试一次）
- 后台「供应商」页对 `agent2api*` 提供三个动作：网关签到、网关状态、插件目录
- 详细说明与线上部署记录见 [docs/agent2api.md](docs/agent2api.md)

## 文档

完整文档见 **[文档中心](docs/README.md)**（含三条阅读路径与文档地图），每一篇顶部都有统一导航。

| 文档 | 内容 |
| --- | --- |
| [安装指南](docs/installation.md) | 环境检查、建库、`.env`、安装脚本、Web 服务器、首次登录、本地开发 |
| [部署指南](docs/deployment.md) | Nginx / Apache / PHP-FPM、计划任务、CDN 回源令牌、备份升级、排障、安全加固 |
| [配置参考](docs/configuration.md) | 全部环境变量与默认值 |
| [架构说明](docs/architecture.md) | 请求生命周期、分层职责、数据模型、关键链路 |
| [API 参考](docs/api.md) | OpenAI 兼容接口、用户端与管理端接口、错误格式 |
| [供应商适配](docs/providers.md) | 13 个上游、登录方式、能力矩阵、新增供应商 |
| [Agent2API 接入](docs/agent2api.md) | 网关接入、模型同步、账号写入、排障与部署记录 |
| [开发指南](docs/development.md) | 代码约定、新增接口模板、调试、数据库变更、提交自检 |

> 第一次部署请从 [安装指南](docs/installation.md) 开始；只想调用接口请直接看 [API 参考](docs/api.md)。

## 反馈与交流

遇到问题、想提建议，或希望跟踪版本更新，欢迎加入反馈群。

| 方式 | 信息 |
| --- | --- |
| 群名称 | **平台问题故而隐藏** |
| QQ 群号 | **** |
| 加群 | 手机 QQ 扫码，或在 QQ 里直接搜索群号 |

<p align="center">
  <img src="docs/assets/feedback-group.jpg" width="260" alt="反馈群二维码（QQ 群 ）">
</p>

> 提 issue 前建议先看 [文档中心](docs/README.md) 与 [部署指南的故障排查](docs/deployment.md#10-故障排查)，
> 提问时附上 `storage/logs/php-error.log` 的相关片段会更快定位。

## 许可证与合规

| 文档 | 内容 |
| --- | --- |
| [发布说明](CHANGELOG.md) | 版本、功能、变更与已知限制 |
| [隐私说明](PRIVACY.md) | 实例会处理哪些数据、保存多久、提供给谁 |
| [第三方声明](THIRD-PARTY-NOTICES.md) | 第三方组件、商标素材与许可范围 |
| [许可证全文](LICENSE) | GNU AGPL-3.0 英文原文 |

### 许可证范围

本项目以 **GNU Affero General Public License v3.0（AGPL-3.0）** 授权：

- **覆盖**：`app/`、`bootstrap/`、`config/`、`routes/`、`bin/`、`database/`、`public/`（除 `assets/vendor/`）、`deploy/`、`docs/` 与根目录文档，均为原创代码，按 AGPL-3.0 授权。
- **不覆盖**：
  - `public/assets/vendor/vue.global.prod.js` —— Vue 3，**MIT** 许可（版权归 Vue 作者）
  - `public/assets/img/providers/*` —— 供应商名称与 Logo 属**商标素材**，归各权利方所有，仅用于标识对应服务，不在 AGPL 授权范围内
  - PHP 扩展、Nginx、MySQL 等系统组件 —— 未随仓库分发，适用各自许可
- **网络使用（§13）**：修改后通过公网向用户提供交互式服务，必须向这些用户提供对应源码的获取方式。
- 不附加 AGPL-3.0 之外的额外限制；不授予任何商标使用权。

> 商业使用本身不被 AGPL 禁止，但必须履行源码开放义务。若无法接受，请勿使用本软件。
> 详见 [第三方声明 § 1](THIRD-PARTY-NOTICES.md#1-许可证范围)。

## 安全说明

- `.env`、`storage/` 运行时数据、数据库导出文件均不会入库（见 `.gitignore`）
- 供应商凭据使用 `AIGW_APP_KEY` 派生密钥进行 AES-256-GCM 加密；**主密钥一旦更换，历史凭据全部无法解密**
- 用户 API Key（`sk-user-*`）会锁定租户作用域，只能访问自己的账号与数据
- 生产环境请务必设置 `AIGW_APP_DEBUG=false`

## 免责声明

本项目仅用于学习与自建网关的技术研究。使用各上游服务时请遵守对应服务条款，由此产生的账号风险与合规责任由使用者自行承担。
