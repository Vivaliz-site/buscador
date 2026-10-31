#!/usr/bin/env python3
"""Fail closed if an agent entrypoint loses or weakens its Retome contract."""
from __future__ import annotations

from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
ENTRYPOINTS = ("AGENTS.md", "CLAUDE.md", "GEMINI.md")
BEGIN = "<!-- RESUME_EXECUTION_GATE_V1 -->"
END = "<!-- /RESUME_EXECUTION_GATE_V1 -->"
REQUIRED = (
    "Retome", "Continue", "Prossiga", "Siga", "rules_loaded", "next_action",
    "RUNNING", "CONCLUIDO", "BLOCKED_EXTERNAL", "MFA", "RBAC", "branch protection",
)


def validate(root: Path) -> list[str]:
    failures: list[str] = []
    blocks: dict[str, str] = {}
    for name in ENTRYPOINTS:
        path = root / name
        if not path.is_file():
            failures.append(f"{name}: file missing")
            continue
        text = path.read_text(encoding="utf-8-sig", errors="replace")
        if text.count(BEGIN) != 1 or text.count(END) != 1:
            failures.append(f"{name}: exactly one complete Retome policy block required")
            continue
        head, remainder = text.split(BEGIN, 1)
        policy_block, tail = remainder.split(END, 1)
        if END in head or BEGIN in tail:
            failures.append(f"{name}: misplaced policy marker")
            continue
        missing = [term for term in REQUIRED if term not in policy_block]
        failures.extend(f"{name}: {term} missing from policy block" for term in missing)
        blocks[name] = policy_block.strip()

    if len(blocks) == len(ENTRYPOINTS) and len(set(blocks.values())) != 1:
        failures.append("Retome policy block differs between agent entrypoints")

    copilot = root / ".github" / "copilot-instructions.md"
    if copilot.is_file():
        data = copilot.read_text(encoding="utf-8-sig", errors="replace")
        if "AGENTS.md" not in data and "AI-TO-CLI-PROTOCOL.md" not in data:
            failures.append("Copilot entrypoint must reference canonical agent rules")
    return failures


def main() -> int:
    failures = validate(ROOT)
    if failures:
        print("\n".join(failures), file=sys.stderr)
        return 2
    print("RESUME_EXECUTION_GATE_V1=PASS")
    return 0


if __name__ == "__main__":
    sys.exit(main())
