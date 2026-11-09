# 供应商适配

> **文档导航**：[总目录](README.md) · [安装](installation.md) · [部署](deployment.md) · [配置](configuration.md) · [架构](architecture.md) · [API](api.md) · [供应商](providers.md) · [开发](development.md)

新增适配器的代码骨架与分层约定见 [开发指南 § 6](development.md#6-新增供应商适配器)；
登录相关接口的出入参见 [API 参考](api.md)。

## 1. 内置供应商

| Key | 展示名 | 登录方式 | 图片输入 | 说明 |
| --- | --- | --- | --- | --- |
| `trae` | Trae | `WEBVIEW_CALLBACK` | 支持 | WebView 登录，回调携带凭据 |
| `trae_intl` | Trae 国际版 | `WEBVIEW_CALLBACK` | 支持 | 与 `trae` 共用适配器，按 key 注入区域 |
| `codebuddy` | WorkBuddy | `DEVICE_CODE` | 支持 | 设备码授权 |
| `codebuddy_intl` | WorkBuddy 国际版 | `DEVICE_CODE` | 支持 | 同上，国际区域 |
| `antigravity` | Antigravity | `OAUTH_LOOPBACK` | **不支持** | Google OAuth 回环回调 |
| `doubao` | 豆包 | `WEBVIEW_CALLBACK` | **不支持** | messages 会被扁平化为纯文本 |
| `zcode` | ZCode | `DEVICE_CODE` | **不支持** | 主通道为 Anthropic 消息块 |
| `zcode_intl` | ZCode 国际版 | `DEVICE_CODE` | **不支持** | 同上，国际区域 |
| `qoder` | Qoder | `DEVICE_CODE` | 支持 | |
| `qoder_intl` | Qoder 国际版 | `DEVICE_CODE` | 支持 | |
| `xiaohuanxiong` | 商汤小浣熊 | `WEBVIEW_CALLBACK` | 支持 | |
| `loomy` | Loomy | `SMS_CODE` | 支持 | 短信验证码登录 |
| `custom` | 自定义供应商 | `API_KEY` | 支持 | OpenAI 兼容上游，用户可自建 |

> 「图片输入」指**当前协议链路**是否保证保留 `image_url` 内容块。返回不支持时，Responses 适配层会在请求前明确报 `400 unsupported_image_input`，避免图片被上游静默丢弃。

## 2. 登录方式

| 类型 | 流程 |
| --- | --- |
| `WEBVIEW_CALLBACK` | `start` 返回一个登录页地址 → 客户端用 WebView 打开 → 登录完成后上游重定向到 `/authorize`，服务端 `handleCallback()` 解析凭据落库 |
| `OAUTH_LOOPBACK` | `start` 返回授权链接 → 回调到本机回环地址（`/oauth-callback`）→ 同上落库 |
| `DEVICE_CODE` | `start` 返回设备码与验证地址 → 客户端轮询 `poll` → 授权完成后写回凭据 |
| `SMS_CODE` | 发送短信验证码后轮询 `poll` 完成登录 |
| `API_KEY` | 直接提交上游 API Key 与 `base_url` |

对应接口（管理端与用户端同构）：

```text
POST /api/{admin|user}/auth/{provider}/start
POST /api/{admin|user}/auth/{provider}/poll
POST /api/{admin|user}/auth/{provider}/callback
```

## 3. 凭据存储

- 账号凭据以 JSON 形式整体加密后落库：`Crypto::encryptArray()` → AES-256-GCM（密钥来自 `AIGW_APP_KEY`）
- 解密只在调用上游时发生，接口返回给前端时统一走 `mask_secret()` 脱敏
- Token 临期时由 `refreshToken()` 刷新，失败会记录到账号状态并进入冷却

## 4. 账号能力

| 方法 | 用途 |
| --- | --- |
| `models()` | 拉取账号可用模型 ID |
| `modelCatalog()` | 拉取模型目录（倍率、上下文窗口、最大输出、多模态/推理标签），供 `ModelCatalogSync` 落库 |
| `credits()` | 查询额度 / 积分 |
| `action()` | 扩展动作：`checkin`（签到）、`tasks`（新手任务）、`redeem`（兑换） |
| `refreshToken()` | 刷新访问令牌 |
| `parseError()` | 把上游错误映射为统一分类 `ErrorCategory` |

`ErrorCategory` 会把上游错误归并为：额度耗尽、鉴权失效、模型不可用、请求非法、限流、上游故障、网络错误等，供账号池决定「换账号 / 冷却 / 直接失败」。

## 5. 自定义供应商

`custom` 适配器支持用户在控制台自建 OpenAI 兼容上游：

- 配置项：`base_url`、`api_key`、模型列表、超时、代理
- `base_url` 受 `Support\UrlGuard` 的 SSRF 校验；默认禁止私网地址，确需内网时显式设置 `AIGW_ALLOW_PRIVATE_UPSTREAM=1`
- 供应商 key 形如 `custom_u<uid>_xxx`，按租户隔离

## 6. 新增供应商

1. 在 `app/Provider/Providers/` 新建类，继承 `AbstractProvider` 并实现 `Provider` 契约
2. 在 `ProviderManager::$builtins` 注册 key（区域版可用同一个类，通过 `withKey()` 区分）
3. 在 `database/schema.sql` 与 `aigw_provider` 种子数据中补充供应商记录与默认配置
4. 如需前端展示图标，在 `public/assets/providers/` 放入素材并在页面的供应商映射中登记

适配器只需要关心四件事：**登录换凭据、请求体转换、SSE 增量解析、错误归一化**。其余（账号池、限流、日志、计费口径）均由服务层统一处理。
