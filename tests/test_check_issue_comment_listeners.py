import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "governance" / "check-issue-comment-listeners.py"


def load_module():
    spec = importlib.util.spec_from_file_location("check_issue_comment_listeners", SCRIPT)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


TWO_LISTENERS = {
    "a.yml": "name: A\non:\n  issue_comment:\n    types: [created]\njobs:\n  x:\n    runs-on: ubuntu-latest\n    steps: []\n",
    "b.yml": "name: B\non: [push, issue_comment]\njobs:\n  x:\n    runs-on: ubuntu-latest\n    steps: []\n",
    "c.yml": "name: C\non:\n  push:\n    branches: [main]\njobs:\n  x:\n    runs-on: ubuntu-latest\n    steps: []\n",
}

ONE_LISTENER = {
    "a.yml": "name: A\non:\n  issue_comment:\n    types: [created]\njobs:\n  x:\n    runs-on: ubuntu-latest\n    steps: []\n",
    "c.yml": "name: C\non:\n  push:\n    branches: [main]\njobs:\n  x:\n    runs-on: ubuntu-latest\n    steps: []\n",
}

ZERO_LISTENERS = {
    "c.yml": "name: C\non:\n  push:\n    branches: [main]\n  pull_request: {}\njobs:\n  x:\n    runs-on: ubuntu-latest\n    steps: []\n",
    "d.yml": "name: D\non: workflow_dispatch\njobs:\n  x:\n    runs-on: ubuntu-latest\n    steps: []\n",
    # disabled files and archive/historical paths must be skipped even if they
    # declare issue_comment.
    "e.yml.disabled": "name: E\non:\n  issue_comment: {}\njobs:\n  x:\n    runs-on: ubuntu-latest\n    steps: []\n",
}

# a nested/non-top-level mention of "issue_comment" must NOT be counted as a
# direct top-level trigger.
NESTED_MENTION_ONLY = {
    "f.yml": (
        "name: F\n"
        "on:\n"
        "  push:\n"
        "    branches: [main]\n"
        "jobs:\n"
        "  x:\n"
        "    runs-on: ubuntu-latest\n"
        "    steps:\n"
        "      - run: echo 'not a real issue_comment trigger'\n"
    ),
}


class CheckIssueCommentListenersTest(unittest.TestCase):
    def setUp(self):
        self.mod = load_module()

    def _write_workflows(self, files):
        d = tempfile.TemporaryDirectory()
        self.addCleanup(d.cleanup)
        base = Path(d.name) / ".github" / "workflows"
        base.mkdir(parents=True)
        for name, content in files.items():
            (base / name).write_text(content)
        return Path(d.name)

    def test_two_listeners_fail_and_count(self):
        root = self._write_workflows(TWO_LISTENERS)
        findings = self.mod.find_issue_comment_listeners(root)
        self.assertEqual(len(findings), 2)
        names = sorted(f.name for f in findings)
        self.assertEqual(names, ["a.yml", "b.yml"])

    def test_one_listener_passes(self):
        root = self._write_workflows(ONE_LISTENER)
        findings = self.mod.find_issue_comment_listeners(root)
        self.assertEqual(len(findings), 1)

    def test_zero_listeners_passes_and_skips_disabled(self):
        root = self._write_workflows(ZERO_LISTENERS)
        findings = self.mod.find_issue_comment_listeners(root)
        self.assertEqual(len(findings), 0)

    def test_nested_mention_is_not_a_trigger(self):
        root = self._write_workflows(NESTED_MENTION_ONLY)
        findings = self.mod.find_issue_comment_listeners(root)
        self.assertEqual(len(findings), 0)

    def test_main_exit_code_fail_on_multiple(self):
        root = self._write_workflows(TWO_LISTENERS)
        rc = self.mod.main(["--workflows-root", str(root)])
        self.assertEqual(rc, 1)

    def test_main_exit_code_ok_on_single(self):
        root = self._write_workflows(ONE_LISTENER)
        rc = self.mod.main(["--workflows-root", str(root)])
        self.assertEqual(rc, 0)

    def test_main_exit_code_ok_on_zero(self):
        root = self._write_workflows(ZERO_LISTENERS)
        rc = self.mod.main(["--workflows-root", str(root)])
        self.assertEqual(rc, 0)


if __name__ == "__main__":
    unittest.main()
