#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
docs="$root/docs/buscador.md"
admin="$root/admin/buscador.php"
api="$root/api/agent/buscador.php"
core="$root/includes/buscador-core.php"
claude_installer="$root/ops/buscador/install-claude-bridge-user-service.sh"
codex_installer="$root/ops/buscador/install-codex-bridge-user-service.sh"

test -f "$core"
test -f "$api"
test -f "$admin"
test -f "$docs"
test -x "$claude_installer"
test -x "$codex_installer"
test -f "$root/ops/buscador/claude-bridge.mjs"
test -f "$root/ops/buscador/codex-bridge.mjs"

node --check "$root/ops/buscador/claude-bridge.mjs"
node --check "$root/ops/buscador/codex-bridge.mjs"

grep -q "endpoint' => 'buscador'" "$api"
grep -Fq "const API='/api/agent/buscador.php'" "$admin"
grep -Fq "j.endpoint!=='buscador'" "$admin"
grep -Fq 'Fable: desabilitado' "$admin"
grep -q 'codex_chatgpt' "$docs"
grep -q 'claude_code' "$docs"
grep -q 'vertex_oauth' "$docs"
grep -Fq 'OpenAI: `gpt-5.6-terra`, effort `medium`;' "$docs"
grep -Fq 'Anthropic: `claude-sonnet-5`, effort `medium`;' "$docs"
grep -Fq 'Gemini: `gemini-2.5-flash`, thinking `MEDIUM`;' "$docs"

! grep -q "getenv('OPENAI_API_KEY')" "$core"
! grep -q "getenv('ANTHROPIC_API_KEY')" "$core"
! grep -q "getenv('OPENROUTER_API_KEY')" "$core"

echo "BUSCADOR_THREE_PROVIDER_RUNTIME_CONTRACT_TEST=PASS"
