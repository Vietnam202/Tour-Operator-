#!/usr/bin/env python3
"""Unit contracts only. Synthetic *structurally valid* evidence is NOT human UAT."""
import importlib.util
import json
import hashlib
import tempfile
import zipfile
import io
import subprocess
import sys
from pathlib import Path

root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("rc62_evidence_gate",root/"deploy/check-rc62-evidence.py")
gate=importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
PIN="a"*40
def sha(data): return hashlib.sha256(data).hexdigest()
def yes(condition,label):
    assert condition,label
    print("PASS_CI "+label)
def rejected(fn,code,label):
    try: fn()
    except gate.Stop as e:
        yes(str(e)==code,label)
        return
    raise AssertionError(label+" should block")
def ref(n):return "private://redacted-fixture/"+n
def item(n):
    return {"status":"PASS_STAGING_REAL_AUTH","candidate_sha":PIN,
        "evidence_ref":ref(n),"reviewer":"Synthetic Fixture Reviewer",
        "origin":"OPERATOR_HTTPS_STAGING","observed_at":"2026-10-12T16:00:00+07:00"}
def fake_packet(artifact):
    documents=[{"source_sha256":sha(("doc-fixture-"+str(i)).encode()),
        "format":"DOCX" if i<25 else "PDF","source_kind":"REAL_OPERATOR_SUPPLIED",
        "saved_reopened":True,"images_tables_reviewed":True,"docx_export_checked":True,
        "pdf_export_checked":True,"evidence_ref":ref("doc-"+str(i)),
        "source_ref":ref("original-"+str(i))}
        for i in range(50)]
    return {"schema":"vta.rc62.operator-evidence.v1","source_kind":"OPERATOR_COLLECTED_NOT_CI",
        "release_authorized":False,"candidate_sha":PIN,"artifact_zip_sha256":artifact["zip_sha256"],
        "manifest_sha256":artifact["manifest_sha256"],
        "staging":{"url":"https://stage-fixture.example.invalid","docroot_isolated":True,
            "tls_verified":True,"private_config_outside_webroot":True,
            "php_83_extensions_checked":True,"clone_db":"vta_rc62_clone_fixture",
            "evidence_ref":ref("isolation")},
        "recovery":{"encrypted_backup_sha256":sha(b"synthetic-backup-not-a-real-backup"),
            "source_and_clone_isolated":True,"restore_verified":True,
            "rollback_rehearsed":True,"migration_ledgers_equal":True,
            "read_only_preflight":"READ_ONLY_PREFLIGHT_PASS",
            "preflight_release_authorized":False,"clone_db":"vta_rc62_clone_fixture",
            "evidence_ref":ref("restore")},
        "roles":{name:item("role-"+str(i)) for i,name in enumerate(gate.REQUIRED_ROLES)},
        "scenarios":{name:item("scenario-"+str(i)) for i,name in enumerate(gate.REQUIRED_SCENARIOS)},
        "cost":{"paying_pax":6,"foc_pax":1,"hotel_stars":3,"cruise_stars":5,
            "supplier_availability_reviewed":True,"net_price_source_approved":True,
            "evidence_ref":ref("cost")},
        "real_documents":documents,
        "operator_review":{"result":"READY_FOR_INDEPENDENT_RELEASE_REVIEW",
            "candidate_sha":PIN,"change_window_reviewed":True,
            "rollback_responsible_person_named":True,"evidence_ref":ref("signoff")}
    }

def synth_doc(index):
    if index>=25:
        return ("%PDF-1.4\\n% Synthetic fixture document "+str(index)+"\\n").encode()
    stream=io.BytesIO()
    with zipfile.ZipFile(stream,"w",zipfile.ZIP_DEFLATED) as z:
        z.writestr("[Content_Types].xml","<Types/>")
        z.writestr("word/document.xml","<document>Only synthetic CI fixture %d</document>"%index)
    return stream.getvalue()
def make_local_index(root,packet):
    (root/"refs").mkdir(parents=True,exist_ok=True)
    (root/"originals").mkdir(parents=True,exist_ok=True)
    sources={d["source_ref"]:(i,d) for i,d in enumerate(packet["real_documents"])}
    index={}
    for n,reference in enumerate(sorted(gate.all_evidence_refs(packet))):
        if reference in sources:
            i,entry=sources[reference]
            ext="docx" if entry["format"]=="DOCX" else "pdf"
            rel="originals/file-%03d.%s"%(i,ext)
            data=synth_doc(i)
            entry["source_sha256"]=sha(data)
        else:
            rel="refs/evidence-%03d.txt"%n
            data=("Synthetic CI-only evidence ref "+str(n)+"\\n").encode()
        target=root/rel
        target.write_bytes(data)
        index[reference]={"path":rel,"sha256":sha(data)}
    packet["evidence_index"]=index

with tempfile.TemporaryDirectory(prefix="vta-rc62-evidence-fixture-") as temp:
    directory=Path(temp)
    files={"api/migrations/fixture_%03d.sql"%i:b"SELECT 1;\n"+str(i).encode() for i in range(35)}
    files.update({"assets/fixture-%03d.js"%i:("console.log(%d);"%i).encode() for i in range(75)})
    names={p:sha(data) for p,data in files.items()}
    manifest={"schema":"vta.rc62.runtime.manifest.v1","release_type":gate.RELEASE_TYPE,
        "source_commit":PIN,"migration_execution":"NOT_PERFORMED",
        "sha256":names,"file_count":len(names),"migration_count":35}
    m=directory/("vta-rc62-manifest-"+PIN[:12]+".json")
    z=directory/("vta-rc62-integrated-staging-"+PIN[:12]+".zip")
    sums=directory/"SHA256SUMS.txt"
    m.write_text(json.dumps(manifest))
    with zipfile.ZipFile(z,"w",zipfile.ZIP_DEFLATED) as arc:
        for name,data in files.items():arc.writestr("runtime/"+name,data)
        arc.writestr("REVIEW_STAGING_ONLY.txt","Synthetic fixture is NOT a release.")
    sums.write_text(sha(z.read_bytes())+"  "+z.name+"\n"+sha(m.read_bytes())+"  "+m.name+"\n")
    artifacts=gate.verify_artifact(m,z,sums,PIN)
    yes(artifacts["runtime_files"]==110 and artifacts["migration_files"]==35,
        "independent artifact hashes match pinned SHA and all ZIP runtime members")
    packet=fake_packet(artifacts)
    private_root=directory/"private-evidence"
    private_root.mkdir()
    make_local_index(private_root,packet)
    gate.verify_evidence(packet,artifacts)
    bound=gate.verify_private_evidence(packet,private_root)
    yes(bound["attachment_count"]==119 and bound["real_original_count"]==50,
        "119 operator references bind to private proof bytes and 50 synthetic DOCX/PDF original hashes")
    yes(True,"synthetic structurally complete packet still has release_authorized=false")
    packet_file=directory/"synthetic-operator-evidence.json"
    packet_file.write_text(json.dumps(packet))
    cli=subprocess.run([sys.executable,str(root/"deploy/check-rc62-evidence.py"),
        "--manifest",str(m),"--zip",str(z),"--sha256sums",str(sums),
        "--expected-sha",PIN,"--evidence",str(packet_file),
        "--evidence-root",str(private_root)],capture_output=True,text=True)
    yes(cli.returncode==0 and json.loads(cli.stdout)["release_authorized"] is False,
        "offline operator CLI verifies local evidence bytes but never authorizes release")
    def changed(f):
        p=json.loads(json.dumps(packet))
        f(p)
        return p
    rejected(lambda:gate.verify_artifact(m,z,sums,"b"*40),"ARTIFACT_IDENTITY_MISMATCH",
        "different expected Git SHA cannot borrow CI artifact")
    old=z.read_bytes()
    z.write_bytes(old+b"x")
    rejected(lambda:gate.verify_artifact(m,z,sums,PIN),"SHA256SUMS_MISMATCH",
        "corrupted ZIP checksum rejected")
    z.write_bytes(old)
    old_manifest=m.read_bytes()
    m.write_bytes(old_manifest+b" ")
    rejected(lambda:gate.verify_artifact(m,z,sums,PIN),"SHA256SUMS_MISMATCH",
        "changed manifest fails independently stored SHA256SUMS")
    m.write_bytes(old_manifest)
    rejected(lambda:gate.verify_evidence(changed(lambda p:p.update(candidate_sha="b"*40)),artifacts),
        "REVIEW_PACKET_CANDIDATE_MISMATCH","mixed-candidate evidence refused")
    rejected(lambda:gate.verify_evidence(changed(lambda p:p.update(source_kind="PASS_CI_FIXTURE")),artifacts),
        "CI_CANNOT_AUTHORIZE_STAGING","CI artifacts cannot be relabeled as real UAT")
    rejected(lambda:gate.verify_evidence(changed(lambda p:p.update(release_authorized=True)),artifacts),
        "CI_CANNOT_AUTHORIZE_STAGING","packet cannot self-authorize deployment")
    rejected(lambda:gate.verify_evidence(changed(lambda p:p["staging"].update(url="https://quote.vietnamtraveladvisor.com.vn")),artifacts),
        "STAGING_ISOLATION_NOT_ATTESTED","production hostname explicitly refused")
    rejected(lambda:gate.verify_evidence(changed(lambda p:p["staging"].update(docroot_isolated=False)),artifacts),
        "STAGING_ISOLATION_NOT_ATTESTED","staging sharing production docroot refused")
    rejected(lambda:gate.verify_evidence(changed(lambda p:p["recovery"].update(rollback_rehearsed=False)),artifacts),
        "RECOVERY_OPERATOR_EVIDENCE_MISSING","no independently attested rollback blocks")
    rejected(lambda:gate.verify_evidence(changed(lambda p:p["recovery"].update(preflight_release_authorized=True)),artifacts),
        "RECOVERY_OPERATOR_EVIDENCE_MISSING","read-only preflight cannot claim release authorized")
    rejected(lambda:gate.verify_evidence(changed(lambda p:p["roles"]["Agency B"].update(
        evidence_ref=p["roles"]["Agency A"]["evidence_ref"])),artifacts),
        "AGENCY_A_B_EVIDENCE_NOT_INDEPENDENT","two agency sessions need independent evidence")
    rejected(lambda:gate.verify_evidence(changed(lambda p:p["scenarios"]["E2E-04"].update(status="PASS_CI_FIXTURE")),artifacts),
        "GOLDEN_PATH_REAL_UAT_INCOMPLETE","synthetic golden-path evidence cannot count as real")
    rejected(lambda:gate.verify_evidence(changed(lambda p:p["cost"].update(foc_pax=0)),artifacts),
        "SUPPLIER_FOC_MIXED_CHOICE_NOT_ACCEPTED","6 paying + 1 FOC required")
    rejected(lambda:gate.verify_evidence(changed(lambda p:p["real_documents"].pop()),artifacts),
        "REAL_50_DOCUMENTS_NOT_ACCEPTED","49 documents cannot count as 50")
    rejected(lambda:gate.verify_evidence(changed(lambda p:p["real_documents"][1].update(
        source_sha256=p["real_documents"][0]["source_sha256"])),artifacts),
        "DUPLICATE_SOURCE_EVIDENCE","duplicate document hashes refused")
    rejected(lambda:gate.verify_evidence(changed(lambda p:p["real_documents"][0].update(
        images_tables_reviewed=False)),artifacts),"RICH_DOCUMENT_REVIEW_INCOMPLETE",
        "DOCX image/table fidelity must be attested")
    rejected(lambda:gate.verify_evidence(changed(lambda p:p["operator_review"].update(
        result="GO_PRODUCTION")),artifacts),"OPERATOR_REVIEW_PACKET_INCOMPLETE",
        "structural gate never accepts a GO_PRODUCTION field")
    def bound_negative(fail,reason,label):
        rejected(lambda:(fail(),gate.verify_private_evidence(packet,private_root)),reason,label)
    refkey=packet["roles"]["Admin"]["evidence_ref"]
    orig_path=private_root/packet["evidence_index"][refkey]["path"]
    orig=orig_path.read_bytes()
    orig_path.write_bytes(b"tampered-but-still-synthetic")
    rejected(lambda:gate.verify_private_evidence(packet,private_root),
        "PRIVATE_EVIDENCE_CHECKSUM_MISMATCH","modified private operator log bytes rejected")
    orig_path.write_bytes(orig)
    backup=packet["evidence_index"][refkey]
    packet["evidence_index"][refkey]={"path":"../outside.txt","sha256":backup["sha256"]}
    rejected(lambda:gate.verify_private_evidence(packet,private_root),
        "PRIVATE_EVIDENCE_PATH_UNSAFE","path traversal cannot escape evidence root")
    packet["evidence_index"][refkey]=backup
    packet["evidence_index"].pop(refkey)
    rejected(lambda:gate.verify_private_evidence(packet,private_root),
        "PRIVATE_EVIDENCE_INDEX_INCOMPLETE","absent evidence source reference blocks release")
    packet["evidence_index"][refkey]=backup
    packet["evidence_index"]["private://extra-unused-proof"]={"path":"unused.txt","sha256":sha(b"anything")}
    rejected(lambda:gate.verify_private_evidence(packet,private_root),
        "PRIVATE_EVIDENCE_INDEX_INCOMPLETE","extraneous/unreviewed private evidence reference blocked")
    del packet["evidence_index"]["private://extra-unused-proof"]
    source=packet["real_documents"][0]
    src_path=private_root/packet["evidence_index"][source["source_ref"]]["path"]
    src_bytes=src_path.read_bytes()
    fake_pdf=b"%PDF-1.4\\nWrong source content format\\n"
    src_path.write_bytes(fake_pdf)
    item=packet["evidence_index"][source["source_ref"]]
    old_digest=item["sha256"];old_source_digest=source["source_sha256"]
    item["sha256"]=sha(fake_pdf);source["source_sha256"]=sha(fake_pdf)
    rejected(lambda:gate.verify_private_evidence(packet,private_root),
        "DOCUMENT_SOURCE_FORMAT_MISMATCH","PDF bytes cannot masquerade as a DOCX original")
    src_path.write_bytes(src_bytes);item["sha256"]=old_digest;source["source_sha256"]=old_source_digest
    link=private_root/packet["evidence_index"][refkey]["path"]
    link.unlink()
    link.symlink_to(packet_file)
    rejected(lambda:gate.verify_private_evidence(packet,private_root),
        "PRIVATE_EVIDENCE_PATH_UNSAFE","symlink to private document rejected")
    link.unlink();link.write_bytes(orig)
    yes(True,"VIE-11 private-byte evidence checker negative tests complete; never contacts DB or hosting")

