import { McpServer } from '@modelcontextprotocol/server';
import * as z from 'zod/v4';

export const BUSCADOR_UPSTREAM_URL = 'https://shopvivaliz.com.br/api/agent/buscador.php';
export const BUSCADOR_PROFILES = ['deep_research', 'balanced', 'fast'];
export const BUSCADOR_MODES = ['parallel', 'debate', 'research'];
export const BUSCADOR_PROVIDERS = ['openai', 'anthropic', 'gemini'];
export const BUSCADOR_PHASES_BY_MODE = Object.freeze({
  parallel: ['research'],
  debate: ['research', 'critique'],
  research: ['research', 'critique', 'converge'],
});

const DEFAULT_TIMEOUT_MS = 840_000;
const HEALTH_TIMEOUT_MS = 35_000;
const MAX_RESPONSE_BYTES = 2_000_000;
const MAX_MESSAGE_CHARS = 20_000;

export class BuscadorMcpError extends Error {
  constructor(errorClass, message, details = {}) {
    super(message);
    this.name = 'BuscadorMcpError';
    this.errorClass = errorClass;
    this.details = details;
  }
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

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

const defaultRunGuard = createRunGuard();

export function redactSecrets(value, secrets = []) {
  let text = String(value ?? '');
  for (const secret of secrets) {
    if (typeof secret === 'string' && secret.length >= 4) {
      text = text.split(secret).join('[REDACTED]');
    }
  }
  text = text.replace(/Bearer\s+[A-Za-z0-9._~+\/-]+/gi, 'Bearer [REDACTED]');
  return text;
}

export function classifyHttpError(status) {
  if (status === 401 || status === 403) return 'auth_error';
  if (status === 429) return 'upstream_rate_limited';
  if (status >= 500) return 'upstream_error';
  if (status >= 400) return 'invalid_response';
  return 'upstream_error';
}

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

export function normalizeHealth(payload) {
  const providers = isObject(payload?.providers) ? payload.providers : {};
  const allPresent = BUSCADOR_PROVIDERS.every((provider) => isObject(providers[provider]));
  const allVerified = allPresent && BUSCADOR_PROVIDERS.every(
    (provider) => providers[provider]?.health === 'verified',
  );
  const upstreamOk = payload?.ok === true && payload?.endpoint === 'buscador';

  return {
    ok: upstreamOk && allPresent && allVerified,
    upstream_ok: upstreamOk,
    endpoint: payload?.endpoint ?? null,
    profile: payload?.profile ?? null,
    providers,
    providers_expected: BUSCADOR_PROVIDERS,
    all_providers_present: allPresent,
    all_providers_verified: allVerified,
  };
}

function publicEvent(event) {
  const type = event?.type;
  if (!['agent_message', 'agent_error', 'agent_manual_required', 'consensus', 'cycle_finished'].includes(type)) {
    return null;
  }
  const out = {
    type,
    provider: event.provider ?? null,
    phase: event.phase ?? null,
    model: event.model ?? null,
    ok: event.ok === true,
  };
  if (type === 'agent_message' || type === 'consensus') {
    out.text = typeof event.text === 'string' ? event.text : '';
    out.sources = Array.isArray(event.sources) ? event.sources : [];
    out.transport = event.transport ?? null;
  }
  if (type === 'agent_error' || type === 'agent_manual_required') {
    out.failure_class = event.failure_class ?? (type === 'agent_manual_required' ? 'manual_required' : 'provider_error');
    out.error = typeof event.error === 'string' ? event.error : null;
  }
  if (type === 'cycle_finished') {
    out.provider_status = isObject(event.provider_status) ? event.provider_status : {};
    out.provider_phase_status = isObject(event.provider_phase_status) ? event.provider_phase_status : {};
    out.complete_provider_coverage = event.complete_provider_coverage === true;
    out.consensus_available = event.consensus_available === true;
    out.message_count = Number.isFinite(event.message_count) ? event.message_count : null;
    out.duration_ms = Number.isFinite(event.duration_ms) ? event.duration_ms : null;
  }
  return out;
}

function hasCompletePhaseCoverage(providerPhaseStatus, mode) {
  const phases = BUSCADOR_PHASES_BY_MODE[mode] ?? [];
  return BUSCADOR_PROVIDERS.every((provider) =>
    phases.every((phase) => providerPhaseStatus?.[provider]?.[phase]?.status === 'ok'),
  );
}

export function normalizeRun(payload, mode = 'research') {
  const events = Array.isArray(payload?.events) ? payload.events : [];
  const agentMessages = events.filter(
    (event) => event?.type === 'agent_message' && event?.ok === true && BUSCADOR_PROVIDERS.includes(event?.provider),
  );
  const providersObserved = [...new Set(agentMessages.map((event) => event.provider))];
  const consensus = [...events].reverse().find((event) => event?.type === 'consensus' && event?.ok === true) ?? null;
  const finished = [...events].reverse().find((event) => event?.type === 'cycle_finished') ?? null;
  const providerStatus = isObject(finished?.provider_status) ? finished.provider_status : {};
  const providerPhaseStatus = isObject(finished?.provider_phase_status) ? finished.provider_phase_status : {};
  const providerStatusOk = BUSCADOR_PROVIDERS.every((provider) => providerStatus[provider] === 'ok');
  const allProvidersObserved = BUSCADOR_PROVIDERS.every((provider) => providersObserved.includes(provider));
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
    ok: completeConsensus,
    endpoint: payload?.endpoint ?? null,
    cycle_id: payload?.cycle_id ?? null,
    providers_observed: providersObserved,
    provider_status: providerStatus,
    provider_phase_status: providerPhaseStatus,
    required_phases: requiredPhases,
    phase_coverage_ok: phaseCoverageOk,
    message_count: finished?.message_count ?? agentMessages.length,
    complete_provider_coverage: finished?.complete_provider_coverage === true,
    consensus_present: consensus !== null,
    cycle_finished_present: finished !== null,
    complete_consensus: completeConsensus,
    consensus: consensus ? publicEvent(consensus) : null,
    events: events.map(publicEvent).filter(Boolean),
  };
}

async function readTextLimited(response, maxBytes = MAX_RESPONSE_BYTES) {
  if (!response.body) return '';
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let total = 0;
  let text = '';
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      total += value.byteLength;
      if (total > maxBytes) {
        await reader.cancel();
        throw new BuscadorMcpError('invalid_response', 'upstream_response_too_large');
      }
      text += decoder.decode(value, { stream: true });
    }
    text += decoder.decode();
    return text;
  } finally {
    reader.releaseLock();
  }
}

async function requestJson({
  fetchImpl,
  url,
  init,
  timeoutMs,
  secrets = [],
}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    let response;
    try {
      response = await fetchImpl(url, { ...init, redirect: 'error', signal: controller.signal });
    } catch (error) {
      if (controller.signal.aborted) {
        throw new BuscadorMcpError('upstream_timeout', 'upstream_timeout');
      }
      throw new BuscadorMcpError('upstream_unreachable', redactSecrets(error?.message, secrets));
    }

    const text = await readTextLimited(response);
    if (!response.ok) {
      throw new BuscadorMcpError(
        classifyHttpError(response.status),
        `upstream_http_${response.status}`,
        { http_status: response.status },
      );
    }

    try {
      return { payload: JSON.parse(text), httpStatus: response.status };
    } catch {
      throw new BuscadorMcpError('invalid_response', 'upstream_invalid_json', { http_status: response.status });
    }
  } finally {
    clearTimeout(timer);
  }
}


async function requestNdjson({
  fetchImpl,
  url,
  init,
  timeoutMs,
  secrets = [],
}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    let response;
    try {
      response = await fetchImpl(url, { ...init, redirect: 'error', signal: controller.signal });
    } catch (error) {
      if (controller.signal.aborted) {
        throw new BuscadorMcpError('upstream_timeout', 'upstream_timeout');
      }
      throw new BuscadorMcpError('upstream_unreachable', redactSecrets(error?.message, secrets));
    }

    if (!response.ok) {
      await readTextLimited(response);
      throw new BuscadorMcpError(
        classifyHttpError(response.status),
        `upstream_http_${response.status}`,
        { http_status: response.status },
      );
    }
    if (!response.body) {
      throw new BuscadorMcpError('invalid_response', 'upstream_invalid_json', { http_status: response.status });
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let total = 0;
    let buffer = '';
    const events = [];
    const parseLine = (line) => {
      const trimmed = line.trim();
      if (!trimmed) return;
      try {
        const event = JSON.parse(trimmed);
        if (!isObject(event)) throw new Error('not_object');
        events.push(event);
      } catch {
        throw new BuscadorMcpError('invalid_response', 'upstream_invalid_json', { http_status: response.status });
      }
    };

    try {
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        total += value.byteLength;
        if (total > MAX_RESPONSE_BYTES) {
          throw new BuscadorMcpError('invalid_response', 'upstream_response_too_large');
        }
        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split(/\r?\n/);
        buffer = lines.pop() ?? '';
        for (const line of lines) parseLine(line);
      }
      buffer += decoder.decode();
      parseLine(buffer);
    } catch (error) {
      try {
        await reader.cancel();
      } catch {
        // The stream may already be errored/aborted; preserve the original failure.
      }
      if (error instanceof BuscadorMcpError) throw error;
      if (controller.signal.aborted) {
        throw new BuscadorMcpError('upstream_timeout', 'upstream_timeout');
      }
      throw new BuscadorMcpError(
        'upstream_unreachable',
        redactSecrets(error?.message, secrets),
      );
    } finally {
      reader.releaseLock();
    }

    if (events.length === 0) {
      throw new BuscadorMcpError('invalid_response', 'upstream_invalid_json', { http_status: response.status });
    }
    const started = events.find((event) => event?.type === 'cycle_started') ?? null;
    const finishedEvents = events.filter((event) => event?.type === 'cycle_finished');
    if (finishedEvents.length !== 1) {
      throw new BuscadorMcpError('invalid_response', 'upstream_invalid_cycle', { http_status: response.status });
    }
    const [finished] = finishedEvents;
    return {
      payload: {
        ok: finished?.ok === true,
        endpoint: 'buscador',
        cycle_id: started?.cycle_id ?? finished?.cycle_id ?? null,
        events,
      },
      httpStatus: response.status,
    };
  } finally {
    clearTimeout(timer);
  }
}
function jsonToolResult(data, isError = false) {
  return {
    content: [{ type: 'text', text: JSON.stringify(data) }],
    structuredContent: data,
    isError,
  };
}

function errorToolResult(error, secrets = []) {
  const errorClass = error instanceof BuscadorMcpError ? error.errorClass : 'invalid_response';
  const message = redactSecrets(error?.message ?? 'unknown_error', secrets);
  return jsonToolResult({ ok: false, error_class: errorClass, error: message }, true);
}

function defaultKeyProvider() {
  return process.env.BUSCADOR_MCP_KEY?.trim() ?? '';
}

function parseTimeout() {
  const raw = Number.parseInt(process.env.BUSCADOR_MCP_UPSTREAM_TIMEOUT_MS ?? '', 10);
  if (!Number.isFinite(raw)) return DEFAULT_TIMEOUT_MS;
  return Math.max(5_000, Math.min(raw, 900_000));
}

export function createBuscadorMcpServer({
  fetchImpl = globalThis.fetch,
  keyProvider = defaultKeyProvider,
  logger = (entry) => console.error(JSON.stringify(entry)),
  now = () => Date.now(),
  runGuard = defaultRunGuard,
} = {}) {
  const server = new McpServer(
    { name: 'shopvivaliz-buscador', version: '0.1.0' },
    {
      instructions:
        'Call getBuscadorHealth before runBuscador. Never claim three-provider consensus unless complete_consensus=true.',
    },
  );

  server.registerTool(
    'getBuscadorHealth',
    {
      description: 'Check live Buscador availability and verification state for OpenAI, Anthropic and Gemini before research.',
      inputSchema: z.object({
        profile: z.enum(BUSCADOR_PROFILES).default('deep_research'),
      }).strict(),
      annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
      },
    },
    async ({ profile }) => {
      const started = now();
      try {
        const url = new URL(BUSCADOR_UPSTREAM_URL);
        url.searchParams.set('health', '1');
        url.searchParams.set('profile', profile);
        const { payload, httpStatus } = await requestJson({
          fetchImpl,
          url,
          init: { method: 'GET', headers: { Accept: 'application/json' } },
          timeoutMs: HEALTH_TIMEOUT_MS,
        });
        const result = normalizeHealth(payload);
        logger({
          tool: 'getBuscadorHealth',
          duration_ms: Math.max(0, now() - started),
          profile,
          http_status: httpStatus,
          providers: Object.fromEntries(
            BUSCADOR_PROVIDERS.map((p) => [p, result.providers?.[p]?.health ?? 'missing']),
          ),
          ok: result.ok,
        });
        return jsonToolResult(result);
      } catch (error) {
        logger({
          tool: 'getBuscadorHealth',
          duration_ms: Math.max(0, now() - started),
          profile,
          error_class: error?.errorClass ?? 'invalid_response',
          ok: false,
        });
        return errorToolResult(error);
      }
    },
  );

  server.registerTool(
    'runBuscador',
    {
      description: 'Run a finite multi-provider Buscador research cycle. Use only after health; complete consensus requires OpenAI, Anthropic and Gemini.',
      inputSchema: z.object({
        message: z.string().trim().min(1).max(MAX_MESSAGE_CHARS),
        profile: z.enum(BUSCADOR_PROFILES).default('deep_research'),
        mode: z.enum(BUSCADOR_MODES).default('research'),
      }).strict(),
      annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: false,
        openWorldHint: true,
      },
    },
    async (args) => {
      const started = now();
      const key = keyProvider();
      try {
        const input = validateRunInput(args);
        if (!key) throw new BuscadorMcpError('auth_error', 'BUSCADOR_MCP_KEY_not_configured');

        const { payload, httpStatus } = await runGuard.execute(() => requestNdjson({
          fetchImpl,
          url: BUSCADOR_UPSTREAM_URL,
          init: {
            method: 'POST',
            headers: {
              Accept: 'application/x-ndjson',
              'Content-Type': 'application/json',
              Authorization: `Bearer ${key}`,
            },
            body: JSON.stringify({ ...input, stream: true }),
          },
          timeoutMs: parseTimeout(),
          secrets: [key],
        }));
        const result = normalizeRun(payload, input.mode);
        logger({
          tool: 'runBuscador',
          duration_ms: Math.max(0, now() - started),
          profile: input.profile,
          mode: input.mode,
          http_status: httpStatus,
          providers: result.provider_status,
          consensus_present: result.consensus_present,
          cycle_finished_present: result.cycle_finished_present,
          ok: result.complete_consensus,
        });
        return jsonToolResult(result);
      } catch (error) {
        logger({
          tool: 'runBuscador',
          duration_ms: Math.max(0, now() - started),
          profile: args?.profile ?? null,
          mode: args?.mode ?? null,
          error_class: error?.errorClass ?? 'invalid_input',
          ok: false,
        });
        return errorToolResult(error, [key]);
      }
    },
  );

  return server;
}
