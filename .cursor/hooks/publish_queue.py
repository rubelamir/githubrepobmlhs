"""Publish the files Cursor edited during this turn to the live FTPS site."""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
QUEUE = ROOT / ".cursor" / "live-deploy-queue.txt"
DEPLOY = ROOT / "scripts" / "deploy_paths.py"


def main() -> int:
    if not QUEUE.is_file():
        return 0
    paths = [line.strip() for line in QUEUE.read_text(encoding="utf-8").splitlines() if line.strip()]
    QUEUE.write_text("", encoding="utf-8")
    if not paths:
        return 0
    completed = subprocess.run(
        [sys.executable, str(DEPLOY), *paths],
        cwd=ROOT,
        capture_output=True,
        text=True,
        timeout=150,
    )
    log = ROOT / ".cursor" / "live-deploy.log"
    log.write_text((completed.stdout or "") + (completed.stderr or ""), encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
