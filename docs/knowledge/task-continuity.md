# Global task continuity
Policy: `GLOBAL_TASK_CONTINUITY_V8`.

This repository consumes the canonical controller in `Vivaliz-site/site-shopvivaliz`.
The local adapter pins `repository=Vivaliz-site/buscador`, fails closed if the A1 controller is unavailable,
and requires a real `continuity_e2e_pass` for certification. Background recovery remains
Gemini-only; Codex remains the last explicit finite option.
