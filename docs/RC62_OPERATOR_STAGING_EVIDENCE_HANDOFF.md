# Candidate native B2C — 12/10/2026

Bổ sung nối PR #62 `b859d4f7d29c1dfe6fee77375c6287d896e21a18`, giữ nguyên B2C và dùng WordPress như giao diện tùy chọn theo xác nhận owner. Candidate/CI/artifact phải lấy từ HEAD PR bổ sung, không dùng ZIP PR #52/#61/#62 cho code mới. Gate và cấu hình hiện hành: [RC62_B2C_NATIVE_STOREFRONT_VI.md](RC62_B2C_NATIVE_STOREFRONT_VI.md). Các mốc bên dưới là lịch sử; không thay branch pin/RuntimeGuard, không cấp quyền deploy hoặc migration dữ liệu thật.

---

# RC6.2 — Staging Operator Handoff & Evidence Gate

**11 October 2026. Status: BLOCKED_ENV / NO-GO.** This is a guide for an *authorized operator*, not authorization to connect to CyberPanel, restore data, run migration, install WordPress or deploy production.

## Candidate identity — never cross-use artifacts

The original PR #52 candidate has SHA `157261336e6b999a33bded252ad92144caee05d2`. PR #53 tested Golden Path continuity; PR #54 hardened the readiness CLI. The combined Draft verification PR incorporates both. **Choose exactly one artifact built from its own HEAD SHA** once exact-SHA checks PASS. Never substitute the PR #52 ZIP/manifest or patch an older runtime ZIP with newer PHP source.

Preserve source branch, commit SHA, GitHub Actions run ID, ZIP hash, manifest hash, number of packaged PHP/migrations, archive verification date, the reviewer and the operator. The manifest inside the approved artifact must identify exactly the independently approved SHA. An artifact without a matching manifest and hash is **REJECTED**.

## 1. Host and database isolation (operator-controlled)

- Record the **authorized staging** hostname, TLS validity, PHP 8.3/extensions, actual webroot/docroot, runtime owner and private config location **without posting secrets or customer data**.
- Confirm staging document root is different from any production website. The runtime has a hardcoded host allowlist in `RuntimeGuard.php`; an unapproved hostname fails closed. Never silently bypass it.
- Name the clone with an unambiguous isolated marker (e.g. `vta_rc62_clone`). Keep a separate account with **SELECT-only** access for the readiness CLI. Cross-check the operator-approved DB name, private config `db.database` and `SELECT DATABASE()`; refuse any mismatch.
- The current CyberPanel staging controller pins `codex/Vietnam/rc6.2-testing`, *not* this Draft verification branch. Do not change/passthrough/bypass the pin without a separately reviewed operator deployment plan.

## 2. Recoverability and migration evidence (requires separate authorization)

1. From an explicitly authorized database source, produce a dated **encrypted backup** with checksum; record the engine version, migration ledger/count and table/row-count inventory. Never upload the backup, SQL dump, customer PII or credential files to GitHub/Linear.
2. Restore into a **physically/logically isolated disposable MariaDB clone** using an approved account. Prove the clone is independent from production, and compare key table row counts/metadata and a restore checksum/inventory before further action.
3. Record the original `schema_migrations` and `migration_checksums` ledger; inspect for unknown, failed, missing or modified entries. Stop on any drift. No automatic checksum baselining.
4. Rehearse reviewed forward-only migrations **only on the clone**, using the repository's guarded ordering (core 025/036 before deferred Marketing and B2B 037/038), with separately authorized write credentials. Do not run production migrations; MySQL DDL is not assumed reversible.
5. After the operator-reviewed clone migration, switch back to a **read-only account**, verify migration checksums and run the private CLI preflight.
6. Prove **restore/rollback** from the verified backup, and record the responsible operator's signoff before any genuine staging cutover.

Only after previous steps are proven may the operator run the read-only preflight for a newly selected, unpacked and checksum-verified candidate:

```bash
# Placeholder values, not credentials or release approval.
# Both values must be independently approved; do not copy blindly from manifest/config.
APPROVED_SHA="<exact-40-character-reviewed-candidate-head>"
APPROVED_CLONE="<operator-verified-isolated-clone-db>"
VTA_CONFIG_FILE="/private/staging/config.php" \
php runtime/api/bin/rc62-release-readiness.php \
  --manifest="/private/rc62-manifest.json" \
  --expected-sha="$APPROVED_SHA" \
  --expected-db="$APPROVED_CLONE"
```

Expected successful output includes `status=READ_ONLY_PREFLIGHT_PASS`, `source_commit=$APPROVED_SHA`, and `release_authorized=false`. Exit code 2 with `RELEASE_BLOCKED` means STOP; do **not** retry by bypassing host checks, renaming production DB or disabling ledger checks. If migrations are pending, the CLI blocks rather than applying them.

**Caution:** The new CLI arguments are present only in the combined verification branch/its own artifact, not the historical PR #52 RC1 ZIP. Do not mix them.

## 3. Real-auth workflow and safety acceptance

See `docs/RC62_E2E_WORKFLOW_ACCEPTANCE.md` for E2E-01 through E2E-08. Run only after the isolated environment and backup are accepted.

- **Website intake:** use a separately staged `vta-connect` WordPress plugin, HMAC negative/positive tests, duplicate submission, Sales conversion and source attribution. The WordPress plugin is **not** bundled inside the RC6.2 runtime ZIP.
- **Tour and document:** Inquiry → Tour Profile → use independent Tour Library copy → Word-canvas edit/undo/save/reopen → rich DOCX/PDF output; master and sent versions remain immutable.
- **Cost and pricing:** supplier-reviewed VND evidence, three tiers, **6 paying + 1 FOC**, independently select Hotel 3★ + Cruise 5★, no fabricated FX, availability or supplier NET. Only server-calculated price may reach public proposal.
- **Commercial/operations:** approve → immutable SENT quote → Quick Send expiry/revoke → confirm → Booking (no duplicate) → handover → Operations intake/acceptance → documented supplier confirmation and Finance AR/AP settlement. A Finance **DRAFT** alone is not settled or finalized.
- **Security/browser:** Admin/Sales/Operations/Finance/Read-only plus two genuine B2B partner sessions A/B, COMMON/EXCLUSIVE, revoked, cross-tenant API ID attacks, public documents without NET/profit; PC/tablet/real mobile/PWA and save/network recovery.

For every test record: operator, date/time, candidate SHA, environment/role, input source IDs, output IDs, expected/actual result, redacted HTTP/screenshots/SQL evidence URL, and status `PASS_STAGING_REAL_AUTH`, `FAIL`, `BLOCKED_ENV` or `NOT_TESTED`. Existing GitHub fixture CI can only earn `PASS_CI_FIXTURE`.

## 4. Minimal operator evidence register — not yet populated

| Gate | Evidence required | Current status |
|---|---|---|
| Candidate exact-SHA artifact | ZIP/manifest/SHA256SUMS reconciled to independently approved HEAD | NOT_TESTED by operator |
| Staging isolation | hostname/docroot/private config/PHP version, database identity | BLOCKED_ENV |
| Backup and restore | encrypted verified backup restored into authorized clone | BLOCKED_ENV |
| Migration integrity | before/after ledger and checksum, core/Marketing/B2B ordering, rollback | BLOCKED_ENV |
| Read-only CLI | complete preflight log, expected SHA/DB and exit status | BLOCKED_ENV |
| Golden Path E2E-01 to E2E-08 | operator UAT matrix, real role/browser evidence | BLOCKED_ENV |
| Operator approval | dated signoff and rollback/cutover route | NOT_TESTED |

## 5. Release decision

**NO-GO.** Do not merge PR #52/#53/#54/the combined PR, upload to CyberPanel, change DNS, send provider messages, migrate production, or conclude a staging PASS from CI. VIE-5, VIE-6, VIE-14, VIE-12 and final VIE-11 gate remain subject to explicit verification and signoff.
