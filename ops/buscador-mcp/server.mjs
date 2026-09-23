import { createMcpExpressApp } from '@modelcontextprotocol/express';
import { toNodeHandler } from '@modelcontextprotocol/node';
import { createMcpHandler } from '@modelcontextprotocol/server';

import { createBuscadorMcpServer } from './lib.mjs';

const host = process.env.BUSCADOR_MCP_HOST?.trim() || '127.0.0.1';
const port = Number.parseInt(process.env.BUSCADOR_MCP_PORT || '8787', 10);

if (!['127.0.0.1', '::1', 'localhost'].includes(host)) {
  throw new Error('BUSCADOR_MCP_HOST must remain loopback-only');
}
if (!Number.isInteger(port) || port < 1024 || port > 65535) {
  throw new Error('BUSCADOR_MCP_PORT must be an integer between 1024 and 65535');
}

const handler = createMcpHandler(
  () => createBuscadorMcpServer(),
  {
    maxRequestBodySize: 131_072,
    onerror: (error) => {
      console.error(JSON.stringify({
        component: 'buscador-mcp',
        error_class: 'mcp_protocol_error',
        message: String(error?.message || 'unknown_error').slice(0, 400),
      }));
    },
  },
);
const nodeHandler = toNodeHandler(handler);
const app = createMcpExpressApp({ host });

app.get('/healthz', (_req, res) => {
  res.json({
    ok: true,
    service: 'buscador-mcp',
    transport: 'streamable-http',
    tools: ['getBuscadorHealth', 'runBuscador'],
  });
});

app.all('/mcp', (req, res) => {
  void nodeHandler(req, res, req.body);
});

const httpServer = app.listen(port, host, () => {
  console.error(JSON.stringify({
    component: 'buscador-mcp',
    event: 'listening',
    host,
    port,
  }));
});

async function shutdown(signal) {
  console.error(JSON.stringify({ component: 'buscador-mcp', event: 'shutdown', signal }));
  httpServer.close(async () => {
    await handler.close();
    process.exit(0);
  });
}

process.on('SIGTERM', () => void shutdown('SIGTERM'));
process.on('SIGINT', () => void shutdown('SIGINT'));
