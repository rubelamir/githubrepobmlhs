"""Upload specific project files to the live site over the FTPS account in .vscode/sftp.json."""

from __future__ import annotations

import ftplib
import json
import ssl
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CONFIG_FILE = ROOT / ".vscode" / "sftp.json"
SKIP_PREFIXES = (
    ".git/",
    ".github/",
    ".cursor/",
    ".vscode/",
    "android/",
    "node_modules/",
    "scripts/",
    "src/",
    "tests/",
    "docs/",
)
SKIP_SUFFIXES = (".sql", ".md", ".pdf", ".csv", ".log")


def load_config() -> dict:
    if not CONFIG_FILE.is_file():
        raise RuntimeError("FTPS configuration is missing: .vscode/sftp.json")
    config = json.loads(CONFIG_FILE.read_text(encoding="utf-8"))
    if config.get("protocol") != "ftp" or config.get("secure") is not True:
        raise RuntimeError("Deployment stopped: .vscode/sftp.json must configure secure FTP.")
    if not all(config.get(key) for key in ("host", "username", "password")):
        raise RuntimeError("Deployment stopped: FTPS host configuration is incomplete.")
    return config


def relative_upload_path(raw_path: str) -> Path | None:
    candidate = Path(raw_path.strip().strip('"'))
    if not candidate.parts:
        return None
    absolute = candidate if candidate.is_absolute() else ROOT / candidate
    try:
        relative = absolute.resolve().relative_to(ROOT.resolve())
    except (OSError, ValueError):
        return None
    if not (ROOT / relative).is_file():
        return None
    posix = relative.as_posix()
    if posix == ".vscode/sftp.json" or posix.startswith(SKIP_PREFIXES) or posix.endswith(SKIP_SUFFIXES):
        return None
    return relative


def cwd_remote_root(ftp: ftplib.FTP_TLS, root_parts: list[str]) -> None:
    ftp.cwd("/")
    for part in root_parts:
        ftp.cwd(part)


def upload_file(ftp: ftplib.FTP_TLS, relative: Path, root_parts: list[str]) -> None:
    local_path = ROOT / relative
    cwd_remote_root(ftp, root_parts)
    parts = relative.parts
    for directory in parts[:-1]:
        try:
            ftp.cwd(directory)
        except ftplib.error_perm:
            ftp.mkd(directory)
            ftp.cwd(directory)
    with local_path.open("rb") as source:
        ftp.storbinary(f"STOR {relative.name}", source)
    remote_size = ftp.size(relative.name)
    if remote_size != local_path.stat().st_size:
        raise OSError(f"Uploaded size mismatch for {relative.as_posix()}.")


def deploy(paths: list[str]) -> list[str]:
    selected: list[Path] = []
    seen: set[str] = set()
    for raw_path in paths:
        relative = relative_upload_path(raw_path)
        if relative is None:
            continue
        key = relative.as_posix()
        if key in seen:
            continue
        seen.add(key)
        selected.append(relative)
    if not selected:
        return []

    config = load_config()
    remote_root = str(config.get("remotePath", "/")).strip("/")
    root_parts = [part for part in remote_root.split("/") if part]
    with ftplib.FTP_TLS(
        str(config["host"]),
        timeout=45,
        context=ssl.create_default_context(),
    ) as ftp:
        ftp.login(str(config["username"]), str(config["password"]))
        ftp.prot_p()
        for relative in selected:
            upload_file(ftp, relative, root_parts)
    return [relative.as_posix() for relative in selected]


def main() -> int:
    raw_paths = sys.argv[1:]
    if not raw_paths and not sys.stdin.isatty():
        raw_paths = [line for line in sys.stdin.read().splitlines() if line.strip()]
    try:
        uploaded = deploy(raw_paths)
    except Exception as error:
        print(f"FTPS deployment failed: {type(error).__name__}: {error}", file=sys.stderr)
        return 1
    if not uploaded:
        print("No website files to publish.")
        return 0
    print("Published to the live site:")
    for path in uploaded:
        print(path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
