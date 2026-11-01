<!-- MANDATORY_AGENT_CHECKLIST_V1 -->
## Checklist de execucao obrigatorio (GPT, Codex, Claude, Gemini, Copilot e outros)
Antes de cada tarefa e em cada nova conversa, carregar no `main` a documentacao canonica ShopVivaliz e as regras deste repositorio. Iniciar ou recuperar a tarefa original com `scripts/agent_task_state.py` conectado ao controlador canonico autorizado, sem criar duplicata nem inferir IDs de conversa. Registrar plano com `plan-checklist --task ID --step "..."`, consultar a proxima etapa via `next-step --task ID` ANTES de agir, executar e validar cada etapa, marcar com `check-step --task ID --step-id N --evidence PROVA --verification VALIDACAO`, e so entao passar para a proxima. Novas exigencias entram via `add-checklist-step`; nenhuma etapa e descartada. Os comandos de registro falham sem o controlador canonico provisionado; nao simular estado local, nem declarar sucesso por ACK, PR, CI verde ou processo ativo. `ready`/`complete` exigem a meta original comprovada, correcoes, regressao, E2E/UI quando aplicavel e merge/deploy validado. Se uma rota tecnica falhar, avaliar alternativas EXISTENTES e independentemente autorizadas (MCP, GitHub, RDC, SSH privado/Tailscale, tuneis, APIs, hosts e ferramentas disponiveis); documentar cada tentativa. `BLOCKED_EXTERNAL` so com impedimento objetivo apos esgotar rotas seguras; jamais burlar MFA, RBAC, 401/403, branch protection, leases ou aprovacoes. O controlador e contingencia, nao substitui a obrigacao de o agente executar e continuar ate concluir. Este contrato deve ser testado e aplicado no CI, nao apenas estar escrito.
<!-- /MANDATORY_AGENT_CHECKLIST_V1 -->

<!-- RESUME_EXECUTION_GATE_V1 -->
## Retome / Continue / Prossiga / Siga: execucao obrigatoria ate verificacao

Toda ordem Retome, Continue, Prossiga ou Siga e uma tarefa operacional de execucao, nao pedido de plano, status ou confirmacao generica. O agente principal, Claude, Gemini, Copilot e qualquer subagente devem ler as regras atuais do AGENTS.md no main e registrar prova nao secreta rules_loaded (fonte, ref, SHA, data) antes de mutacoes. Recuperar task_id, ultimo checkpoint e next_action com origem real; nao criar identidades de conversa ou sessoes ficticias.
Executar a proxima acao permitida; investigar erro, corrigir a causa, testar, fazer merge/deploy quando autorizado e validar o objetivo original no runtime. Persistir RUNNING com next_action real enquanto houver caminho seguro viavel; erro de ferramenta, timeout, status Pensando, PR ou ACK nao autorizam encerrar. CONCLUIDO exige evidencia fresca ponta a ponta; BLOCKED_EXTERNAL exige impedimento externo objetivo, alternativas seguras esgotadas e condicao exata de retomada. Quando disponivel, comprovar terminalidade com agent_task_state.py terminal --task <task_id>.
Nao contornar MFA, RBAC, aprovacao sensivel, branch protection ou direitos de outra sessao. Evitar mensagens intermediarias desnecessarias quando o proprietario pedir para nao postar; se limite externo interromper a execucao, salvar checkpoint verdadeiro e nao prometer trabalho de fundo sem mecanismo realmente ativo. Encaminhar as mesmas obrigacoes ao agente delegado.
<!-- /RESUME_EXECUTION_GATE_V1 -->

<!-- EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->
> **AUDITORIA EXTREMA V5 = UI REAL OBRIGATORIA.** Antes de declarar `APTO` ou `CONCLUIDO`, o proprio agente percorre cada jornada material na interface grafica real (navegacao, cliques, preenchimento, resultado visivel, reload/revisita, persistencia e evidencia visual com ambiente/release). API, terminal, SQL, codigo, screenshot isolado, mocks e testes apenas headless nao substituem prova pela UI. A auditoria inclui investigacao, correcao e reauditoria visual apos reparo. Nao ocultar uma UI marcando `browser.required=false` ou `journey.ui=false`. Somente componentes comprovadamente sem UI podem usar excecao com inventario negativo documentado e evidencias. Em bloqueio de sessao, esgotar rotas autorizadas; nao burlar MFA/permissaos. Leia e obedeça a secao `EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1` de `AGENTS.md` antes da tarefa.
<!-- /EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->

<!-- AUDIT_EXTERNAL_REMEDIATION_V1 -->
> **AUDITORIA EXTERNA:** também é corretiva. Auditor com acesso autorizado corrige, testa e reaudita; auditor read-only não encerra em relatório, devendo encaminhar achados a executor autorizado até correção + revalidação independente ou bloqueio externo comprovado.
<!-- /AUDIT_EXTERNAL_REMEDIATION_V1 -->

<!-- DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
> **DIAGNÓSTICO CORRETIVO GLOBAL:** diagnóstico confirmado obriga correção segura, prevenção pertinente, testes e validação real/E2E aplicável antes de `CONCLUIDO`; enquanto houver ação executável, permanecer `RUNNING`.
<!-- /DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->

<!-- GEMINI_READ_AGENTS_FIRST_V1 -->
Antes de qualquer análise, alteração, auditoria, browser, PR, deploy ou validação, leia integralmente `AGENTS.md` e siga suas regras. Em conflito, `AGENTS.md` prevalece.

