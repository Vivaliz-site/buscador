# Buscador MCP privado

O servidor em `ops/buscador-mcp` expõe o Buscador ao ChatGPT/Codex via MCP sem abrir porta pública.

## Contrato

O endpoint MCP local é `http://127.0.0.1:8787/mcp` e anuncia exatamente duas tools:

- `getBuscadorHealth`
- `runBuscador`

`runBuscador` usa **streaming NDJSON** no upstream fixo `https://shopvivaliz.com.br/api/agent/buscador.php`, consome heartbeats/eventos incrementalmente e agrega o ciclo antes de devolver o resultado estruturado ao cliente MCP. O contrato upstream é uma linha JSON compacta por evento; heartbeats são linhas vazias. A tool nunca aceita URL nem controle de `stream` fornecidos pelo modelo.

Consenso completo só existe quando OpenAI, Anthropic e Gemini aparecem, todos terminam com status `ok`, o evento `consensus` existe e `cycle_finished` confirma cobertura completa.

## Segredo

O runtime lê `BUSCADOR_MCP_KEY` de:

`~/.config/shopvivaliz/buscador-mcp.env`

O arquivo deve ter permissão `0600`. O valor nunca deve aparecer em Git, unit file, argumento de processo, log ou resposta MCP.

## Instalação na backend

Executar a partir de um checkout validado do repositório:

```bash
bash ops/buscador/install-buscador-mcp-user-service.sh
```

O instalador cria release imutável em `~/.local/share/shopvivaliz-buscador-mcp/releases/`, troca o symlink `current` atomicamente e reinicia `shopvivaliz-buscador-mcp.service`.

## Validação local

`/healthz` prova somente que o processo MCP local está escutando. Ele nunca certifica health de providers nem consenso. Health real dos providers é comprovado somente pela tool MCP `getBuscadorHealth`.

Pré-requisito de persistência do serviço de usuário:

```bash
loginctl show-user ubuntu -p Linger --value
```

O valor esperado é `yes`. Sem `Linger=yes`, o instalador falha fechado.

```bash
curl -fsS http://127.0.0.1:8787/healthz
curl -sS -X POST http://127.0.0.1:8787/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

A descoberta deve retornar exatamente as duas tools acima.

## Secure MCP Tunnel

O serviço permanece em loopback. O `tunnel-client` roda na mesma backend VM e encaminha requisições pelo canal de saída para a OpenAI. Não abrir a porta 8787 no firewall.

Configurar o túnel somente com o `tunnel_id` do workspace e credencial de control plane armazenada fora da release. Antes de conectar o Plugin, executar `tunnel-client doctor` e confirmar o estado saudável.

## Gate

Não declarar APTO se qualquer um destes itens faltar:

- serviço local ativo;
- descoberta MCP com duas tools;
- health do Buscador com três providers;
- ciclo fast real;
- ciclo deep_research real;
- consenso + cycle_finished;
- túnel saudável e associado ao workspace;
- ausência de secrets em logs/respostas.
