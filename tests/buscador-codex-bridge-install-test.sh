#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
installer="$root/ops/buscador/install-codex-bridge-user-service.sh"

test -f "$installer"
bash -n "$installer"

grep -Fq 'shopvivaliz-buscador-codex-bridge.service' "$installer"
grep -Fq '127.0.0.1' "$root/ops/buscador/codex-bridge.mjs"
grep -Fq '/health' "$installer"
grep -Fq 'XDG_RUNTIME_DIR' "$installer"
grep -Fq 'systemctl --user daemon-reload' "$installer"
grep -Fq 'systemctl --user enable --now' "$installer"
grep -Fq 'BUSCADOR_CODEX_REAL=' "$installer"
grep -Fq 'BUSCADOR_CODEX_BUSINESS_HOME=' "$installer"
grep -Fq 'BUSCADOR_CODEX_WEB_SEARCH_MODE=live' "$installer"
grep -Fq 'ExecStart=' "$installer"

if grep -Eq 'auth\.json|OPENAI_API_KEY|CODEX_API_KEY' "$installer"; then
  echo "FAIL: installer must not copy or expose auth/key material" >&2
  exit 1
fi

echo 'BUSCADOR_CODEX_BRIDGE_INSTALL_TEST=PASS'
