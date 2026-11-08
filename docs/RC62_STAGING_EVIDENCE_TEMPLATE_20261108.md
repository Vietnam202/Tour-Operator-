# RC6.2 — Staging evidence register (operator template, 08/11/2026)

> **BLANK TEMPLATE · NOT PASS EVIDENCE.** Fill only after an authorized independent examination. GitHub stores **redacted links and audit metadata**, never private DB dumps, customers' PII, credentials, tokens, keys, config files or production documents. All values below are placeholders. Empty/unknown = `NOT_VERIFIED` / `BLOCKED`.

## 1. Candidate identity — independently cross-checked

| Field | Actual verified value | Source / reviewer |
| --- | --- | --- |
| Repository / Draft PR | `Vietnam202/Tour-Operator-` / [link when ready] | GitHub |
| Head branch and exact 40-hex **candidate SHA** | **NOT_VERIFIED** | GitHub compare and commit |
| Base branch, parent SHA and dependency graph | **NOT_VERIFIED** | PR checks/review |
| CI workflow names, run URLs and matching head SHA | **NOT_VERIFIED** | Actions |
| Runtime ZIP name, byte size, SHA-256 | **NOT_VERIFIED** | Independent artifact |
| Manifest SHA-256 and file-count parity | **NOT_VERIFIED** | Independent artifact |
| Source inventory SHA-256 | **NOT_VERIFIED** | ZIP + Git and staging docroot |
| Migration file names/checksums, including `090` | **NOT_VERIFIED** | migration inventory/ledger |
| Package approved by and at UTC time | **NOT_VERIFIED** | Signed private decision |

**Rule:** A later commit changes the candidate SHA; CI/UAT from old SHA must not be carried over.

## 2. Hosting identity & isolation (read-only inspection)

| Check | Observed value / redacted private evidence reference | Verdict |
| --- | --- | --- |
| Approved staging hostname `rc62-staging.vietnamtraveladvisor.com.vn` resolved and TLS/SNI/public CA valid | NOT_VERIFIED | BLOCKED_ENV |
| vHost and absolute docroot verified independently (distinct from live sites) | NOT_VERIFIED | BLOCKED_ENV |
| Operator/ownership, file ACL, PHP 8.3/OpenLiteSpeed and extensions | NOT_VERIFIED | BLOCKED_ENV |
| Private `VTA_CONFIG_FILE` outside docroot, distinct staging secrets | NOT_VERIFIED | BLOCKED_ENV |
| Independent staging host/DB/storage PINs verified (record *status only*) | NOT_VERIFIED | BLOCKED_ENV |
| Staging DB name/connection + `SELECT DATABASE()` match approved clone (NO DSN here) | NOT_VERIFIED | BLOCKED_ENV |
| Current runtime SHA + source inventory read from server and compared to package | NOT_VERIFIED | BLOCKED_ENV |
| Old controller branch `codex/Vietnam/rc6.2-testing` and legacy v2quote path reviewed: no bypass | Unchanged in source at 08/11/2026; target-host authorization unknown | BLOCKED_CONTROLLER |
| Distinct staging-only operator change approval recorded | NOT_VERIFIED | BLOCKED_APPROVAL |

## 3. VIE-6 — data safety, clone restore and rollback

| Evidence | Protected/private reference, reviewer and UTC | Verdict |
| --- | --- | --- |
| Authorized SOURCE database/volume scoped (no production by implication) | NOT_VERIFIED | BLOCKED_DATA |
| Dated encrypted backup and media/private-storage snapshot, checksum checked | NOT_VERIFIED | BLOCKED_DATA |
| Independently isolated MariaDB clone restored from existing-data backup | NOT_VERIFIED | BLOCKED_DATA |
| Before/after table inventory/row counts/digests reconciled | NOT_VERIFIED | BLOCKED_DATA |
| `schema_migrations` and `migration_checksums` reconciled; unknown/drift stopped | NOT_VERIFIED | BLOCKED_DATA |
| Core/B2B/Marketing ordering and proposed migration `090` individually reviewed | NOT_VERIFIED | BLOCKED_DATA |
| Forward-only rehearsal **on approved clone**, no unexpected data loss | NOT_VERIFIED | BLOCKED_DATA |
| Clone rollback and recovery actually restored and verified | NOT_VERIFIED | BLOCKED_DATA |
| SELECT-only readiness preflight exit/status with exact SHA & approved clone DB | NOT_VERIFIED | BLOCKED_DATA |
| VIE-6 authorized operator review/signoff (not CI) | NOT_VERIFIED | BLOCKED_APPROVAL |

## 4. Staging UAT / acceptance register

Each case must carry the complete execution identity, not just a checkbox.

| ID | Test / input→output trace (redacted IDs) | User role, device, UTC | Running SHA / fixture | Expected vs actual and private evidence pointer | Verdict |
| --- | --- | --- | --- | --- | --- |
| ST-01 | Runtime/TLS/docroot/manifest/API | NOT_TESTED | NOT_VERIFIED | NOT_TESTED | BLOCKED_ENV |
| ST-02 | Master Gallery, Word editor, V6 visual parity #99, original→Draft | NOT_TESTED | NOT_VERIFIED | NOT_TESTED | BLOCKED_UAT |
| ST-03 | Actual DOCX/PDF import/export; bytes, pages, images, SHA-256 | NOT_TESTED | NOT_VERIFIED | NOT_TESTED | BLOCKED_UAT |
| ST-04 | Supplier-reviewed VND Cost/Quote, 6 paying+1 FOC, Hotel3★/Cruise5★, SENT | NOT_TESTED | NOT_VERIFIED | NOT_TESTED | BLOCKED_UAT |
| ST-05 | B2B Partner A/B, COMMON/EXCLUSIVE, direct-ID and NET leak | NOT_TESTED | NOT_VERIFIED | NOT_TESTED | BLOCKED_UAT |
| ST-06 | AI JSON preview/diff→NEW Draft; 4 styles/5 tiers; RBAC/stale revision | NOT_TESTED | NOT_VERIFIED | NOT_TESTED | BLOCKED_UAT |
| ST-07 | Approved B2C/Ads consent/UTM→NEW LeadHub read-back, dedup, withdraw | NOT_TESTED | NOT_VERIFIED | NOT_TESTED | BLOCKED_UAT |
| ST-08 | Lead→Inquiry→Cost→Quote→Booking→Ops→Finance continuity | NOT_TESTED | NOT_VERIFIED | NOT_TESTED | BLOCKED_UAT |
| ST-09 | 1366/1440/390px, Android, PWA/TLS, reconnect and save recovery | NOT_TESTED | NOT_VERIFIED | NOT_TESTED | BLOCKED_UAT |
| ST-10 | Authorized rollback/recovery and operator+owner acceptance for same SHA | NOT_TESTED | NOT_VERIFIED | NOT_TESTED | BLOCKED_APPROVAL |

Use verdicts `PASS_CI_FIXTURE`, `PASS_STAGING_REAL_AUTH`, `FAIL`, `BLOCKED_ENV`, `BLOCKED_DATA`, `BLOCKED_UAT`, `NOT_TESTED`. `OWNER_ACCEPTED` is a separate signed decision, not a side effect of tests.

## 5. Human approval — never inferred

| Decision | Required identity and evidence | Status |
| --- | --- | --- |
| VIE-5 owner-approved integrated scope/deferrals | Exact SHA, modules, source graph, WordPress B2C included or explicitly deferred | **PENDING** |
| VIE-6 operator accepts backup/clone/migrations/recovery | Signed evidence reference and UTC | **PENDING** |
| VIE-11 operator authorizes controlled staging operation | Host/docroot/DB/storage/branch/controller/change-window exact pins | **PENDING** |
| Issue #99 owner accepts corrected V6 gallery/editor parity | Side-by-side screenshot, source tours, real browser and exact running SHA | **PENDING** |
| VIE-14 and VIE-16 owner accepts Golden Path/AI outcomes | ST-01–ST-10 evidence per role/record/export | **PENDING** |
| Production release authorization | **Distinct future owner decision**; never implied from staging | **NO-GO** |

**Default decision:** `KEEP_DRAFT / BLOCKED_GATE / NO_UPLOAD / NO_MERGE / NO_PRODUCTION_WRITE`.

For the actual execution order, see [RC62_FULL_STAGING_PREPARATION_20261108.md](RC62_FULL_STAGING_PREPARATION_20261108.md). Blocking issues: [#97](https://github.com/Vietnam202/Tour-Operator-/issues/97), [#99](https://github.com/Vietnam202/Tour-Operator-/issues/99), [Linear VIE-6](https://linear.app/vietnamtraveladvisor/issue/VIE-6), [Linear VIE-11](https://linear.app/vietnamtraveladvisor/issue/VIE-11).
