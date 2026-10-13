"""Publish the profile responsive and favicon fixes over the configured FTPS host."""

from __future__ import annotations

import ftplib
import hashlib
import json
import ssl
import sys
from datetime import datetime, timezone
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CONFIG_FILE = ROOT / ".vscode" / "sftp.json"
FILES = (
    Path("dist/includes/layout-head.php"),
    Path("dist/pages/profile.php"),
)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def remote_sha256(ftp: ftplib.FTP_TLS, filename: str) -> str:
    digest = hashlib.sha256()
    ftp.retrbinary(f"RETR {filename}", lambda chunk: digest.update(chunk))
    return digest.hexdigest()


def main() -> int:
    if not CONFIG_FILE.is_file():
        print("FTPS configuration is missing: .vscode/sftp.json")
        return 1

    config = json.loads(CONFIG_FILE.read_text(encoding="utf-8"))
    if config.get("protocol") != "ftp" or config.get("secure") is not True:
        print("Deployment stopped: .vscode/sftp.json must configure secure FTP.")
        return 1
    if not all(config.get(key) for key in ("host", "username", "password")):
        print("Deployment stopped: FTPS host configuration is incomplete.")
        return 1
    if any(not (ROOT / relative_path).is_file() for relative_path in FILES):
        print("Deployment stopped: one or more local web files are missing.")
        return 1

    remote_root = str(config.get("remotePath", "/")).strip("/")
    root_parts = [part for part in remote_root.split("/") if part]
    staging_suffix = f".uploading-{datetime.now(timezone.utc):%Y%m%d%H%M%S}"
    backup_suffix = f".previous-{datetime.now(timezone.utc):%Y%m%d%H%M%S}"
    staged: list[tuple[Path, str, str]] = []
    backups: list[tuple[str, str]] = []

    try:
        with ftplib.FTP_TLS(
            str(config["host"]),
            timeout=30,
            context=ssl.create_default_context(),
        ) as ftp:
            ftp.login(str(config["username"]), str(config["password"]))
            ftp.prot_p()
            for part in root_parts:
                ftp.cwd(part)

            for relative_path in FILES:
                local_path = ROOT / relative_path
                remote_path = relative_path.as_posix()
                staging_path = f"{remote_path}{staging_suffix}"
                ftp.cwd("/")
                for part in root_parts:
                    ftp.cwd(part)
                with local_path.open("rb") as source:
                    ftp.storbinary(f"STOR {staging_path}", source)
                if ftp.size(staging_path) != local_path.stat().st_size:
                    raise OSError(f"Staged size mismatch for {remote_path}.")
                if remote_sha256(ftp, staging_path) != sha256_file(local_path):
                    raise OSError(f"Staged SHA-256 mismatch for {remote_path}.")
                staged.append((relative_path, staging_path, remote_path))

            for _, staging_path, remote_path in staged:
                backup_path = f"{remote_path}{backup_suffix}"
                try:
                    ftp.size(remote_path)
                    ftp.rename(remote_path, backup_path)
                    backups.append((remote_path, backup_path))
                except ftplib.error_perm as error:
                    if not str(error).startswith("550"):
                        raise

                try:
                    ftp.rename(staging_path, remote_path)
                except Exception:
                    if backups and backups[-1][0] == remote_path:
                        ftp.rename(backups[-1][1], remote_path)
                        backups.pop()
                    raise

            for relative_path, _, remote_path in staged:
                local_path = ROOT / relative_path
                if ftp.size(remote_path) != local_path.stat().st_size:
                    raise OSError(f"Published size mismatch for {remote_path}.")
                if remote_sha256(ftp, remote_path) != sha256_file(local_path):
                    raise OSError(f"Published SHA-256 mismatch for {remote_path}.")
    except Exception as error:
        print(f"FTPS deployment failed: {type(error).__name__}: {error}")
        return 1

    print("Published profile responsive and favicon fixes over FTPS.")
    for relative_path in FILES:
        print(f"Verified: {relative_path.as_posix()}")
    print(f"Remote backups retained with suffix: {backup_suffix}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
