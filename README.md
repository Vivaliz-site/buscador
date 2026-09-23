# Buscador

Buscador multi-IA da ShopVivaliz para pesquisa profunda, debate contraditorio e consenso entre OpenAI, Claude e Gemini.

## Objetivo

Este repositorio separa o motor de pesquisa/debate do site principal para evitar confusao com o Squad Chat generico.

## Contrato

- Nome publico: Buscador
- Endpoint canonico: /api/agent/buscador.php
- UI canonica: /admin/buscador.php
- Perfil principal: deep_research
- Fable: proibido
- Health estrutural: ok=true, endpoint=buscador e providers presente\n- Health verificado: OpenAI, Anthropic e Gemini com health=verified\n- Consenso completo: tres providers + consensus + cycle_finished + cobertura completa

## Compatibilidade

O site principal pode manter aliases ai-squad temporariamente durante a migracao, mas novas integracoes devem usar Buscador.


## Integração com o runtime de produção

Este repositório é self-contained para desenvolvimento e validação do motor Buscador, mas o runtime publicado da ShopVivaliz continua empacotado e deployado por `Vivaliz-site/site-shopvivaliz`.

Por isso:

- merge/CI verde aqui não provam deploy em produção;
- invariantes críticos compartilhados com o runtime do site devem permanecer compatíveis;
- o contrato `tests/buscador-runtime-parity-contract-test.sh` compara proteções essenciais do endpoint canônico nos dois repositórios;
- a proteção de drain usa os mesmos locks `ai-squad-deploy-gate.lock` e `ai-squad-runtime.lock` enquanto a migração interna de nomes ainda não terminou.

A extração só deve ser considerada concluída quando existir um mecanismo explícito de build/deploy que publique este repositório diretamente e a validação pós-deploy comprovar o mesmo SHA/conteúdo.
