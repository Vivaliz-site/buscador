import test from 'node:test';
import assert from 'node:assert/strict';

import { createMcpHandler } from '@modelcontextprotocol/server';
import { createBuscadorMcpServer } from '../lib.mjs';

function parseMcpResponse(text) {
  const dataLine = text.split('\n').find((line) => line.startsWith('data: '));
  const raw = dataLine ? dataLine.slice(6) : text;
  return JSON.parse(raw);
}

function makeHandler(fetchImpl) {
  return createMcpHandler(
    () => createBuscadorMcpServer({
      fetchImpl,
      keyProvider: () => 'test-key-never-log',
      logger: () => {},
    }),
    { legacy: 'stateless' },
  );
}

async function rpc(handler, id, method, params) {
  const response = await handler.fetch(new Request('http://localhost/mcp', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json, text/event-stream',
    },
    body: JSON.stringify({ jsonrpc: '2.0', id, method, ...(params ? { params } : {}) }),
  }));
  assert.equal(response.status, 200);
  return parseMcpResponse(await response.text());
}

test('MCP advertises exactly two allowlisted tools', async () => {
  const handler = makeHandler(async () => {
    throw new Error('fetch must not run during tools/list');
  });
  const body = await rpc(handler, 1, 'tools/list');
  const names = body.result.tools.map((tool) => tool.name).sort();
  assert.deepEqual(names, ['getBuscadorHealth', 'runBuscador']);
  await handler.close();
});

test('getBuscadorHealth uses fixed upstream and preserves configured_unverified', async () => {
  let seenUrl = null;
  const handler = makeHandler(async (url) => {
    seenUrl = String(url);
    return new Response(JSON.stringify({
      ok: true,
      endpoint: 'buscador',
      profile: 'deep_research',
      providers: {
        openai: { health: 'verified', configured: true },
        anthropic: { health: 'verified', configured: true },
        gemini: { health: 'configured_unverified', configured: true },
      },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  });

  const body = await rpc(handler, 2, 'tools/call', {
    name: 'getBuscadorHealth',
    arguments: { profile: 'deep_research' },
  });
  assert.match(seenUrl, /^https:\/\/shopvivaliz\.com\.br\/api\/agent\/buscador\.php\?/);
  assert.equal(body.result.structuredContent.ok, false);
  assert.equal(body.result.structuredContent.all_providers_verified, false);
  await handler.close();
});

test('runBuscador forces stream=false and auth stays server-side', async () => {
  let captured = null;
  const handler = makeHandler(async (url, init) => {
    captured = { url: String(url), init };
    return new Response(JSON.stringify({
      ok: true,
      endpoint: 'buscador',
      cycle_id: 'cycle-test',
      events: [
        { type: 'agent_message', provider: 'openai', phase: 'research', ok: true, text: 'a', sources: [] },
        { type: 'agent_message', provider: 'anthropic', phase: 'research', ok: true, text: 'b', sources: [] },
        { type: 'agent_message', provider: 'gemini', phase: 'research', ok: true, text: 'c', sources: [] },
        { type: 'consensus', provider: 'gemini', ok: true, text: 'done', sources: [] },
        {
          type: 'cycle_finished',
          ok: true,
          provider_status: { openai: 'ok', anthropic: 'ok', gemini: 'ok' },
          provider_phase_status: {},
          complete_provider_coverage: true,
          consensus_available: true,
          message_count: 3,
        },
      ],
    }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  });

  const body = await rpc(handler, 3, 'tools/call', {
    name: 'runBuscador',
    arguments: { message: 'teste', profile: 'fast', mode: 'parallel' },
  });
  assert.equal(captured.url, 'https://shopvivaliz.com.br/api/agent/buscador.php');
  assert.equal(JSON.parse(captured.init.body).stream, false);
  assert.equal(captured.init.headers.Authorization, 'Bearer test-key-never-log');
  assert.equal(body.result.structuredContent.complete_consensus, true);
  assert.equal(JSON.stringify(body).includes('test-key-never-log'), false);
  await handler.close();
});
