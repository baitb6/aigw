#!/usr/bin/env bash
#
# Agent2API 网关 —— Linux 服务器一键安装
#
#   bash install-agent2api.sh                  # 装到 /opt/agent2api，只听 127.0.0.1:3065
#   A2A_BIND=0.0.0.0 bash install-agent2api.sh # 允许公网直连（面板务必先设强口令）
#   A2A_VERSION=v3.4.2 bash install-agent2api.sh
#
# 做的事：
#   1) 按 CPU 架构（amd64 / arm64）下官方 Release 的 agent2api-server；
#   2) sha256 校验（官方同目录有 .sha256 资产）；
#   3) 解到 /opt/agent2api/{dist,data,logs}，生成 start.sh；
#   4) 有 systemd 就注册 agent2api.service（开机自启 + 崩溃自动重启），没有就 nohup 拉起。
#
# 幂等：重复执行只替换二进制，data/ 里的数据库与插件保持不动。

set -euo pipefail

VERSION="${A2A_VERSION:-latest}"
PREFIX="${A2A_PREFIX:-/opt/agent2api}"
BIND="${A2A_BIND:-127.0.0.1}"
PORT="${A2A_PORT:-3065}"
REPO="aimod-cc/Agent2API"

log()  { printf '\033[36m[agent2api]\033[0m %s\n' "$*"; }
warn() { printf '\033[33m[agent2api]\033[0m %s\n' "$*"; }
die()  { printf '\033[31m[agent2api]\033[0m %s\n' "$*" >&2; exit 1; }

case "$(uname -m)" in
  x86_64|amd64)  ARCH=amd64 ;;
  aarch64|arm64) ARCH=arm64 ;;
  *) die "不支持的 CPU 架构：$(uname -m)（官方只发 amd64 / arm64）" ;;
esac

for bin in curl tar sha256sum; do
  command -v "$bin" >/dev/null 2>&1 || die "缺少命令：$bin"
done

if [ "$VERSION" = "latest" ]; then
  VERSION=$(curl -fsSL "https://api.github.com/repos/${REPO}/releases/latest" \
    | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)
  [ -n "$VERSION" ] || die "取最新版本号失败（可能是网络/代理问题），可显式指定 A2A_VERSION=v3.4.2"
fi

URL="https://github.com/${REPO}/releases/download/${VERSION}/agent2api-server-linux-${ARCH}.tar.gz"
log "版本 ${VERSION} / 架构 ${ARCH}"
log "下载 ${URL}"

mkdir -p "${PREFIX}/dist" "${PREFIX}/data" "${PREFIX}/logs"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# 断点续传重试：GitHub 在国内经常半路断，-C - 接上继续
for i in 1 2 3 4 5; do
  if curl -fL --retry 3 --retry-delay 2 --connect-timeout 20 -C - \
       -o "${TMP}/pkg.tar.gz" "$URL"; then
    break
  fi
  warn "第 ${i} 次下载中断，2 秒后续传…"
  sleep 2
done
[ -s "${TMP}/pkg.tar.gz" ] || die "下载失败；可先在本地下好，用 A2A_LOCAL_PKG=/path/pkg.tar.gz 重跑"

if curl -fsSL -o "${TMP}/pkg.sha256" "${URL}.sha256" 2>/dev/null; then
  want=$(awk 'NF{print $1; exit}' "${TMP}/pkg.sha256")
  got=$(sha256sum "${TMP}/pkg.tar.gz" | awk '{print $1}')
  if [ "$want" = "$got" ]; then
    log "sha256 校验通过"
  else
    die "sha256 不匹配：期望 ${want}，实际 ${got}"
  fi
else
  warn "未取到官方 .sha256，跳过校验"
fi

tar -xzf "${TMP}/pkg.tar.gz" -C "$TMP"
# 包内可能是 dist/ 目录，也可能直接摊平，两种都兼容
if [ -d "${TMP}/dist" ]; then
  cp -a "${TMP}/dist/." "${PREFIX}/dist/"
else
  cp -a "${TMP}/." "${PREFIX}/dist/"
fi
chmod +x "${PREFIX}/dist/agent2api-server"
log "已解包到 ${PREFIX}/dist"

cat > "${PREFIX}/start.sh" <<SH
#!/usr/bin/env bash
# 由 install-agent2api.sh 生成
set -euo pipefail
DIR="\$(cd "\$(dirname "\$0")" && pwd)"
export AGENT2API_HOST="\${AGENT2API_HOST:-${BIND}}"
export AGENT2API_PROXY_HOME="\${AGENT2API_PROXY_HOME:-\$DIR/data}"
export AGENT2API_UI_DIR="\${AGENT2API_UI_DIR:-\$DIR/dist/ui}"
export AGENT2API_CAPTCHA_ENABLED="\${AGENT2API_CAPTCHA_ENABLED:-0}"
exec "\$DIR/dist/agent2api-server"
SH
chmod +x "${PREFIX}/start.sh"

if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
  cat > /etc/systemd/system/agent2api.service <<UNIT
[Unit]
Description=Agent2API Gateway
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=${PREFIX}
Environment=AGENT2API_HOST=${BIND}
Environment=AGENT2API_PROXY_HOME=${PREFIX}/data
Environment=AGENT2API_UI_DIR=${PREFIX}/dist/ui
Environment=AGENT2API_CAPTCHA_ENABLED=0
ExecStart=${PREFIX}/dist/agent2api-server
Restart=always
RestartSec=3
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
UNIT

  systemctl daemon-reload
  systemctl enable agent2api >/dev/null 2>&1 || true
  systemctl restart agent2api
  log "已注册 systemd 服务：systemctl status agent2api"
else
  warn "没有 systemd，改用 nohup 拉起（重启机器后需手动再跑一次 ${PREFIX}/start.sh）"
  pkill -f "agent2api-server" 2>/dev/null || true
  sleep 1
  nohup "${PREFIX}/start.sh" > "${PREFIX}/logs/server.log" 2>&1 &
  log "已启动，pid=$!"
fi

# 等端口起来（最多 20 秒）
for i in $(seq 1 20); do
  if curl -fsS -m 2 "http://127.0.0.1:${PORT}/health" >/dev/null 2>&1; then
    log "健康检查通过：http://127.0.0.1:${PORT}/health"
    break
  fi
  sleep 1
  [ "$i" = "20" ] && warn "20 秒内没等到 /health，查看日志：${PREFIX}/logs/server.log"
done

cat <<TXT

下一步：
  1) 打开面板注册管理员：http://<服务器IP>:${PORT}/  （若 ${BIND}=127.0.0.1，先用 SSH 隧道：
       ssh -L ${PORT}:127.0.0.1:${PORT} root@<服务器IP>  然后本地开 http://127.0.0.1:${PORT}）
  2) 装两家插件（37 个 provider）：bash install-plugins.sh
  3) 面板「网关 Key」页建一把 Key，填进 AIGW 的供应商配置或跑 bin/agent2api-sync.php

TXT
