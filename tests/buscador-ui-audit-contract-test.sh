#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
ui_audit="$root/scripts/buscador-ui-audit.mjs"
admin="$root/admin/buscador.php"

test -f "$ui_audit"
node --check "$ui_audit"

grep -q 'BUSCADOR_UI_AUDIT=PASS' "$ui_audit"
grep -q 'deep_research' "$ui_audit"
grep -q 'Pesquisa independente' "$ui_audit"
grep -q 'Contraditório' "$ui_audit"
grep -q 'Convergência' "$ui_audit"
grep -q 'Síntese de consenso' "$ui_audit"
grep -q 'gemini-3.5-flash' "$ui_audit"
! grep -q 'gemini-2.5-flash' "$ui_audit"
grep -q 'BUSCADOR_BROWSER_HEADLESS' "$ui_audit"
grep -q 'headless: browserHeadless' "$ui_audit"
grep -Fq "page.on('console'" "$ui_audit"
grep -Fq "page.on('pageerror'" "$ui_audit"
grep -Fq "page.on('requestfailed'" "$ui_audit"
grep -Fq 'response.status() >= 500' "$ui_audit"
grep -Fq 'page.reload' "$ui_audit"
grep -Fq 'browserMode:' "$ui_audit"
grep -Fq "const API='/api/agent/buscador.php'" "$admin"
grep -Fq "j.endpoint!=='buscador'" "$admin"
grep -Fq 'healthState(p)' "$admin"

echo "BUSCADOR_UI_AUDIT_CONTRACT=PASS"
