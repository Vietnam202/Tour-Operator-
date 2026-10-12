#!/usr/bin/env python3
"""Offline RC6.2 staging review-packet *structure* gate. NEVER deploys or authorizes.

Usage: python3 deploy/check-rc62-evidence.py --manifest ... --zip ... --sha256sums ...
       --expected-sha <independently-approved-40-hex> --evidence <private/redacted.json>
       --evidence-root <private/operator-only/evidence-files>

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
import os
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
            and private_ref(entry.get("evidence_ref")) and private_ref(entry.get("source_ref"))
            and entry["source_ref"]!=entry["evidence_ref"],
            "RICH_DOCUMENT_REVIEW_INCOMPLETE")
        digest=entry["source_sha256"]
        require(digest not in digests,"DUPLICATE_SOURCE_EVIDENCE")
        digests.add(digest)
        extensions.add(entry["format"])
    require(extensions=={"DOCX","PDF"},"REAL_DOCX_PDF_COVERAGE_MISSING")
    require(len({d["source_ref"] for d in docs})==50,"DUPLICATE_PRIVATE_SOURCE_REFERENCE")
    signed=packet.get("operator_review")
    require(isinstance(signed,dict) and signed.get("result")=="READY_FOR_INDEPENDENT_RELEASE_REVIEW"
        and signed.get("candidate_sha")==pin and signed.get("change_window_reviewed") is True
        and signed.get("rollback_responsible_person_named") is True
        and private_ref(signed.get("evidence_ref")),"OPERATOR_REVIEW_PACKET_INCOMPLETE")

def all_evidence_refs(packet:dict)->set[str]:
    """Fail closed on missing/duplicate role and scenario evidence references."""
    refs=[
        packet["staging"]["evidence_ref"],packet["recovery"]["evidence_ref"],
        packet["cost"]["evidence_ref"],packet["operator_review"]["evidence_ref"]
    ]
    refs.extend(r["evidence_ref"] for r in packet["roles"].values())
    refs.extend(r["evidence_ref"] for r in packet["scenarios"].values())
    for d in packet["real_documents"]:
        refs.extend((d["evidence_ref"],d["source_ref"]))
    require(len(refs)==len(set(refs)),"PRIVATE_EVIDENCE_REFERENCES_REUSED")
    return set(refs)

def sha256_file(path:Path)->tuple[str,int]:
    digest=hashlib.sha256()
    size=0
    with path.open("rb") as handle:
        while True:
            block=handle.read(1024*1024)
            if not block: break
            size+=len(block)
            require(size<=50*1024*1024,"PRIVATE_EVIDENCE_FILE_TOO_LARGE")
            digest.update(block)
    require(size>=8,"PRIVATE_EVIDENCE_FILE_EMPTY")
    return digest.hexdigest(),size

def verify_private_evidence(packet:dict,root:Path)->dict:
    """Verify operator-held file bytes, not just free-form private:// placeholders.

    This proves evidence files *exist* and bind to their claimed SHA, NOT that
    their real-world content, role attribution or signatures are authentic.
    No file content is printed, copied, exported, nor placed in the release ZIP.
    """
    require(not root.is_symlink() and root.is_dir(),"PRIVATE_EVIDENCE_ROOT_MISSING")
    resolved=root.resolve(strict=True)
    source_dir=Path(__file__).resolve().parents[1]
    require(not (resolved==source_dir or source_dir in resolved.parents),
        "PRIVATE_EVIDENCE_ROOT_INSIDE_REPOSITORY")
    index=packet.get("evidence_index")
    refs=all_evidence_refs(packet)
    require(isinstance(index,dict) and set(index)==refs,
        "PRIVATE_EVIDENCE_INDEX_INCOMPLETE")
    paths=set()
    verified={}
    for ref in sorted(refs):
        item=index[ref]
        require(isinstance(item,dict) and set(item)=={"path","sha256"},
            "PRIVATE_EVIDENCE_INDEX_INVALID")
        rel=item["path"];recorded=item["sha256"]
        require(isinstance(rel,str) and SAFE_REL.fullmatch(rel) is not None
            and not rel.startswith(".") and ".." not in rel.split("/")
            and isinstance(recorded,str) and SHA64.fullmatch(recorded) is not None,
            "PRIVATE_EVIDENCE_PATH_UNSAFE")
        dest=resolved
        for part in rel.split("/"):
            dest=dest/part
            require(not dest.is_symlink(),"PRIVATE_EVIDENCE_PATH_UNSAFE")
        require(dest.is_file() and dest.resolve().is_relative_to(resolved),
            "PRIVATE_EVIDENCE_FILE_MISSING")
        require(str(dest) not in paths,"PRIVATE_EVIDENCE_FILE_REUSED")
        paths.add(str(dest))
        current,_=sha256_file(dest)
        require(current==recorded,"PRIVATE_EVIDENCE_CHECKSUM_MISMATCH")
        verified[ref]=dest
    for d in packet["real_documents"]:
        ref=d["source_ref"]
        require(index[ref]["sha256"]==d["source_sha256"],"DOCUMENT_SOURCE_HASH_MISMATCH")
        target=verified[ref]
        with target.open("rb") as handle: magic=handle.read(8)
        if d["format"]=="PDF":
            require(magic.startswith(b"%PDF-"),"DOCUMENT_SOURCE_FORMAT_MISMATCH")
        else:
            require(magic.startswith(b"PK\x03\x04"),"DOCUMENT_SOURCE_FORMAT_MISMATCH")
            try:
                with zipfile.ZipFile(target) as arc:
                    require("word/document.xml" in arc.namelist()
                        and "[Content_Types].xml" in arc.namelist(),
                        "DOCUMENT_SOURCE_FORMAT_MISMATCH")
                    require(arc.testzip() is None,"DOCUMENT_SOURCE_FORMAT_MISMATCH")
            except (OSError,zipfile.BadZipFile,RuntimeError):
                raise Stop("DOCUMENT_SOURCE_FORMAT_MISMATCH") from None
    return {"attachment_count":len(verified),"real_original_count":50}

def main(argv:list[str])->int:
    parser=argparse.ArgumentParser(description=__doc__)
    for name in ("manifest","zip","sha256sums","expected-sha","evidence","evidence-root"):
        parser.add_argument("--"+name,required=True)
    args=parser.parse_args(argv)
    try:
        artifact=verify_artifact(Path(args.manifest),Path(args.zip),Path(args.sha256sums),args.expected_sha)
        packet=load(Path(args.evidence))
        verify_evidence(packet,artifact)
        bound=verify_private_evidence(packet,Path(args.evidence_root))
        print(json.dumps({"ok":True,"status":"EVIDENCE_PACKET_STRUCTURALLY_COMPLETE",
            "source_commit":artifact["sha"],"runtime_files":artifact["runtime_files"],
            "migration_files":artifact["migration_files"],
            "private_evidence_files_verified":bound["attachment_count"],
            "real_original_file_hashes_verified":bound["real_original_count"],
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
