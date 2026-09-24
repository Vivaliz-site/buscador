import test from 'node:test';
import assert from 'node:assert/strict';

import { createMcpHandler } from '@modelcontextprotocol/server';
import { createBuscadorMcpServer, createRunGuard } from '../lib.mjs';

function parseMcpResponse(text) {
  const dataLine = text.split('\n').find((line) => line.startsWith('data: '));
  const raw = dataLine ? dataLine.slice(6) : text;
  return JSON.parse(raw);
}

function makeHandler(fetchImpl, {
  key = 'test-key-never-log',
  logger = () => {},
} = {}) {
  const runGuard = createRunGuard();
  return createMcpHandler(
    () => createBuscadorMcpServer({
      fetchImpl,
      keyProvider: () => key,
      logger,
      runGuard,
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

test('runBuscador sends streaming transport/auth request without exposing server key', async () => {
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
          provider_phase_status: {
            openai: { research: { status: 'ok' } },
            anthropic: { research: { status: 'ok' } },
            gemini: { research: { status: 'ok' } },
          },
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
  assert.equal(JSON.parse(captured.init.body).stream, true);
  assert.equal(captured.init.headers.Authorization, 'Bearer test-key-never-log');
  assert.equal(JSON.stringify(body).includes('test-key-never-log'), false);
  await handler.close();
});

test('runBuscador parses heartbeat NDJSON into a complete result', async () => {
  const handler = makeHandler(async (_url, init) => {
    assert.match(init.headers.Accept, /application\/x-ndjson/);
    const events = [
      { type: 'cycle_started', cycle_id: 'cycle-stream', profile: 'fast', mode: 'parallel' },
      { type: 'agent_message', cycle_id: 'cycle-stream', provider: 'openai', phase: 'research', ok: true, text: 'a', sources: [] },
      { type: 'agent_message', cycle_id: 'cycle-stream', provider: 'anthropic', phase: 'research', ok: true, text: 'b', sources: [] },
      { type: 'agent_message', cycle_id: 'cycle-stream', provider: 'gemini', phase: 'research', ok: true, text: 'c', sources: [] },
      { type: 'consensus', cycle_id: 'cycle-stream', provider: 'openai', ok: true, text: 'done', sources: [] },
      {
        type: 'cycle_finished',
        cycle_id: 'cycle-stream',
        ok: true,
        provider_status: { openai: 'ok', anthropic: 'ok', gemini: 'ok' },
        provider_phase_status: {
          openai: { research: { status: 'ok' } },
          anthropic: { research: { status: 'ok' } },
          gemini: { research: { status: 'ok' } },
        },
        complete_provider_coverage: true,
        consensus_available: true,
        message_count: 3,
      },
    ];
    const ndjson = '\n' + events.map((event) => JSON.stringify(event)).join('\n\n') + '\n';
    return new Response(ndjson, { status: 200, headers: { 'Content-Type': 'application/x-ndjson' } });
  });

  const body = await rpc(handler, 4, 'tools/call', {
    name: 'runBuscador',
    arguments: { message: 'teste', profile: 'fast', mode: 'parallel' },
  });
  assert.equal(body.result.structuredContent.complete_consensus, true);
  assert.equal(body.result.structuredContent.cycle_id, 'cycle-stream');
  assert.equal(body.result.structuredContent.message_count, 3);
  await handler.close();
});

test('runBuscador handles JSON split across streaming chunks', async () => {
  const handler = makeHandler(async () => {
    const events = [
      { type: 'cycle_started', cycle_id: 'cycle-split' },
      { type: 'agent_message', provider: 'openai', phase: 'research', ok: true, text: 'a', sources: [] },
      { type: 'agent_message', provider: 'anthropic', phase: 'research', ok: true, text: 'b', sources: [] },
      { type: 'agent_message', provider: 'gemini', phase: 'research', ok: true, text: 'c', sources: [] },
      { type: 'consensus', provider: 'openai', ok: true, text: 'done', sources: [] },
      { type: 'cycle_finished', cycle_id: 'cycle-split', ok: true,
        provider_status: { openai: 'ok', anthropic: 'ok', gemini: 'ok' },
        provider_phase_status: { openai: { research: { status: 'ok' } }, anthropic: { research: { status: 'ok' } }, gemini: { research: { status: 'ok' } } },
        complete_provider_coverage: true, consensus_available: true, message_count: 3 },
    ];
    const bytes = new TextEncoder().encode(events.map((e) => JSON.stringify(e)).join('\n') + '\n');
    const stream = new ReadableStream({ start(controller) {
      for (let i = 0; i < bytes.length; i += 7) controller.enqueue(bytes.slice(i, i + 7));
      controller.close();
    }});
    return new Response(stream, { status: 200, headers: { 'Content-Type': 'application/x-ndjson' } });
  });
  const body = await rpc(handler, 5, 'tools/call', {
    name: 'runBuscador', arguments: { message: 'teste', profile: 'fast', mode: 'parallel' },
  });
  assert.equal(body.result.structuredContent.complete_consensus, true);
  assert.equal(body.result.structuredContent.cycle_id, 'cycle-split');
  await handler.close();
});

test('runBuscador cancels malformed streaming body', async () => {
  let cancelled = false;
  const handler = makeHandler(async () => {
    const stream = new ReadableStream({
      start(controller) {
        controller.enqueue(new TextEncoder().encode(
          '{"type":"cycle_started","cycle_id":"cycle-bad"}\nnot-json\n',
        ));
      },
      cancel() { cancelled = true; },
    });
    return new Response(stream, { status: 200, headers: { 'Content-Type': 'application/x-ndjson' } });
  });
  const body = await rpc(handler, 6, 'tools/call', {
    name: 'runBuscador', arguments: { message: 'teste', profile: 'fast', mode: 'parallel' },
  });
  assert.equal(body.result.structuredContent.error_class, 'invalid_response');
  assert.equal(cancelled, true);
  await handler.close();
});

test('runBuscador rejects duplicate cycle_finished events', async () => {
  const handler = makeHandler(async () => {
    const finished = {
      type: 'cycle_finished', cycle_id: 'cycle-dup', ok: true,
      provider_status: { openai: 'ok', anthropic: 'ok', gemini: 'ok' },
      provider_phase_status: {
        openai: { research: { status: 'ok' } },
        anthropic: { research: { status: 'ok' } },
        gemini: { research: { status: 'ok' } },
      },
      complete_provider_coverage: true, consensus_available: true, message_count: 3,
    };
    const events = [
      { type: 'cycle_started', cycle_id: 'cycle-dup' },
      { type: 'agent_message', provider: 'openai', phase: 'research', ok: true, text: 'a', sources: [] },
      { type: 'agent_message', provider: 'anthropic', phase: 'research', ok: true, text: 'b', sources: [] },
      { type: 'agent_message', provider: 'gemini', phase: 'research', ok: true, text: 'c', sources: [] },
      { type: 'consensus', provider: 'openai', ok: true, text: 'done', sources: [] },
      finished, finished,
    ];
    return new Response(events.map((e) => JSON.stringify(e)).join('\n') + '\n', {
      status: 200, headers: { 'Content-Type': 'application/x-ndjson' },
    });
  });
  const body = await rpc(handler, 7, 'tools/call', {
    name: 'runBuscador', arguments: { message: 'teste', profile: 'fast', mode: 'parallel' },
  });
  assert.equal(body.result.structuredContent.error_class, 'invalid_response');
  assert.equal(body.result.structuredContent.error, 'upstream_invalid_cycle');
  await handler.close();
});

test('runBuscador classifies timeout while streaming body', async () => {
  const previous = process.env.BUSCADOR_MCP_UPSTREAM_TIMEOUT_MS;
  process.env.BUSCADOR_MCP_UPSTREAM_TIMEOUT_MS = '5000';
  let handler;
  try {
    handler = makeHandler(async (_url, init) => {
      const stream = new ReadableStream({
        start(controller) {
          controller.enqueue(new TextEncoder().encode(
            '{"type":"cycle_started","cycle_id":"cycle-timeout"}\n',
          ));
          init.signal.addEventListener('abort', () => {
            controller.error(new DOMException('aborted', 'AbortError'));
          }, { once: true });
        },
      });
      return new Response(stream, { status: 200, headers: { 'Content-Type': 'application/x-ndjson' } });
    });
    const body = await rpc(handler, 8, 'tools/call', {
      name: 'runBuscador', arguments: { message: 'teste', profile: 'fast', mode: 'parallel' },
    });
    assert.equal(body.result.structuredContent.error_class, 'upstream_timeout');
  } finally {
    if (previous === undefined) delete process.env.BUSCADOR_MCP_UPSTREAM_TIMEOUT_MS;
    else process.env.BUSCADOR_MCP_UPSTREAM_TIMEOUT_MS = previous;
    await handler?.close();
  }
});

test('runBuscador classifies upstream HTTP failures without leaking auth', async () => {
  for (const [status, expectedClass] of [
    [401, 'auth_error'],
    [429, 'upstream_rate_limited'],
    [500, 'upstream_error'],
  ]) {
    const key = `secret-${status}-never-log`;
    const logs = [];
    const handler = makeHandler(async () => new Response(
      JSON.stringify({ ok: false, error: `status-${status}` }),
      { status, headers: { 'Content-Type': 'application/json' } },
    ), {
      key,
      logger: (entry) => logs.push(entry),
    });

    const body = await rpc(handler, 100 + status, 'tools/call', {
      name: 'runBuscador',
      arguments: { message: 'teste', profile: 'fast', mode: 'parallel' },
    });
    assert.equal(body.result.isError, true);
    assert.equal(body.result.structuredContent.error_class, expectedClass);
    assert.equal(JSON.stringify(body).includes(key), false);
    assert.equal(JSON.stringify(logs).includes(key), false);
    await handler.close();
  }
});

test('runBuscador classifies network failures and redacts secrets', async () => {
  const key = 'network-secret-never-log';
  const logs = [];
  const handler = makeHandler(async () => {
    throw new Error(`connect failed with ${key}`);
  }, {
    key,
    logger: (entry) => logs.push(entry),
  });

  const body = await rpc(handler, 200, 'tools/call', {
    name: 'runBuscador',
    arguments: { message: 'teste', profile: 'fast', mode: 'parallel' },
  });
  assert.equal(body.result.structuredContent.error_class, 'upstream_unreachable');
  assert.match(body.result.structuredContent.error, /\[REDACTED\]/);
  assert.equal(JSON.stringify(body).includes(key), false);
  assert.equal(JSON.stringify(logs).includes(key), false);
  await handler.close();
});

test('runBuscador rejects invalid JSON and oversized upstream bodies', async () => {
  const invalid = makeHandler(async () => new Response(
    'not-json',
    { status: 200, headers: { 'Content-Type': 'application/json' } },
  ));
  const invalidBody = await rpc(invalid, 201, 'tools/call', {
    name: 'runBuscador',
    arguments: { message: 'teste', profile: 'fast', mode: 'parallel' },
  });
  assert.equal(invalidBody.result.structuredContent.error_class, 'invalid_response');
  assert.equal(invalidBody.result.structuredContent.error, 'upstream_invalid_json');
  await invalid.close();

  const oversized = makeHandler(async () => new Response('x'.repeat(2_000_001), { status: 200 }));
  const oversizedBody = await rpc(oversized, 202, 'tools/call', {
    name: 'runBuscador',
    arguments: { message: 'teste', profile: 'fast', mode: 'parallel' },
  });
  assert.equal(oversizedBody.result.structuredContent.error_class, 'invalid_response');
  assert.equal(oversizedBody.result.structuredContent.error, 'upstream_response_too_large');
  await oversized.close();
});

test('runBuscador sets redirect=error on the fixed upstream request', async () => {
  let seenRedirect = null;
  const handler = makeHandler(async (_url, init) => {
    seenRedirect = init.redirect;
    throw new Error('stop-after-capture');
  });
  await rpc(handler, 203, 'tools/call', {
    name: 'runBuscador',
    arguments: { message: 'teste', profile: 'fast', mode: 'parallel' },
  });
  assert.equal(seenRedirect, 'error');
  await handler.close();
});

test('runBuscador reports an upstream timeout', async () => {
  const previous = process.env.BUSCADOR_MCP_UPSTREAM_TIMEOUT_MS;
  process.env.BUSCADOR_MCP_UPSTREAM_TIMEOUT_MS = '5000';
  const handler = makeHandler(async (_url, init) => new Promise((_resolve, reject) => {
    init.signal.addEventListener('abort', () => reject(new Error('aborted-by-test')), { once: true });
  }));

  try {
    const body = await rpc(handler, 204, 'tools/call', {
      name: 'runBuscador',
      arguments: { message: 'teste', profile: 'fast', mode: 'parallel' },
    });
    assert.equal(body.result.structuredContent.error_class, 'upstream_timeout');
  } finally {
    if (previous === undefined) delete process.env.BUSCADOR_MCP_UPSTREAM_TIMEOUT_MS;
    else process.env.BUSCADOR_MCP_UPSTREAM_TIMEOUT_MS = previous;
    await handler.close();
  }
});
