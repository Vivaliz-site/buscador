#!/usr/bin/env bash
set -Eeuo pipefail

export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"

service="shopvivaliz-buscador-claude-bridge.service"
service_dir="$HOME/.config/systemd/user"
unit="$service_dir/$service"
runtime="$HOME/.local/share/shopvivaliz-buscador-claude"
workspace="$runtime/workspace"
bridge="/home/ubuntu/shopvivaliz-deploy/current/ops/buscador/claude-bridge.mjs"
claude_bin="/home/ubuntu/.local/bin/claude"
env_path="/home/ubuntu/shopvivaliz-deploy/shared/.env"
credentials_path="/home/ubuntu/.claude/.credentials.json"
claude_dir="$HOME/.claude"
user_memory="$claude_dir/CLAUDE.md"
bootstrap_doc="/home/ubuntu/shopvivaliz-deploy/current/docs/knowledge/claude-vm-bootstrap.md"
bootstrap_link="$claude_dir/shopvivaliz-bootstrap.md"
node_bin="$(command -v node)"

test -n "$node_bin"
test -f "$bridge"
test -x "$claude_bin"
test -f "$env_path"
test -f "$bootstrap_doc"
mkdir -p "$service_dir" "$workspace" "$claude_dir"
chmod 700 "$runtime" "$workspace" "$claude_dir"

# Claude Code user memory is global across projects. Keep a stable import in
# ~/.claude/CLAUDE.md and point it at the canonical bootstrap from the active
# immutable release. Preserve any pre-existing user memory.
ln -sfn "$bootstrap_doc" "$bootstrap_link"
touch "$user_memory"
chmod 600 "$user_memory"
bootstrap_import='@~/.claude/shopvivaliz-bootstrap.md'
if ! grep -Fqx "$bootstrap_import" "$user_memory"; then
  {
    printf '\n# ShopVivaliz managed VM bootstrap\n'
    printf '%s\n' "$bootstrap_import"
  } >>"$user_memory"
fi

tmp="$(mktemp "$service_dir/.${service}.XXXXXX")"
trap 'rm -f "$tmp"' EXIT
cat >"$tmp" <<EOF
[Unit]
Description=ShopVivaliz Buscador Claude Code account bridge
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=$workspace
ExecStart=$node_bin $bridge
Environment=BUSCADOR_CLAUDE_BRIDGE_PORT=17657
Environment=BUSCADOR_CLAUDE_BIN=$claude_bin
Environment=BUSCADOR_CLAUDE_ENV_PATH=$env_path
Environment=BUSCADOR_CLAUDE_HOME=/home/ubuntu
Environment=BUSCADOR_CLAUDE_CREDENTIALS_PATH=$credentials_path
Environment=BUSCADOR_CLAUDE_WORKDIR=$workspace
Environment=HOME=/home/ubuntu
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
if systemctl --user is-active --quiet "$service" 2>/dev/null; then
  systemctl --user stop "$service" >/dev/null 2>&1
fi
if command -v fuser >/dev/null 2>&1; then
  fuser_rc=0
  fuser -k 17657/tcp >/dev/null 2>&1 || fuser_rc=$?
  # fuser exits 1 when no process holds the port — that is expected and not an error
  if [ "$fuser_rc" -gt 1 ]; then
    printf 'fuser: unexpected exit %d\n' "$fuser_rc" >&2
    exit "$fuser_rc"
  fi
fi
systemctl --user enable "$service" >/dev/null
systemctl --user start "$service"

health_url="http://127.0.0.1:17657/health"
for _ in $(seq 1 50); do
  if body="$(curl -fsS --max-time 3 "$health_url" 2>/dev/null)"; then
    if printf '%s' "$body" | grep -q '"endpoint":"buscador-claude-bridge"' \
      && printf '%s' "$body" | grep -q '"ok":true' \
      && printf '%s' "$body" | grep -q '"authenticated":true'; then
      printf '%s\n' "BUSCADOR_CLAUDE_BRIDGE_SERVICE=ACTIVE"
      printf '%s\n' "BUSCADOR_CLAUDE_BRIDGE_HEALTH=VERIFIED"
      exit 0
    fi
  fi
  sleep 1
done

printf '%s\n' "BUSCADOR_CLAUDE_BRIDGE_HEALTH=FAILED" >&2
if ! systemctl --user --no-pager --full status "$service" >&2; then
  printf 'systemctl status: service not found or failed to query\n' >&2
fi
exit 1
