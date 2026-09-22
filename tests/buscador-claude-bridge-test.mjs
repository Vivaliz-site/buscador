import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import {
  validateRequest,
  sanitizeBridgeError,
  classifyClaudeError,
  claudeFailureDetail,
  buildClaudeArgs,
  isDirectInvocation,
} from '../ops/buscador/claude-bridge.mjs';

const valid = validateRequest({
  model: 'claude-opus-5',
  effort: 'xhigh',
  system: 'system-safe',
  prompt: 'secret-user-prompt',
  web_search: true,
});
assert.equal(valid.model, 'claude-opus-5');
assert.equal(valid.effort, 'xhigh');
assert.equal(valid.web_search, true);

assert.throws(() => validateRequest({
  model: 'claude-fable-5',
  effort: 'xhigh',
  system: 'x',
  prompt: 'x',
}), /invalid_model/);

assert.throws(() => validateRequest({
  model: 'claude-opus-5',
  effort: 'extreme',
  system: 'x',
  prompt: 'x',
}), /invalid_effort/);

const args = buildClaudeArgs(valid);
assert(args.includes('--safe-mode'));
assert(args.includes('--restricted'));
assert(args.includes('--no-session-persistence'));
assert(args.includes('WebSearch,WebFetch'));
assert(!args.includes(valid.prompt), 'user prompt must go over stdin, not argv');

const noWebArgs = buildClaudeArgs({ ...valid, web_search: false });
assert.equal(noWebArgs[noWebArgs.indexOf('--tools') + 1], '');

assert.equal(classifyClaudeError('OAuth session expired'), 'auth');
assert.equal(classifyClaudeError('credit balance is too low'), 'quota');
assert.equal(classifyClaudeError("You've hit your session limit · resets 12:30am (UTC)"), 'quota');
assert.equal(classifyClaudeError('request_timeout'), 'timeout');

const sessionLimitDetail = claudeFailureDetail({
  code: 1,
  stderr: '',
  stdout: JSON.stringify({
    terminal_reason: 'api_error',
    api_error_status: 429,
    result: "You've hit your session limit · resets 12:30am (UTC)",
  }),
});
assert.match(sessionLimitDetail, /session limit/);

const safe = sanitizeBridgeError('Authorization: Bearer sk-ant-oat-secret user@example.com');
assert(!safe.includes('sk-ant-oat-secret'));
assert(!safe.includes('user@example.com'));

const invocationDir = fs.mkdtempSync(path.join(os.tmpdir(), 'buscador-claude-invocation-'));
const bridgeTarget = fileURLToPath(new URL('../ops/buscador/claude-bridge.mjs', import.meta.url));
const bridgeLink = path.join(invocationDir, 'current-claude-bridge.mjs');
fs.symlinkSync(bridgeTarget, bridgeLink);
assert.equal(isDirectInvocation(pathToFileURL(bridgeTarget).href, bridgeLink), true);
assert.equal(isDirectInvocation(pathToFileURL(bridgeTarget).href, import.meta.filename), false);
fs.rmSync(invocationDir, { recursive: true, force: true });

console.log('BUSCADOR_CLAUDE_BRIDGE_TEST=PASS');
