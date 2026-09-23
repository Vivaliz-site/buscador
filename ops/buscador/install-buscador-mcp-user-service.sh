#!/usr/bin/env bash
set -Eeuo pipefail

export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"

service="shopvivaliz-buscador-mcp.service"
service_dir="$HOME/.config/systemd/user"
unit="$service_dir/$service"
runtime="$HOME/.local/share/shopvivaliz-buscador-mcp"
releases="$runtime/releases"
env_dir="$HOME/.config/shopvivaliz"
env_path="$env_dir/buscador-mcp.env"
source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../buscador-mcp" && pwd)"
node_bin="$(command -v node)"
sha="$(git -C "$source_dir/../.." rev-parse --short=12 HEAD 2>/dev/null || printf manual)"
release_id="$(date -u +%Y%m%dT%H%M%SZ)-$sha"
release="$releases/$release_id"

test -n "$node_bin"
test -f "$source_dir/package.json"
test -f "$source_dir/package-lock.json"
test -f "$source_dir/server.mjs"
test -f "$source_dir/lib.mjs"
test -f "$env_path"

linger="$(loginctl show-user "$(id -un)" -p Linger --value 2>/dev/null || true)"
if [[ "$linger" != "yes" ]]; then
  echo "BUSCADOR_MCP_INSTALL_ERROR=linger_required" >&2
  exit 69
fi
grep -Eq '^BUSCADOR_MCP_KEY=.+' "$env_path"

mkdir -p "$service_dir" "$releases" "$env_dir"
chmod 700 "$runtime" "$releases" "$env_dir"
chmod 600 "$env_path"

mkdir "$release"
install -m 600 "$source_dir/package.json" "$release/package.json"
install -m 600 "$source_dir/package-lock.json" "$release/package-lock.json"
install -m 600 "$source_dir/server.mjs" "$release/server.mjs"
install -m 600 "$source_dir/lib.mjs" "$release/lib.mjs"

(
  cd "$release"
  npm ci --omit=dev --ignore-scripts --no-audit --no-fund
)

ln -s "$release" "$runtime/current.new"
mv -Tf "$runtime/current.new" "$runtime/current"

tmp="$(mktemp "$service_dir/.${service}.XXXXXX")"
trap 'rm -f "$tmp"' EXIT
cat >"$tmp" <<EOF
[Unit]
Description=ShopVivaliz Buscador private MCP server
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=$runtime/current
ExecStart=$node_bin $runtime/current/server.mjs
EnvironmentFile=$env_path
Environment=BUSCADOR_MCP_HOST=127.0.0.1
Environment=BUSCADOR_MCP_PORT=8787
Environment=HOME=/home/ubuntu
Environment=PATH=/usr/local/bin:/usr/bin:/bin
Restart=on-failure
RestartSec=5
TimeoutStopSec=20
KillMode=mixed
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=read-only
ReadWritePaths=$runtime
UMask=0077

[Install]
WantedBy=default.target
EOF

chmod 600 "$tmp"
mv "$tmp" "$unit"
trap - EXIT

systemctl --user daemon-reload
systemctl --user enable "$service" >/dev/null
systemctl --user restart "$service"

for _ in $(seq 1 30); do
  if body="$(curl -fsS --max-time 2 http://127.0.0.1:8787/healthz 2>/dev/null)"; then
    if printf '%s' "$body" | grep -q '"service":"buscador-mcp"' \
      && printf '%s' "$body" | grep -q '"ok":true'; then
      printf '%s\n' "BUSCADOR_MCP_SERVICE=ACTIVE"
      printf '%s\n' "BUSCADOR_MCP_HEALTH=PASS"
      printf '%s\n' "BUSCADOR_MCP_RELEASE=$release_id"
      exit 0
    fi
  fi
  sleep 1
done

printf '%s\n' "BUSCADOR_MCP_HEALTH=FAILED" >&2
systemctl --user --no-pager --full status "$service" >&2 || true
exit 1
