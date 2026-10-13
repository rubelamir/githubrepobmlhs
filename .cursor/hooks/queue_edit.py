"""Remember website files edited by Cursor so they can be published at the end of the turn."""

from __future__ import annotations

import json
import sys
from pathlib import Path


QUEUE = Path(__file__).resolve().parents[2] / ".cursor" / "live-deploy-queue.txt"


def paths_from_payload(payload: dict) -> list[str]:
    found: list[str] = []
    for key in ("file_path", "filePath", "path", "uri"):
        value = payload.get(key)
        if isinstance(value, str) and value.strip():
            found.append(value)
    tool_input = payload.get("tool_input")
    if isinstance(tool_input, dict):
        for key in ("path", "file_path", "filePath"):
            value = tool_input.get(key)
            if isinstance(value, str) and value.strip():
                found.append(value)
    return found


def main() -> int:
    raw = sys.stdin.read()
    if not raw.strip():
        return 0
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError:
        return 0
    if not isinstance(payload, dict):
        return 0
    paths = paths_from_payload(payload)
    if not paths:
        return 0
    QUEUE.parent.mkdir(parents=True, exist_ok=True)
    with QUEUE.open("a", encoding="utf-8") as handle:
        for path in paths:
            handle.write(path.replace("\\", "/") + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
