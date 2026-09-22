import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import readline from 'node:readline';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ALLOWED_MODELS = new Set([
  'gpt-5.6-sol',
  'gpt-5.6-terra',
  'gpt-5.6-luna',
]);
const ALLOWED_EFFORTS = new Set(['xhigh', 'high', 'medium', 'low']);
const DEFAULT_PORT = 17656;
const MAX_BODY_BYTES = 262144;
const DEFAULT_TIMEOUT_MS = 180000;
const HEALTH_CACHE_MS = 60000;

export function sanitizeBridgeError(value) {
  return String(value ?? '')
    .replace(/Bearer\s+\S+/gi, 'Bearer [redacted]')
    .replace(/sk-[A-Za-z0-9_-]+/g, '[redacted]')
    .replace(/AIza[A-Za-z0-9_-]+/g, '[redacted]')
    .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '[redacted-email]')
    .slice(0, 400);
}

export function exactModelMatches(requested, actual) {
  return String(requested) === String(actual);
}

export function classifyRateLimit(rateLimits) {
  if (!rateLimits || typeof rateLimits !== 'object') return 'unknown';
  if (rateLimits.rateLimitReachedType) return 'exhausted';
  const windows = [rateLimits.primary, rateLimits.secondary].filter(Boolean);
  return windows.some((entry) => Number(entry?.usedPercent ?? 0) >= 100)
    ? 'exhausted'
    : 'available';
}

export function remainingRequestMs(deadlineMs, nowMs = Date.now(), capMs = Infinity) {
  const remaining = Number(deadlineMs) - Number(nowMs);
  if (!Number.isFinite(remaining) || remaining <= 0) throw new Error('request_timeout');
  return Math.max(1, Math.min(remaining, Number(capMs)));
}

export function resolveCodexWebSearchMode(webSearch, configuredMode = process.env.BUSCADOR_CODEX_WEB_SEARCH_MODE) {
  if (webSearch !== true) return 'disabled';
  const mode = String(configuredMode ?? '').trim().toLowerCase() || 'live';
  if (!['cached', 'live'].includes(mode)) throw new Error('invalid_web_search_mode');
  return mode;
}

export function isDirectInvocation(moduleUrl, argvPath) {
  if (!argvPath) return false;
  try {
    const modulePath = fs.realpathSync(fileURLToPath(moduleUrl));
    const invokedPath = fs.realpathSync(path.resolve(argvPath));
    return modulePath === invokedPath;
  } catch {
    return false;
  }
}

export function validateRequest(input) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    throw new Error('invalid_request');
  }
  const model = String(input.model ?? '').trim();
  const effort = String(input.effort ?? '').trim().toLowerCase();
  const prompt = String(input.prompt ?? '').trim();
  const webSearch = input.web_search === true;

  if (!ALLOWED_MODELS.has(model)) throw new Error('invalid_model');
  if (!ALLOWED_EFFORTS.has(effort)) throw new Error('invalid_effort');
  if (!prompt || prompt.length > 120000) throw new Error('invalid_prompt');

  return { model, effort, prompt, web_search: webSearch };
}

function classifyFailure(error) {
  const text = String(error?.message ?? error ?? '').toLowerCase();
  if (text.includes('timeout')) return 'timeout';
  if (text.includes('usagelimitexceeded') || text.includes('usage limit')
      || text.includes('rate limit') || text.includes('quota')
      || text.includes('out of credits')) return 'quota';
  if (text.includes('unauthorized') || text.includes('authentication')
      || text.includes('not logged in') || text.includes('token expired')) return 'auth';
  if (text.includes('model_mismatch') || text.includes('invalid_model')) return 'model';
  return 'transport';
}

function extractUrls(text) {
  const found = String(text ?? '').match(/https?:\/\/[^\s<>()\[\]{}"']+/g) ?? [];
  return [...new Set(found.map((url) => url.replace(/[.,;:!?]+$/, '')))].slice(0, 30);
}

function realCodexPath() {
  return process.env.BUSCADOR_CODEX_REAL
    || process.env.CODEX_REAL
    || path.join(os.homedir(), '.local/lib/node_modules/@openai/codex/bin/codex.js');
}

function businessHome() {
  return process.env.BUSCADOR_CODEX_BUSINESS_HOME
    || path.join(os.homedir(), '.codex-business');
}

function configuredProfileHomes() {
  const explicit = String(process.env.BUSCADOR_CODEX_PROFILE_HOMES ?? '').trim();
  if (explicit) {
    return explicit.split(',').map((p) => p.trim()).filter(Boolean);
  }

  const root = businessHome();
  let dirs = [];
  try {
    dirs = fs.readdirSync(root, { withFileTypes: true })
      .filter((entry) => entry.isDirectory())
      .map((entry) => path.join(root, entry.name))
      .filter((dir) => fs.existsSync(path.join(dir, 'auth.json')));
  } catch {}

  const statePath = path.join(root, 'failover-state.json');
  try {
    const preferred = JSON.parse(fs.readFileSync(statePath, 'utf8')).preferred_profile;
    dirs.sort((a, b) => (path.basename(a) === preferred ? -1 : path.basename(b) === preferred ? 1 : 0));
  } catch {}
  return dirs;
}
class AppServerClient {
  constructor(profileHome, request) {
    this.profileHome = profileHome;
    this.request = request;
    this.proc = null;
    this.pending = new Map();
    this.seq = 0;
    this.agentText = '';
    this.turnDone = null;
    this.stderr = '';
    this.failed = null;
  }

  async start(deadlineMs) {
    const args = [
      'app-server', '--stdio',
      '-c', `web_search="${resolveCodexWebSearchMode(this.request.web_search)}"`,
      '-c', `model_reasoning_effort="${this.request.effort}"`,
      '-c', 'features.shell_tool=false',
      '-c', 'agents.enabled=false',
      '-c', 'allow_login_shell=false',
    ];
    const env = { ...process.env, CODEX_HOME: this.profileHome };
    delete env.OPENAI_API_KEY;
    delete env.CODEX_API_KEY;

    this.proc = spawn(realCodexPath(), args, { stdio: ['pipe', 'pipe', 'pipe'], env });
    const rl = readline.createInterface({ input: this.proc.stdout });
    rl.on('line', (line) => this.onLine(line));
    this.proc.stderr.on('data', (chunk) => {
      this.stderr = (this.stderr + String(chunk)).slice(-4000);
    });
    this.proc.on('exit', () => {
      if (!this.failed) this.fail(new Error('app_server_exited'));
    });

    await this.rpc('initialize', {
      clientInfo: {
        name: 'shopvivaliz_ai_squad',
        title: 'ShopVivaliz Buscador',
        version: '1.0.0',
      },
    }, remainingRequestMs(deadlineMs, Date.now(), 10000));
    this.proc.stdin.write(JSON.stringify({ method: 'initialized', params: {} }) + '\n');
  }

  onLine(line) {
    let msg;
    try { msg = JSON.parse(line); } catch { return; }

    if (msg.id !== undefined && this.pending.has(msg.id)) {
      const pending = this.pending.get(msg.id);
      this.pending.delete(msg.id);
      clearTimeout(pending.timer);
      if (msg.error) pending.reject(new Error(msg.error.message || 'app_server_rpc_error'));
      else pending.resolve(msg.result);
      return;
    }

    if (msg.id !== undefined && msg.method) {
      this.fail(new Error('server_request_not_allowed:' + msg.method));
      return;
    }

    if (msg.method === 'item/agentMessage/delta') {
      this.agentText += String(msg.params?.delta ?? '');
    } else if (msg.method === 'item/completed' && msg.params?.item?.type === 'agentMessage') {
      this.agentText = String(msg.params.item.text ?? this.agentText);
    } else if (msg.method === 'turn/completed' && this.turnDone) {
      this.turnDone.resolve(msg.params?.turn ?? {});
      this.turnDone = null;
    } else if (msg.method === 'error' && this.turnDone) {
      const detail = msg.params?.error?.codexErrorInfo
        ? JSON.stringify(msg.params.error.codexErrorInfo)
        : String(msg.params?.error?.message ?? 'turn_error');
      this.turnDone.reject(new Error(detail));
      this.turnDone = null;
    }
  }
  rpc(method, params, timeoutMs = 10000) {
    if (!this.proc?.stdin?.writable) return Promise.reject(new Error('app_server_not_running'));
    return new Promise((resolve, reject) => {
      const id = ++this.seq;
      const timer = setTimeout(() => {
        this.pending.delete(id);
        reject(new Error(method + '_timeout'));
      }, timeoutMs);
      this.pending.set(id, { resolve, reject, timer });
      this.proc.stdin.write(JSON.stringify({ method, id, params }) + '\n');
    });
  }

  waitForTurn(timeoutMs) {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.turnDone = null;
        reject(new Error('turn_timeout'));
      }, timeoutMs);
      this.turnDone = {
        resolve: (value) => { clearTimeout(timer); resolve(value); },
        reject: (error) => { clearTimeout(timer); reject(error); },
      };
    });
  }

  fail(error) {
    if (this.failed) return;
    this.failed = error;
    for (const pending of this.pending.values()) {
      clearTimeout(pending.timer);
      pending.reject(error);
    }
    this.pending.clear();
    if (this.turnDone) {
      this.turnDone.reject(error);
      this.turnDone = null;
    }
  }

  close() {
    if (this.proc && !this.proc.killed) this.proc.kill('SIGTERM');
  }
}

async function probeProfile(profileHome, request, deadlineMs) {
  const client = new AppServerClient(profileHome, request);
  try {
    await client.start(deadlineMs);
    const account = await client.rpc(
      'account/read',
      { refreshToken: false },
      remainingRequestMs(deadlineMs, Date.now(), 8000)
    );
    if (account?.account?.type !== 'chatgpt') {
      throw new Error('authentication_required');
    }
    const limits = await client.rpc(
      'account/rateLimits/read',
      {},
      remainingRequestMs(deadlineMs, Date.now(), 8000)
    );
    const state = classifyRateLimit(limits?.rateLimits);
    return { client, state };
  } catch (error) {
    client.close();
    throw error;
  }
}
async function runProfile(profileHome, request, deadlineMs) {
  const { client, state } = await probeProfile(profileHome, request, deadlineMs);
  if (state === 'exhausted') {
    client.close();
    const error = new Error('usage_limit_exhausted');
    error.failureClass = 'quota';
    throw error;
  }

  const cwd = process.env.BUSCADOR_CODEX_WORKDIR
    || path.join(os.tmpdir(), 'shopvivaliz-buscador-codex-empty');
  fs.mkdirSync(cwd, { recursive: true, mode: 0o700 });

  try {
    const threadResult = await client.rpc('thread/start', {
      model: request.model,
      cwd,
      approvalPolicy: 'never',
      sandbox: 'read-only',
      ephemeral: true,
      serviceName: 'shopvivaliz_ai_squad',
    }, remainingRequestMs(deadlineMs, Date.now(), 15000));

    const effectiveModel = String(
      threadResult?.model || threadResult?.thread?.model || ''
    );
    if (!exactModelMatches(request.model, effectiveModel)) {
      throw new Error('model_mismatch');
    }

    const turnPromise = client.waitForTurn(remainingRequestMs(deadlineMs));
    await client.rpc('turn/start', {
      threadId: threadResult.thread.id,
      model: request.model,
      effort: request.effort,
      approvalPolicy: 'never',
      sandboxPolicy: { type: 'readOnly', networkAccess: false },
      input: [{ type: 'text', text: request.prompt }],
    }, remainingRequestMs(deadlineMs, Date.now(), 15000));

    const turn = await turnPromise;
    if (turn?.status !== 'completed') {
      throw new Error('turn_' + String(turn?.status ?? 'failed'));
    }
    const text = client.agentText.trim();
    if (!text) throw new Error('empty_response');

    return {
      ok: true,
      text,
      model: effectiveModel,
      transport: 'codex_chatgpt',
      sources: extractUrls(text),
      usage: {},
      profile_state: 'available',
    };
  } finally {
    client.close();
  }
}

let healthCache = { at: 0, value: null };

async function bridgeHealth() {
  const now = Date.now();
  if (healthCache.value && now - healthCache.at < HEALTH_CACHE_MS) {
    return healthCache.value;
  }
  const profiles = configuredProfileHomes();
  let available = 0;
  let exhausted = 0;
  let authenticated = 0;
  const probeRequest = {
    model: 'gpt-5.6-luna',
    effort: 'low',
    prompt: 'health',
    web_search: false,
  };
  const results = await Promise.allSettled(profiles.map(async (profile) => {
    const { client, state } = await probeProfile(profile, probeRequest, Date.now() + 20000);
    client.close();
    return state;
  }));
  for (const result of results) {
    if (result.status !== 'fulfilled') continue;
    authenticated++;
    if (result.value === 'exhausted') exhausted++;
    else available++;
  }

  const value = {
    ok: fs.existsSync(realCodexPath()) && authenticated > 0,
    endpoint: 'buscador-codex-bridge',
    auth_mode: authenticated > 0 ? 'chatgpt' : 'unavailable',
    profile_count: profiles.length,
    authenticated_profile_count: authenticated,
    available_profile_count: available,
    exhausted_profile_count: exhausted,
    model_allowlist: [...ALLOWED_MODELS],
    web_search_mode: resolveCodexWebSearchMode(true),
  };
  healthCache = { at: now, value };
  return value;
}

async function respond(request) {
  const profiles = configuredProfileHomes();
  if (!profiles.length) {
    return { ok: false, error: 'codex_unavailable', attempts: ['auth'] };
  }
  const timeoutMs = Math.max(
    10000,
    Math.min(Number(process.env.BUSCADOR_CODEX_TIMEOUT_MS || DEFAULT_TIMEOUT_MS), 300000)
  );
  const deadlineMs = Date.now() + timeoutMs;
  const attempts = [];

  for (const profile of profiles) {
    try {
      return await runProfile(profile, request, deadlineMs);
    } catch (error) {
      attempts.push(error?.failureClass || classifyFailure(error));
    }
  }
  return { ok: false, error: 'codex_unavailable', attempts };
}

function sendJson(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
    'Cache-Control': 'no-store',
  });
  res.end(body);
}

function beginHeartbeat(res) {
  res.writeHead(200, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
    'X-Accel-Buffering': 'no',
  });
  res.write('\n');
  const timer = setInterval(() => {
    if (!res.destroyed && !res.writableEnded) res.write('\n');
  }, 15000);
  timer.unref?.();
  return (payload) => {
    clearInterval(timer);
    if (!res.writableEnded) res.end(JSON.stringify(payload));
  };
}

async function readJson(req) {
  let size = 0;
  const chunks = [];
  for await (const chunk of req) {
    size += chunk.length;
    if (size > MAX_BODY_BYTES) throw new Error('payload_too_large');
    chunks.push(chunk);
  }
  try {
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    throw new Error('invalid_json');
  }
}
export function createServer() {
  let active = 0;
  return http.createServer(async (req, res) => {
    try {
      if (req.method === 'GET' && req.url === '/health') {
        return sendJson(res, 200, await bridgeHealth());
      }
      if (req.method !== 'POST' || req.url !== '/v1/respond') {
        return sendJson(res, 404, { ok: false, error: 'not_found' });
      }
      if (active >= 2) {
        return sendJson(res, 429, { ok: false, error: 'bridge_busy' });
      }

      active++;
      try {
        const request = validateRequest(await readJson(req));
        const finish = beginHeartbeat(res);
        try {
          const result = await respond(request);
          finish(result);
          return;
        } catch (error) {
          const safe = sanitizeBridgeError(error);
          finish({ ok: false, error: safe });
          return;
        }
      } finally {
        active--;
      }
    } catch (error) {
      const safe = sanitizeBridgeError(error);
      const status = safe.includes('payload_too_large') ? 413 : 400;
      return sendJson(res, status, { ok: false, error: safe });
    }
  });
}

export async function startServer() {
  const host = '127.0.0.1';
  const port = Number(process.env.BUSCADOR_CODEX_BRIDGE_PORT || DEFAULT_PORT);
  const server = createServer();
  server.listen(port, host, () => {
    console.log(`BUSCADOR_CODEX_BRIDGE_READY host=${host} port=${port}`);
  });
  return server;
}

const invoked = isDirectInvocation(import.meta.url, process.argv[1]);
if (invoked) {
  startServer().catch((error) => {
    console.error('BUSCADOR_CODEX_BRIDGE_FATAL ' + sanitizeBridgeError(error));
    process.exit(1);
  });
}