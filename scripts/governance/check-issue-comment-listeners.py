#!/usr/bin/env python3
"""Governance guard: cap direct top-level `issue_comment` GitHub Actions
listeners in this repository at 1.

A repo with more than one workflow directly reacting to `issue_comment`
events is the exact pattern that let a same-repo PR-comment listener race
against another comment-triggered workflow. This guard scans the real,
active workflow files under `.github/workflows/` (skipping anything
`.disabled` or living in an archive/historical directory) and fails when
more than one workflow declares `issue_comment` as a direct top-level
trigger.

Only direct top-level triggers count. A workflow that merely mentions the
string "issue_comment" in a step, comment, or nested field is not a
listener and must not be counted.

Usage:
    python3 scripts/governance/check-issue-comment-listeners.py \
        [--workflows-root PATH]

Exit code 0 when the count is 0 or 1; exit code 1 when it is 2 or more.
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path
from typing import List

WORKFLOW_EXTENSIONS = (".yml", ".yaml")
ARCHIVE_MARKERS = ("archive", "historical")


def _is_skipped(path: Path, workflows_root: Path) -> bool:
    if path.name.endswith(".disabled"):
        return True
    if any(part.endswith(".disabled") for part in path.parts):
        return True
    relative_parts = path.relative_to(workflows_root).parts[:-1]
    for part in relative_parts:
        lowered = part.lower()
        if any(marker in lowered for marker in ARCHIVE_MARKERS):
            return True
    return False


def _strip_comment(line: str) -> str:
    """Best-effort removal of a trailing `#` comment, respecting quotes."""
    in_single = False
    in_double = False
    for idx, ch in enumerate(line):
        if ch == "'" and not in_double:
            in_single = not in_single
        elif ch == '"' and not in_single:
            in_double = not in_double
        elif ch == "#" and not in_single and not in_double:
            return line[:idx]
    return line


def _leading_spaces(line: str) -> int:
    return len(line) - len(line.lstrip(" "))


def _extract_on_block(lines: List[str]):
    """Return (inline_value, block_lines) for the top-level `on:` key.

    `inline_value` is the remainder of the `on:` line when the trigger is
    declared inline (e.g. `on: push` or `on: [push, issue_comment]`), or
    None when the trigger is declared as an indented block below `on:`.
    `block_lines` holds the raw (unstripped) lines that belong to that
    block, in the case of a block-style declaration.
    """
    for i, raw_line in enumerate(lines):
        line = _strip_comment(raw_line).rstrip("\n")
        if _leading_spaces(line) != 0:
            continue
        stripped = line.strip()
        key = None
        rest = ""
        for candidate in ("on:", '"on":', "'on':"):
            if stripped == candidate.rstrip(":") + ":" or stripped.startswith(candidate):
                key = candidate
                rest = stripped[len(candidate):].strip()
                break
        if key is None:
            continue
        if rest:
            return rest, []
        block = []
        for follow in lines[i + 1:]:
            follow_stripped = _strip_comment(follow).rstrip("\n")
            if follow_stripped.strip() == "":
                block.append(follow)
                continue
            if _leading_spaces(follow_stripped) == 0:
                break
            block.append(follow)
        return None, block
    return None, None


def _inline_has_issue_comment(inline_value: str) -> bool:
    value = inline_value.strip()
    if value.startswith("[") and value.endswith("]"):
        items = value[1:-1].split(",")
    else:
        items = [value]
    for item in items:
        token = item.strip().strip("'\"")
        if token == "issue_comment":
            return True
    return False


def _block_has_issue_comment(block_lines: List[str]) -> bool:
    if not block_lines:
        return False
    first_indent = None
    for raw_line in block_lines:
        line = _strip_comment(raw_line).rstrip("\n")
        if line.strip() == "":
            continue
        indent = _leading_spaces(line)
        if first_indent is None:
            first_indent = indent
        if indent != first_indent:
            continue
        stripped = line.strip()
        if stripped.startswith("- "):
            token = stripped[2:].strip().strip("'\"")
            if token == "issue_comment":
                return True
            continue
        for candidate in ("issue_comment:", '"issue_comment":', "'issue_comment':"):
            if stripped == candidate.rstrip(":") + ":" or stripped.startswith(candidate):
                return True
    return False


def workflow_has_issue_comment_trigger(text: str) -> bool:
    lines = text.splitlines(keepends=True)
    inline_value, block_lines = _extract_on_block(lines)
    if inline_value is not None:
        return _inline_has_issue_comment(inline_value)
    if block_lines is not None:
        return _block_has_issue_comment(block_lines)
    return False


def find_issue_comment_listeners(root: Path) -> List[Path]:
    workflows_root = Path(root) / ".github" / "workflows"
    if not workflows_root.is_dir():
        return []
    findings: List[Path] = []
    for path in sorted(workflows_root.rglob("*")):
        if not path.is_file():
            continue
        if path.suffix not in WORKFLOW_EXTENSIONS:
            continue
        if _is_skipped(path, workflows_root):
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        if workflow_has_issue_comment_trigger(text):
            findings.append(path)
    return findings


def main(argv: List[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--workflows-root",
        default=".",
        help="Repository root to scan for .github/workflows (default: current directory)",
    )
    args = parser.parse_args(argv)

    root = Path(args.workflows_root).resolve()
    findings = find_issue_comment_listeners(root)

    print(f"issue_comment direct top-level listeners found: {len(findings)}")
    for path in findings:
        print(f"  - {path}")

    if len(findings) > 1:
        print(
            "FAIL: more than 1 active direct issue_comment listener "
            "(ACTIVE_DIRECT_ISSUE_COMMENT_LISTENERS <= 1 invariant violated)."
        )
        return 1

    print("OK: at most 1 active direct issue_comment listener.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
