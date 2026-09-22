#!/usr/bin/env bash
set -euo pipefail

export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"

service="shopvivaliz-buscador-codex-bridge.service"
service_dir="$HOME/.config/systemd/user"
unit="$service_dir/$service"
runtime="$HOME/.local/share/shopvivaliz-buscador-codex"
workspace="$runtime/workspace"
bridge="/home/ubuntu/shopvivaliz-deploy/current/ops/buscador/codex-bridge.mjs"
codex_real="/home/ubuntu/.local/lib/node_modules/@openai/codex/bin/codex.js"
business_home="/home/ubuntu/.codex-business"
node_bin="$(command -v node)"

test -n "$node_bin"
test -f "$bridge"
test -x "$codex_real"
mkdir -p "$service_dir" "$workspace"
chmod 700 "$runtime" "$workspace"

tmp="$(mktemp "$service_dir/.${service}.XXXXXX")"
trap 'rm -f "$tmp"' EXIT
cat >"$tmp" <<EOF
[Unit]
Description=ShopVivaliz Buscador Codex ChatGPT bridge
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=$workspace
ExecStart=$node_bin $bridge
Environment=BUSCADOR_CODEX_BRIDGE_PORT=17656
Environment=BUSCADOR_CODEX_REAL=$codex_real
Environment=BUSCADOR_CODEX_BUSINESS_HOME=$business_home
Environment=BUSCADOR_CODEX_WORKDIR=$workspace
Environment=BUSCADOR_CODEX_WEB_SEARCH_MODE=live
Environment=PATH=/home/ubuntu/.local/bin:/usr/local/bin:/usr/bin:/bin
Restart=always
RestartSec=5
TimeoutStopSec=15
KillMode=mixed
NoNewPrivileges=yes
PrivateTmp=yes
UMask=0077

[Install]
WantedBy=default.target
EOF

chmod 600 "$tmp"
mv "$tmp" "$unit"
trap - EXIT

systemctl --user daemon-reload
systemctl --user enable --now "$service"
systemctl --user restart "$service"

health_url="http://127.0.0.1:17656/health"
for _ in $(seq 1 30); do
  if body="$(curl -fsS --max-time 5 "$health_url" 2>/dev/null)"; then
    if printf '%s' "$body" | grep -q '"ok":true'; then
      printf '%s\n' "BUSCADOR_CODEX_BRIDGE_SERVICE=ACTIVE"
      printf '%s\n' "BUSCADOR_CODEX_BRIDGE_HEALTH=OK"
      exit 0
    fi
  fi
  sleep 1
done

printf '%s\n' "BUSCADOR_CODEX_BRIDGE_HEALTH=FAILED" >&2
if ! systemctl --user --no-pager --full status "$service" >&2; then
  printf '%s\n' "BUSCADOR_CODEX_BRIDGE_STATUS=UNAVAILABLE" >&2
fi
exit 1
