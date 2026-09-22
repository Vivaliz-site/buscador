#!/usr/bin/env node
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const DEFAULT_PORT = 17657;
const DEFAULT_TIMEOUT_MS = 240000;
const MAX_BODY_BYTES = 2 * 1024 * 1024;
const MAX_OUTPUT_BYTES = 8 * 1024 * 1024;
const DEFAULT_CLAUDE_BIN = '/home/ubuntu/.local/bin/claude';
const DEFAULT_ENV_PATH = '/home/ubuntu/shopvivaliz-deploy/shared/.env';
const DEFAULT_CREDENTIALS_PATH = '/home/ubuntu/.claude/.credentials.json';
const DEFAULT_WORKDIR = '/home/ubuntu/.local/share/shopvivaliz-buscador-claude/workspace';
const MODEL_ALLOWLIST = new Set([
  'claude-opus-5',
  'claude-sonnet-5',
  'claude-haiku-4-5',
  'claude-haiku-4-5-20251001',
]);

let authState = { checked_at: 0, authenticated: false };

export function validateRequest(input) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    throw new Error('invalid_request');
  }
  const model = String(input.model || '').trim();
  const effort = String(input.effort || '').trim().toLowerCase();
  const system = String(input.system || '');
  const prompt = String(input.prompt || '');
  const webSearch = input.web_search === true;

  if (!MODEL_ALLOWLIST.has(model)) throw new Error('invalid_model');
  if (!['low', 'medium', 'high', 'xhigh', 'max'].includes(effort)) throw new Error('invalid_effort');
  if (system.length < 1 || system.length > 50000) throw new Error('invalid_system');
  if (prompt.length < 1 || prompt.length > 700000) throw new Error('invalid_prompt');
  return { model, effort, system, prompt, web_search: webSearch };
}

export function sanitizeBridgeError(value) {
  return String(value || '')
    .replace(/sk-[A-Za-z0-9_-]+/gi, '[redacted]')
    .replace(/Bearer\s+\S+/gi, 'Bearer [redacted]')
    .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '[redacted-email]')
    .slice(0, 500);
}

export function classifyClaudeError(value) {
  const text = String(value || '').toLowerCase();
  if (text.includes('oauth') || text.includes('authenticate') || text.includes('authentication') || text.includes('token expired')) return 'auth';
  if (text.includes('quota') || text.includes('usage limit') || text.includes('session limit') || text.includes('rate limit') || text.includes('credit balance')) return 'quota';
  if (text.includes('timed out') || text.includes('timeout')) return 'timeout';
  if (text.includes('model')) return 'model';
  if (text.includes('not configured') || text.includes('missing token')) return 'not_configured';
  return 'transport';
}

export function claudeFailureDetail(result) {
  let detail = String(result?.stderr || '').trim();
  if (!detail) {
    const parsed = parseClaudeOutput(result?.stdout || '');
    detail = String(parsed.result || parsed.error || result?.stdout || '').trim();
  }
  return sanitizeBridgeError(detail || ('claude_exit_' + String(result?.code ?? 'unknown')));
}

export function buildClaudeArgs(request) {
  const args = [
    '-p',
    '--output-format', 'stream-json',
    '--verbose',
    '--no-session-persistence',
    '--safe-mode',
    '--restricted',
    '--permission-mode', 'dontAsk',
    '--permission-prompts', 'none',
    '--disable-slash-commands',
    '--no-chrome',
    '--model', request.model,
    '--effort', request.effort,
    '--system-prompt', request.system,
    '--tools', request.web_search ? 'WebSearch,WebFetch' : '',
  ];
  return args;
}

export function isDirectInvocation(metaUrl, argvPath) {
  if (!argvPath) return false;
  try {
    return fs.realpathSync(fileURLToPath(metaUrl)) === fs.realpathSync(argvPath);
  } catch {
    return false;
  }
}

function envValue(name) {
  const envPath = process.env.BUSCADOR_CLAUDE_ENV_PATH || DEFAULT_ENV_PATH;
  let text = '';
  try {
    text = fs.readFileSync(envPath, 'utf8');
  } catch {
    return '';
  }
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    const idx = line.indexOf('=');
    if (idx < 1 || line.slice(0, idx).trim() !== name) continue;
    let value = line.slice(idx + 1).trim();
    if (value.length >= 2 && ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'")))) {
      value = value.slice(1, -1);
    }
    return value;
  }
  return '';
}

export function resolveClaudeAuthSource(explicitToken, credentialsPath = DEFAULT_CREDENTIALS_PATH) {
  const direct = String(explicitToken || '').trim();
  if (direct) return { mode: 'env_token', configured: true, token: direct };

  try {
    const parsed = JSON.parse(fs.readFileSync(credentialsPath, 'utf8'));
    const oauth = parsed?.claudeAiOauth;
    const configured = Boolean(
      oauth
      && typeof oauth === 'object'
      && (String(oauth.accessToken || '').trim() || String(oauth.refreshToken || '').trim())
    );
    return { mode: configured ? 'credential_store' : 'none', configured, token: '' };
  } catch {
    return { mode: 'none', configured: false, token: '' };
  }
}

function claudeAuthSource() {
  const credentialsPath = process.env.BUSCADOR_CLAUDE_CREDENTIALS_PATH || DEFAULT_CREDENTIALS_PATH;
  return resolveClaudeAuthSource(envValue('CLAUDE_CODE_OAUTH_TOKEN'), credentialsPath);
}

function claudeEnv(token = '') {
  const env = { ...process.env };
  delete env.ANTHROPIC_API_KEY;
  delete env.CLAUDE_API_KEY;
  if (String(token || '').trim()) env.CLAUDE_CODE_OAUTH_TOKEN = String(token).trim();
  else delete env.CLAUDE_CODE_OAUTH_TOKEN;
  env.HOME = process.env.BUSCADOR_CLAUDE_HOME || '/home/ubuntu';
  env.PATH = process.env.PATH || '/home/ubuntu/.local/bin:/usr/local/bin:/usr/bin:/bin';
  return env;
}

function runClaude(args, input, timeoutMs, token) {
  const bin = process.env.BUSCADOR_CLAUDE_BIN || DEFAULT_CLAUDE_BIN;
  const cwd = process.env.BUSCADOR_CLAUDE_WORKDIR || DEFAULT_WORKDIR;
  fs.mkdirSync(cwd, { recursive: true, mode: 0o700 });

  return new Promise((resolve, reject) => {
    const child = spawn(bin, args, {
      cwd,
      env: claudeEnv(token),
      stdio: ['pipe', 'pipe', 'pipe'],
    });
    let stdout = '';
    let stderr = '';
    let settled = false;

    const finish = (fn, value) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      fn(value);
    };

    const timer = setTimeout(() => {
      child.kill('SIGTERM');
      setTimeout(() => child.kill('SIGKILL'), 1500).unref();
      finish(reject, new Error('request_timeout'));
    }, timeoutMs);
    timer.unref();

    child.stdout.setEncoding('utf8');
    child.stderr.setEncoding('utf8');
    child.stdout.on('data', (chunk) => {
      stdout += chunk;
      if (stdout.length > MAX_OUTPUT_BYTES) {
        child.kill('SIGKILL');
        finish(reject, new Error('response_too_large'));
      }
    });
    child.stderr.on('data', (chunk) => {
      stderr += chunk;
      if (stderr.length > MAX_OUTPUT_BYTES) stderr = stderr.slice(-MAX_OUTPUT_BYTES);
    });
    child.on('error', (error) => finish(reject, error));
    child.on('close', (code) => finish(resolve, { code, stdout, stderr }));
    child.stdin.end(input);
  });
}

async function probeAuth() {
  const auth = claudeAuthSource();
  if (!auth.configured) {
    authState = { checked_at: Date.now(), authenticated: false };
    return false;
  }
  try {
    // `claude auth status` can report loggedIn=true for an OAuth token that
    // cannot perform inference. Health must prove the same non-interactive
    // path used by the Buscador, otherwise the UI can go falsely green.
    const status = await runClaude(['auth', 'status', '--json'], '', 8000, auth.token);
    const statusData = JSON.parse(status.stdout || '{}');
    if (status.code !== 0 || statusData?.loggedIn !== true) {
      authState = { checked_at: Date.now(), authenticated: false };
      return false;
    }

    const request = {
      model: 'claude-sonnet-5',
      effort: 'low',
      system: 'Buscador authentication health probe. Reply only OK.',
      prompt: 'OK',
      web_search: false,
    };
    const result = await runClaude(buildClaudeArgs(request), request.prompt, 20000, auth.token);
    const data = parseClaudeOutput(result.stdout || '');
    const ok = result.code === 0
      && data.is_error !== true
      && data.result !== '';
    authState = { checked_at: Date.now(), authenticated: ok };
    return ok;
  } catch {
    authState = { checked_at: Date.now(), authenticated: false };
    return false;
  }
}

function extractUrls(text) {
  const seen = new Set();
  const matches = String(text || '').match(/https?:\/\/[^\s<>"')\]]+/g) || [];
  for (const url of matches) seen.add(url.replace(/[.,;:!?]+$/, ''));
  return [...seen].slice(0, 30);
}

function collectUrlsFromValue(value, seen) {
  if (typeof value === 'string') {
    for (const url of extractUrls(value)) seen.add(url);
    return;
  }
  if (Array.isArray(value)) {
    for (const item of value) collectUrlsFromValue(item, seen);
    return;
  }
  if (!value || typeof value !== 'object') return;
  for (const nested of Object.values(value)) collectUrlsFromValue(nested, seen);
}

export function parseClaudeOutput(stdout) {
  const raw = String(stdout || '').trim();
  if (!raw) return { result: '', usage: {}, sources: [], is_error: false, error: '' };

  const records = [];
  try {
    records.push(JSON.parse(raw));
  } catch {
    for (const line of raw.split(/\r?\n/)) {
      const trimmed = line.trim();
      if (!trimmed) continue;
      try {
        records.push(JSON.parse(trimmed));
      } catch {
        // Ignore non-JSON diagnostic lines; stderr remains the authoritative
        // transport diagnostic channel.
      }
    }
  }

  if (records.length === 0) {
    return { result: '', usage: {}, sources: [], is_error: true, error: 'invalid_json' };
  }

  const final = [...records].reverse().find((entry) => entry?.type === 'result')
    || records[records.length - 1];
  const result = String(final?.result || '').trim();
  const seen = new Set(extractUrls(result));

  for (const record of records) collectUrlsFromValue(record, seen);

  return {
    result,
    usage: final?.usage && typeof final.usage === 'object' ? final.usage : {},
    sources: [...seen].slice(0, 30),
    is_error: final?.is_error === true || final?.subtype === 'error',
    error: String(final?.error?.message || final?.message || ''),
  };
}

async function answer(request) {
  const auth = claudeAuthSource();
  if (!auth.configured) throw new Error('missing token');

  const timeoutMs = Math.max(30000, Math.min(300000, Number(process.env.BUSCADOR_CLAUDE_REQUEST_TIMEOUT_MS || DEFAULT_TIMEOUT_MS)));
  const result = await runClaude(buildClaudeArgs(request), request.prompt, timeoutMs, auth.token);
  if (result.code !== 0) {
    const detail = claudeFailureDetail(result);
    throw new Error(detail || 'claude_transport_error');
  }
  const data = parseClaudeOutput(result.stdout);
  if (data.is_error === true) {
    throw new Error(sanitizeBridgeError(data.error || data.result || 'claude_error'));
  }
  const text = data.result;
  if (!text) throw new Error('empty_response');

  authState = { checked_at: Date.now(), authenticated: true };
  return {
    ok: true,
    text,
    model: request.model,
    transport: 'claude_code',
    sources: data.sources,
    usage: data.usage,
  };
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

async function handle(req, res) {
  if (req.method === 'GET' && req.url === '/health') {
    const auth = claudeAuthSource();
    const bin = process.env.BUSCADOR_CLAUDE_BIN || DEFAULT_CLAUDE_BIN;
    const binaryConfigured = fs.existsSync(bin);
    sendJson(res, 200, {
      ok: auth.configured && binaryConfigured && authState.authenticated,
      endpoint: 'buscador-claude-bridge',
      auth_mode: 'claude_code_oauth',
      token_configured: auth.configured,
      credential_store_configured: auth.mode === 'credential_store',
      auth_source: auth.mode,
      authenticated: authState.authenticated,
      binary_configured: binaryConfigured,
      model_allowlist: [...MODEL_ALLOWLIST],
    });
    return;
  }

  if (req.method !== 'POST' || req.url !== '/v1/respond') {
    sendJson(res, 404, { ok: false, error_class: 'not_found' });
    return;
  }

  let bytes = 0;
  const chunks = [];
  for await (const chunk of req) {
    bytes += chunk.length;
    if (bytes > MAX_BODY_BYTES) {
      sendJson(res, 413, { ok: false, error_class: 'request_too_large' });
      return;
    }
    chunks.push(chunk);
  }

  try {
    const parsed = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    const request = validateRequest(parsed);
    const response = await answer(request);
    sendJson(res, 200, response);
  } catch (error) {
    const safe = sanitizeBridgeError(error?.message || error);
    const errorClass = classifyClaudeError(safe);
    if (errorClass === 'auth') authState = { checked_at: Date.now(), authenticated: false };
    sendJson(res, 503, { ok: false, error_class: errorClass });
  }
}

async function main() {
  const port = Number(process.env.BUSCADOR_CLAUDE_BRIDGE_PORT || DEFAULT_PORT);
  const host = '127.0.0.1';
  const workdir = process.env.BUSCADOR_CLAUDE_WORKDIR || DEFAULT_WORKDIR;
  fs.mkdirSync(workdir, { recursive: true, mode: 0o700 });

  const server = http.createServer((req, res) => {
    handle(req, res).catch(() => sendJson(res, 500, { ok: false, error_class: 'internal' }));
  });
  server.requestTimeout = 310000;
  server.headersTimeout = 10000;
  server.listen(port, host, () => {
    probeAuth().catch(() => {});
  });
}

if (isDirectInvocation(import.meta.url, process.argv[1])) {
  main().catch(() => process.exit(1));
}