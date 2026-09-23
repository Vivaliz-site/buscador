import test from 'node:test';
import assert from 'node:assert/strict';

import {
  BUSCADOR_UPSTREAM_URL,
  classifyHttpError,
  normalizeHealth,
  normalizeRun,
  redactSecrets,
  validateRunInput,
} from '../lib.mjs';

const verifiedProviders = {
  openai: { health: 'verified', configured: true },
  anthropic: { health: 'verified', configured: true },
  gemini: { health: 'verified', configured: true },
};

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

test('upstream URL is fixed and HTTPS', () => {
  assert.equal(BUSCADOR_UPSTREAM_URL, 'https://shopvivaliz.com.br/api/agent/buscador.php');
});

test('health requires all three providers and verified state', () => {
  const healthy = normalizeHealth({
    ok: true,
    endpoint: 'buscador',
    profile: 'deep_research',
    providers: verifiedProviders,
  });
  assert.equal(healthy.ok, true);
  assert.equal(healthy.all_providers_present, true);
  assert.equal(healthy.all_providers_verified, true);

  const configuredOnly = normalizeHealth({
    ok: true,
    endpoint: 'buscador',
    providers: {
      ...verifiedProviders,
      gemini: { health: 'configured_unverified', configured: true },
    },
  });
  assert.equal(configuredOnly.ok, false);
  assert.equal(configuredOnly.all_providers_verified, false);
});

test('run is complete only with all providers, consensus and successful cycle_finished', () => {
  const complete = normalizeRun({
    ok: true,
    endpoint: 'buscador',
    cycle_id: 'cycle-1',
    events: [
      { type: 'agent_message', provider: 'openai', phase: 'research', ok: true, text: 'a', sources: [] },
      { type: 'agent_message', provider: 'anthropic', phase: 'research', ok: true, text: 'b', sources: [] },
      { type: 'agent_message', provider: 'gemini', phase: 'research', ok: true, text: 'c', sources: [] },
      { type: 'consensus', provider: 'openai', ok: true, text: 'consenso', sources: [] },
      {
        type: 'cycle_finished',
        ok: true,
        provider_status: { openai: 'ok', anthropic: 'ok', gemini: 'ok' },
        provider_phase_status: fullResearchPhases,
        complete_provider_coverage: true,
        consensus_available: true,
        message_count: 3,
      },
    ],
  });
  assert.equal(complete.complete_consensus, true);
  assert.deepEqual(complete.providers_observed.sort(), ['anthropic', 'gemini', 'openai']);

  const partial = normalizeRun({
    ok: true,
    endpoint: 'buscador',
    cycle_id: 'cycle-2',
    events: [
      { type: 'agent_message', provider: 'openai', phase: 'research', ok: true, text: 'a' },
      { type: 'agent_message', provider: 'anthropic', phase: 'research', ok: true, text: 'b' },
      {
        type: 'cycle_finished',
        ok: false,
        provider_status: { openai: 'ok', anthropic: 'ok', gemini: 'error' },
        complete_provider_coverage: false,
        consensus_available: false,
      },
    ],
  });
  assert.equal(partial.complete_consensus, false);
  assert.equal(partial.consensus_present, false);
});

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

test('validation failures carry invalid_input error class', () => {
  assert.throws(
    () => validateRunInput({ message: '', profile: 'fast', mode: 'parallel' }),
    (error) => error?.errorClass === 'invalid_input' && /invalid_message/.test(error.message),
  );
});

test('run input is strict and never accepts an upstream URL', () => {
  assert.deepEqual(
    validateRunInput({ message: 'Pesquisar', profile: 'fast', mode: 'parallel' }),
    { message: 'Pesquisar', profile: 'fast', mode: 'parallel' },
  );
  assert.throws(() => validateRunInput({ message: '', profile: 'fast', mode: 'parallel' }), /invalid_message/);
  assert.throws(() => validateRunInput({ message: 'x', profile: 'other', mode: 'parallel' }), /invalid_profile/);
  assert.throws(() => validateRunInput({ message: 'x', profile: 'fast', mode: 'other' }), /invalid_mode/);
  assert.throws(
    () => validateRunInput({ message: 'x', profile: 'fast', mode: 'parallel', url: 'https://evil.test' }),
    /unknown_field/,
  );
});

test('HTTP status classes are stable', () => {
  assert.equal(classifyHttpError(401), 'auth_error');
  assert.equal(classifyHttpError(429), 'upstream_rate_limited');
  assert.equal(classifyHttpError(500), 'upstream_error');
});

test('secret redaction never returns known secret values', () => {
  const secret = 'super-secret-test-value';
  const redacted = redactSecrets(`authorization failed for ${secret}`, [secret]);
  assert.equal(redacted.includes(secret), false);
  assert.match(redacted, /\[REDACTED\]/);
});
