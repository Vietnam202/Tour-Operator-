#!/usr/bin/env python3
"""Generate a SHA-pinned, verified RC6.2 PHP/MariaDB runtime artifact. Does not deploy."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import zipfile

ROOT = Path(__file__).resolve().parents[1]
BLOCKED_SEGMENTS = {
    ".git", "vta_private", "private", "uploads", "storage", "runtime",
    "logs", "cache", "backup", "backups", "generated", "node_modules",
}
ROOT_EXTS = {".js", ".css", ".html", ".json", ".php"}
REQUIRED_PDF_ASSETS = {
    "api/vendor/tfpdf/font/unifont/DejaVuSans.ttf",
    "api/vendor/tfpdf/font/unifont/DejaVu_LICENSE.txt",
    "api/vendor/tfpdf/LICENSE",
}
ASSET_EXTS = {".css", ".js", ".svg", ".png", ".jpg", ".jpeg", ".webp", ".ico", ".woff", ".woff2", ".ttf"}
REQUIRED = {
    ".htaccess", "index.html", "preview.html", "partner.html",
    "api/index.php", "api/bootstrap.php", "api/bin/healthcheck.php",
    "api/lib/RuntimeGuard.php", "api/lib/Migrations.php",
    "api/lib/SmartEnhancements.php", "api/lib/PartnerHub.php",
    "api/bin/rc62-release-readiness.php",
    "api/vendor/tfpdf/font/unifont/DejaVuSans.ttf",
    "api/lib/B2CStudio.php", "api/lib/B2CPublicationBridge.php",
    "api/migrations/044_b2c_publications.sql", "b2c-studio.js", "b2c-demo.js",
    "journeys.php", "b2c-storefront.js", "b2c-storefront.css", "api/lib/B2CStorefront.php",
    "vta-plus-assist.js", "vta-plus-assist.css",
    "api/lib/TourLibrary.php", "app.js", "unified-workspace.js",
    "tour-library.js", "tour-program-workspace.js", "smart-costing.js",
    "cost-sheet.js", "document-editor.js", "vta-cost-editor.js",
    "smart-enhancements.js", "smart-enhancements.css", "service-worker.js",
}
MIN_FILES = 75


def git(*args: str) -> str:
    return subprocess.check_output(["git", "-C", str(ROOT), *args], text=True).strip()


def managed(name: str) -> bool:
    p = Path(name)
    if p.is_absolute() or ".." in p.parts or not p.parts:
        return False
    if any(v in BLOCKED_SEGMENTS or v == ".env" or v.startswith(".env.") for v in p.parts):
        return False
    if name in REQUIRED_PDF_ASSETS:
        return True
    if name in {"api/config.php", "api/config.example.php"}:
        return False
    if len(p.parts) == 1:
        return p.suffix.lower() in ROOT_EXTS or name in {".htaccess", ".user.ini"}
    if p.parts[0] == "api":
        return p.suffix.lower() in {".php", ".sql"}
    if p.parts[0] == "assets":
        return p.suffix.lower() in ASSET_EXTS
    return False


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def listing() -> list[str]:
    data = subprocess.check_output(["git", "-C", str(ROOT), "ls-files", "-z"])
    return sorted({p.decode("utf-8") for p in data.split(b"\0") if p})


def safe_file(path: Path) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise RuntimeError("Unsafe/non-regular tracked file: " + str(path))
    return path.read_bytes()


def make(output: Path) -> tuple[Path, Path]:
    head = git("rev-parse", "HEAD")
    if not re.fullmatch(r"[0-9a-f]{40}", head):
        raise RuntimeError("Unexpected git commit reference")
    if subprocess.call(["git", "-C", str(ROOT), "diff", "--quiet", "HEAD"]) != 0:
        raise RuntimeError("Tracked working tree has uncommitted changes")
    names = [p for p in listing() if managed(p)]
    if len(names) < MIN_FILES or REQUIRED - set(names):
        raise RuntimeError("Incomplete runtime source: " + str(sorted(REQUIRED - set(names))))
    # Packaging must match the source SHA byte for byte, even on a reused runner.
    digest = {}
    data = {}
    for p in names:
        blob = safe_file(ROOT / p)
        expected = git("rev-parse", "HEAD:" + p)
        if hashlib.sha1(b"blob " + str(len(blob)).encode() + b"\0" + blob).hexdigest() != expected:
            raise RuntimeError("Source changed or smudged: " + p)
        digest[p] = sha256(blob)
        data[p] = blob
    migration_names = [p for p in names if p.startswith("api/migrations/") and p.endswith(".sql")]
    if not any("038_b2b_hybrid_distribution" in p for p in migration_names):
        raise RuntimeError("B2B hybrid migration is missing")
    if not any("036_tour_library_proposal_studio" in p for p in migration_names):
        raise RuntimeError("Approved Word itinerary migration is missing")
    manifest = {
        "schema": "vta.rc62.runtime.manifest.v1",
        "repository": "Vietnam202/Tour-Operator-",
        "source_commit": head,
        "release_type": "STAGING_CANDIDATE_NOT_PRODUCTION",
        "payload_prefix": "runtime/",
        "file_count": len(names),
        "sha256": digest,
        "migration_count": len(migration_names),
        "migration_execution": "NOT_PERFORMED",
        "external_private_config": "REQUIRED",
        "backend": "PHP 8.3 + MariaDB; does not depend on WordPress",
    }
    output.mkdir(parents=True, exist_ok=True)
    manifest_path = output / ("vta-rc62-manifest-" + head[:12] + ".json")
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    package_path = output / ("vta-rc62-integrated-staging-" + head[:12] + ".zip")
    notice = (
        "VTA RC6.2 INTEGRATED STAGING CANDIDATE — NOT A PRODUCTION DEPLOY\n"
        "This is real PHP/MariaDB source, not an HTML mockup. RuntimeGuard and private\n"
        "configuration requirements remain mandatory; this archive DOES NOT migrate\n"
        "database, install on CyberPanel, or include secrets/WordPress plugins.\n"
        "Never extract over a live public_html. Read docs/RC62_INTEGRATED_STAGING_RELEASE_VI.md\n"
        "in GitHub and verify the SHA-256 manifest before using on isolated staging.\n"
        "Git SHA: " + head + "\n"
    )
    with zipfile.ZipFile(package_path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for p in names:
            info = zipfile.ZipInfo("runtime/" + p, (2026, 10, 11, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, data[p], compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        info = zipfile.ZipInfo("REVIEW_STAGING_ONLY.txt", (2026, 10, 11, 0, 0, 0))
        info.external_attr = 0o100644 << 16
        archive.writestr(info, notice)
    return package_path, manifest_path


def verify(package: Path, manifest_file: Path) -> None:
    manifest = json.loads(manifest_file.read_text(encoding="utf-8"))
    assert manifest["release_type"] == "STAGING_CANDIDATE_NOT_PRODUCTION"
    entries = manifest["sha256"]
    if not isinstance(entries, dict) or len(entries) != manifest["file_count"] or len(entries) < MIN_FILES:
        raise RuntimeError("Manifest file count mismatch")
    if set(REQUIRED) - set(entries):
        raise RuntimeError("Release missing essential files")
    with zipfile.ZipFile(package) as archive:
        if archive.testzip() is not None:
            raise RuntimeError("Corrupt archive")
        names = archive.namelist()
        target = {"runtime/" + p for p in entries}
        if set(names) != target | {"REVIEW_STAGING_ONLY.txt"}:
            raise RuntimeError("Payload mismatch: unexpected/missing files")
        for p, expected in entries.items():
            if not managed(p) or sha256(archive.read("runtime/" + p)) != expected:
                raise RuntimeError("Unsafe path or digest mismatch: " + p)
        html = archive.read("runtime/index.html").decode()
        preview = archive.read("runtime/preview.html").decode()
        for content in (html, preview):
            for path in re.findall(r'(?:src|href)="([^"#?]+\.(?:js|css))\?[^"]+"', content):
                if path.startswith(("http", "/")):
                    continue
                if path.lstrip("./") not in entries:
                    raise RuntimeError("HTML reference missing from payload: " + path)
        sw = archive.read("runtime/service-worker.js").decode()
        if "smart-enhancements.js" not in sw or "vta-RC62-V5-B2C-NATIVE-1-PWA" not in sw:
            raise RuntimeError("PWA version/assets missing")
        forbidden = {"integrations/", "tests/", "verification/", "docs/", "deploy/", ".git/", "api/config.php"}
        for name in names:
            if any(name.startswith("runtime/" + x) for x in forbidden):
                raise RuntimeError("Unsafe file in runtime ZIP: " + name)
    print("PASS verified SHA-pinned runtime candidate: " + manifest["source_commit"], flush=True)
    print("PASS files=" + str(len(entries)) + " migrations=" + str(manifest["migration_count"]), flush=True)
    print("PASS no secrets, WordPress plugin, docs/tests or private storage included", flush=True)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", default="dist", help="Build output folder")
    parser.add_argument("--verify", nargs=2, metavar=("ZIP", "MANIFEST"), help="Verify produced files")
    args = parser.parse_args()
    if args.verify:
        verify(Path(args.verify[0]), Path(args.verify[1]))
    else:
        package, manifest = make(ROOT / args.output)
        verify(package, manifest)
        print("ZIP=" + str(package))
        print("MANIFEST=" + str(manifest))


if __name__ == "__main__":
    try:
        main()
    except (OSError, RuntimeError, ValueError, KeyError, AssertionError, zipfile.BadZipFile) as exc:
        sys.exit("REFUSED: " + str(exc))
