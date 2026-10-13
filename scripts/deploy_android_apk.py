"""Publish the freshly built Android debug APK to the configured FTPS host."""

from __future__ import annotations

import ftplib
import hashlib
import json
import shutil
import ssl
import sys
import zipfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SOURCE_APK = ROOT / "android" / "app" / "build" / "outputs" / "apk" / "debug" / "app-debug.apk"
DOWNLOAD_APK = ROOT / "dist" / "downloads" / "bmlhs-android.apk"
CONFIG_FILE = ROOT / ".vscode" / "sftp.json"
REMOTE_NAME = "bmlhs-android.apk"


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as apk:
        for chunk in iter(lambda: apk.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def remote_sha256(ftp: ftplib.FTP_TLS, filename: str) -> str:
    digest = hashlib.sha256()
    ftp.retrbinary(f"RETR {filename}", lambda chunk: digest.update(chunk))
    return digest.hexdigest()


def main() -> int:
    if not SOURCE_APK.is_file():
        print(f"Built APK not found: {SOURCE_APK}")
        return 1
    if not zipfile.is_zipfile(SOURCE_APK):
        print("Built APK is not a valid ZIP/APK archive.")
        return 1
    with zipfile.ZipFile(SOURCE_APK) as archive:
        invalid_entry = archive.testzip()
    if invalid_entry is not None:
        print(f"Built APK archive contains a corrupt entry: {invalid_entry}")
        return 1
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

    DOWNLOAD_APK.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(SOURCE_APK, DOWNLOAD_APK)
    local_hash = sha256_file(DOWNLOAD_APK)
    remote_root = str(config.get("remotePath", "/")).strip("/") or "/"
    remote_directories = [part for part in remote_root.strip("/").split("/") if part]
    remote_directories.extend(("dist", "downloads"))

    try:
        context = ssl.create_default_context()
        with ftplib.FTP_TLS(
            str(config["host"]),
            timeout=30,
            context=context,
        ) as ftp:
            ftp.login(str(config["username"]), str(config["password"]))
            ftp.prot_p()
            for directory in remote_directories:
                ftp.cwd(directory)

            staging_name = f"{REMOTE_NAME}.uploading"
            with DOWNLOAD_APK.open("rb") as apk:
                ftp.storbinary(f"STOR {staging_name}", apk)

            if ftp.size(staging_name) != DOWNLOAD_APK.stat().st_size:
                raise OSError("Uploaded staging file size does not match the local APK.")
            if remote_sha256(ftp, staging_name) != local_hash:
                raise OSError("Uploaded staging file SHA-256 does not match the local APK.")

            backup_name = f"{REMOTE_NAME}.previous"
            previous_exists = False
            try:
                ftp.size(REMOTE_NAME)
                previous_exists = True
            except ftplib.error_perm as error:
                if not str(error).startswith("550"):
                    raise

            if previous_exists:
                ftp.rename(REMOTE_NAME, backup_name)
            try:
                ftp.rename(staging_name, REMOTE_NAME)
            except Exception:
                if previous_exists:
                    ftp.rename(backup_name, REMOTE_NAME)
                raise
            if previous_exists:
                ftp.delete(backup_name)

            if ftp.size(REMOTE_NAME) != DOWNLOAD_APK.stat().st_size:
                raise OSError("Published APK size does not match the local APK.")
            if remote_sha256(ftp, REMOTE_NAME) != local_hash:
                raise OSError("Published APK SHA-256 does not match the local APK.")
    except Exception as error:
        print(f"FTPS deployment failed: {type(error).__name__}: {error}")
        return 1

    print("APK copied to the local website download folder.")
    print(f"FTPS upload verified; size: {DOWNLOAD_APK.stat().st_size} bytes.")
    print(f"SHA-256: {local_hash}")
    print("Download URL: https://rubelamir.site/dist/downloads/bmlhs-android.apk")
    return 0


if __name__ == "__main__":
    sys.exit(main())
