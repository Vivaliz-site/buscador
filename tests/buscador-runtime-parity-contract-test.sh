#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
local_api="$root/api/agent/buscador.php"
remote_api="$(mktemp)"
trap 'rm -f "$remote_api"' EXIT

curl -fsSL --retry 3 --retry-all-errors   "https://raw.githubusercontent.com/Vivaliz-site/site-shopvivaliz/main/api/agent/buscador.php"   -o "$remote_api"

for marker in   "function svais_api_acquire_runtime_cycle_lock"   "ai-squad-deploy-gate.lock"   "ai-squad-runtime.lock"   'flock($gate, LOCK_SH | LOCK_NB)'   'flock($runtime, LOCK_SH)'   "svais_api_release_runtime_cycle_lock"   "provider_phase_status"   "complete_provider_coverage"   "'endpoint' => 'buscador'"
do
  grep -Fq "$marker" "$local_api"
  grep -Fq "$marker" "$remote_api"
done

grep -Fq "Vivaliz-site/site-shopvivaliz" "$root/README.md"

echo "BUSCADOR_RUNTIME_PARITY_CONTRACT=PASS"
