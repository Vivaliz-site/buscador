#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "main_provenance",
    ROOT / "scripts" / "validate-main-merge-provenance.py",
)
module = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(module)


class MainMergeProvenanceTests(unittest.TestCase):
    def test_merged_pr_to_main_passes(self):
        prs = [{
            "number": 12,
            "state": "closed",
            "merged_at": "2026-09-22T10:00:00Z",
            "base": {"ref": "main"},
        }]
        ok, reasons = module.evaluate_associated_prs(prs, "main")
        self.assertTrue(ok, reasons)

    def test_no_associated_pr_fails(self):
        ok, reasons = module.evaluate_associated_prs([], "main")
        self.assertFalse(ok)
        self.assertTrue(any("no associated" in x for x in reasons))

    def test_open_pr_fails(self):
        prs = [{
            "number": 12,
            "state": "open",
            "merged_at": None,
            "base": {"ref": "main"},
        }]
        ok, _ = module.evaluate_associated_prs(prs, "main")
        self.assertFalse(ok)

    def test_wrong_base_fails(self):
        prs = [{
            "number": 12,
            "state": "closed",
            "merged_at": "2026-09-22T10:00:00Z",
            "base": {"ref": "develop"},
        }]
        ok, _ = module.evaluate_associated_prs(prs, "main")
        self.assertFalse(ok)

    def test_one_valid_pr_among_multiple_passes(self):
        prs = [
            {
                "number": 1,
                "state": "closed",
                "merged_at": None,
                "base": {"ref": "main"},
            },
            {
                "number": 2,
                "state": "closed",
                "merged_at": "2026-09-22T10:00:00Z",
                "base": {"ref": "main"},
            },
        ]
        ok, reasons = module.evaluate_associated_prs(prs, "main")
        self.assertTrue(ok, reasons)


    def test_main_guard_injects_github_token_for_global_manifest(self):
        """Read the canonical audit manifest with the existing authorized token."""
        workflow = (ROOT / ".github/workflows/absolute-audit-main-guard.yml").read_text(
            encoding="utf-8"
        )
        marker = "      - name: Validate Absolute Audit V5 on published main\n"
        self.assertIn(marker, workflow)
        validation_step = workflow.split(marker, 1)[1].split("\n      - name:", 1)[0]
        self.assertIn("GITHUB_TOKEN: ${{ github.token }}", validation_step)


if __name__ == "__main__":
    unittest.main()
