<!-- AUDIT_UI_EVIDENCE_INTEGRITY_V2 -->
## Auditoria V5 na interface grafica real, sem abandono

Todos os agentes devem executar `docs/quality/AUDIT_UI_EVIDENCE_INTEGRITY_V2.md`: UI real, cada jornada/controle/caso material, trace/video e evidencias em disco com hash recomputado, RED/GREEN/regressao, reauditoria independente e pos-deploy. API/SQL/CLI, headless-only, flags ou screenshot estatico nao autorizam APTO. Usar checkpoint `RUNNING` com `next_action` concreta ate certifier oficial produzir `AUDIT_VERDICT=APTO` e controlador confirmar terminal; falha temporaria ou PR nao encerra. Manter regras de seguranca e autorizacao.
<!-- /AUDIT_UI_EVIDENCE_INTEGRITY_V2 -->

<!-- EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->
## GATE INEGOCIAVEL: Auditoria Extrema V5 executada pela UI real

**Ordem explicita do proprietario (2026-10-13), vinculante a GPT, Claude, Gemini, Codex, subagentes, orquestradores e revisores:** toda auditoria extrema/completa/V5/aptidao de sistema que tenha UI **DEVE ser executada pelo proprio agente atraves da interface grafica real**. O auditor nao pode substituir a UI por API, terminal, SQL, inspecao de codigo, logs, mocks, healthcheck, testes unitarios, Playwright exclusivamente headless nem screenshot estatico. Esses meios sao auxiliares para investigar/reconciliar, jamais prova suficiente do funcionamento que o usuario vivencia.

1. **UI primeiro, sem atalhos:** inventariar telas, jornadas, perfis, estados e casos reais; abrir a interface publicada no host/perfil autorizado; navegar, clicar, preencher, filtrar, salvar/submeter dentro das permissoes; observar resposta visual, erros e efeitos; recarregar, sair e voltar para provar persistencia. Para Amazon Seller Central, Mercado Livre e paineis externos, auditar os casos e transicoes acessiveis na UI oficial, um a um conforme escopo, sem declarar sucesso por API somente.
2. **Evidencia obrigatoria por jornada:** registrar ambiente/URL, SHA/release quando aplicavel, sessao autorizada sem segredos, timestamp, passos efetivamente executados, capturas/trace/video, estado antes/depois, erros de console/rede, reload/revisita e reconciliacao independente dos efeitos duraveis. Screenshot isolado, abertura de pagina, resposta HTTP 200 ou teste simulado nao certificam jornada.
3. **Auditoria inclui reparo:** ao encontrar falha corrigivel no escopo autorizado, investigar causa raiz, corrigir, prevenir recorrencia, executar testes e **repetir o mesmo fluxo pela UI real apos correcao/deploy**, com evidencia independente. Nao encerrar no diagnostico, em PR ou em teste apenas de backend.
4. **Gate fail-closed, sem falso APTO:** qualquer fluxo com UI nao percorrido e comprovado permanece `NAO_VALIDADO_UI` / `NAO_APTO`; e proibido declarar `APTO`, `CONCLUIDO`, 100% ou verde. `browser.required=false` ou `journey.ui=false` nao podem ser usados para ocultar UI existente. Se realmente nao existir interface para o componente auditado, registrar inventario negativo de UI, justificativa e evidencias; validar os caminhos reais nao visuais pertinentes. Ausencia/falha transitoria de browser exige reparo/fallback autorizado antes de `BLOCKED_EXTERNAL`.
5. **Seguranca:** usar browser/desktop/MCP/RDC nos hosts e perfis previstos nos runbooks, com ownership/leases, controles de autorizacao e MFA; preservar sessoes. A auditoria nao autoriza exclusao, faturamento real, modificacao irreversivel ou acesso a segredo. Quando a acao terminal nao puder ser realizada com seguranca, testar ate o limite seguro e registrar exatamente a cobertura pendente; nunca inventar sucesso.

Fonte complementar: `AUDIT_POLICY.md`, `docs/quality/AUDIT_BROWSER_E2E_REAL_V1.md` e `docs/quality/AUDIT_ABSOLUTE_GATE_V1.md` do repositorio governado. **Este gate prevalece sobre instrucoes mais brandas que permitam certificar por API ou parecer do agente.**
<!-- /EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->

<!-- REMOTE_ACCESS_EXHAUSTION_GATE_V1 -->
## Gate obrigatório: esgotar acesso remoto antes de declarar bloqueio
Em `Retome`, `Continue`, `Prossiga`, `Siga` ou qualquer tarefa remota, uma falha de MCP/RDC/SSH/rede/autenticacao isolada **não** encerra a execução. Ler AGENTS.md, runbooks locais e `Vivaliz-site/site-shopvivaliz/docs/knowledge/host-access.md` (main atual), comprovando fonte e revisao. Identificar host, identidade e permissoes efetivas.
**Verificar todas as rotas aplicaveis, existentes e autorizadas, na prioridade do runbook:** ShopVivaliz Remote Control MCP/controller; RDC e RDC 2 se disponiveis/autorizados; SSH privado/VCN/Tailscale/tunel/reverse SSH documentado; `desktop_*`/bridge/RustDesk para GUI; OCI Bastion, OCI Compute Run Command, serial console e GitHub Actions somente para bootstrap/recovery/reparo, nunca como transporte normal de runtime. Browser ShopVivaliz permanece na VM backend, sem migracao para Windows. Para cada rota, testar conectividade, identidade e capacidade real; corrigir falhas tecnicas autorizadas, recuperar servico/sessao e aplicar retry idempotente e limitado ou fallback.
Registrar checkpoint nao secreto `remote_access_attempts` por host: rota, objetivo, `PASS/FAILED/NOT_PROVISIONED/NOT_APPLICABLE`, evidencias, causa, reparos e alternativa seguinte. Nao apresentar rota nao provisionada como testada. Manter `RUNNING` enquanto houver acesso alternativo viavel. **`BLOCKED_EXTERNAL` apenas apos exaurir e documentar todos os caminhos tecnicamente viaveis, seguros e autorizados.** `AWAITING_APPROVAL` exige identificar aprovacao obrigatoria exata. Proibidos bypass de MFA/RBAC/branch protection, exibir credenciais, abrir SSH publico, alterar browser para Windows sem autorizacao expressa ou loops infinitos.
<!-- /REMOTE_ACCESS_EXHAUSTION_GATE_V1 -->

<!-- GLOBAL_RESUME_VERIFIED_RULES_V3 -->
## Retome obrigatorio: leitura comprovada e execucao ate validacao
- `Retome`, `Continue`, `Prossiga` e `Siga` isolados sao ordens de executar a ultima tarefa pendente, nunca somente relatar status.
- Antes de cada retomada ou mutacao, ler o AGENTS.md vigente e a base canonica em Vivaliz-site/site-shopvivaliz: docs/knowledge/atendimento-chatgpt-bootstrap.md, host-access.md, README.md, agent-rules.md e runbooks especificos. Registrar checkpoint nao secreto `rules_loaded` com paths, refs, SHAs e instante; citar sem ler nao vale. Se sem acesso, recuperar leitura antes de mutacoes.
- Recuperar ultimo checkpoint e executar: identificar causa raiz, corrigir, testar, prevenir, usar fallback autorizado, continuar, validar runtime/E2E, integrar PR e deploy quando aplicavel.
- Proibido encerrar por diagnostico, relatorio, timeout, erro transitorio, CI ou PR. Antes de declarar bloqueio, esgotar e documentar todas as alternativas razoaveis, seguras e autorizadas, sem loops infinitos nem bypass de MFA, CI, aprovacoes ou seguranca.
- Manter RUNNING enquanto houver acao segura viavel; CONCLUIDO apenas com evidencias; BLOCKED_EXTERNAL somente com impedimento externo comprovado e checkpoint recuperavel. Nao prometer persistencia sem controlador ativo.
<!-- /GLOBAL_RESUME_VERIFIED_RULES_V3 -->

<!-- AUTONOMOUS_RESUME_TO_VALIDATED_COMPLETION_V1 -->
## Regra obrigatoria: retomar e executar ate conclusao comprovada

- Recuperar ultimo estado/checkpoint e preservar operacoes ja concluidas antes de agir.
- Ciclo: verificar estado -> executar -> detectar falhas -> corrigir causa raiz -> prevenir -> testar -> retomar -> validar.
- Diagnostico, relatorio, PR, timeout ou falha transitoria nao encerram a tarefa enquanto houver acao segura, autorizada e viavel.
- Recuperar navegador/MCP/RDC/API/CLI/worker/rede de forma idempotente e usar fallback autorizado, respeitando controles de seguranca.
- Usar checkpoints e logs quando disponiveis; nao alegar execucao em segundo plano sem controlador ativo.
- Manter RUNNING ate evidencia fresca de testes e validacao runtime/E2E aplicavel permitir CONCLUIDO.
- Para bloqueio externo ou aprovacao pendente, registrar evidencias, tentativas, checkpoint e acao exata para retomar. Nao contornar aprovacoes, MFA ou protecoes de branch.
- Aplicavel a agentes, subagentes, Codex e automacoes; preservar normas especificas e limites da sessao.
Fonte global: Vivaliz-site/site-shopvivaliz/REGRAS-AGENTES-CENTRALIZADAS.md.
<!-- /AUTONOMOUS_RESUME_TO_VALIDATED_COMPLETION_V1 -->

<!-- AUDIT_EXTERNAL_REMEDIATION_V1 -->
> **AUDITORIA EXTERNA TAMBÉM É CORRETIVA:** auditoria interna, externa, independente, contraditória ou feita por outro agente/revisor segue o mesmo ciclo. Auditor externo com autorização deve corrigir, testar e reauditar. Se for read-only, o relatório não encerra: os achados corrigíveis seguem para executor autorizado e permanecem em andamento até correção + revalidação independente ou bloqueio externo comprovado.
<!-- /AUDIT_EXTERNAL_REMEDIATION_V1 -->

<!-- AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1 -->
## Auditoria corretiva obrigatória

- Todo pedido de auditoria significa **investigar, corrigir, prevenir e validar**; não encerrar apenas relacionando erros.
- Todo achado material corrigível e autorizado deve ter causa raiz investigada, correção aplicada, prevenção pertinente, teste e reauditoria.
- Relatório, recomendação, plano ou issue são estados intermediários enquanto existir ação segura executável.
- APTO/COMPROVADO/CONCLUIDO exigem evidência fresca pós-correção e E2E real quando aplicável; sem falso-verde.
- Pendência só é aceitável após esgotar alternativas seguras, com evidência, causa e ação exata necessária para continuidade.
- Fonte global: `REGRAS-AGENTES-CENTRALIZADAS.md`, policy `AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1`.
<!-- /AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1 -->

<!-- DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
> **DIAGNÓSTICO É CORRETIVO EM QUALQUER TAREFA:** defeito confirmado exige correção segura, prevenção pertinente, testes e validação real/E2E aplicável antes de `CONCLUIDO`. Enquanto houver ação segura executável, permanecer `RUNNING`; somente `BLOCKED_EXTERNAL` comprovado permite encerrar sem corrigir.
<!-- /DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
<!-- GEPETO_EVERY_TASK_V1 -->
## Gepeto obrigatorio em toda tarefa
Acione o plugin Gepeto junto com Superpowers em toda tarefa e retomada, sem nova mencao do usuario. Leia e cumpra `GEPETO-POLICY.md`. Se o runtime nao expuser o plugin, registre `GEPETO_UNAVAILABLE`, informe a limitacao e continue o trabalho autorizado sem simular participacao. Aplicar um plugin nao comprova delegacao nem revisao independente.
<!-- /GEPETO_EVERY_TASK_V1 -->

# AGENTS.md

Leia README.md antes de alterar o projeto. Nao exponha secrets. Validar providers por chamada real, nao apenas configured=true.


<!-- AUDIT_ABSOLUTE_V5_ENTRYPOINT -->
## Auditoria Extrema V5 absoluta — entrada obrigatória
Antes de qualquer auditoria completa/extrema, validação de release ou declaração de aptidão, leia e execute integralmente `AUDIT_POLICY.md`, `docs/quality/AUDIT_ABSOLUTE_GATE_V1.md`, `docs/quality/AUDIT_BROWSER_E2E_REAL_V1.md`, `docs/quality/AUDIT_APTO_REMEDIATION_LOOP_V1.md`, `docs/quality/AUDIT_AUTH_CREDENTIAL_DISCOVERY_V1.md`, `docs/quality/AUDIT_PROJECT_REQUIREMENTS_V1.md` e `docs/quality/AUDIT_PROJECT_REQUIREMENTS.json`.
Se existir UI, o próprio agente executa E2E real no navegador gráfico no mesmo release. API/CLI/headless-only não certificam. Todo bloqueador executável deve ser corrigido, testado, deployado quando aplicável e reauditado até o certifier retornar `AUDIT_VERDICT=APTO`. Antes de declarar bloqueio por login/credencial, esgote a descoberta segura em todos os repositórios governados e fontes canônicas sem expor secrets.


<!-- AUDIT_MERGE_ENFORCEMENT_V1 -->
## Enforcement absoluto de merge/main
Em auditoria/aptidão, leia `docs/quality/AUDIT_MERGE_ENFORCEMENT_V1.md`. O gate local deve executar `scripts/absolute-audit-governance-validate.sh`, e todo push em `main`/`master` deve passar pelo **Absolute Audit Main Guard** com prova de PR mesclado.

<!-- GLOBAL_TASK_CONTINUITY_V8 -->
## Global task continuity V8
Tasks that can mutate code, infrastructure, data, CI, or deployment must use `python3 scripts/agent_task_state.py` to persist durable progress. This repository is pinned as `repository=Vivaliz-site/buscador`. Recoverable failures remain RUNNING; completion requires fresh verification. The adapter fails closed if the canonical A1 controller is unavailable. Background recovery is Gemini-only. Codex is never an automatic fallback and remains the last explicit finite option.
<!-- /GLOBAL_TASK_CONTINUITY_V8 -->

<!-- CHECKPOINT_FIRST_V9 -->
## Checkpoint antes da primeira etapa material
Toda tarefa potencialmente longa ou mutável deve registrar `agent_task_state.py start`
**antes** da primeira investigação extensa, chamada remota material, edição, mutação,
execução longa, delegação ou espera de CI. O objetivo é eliminar a janela em que uma
interrupção do ChatGPT ocorre antes de existir estado durável.

Depois de cada avanço material, atualize `progress` com evidência e `next_action`.
Não adie o primeiro checkpoint para depois do diagnóstico. Se o contexto atual não
consegue alcançar o controlador canônico, falhe fechado para tarefas que dependem de
retomada automática e use a rota operacional auditável que consiga registrar o estado.
<!-- /CHECKPOINT_FIRST_V9 -->

<!-- SHOPVIVALIZ_GRAPHICAL_SESSION_RECOVERY_V1 -->
## Gate obrigatorio para todos os agentes: GUI ShopVivaliz e contas ChatGPT

Antes de diagnosticar RustDesk, VM, autenticacao Dev/Atendimento ou classificar um login como perdido, **ler efetivamente no `main` de `Vivaliz-site/site-shopvivaliz`** o runbook `docs/knowledge/shopvivaliz-graphical-session-recovery.md`, junto de `AGENTS.md` e `docs/knowledge/browser-sessions.md`, registrando `rules_loaded` com SHAs. Esta regra aplica-se a ChatGPT, Claude, Gemini, Gepeto, subagentes e rotinas persistentes deste repositorio.

Fatos observados em 2026-10-11: a area XFCE/RustDesk `fredconsole/:0` foi recuperada e mostrou Chromium ChatGPT no workspace Vivaliz com Dev e Atendimento no seletor; a tela LightDM `lightdm/:1` e login do **Ubuntu**, nao login ChatGPT; os perfis corporativos independentes permanecem no usuario `fredrdp`, display `:99`, Dev CDP9559 e Atendimento CDP9556. **Ver conta no seletor nao prova autenticacao individual**: exigir identidade exata em `/api/auth/session` no respectivo perfil antes de marcar `AUTHENTICATED`/`CONCLUIDO`. Nao reutilizar cookies entre perfis, nao destruir sessoes, nao solicitar senha ja provisionada e nao redefinir o GNOME Keyring por tentativa. Usar somente senha existente + TOTP oficial conforme preferencia atual do proprietario, respeitando etapas do provedor, MFA, lease e aprovacoes. Preservar checkpointer e monitor; `RUNNING` ate progresso real.
<!-- /SHOPVIVALIZ_GRAPHICAL_SESSION_RECOVERY_V1 -->
