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
- Health valido: ok=true, endpoint=buscador e providers presente

## Compatibilidade

O site principal pode manter aliases ai-squad temporariamente durante a migracao, mas novas integracoes devem usar Buscador.
