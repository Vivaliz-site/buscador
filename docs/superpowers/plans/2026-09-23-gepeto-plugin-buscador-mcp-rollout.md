# Gepeto Plugin + Buscador MCP Rollout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish, harden, deploy, connect, and certify the already-merged private Buscador MCP so the migrated Gepeto Plugin can invoke OpenAI + Claude + Gemini through exactly two MCP tools without exposing secrets or creating false consensus.

**Architecture:** The Buscador MCP runs on `always-free-arm-1787907847-26` as a loopback-only Node 24 service on `127.0.0.1:8787`. A Secure MCP Tunnel makes that private server available to the ChatGPT Business workspace, where it is registered as a developer-mode app and mapped into the existing Gepeto Plugin. The canonical ShopVivaliz endpoint remains `https://shopvivaliz.com.br/api/agent/buscador.php`; the Plugin contains workflow skills only, while live provider calls and auth remain server-side.

**Tech Stack:** Node.js 24, `@modelcontextprotocol/*` 2.0.0, Express 5.2.1, Zod 4.6.5, PHP 8.2, GitHub Actions, systemd --user, OpenAI Secure MCP Tunnel, ChatGPT Business Plugins, OpenAI Plugin Creator.

**Spec:** `docs/superpowers/specs/2026-09-22-buscador-mcp-private-app-design.md`

## Global Constraints

- Gepeto + Superpowers are mandatory for every task and resumption.
- Fable is prohibited from all active Buscador presets and transport paths.
- Browser automation for ShopVivaliz uses `always-free-arm-1787907847-26`; Fred-Win and KOCEPSV are not browser fallbacks.
- ChatGPT/OpenAI browser work reuses the canonical persistent profile `ai-squad-chatgpt`; if human verification is required, use the Browser Worker interface and never bypass CAPTCHA/MFA.
- Production web/deploy remains `shopvivaliz-free-a1`; never edit `current/` or an active immutable release directly.
- The MCP server binds only to loopback; do not open port 8787 publicly.
- The MCP exposes exactly `getBuscadorHealth` and `runBuscador`.
- `runBuscador` always sends `stream=false` upstream.
- Full consensus requires OpenAI + Anthropic + Gemini, all required phases for the selected mode, a successful `consensus` event, and a successful `cycle_finished`.
- `BUSCADOR_MCP_KEY` is dedicated to the MCP, never hardcoded, logged, printed, placed in process arguments, issues, PRs, chat, or Plugin files.
- Secure MCP Tunnel control-plane credentials are separate from `BUSCADOR_MCP_KEY`.
- The original migrated Gepeto Plugin identity must be preserved whenever the platform permits an in-place update.
- No completion claim is allowed from code/CI/merge alone; runtime, Plugin, browser E2E, reboot persistence, and the absolute audit certifier must all pass for the same release.
- Any CAPTCHA, human recovery, unavailable third party, or destructive step must be treated according to the absolute audit rules; do not weaken a gate to get green.

## Current Baseline to Revalidate Before Execution

- `Vivaliz-site/buscador` main already contains the MCP implementation and PR #14 is merged.
- `Vivaliz-site/site-shopvivaliz` main and current production code already accept `BUSCADOR_MCP_KEY`.
- Production shared env currently has `GEPETO_ACTION_KEY` but not `BUSCADOR_MCP_KEY`.
- Backend Node is `v24.20.0`, npm is `12.0.2`, `tunnel-client` is not installed, port 8787 is not listening, and no Buscador MCP user service/runtime exists.
- Backend `ubuntu` has `Linger=yes`.
- Backend disk was observed at 82% usage and must be rechecked before installing packages.
- Desktop Commander and Browser Worker were healthy at the last probe; treat that as historical until revalidated.
- The runtime skill namespace `gpt-4d1b51dc23427fe5106bbd896a5b7a0e` is not the backend Plugin ID accepted by Plugin Creator.

## Review Focus

1. **False consensus from inconsistent upstream metadata:** if `cycle_finished.complete_provider_coverage=true` but a required provider/phase is missing or failed, the MCP must return `complete_consensus=false`.
2. **Long-running and failing upstream calls:** timeout, 429, 5xx, redirect, invalid JSON, oversized response, and unreachable upstream must return stable error classes without leaking credentials.
3. **Concurrent expensive runs:** a second overlapping `runBuscador` must fail closed instead of spawning uncontrolled multi-provider cycles; repeated upstream failures must open a bounded circuit breaker.
4. **Plugin migration identity/source drift:** never infer the Plugin backend ID or package contents; export the current release first, preserve every existing dependency, and compare the candidate against that source before updating the migrated identity.
5. **Transport looks green but is unusable:** a locally healthy MCP or a connected tunnel is insufficient; tool discovery, fast cycle, deep-research cycle, skill activation, negative non-activation, reboot persistence, and actual Plugin invocation must all be proven.

---

### Task 1: Harden consensus semantics and input error classification

**Files:**
- Modify: `ops/buscador-mcp/lib.mjs`
- Modify: `ops/buscador-mcp/test/lib.test.mjs`
- Modify: `ops/buscador-mcp/test/protocol.test.mjs`

**Interfaces:**
- Consumes: upstream Buscador JSON with `events`, `provider_status`, and `provider_phase_status`.
- Produces: `normalizeRun(payload, mode)` with `required_phases`, `phase_coverage_ok`, and fail-closed `complete_consensus`.
- Produces: `BuscadorMcpError('invalid_input', reason)` for deterministic validation failures.

- [ ] **Step 1: Start in an isolated worktree from fresh `main`**

Use `superpowers:using-git-worktrees`. Create a new implementation branch from the latest `origin/main`; do not continue development directly on the historical PR #14 branch.

Run:

```bash
git fetch origin main
git rev-parse origin/main
git status --short
```

Expected: clean worktree and a recorded base SHA.

- [ ] **Step 2: Write failing phase-coverage tests**

Add to `ops/buscador-mcp/test/lib.test.mjs`:

```js
const fullResearchPhases = Object.fromEntries(
  ['openai', 'anthropic', 'gemini'].map((provider) => [
    provider,
    {
      research: { status: 'ok' },
      critique: { status: 'ok' },
      converge: { status: 'ok' },
    },
  ]),
);

test('research consensus fails closed when a required phase is not ok', () => {
  const broken = structuredClone(fullResearchPhases);
  broken.gemini.converge = { status: 'error', failure_class: 'timeout' };

  const result = normalizeRun({
    ok: true,
    endpoint: 'buscador',
    events: [
      { type: 'agent_message', provider: 'openai', phase: 'research', ok: true, text: 'a' },
      { type: 'agent_message', provider: 'anthropic', phase: 'research', ok: true, text: 'b' },
      { type: 'agent_message', provider: 'gemini', phase: 'research', ok: true, text: 'c' },
      { type: 'consensus', ok: true, text: 'should not be trusted' },
      {
        type: 'cycle_finished',
        ok: true,
        provider_status: { openai: 'ok', anthropic: 'ok', gemini: 'ok' },
        provider_phase_status: broken,
        complete_provider_coverage: true,
        consensus_available: true,
      },
    ],
  }, 'research');

  assert.equal(result.phase_coverage_ok, false);
  assert.equal(result.complete_consensus, false);
});

test('parallel mode requires only the research phase', () => {
  const phases = Object.fromEntries(
    ['openai', 'anthropic', 'gemini'].map((provider) => [
      provider,
      { research: { status: 'ok' } },
    ]),
  );

  const result = normalizeRun({
    ok: true,
    endpoint: 'buscador',
    events: [
      { type: 'agent_message', provider: 'openai', phase: 'research', ok: true, text: 'a' },
      { type: 'agent_message', provider: 'anthropic', phase: 'research', ok: true, text: 'b' },
      { type: 'agent_message', provider: 'gemini', phase: 'research', ok: true, text: 'c' },
      { type: 'consensus', ok: true, text: 'ok' },
      {
        type: 'cycle_finished',
        ok: true,
        provider_status: { openai: 'ok', anthropic: 'ok', gemini: 'ok' },
        provider_phase_status: phases,
        complete_provider_coverage: true,
        consensus_available: true,
      },
    ],
  }, 'parallel');

  assert.equal(result.phase_coverage_ok, true);
  assert.equal(result.complete_consensus, true);
});
```

- [ ] **Step 3: Run the targeted tests and confirm they fail**

Run:

```bash
npm test --prefix ops/buscador-mcp -- --test-name-pattern='required phase|parallel mode'
```

Expected: FAIL because `normalizeRun` is not mode-aware and does not return `phase_coverage_ok`.

- [ ] **Step 4: Implement explicit mode-to-phase validation**

In `ops/buscador-mcp/lib.mjs`, add:

```js
export const BUSCADOR_PHASES_BY_MODE = Object.freeze({
  parallel: ['research'],
  debate: ['research', 'critique'],
  research: ['research', 'critique', 'converge'],
});

function hasCompletePhaseCoverage(providerPhaseStatus, mode) {
  const phases = BUSCADOR_PHASES_BY_MODE[mode] ?? [];
  return BUSCADOR_PROVIDERS.every((provider) =>
    phases.every((phase) => providerPhaseStatus?.[provider]?.[phase]?.status === 'ok'),
  );
}
```

Change the signature and consensus calculation:

```js
export function normalizeRun(payload, mode = 'research') {
  // existing extraction...
  const providerPhaseStatus = isObject(finished?.provider_phase_status)
    ? finished.provider_phase_status
    : {};
  const requiredPhases = BUSCADOR_PHASES_BY_MODE[mode] ?? [];
  const phaseCoverageOk = hasCompletePhaseCoverage(providerPhaseStatus, mode);

  const completeConsensus = payload?.ok === true
    && payload?.endpoint === 'buscador'
    && allProvidersObserved
    && providerStatusOk
    && phaseCoverageOk
    && consensus !== null
    && finished?.ok === true
    && finished?.complete_provider_coverage === true
    && finished?.consensus_available === true;

  return {
    // existing fields...
    required_phases: requiredPhases,
    phase_coverage_ok: phaseCoverageOk,
    complete_consensus: completeConsensus,
  };
}
```

Call it with the validated mode:

```js
const result = normalizeRun(payload, input.mode);
```

- [ ] **Step 5: Make validation failures use the required error class**

Replace generic validation throws with:

```js
function invalidInput(reason) {
  return new BuscadorMcpError('invalid_input', reason);
}

export function validateRunInput(input) {
  if (!isObject(input)) throw invalidInput('invalid_input');
  const allowed = new Set(['message', 'profile', 'mode']);
  for (const key of Object.keys(input)) {
    if (!allowed.has(key)) throw invalidInput('unknown_field');
  }

  const message = typeof input.message === 'string' ? input.message.trim() : '';
  if (!message || [...message].length > MAX_MESSAGE_CHARS) throw invalidInput('invalid_message');

  const profile = input.profile ?? 'deep_research';
  if (!BUSCADOR_PROFILES.includes(profile)) throw invalidInput('invalid_profile');

  const mode = input.mode ?? 'research';
  if (!BUSCADOR_MODES.includes(mode)) throw invalidInput('invalid_mode');

  return { message, profile, mode };
}
```

Add a test asserting `error.errorClass === 'invalid_input'`.

- [ ] **Step 6: Run the full MCP test suite**

Run:

```bash
npm test --prefix ops/buscador-mcp
```

Expected: all MCP tests PASS.

- [ ] **Step 7: Commit**

```bash
git add ops/buscador-mcp/lib.mjs ops/buscador-mcp/test/lib.test.mjs ops/buscador-mcp/test/protocol.test.mjs
git commit -m "fix: fail closed on incomplete Buscador phases"
```

---

### Task 2: Add bounded concurrency, circuit breaking, and transport regression tests

**Files:**
- Modify: `ops/buscador-mcp/lib.mjs`
- Modify: `ops/buscador-mcp/test/lib.test.mjs`
- Modify: `ops/buscador-mcp/test/protocol.test.mjs`

**Interfaces:**
- Consumes: `BuscadorMcpError.errorClass`.
- Produces: `createRunGuard()` used only around `runBuscador`.
- Produces stable local errors `run_busy` and `upstream_circuit_open`.

- [ ] **Step 1: Write failing tests for overlapping runs and circuit opening**

Add a test that starts one guarded promise, calls the guard again before the first resolves, and asserts the second fails with `run_busy`. Add a second test that causes three consecutive `upstream_error` failures and asserts the next execution fails with `upstream_circuit_open` without calling the wrapped function.

Use a fake `now()` so the cooldown test is deterministic.

- [ ] **Step 2: Run the guard tests and confirm they fail**

Run:

```bash
npm test --prefix ops/buscador-mcp -- --test-name-pattern='run guard|circuit'
```

Expected: FAIL because `createRunGuard` does not exist.

- [ ] **Step 3: Implement the guard**

Add to `lib.mjs`:

```js
export function createRunGuard({
  maxConcurrent = 1,
  failureThreshold = 3,
  cooldownMs = 60_000,
  now = () => Date.now(),
} = {}) {
  let inFlight = 0;
  let consecutiveFailures = 0;
  let openUntil = 0;

  const opensCircuit = new Set([
    'upstream_error',
    'upstream_unreachable',
    'upstream_timeout',
    'upstream_rate_limited',
  ]);

  return {
    async execute(fn) {
      if (openUntil > now()) {
        throw new BuscadorMcpError('upstream_circuit_open', 'upstream_circuit_open');
      }
      if (inFlight >= maxConcurrent) {
        throw new BuscadorMcpError('run_busy', 'run_already_in_progress');
      }

      inFlight += 1;
      try {
        const result = await fn();
        consecutiveFailures = 0;
        openUntil = 0;
        return result;
      } catch (error) {
        if (opensCircuit.has(error?.errorClass)) {
          consecutiveFailures += 1;
          if (consecutiveFailures >= failureThreshold) {
            openUntil = now() + cooldownMs;
          }
        }
        throw error;
      } finally {
        inFlight -= 1;
      }
    },
  };
}
```

Instantiate one guard per MCP server and wrap only the upstream POST path for `runBuscador`.

- [ ] **Step 4: Add transport failure regression tests**

Cover all of these with injected `fetchImpl` responses/errors:

```text
401 -> auth_error
429 -> upstream_rate_limited
500 -> upstream_error
Abort/timeout -> upstream_timeout
network throw -> upstream_unreachable
invalid JSON -> invalid_response
response body > 2,000,000 bytes -> invalid_response / upstream_response_too_large
redirect rejected by fetch -> upstream_unreachable or invalid_response, never followed
known secret embedded in thrown error -> [REDACTED]
```

The test must also assert that logger entries never contain the test secret.

- [ ] **Step 5: Run the complete MCP tests**

```bash
npm test --prefix ops/buscador-mcp
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add ops/buscador-mcp/lib.mjs ops/buscador-mcp/test/lib.test.mjs ops/buscador-mcp/test/protocol.test.mjs
git commit -m "feat: bound Buscador MCP upstream execution"
```

---

### Task 3: Make the MCP build reproducible and the user service reboot-safe

**Files:**
- Create: `ops/buscador-mcp/package-lock.json`
- Modify: `ops/buscador-mcp/package.json`
- Modify: `.github/workflows/ci.yml`
- Modify: `ops/buscador/install-buscador-mcp-user-service.sh`
- Create: `tests/buscador-mcp-ops-contract-test.sh`
- Modify: `docs/buscador-mcp.md`

**Interfaces:**
- Consumes: Node 24 and `ubuntu` user with `Linger=yes`.
- Produces: deterministic `npm ci` installs and a user service that fails preflight if persistence prerequisites are missing.

- [ ] **Step 1: Add a failing ops contract test**

Create `tests/buscador-mcp-ops-contract-test.sh`:

```bash
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
! grep -Fq 'npm install --prefix ops/buscador-mcp' "$ci"
echo BUSCADOR_MCP_OPS_CONTRACT=PASS
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
bash tests/buscador-mcp-ops-contract-test.sh
```

Expected: FAIL because there is no lockfile and the installer/CI still use `npm install`.

- [ ] **Step 3: Generate and commit the lockfile**

Run from the isolated worktree:

```bash
npm install --package-lock-only --ignore-scripts --no-audit --no-fund --prefix ops/buscador-mcp
npm ci --ignore-scripts --no-audit --no-fund --prefix ops/buscador-mcp
```

Expected: deterministic install with no package changes after `npm ci`.

- [ ] **Step 4: Update CI to use `npm ci`**

Replace the MCP install step with:

```yaml
- name: Buscador MCP install
  run: npm ci --ignore-scripts --no-audit --no-fund --prefix ops/buscador-mcp
```

- [ ] **Step 5: Harden the user-service installer**

Before release creation, add:

```bash
linger="$(loginctl show-user "$(id -un)" -p Linger --value 2>/dev/null || true)"
if [[ "$linger" != "yes" ]]; then
  echo "BUSCADOR_MCP_INSTALL_ERROR=linger_required" >&2
  exit 69
fi

test -f "$source_dir/package-lock.json"
```

Copy the lockfile into the immutable release and replace runtime install with:

```bash
npm ci --omit=dev --ignore-scripts --no-audit --no-fund
```

Keep `BUSCADOR_MCP_HOST=127.0.0.1`, `NoNewPrivileges=yes`, `ProtectSystem=strict`, and `UMask=0077`.

- [ ] **Step 6: Clarify liveness vs Buscador health in the runbook**

In `docs/buscador-mcp.md`, state explicitly:

```text
/healthz proves only that the local MCP process is listening.
It never certifies provider health or consensus.
Provider health is proven only by the getBuscadorHealth MCP tool.
```

Document `Linger=yes` as a required preflight.

- [ ] **Step 7: Run repo tests and governance locally**

```bash
npm test --prefix ops/buscador-mcp
bash tests/buscador-mcp-ops-contract-test.sh
bash scripts/absolute-audit-governance-validate.sh
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add ops/buscador-mcp/package.json ops/buscador-mcp/package-lock.json .github/workflows/ci.yml ops/buscador/install-buscador-mcp-user-service.sh tests/buscador-mcp-ops-contract-test.sh docs/buscador-mcp.md
git commit -m "build: make Buscador MCP rollout reproducible"
```

---

### Task 4: Align ShopVivaliz documentation and tests with the migrated Plugin/MCP architecture

**Files in `Vivaliz-site/site-shopvivaliz`:**
- Modify: `docs/knowledge/gepeto.md`
- Modify: `docs/knowledge/buscador.md`
- Modify: `tests/gepeto-buscador-doc-contract-test.php`
- Verify only: `api/agent/buscador.php`
- Verify only: `tests/buscador-mcp-auth-contract-test.php`

**Interfaces:**
- Consumes: existing production auth candidate `BUSCADOR_MCP_KEY`.
- Produces: canonical docs that describe Plugin + MCP as current and the GPT Action schema as legacy.

- [ ] **Step 1: Create an isolated site worktree from fresh `main`**

Use `superpowers:using-git-worktrees`. Confirm no unrelated local changes are touched.

- [ ] **Step 2: Write failing documentation-contract assertions**

Extend `tests/gepeto-buscador-doc-contract-test.php` with assertions equivalent to:

```php
$checks = [
    'gepeto migrated plugin' => str_contains($doc, 'Plugin'),
    'mcp is current integration' => str_contains($doc, 'Buscador MCP'),
    'dedicated mcp key documented' => str_contains($doc, 'BUSCADOR_MCP_KEY'),
    'legacy action is labeled legacy' => str_contains($doc, 'Action') && str_contains($doc, 'legado'),
    'legacy key is not current exclusive auth' =>
        !str_contains($doc, 'usando exclusivamente a credencial de runtime `GEPETO_ACTION_KEY`'),
];
```

- [ ] **Step 3: Run the doc contract and confirm it fails**

```bash
php tests/gepeto-buscador-doc-contract-test.php
```

Expected: FAIL against the current Action-first documentation.

- [ ] **Step 4: Update the canonical docs**

Replace the current `## Action` section with a Plugin/MCP section that states:

```text
Gepeto is migrated to a Plugin.
The current Buscador integration is the private Buscador MCP with
getBuscadorHealth and runBuscador.
BUSCADOR_MCP_KEY exists only in protected runtime configuration.
GEPETO_ACTION_KEY and docs/actions/gepeto-ai-squad.openapi.yaml are retained
only for legacy compatibility/history and are not the current Plugin transport.
```

In `docs/knowledge/buscador.md`, list `BUSCADOR_MCP_KEY` among secret names that must never be logged and document that Plugin MCP calls use `stream=false`.

- [ ] **Step 5: Verify the existing auth implementation rather than duplicating it**

Run:

```bash
php -l api/agent/buscador.php
php tests/buscador-mcp-auth-contract-test.php
php tests/gepeto-buscador-doc-contract-test.php
```

Expected:

```text
BUSCADOR_MCP_AUTH_CONTRACT=PASS
```

and doc contract PASS.

- [ ] **Step 6: Run affected Buscador regression tests**

```bash
php tests/ai-squad-core-test.php
php tests/buscador-reliability-contract-test.php
bash tests/ai-squad-three-provider-runtime-contract-test.sh
bash scripts/absolute-audit-governance-validate.sh
```

Expected: PASS.

- [ ] **Step 7: Commit and open a site PR**

```bash
git add docs/knowledge/gepeto.md docs/knowledge/buscador.md tests/gepeto-buscador-doc-contract-test.php
git commit -m "docs: make Gepeto Plugin MCP integration canonical"
```

Push, open a PR, wait for required checks, request review, merge through the normal gate, and later verify production parity after deploy. Do not edit production directly.

---

### Task 5: Provision the dedicated MCP key and deploy the private MCP service

**Files/runtime:**
- Protected production env: `/home/ubuntu/shopvivaliz-deploy/shared/.env`
- Protected backend env: `/home/ubuntu/.config/shopvivaliz/buscador-mcp.env`
- Backend source checkout: `/home/ubuntu/shopvivaliz-buscador-deploy/repo`
- Backend immutable runtime: `/home/ubuntu/.local/share/shopvivaliz-buscador-mcp`

**Interfaces:**
- Consumes: merged Buscador MCP commit and production endpoint accepting `BUSCADOR_MCP_KEY`.
- Produces: local MCP at `http://127.0.0.1:8787/mcp`.

- [ ] **Step 1: Revalidate host health before writes**

On backend, record without secrets:

```bash
hostname
node --version
npm --version
df -h /
free -h
loginctl show-user ubuntu -p Linger --value
ss -ltn | grep ':8787 ' || true
systemctl --user --failed --no-pager || true
```

Expected: correct host, Node 24, `Linger=yes`, port 8787 unused, and sufficient disk headroom. If disk pressure is materially worse than the 82% baseline, investigate before installing.

- [ ] **Step 2: Generate one dedicated key on backend and copy it to production without printing it**

Use a shell variable and stdin-only transfer. The command must not echo the key.

Backend procedure:

```bash
set -Eeuo pipefail
umask 077
mkdir -p "$HOME/.config/shopvivaliz"
key="$(openssl rand -hex 32)"
printf 'BUSCADOR_MCP_KEY=%s\n' "$key" > "$HOME/.config/shopvivaliz/buscador-mcp.env"
chmod 600 "$HOME/.config/shopvivaliz/buscador-mcp.env"

printf '%s' "$key" | ssh   -i "$HOME/.ssh/shopvivaliz-free-a1-monitor"   -o BatchMode=yes   -o StrictHostKeyChecking=yes   ubuntu@10.0.1.112   'python3 -c '"'"'
import os, pathlib, sys, tempfile
p = pathlib.Path("/home/ubuntu/shopvivaliz-deploy/shared/.env")
secret = sys.stdin.read().strip()
lines = p.read_text().splitlines()
out = [line for line in lines if not line.startswith("BUSCADOR_MCP_KEY=")]
out.append("BUSCADOR_MCP_KEY=" + secret)
fd, tmp = tempfile.mkstemp(prefix=".env.", dir=str(p.parent))
os.fchmod(fd, 0o640)
with os.fdopen(fd, "w") as f:
    f.write("\n".join(out) + "\n")
os.replace(tmp, p)
'"'"''
unset key
```

If the monitor key is restricted and cannot perform this write, stop this step and use the already-authorized production RDC session to establish a secure one-time file-transfer path; do not copy the key through chat or logs.

- [ ] **Step 3: Verify only presence and permissions**

Backend:

```bash
grep -Eq '^BUSCADOR_MCP_KEY=.+' "$HOME/.config/shopvivaliz/buscador-mcp.env"
stat -c '%a %U:%G %n' "$HOME/.config/shopvivaliz/buscador-mcp.env"
```

Production:

```bash
grep -Eq '^BUSCADOR_MCP_KEY=.+' /home/ubuntu/shopvivaliz-deploy/shared/.env
stat -c '%a %U:%G %n' /home/ubuntu/shopvivaliz-deploy/shared/.env
```

Expected: backend env 600; production shared env remains 640 `ubuntu:www-data`; no value printed.

- [ ] **Step 4: Prove endpoint auth with the dedicated key**

From backend:

```bash
set -Eeuo pipefail
set -a
source "$HOME/.config/shopvivaliz/buscador-mcp.env"
set +a
code="$(curl -sS -o /tmp/buscador-auth.json -w '%{http_code}'   -H "Authorization: Bearer $BUSCADOR_MCP_KEY"   -H 'Content-Type: application/json'   -d '{}'   https://shopvivaliz.com.br/api/agent/buscador.php)"
printf 'HTTP=%s\n' "$code"
python3 - <<'PY'
import json
p=json.load(open('/tmp/buscador-auth.json'))
print('error='+str(p.get('error')))
PY
rm -f /tmp/buscador-auth.json
unset BUSCADOR_MCP_KEY
```

Expected: `HTTP=422`, `error=invalid_message`. This proves the key was accepted without running providers.

Also call without auth and expect `HTTP=401`.

- [ ] **Step 5: Clone the exact merged Buscador commit and run tests before install**

```bash
set -Eeuo pipefail
repo="$HOME/shopvivaliz-buscador-deploy/repo"
if [[ ! -d "$repo/.git" ]]; then
  mkdir -p "$(dirname "$repo")"
  git clone https://github.com/Vivaliz-site/buscador.git "$repo"
fi
cd "$repo"
git fetch origin main
git checkout --detach origin/main
npm ci --ignore-scripts --no-audit --no-fund --prefix ops/buscador-mcp
npm test --prefix ops/buscador-mcp
bash tests/buscador-mcp-ops-contract-test.sh
```

Expected: all PASS.

- [ ] **Step 6: Install the immutable user service**

```bash
cd "$HOME/shopvivaliz-buscador-deploy/repo"
bash ops/buscador/install-buscador-mcp-user-service.sh
systemctl --user is-enabled shopvivaliz-buscador-mcp.service
systemctl --user is-active shopvivaliz-buscador-mcp.service
ss -ltn | grep '127.0.0.1:8787'
```

Expected: enabled + active; only loopback listener.

- [ ] **Step 7: Verify MCP tool discovery locally**

```bash
curl -fsS http://127.0.0.1:8787/healthz
curl -sS -X POST http://127.0.0.1:8787/mcp   -H 'Content-Type: application/json'   -H 'Accept: application/json, text/event-stream'   -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'   > /tmp/mcp-tools.out
grep -q 'getBuscadorHealth' /tmp/mcp-tools.out
grep -q 'runBuscador' /tmp/mcp-tools.out
rm -f /tmp/mcp-tools.out
```

Expected: exactly the two allowlisted tools.

- [ ] **Step 8: Call `getBuscadorHealth` through the local MCP**

Use JSON-RPC `tools/call` with `profile=fast`. Assert:

```text
ok=true
endpoint=buscador
all_providers_present=true
all_providers_verified=true
```

If any provider is not verified, debug the provider before continuing; do not proceed to a consensus claim.

- [ ] **Step 9: Run one real local `fast/parallel` cycle**

Call `runBuscador` through MCP with a short, deterministic integration prompt. Assert:

```text
complete_consensus=true
providers_observed contains openai, anthropic, gemini
consensus_present=true
cycle_finished_present=true
phase_coverage_ok=true
```

Record duration and non-sensitive metadata only.

---

### Task 6: Create and persist the Secure MCP Tunnel

**Files/runtime:**
- Backend tunnel binary and profile under a dedicated non-secret config directory.
- Backend protected runtime key file for the tunnel control plane.
- User unit: `shopvivaliz-buscador-mcp-tunnel.service`.
- Repo docs: modify `docs/buscador-mcp.md`.
- Repo ops: create `ops/buscador/install-buscador-mcp-tunnel-user-service.sh`.
- Repo test: create `tests/buscador-mcp-tunnel-ops-contract-test.sh`.

**Interfaces:**
- Consumes: local MCP URL `http://127.0.0.1:8787/mcp`, a real OpenAI Platform `tunnel_id`, and a tunnel runtime API key.
- Produces: healthy outbound-only tunnel selectable from the target ChatGPT workspace.

- [ ] **Step 1: Write the tunnel ops contract test before the installer**

The test must require:

```text
127.0.0.1:8787/mcp
tunnel-client doctor
tunnel-client run
EnvironmentFile outside the release
Restart=on-failure
NoNewPrivileges=yes
no hard-coded tunnel_id
no hard-coded API key
```

Run it and confirm FAIL because the installer does not exist.

- [ ] **Step 2: Create the OpenAI-hosted tunnel using the authorized Platform account**

Use the OpenAI Platform tunnel settings through the authorized browser/account. Record only the non-secret `tunnel_id`.

Create a dedicated runtime API key for `tunnel-client` using the OpenAI Platform connector/account workflow. Do not reuse a general application key. The value must go directly into a protected backend env/config file and must never be printed in chat or a process argument.

- [ ] **Step 3: Install `tunnel-client` from the current official release**

Use the latest release source referenced by the OpenAI tunnel settings/documentation; do not hard-code a historical version URL in repo docs.

After install:

```bash
tunnel-client help quickstart
```

Expected: command succeeds.

- [ ] **Step 4: Initialize the HTTP tunnel profile**

The effective profile must point to:

```text
http://127.0.0.1:8787/mcp
```

Use `CONTROL_PLANE_API_KEY` only through an EnvironmentFile or shell environment, never on the command line.

Run:

```bash
tunnel-client doctor --profile buscador-private --explain
```

Expected: healthy/ready with local MCP reachable and control plane authenticated.

- [ ] **Step 5: Implement the user-service installer and systemd unit**

The installer should create a unit equivalent to:

```ini
[Unit]
Description=ShopVivaliz Buscador Secure MCP Tunnel
After=network-online.target shopvivaliz-buscador-mcp.service
Wants=network-online.target
Requires=shopvivaliz-buscador-mcp.service

[Service]
Type=simple
EnvironmentFile=%h/.config/shopvivaliz/buscador-mcp-tunnel.env
ExecStart=/usr/local/bin/tunnel-client run --profile buscador-private
Restart=on-failure
RestartSec=5
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=read-only
UMask=0077

[Install]
WantedBy=default.target
```

Keep the profile path and control-plane key outside the immutable MCP release.

- [ ] **Step 6: Run contract tests and install**

```bash
bash tests/buscador-mcp-tunnel-ops-contract-test.sh
bash ops/buscador/install-buscador-mcp-tunnel-user-service.sh
systemctl --user is-enabled shopvivaliz-buscador-mcp-tunnel.service
systemctl --user is-active shopvivaliz-buscador-mcp-tunnel.service
```

Expected: PASS, enabled, active.

- [ ] **Step 7: Re-run doctor against the persistent service configuration**

```bash
tunnel-client doctor --profile buscador-private --explain
```

Expected: healthy.

- [ ] **Step 8: Commit tunnel ops code and docs**

```bash
git add ops/buscador/install-buscador-mcp-tunnel-user-service.sh tests/buscador-mcp-tunnel-ops-contract-test.sh docs/buscador-mcp.md
git commit -m "ops: persist private Buscador MCP tunnel"
```

---

### Task 7: Register the Buscador MCP app in ChatGPT Business and discover the migrated Gepeto Plugin identity

**Files/runtime:**
- ChatGPT Business workspace app registration.
- Browser Worker persistent profile `ai-squad-chatgpt`.
- No repo mutation until IDs are proven.

**Interfaces:**
- Consumes: healthy tunnel and `tunnel_id`.
- Produces: registered Buscador MCP app ID and real backend Gepeto Plugin ID.

- [ ] **Step 1: Revalidate the canonical ChatGPT browser profile**

Create a Browser Worker session with:

```json
{
  "persistent": true,
  "profile": "ai-squad-chatgpt",
  "label": "gepeto-plugin-rollout",
  "origin": "gepeto-plugin-rollout",
  "ttl_seconds": 7200
}
```

Open ChatGPT. If login/MFA/CAPTCHA is required, expose that same profile through the authorized Browser Worker UI and wait for the human step. Do not create a new profile and do not bypass Cloudflare.

- [ ] **Step 2: Enable/verify developer mode and register the Buscador app**

In ChatGPT Plugins, create a developer-mode connection:

```text
Name: Buscador
Connection: Tunnel
Tunnel: the exact tunnel_id created in Task 6
```

Review discovered tools before saving.

Expected tool names:

```text
getBuscadorHealth
runBuscador
```

No third Buscador tool is acceptable.

- [ ] **Step 3: Capture the registered app ID from the authorized product surface**

Record only the non-secret registered server/app ID returned by ChatGPT/Plugin Management. Validate that it matches the current accepted app-id format for workspace Plugin mapping.

- [ ] **Step 4: Discover the real Gepeto backend Plugin ID**

Open the migrated Gepeto entry under ChatGPT Plugins/Admin and capture the backend Plugin ID from the authorized UI or product metadata.

Do not derive it from:

```text
gpt-4d1b51dc23427fe5106bbd896a5b7a0e
```

because that is the runtime skill namespace, not a valid Plugin Creator backend ID.

- [ ] **Step 5: Prove the IDs with Plugin Creator**

Call Plugin Creator `get_workspace_plugin` using the discovered Gepeto backend ID.

Expected: returns the Gepeto plugin metadata and current release ID.

Then call `get_workspace_plugin_source` and list all files before any update.

---

### Task 8: Version the migrated Gepeto Plugin source and add focused workflows

**Files in new canonical repo `Vivaliz-site/gepeto-plugin`:**
- Preserve every file exported from the current Plugin release.
- Modify the existing core migrated skill rather than deleting it if Plugin Creator cannot delete files.
- Create: `skills/shopvivaliz-ops/SKILL.md`
- Create: `skills/buscador-research/SKILL.md`
- Create: `skills/buscador-research/agents/openai.yaml`
- Create or modify according to exported package: `.app.json`
- Preserve or add portable `plugin.json` only in a format compatible with the exported release/current OpenAI schema.
- Create: `docs/migration-audit.md`
- Create: `docs/testing.md`

**Interfaces:**
- Consumes: exported current Plugin source and registered Buscador app ID.
- Produces: versioned Plugin package that preserves existing apps and adds Buscador workflow/tool dependency.

- [ ] **Step 1: Create the canonical repo only after export**

Create `Vivaliz-site/gepeto-plugin` and commit the exact non-secret exported Plugin source as the baseline migration snapshot.

Do not invent a new manifest before the export is inspected.

- [ ] **Step 2: Write the migration audit**

`docs/migration-audit.md` must record, without secrets:

```text
backend Plugin ID
current release ID
scope
discoverability
export timestamp
file inventory
skill inventory
app/dependency inventory
knowledge inventory
source hash
```

- [ ] **Step 3: Turn the existing migrated core skill into the concise Gepeto core**

Because Plugin Creator updates overlay and cannot delete files, preserve the existing core skill path if deleting/renaming it would leave duplicate skills.

Its frontmatter/body must retain these behaviors:

```markdown
---
name: instructions
description: Core Gepeto workflow for technical development, operations, research, auditing, review, and debugging. Use whenever Gepeto is explicitly invoked.
---

Act as Gepeto, Frederico's technical partner.
Use PT-BR by default.
Never invent access, state, tests, deploys, provider results, or consensus.
Classify material conclusions as COMPROVADO, FALHOU, or INCONCLUSIVO.
Use Superpowers for each material implementation/debugging/review stage when available.
Never expose secrets.
Versioned work is not complete until PR, merge, runtime validation, and cleanup are proven.
Destructive or irreversible actions still require explicit authorization.
```

Keep ShopVivaliz-specific details out of this core skill.

- [ ] **Step 4: Add `shopvivaliz-ops`**

Create:

```markdown
---
name: shopvivaliz-ops
description: Operate, debug, review, deploy, or audit ShopVivaliz repositories and infrastructure using the canonical repo rules, hosts, browser policy, and evidence gates.
---

Use this skill for ShopVivaliz engineering or operations.

Before changing a repository, read its AGENTS.md and the canonical ShopVivaliz rules:
- REGRAS-AGENTES-CENTRALIZADAS.md
- docs/AGENT-REMOTE-ACCESS.md
- docs/knowledge/host-access.md
- docs/knowledge/README.md
- docs/knowledge/agent-rules.md
- documentation for the affected routine

Use the backend VM for browser work. Never use Fred-Win or KOCEPSV as a browser fallback.
Never edit an active immutable production release.
Preserve unrelated changes and check for concurrent work.
For debugging, prove root cause before fixing.
For audited work, continue remediation until APTO or a proven external blocker.
```

- [ ] **Step 5: Add `buscador-research`**

Create:

```markdown
---
name: buscador-research
description: Use the ShopVivaliz Buscador for multi-AI research, contradiction, comparison, or consensus across OpenAI, Claude, and Gemini.
---

When the request needs multi-provider research or consensus:

1. Call getBuscadorHealth first.
2. Require ok=true, endpoint=buscador, all_providers_present=true, and all_providers_verified=true.
3. For short checks use profile=fast and mode=parallel.
4. For complex research use profile=deep_research and mode=research.
5. Call runBuscador only after health passes.
6. Declare three-provider consensus only when complete_consensus=true,
   providers_observed includes openai, anthropic, and gemini,
   phase_coverage_ok=true, consensus_present=true, and cycle_finished_present=true.
7. If a provider, phase, tunnel, or tool fails, report the result as partial/INCONCLUSIVO.
8. Gepeto reviews the output but never counts as a fourth provider.
9. Never claim the Buscador ran unless the tool returned evidence.
```

- [ ] **Step 6: Declare the MCP tool dependency**

Create `skills/buscador-research/agents/openai.yaml` using the registered Buscador app mapping accepted by the current package format. The dependency must identify the Buscador MCP connection and must not contain a secret.

If the exported Plugin package uses `.app.json`, add the Buscador mapping while preserving every existing app entry. Never replace the whole apps object with only Buscador.

- [ ] **Step 7: Add package-level tests**

Create a deterministic script that checks:

```text
all exported preexisting app IDs remain present
Buscador registered app ID is present exactly once
there are no secret-looking values
there are no OPENAI_API_KEY / BUSCADOR_MCP_KEY values
the core skill exists
shopvivaliz-ops exists
buscador-research exists
buscador-research declares MCP dependency
Fable does not appear in Buscador workflow instructions
```

- [ ] **Step 8: Commit the Plugin candidate**

Commit baseline export first, then skill/app changes as a second commit so review can distinguish migration preservation from new behavior.

---

### Task 9: Update the existing Gepeto Plugin and run real Plugin E2E

**Files/runtime:**
- Candidate archive produced from `Vivaliz-site/gepeto-plugin`.
- Existing Gepeto backend Plugin ID.
- Existing current release ID captured immediately before update.

**Interfaces:**
- Consumes: package candidate and healthy registered Buscador app.
- Produces: new release attached to the existing Gepeto Plugin identity.

- [ ] **Step 1: Re-fetch the current Plugin release immediately before mutation**

Call `get_workspace_plugin_source` again.

If `current_release_id` changed since Task 8, stop, diff the newer source, reconcile it into the repo, rebuild the candidate, and only then continue.

- [ ] **Step 2: Create the candidate archive without secrets**

The archive must contain only the versioned Plugin source. Scan it before upload:

```bash
unzip -l gepeto-plugin.zip
grep -RInE '(sk-[A-Za-z0-9_-]{20,}|BUSCADOR_MCP_KEY=|GEPETO_ACTION_KEY=|Authorization:[[:space:]]*Bearer)' .
```

Expected: file listing is correct; secret scan returns no credential values.

- [ ] **Step 3: Update the existing Plugin with optimistic concurrency**

Call Plugin Creator `update_plugin` with:

```text
plugin_id = discovered Gepeto backend Plugin ID
expected_release_id = freshly fetched current release ID
archive = candidate ZIP
```

Expected: `status=updated`, same Plugin ID, new release ID.

- [ ] **Step 4: Verify source after update**

Call `get_workspace_plugin_source` and confirm the new release contains:

```text
core migrated skill
shopvivaliz-ops
buscador-research
preserved preexisting apps
Buscador registered MCP mapping
```

- [ ] **Step 5: Run direct skill activation tests in new chats**

Test:
1. direct `@Gepeto` invocation -> core behavior;
2. ShopVivaliz operational task -> ShopVivaliz skill/rules;
3. direct multi-AI request -> Buscador workflow;
4. indirect “compare with the three AIs and reach consensus” -> Buscador workflow;
5. unrelated writing request -> Buscador must not be called.

Record tool selection and result for each.

- [ ] **Step 6: Run Plugin `getBuscadorHealth`**

Expected:

```text
ok=true
endpoint=buscador
all_providers_present=true
all_providers_verified=true
```

- [ ] **Step 7: Run Plugin `fast/parallel`**

Use a short factual/research prompt.

Required evidence:

```text
OpenAI response present
Anthropic response present
Gemini response present
complete_consensus=true
consensus_present=true
cycle_finished_present=true
phase_coverage_ok=true
```

- [ ] **Step 8: Run Plugin `deep_research/research`**

Use a substantive research prompt that requires current evidence.

Required evidence:

```text
OpenAI research + critique + converge all ok
Anthropic research + critique + converge all ok
Gemini research + critique + converge all ok
consensus event present
cycle_finished ok
complete_provider_coverage=true
complete_consensus=true
Gepeto not counted as provider
```

Record duration. If the call hits a product timeout, stop and return to design review for the async `start/poll` protocol; do not silently implement it.

- [ ] **Step 9: Inspect backend logs for secret exposure**

Search the MCP and tunnel journals for secret values indirectly by checking that known variable names/authorization headers are absent from payload logs. Never print actual key values during the check.

Expected: no secrets, cookies, tokens, or full prompt/response persistence.

---

### Task 10: Merge, deploy, reboot-test, and certify with Absolute Audit V5

**Files:**
- Update: `docs/quality/AUDIT_STATUS.md`
- Update only if an escape is found: `docs/quality/AUDIT_ESCAPE_REGISTER.md`
- Produce: `AUDIT_CERTIFICATION_MANIFEST_V1` evidence package according to repo tooling.

**Interfaces:**
- Consumes: merged code/docs, active MCP/tunnel, updated Gepeto Plugin.
- Produces: fail-closed `AUDIT_VERDICT=APTO` for the exact certified SHAs/releases or a narrowly proven `BLOCKED_EXTERNAL`.

- [ ] **Step 1: Request code review before merging the Buscador hardening branch**

Use `superpowers:requesting-code-review`. Resolve every material finding through `superpowers:receiving-code-review`.

- [ ] **Step 2: Run complete Buscador repo CI/governance locally and in GitHub**

At minimum:

```bash
npm ci --ignore-scripts --no-audit --no-fund --prefix ops/buscador-mcp
npm test --prefix ops/buscador-mcp
for f in tests/*.php; do php "$f"; done
for f in tests/*.sh; do bash "$f"; done
for f in tests/*.mjs; do node "$f"; done
bash scripts/absolute-audit-governance-validate.sh
```

Open PR, wait for checks, review, and merge. Confirm the main guard passes on the actual main commit.

- [ ] **Step 3: Redeploy backend MCP/tunnel from the merged Buscador SHA**

Update the backend source checkout to the merged main SHA, run tests, reinstall the immutable MCP release and tunnel unit, then confirm the active release identity.

- [ ] **Step 4: Verify site production parity**

After the site documentation PR merges and the canonical deploy completes, prove:

```text
production release contains BUSCADOR_MCP_KEY auth candidate
unauthenticated POST -> 401
dedicated MCP key + empty body -> 422 invalid_message
GET health -> endpoint=buscador
```

Do not print the key.

- [ ] **Step 5: Perform an intentional backend reboot persistence test**

Before reboot, record non-sensitive service states and the active MCP release ID. Reboot the backend through the authorized mechanism.

After the host returns, prove:

```bash
systemctl --user is-active shopvivaliz-buscador-mcp.service
systemctl --user is-active shopvivaliz-buscador-mcp-tunnel.service
curl -fsS http://127.0.0.1:8787/healthz
tunnel-client doctor --profile buscador-private --explain
```

Then call `getBuscadorHealth` from the Gepeto Plugin again. This is the required persistence proof.

- [ ] **Step 6: Execute the full project-specific browser E2E**

Using the real graphical backend browser against the same release:

```text
buscador-admin-login
buscador-chat-cycle
```

Exercise the real UI, all three providers, research/critique/converge, consensus, reload/revisit, and inspect console/pageerror/requestfailed. Do not substitute API-only proof.

- [ ] **Step 7: Run the Absolute Audit V5 remediation loop**

Read and execute all mandatory audit documents listed by `AUDIT_POLICY.md`, including:

```text
EXTREME_AUDIT_PROTOCOL
AUDIT_RUNTIME_PARITY_V1
AUDIT_UNIVERSAL_COVERAGE_V1
ARCHITECTURE_DEPLOY_AUDIT_V1
AUDIT_SELF_TEST_V1 when applicable
AUDIT_BROWSER_E2E_REAL_V1
AUDIT_JOURNEY_INVENTORY_V1
AUDIT_CLEAN_ROOM_REALITY_V1
AUDIT_HARDENING_MAX_V1
AUDIT_APTO_REMEDIATION_LOOP_V1
AUDIT_ESCAPE_INVALIDATION_V1
AUDIT_ABSOLUTE_GATE_V1
AUDIT_AUTH_CREDENTIAL_DISCOVERY_V1
AUDIT_MERGE_ENFORCEMENT_V1
AUDIT_PROJECT_REQUIREMENTS_V1
AUDIT_PROJECT_REQUIREMENTS.json
AUDIT_OVERLAY.md
```

Any executable blocker discovered returns to root-cause investigation, TDD correction, regression, deploy, and reaudit. Do not stop at a defect list.

- [ ] **Step 8: Satisfy every Buscador project invariant**

The certifier evidence must prove:

```text
BUSCADOR_THREE_PROVIDER_CHAT_REAL_V1
BUSCADOR_ADMIN_UI_E2E_V1
BUSCADOR_HEALTH_NOT_CERTIFICATION_V1
BUSCADOR_NO_FABLE_V1
BUSCADOR_TRANSPORT_AUTH_REAL_V1
```

- [ ] **Step 9: Run the certifier for the exact release/environment**

Only accept:

```text
AUDIT_VERDICT=APTO
```

Anything else is an intermediate remediation state unless a real external blocker is proven under the policy.

- [ ] **Step 10: Clean up task-only state**

Close Browser Worker sessions created by this task, remove temporary files, ensure no staging credentials remain, verify no task PR is left open, and remove worktrees/branches according to `superpowers:finishing-a-development-branch`.

Do not remove persistent canonical services, the canonical browser profile, or the production Plugin.

- [ ] **Step 11: Final evidence summary**

Report only freshly proven values:

```text
GEPETO_PLUGIN_IDENTITY_PRESERVED=true
GEPETO_CORE_SKILL_OK=true
SHOPVIVALIZ_OPS_SKILL_OK=true
BUSCADOR_RESEARCH_SKILL_OK=true
GITHUB_APP_OK=true
GMAIL_APP_OK=true
RDC_APP_OK=true
MCP_SERVER_HEALTHY=true
TUNNEL_HEALTHY=true
BUSCADOR_MCP_MAPPED_TO_GEPETO=true
TOOLS_EXACTLY=getBuscadorHealth,runBuscador
BUSCADOR_HEALTH_OK=true
PROVIDERS=openai,anthropic,gemini
FAST_CYCLE_OK=true
DEEP_RESEARCH_CYCLE_OK=true
CONSENSUS_PRESENT=true
CYCLE_FINISHED_PRESENT=true
GEPETO_NOT_COUNTED_AS_PROVIDER=true
SECRETS_EXPOSED=false
REBOOT_PERSISTENCE_OK=true
AUDIT_VERDICT=APTO
```
