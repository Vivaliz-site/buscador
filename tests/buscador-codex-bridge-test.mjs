import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import {
  validateRequest,
  classifyRateLimit,
  exactModelMatches,
  sanitizeBridgeError,
  remainingRequestMs,
  resolveCodexWebSearchMode,
  isDirectInvocation,
} from '../ops/buscador/codex-bridge.mjs';

const valid = validateRequest({
  model: 'gpt-5.6-sol',
  effort: 'xhigh',
  prompt: 'pesquise',
  web_search: true,
});
assert.equal(valid.model, 'gpt-5.6-sol');
assert.equal(valid.effort, 'xhigh');
assert.equal(valid.web_search, true);

assert.equal(resolveCodexWebSearchMode(true, undefined), 'cached');
assert.equal(resolveCodexWebSearchMode(true, 'cached'), 'cached');
assert.equal(resolveCodexWebSearchMode(true, 'live'), 'live');
assert.equal(resolveCodexWebSearchMode(false, 'live'), 'disabled');
assert.throws(() => resolveCodexWebSearchMode(true, 'invalid'), /invalid_web_search_mode/);

assert.throws(() => validateRequest({
  model: 'gpt-4o',
  effort: 'xhigh',
  prompt: 'x',
  web_search: true,
}), /invalid_model/);

assert.throws(() => validateRequest({
  model: 'gpt-5.6-sol',
  effort: 'extreme',
  prompt: 'x',
  web_search: true,
}), /invalid_effort/);

assert.equal(classifyRateLimit({
  rateLimitReachedType: 'usage_limit',
}), 'exhausted');

assert.equal(classifyRateLimit({
  primary: { usedPercent: 1 },
  rateLimitReachedType: null,
}), 'available');

assert.equal(exactModelMatches('gpt-5.6-sol', 'gpt-5.6-sol'), true);
assert.equal(exactModelMatches('gpt-5.6-sol', 'gpt-5.6-terra'), false);

assert.equal(remainingRequestMs(5000, 1000, 10000), 4000);
assert.equal(remainingRequestMs(5000, 1000, 2500), 2500);
assert.throws(() => remainingRequestMs(1000, 1000, 5000), /request_timeout/);

const invocationDir = fs.mkdtempSync(path.join(os.tmpdir(), 'buscador-invocation-'));
const bridgeTarget = fileURLToPath(new URL('../ops/buscador/codex-bridge.mjs', import.meta.url));
const bridgeLink = path.join(invocationDir, 'current-bridge.mjs');
fs.symlinkSync(bridgeTarget, bridgeLink);
assert.equal(isDirectInvocation(pathToFileURL(bridgeTarget).href, bridgeLink), true);
assert.equal(isDirectInvocation(pathToFileURL(bridgeTarget).href, import.meta.filename), false);
fs.rmSync(invocationDir, { recursive: true, force: true });

const safe = sanitizeBridgeError(
  'Authorization: Bearer sk-secret-token quota reached for user@example.com'
);
assert(!safe.includes('sk-secret-token'));
assert(!safe.includes('user@example.com'));

console.log('BUSCADOR_CODEX_BRIDGE_TEST=PASS');
