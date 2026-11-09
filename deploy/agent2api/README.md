# Agent2API 网关 —— 服务器侧三步走

这三个文件只干一件事：在**同一台服务器**上把 Agent2API 网关（含两家插件）装好，
好让 AIGW 能通过 `http://127.0.0.1:3065` 把它当上游用。

```bash
cd /www/wwwroot/aigw/deploy/agent2api

# 1) 装网关（自动选 amd64/arm64，sha256 校验，systemd 常驻）
bash install-agent2api.sh

# 2) 装两家插件 → 37 个 provider
bash install-plugins.sh admin '面板密码'

# 3) 面板建「网关 Key」，然后回到项目根同步供应商
cd /www/wwwroot/aigw
php bin/agent2api-sync.php \
  --base=http://127.0.0.1:3065 --key=a2a_xxx \
  --panel-user=admin --panel-pass='面板密码' --mode=split
```

## 常用参数

| 变量 | 默认 | 说明 |
|---|---|---|
| `A2A_VERSION` | latest | 指定版本，如 `v3.4.2` |
| `A2A_PREFIX` | `/opt/agent2api` | 安装目录（`dist/` `data/` `logs/`） |
| `A2A_BIND` | `127.0.0.1` | 监听地址；跨机访问要 `0.0.0.0` + 防火墙 |
| `A2A_PORT` | `3065` | 健康检查用 |
| `A2A_BASE` | `http://127.0.0.1:3065` | 装插件时的网关地址 |

## 装完检查

```bash
systemctl status agent2api --no-pager
curl -s http://127.0.0.1:3065/health
curl -s http://127.0.0.1:3065/v1/models | head -c 400
tail -n 50 /opt/agent2api/logs/server.log
```

## 面板打不开 / 插件装不上

1. **面板 502/无响应** → `systemctl status agent2api`；`logs/server.log` 里看是否端口被占。
2. **插件列表空、报 `连接 GitHub 源超时`** → 网关进程走 IPv4；给服务器配代理，或确认 DNS 没解析到 IPv6。
   网关每 5 分钟自动重试索引，网络通了会自愈。
3. **`plugin requires target x86_64-...`** → 用 `install-plugins.sh`，它会读 `catalog.current` 自动匹配架构，别手动传 zip。
4. **AIGW 侧报 `model_api_key_required`** → 那是网关没收到 Key：检查 `api_keys` 填的是**网关 Key**（`/v1` 用的那把），不是面板密码。
5. **AIGW 侧报 401** → 面板令牌过期（2h）；`Agent2ApiClient` 会自动重登一次，仍 401 说明 `panel_user/panel_password` 不对。

## 卸载

```bash
systemctl disable --now agent2api
rm -f /etc/systemd/system/agent2api.service
rm -rf /opt/agent2api     # ⚠️ data/ 里有账号与插件，先备份
```
