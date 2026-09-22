# Buscador — pesquisa multi-provider

## Objetivo

O Buscador é um orquestrador reutilizável para pesquisas, comparações e decisões que se beneficiam de revisão independente entre provedores.

Interface administrativa:

`/admin/buscador.php`

API:

`/api/agent/buscador.php`

A UI mostra a interação em fases:

1. pesquisa independente;
2. contraditório entre OpenAI, Claude e Gemini;
3. convergência individual;
4. síntese de consenso.

## Segurança

- A UI exige sessão administrativa.
- Para agentes, essa sessão administrativa deve ser criada pelo login nativo `/auth/login.php` com a credencial canônica do arquivo privado `admsite.txt` no Google Drive autorizado; não usar Google OAuth como fluxo operacional do admin.
- Nunca versionar, imprimir ou copiar o conteúdo de `admsite.txt`; a documentação registra apenas a localização segura.
- POST da API aceita sessão administrativa com CSRF ou chave operacional já provisionada.
- Nenhuma chave de provider é enviada ao navegador.
- A UI identifica `direto` ou `via OpenRouter`; fallback de transporte nunca é apresentado como troca silenciosa de modelo.
- Secrets são lidos apenas por `config/bootstrap-env.php` a partir do runtime protegido.
- O log persistente contém somente metadados do ciclo; prompt e respostas completas não são persistidos por padrão.
- Nunca registrar `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `GOOGLE_API_KEY`, `SQUAD_TOKEN` ou `SHOPVIVALIZ_AGENT_KEY`.

## Perfis

### `deep_research`

Preset para pesquisas aprofundadas e debates com evidência atual:

- OpenAI: `gpt-5.6-sol`, effort `xhigh`;
- Anthropic: `claude-opus-5`, effort `xhigh`;
- Gemini: `gemini-3.1-pro-preview`, thinking `HIGH`;
- web search habilitado para os três.

Por decisão operacional, Fable não faz parte de nenhum preset do Buscador.

### `balanced`

- OpenAI: `gpt-5.6-terra`, effort `high`;
- Anthropic: `claude-sonnet-5`, effort `high`;
- Gemini: `gemini-3.5-flash`, thinking `MEDIUM`.

### `fast`

Perfil de menor custo/latência para tarefas simples.

## Variáveis de ambiente

As variáveis abaixo são referências de configuração. Valores nunca devem ser versionados.

Obrigatórias para os três provedores:

- `OPENAI_API_KEY`
- `ANTHROPIC_API_KEY`
- `GEMINI_API_KEY` ou `GOOGLE_API_KEY`

Fallback opcional de transporte:

- `OPENROUTER_API_KEY` — usado somente quando a chamada direta do provider falhar; o modelo original continua identificado na resposta.

Overrides opcionais:

- `BUSCADOR_OPENAI_MODEL`
- `BUSCADOR_ANTHROPIC_MODEL`
- `BUSCADOR_GEMINI_MODEL`
- equivalentes `*_BALANCED_MODEL` e `*_FAST_MODEL`.

## Health

```bash
curl -fsS 'https://shopvivaliz.com.br/api/agent/buscador.php?health=1&profile=deep_research'
```

O health esperado contém:

- `ok=true`
- `endpoint=buscador`
- `providers` com OpenAI, Anthropic e Gemini
- modelo e esforço de cada provider
- somente booleano `configured`, nunca a credencial.

## API externa

Para automações autorizadas, use `Authorization: Bearer <SHOPVIVALIZ_AGENT_KEY>` ou `X-Agent-Key`.

Exemplo conceitual:

```bash
curl -N -X POST 'https://shopvivaliz.com.br/api/agent/buscador.php' \
  -H 'Authorization: Bearer <agent-key>' \
  -H 'Content-Type: application/json' \
  --data '{"message":"pesquise o tema X","profile":"deep_research","mode":"research","stream":true}'
```

A resposta streaming usa NDJSON. Eventos relevantes:

- `cycle_started`
- `phase_started`
- `agent_started`
- `agent_message`
- `agent_error`
- `consensus`
- `cycle_finished`

## Testes

```bash
php -l includes/buscador-core.php
php -l api/agent/buscador.php
php -l admin/buscador.php
php tests/buscador-core-test.php
```

O teste também falha caso o nome `fable` apareça em qualquer preset.
