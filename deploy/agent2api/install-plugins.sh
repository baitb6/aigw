#!/usr/bin/env bash
#
# Agent2API 插件一键安装（两家官方插件：providers / web-providers）
#
#   bash install-plugins.sh admin '你的面板密码'
#   A2A_BASE=http://127.0.0.1:3065 bash install-plugins.sh admin '密码'
#
# 干什么：
#   登录面板 → GET /api/plugins 读索引 → 对缺的插件 POST /api/plugins/install-from-release
#   网关会按自己的 target（如 aarch64-unknown-linux-gnu / x86_64-unknown-linux-gnu）
#   去官方 Release 拉对应架构的包，**不需要你手动传 zip**，也不会装错架构。
#
# 为什么要脚本：插件是原生 ELF、带 ed25519 签名，只能由网关自己验签落盘；
# 手动解压到 data/plugins 目录不生效（网关不认）。

set -euo pipefail

BASE="${A2A_BASE:-http://127.0.0.1:3065}"
USER_NAME="${1:-${A2A_USER:-admin}}"
PASS="${2:-${A2A_PASS:-}}"

log()  { printf '\033[36m[plugins]\033[0m %s\n' "$*"; }
warn() { printf '\033[33m[plugins]\033[0m %s\n' "$*"; }
die()  { printf '\033[31m[plugins]\033[0m %s\n' "$*" >&2; exit 1; }

[ -n "$PASS" ] || die "用法：bash install-plugins.sh <面板账号> <面板密码>"
command -v curl >/dev/null 2>&1 || die "缺少 curl"
command -v python3 >/dev/null 2>&1 || die "缺少 python3（用于解析 JSON）"

TOKEN=$(curl -fsS -m 20 -X POST "${BASE}/api/panel/login" \
  -H 'Content-Type: application/json' \
  -d "{\"username\":\"${USER_NAME}\",\"password\":\"${PASS}\"}" \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["accessToken"])') \
  || die "面板登录失败：账号或密码不对？"

log "面板登录成功"

api_get()  { curl -fsS -m 30 -H "x-panel-token: ${TOKEN}" "${BASE}$1"; }
api_post() { curl -fsS -m 60 -X POST -H "x-panel-token: ${TOKEN}" -H 'Content-Type: application/json' -d "$2" "${BASE}$1"; }

STATE=$(api_get /api/plugins)
TARGET=$(printf '%s' "$STATE" | python3 -c 'import sys,json;d=json.load(sys.stdin)["data"];print(d.get("catalog",{}).get("current") or d.get("target",""))')
[ -n "$TARGET" ] || die "网关没给出 target 平台标识，请升级网关到 3.4.x"

log "当前平台标识：${TARGET}"

# 已装 / 可装 两个清单（先落盘再解析，避免 heredoc 与管道抢 stdin）
printf '%s' "$STATE" > /tmp/a2a_plugins_state.json
python3 - <<'PY' > /tmp/a2a_plugins_plan.txt
import json
data = json.loads(open('/tmp/a2a_plugins_state.json', encoding='utf-8').read())['data']
installed = {p.get('id'): p for p in (data.get('packs') or [])}
catalog   = ((data.get('catalog') or {}).get('plugins') or [])
for item in catalog:
    pid = item.get('id')
    if not pid:
        continue
    cur = installed.get(pid)
    if cur is None:
        print(f"INSTALL\t{pid}\t{item.get('version') or ''}\t{(item.get('description') or '')[:60]}")
    else:
        print(f"OK\t{pid}\t{cur.get('version') or ''}\t{'enabled' if cur.get('enabled') else 'disabled'}")
PY

if [ ! -s /tmp/a2a_plugins_plan.txt ]; then
  warn "索引里没有可安装插件（索引可能是空的：等 5 分钟自动重试，或检查网关能否访问 GitHub）"
  exit 1
fi

INSTALLED=0
while IFS=$'\t' read -r action pid ver desc; do
  case "$action" in
    OK)
      log "已存在：${pid} ${ver}（${desc}）"
      ;;
    INSTALL)
      log "安装：${pid} ${ver}"
      if api_post /api/plugins/install-from-release \
           "{\"id\":\"${pid}\",\"target\":\"${TARGET}\"}" > /tmp/a2a_install_resp.json 2>&1; then
        INSTALLED=$((INSTALLED + 1))
      else
        warn "  ✗ 安装请求失败：$(head -c 300 /tmp/a2a_install_resp.json 2>/dev/null)"
      fi
      ;;
  esac
done < /tmp/a2a_plugins_plan.txt

if [ "$INSTALLED" -gt 0 ]; then
  log "已提交 ${INSTALLED} 个安装任务，等网关落盘（最多 120 秒）…"
  for i in $(seq 1 24); do
    sleep 5
    PACKS=$(api_get /api/plugins | python3 -c 'import sys,json;d=json.load(sys.stdin)["data"];print(len(d.get("packs") or []))')
    log "  已装插件数：${PACKS}"
    if [ "$PACKS" -ge "$INSTALLED" ] && [ "$PACKS" -gt 0 ]; then
      break
    fi
  done
fi

echo
log "最终状态："
api_get /api/plugins | python3 -c '
import sys, json
d = json.load(sys.stdin)["data"]
for p in d.get("packs") or []:
    provs = (p.get("host") or {}).get("providers") or []
    print("  - %-26s v%-8s %-8s providers=%d" % (
        p.get("id"), p.get("version"), "enabled" if p.get("enabled") else "disabled", len(provs)))
host = ((d.get("host") or {}).get("providers") or [])
print("  合计 provider：%d 个" % len(host))
'
