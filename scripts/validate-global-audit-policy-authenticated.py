#!/usr/bin/env python3
"""Run strict global audit parity with authenticated reads and rate-limit-only fallback."""
from __future__ import annotations
import io
import os
import runpy
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TARGET = ROOT / "scripts" / "validate-global-audit-policy.py"
API_MANIFEST = "https://api.github.com/repos/Vivaliz-site/-shopvivaliz-pipeline/contents/docs/quality/GLOBAL_AUDIT_MANIFEST.json?ref=main"
RAW_MANIFEST = "https://raw.githubusercontent.com/Vivaliz-site/-shopvivaliz-pipeline/main/docs/quality/GLOBAL_AUDIT_MANIFEST.json"
MAX_MANIFEST_BYTES = 128 * 1024


class RateLimitFallback(urllib.request.BaseHandler):
    """Retry ONLY the exact canonical API URL on GitHub rate limiting.

    GitHub raw is a second transport to the same canonical main branch, never
    a local fallback: a missing/mismatched remote manifest still blocks CI.
    A separate opener avoids sending the API bearer token to another host.
    """

    def _try_raw(self, req, fp, code, msg, headers):
        if req.full_url != API_MANIFEST:
            return None
        if code == 403:
            response = fp.read(4096).lower()
            if b"rate limit" not in response and b"secondary rate" not in response:
                return None
        opener = urllib.request.build_opener()
        request = urllib.request.Request(
            RAW_MANIFEST,
            headers={"User-Agent": "shopvivaliz-absolute-audit-v5-raw-fallback",
                     "Cache-Control": "no-cache"},
        )
        with opener.open(request, timeout=20) as remote:
            content = remote.read(MAX_MANIFEST_BYTES + 1)
            if not content or len(content) > MAX_MANIFEST_BYTES:
                raise ValueError("canonical audit manifest missing or oversized")
        return io.BytesIO(content)

    http_error_403 = _try_raw
    http_error_429 = _try_raw


def install_authenticated_opener() -> None:
    token = os.environ.get("GITHUB_TOKEN", "").strip()
    opener = urllib.request.build_opener(RateLimitFallback())
    opener.addheaders = [
        ("Accept", "application/vnd.github.raw+json"),
        ("User-Agent", "shopvivaliz-absolute-audit-v5-auth-wrapper"),
    ]
    if token:
        opener.addheaders.append(("Authorization", f"Bearer {token}"))
    urllib.request.install_opener(opener)


if __name__ == "__main__":
    install_authenticated_opener()
    runpy.run_path(str(TARGET), run_name="__main__")
