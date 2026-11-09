# 安装指南

> **文档导航**：[总目录](README.md) · [安装](installation.md) · [部署](deployment.md) · [配置](configuration.md) · [架构](architecture.md) · [API](api.md) · [供应商](providers.md) · [开发](development.md)

本文覆盖**从零到跑通第一次对话**的全部步骤：环境检查 → 建库 → 写 `.env` → 执行安装脚本 →
配置 Web 服务器 → 首次登录初始化 → 上线自检。生产环境的长期运维（备份、升级、CDN、排障）
请继续阅读 [部署指南](deployment.md)。

---

## 目录

- [1. 开始之前](#1-开始之前)
- [2. 环境检查](#2-环境检查)
- [3. 获取代码](#3-获取代码)
- [4. 创建数据库与专用账号](#4-创建数据库与专用账号)
- [5. 配置 .env](#5-配置-env)
- [6. 执行安装脚本](#6-执行安装脚本)
- [7. 目录与权限](#7-目录与权限)
- [8. 配置 Web 服务器](#8-配置-web-服务器)
- [9. PHP-FPM 与 php.ini](#9-php-fpm-与-phpini)
- [10. 计划任务](#10-计划任务)
- [11. 首次登录与初始化检查清单](#11-首次登录与初始化检查清单)
- [12. 安装验证](#12-安装验证)
- [13. 常见安装问题](#13-常见安装问题)
- [14. 本地开发环境（Windows 与 phpStudy）](#14-本地开发环境windows-与-phpstudy)
- [15. 重装与卸载](#15-重装与卸载)

---

## 1. 开始之前

### 1.1 三种典型安装场景

| 场景 | 推荐路径 | 说明 |
| --- | --- | --- |
| 生产（Linux + 宝塔） | 本文 §2 ~ §12 | CentOS 7.9 / Ubuntu 22.04 + Nginx + PHP 8.2 + MySQL 8 |
| 自建 VPS（裸装） | 本文 §2 ~ §12，再读 [部署指南](deployment.md) | 需要自行安装 Nginx、PHP-FPM、MySQL |
| 本地开发（Windows） | 直接跳到 [§14](#14-本地开发环境windows-与-phpstudy) | phpStudy 集成环境最省事 |

### 1.2 你需要提前准备

- 一个**域名**（生产环境建议 HTTPS；本地开发可用 `http://127.0.0.1`）
- **MySQL 8.0+** 的连接信息（主机、端口、库名、账号、口令）
- 服务器的 **SSH 与文件上传权限**
- 一颗能接受「没有 Composer、没有常驻进程」的心：

> [!IMPORTANT]
> 本项目是**原生 PHP 8.2**，不依赖 Composer、Laravel 或任何框架。代码拉下来配好 `.env`
> 就能跑，但这也意味着**升级靠覆盖代码 + 执行增量 SQL**，没有 `artisan`、`migrate` 之类的工具。

---

## 2. 环境检查

### 2.1 硬件与系统

| 项目 | 最低 | 建议 |
| --- | --- | --- |
| CPU | 1 核 | 2 核以上（流式请求会长期占用 worker） |
| 内存 | 1 GB | 2 GB 以上（Node/Chrome 验证码能力另需 2 GB） |
| 磁盘 | 20 GB | 40 GB 以上（日志与备份会持续增长） |
| 系统 | CentOS 7.9 / Ubuntu 20.04 | CentOS 7.9 或 Ubuntu 22.04 |

### 2.2 PHP 检查

```bash
php -v                  # 必须是 8.2 或更高
php -m | grep -E 'pdo_mysql|openssl|curl|mbstring|gd|session|json'
```

必须存在的扩展：

| 扩展 | 用途 | 缺失时的表现 |
| --- | --- | --- |
| `pdo_mysql` | 全部数据库访问 | 安装脚本直接报「数据库连接失败」 |
| `openssl` | 凭据 AES-256-GCM 加解密、HTTPS 上游 | 添加账号时报「凭据加密失败」 |
| `curl` | 调用上游 API | 所有对话请求失败 |
| `mbstring` | 中文与多字节处理 | 日志/截断出现乱码 |
| `gd` | 验证码图片 | 登录页验证码空白 |
| `session` / `json` | 控制台登录、JSON 响应 | 登录态丢失 / 接口 500 |

检查 CLI 与 FPM 是**同一个 PHP 版本**：

```bash
/www/server/php/82/bin/php -v                 # CLI
curl -s http://127.0.0.1/phpinfo.php | head   # FPM（临时文件，查完请删除）
```

> [!WARNING]
> 宝塔/军哥 LNMP 经常出现「CLI 是 8.2、FPM 是 7.4」或反之。版本不一致时，
> Web 端正常但 `bin/*.php` 与 `database/install.php` 会因语法（`readonly`、`enum`、命名参数）
> 直接报解析错误。

### 2.3 其他依赖

```bash
php -r 'var_dump(function_exists("proc_open"));'   # 必须 true（异步任务、验证码求解）
mysql -V                                           # 8.0+
nginx -V                                           # 1.20+，建议带 realip 模块
```

> [!NOTE]
> `proc_open` 被 `disable_functions` 禁用不是「致命错误」：核心对话功能仍可用，
> 但「账号动作异步执行」「深度研究工作流」「验证码求解」会退化为不可用或同步阻塞。

> [!NOTE]
> 「验证码求解」还需要一个**未包含在本仓库**的浏览器驱动脚本（原开发目录的
> `tools/captcha-solver.js`）。仓库刻意排除了 `tools/`，因此该能力在公开版本中默认不可用；
> 其余功能（网关、账号池、控制台、计划任务）都不依赖它。

---

## 3. 获取代码

```bash
git clone https://github.com/ikooc/aigw-backend.git /www/wwwroot/aigw
cd /www/wwwroot/aigw
```

没有 git 时也可以下载 ZIP 解压到 `/www/wwwroot/aigw`。

目录结构速览（完整说明见 [架构说明](architecture.md#3-分层职责)）：

```text
/www/wwwroot/aigw
├── app/            业务代码（Controllers / Services / Repositories / Provider …）
├── bin/            CLI：scheduler.php、workflow-worker.php 及各类 worker
├── bootstrap/      自动加载与全局函数
├── config/         app / database / gateway 配置
├── database/       schema.sql（建表）与 install.php（安装器）
├── deploy/         nginx、php-fpm、php.ini、crontab 参考配置
├── docs/           文档（就是你现在看的位置）
├── public/         唯一 Web 入口 index.php + 控制台 SPA + 静态资源
├── routes/         路由文件，启动时自动加载
└── storage/        运行时目录（会话、日志、临时文件；默认不入库）
```

> [!IMPORTANT]
> Web 站点根目录必须是**项目根目录**（`/www/wwwroot/aigw`），**不要**指向 `public/`。
> 入口 `public/index.php` 由 rewrite 转发；指到 `public/` 会导致 `.env`、`storage/` 全部不可见。

---

## 4. 创建数据库与专用账号

推荐**不要用 root**。以 MySQL 8 为例：

```sql
CREATE DATABASE `aigw` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'aigw'@'127.0.0.1' IDENTIFIED BY '换成你自己的强口令';
GRANT ALL PRIVILEGES ON `aigw`.* TO 'aigw'@'127.0.0.1';
FLUSH PRIVILEGES;
```

说明：

- 库名可自定义，但要与 `.env` 的 `AIGW_DB_DATABASE` 一致（示例用 `aigw`；配置文件里的默认值 `ai_com` 仅为历史遗留）。
- 表前缀固定 `aigw_`，由 `config/database.php` 控制，**不要**手工改。
- 字符集必须是 `utf8mb4`，否则中文昵称、emoji 会被截断。
- 安装脚本会自己执行 `CREATE DATABASE IF NOT EXISTS`，所以账号至少有该库的建库权限；若不方便给建库权限，请提前手工建好库，并只授予该库权限。

---

## 5. 配置 .env

### 5.1 复制模板

```bash
cp .env.example .env
chmod 600 .env
```

Windows PowerShell：

```powershell
Copy-Item .env.example .env
```

### 5.2 必须填写的四项

| 变量 | 说明 | 示例 |
| --- | --- | --- |
| `AIGW_APP_URL` | 对外访问地址，**带协议、结尾不带斜杠** | `https://ai.example.com` |
| `AIGW_DB_DATABASE` | 数据库名 | `aigw` |
| `AIGW_DB_USERNAME` / `AIGW_DB_PASSWORD` | 专用账号与口令 | `aigw` / `********` |
| `AIGW_APP_KEY` | 凭据加密主密钥 | `base64:....` |

生成主密钥：

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

> [!WARNING]
> `AIGW_APP_KEY` 一旦录入过任何账号凭据就**不能更换**。更换后所有历史凭据无法解密，
> 表现为「账号全部失效」「添加账号后立刻掉线」。备份时请把 `.env` 与数据库一起备份。

### 5.3 生产环境建议同时设置

```dotenv
AIGW_APP_DEBUG=false                                  # 生产必须 false
AIGW_ADMIN_LOGIN_PATH=/api/admin/x-9f3k-login         # 隐藏管理端登录入口，降低爆破
AIGW_TRUSTED_PROXIES=                                 # 有反向代理时填代理 IP
AIGW_PHP_BIN=/www/server/php/82/bin/php               # 异步 worker 用的 PHP 绝对路径
```

全部变量与默认值见 [配置参考](configuration.md)。

---

## 6. 执行安装脚本

### 6.1 首次安装

```bash
# 建议显式传入管理员口令；不传则随机生成并打印一次
AIGW_INSTALL_ADMIN_PASSWORD='你的强口令' php database/install.php
```

也可以用专用用户名：

```bash
AIGW_INSTALL_ADMIN_USERNAME=aigw AIGW_INSTALL_ADMIN_PASSWORD='强口令' php database/install.php
```

### 6.2 脚本做了什么

```mermaid
graph TD
    A["读取 .env → config/database.php"] --> B["连接 MySQL（不指定库名）"]
    B --> C["CREATE DATABASE IF NOT EXISTS"]
    C --> D{"aigw_provider 表已存在？"}
    D -- 否 --> E["执行 schema.sql 建 29 张表"]
    D -- 是且无 --force --> F["跳过建表（保留数据）"]
    D -- 是且有 --force --> G{"业务表有数据？"}
    G -- 有且未确认 --> H["拒绝执行并提示先备份"]
    G -- 无 / AIGW_ALLOW_DESTRUCTIVE=1 --> I["DROP 重建（清空整库）"]
    E --> J["创建 / 更新管理员账号"]
    F --> J
    I --> J
    J --> K["确保网关设置 id=1 并生成 sk-aigw-* Key"]
    K --> L{".env 是否存在"}
    L -- 否 --> M["按 .env.example 生成并写入随机主密钥"]
    L -- 是 --> N["完成"]
    M --> N
```

期望输出：

```text
[✓] 数据库连接成功 (127.0.0.1:3306)
[✓] 数据库就绪：aigw
[✓] 表结构创建完成（共 N 条语句）
[✓] 管理员账号：admin / **********（请登录后立即修改）
[✓] 网关 API Key：sk-aigw-********
```

> [!CAUTION]
> `--force` 的真实语义是 **DROP 全部表后重建 = 清空整库**（`schema.sql` 每张表前都有
> `DROP TABLE IF EXISTS`）。库内已有业务数据时，必须再加 `AIGW_ALLOW_DESTRUCTIVE=1`
> 才会执行。升级场景**不要**用 `--force`，见 [部署指南 § 8](deployment.md#8-升级与回滚)。

### 6.3 如果安装中途失败

脚本遇到任意一条 SQL 执行失败会**立即中止**并列出失败语句（不会留下半成品继续跑）：

```text
[×] 有 2 条语句执行失败：
    · CREATE TABLE ...  →  Specified key was too long
[×] 安装中止：建表未全部成功，请修复后重试
```

常见原因是 MySQL 参数（`innodb_default_row_format`、`utf8mb4_0900_ai_ci` 排序规则）不一致。
修复后重新执行即可；已建的表因为有 `DROP TABLE IF EXISTS`，重跑是幂等的。

---

## 7. 目录与权限

```bash
cd /www/wwwroot/aigw
chown -R www:www .
find storage -type d -exec chmod 775 {} \;
chmod -R 775 storage
chmod 600 .env
```

`storage/` 会在运行时自动创建以下子目录：

| 目录 | 内容 |
| --- | --- |
| `storage/sessions/` | 会话文件（登录态） |
| `storage/logs/` | `app-YYYY-MM-DD.log`、`php-error.log`、worker 日志、`cron.log` |
| `storage/tmp/` | 临时文件、上传中转 |
| `storage/backup/`、`storage/login/`、`storage/persona/` | 业务缓存 |

> [!WARNING]
> 宝塔站点设置里的「防跨站攻击（open_basedir）」建议**关闭**。它会把可访问路径限制到
> 站点目录内，导致 `.env` 读不到、`storage/` 不可写、`proc_open` 子进程起不来，
> 典型症状是「登录后刷新立刻掉线」「打开页面直接 500」。

---

## 8. 配置 Web 服务器

### 8.1 Nginx（推荐）

用 [deploy/nginx-aigw.conf](../deploy/nginx-aigw.conf) **整体替换**站点配置，然后改三处：

| 需要改的地方 | 说明 |
| --- | --- |
| `server_name` | 改成你的域名 |
| `root` 与 `alias` | 改成你的站点绝对路径（默认 `/www/wwwroot/aigw`） |
| `fastcgi_pass` | 确认 PHP-FPM socket，执行 `ls -l /tmp/php-cgi-8*.sock` 得到实际值 |

必须保留的关键片段：

| 片段 | 作用 | 删掉会怎样 |
| --- | --- | --- |
| `location = /v1/chat/completions`（`fastcgi_buffering off`） | SSE 逐块下发 | 流式变「一次性吐完」或 504 |
| `location ~ ^/(storage\|database\|docs\|app\|config\|routes\|bin\|tests\|tools)(/\|$)` | 拒绝源码/日志/`.env` 直出 | 日志中的对话原文、数据库口令可被公网下载 |
| `location ~* \.(env\|sql\|log\|bak\|md\|ini\|sh)$` | 敏感扩展名封禁 | 同上 |
| `/assets/**` 的 `alias` | 静态资源映射到 `public/assets` | 控制台白屏 |
| `client_max_body_size 50m` | 长上下文请求体 | 上传/长对话直接 413 |

验证配置并重载：

```bash
nginx -t && nginx -s reload
```

### 8.2 Apache

站点根指向**项目根目录**，仓库根目录的 `.htaccess` 已包含等价 rewrite 规则，只需确保：

```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

并在虚拟主机中允许重写：

```apache
<Directory "/www/wwwroot/aigw">
    AllowOverride All
    Require all granted
</Directory>
```

### 8.3 为什么不能只用 PHP 内置服务器

`php -S` 不执行 `.htaccess`，也不认识 `/assets/**` 的重写规则，直接把静态资源请求
扔进应用会造成控制台白屏。本地调试请用 [§14.2](#142-方案-bphp-内置服务器可选) 的
`dev-router.php` 写法，或直接用 phpStudy 的 Apache/Nginx。

---

## 9. PHP-FPM 与 php.ini

参考 [deploy/php-fpm-snippet.conf](../deploy/php-fpm-snippet.conf) 与
[deploy/php-ini-snippet.ini](../deploy/php-ini-snippet.ini)，最小改动是：

```ini
; 会话必须落在项目内，多 worker 才不会解析到不同临时目录
session.save_path = "/www/wwwroot/aigw/storage/sessions"
session.gc_maxlifetime = 604800

; SSE 不能被缓冲或压缩
output_buffering = Off
zlib.output_compression = Off

; 长对话与流式
max_execution_time = 300
memory_limit = 512M
```

同时确认 `disable_functions` **没有**包含 `proc_open`、`exec`、`shell_exec`：

```bash
php -i | grep disable_functions
```

改完重启 PHP-FPM（宝塔：软件商店 → PHP 8.2 → 重载配置）。

---

## 10. 计划任务

本项目**没有常驻进程**，内置签到 / Token 保活 / 积分巡检 / 用户工作流全靠 cron 触发：

```cron
# 内置调度（幂等，建议 5 分钟一次）
*/5 * * * * cd /www/wwwroot/aigw && /www/server/php/82/bin/php bin/scheduler.php >> /www/wwwroot/aigw/storage/logs/cron.log 2>&1

# 用户工作流（建议 1 分钟一次）
* * * * * cd /www/wwwroot/aigw && /www/server/php/82/bin/php bin/workflow-worker.php >> /www/wwwroot/aigw/storage/logs/cron.log 2>&1
```

要点：

- 必须写 **PHP 绝对路径**（cron 的 PATH 与登录 shell 不同，容易误用系统自带的 PHP 5.4）。
- 时区必须是 `Asia/Shanghai`，否则「22 点该保活」会跑到别的时段。
- 任务总开关**默认关闭**，需要到管理台「设置」里手动打开。
- 更完整的说明与宝塔面板配置方式见 [deploy/crontab.txt](../deploy/crontab.txt)。

---

## 11. 首次登录与初始化检查清单

### 11.1 登录管理台

1. 浏览器打开 `https://你的域名/admin`
2. 使用安装脚本输出的管理员账号登录（默认用户名 `admin`）
3. **立即修改密码**：右上角头像 → 个人资料 → 修改密码

> [!TIP]
> 若把 `AIGW_ADMIN_LOGIN_PATH` 改成了自定义值，登录**页面**仍是 `/admin`，
> 但前端调用的登录**接口**会随之改变；清空浏览器缓存后再登录。

### 11.2 按顺序完成这些初始化

| # | 操作 | 位置 | 说明 |
| --- | --- | --- | --- |
| 1 | 修改管理员密码 | 管理台 → 个人资料 | 安装脚本打印的口令只应出现一次 |
| 2 | 核对站点名称与访问地址 | 管理台 → 设置 | 影响接入地址展示与 OAuth 回调 |
| 3 | 确认网关 API Key | 管理台 → 设置 | 已由安装脚本生成 `sk-aigw-*`，可重置 |
| 4 | 添加一个上游账号 | 管理台 → 账号管理 → 添加 | 按供应商选登录方式，见 [供应商适配](providers.md#2-登录方式) |
| 5 | 同步该账号的模型 | 管理台 → 账号管理 → 模型 | 决定 `/v1/models` 返回内容 |
| 6 | 发一次测试对话 | 管理台 → 调试 / `curl` | 见 [§12](#12-安装验证) |
| 7 | 按需开启定时任务 | 管理台 → 设置 → 定时任务 | 签到、Token 保活、积分巡检 |
| 8 | 创建普通用户（可选） | 管理台 → 用户管理 | 用户自带 `sk-user-*`，按租户隔离 |

---

## 12. 安装验证

### 12.1 健康检查

```bash
curl -s http://127.0.0.1/v1/health
```

期望返回类似：

```json
{"status":"ok","version":"1.0.0","time":1760000000}
```

### 12.2 模型列表

```bash
curl -s http://127.0.0.1/v1/models \
  -H "Authorization: Bearer sk-aigw-xxxx"
```

### 12.3 第一次对话

```bash
curl -s http://127.0.0.1/v1/chat/completions \
  -H "Authorization: Bearer sk-aigw-xxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "你账号里真实存在的模型 ID",
    "messages": [{"role": "user", "content": "你好，用一句话介绍你自己"}],
    "stream": false
  }'
```

流式验证（应**逐字**出现，而不是等很久一次性吐出）：

```bash
curl -N http://127.0.0.1/v1/chat/completions \
  -H "Authorization: Bearer sk-aigw-xxxx" \
  -H "Content-Type: application/json" \
  -d '{"model":"同上的模型 ID","messages":[{"role":"user","content":"数到 10"}],"stream":true}'
```

### 12.4 安装自检清单

- [ ] `GET /v1/health` 返回 200
- [ ] `/admin` 可打开，能登录，刷新不掉线
- [ ] `/console` 可打开（用户端控制台）
- [ ] `/assets/js/app.js` 返回 200 且 `Content-Type: application/javascript`（不是 HTML）
- [ ] 直接访问 `/storage/logs/` 返回 **404**（安全闸门生效）
- [ ] 添加账号成功，凭据未在接口响应中明文出现
- [ ] `stream=true` 逐块输出
- [ ] `storage/logs/app-YYYY-MM-DD.log` 有本次请求记录

接口细节见 [API 参考](api.md)；安全相关配置见 [部署指南 § 9](deployment.md#9-安全加固清单)。

---

## 13. 常见安装问题

| 现象 | 常见原因 | 处理 |
| --- | --- | --- |
| `数据库连接失败：SQLSTATE[HY000] [2002]` | 主机/端口错、MySQL 未启动、只监听 socket | `AIGW_DB_HOST=127.0.0.1`，或改用 `localhost`；确认 `systemctl status mysqld` |
| `Access denied for user` | 账号无该库权限、口令含特殊字符未加引号 | 重新授权；`.env` 中口令**不要加引号**，有空格/`#` 时用单引号包裹 |
| `SQLSTATE[HY000] [1045]` 且账号正确 | MySQL 8 默认 `caching_sha2_password` 与老版客户端不兼容 | 升级 PHP 的 `pdo_mysql`，或为用户改用 `mysql_native_password` |
| 建表报 `Specified key was too long` | 行格式为 `COMPACT`，索引总长度超限 | `innodb_default_row_format=DYNAMIC`，重启 MySQL 后重跑 |
| 页面 500、无任何提示 | `display_errors=0`（生产默认） | 看 `storage/logs/php-error.log`；临时把 `AIGW_APP_DEBUG=true` 定位后立刻改回 |
| 安装脚本输出全是乱码方块 | Windows 终端编码问题 | 在 PowerShell 里 `chcp 65001`，或用 Git Bash 执行 |
| 登录后刷新立刻退出 | `session.save_path` 不可写、多 worker 目录不一致、`open_basedir` 拦截 | §7 与 §9；确认 `storage/sessions` 属主为 `www` |
| `/admin` 404 | 站点目录不是项目根、rewrite 未生效 | 检查 `root`、`try_files`、Apache 的 `AllowOverride` |
| 控制台白屏、`/assets/js/app.js` 返回 HTML | 静态资源没有映射到 `public/assets` | 补上 `/assets/**` 的 `alias` 规则（见 §8.1） |
| 登录接口 404，页面能打开 | `AIGW_ADMIN_LOGIN_PATH` 与前端缓存的旧值不一致 | 清缓存/换浏览器；或把该变量改回默认值 |
| 添加账号报「凭据加密失败」 | `openssl` 扩展缺失或 `AIGW_APP_KEY` 为空 | `php -m`、生成主密钥后重启 FPM |
| 异步任务一直 PENDING | `proc_open` 被禁、`AIGW_PHP_BIN` 指向错误 | 看 `storage/logs/action-worker.log`；用绝对路径 |
| 验证码求解报错/无响应 | 缺少 `tools/captcha-solver.js`（仓库不含），或 Node/Chrome 不可用 | 见 [§2.3](#23-其他依赖) 的说明；不需要该能力可忽略 |
| `mkdir(): Permission denied` | `storage/` 属主不对 | `chown -R www:www storage && chmod -R 775 storage` |
| 请求返回 413 | 请求体超过 `client_max_body_size` | 调到 `50m` 或更大 |
| 502 Bad Gateway | PHP-FPM socket 路径错 / 未启动 | `ls -l /tmp/php-cgi-8*.sock`，`nginx -t` |

排查三步走：

```bash
tail -n 50 storage/logs/php-error.log
tail -n 50 storage/logs/app-$(date +%F).log
nginx -t && nginx -s reload
```

---

## 14. 本地开发环境（Windows 与 phpStudy）

> 这一节以 **phpStudy（Apache + PHP 8.2 + MySQL 8）** 为例；用 Nginx 同理，
> 规则换成 [deploy/nginx-aigw.conf](../deploy/nginx-aigw.conf)。

### 14.1 方案 A：phpStudy（推荐）

1. **启用 PHP 8.2** 与扩展：`pdo_mysql`、`openssl`、`curl`、`mbstring`、`gd`、`fileinfo`（在 `php.ini` 里去掉前面的 `;`）。
2. **创建数据库**：phpStudy → 数据库 → 新建 `aigw`，字符集 `utf8mb4`。
3. **复制配置**：

   ```powershell
   Copy-Item .env.example .env
   ```

   至少修改：`AIGW_APP_URL=http://127.0.0.1:端口`、`AIGW_DB_*`、`AIGW_APP_KEY`。

4. **执行安装**（用绝对路径调用 php）：

   ```powershell
   & "D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe" database/install.php
   ```

5. **建站点**：phpStudy → 网站 → 创建站点，**根目录选项目根**（不是 `public`），
   Apache 下确认 `mod_rewrite` 已开启，`.htaccess` 生效。
6. 打开 `http://127.0.0.1:端口/admin` 登录。

> [!TIP]
> 本地开发建议在 `.env` 里设 `AIGW_APP_DEBUG=true`（可以看堆栈），
> **上线前必须改回 `false`**。同时把 `AIGW_PHP_BIN` 指向 `php.exe` 绝对路径，
> 否则异步 worker 起不来。

本地不需要配置 cron，需要时可手工触发：

```powershell
& "D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe" bin/scheduler.php
```

若确实要定时执行，用 Windows「任务计划程序」调用上面的命令即可。

### 14.2 方案 B：PHP 内置服务器（可选）

内置服务器不认 `.htaccess`，需要一个临时路由脚本（**仅本地使用，不要提交**）：

```php
<?php
// dev-router.php —— 放在项目根目录，仅供本地调试
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/public' . $path;

// 静态资源直出（/assets/**、favicon 等）
if (is_file($file) && !str_ends_with($file, '.php')) {
    header('Content-Type: ' . (mime_content_type($file) ?: 'application/octet-stream'));
    readfile($file);
    return;
}

require __DIR__ . '/public/index.php';
```

启动：

```bash
php -S 127.0.0.1:8080 dev-router.php
```

> [!NOTE]
> 内置服务器是**单进程**，一边开流式对话一边刷新控制台会互相阻塞；
> 只适合接口调试，不适合做前端联调。

---

## 15. 重装与卸载

### 15.1 保留数据重装代码

```bash
cd /www/wwwroot/aigw
git pull            # 或解压新版本覆盖
php database/install.php   # 已有表会跳过建表，不会清空数据
```

### 15.2 彻底重来（**会清空数据**）

```bash
# 1) 先备份
mysqldump -uaigw -p aigw > backup-$(date +%F-%H%M).sql
tar -czf storage-backup-$(date +%F).tar.gz storage .env

# 2) 确认要清空后再执行（二选一）
mysql -uaigw -p -e "DROP DATABASE aigw; CREATE DATABASE aigw DEFAULT CHARACTER SET utf8mb4;"
# 或者
AIGW_ALLOW_DESTRUCTIVE=1 php database/install.php --force
```

> [!CAUTION]
> 删除 `storage/` 会丢失**所有**登录会话、Persona 缓存与临时文件；
> 删除 `.env` 会丢失 `AIGW_APP_KEY`，**已入库的供应商凭据将永久无法解密**。
> 除非你就是要全新开始，否则两个都不要删。

---

## 相关文档

- [部署指南](deployment.md) —— 生产运维、CDN 回源、备份升级、故障排查
- [配置参考](configuration.md) —— 全部环境变量
- [API 参考](api.md) —— 接口契约与示例
- [供应商适配](providers.md) —— 各上游登录方式与凭据
- [架构说明](architecture.md) —— 请求生命周期与分层
- [开发指南](development.md) —— 二次开发
- [文档中心](README.md)
