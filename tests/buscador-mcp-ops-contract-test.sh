#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
installer="$root/ops/buscador/install-buscador-mcp-user-service.sh"
ci="$root/.github/workflows/ci.yml"

test -f "$root/ops/buscador-mcp/package-lock.json"
grep -Fq 'npm ci --omit=dev --ignore-scripts --no-audit --no-fund' "$installer"
grep -Fq 'loginctl show-user' "$installer"
grep -Fq 'BUSCADOR_MCP_HOST=127.0.0.1' "$installer"
grep -Fq 'npm ci --ignore-scripts --no-audit --no-fund --prefix ops/buscador-mcp' "$ci"
! grep -Fq 'npm install --ignore-scripts --no-audit --no-fund --prefix ops/buscador-mcp' "$ci"
echo BUSCADOR_MCP_OPS_CONTRACT=PASS
