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

