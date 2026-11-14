import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeRun } from '../lib.mjs';

function eventSet(mixed) {
  const cycle = 'cycle-A';
  const foreign = mixed ? 'cycle-B' : cycle;
  return [
    { type: 'cycle_started', cycle_id: cycle },
    { type: 'agent_message', cycle_id: cycle, provider: 'openai', phase: 'research', ok: true, text: 'a' },
    { type: 'agent_message', cycle_id: foreign, provider: 'anthropic', phase: 'research', ok: true, text: 'b' },
    { type: 'agent_message', cycle_id: foreign, provider: 'gemini', phase: 'research', ok: true, text: 'c' },
    { type: 'consensus', cycle_id: foreign, ok: true, text: 'consenso' },
    {
      type: 'cycle_finished', cycle_id: cycle, ok: true,
      provider_status: { openai: 'ok', anthropic: 'ok', gemini: 'ok' },
      provider_phase_status: {
        openai: { research: { status: 'ok' } },
        anthropic: { research: { status: 'ok' } },
        gemini: { research: { status: 'ok' } },
      },
      complete_provider_coverage: true,
      consensus_available: true,
    },
  ];
}

test('nao certifica consenso com mensagens de outro ciclo', () => {
  const payload = { ok: true, endpoint: 'buscador', events: eventSet(true) };
  assert.equal(normalizeRun(payload, 'parallel').complete_consensus, false);
});

test('mantem o consenso valido com identificadores coerentes', () => {
  const payload = { ok: true, endpoint: 'buscador', events: eventSet(false) };
  assert.equal(normalizeRun(payload, 'parallel').complete_consensus, true);
});

test('rejeita consenso cujo envelope declara outro ciclo', () => {
  const payload = { ok: true, endpoint: 'buscador', cycle_id: 'cycle-B', events: eventSet(false) };
  assert.equal(normalizeRun(payload, 'parallel').complete_consensus, false);
});

test('rejeita IDs mistos mesmo sem identificador nos marcadores de inicio e fim', () => {
  const events = eventSet(true).map((event) =>
    ['cycle_started', 'cycle_finished'].includes(event.type) ? { ...event, cycle_id: undefined } : event);
  const payload = { ok: true, endpoint: 'buscador', events };
  assert.equal(normalizeRun(payload, 'parallel').complete_consensus, false);
});

test('mantem compatibilidade quando todos os eventos sao legados sem cycle_id', () => {
  const events = eventSet(false).map(({ cycle_id, ...event }) => event);
  const payload = { ok: true, endpoint: 'buscador', events };
  assert.equal(normalizeRun(payload, 'parallel').complete_consensus, true);
});

test('propaga o identificador do ciclo ao resultado mesmo sem envelope', () => {
  const payload = { ok: true, endpoint: 'buscador', events: eventSet(false) };
  const result = normalizeRun(payload, 'parallel');
  assert.equal(result.cycle_id, 'cycle-A');
  assert.equal(result.complete_consensus, true);
});
