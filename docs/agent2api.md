# Agent2API 网关接入（两套官方插件 → AIGW 供应商）

> 一句话：**Agent2API 跑在服务器上当一个「上游」，AIGW 把它那两家插件注册出来的 37 个 provider 变成自己的供应商/模型目录。**
> 插件本身是原生 ELF，跑在网关进程里，PHP 进程装不了它 —— 所以这是「能力桥接」，不是「代码合并」。

## 1. 为什么不是把插件代码塞进 PHP

Agent2API 的两家插件：

| 插件 | 版本 | 作用 | 形态 |
|---|---|---|---|
| `agent2api-providers` | 3.4.2 | 提供商增强：账号登录/导入、凭证续期、模型目录、余额、签到（34 个 provider） | 原生 ELF，ed25519 签名，由网关 host 加载 |
| `agent2api-web-providers` | 3.4.3 | 网页提供商：智谱清言 / DeepSeek 网页 / Tabbit 等（4 个 provider） | 同上 |

它们不是 PHP 扩展，也不是 HTTP 服务，而是**网关进程内的动态库**。能复用的是它们在网关里注册出来的结果：

```
        ┌──────────────────────── 服务器 ────────────────────────┐
        │                                                        │
浏览器 →│  AIGW (PHP 8.2 + MySQL)                                │
        │   /v1/chat/completions  ──┐                            │
        │   /console 供应商/模型页   │  HTTP + 网关 Key            │
        │                           ▼                            │
        │                 Agent2API 网关 :3065                    │
        │                   ├── /v1/*      模型面（Bearer Key）    │
        │                   ├── /api/*     管理面（x-panel-token） │
        │                   ├── agent2api-providers      (34)     │
        │                   └── agent2api-web-providers  ( 4)     │
        │                              │                         │
        └──────────────────────────────┼─────────────────────────┘
                                       ▼
                          各家上游（智谱 / DeepSeek / Qoder / ZCode …）
```

AIGW 这侧新增的就是「第 14 个适配器」：`Agent2ApiProvider`。

## 2. 改动清单（可直接覆盖到原项目）

新增：

| 文件 | 作用 |
|---|---|
| `app/Support/Agent2ApiClient.php` | 网关客户端：**两套鉴权面**分开封装（`/v1` 用 Bearer Key，`/api` 用 `x-panel-token`，令牌 2 小时自动续签） |
| `app/Provider/Providers/Agent2ApiProvider.php` | 适配器：chat / models / modelCatalog / credits / action / parseError |
| `bin/agent2api-sync.php` | 同步脚本：把网关的 provider + 模型目录写进 `aigw_provider` / `aigw_model` |
| `deploy/agent2api/install-agent2api.sh` | 在 Linux 服务器上装网关（自动选 amd64/arm64、sha256 校验、systemd 常驻） |
| `deploy/agent2api/install-plugins.sh` | 一键装两家插件（走 `install-from-release`，自动匹配架构，无需传 zip） |
| `deploy/agent2api/README.md` | 服务器侧三步走速查 |

修改：

| 文件 | 改动 |
|---|---|
| `app/Provider/ProviderManager.php` | 注册 `agent2api`；`agent2api_*` 前缀复用同一适配器（多实例） |
| `database/schema.sql` | 种子行 `agent2api`（sort_order 60） |
| `public/assets/js/components.js` | 账号卡动作按钮：`网关签到` / `网关状态` / `插件目录` |

## 3. 部署（宝塔，从零到能对话）

### 3.1 装 Agent2API 网关（同一台服务器）

```bash
# 上传本目录到 /www/wwwroot/aigw 后，先装网关
cd /www/wwwroot/aigw/deploy/agent2api
bash install-agent2api.sh
# 默认装到 /opt/agent2api，只监听 127.0.0.1:3065，systemd 常驻
```

要公网直连面板（不建议裸奔）：`A2A_BIND=0.0.0.0 bash install-agent2api.sh`

### 3.2 装两家插件 → 37 个 provider

```bash
bash install-plugins.sh admin '你的面板密码'
# 期望输出：agent2api-providers v3.4.2 enabled providers=34
#           agent2api-web-providers v3.4.3 enabled providers=4
#           合计 provider：37 个
```

> 插件索引要联网拉 GitHub。服务器在国内时，若报「连接 GitHub 源超时」，
> 给服务器配好代理或 `/etc/hosts` 钉 IPv4（网关进程只认 IPv4，IPv6 泄漏是最常见死因）。

### 3.3 建站 + 建库

宝塔 → 网站 → 添加站点（PHP 8.2，MySQL 8.0），根目录指向本项目的 `public/`。

```bash
cd /www/wwwroot/aigw
cp .env.example .env 2>/dev/null || true
# 编辑 .env：AIGW_DB_DATABASE / AIGW_DB_USERNAME / AIGW_DB_PASSWORD
php database/install.php
# 输出管理员账号与 API Key（只打印一次，务必存下）
```

Nginx 用 `deploy/nginx-aigw.conf`，PHP 参数用 `deploy/php-ini-snippet.ini`。

### 3.4 把网关挂成 AIGW 供应商

面板里在 Agent2API 建一把**网关 Key**（「网关 Key」页 → 新建），然后二选一：

**A. 脚本同步（推荐，37 个 provider 一次到位）**

```bash
php bin/agent2api-sync.php \
  --base=http://127.0.0.1:3065 \
  --key=a2a_你的网关Key \
  --panel-user=admin --panel-pass='面板密码' \
  --mode=split
```

- `--mode=split`：网关里每个 provider 在 AIGW 各占一行（`agent2api_chatglm`、`agent2api_tabbit` …），可单独开关/定价
- `--mode=aggregate`：全部模型合成一行 `agent2api`
- `--dry-run`：只看会写什么，不落库

**B. 控制台手填**：供应商 → Agent2API 网关 → 粘贴凭据 `Base URL` + `网关 Key`；额度/签到需要再填面板账号（`options.panel_user` / `panel_password`）。

### 3.5 验证

```bash
curl -s http://127.0.0.1:3065/health | head -c 200          # 网关活着
curl -s http://127.0.0.1:3065/v1/models | head -c 300      # 6 个模型（示例）
curl -s -X POST http://你的站点/v1/chat/completions \
  -H 'Authorization: Bearer AIGW的Key' -H 'Content-Type: application/json' \
  -d '{"model":"glm-5.3-flash","messages":[{"role":"user","content":"hi"}]}'
```

## 4. 凭据怎么填（三处，后者覆盖前者）

```jsonc
// aigw_provider.options
{
  "base_url": "http://127.0.0.1:3065",
  "api_keys": ["a2a_xxx"],          // 模型面用，必须有
  "panel_user": "admin",             // 管理面用，额度/签到/插件目录才有
  "panel_password": "***",
  "upstream_provider": "chatglm",    // 仅 split 模式：本行只暴露该 provider 的模型
  "models": []                       // 兜底：网关不可达时仍能出目录
}
```

账号级凭据（`aigw_account.credential`）可覆盖：`baseUrl` / `apiKey` / `panelUser` / `panelPassword`。

> 面板密码是明文存 `options`（与项目里其他 API_KEY 供应商一致）。
> 若多租户，把 `agent2api` 行设为管理员私有（`user_id=0`），别下发到普通用户。

## 5. 适配器能力对照

| AIGW 契约 | 实现 |
|---|---|
| `chat` | 转发 `/v1/chat/completions`（`stream:true`），复用基类 `parseOpenAiSse`，工具调用/推理/缓存字段全保留 |
| `models` / `modelCatalog` | 读 `/v1/models`，split 模式按 `owned_by` 过滤 |
| `credits` | 读 `/api/accounts/usage`，按账号汇总 `available` 与积分包 |
| `action` | `health` / `providers` / `accounts` / `checkin` / `refresh` / `auto-checkin` |
| `parseError` | 网关自有码：`model_api_key_required`→CLIENT、`model_not_found`→MODEL_UNAVAILABLE、`refresh_token_expired`→SESSION_DEAD、`panel_login_required`→CLIENT |
| `ingestCredential` | 真打 `/health` + `/v1/models` + 面板登录，验证完才落库 |
| `refreshToken` | 无操作：网关 Key 长期有效 |

## 6. 坑位备忘（踩过的）

1. **两套鉴权不能混**：把面板令牌塞进 `Authorization` → 网关回 `model_api_key_required`，看起来像 Key 没配。
2. **`AGENT2API_HOST` 默认 127.0.0.1**：AIGW 与网关不在同一台机器时，必须 `A2A_BIND=0.0.0.0` 且防火墙只放行 AIGW 那台。
3. **插件只能网关自己装**：手动解压到 `data/plugins` 不生效（签名校验不过）。
4. **`provider_key` 上限 32 字符**：`agent2api_` 占 10 个，脚本已按此截断；否则 MySQL 静默截断会让两个 provider 互相覆盖。
5. **模型 id 不改写**：网关按 `model` 自己路由到账号池，加前缀反而 404。
6. **上下文别贪大**：网关侧 prompt cache 命中率 99.8% 时首响 3.4s；一次换账号/换节点就全 miss，首响 10s+。AIGW 这边不要给同一会话做轮询负载均衡。

---

## 十、线上部署记录（2026-11-09 · your-domain.com）

实际落地的部署参数，供复查或迁移时对照：

| 项目 | 值 |
|---|---|
| 站点 | `https://your-domain.com`（宝塔站点 id 11） |
| 站点根 | `/www/wwwroot/your-domain.com`，**运行目录指向 `/public`** |
| PHP | 8.4.27（宝塔 php84，socket `unix:/tmp/php-cgi-84.sock`） |
| 数据库 | `aigw_db` / `aigw_db`，字符集 utf8mb4，仅 127.0.0.1 |
| 后台账号 | 见安装输出，安装后请立刻改密 |
| AIGW Key | `sk-aigw-…`（安装时打印一次，控制台可重置） |
| 网关地址 | `http://127.0.0.1:3065`（Agent2API，systemd 服务名 `agent2api`） |
| 网关程序 | `/opt/agent2api/agent2api-server`，数据 `/var/lib/agent2api`，环境 `/etc/agent2api/agent2api.env` |
| 网关插件 | `/var/lib/agent2api/plugins/agent2api-providers/3.4.2`、`agent2api-web-providers/3.4.3` |

### 与本文档前九节不同的两处

1. **运行目录直接指到 `public/`**，而不是「站点根保持项目根 + rewrite 转发」。
   这样 `.env`、`storage/`、`app/` 天然在 web 根之外，第九节那串敏感目录 404 规则就不再是唯一防线。
   代价：`$document_root` 变成 `…/public`，SSE 段里 `SCRIPT_FILENAME` 要写 `$document_root/index.php`。
2. **网关面板必须关掉人机验证**（`AGENT2API_CAPTCHA_ENABLED=0`）。
   AIGW 的后台动作（额度、签到、插件目录）走 `POST /api/panel/login`，
   带验证码时永远拿不到 `accessToken`，这些按钮会全部报 `panel_login_required`。
   关掉之后，公网面板改用 Nginx Basic Auth 兜一层，`/v1` 不受影响。

### 上线 checklist（照抄可复现）

```bash
# 1. 站点根指向 public（宝塔 → 网站 → 设置 → 网站目录 → 运行目录 /public）
# 2. .env 至少这七项
AIGW_APP_URL=https://your-domain.com
AIGW_APP_DEBUG=false
AIGW_APP_KEY=base64:...            # 32 字节随机，换掉即所有凭据失效
AIGW_DB_HOST=127.0.0.1
AIGW_DB_DATABASE=aigw_db
AIGW_DB_USERNAME=aigw_db
AIGW_DB_PASSWORD=...

# 3. 建表
php database/install.php

# 4. 同步网关模型目录
php bin/agent2api-sync.php --base=http://127.0.0.1:3065 --key=sk-a2a-xxx     --panel-user=admin --panel-pass='面板密码' --mode=split

# 5. 给每个新建供应商写账号（否则会报「没有可用账号」）
php bin/agent2api-account.php --provider=agent2api_autoclaw     --key=sk-a2a-xxx --base=http://127.0.0.1:3065     --panel-user=admin --panel-pass='面板密码'
```

### 排障：三种「看起来像代码坏了」其实不是

| 现象 | 真因 |
|---|---|
| 回复报 `所有启用中的账号都被限制器拦下` | 网关侧账号池空了或余额读数为 0，与 AIGW 无关；导入账号或调限制器 |
| 流式只看到 `: waiting` 一直转 | 网关在排队等账号租约（同账号并发上限），不是 SSE 断了；等占用者释放即可 |
| 报 `供应商 X 当前没有可用账号` | 该供应商行没有账号，或 `AIGW_APP_KEY` 换过导致旧凭据解不开，重跑第 5 步 |
