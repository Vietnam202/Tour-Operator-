#!/usr/bin/env python3
"""Unit contracts only. Synthetic *structurally valid* evidence is NOT human UAT."""
import importlib.util
import json
import hashlib
import tempfile
import zipfile
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
        "pdf_export_checked":True,"evidence_ref":ref("doc-"+str(i))}
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
    gate.verify_evidence(packet,artifacts)
    yes(True,"synthetic structurally complete packet still has release_authorized=false")
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
    yes(True,"VIE-11 evidence checker negative tests complete; never contacts DB or hosting")
