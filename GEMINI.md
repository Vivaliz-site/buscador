<!-- SHOPVIVALIZ_HOST_ACCESS_CANONICAL_V2 -->
## Acesso canônico aos hosts ShopVivaliz
Leia `docs/HOST-ACCESS.md` antes de operar host/VM/runtime/browser/serviço/deploy/logs/recuperação. Fonte central: `Vivaliz-site/site-shopvivaliz:docs/knowledge/host-access.md`.
Produção: `shopvivaliz-free-a1/10.0.1.112`; backend/controller/browser: `always-free-arm-1787907847-26/10.0.1.38`; browser somente no backend; Linux por SSH privado/Tailscale; OCI Bastion para bootstrap/recovery; RustDesk GUI; Desktop Commander contingência; Fred-Win via `127.0.0.1:2222`; KOCEPSV via `127.0.0.1:2223`; `5557/5558` só bootstrap/recovery. Validar hostname/identidade/diretório/Git e nunca registrar valores secretos.
<!-- /SHOPVIVALIZ_HOST_ACCESS_CANONICAL_V2 -->

<!-- GEMINI_READ_AGENTS_FIRST_V1 -->
Antes de qualquer análise, alteração, auditoria, browser, PR, deploy ou validação, leia integralmente `AGENTS.md` e siga suas regras. Em conflito, `AGENTS.md` prevalece.

