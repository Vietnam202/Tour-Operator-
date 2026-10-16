#!/usr/bin/env python3
"""Native-Windows MCP launcher with DPAPI credentials and untouched stdin/stdout.

Hermes spawns this Python process directly. No PowerShell subprocess sits
between Hermes and the JSON-RPC stdio stream.
"""
from __future__ import annotations

import ctypes
from ctypes import wintypes
import json
import os
from pathlib import Path
import sys
from urllib.parse import urlsplit

BLOCKED = {"v2quote.vietnamtraveladvisor.com.vn",
           "quote.vietnamtraveladvisor.com.vn",
           "vietnamtraveladvisor.com.vn"}


class Blob(ctypes.Structure):
    _fields_ = [("cbData", wintypes.DWORD),
                ("pbData", ctypes.POINTER(ctypes.c_ubyte))]


def unprotect(secret_blob: bytes) -> str:
    if os.name != "nt":
        raise RuntimeError("Windows DPAPI requires native Windows.")
    if len(secret_blob) < 24 or len(secret_blob) > 65536:
        raise RuntimeError("Invalid DPAPI credential size.")
    backing = ctypes.create_string_buffer(secret_blob)
    src = Blob(len(secret_blob), ctypes.cast(backing, ctypes.POINTER(ctypes.c_ubyte)))
    dst = Blob()
    crypt = ctypes.WinDLL("crypt32", use_last_error=True)
    crypt.CryptUnprotectData.argtypes = [
        ctypes.POINTER(Blob), ctypes.POINTER(wintypes.LPWSTR),
        ctypes.POINTER(Blob), ctypes.c_void_p, ctypes.c_void_p,
        wintypes.DWORD, ctypes.POINTER(Blob)
    ]
    crypt.CryptUnprotectData.restype = wintypes.BOOL
    if not crypt.CryptUnprotectData(ctypes.byref(src), None, None, None, None,
                                     0, ctypes.byref(dst)):
        raise OSError(ctypes.get_last_error(), "DPAPI decryption failed")
    try:
        raw = ctypes.string_at(dst.pbData, dst.cbData)
        return raw.decode("utf-16le")
    finally:
        ctypes.WinDLL("kernel32", use_last_error=True).LocalFree(
            ctypes.cast(dst.pbData, ctypes.c_void_p))


def main() -> None:
    if os.name != "nt":
        raise RuntimeError("This launcher must run under the Windows user that installed it.")
    home = os.environ.get("LOCALAPPDATA", "")
    if not home:
        raise RuntimeError("LOCALAPPDATA is missing")
    root = Path(home) / "VTA" / "HermesRC62"
    cfg = json.loads((root / "bridge-settings.json").read_text(encoding="utf-8-sig"))
    uri = urlsplit(cfg["api_url"])
    if (cfg.get("target_environment") != "staging"
            or str(cfg.get("enable_task_writes")) != "0"
            or uri.scheme != "https" or uri.hostname is None
            or uri.hostname.lower() in BLOCKED
            or uri.path != "/api/index.php" or uri.username or uri.password
            or uri.query or uri.fragment):
        raise RuntimeError("Unsafe RC6.2 target / writes refused.")
    try:
        company = int(cfg["company_id"])
    except (TypeError, ValueError) as exc:
        raise RuntimeError("Invalid expected tenant.") from exc
    if company < 1:
        raise RuntimeError("Invalid expected tenant.")
    hexblob = (root / "agent-password.dpapi").read_text(encoding="utf-8-sig").strip()
    password = unprotect(bytes.fromhex(hexblob))
    if not password:
        raise RuntimeError("Empty agent credential.")
    os.environ["VTA_API_URL"] = cfg["api_url"]
    os.environ["VTA_TARGET_ENV"] = "staging"
    os.environ["VTA_AGENT_EMAIL"] = cfg["agent_email"]
    os.environ["VTA_EXPECTED_COMPANY_ID"] = str(company)
    os.environ["VTA_ENABLE_TASK_WRITES"] = "0"
    os.environ["VTA_AGENT_PASSWORD"] = password

    # Same-process exec: Hermes stdin/stdout remains connected to the MCP handler.
    from vta_mcp_server import main as mcp_main
    mcp_main()


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("VTA Hermes launcher refused configuration: " + str(exc), file=sys.stderr)
        sys.exit(2)
