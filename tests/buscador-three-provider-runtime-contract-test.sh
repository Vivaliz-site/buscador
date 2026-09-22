#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
workflow="$root/.github/workflows/sync-ai-keys-to-vm.yml"
unit="$root/deploy/systemd/shopvivaliz-squad-claude-bridge.service"
deploy="$root/scripts/deploy-production.sh"
docs="$root/docs/knowledge/buscador.md"
admin="$root/admin/buscador.php"

grep -q 'CLAUDE_CODE_OAUTH_TOKEN:.*secrets.CLAUDE_CODE_OAUTH_TOKEN' "$workflow"
grep -q "'CLAUDE_CODE_OAUTH_TOKEN='" "$workflow"
grep -q 'CLAUDE_CODE_OAUTH_TOKEN' "$workflow"

test -f "$unit"
grep -q '^User=ubuntu$' "$unit"
grep -q 'claude-bridge.mjs' "$unit"
grep -q 'BUSCADOR_CLAUDE_BRIDGE_PORT=17657' "$unit"
grep -q 'BUSCADOR_CLAUDE_ENV_PATH=/home/ubuntu/shopvivaliz-deploy/shared/.env' "$unit"

grep -q 'reconcile_ai_squad_codex_bridge_unit' "$deploy"
grep -q 'install-codex-bridge-user-service.sh' "$deploy"
grep -q 'reconcile_ai_squad_claude_bridge_unit' "$deploy"
grep -q 'shopvivaliz-squad-claude-bridge.service' "$deploy"
python3 - "$deploy" <<'PY'
from pathlib import Path
import sys
text = Path(sys.argv[1]).read_text(encoding="utf-8")
start = text.index('if [ "${REMOTE_SHA:0:8}" = "$ACTIVE_SHA" ]; then')
end = text.index('log INFO "Producao e runtime ja alinhados', start)
block = text[start:end]
for required in ('reconcile_ai_squad_codex_bridge_unit "$CURRENT_LINK"', 'reconcile_ai_squad_claude_bridge_unit "$CURRENT_LINK"'):
    if required not in block:
        raise SystemExit(f"FAIL: aligned-release path missing {required}")
PY
grep -q 'claude_code' "$docs"
grep -q 'vertex_oauth' "$docs"
grep -Fq 'OpenAI: `gpt-5.6-terra`, effort `medium`;' "$docs"
grep -Fq 'Anthropic: `claude-sonnet-5`, effort `medium`;' "$docs"
grep -Fq 'Gemini: `gemini-3.5-flash`, thinking `MEDIUM`;' "$docs"
grep -Fq '`BUSCADOR_CODEX_WEB_SEARCH_MODE`' "$docs"
grep -q 'Fable: desabilitado' "$admin"
if grep -q 'Opus 5 primário' "$admin"; then
  echo 'FAIL: admin UI contains stale hard-coded Anthropic model label' >&2
  exit 1
fi

echo "BUSCADOR_THREE_PROVIDER_RUNTIME_CONTRACT_TEST=PASS"
