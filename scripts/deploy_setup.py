"""One-time setup: store the deploy FTP password in Windows Credential Manager."""
import ftplib
import getpass
import ssl
import sys

import keyring

HOST = "rubelamir.site"
USER = "deploy@rubelamir.site"
SERVICE = "bmlhs-deploy"

password = getpass.getpass(f"FTP password for {USER} (hidden): ")
try:
    ftp = ftplib.FTP_TLS(HOST, timeout=30, context=ssl.create_default_context())
    ftp.login(USER, password)
    ftp.prot_p()
    print("TLS login OK. Root listing:", ", ".join(n for n in ftp.nlst()[:8]))
    ftp.quit()
except Exception as exc:
    print("Login FAILED:", type(exc).__name__, exc)
    sys.exit(1)

keyring.set_password(SERVICE, USER, password)
print("Password saved securely in Windows Credential Manager.")
