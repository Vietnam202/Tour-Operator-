#!/usr/bin/env python3
"""Offline RC6.2 staging review-packet *structure* gate. NEVER deploys or authorizes.

Usage: python3 deploy/check-rc62-evidence.py --manifest ... --zip ... --sha256sums ...
       --expected-sha <independently-approved-40-hex> --evidence <private/redacted.json>

The operator controls all evidence assertions. Passing this structural gate
does NOT authenticate claims, complete real staging UAT, or authorize release.
Do not commit/upload the actual evidence packet, DB dump or customer documents.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import re
import sys
import zipfile
from pathlib import Path
from urllib.parse import urlparse

SHA40=re.compile(r"^[a-f0-9]{40}$")
SHA64=re.compile(r"^[a-f0-9]{64}$")
EVIDENCE_REF=re.compile(r"^private://[a-z0-9][a-z0-9._/-]{7,180}$")
SAFE_REL=re.compile(r"^(?!/)(?!.*//)(?!.*(?:^|/)\.\.?/)[a-zA-Z0-9_./+@-]+$")
REQUIRED_ROLES=("Admin","Sales","Operations","Finance","Read-only","Agency A","Agency B")
REQUIRED_SCENARIOS=tuple("E2E-%02d" % i for i in range(1,9))
RELEASE_TYPE="STAGING_CANDIDATE_NOT_PRODUCTION"

class Stop(Exception):
    pass

def require(ok: bool,reason: str)->None:
    if not ok: raise Stop(reason)

def sha(b: bytes)->str:
    return hashlib.sha256(b).hexdigest()

def load(path: Path)->dict:
    try:
        obj=json.loads(path.read_text(encoding="utf-8"))
    except (OSError,ValueError,UnicodeDecodeError):
        raise Stop("PRIVATE_EVIDENCE_OR_MANIFEST_UNREADABLE") from None
    require(isinstance(obj,dict),"INVALID_JSON_STRUCTURE")
    return obj

def private_ref(obj: object)->bool:
    return isinstance(obj,str) and EVIDENCE_REF.fullmatch(obj) is not None

def evidence_item(item:object,sha_pin:str)->bool:
    return (isinstance(item,dict) and item.get("status")=="PASS_STAGING_REAL_AUTH"
        and item.get("candidate_sha")==sha_pin and private_ref(item.get("evidence_ref"))
        and isinstance(item.get("reviewer"),str) and 3<=len(item["reviewer"])<=120
        and item.get("origin")=="OPERATOR_HTTPS_STAGING" and
        isinstance(item.get("observed_at"),str) and
        re.fullmatch(r"20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}",item["observed_at"]) is not None)

def verify_artifact(manifest_path:Path,archive_path:Path,sums_path:Path,pin:str)->dict:
    require(SHA40.fullmatch(pin) is not None,"EXACT_SHA_PIN_REQUIRED")
    m=load(manifest_path)
    require(m.get("schema")=="vta.rc62.runtime.manifest.v1"
        and m.get("source_commit")==pin and m.get("release_type")==RELEASE_TYPE
        and m.get("migration_execution")=="NOT_PERFORMED","ARTIFACT_IDENTITY_MISMATCH")
    files=m.get("sha256")
    require(isinstance(files,dict) and len(files)>=100
        and len(files)==m.get("file_count") and isinstance(m.get("migration_count"),int)
        and m["migration_count"]>=30,"ARTIFACT_INCOMPLETE")
    for name,digest in files.items():
        require(isinstance(name,str) and SAFE_REL.fullmatch(name) is not None and
            ".." not in name.split("/") and isinstance(digest,str)
            and SHA64.fullmatch(digest) is not None,"ARTIFACT_UNSAFE_ENTRY")
    require(sum(name.startswith("api/migrations/") and name.endswith(".sql") for name in files)
        ==m["migration_count"],"ARTIFACT_MIGRATIONS_MISMATCH")
    try:
        manifest_bytes=manifest_path.read_bytes()
        zip_digest=sha(archive_path.read_bytes())
        manifest_digest=sha(manifest_bytes)
        entries={}
        for line in sums_path.read_text(encoding="utf8").splitlines():
            parts=line.split(None,1)
            require(len(parts)==2 and SHA64.fullmatch(parts[0]) is not None,"SHA256SUMS_INVALID")
            name=parts[1].lstrip("*")
            require("/" not in name and name not in entries,"SHA256SUMS_INVALID")
            entries[name]=parts[0]
        require(set(entries)=={archive_path.name,manifest_path.name},"SHA256SUMS_ARTIFACT_PAIR_REQUIRED")
        require(entries[archive_path.name]==zip_digest and entries[manifest_path.name]==manifest_digest,
            "SHA256SUMS_MISMATCH")
        with zipfile.ZipFile(archive_path) as z:
            infos=z.infolist()
            names=[i.filename for i in infos]
            require(len(names)==len(set(names)),"ZIP_DUPLICATE_ENTRY")
            require(set(names)=={"runtime/"+k for k in files}|{"REVIEW_STAGING_ONLY.txt"},
                "ZIP_CONTENT_MISMATCH")
            for info in infos:
                require(not info.is_dir() and info.file_size<=50*1024*1024
                    and (info.external_attr>>16)&0o170000 not in (0o120000,),
                    "ZIP_UNSAFE_ENTRY")
            for name,digest in files.items():
                require(sha(z.read("runtime/"+name))==digest,"ZIP_RUNTIME_HASH_MISMATCH")
    except (OSError,zipfile.BadZipFile,RuntimeError,ValueError):
        raise Stop("ARTIFACT_FILE_UNREADABLE") from None
    return {"sha":pin,"zip_sha256":zip_digest,"manifest_sha256":manifest_digest,
        "runtime_files":len(files),"migration_files":m["migration_count"]}

def verify_evidence(packet:dict,artifact:dict)->None:
    pin=artifact["sha"]
    require(packet.get("schema")=="vta.rc62.operator-evidence.v1"
        and packet.get("candidate_sha")==pin and packet.get("artifact_zip_sha256")==artifact["zip_sha256"]
        and packet.get("manifest_sha256")==artifact["manifest_sha256"],"REVIEW_PACKET_CANDIDATE_MISMATCH")
    require(packet.get("source_kind")=="OPERATOR_COLLECTED_NOT_CI"
        and packet.get("release_authorized") is False,"CI_CANNOT_AUTHORIZE_STAGING")
    stage=packet.get("staging")
    require(isinstance(stage,dict),"STAGING_EVIDENCE_MISSING")
    url=stage.get("url")
    parsed=urlparse(url) if isinstance(url,str) else None
    require(parsed is not None and parsed.scheme=="https" and parsed.hostname and parsed.username is None
        and parsed.password is None and parsed.port in (None,443) and
        "stag" in parsed.hostname and
        parsed.hostname not in ("quote.vietnamtraveladvisor.com.vn","v2quote.vietnamtraveladvisor.com.vn")
        and parsed.path in ("","/") and not parsed.query and not parsed.fragment
        and stage.get("docroot_isolated") is True and stage.get("tls_verified") is True
        and stage.get("private_config_outside_webroot") is True
        and stage.get("php_83_extensions_checked") is True and
        private_ref(stage.get("evidence_ref")),"STAGING_ISOLATION_NOT_ATTESTED")
    db=stage.get("clone_db")
    require(isinstance(db,str) and re.fullmatch(r"[A-Za-z0-9_]{6,64}",db) is not None
        and re.search(r"(?:^|_)(?:clone|staging|stage|sandbox|test|rc62)(?:_|$)",db,re.I)
        and re.search(r"(?:^|_)(?:prod|production|live)(?:_|$)",db,re.I) is None,
        "STAGING_CLONE_NOT_PINNED")
    recovery=packet.get("recovery")
    require(isinstance(recovery,dict) and SHA64.fullmatch(str(recovery.get("encrypted_backup_sha256",""))) is not None
        and recovery.get("source_and_clone_isolated") is True
        and recovery.get("restore_verified") is True and recovery.get("rollback_rehearsed") is True
        and recovery.get("migration_ledgers_equal") is True
        and recovery.get("read_only_preflight")=="READ_ONLY_PREFLIGHT_PASS"
        and recovery.get("preflight_release_authorized") is False
        and recovery.get("clone_db")==db
        and private_ref(recovery.get("evidence_ref")),"RECOVERY_OPERATOR_EVIDENCE_MISSING")
    roles=packet.get("roles")
    require(isinstance(roles,dict) and set(roles)==set(REQUIRED_ROLES)
        and all(evidence_item(item,pin) for item in roles.values()),"AUTHENTIC_ROLE_SESSIONS_MISSING")
    require(roles["Agency A"]["evidence_ref"]!=roles["Agency B"]["evidence_ref"],
        "AGENCY_A_B_EVIDENCE_NOT_INDEPENDENT")
    scenarios=packet.get("scenarios")
    require(isinstance(scenarios,dict) and set(scenarios)==set(REQUIRED_SCENARIOS)
        and all(evidence_item(item,pin) for item in scenarios.values()),"GOLDEN_PATH_REAL_UAT_INCOMPLETE")
    cost=packet.get("cost")
    require(isinstance(cost,dict) and cost.get("paying_pax")==6 and cost.get("foc_pax")==1
        and cost.get("hotel_stars")==3 and cost.get("cruise_stars")==5
        and cost.get("supplier_availability_reviewed") is True
        and cost.get("net_price_source_approved") is True
        and private_ref(cost.get("evidence_ref")),"SUPPLIER_FOC_MIXED_CHOICE_NOT_ACCEPTED")
    docs=packet.get("real_documents")
    require(isinstance(docs,list) and len(docs)==50,"REAL_50_DOCUMENTS_NOT_ACCEPTED")
    digests=set()
    extensions=set()
    for entry in docs:
        require(isinstance(entry,dict) and SHA64.fullmatch(str(entry.get("source_sha256",""))) is not None
            and entry.get("format") in ("DOCX","PDF")
            and entry.get("source_kind")=="REAL_OPERATOR_SUPPLIED"
            and all(entry.get(k) is True for k in ("saved_reopened","images_tables_reviewed","docx_export_checked","pdf_export_checked"))
            and private_ref(entry.get("evidence_ref")),"RICH_DOCUMENT_REVIEW_INCOMPLETE")
        digest=entry["source_sha256"]
        require(digest not in digests,"DUPLICATE_SOURCE_EVIDENCE")
        digests.add(digest)
        extensions.add(entry["format"])
    require(extensions=={"DOCX","PDF"},"REAL_DOCX_PDF_COVERAGE_MISSING")
    signed=packet.get("operator_review")
    require(isinstance(signed,dict) and signed.get("result")=="READY_FOR_INDEPENDENT_RELEASE_REVIEW"
        and signed.get("candidate_sha")==pin and signed.get("change_window_reviewed") is True
        and signed.get("rollback_responsible_person_named") is True
        and private_ref(signed.get("evidence_ref")),"OPERATOR_REVIEW_PACKET_INCOMPLETE")

def main(argv:list[str])->int:
    parser=argparse.ArgumentParser(description=__doc__)
    for name in ("manifest","zip","sha256sums","expected-sha","evidence"):
        parser.add_argument("--"+name,required=True)
    args=parser.parse_args(argv)
    try:
        artifact=verify_artifact(Path(args.manifest),Path(args.zip),Path(args.sha256sums),args.expected_sha)
        packet=load(Path(args.evidence))
        verify_evidence(packet,artifact)
        print(json.dumps({"ok":True,"status":"EVIDENCE_PACKET_STRUCTURALLY_COMPLETE",
            "source_commit":artifact["sha"],"runtime_files":artifact["runtime_files"],
            "migration_files":artifact["migration_files"],
            "release_authorized":False,"requires":"independent_operator_authenticity_review_and_separate_release_approval"}))
        return 0
    except Stop as exc:
        print(json.dumps({"ok":False,"status":"STAGING_EVIDENCE_BLOCKED","reason":str(exc),
            "release_authorized":False}))
        return 2
    except Exception:
        # Never serialize a local filesystem path, config, SQL credential, or raw private JSON.
        print(json.dumps({"ok":False,"status":"STAGING_EVIDENCE_BLOCKED",
            "reason":"PRIVATE_EVIDENCE_CHECK_ERROR","release_authorized":False}))
        return 2

if __name__=="__main__":
    sys.exit(main(sys.argv[1:]))
