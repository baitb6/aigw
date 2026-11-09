# 配置参考

> **文档导航**：[总目录](README.md) · [安装](installation.md) · [部署](deployment.md) · [配置](configuration.md) · [架构](architecture.md) · [API](api.md) · [供应商](providers.md) · [开发](development.md)

配置来源有两层：

1. `.env` 文件（由 `Config::loadEnvFile()` 读入 `$_ENV`，**不调用 `putenv()`**）
2. `config/*.php` 中的默认值（`env()` 读取失败时回退）

> 修改 `.env` 后需要重启 PHP-FPM。`env_raw()` 会同时查 `$_ENV`、`$_SERVER` 与 `getenv()`，因此 PHP-FPM 池里的 `env[XXX]` 也能生效。

## 1. 应用

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `AIGW_APP_NAME` | `AI 聚合网关` | 站点名称 |
| `AIGW_APP_DEBUG` | `false` | 生产必须为 `false`；开启会把异常堆栈返回客户端 |
| `AIGW_APP_URL` | `http://ai.com` | 对外访问地址（带协议、结尾不带斜杠），用于生成接入地址与 OAuth 回调 |
| `AIGW_APP_KEY` | 空 | **凭据加密主密钥**（AES-256-GCM），格式 `base64:<32 字节>`。一旦录入账号就不可更换 |
| `AIGW_ADMIN_LOGIN_PATH` | `/api/admin/login` | 管理台登录入口路径，可改为不易猜到的值以降低扫描爆破 |
| `AIGW_CORS_ORIGINS` | 空 | 额外跨域来源，逗号分隔；同源始终允许 |

生成主密钥：

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

## 2. 数据库

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `AIGW_DB_HOST` | `127.0.0.1` | MySQL 主机 |
| `AIGW_DB_PORT` | `3306` | 端口 |
| `AIGW_DB_DATABASE` | `ai_com` | 库名 |
| `AIGW_DB_USERNAME` | `root` | 账号（建议使用专用账号，不要用 root） |
| `AIGW_DB_PASSWORD` | 空 | 口令，无默认值，必须显式配置 |

表前缀固定为 `aigw_`（见 `config/database.php`）。

## 3. 网关

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `AIGW_GATEWAY_PORT` | `51120` | 仅用于展示「接入地址」，实际由 Web 服务器监听 |
| `AIGW_GATEWAY_BASE_URL` | `http://ai.com` | 对外网关基址，保持与 `AIGW_APP_URL` 一致 |
| `AIGW_TRUSTED_PROXIES` | 空 | 可信反向代理 IP（精确匹配，不做 CIDR）；`127.0.0.1`/`::1` 始终可信 |
| `AIGW_ORIGIN_TOKEN` | 空 | CDN 回源口令，`openssl rand -hex 32` 生成 |
| `AIGW_ORIGIN_TOKEN_HEADER` | `x-aigw-origin-token` | 口令所在请求头（需与 CDN 控制台一致） |
| `AIGW_CLIENT_IP_HEADER` | `eo-connecting-ip` | 真实客户端 IP 头（EdgeOne 用 `eo-connecting-ip`） |
| `AIGW_ALLOW_PRIVATE_UPSTREAM` | 未设置（关闭） | 设为 `1` 允许自定义供应商指向内网地址，会削弱 SSRF 防护 |

`config/gateway.php` 内部还有：

| 配置键 | 默认值 | 说明 |
| --- | --- | --- |
| `quota_cooldown_ms` | 10 分钟 | 额度耗尽后的冷却时长 |
| `soft_cooldown_ms` | 1 分钟 | 软失败冷却 |
| `max_error_count` | 3 | 单账号连续错误上限，超过进入冷却 |

## 4. CLI 与可选运行时

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `AIGW_PHP_BIN` | 自动探测 | PHP CLI 绝对路径，异步 worker 使用 |
| `AIGW_NODE_BIN` | 自动探测 | Node ≥ 20.10 绝对路径（仅验证码求解需要） |
| `AIGW_BROWSER_BIN` | 自动探测 | Chrome/Chromium 绝对路径 |
| `AIGW_CAPTCHA_HEADLESS` | 自动 | `1` 无头（易被风控拦截，仅调试）；`0` 有头 |
| `AIGW_XVFB_DISPLAY` | 空 | Linux 虚拟屏显示号，如 `:99` |
| `AIGW_ANTIGRAVITY_CLIENT_ID` | 空 | Antigravity 的 Google OAuth 客户端 ID；使用该供应商时必填 |
| `AIGW_ANTIGRAVITY_CLIENT_SECRET` | 空 | Antigravity 的 Google OAuth 客户端密钥；使用该供应商时必填 |

## 5. 安装脚本专用

| 变量 | 说明 |
| --- | --- |
| `AIGW_INSTALL_ADMIN_USERNAME` | 初始化管理员用户名，默认 `admin` |
| `AIGW_INSTALL_ADMIN_PASSWORD` | 初始化管理员口令；未设置则随机生成并打印 |
| `AIGW_ALLOW_DESTRUCTIVE` | 设为 `1` 才允许在已有业务数据的库上执行 `install.php --force` |

## 6. 会话

`config/app.php` 中：

| 键 | 值 | 说明 |
| --- | --- | --- |
| `session_name` | `aigw_session` | 会话 Cookie 名 |
| `session_ttl` | `604800`（7 天） | 会话有效期 |

`public/index.php` 会把 `session.save_path` 固定到 `storage/sessions`，避免多 PHP worker 解析到不同临时目录导致登录态丢失。

## 7. PHP 运行参数

参考 [deploy/php-ini-snippet.ini](../deploy/php-ini-snippet.ini) 与 [deploy/php-fpm-snippet.conf](../deploy/php-fpm-snippet.conf)，关键项：

- `session.save_path`、`session.gc_maxlifetime` ≥ 会话有效期
- `output_buffering` 关闭、`zlib.output_compression` 关闭（避免 SSE 被缓冲）
- `max_execution_time` 按流式需求放宽
- 不要禁用 `proc_open`（异步任务与验证码求解需要）
