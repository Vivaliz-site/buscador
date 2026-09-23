# Gepeto Plugin + Buscador MCP privado

Data: 2026-09-22
Status: MCP implementado e testado; rollout privado, Secure MCP Tunnel e package do Plugin ainda pendentes
Repositório desta especificação: `Vivaliz-site/buscador`

## 1. Objetivo

Transformar o Gepeto migrado em um orquestrador técnico reutilizável no ChatGPT/Codex e incorporar o Buscador multi-IA como capacidade MCP nativa do próprio Plugin, sem remover GitHub, Gmail ou Remote Desktop Commander e sem expor a VM backend diretamente à internet.

O usuário deve poder invocar `@Gepeto` e, quando a tarefa exigir pesquisa contraditória, comparação ou consenso, o Plugin deve usar o Buscador como ferramenta controlada. Gepeto continua sendo revisor/orquestrador e nunca conta como quarto provider.

## 2. Evidência da migração observada

Auditoria executada nesta sessão:

- Plugin privado identificado pelo runtime como `gpt-4d1b51dc23427fe5106bbd896a5b7a0e`;
- existe uma skill migrada, `instructions`, e ela contém as regras gerais do Gepeto;
- o índice de arquivos de conhecimento migrados está vazio: `{"files":[]}`;
- apps associados expostos pelo Plugin: Gmail, GitHub e Remote Desktop Commander;
- Gmail e GitHub responderam a probes reais de perfil nesta sessão;
- Remote Desktop Commander respondeu ao probe de conta/dispositivos, embora a VM backend esteja atualmente offline no RDC;
- Superpowers está instalado no workspace, mas não aparece entre os apps associados ao Plugin migrado;
- a Action OpenAPI antiga do Buscador não faz parte do Plugin migrado.

Esses fatos devem ser revalidados no início da implementação, pois configuração de workspace é estado temporal.

## 3. Fatos atuais da plataforma OpenAI

Documentação oficial consultada em 2026-09-22:

- Plugins podem empacotar skills, MCP ou ambos;
- skills definem o workflow; MCP fornece dados, autorização e ações em tempo real;
- packages de Plugin podem mapear servidores MCP registrados por meio da configuração de app do pacote;
- servidores MCP privados podem ser conectados ao ChatGPT em developer mode usando Secure MCP Tunnel;
- Secure MCP Tunnel é apropriado para uso privado/desenvolvimento, mas não satisfaz sozinho os requisitos de distribuição pública;
- na migração de GPT para Plugin, instruções viram skill, apps conectados são transferidos quando suportados e Actions personalizadas não são migradas automaticamente;
- recomendação de modelo do GPT não é um artefato confiável da migração e não deve ser tratada como configuração do Plugin.

Referências oficiais:

- https://developers.openai.com/plugins
- https://developers.openai.com/plugins/build/plugins
- https://developers.openai.com/plugins/build/skills
- https://developers.openai.com/plugins/build/mcp-server
- https://developers.openai.com/plugins/deploy/connect-chatgpt
- https://developers.openai.com/api/docs/guides/secure-mcp-tunnels
- https://help.openai.com/en/articles/20001519-custom-gpt-retirement-and-migration-faq

## 4. Arquitetura alvo

```text
Usuário
  |
  v
@Gepeto Plugin
  |
  +-- skill: gepeto-core
  +-- skill: shopvivaliz-ops
  +-- skill: buscador-research
  |
  +-- GitHub
  +-- Gmail
  +-- Remote Desktop Commander
  +-- Buscador MCP (registrado no workspace)
          |
          v
     Secure MCP Tunnel
          |
          v
always-free-arm-1787907847-26
  buscador-mcp
    - getBuscadorHealth
    - runBuscador
          |
          v
https://shopvivaliz.com.br/api/agent/buscador.php
          |
          v
OpenAI + Claude + Gemini
          |
          v
consenso validado
```

Não criar um segundo chatbot chamado Buscador. Buscador passa a ser uma ferramenta do Gepeto.

## 5. Separação de responsabilidades

### 5.1 Plugin Gepeto

Responsável por:

- seleção de workflows;
- regras de operação e validação;
- integração das skills;
- declaração dos apps necessários;
- experiência `@Gepeto`;
- política de quando chamar o Buscador;
- apresentação do resultado e classificação COMPROVADO/FALHOU/INCONCLUSIVO.

Não deve conter credenciais nem executar lógica de providers diretamente.

### 5.2 Buscador MCP

Responsável por:

- expor exatamente duas tools;
- validar entrada;
- autenticar no endpoint Buscador;
- normalizar resposta;
- detectar providers/eventos;
- aplicar limites de timeout/tamanho;
- redigir logs seguros;
- classificar falhas de transporte/upstream.

Não decide a política geral do Gepeto.

### 5.3 Site ShopVivaliz

Continua responsável por:

- endpoint canônico `/api/agent/buscador.php`;
- orquestração real OpenAI/Claude/Gemini;
- health real dos providers;
- consenso e eventos do ciclo.

A única mudança de autenticação prevista é aceitar uma credencial dedicada do MCP.

## 6. Fonte versionada do Plugin

O Plugin Gepeto deve deixar de depender apenas do estado editável da UI.

Criar, após aprovação desta especificação, um repositório canônico separado:

`Vivaliz-site/gepeto-plugin`

Motivo: Gepeto é mais amplo que o Buscador e não deve ficar acoplado ao repositório do motor multi-IA.

Estrutura alvo conceitual:

```text
plugin.json
.app.json
skills/
  gepeto-core/
    SKILL.md
  shopvivaliz-ops/
    SKILL.md
    references/
  buscador-research/
    SKILL.md
    agents/
      openai.yaml
docs/
  migration-audit.md
  testing.md
```

A sintaxe exata do manifest deve ser validada contra a documentação OpenAI vigente no momento da implementação. Não hardcodar schema obsoleto.

Se a UI do Plugin migrado permitir atualizar diretamente o pacote sem criar uma nova identidade, preservar o Plugin atual. Criar substituto apenas se a plataforma não permitir versionar/atualizar a identidade migrada.

## 7. Skills

### 7.1 `gepeto-core`

Evolução da atual `instructions`.

Dispara sempre que Gepeto for explicitamente invocado.

Contém somente regras gerais:

- PT-BR por padrão;
- evidência antes de conclusão;
- COMPROVADO/FALHOU/INCONCLUSIVO;
- não inventar acesso/estado/teste;
- segurança de secrets;
- ações destrutivas exigem autorização explícita;
- mudança versionada termina somente após merge + validação;
- aplicar @Superpowers em cada etapa material quando disponível.

Detalhes específicos do ShopVivaliz não devem inflar esta skill.

### 7.2 `shopvivaliz-ops`

Dispara para tarefas do ecossistema ShopVivaliz.

Deve orientar o agente a ler as fontes canônicas no GitHub antes de operar:

- `AGENTS.md`;
- `REGRAS-AGENTES-CENTRALIZADAS.md`;
- `docs/AGENT-REMOTE-ACCESS.md`;
- `docs/knowledge/host-access.md`;
- `docs/knowledge/README.md`;
- `docs/knowledge/agent-rules.md`;
- documentação específica da rotina.

Nunca copiar secrets para a skill.

### 7.3 `buscador-research`

Dispara quando o usuário pedir pesquisa multi-IA, contraditório, debate, comparação aprofundada, validação por três IAs ou consenso.

Workflow obrigatório:

1. chamar `getBuscadorHealth`;
2. exigir `ok=true`, `endpoint=buscador` e os três providers;
3. escolher `fast/parallel` para checagem curta ou `deep_research/research` para investigação complexa;
4. chamar `runBuscador`;
5. só declarar consenso completo se houver OpenAI, Anthropic e Gemini mais `consensus` e `cycle_finished`;
6. se provider faltar/falhar/timeout, relatar resultado parcial e proibir a expressão "consenso das três";
7. Gepeto revisa/sintetiza a saída, mas nunca é contado como provider.

A skill deve declarar dependência da ferramenta MCP conforme o formato vigente do Plugin.

## 8. Contrato MCP

### 8.1 `getBuscadorHealth`

Somente leitura.

Entrada opcional:

```json
{"profile":"deep_research | balanced | fast"}
```

Condições mínimas de sucesso:

- `ok === true`;
- `endpoint === "buscador"`;
- presença de `openai`, `anthropic` e `gemini`;
- não converter `configured=true` em health verde.

### 8.2 `runBuscador`

Entrada:

```json
{
  "message":"string não vazia",
  "profile":"deep_research | balanced | fast",
  "mode":"parallel | debate | research"
}
```

O adaptador força `stream=false`.

Defaults do Plugin:

- pesquisa profunda: `deep_research/research`;
- checagem rápida: `fast/parallel`.

Consenso completo exige:

- `ok=true`;
- providers OpenAI + Anthropic + Gemini;
- evento `consensus`;
- evento `cycle_finished`;
- nenhuma falha material de provider/fase.

## 9. Autenticação e secrets

Criar uma credencial exclusiva:

`BUSCADOR_MCP_KEY`

Não reutilizar permanentemente `GEPETO_ACTION_KEY`.

No site:

- adicionar `BUSCADOR_MCP_KEY` à lista aceita por `/api/agent/buscador.php`;
- preservar compatibilidade com credenciais existentes;
- manter comparação segura;
- não registrar o valor.

No backend:

- armazenar fora da release;
- permissão mínima, preferencialmente `0600`;
- nunca incluir o valor em argumento de processo, log, PR, issue ou resposta MCP.

O Secure MCP Tunnel usa credencial própria, separada de `BUSCADOR_MCP_KEY`.

## 10. Transporte e exposição

Fase privada:

- Buscador MCP somente na backend VM;
- preferir stdio controlado pelo tunnel-client quando suportado;
- se HTTP for necessário, bind exclusivo em `127.0.0.1`;
- nenhuma nova porta de entrada pública;
- Secure MCP Tunnel inicia conexão de saída;
- MCP registrado no workspace recebe um ID técnico;
- o package Gepeto mapeia esse servidor registrado como dependência/app.

Fase pública futura não faz parte desta entrega.

## 11. Persistência

O servidor/túnel deve sobreviver a reinício da backend VM.

Usar systemd com:

- usuário não-root;
- `Restart=on-failure` ou política equivalente;
- limits/circuit breaker;
- network dependency;
- logs redigidos;
- nenhum daemon de IA pago.

O serviço MCP é determinístico; ele somente chama IA quando uma tool é explicitamente executada por uma tarefa finita.

## 12. Segurança

- duas tools allowlisted e nenhuma terceira;
- schema estrito;
- limite defensivo de tamanho para `message`;
- upstream fixo `https://shopvivaliz.com.br/api/agent/buscador.php`;
- TLS obrigatório;
- redirects inesperados proibidos;
- resposta com tamanho máximo;
- rate limit/circuit breaker;
- sem shell arbitrário;
- sem URL fornecida pelo modelo;
- nenhuma tool destrutiva;
- prompts são dados, não comandos operacionais;
- nenhuma resposta ou log deve conter chave/cookie/token;
- falha de auth deve ser diferenciada de falha de provider.

## 13. Timeouts

O Buscador pode executar ciclos longos.

Implementação inicial:

1. smoke com `fast/parallel`;
2. medir o caminho real ChatGPT -> Plugin -> Tunnel -> MCP -> Buscador;
3. executar `deep_research/research`;
4. registrar duração e ponto de falha.

Se o caminho real impor timeout menor que um ciclo profundo, parar. O protocolo assíncrono `start/poll` é uma mudança de contrato e exige nova revisão de design; não implementá-lo preventivamente.

## 14. Observabilidade

Registrar metadados, sem conteúdo sensível:

- tool;
- início/fim;
- duração;
- profile/mode;
- HTTP status;
- providers observados;
- consensus presente;
- cycle_finished presente;
- request/correlation id não sensível;
- classe de erro.

Classes mínimas:

- `invalid_input`;
- `auth_error`;
- `upstream_unreachable`;
- `upstream_timeout`;
- `invalid_response`;
- `provider_incomplete`;
- `tunnel_unavailable`.

## 15. Testes do MCP

### Unidade

- schema/defaults;
- redaction;
- erros;
- providers;
- eventos;
- timeout;
- tamanho máximo;
- rejeição de URL/upstream arbitrário.

### Contrato

Fixtures para:

- health saudável;
- configured sem verified;
- provider ausente;
- 401;
- 422;
- 429;
- 5xx;
- JSON inválido;
- ciclo completo;
- ciclo parcial.

### Integração real

- sem chave -> 401;
- `BUSCADOR_MCP_KEY` -> auth aceita;
- health real;
- fast real;
- três providers;
- consensus;
- cycle_finished.

## 16. Testes das skills

Para cada skill, testar:

- pedido direto que deve ativar;
- pedido indireto equivalente;
- entrada incompleta;
- pedido que não deve ativar;
- edge case que não pode inventar informação.

Para `buscador-research`, adicionar casos adversariais:

- health com provider ausente;
- ciclo parcial;
- consenso ausente;
- timeout;
- usuário pedindo para "assumir que os três concordaram".

Nenhum desses casos pode produzir falso consenso.

## 17. E2E do Plugin Gepeto

Executar em chat novo no mesmo Plugin que será usado em produção.

Gate mínimo:

1. `@Gepeto` carrega a skill core;
2. tarefa ShopVivaliz carrega regras operacionais;
3. GitHub funciona;
4. Gmail funciona quando necessário;
5. RDC funciona quando o dispositivo está online;
6. Buscador MCP aparece como ferramenta do próprio Plugin;
7. exatamente duas tools do Buscador estão disponíveis;
8. health real passa;
9. pesquisa fast passa;
10. pesquisa deep_research passa;
11. saída comprova os três providers;
12. consensus + cycle_finished presentes;
13. Gepeto não se conta como quarto provider;
14. nenhuma credencial aparece na conversa/logs;
15. um prompt não relacionado não chama Buscador desnecessariamente.

## 18. Repositórios e fluxo de entrega

### 18.1 `Vivaliz-site/gepeto-plugin`

Novo repositório canônico para package, skills e mapeamento dos apps.

Fluxo:

```text
branch -> testes -> commit -> push -> PR -> checks -> review -> merge -> instalação/atualização privada -> E2E
```

### 18.2 `Vivaliz-site/buscador`

Servidor MCP, testes e operação do tunnel-client.

### 18.3 `Vivaliz-site/site-shopvivaliz`

Somente mudança necessária ao endpoint/autenticação e documentação canônica.

Produção continua com releases imutáveis e auto-gate.

## 19. Migração do Plugin atual para fonte versionada

Princípio: preservar a identidade atual sempre que a plataforma permitir.

Procedimento:

1. auditar o Plugin migrado na UI/backend browser;
2. exportar/reproduzir de forma segura apenas configuração não secreta;
3. versionar skills;
4. preservar Gmail, GitHub e RDC;
5. registrar o Buscador MCP no workspace;
6. mapear o ID técnico do MCP ao package;
7. atualizar o Plugin atual, se suportado;
8. somente criar um novo Plugin se não existir caminho de atualização da identidade migrada;
9. comparar comportamento antigo/novo antes de trocar;
10. nunca remover o Plugin atual antes do aceite do substituto.

## 20. Estado operacional da backend como pré-requisito

A backend VM é dependência obrigatória para navegador, MCP e túnel.

Antes de qualquer deploy:

- lifecycle OCI deve estar RUNNING;
- acesso administrativo deve responder;
- browser VM deve estar utilizável;
- não pode existir hang de SSH/RDC;
- disco/memória/load devem ser inspecionados;
- serviços preexistentes devem ser inventariados para evitar concorrência.

Falha desse gate bloqueia implementação/deploy, mas não invalida o design.

## 21. Rollback

Plugin:

- remover apenas o mapeamento/dependência do Buscador;
- preservar skills/apps preexistentes;
- reverter package para última versão aceita.

Backend:

- parar/desabilitar MCP/túnel;
- revogar `BUSCADOR_MCP_KEY`.

Site:

- endpoint Buscador permanece existente;
- retirar a nova credencial apenas após confirmar que não há consumidor.

Rollback não remove GitHub, Gmail ou RDC do Gepeto.

## 22. Fora de escopo

- quarto provider;
- Fable;
- escrita em ERP/site via Buscador MCP;
- tool de shell genérica no MCP;
- endpoint MCP público;
- publicação pública do Plugin;
- job assíncrono antes de evidência de timeout;
- remover apps existentes do Gepeto;
- escolher modelo por hardcode no Plugin;
- alterar a lógica interna de consenso do Buscador sem achado específico.

## 23. Gate final

Somente declarar APTO quando houver evidência objetiva:

```text
GEPETO_PLUGIN_IDENTITY_PRESERVED=true
GEPETO_CORE_SKILL_OK=true
SHOPVIVALIZ_OPS_SKILL_OK=true
BUSCADOR_RESEARCH_SKILL_OK=true
GITHUB_APP_OK=true
GMAIL_APP_OK=true
RDC_APP_OK=true
MCP_SERVER_HEALTHY=true
TUNNEL_HEALTHY=true
BUSCADOR_MCP_MAPPED_TO_GEPETO=true
TOOLS_EXACTLY=getBuscadorHealth,runBuscador
BUSCADOR_HEALTH_OK=true
PROVIDERS=openai,anthropic,gemini
FAST_CYCLE_OK=true
DEEP_RESEARCH_CYCLE_OK=true
CONSENSUS_PRESENT=true
CYCLE_FINISHED_PRESENT=true
GEPETO_NOT_COUNTED_AS_PROVIDER=true
SECRETS_EXPOSED=false
REBOOT_PERSISTENCE_OK=true
AUDIT_VERDICT=APTO
```

Ausência, inferência ou teste parcial de qualquer requisito impede `APTO`.
