# VTA Tour Operator RC6.2 — Full staging preparation / handoff (08/11/2026)

> **DRAFT · PREPARED, NOT DEPLOYED · BLOCKED_GATE / NO UPLOAD · PRODUCTION NO-GO**
>
> This is an **operator execution plan and evidence contract**, NOT an approved deploy, code integration fix, actual restore, verified full-install artifact, staging UAT, or release authorization. No merge to `main`, branch-pin change, CyberPanel upload, DB migration, reset, public-site update, or production write is requested/authorized by this document.

## A. Exact source and decision boundaries

| Item | Read-only GitHub/Linear observation on 08/11/2026 | Evidence classification |
| --- | --- | --- |
| Repository | `Vietnam202/Tour-Operator-` | Verified repository |
| Staging preparation starting point | [Draft PR #107](https://github.com/Vietnam202/Tour-Operator-/pull/107), branch `codex/rc62-release-control-center-v1-20261020`, commit `18834ecd68c7bf24c011a358ce5d43282dccf148` | **Source only**; #107 is offline Release Control Center preview, not a release gate |
| Stacked feature dependency | [PR #106](https://github.com/Vietnam202/Tour-Operator-/pull/106) `5dbbe09d55395346108ff757843fc3aedeb2048f` → [#104](https://github.com/Vietnam202/Tour-Operator-/pull/104) → #103 → #102; integrated lineage also includes [#98](https://github.com/Vietnam202/Tour-Operator-/pull/98) | **Draft chain**, not independent merge approvals |
| Ancestry cross-check | GitHub compare #98 HEAD `2ee5a95e43b426d3743fc1a6bffa59655414e3d3` → #107 HEAD reported **ahead 92, behind 0** | Source ancestry evidence only; not conflict-free staging acceptance |
| Intended hosting | `https://rc62-staging.vietnamtraveladvisor.com.vn` explicitly approved as **target** in [Issue #97](https://github.com/Vietnam202/Tour-Operator-/issues/97) | **Target name**; no current DNS, TLS, docroot, DB or runtime read-back completed here |
| Historical staging report | Issue #97 previously reported `0bd07b707832946ef581ca1ab53c3c7fefcb6c85`; owner subsequently rejected V6 visual parity in [Issue #99](https://github.com/Vietnam202/Tour-Operator-/issues/99) | **Historical**: cannot be used as current runtime SHA or acceptance |
| VIE-5 / VIE-6 / VIE-11 | Linear live read: **In Progress / Todo / Todo** respectively, with VIE-6 & VIE-11 Urgent | **Blocking**; no operator signoff proved |
| VIE-16 | Linear live read: **In Progress**, blocked by VIE-6 and VIE-11 | **No staging real-auth acceptance** |
| VIE-7/8/9/10/14 | Linear live read: **Todo** | Required scoped UAT remains unaccepted |
| Exact source commit of THIS handoff | Read the **new Draft PR HEAD after these documents are committed**; it will differ from #107 parent | Mandatory re-pin; do **not** reuse parent checks/ZIP |

**Canonical release decision: `KEEP_DRAFT / BLOCKED_GATE / NO_UPLOAD / NO_PRODUCTION_WRITE`.** This documentation may be reviewed immediately; it does **not** declare the runtime ready.

## B. Source modules to reconcile — single RC6.2 candidate, not separate applications

The existing integrated source path must be reviewed *at the final, immutable candidate SHA*, including changed-file and source inventory checks. Mark each module `CODE_PRESENT`, `PASS_CI_FIXTURE`, `PASS_STAGING_REAL_AUTH`, `FAIL`, `BLOCKED`, or `NOT_VERIFIED`; never inherit a previous SHA's PASS.

| Scope / source reference | Minimum staging acceptance |
| --- | --- |
| Dashboard, company workspace, Settings, Admin, role permissions | Admin/Sales/Ops/Finance/read-only access; separate tenant/company and Partner A/B; denied direct-ID, CSRF and unauthenticated API |
| Marketing Studio, Leads, Inquiry, CRM, Sales & B2B | Real lead source/consent/attribution → Inquiry read-back, follow-up and archive/restore audit; preserve existing Marketing/AI Chat functionality |
| Master Tour Library + Word/Tiptap Itinerary | Original vs new Draft clone, paste/import DOCX/PDF, edit/add/reorder days, undo, save/reopen, actual media; don't rebuild editor as day cards or overwrite existing drafts |
| V6 visual remediation ([Issue #99](https://github.com/Vietnam202/Tour-Operator-/issues/99)) | Side-by-side **Gallery and Editor** with approved demo; real-source day/destination binding, no literal placeholders, duplicate section policy, readable A4 canvas and toolbar at 1366/1440/390; renewed owner visual signoff |
| Smart Cost / Price / Proposal / Quote | Supplier-reviewed **VND** inputs; simultaneous 3★/4★/5★, separate Private/SIC, Hotel 3★ with Cruise 5★, **7 total = 6 paying + 1 FOC**, Pricing Matrix and valid selling price; no fabricated rate/FX |
| AI Edit Itinerary / [PR #106](https://github.com/Vietnam202/Tour-Operator-/pull/106) | Human-reviewed ChatGPT/Hermes JSON fallback, source revision unchanged until approved NEW Draft, diff/audit/RBAC/CSRF/stale version/dedup; **4 distinct HTML previews**; **5 private tiers 4/6/8/10/12 pax** × 3 hotel classes; unknown selling price = **On request** |
| Document outputs | Actually download/reopen DOCX and inspect paginated PDF bytes, imagery, five-group tables and selling price visibility; HTML screen previews do **not** establish identical DOCX/PDF layout variants |
| B2B Partner Edition / [PR #103](https://github.com/Vietnam202/Tour-Operator-/pull/103) | Agency white-label previews, access scope, prices/contacts/branding, exports; no supplier NET/profit/PII leak; cross-agency attacks denied |
| B2C public tour / Ads publisher / LeadHub / [PR #102](https://github.com/Vietnam202/Tour-Operator-/pull/102) + [#104](https://github.com/Vietnam202/Tour-Operator-/pull/104) | Owner-reviewed publish, consent, UTM, source attribution, NEW LeadHub read-back, retry/dedup/withdraw; **no automatic Booking**; unapproved prices shown On request |
| Booking, Operations, Finance | Immutable SENT and Booking, exactly one authorized booking, Ops supplier status and Finance AR/AP handoff; never fabricate booking/receipt/payment/supplier confirmation |
| PWA, mobile, security and external integrations | Android + desktop real-browser, TLS/session/cache/network recovery, HMAC/CSRF/tenant boundaries; do not turn on Meta/WhatsApp/payment/provider credentials or WordPress live publishing by assumption |

**Scope decision required:** independently confirm whether WordPress B2C publish/withdraw is included in this staging acceptance or formally **deferred by owner**. Existing canonical app route for Ad landing is `/api/index.php?route=ads/landing/{slug}`; **a pretty URL on the main website is not implemented by #104**. `api/migrations/090_ads_landing_publish.sql` is SOURCE ONLY, *not evidence it ran anywhere*. External ChatGPT/Hermes live API is not present in #106; no promise of autonomous generation.

## C. Staging hosting preflight — read-only first, fail closed

Operator must independently record target environment, WITHOUT copying secrets/customer records into GitHub:

1. Confirm DNS, authoritative vHost, correct public CA HTTPS chain/SNI, trusted proxy boundary, HTTP→HTTPS, `Host`/origin policy, PHP 8.3/OpenLiteSpeed/PHP-FPM modules and actual **realpath(docroot)**. Validate current runtime `source_commit` plus `source_inventory_sha256` against independent source + package inventory; never infer deployed SHA from PR head, health HTTP 200, branch name or old screenshots.
2. Confirm target `rc62-staging.vietnamtraveladvisor.com.vn` is **not** `quote.vietnamtraveladvisor.com.vn`, `v2quote.vietnamtraveladvisor.com.vn`, the marketing WordPress website or another live site. No implicit hostname fallback; protect original production baseline.
3. Read only: verify separately pinned **`VTA_RC62_STAGING_HOST_PIN`**, **`VTA_RC62_STAGING_DB_PIN`**, **`VTA_RC62_STAGING_STORAGE_PIN`**; private `VTA_CONFIG_FILE` outside docroot; approved `app.env=staging`, HTTPS base/origin, `db.host=127.0.0.1`, cloned/staging DB, isolated local storage, least privilege, redacted identity evidence. Never place passwords, tokens, private keys or SQL dumps in repository/issue.
4. Inspect current deploy controller **before any rollout**. [`deploy/deploy-staging.sh`](../deploy/deploy-staging.sh) currently pins `BRANCH=codex/Vietnam/rc6.2-testing`, owner `vquot8508`, a **legacy v2quote HOME_DIR**, PHP binary and private paths. This is NOT a green light to deploy the new candidate to the approved rc62-staging host. A mismatched branch, owner, realpath, host, DB or migration ledger **must stop**. Do **not** retarget/bypass/patch the controller in this preparation PR.
5. Record scope and authority for operator-approved staging-only actions in a separate signed change ticket. Until then **NO UPLOAD**. Read-only HTTP smoke, if performed, is not authenticated UAT and should not change state.

Source: [isolated staging host review](RC62_ISOLATED_STAGING_HOST_REVIEW_VI.md), [operator evidence handoff](RC62_OPERATOR_STAGING_EVIDENCE_HANDOFF.md), [Issue #93](https://github.com/Vietnam202/Tour-Operator-/issues/93).

## D. Build candidate and VIE-6 data gate (before any approved staging write)

- **Lock one final code SHA.** Reconcile Draft PR dependency/merge-base conflicts, current source/package inventory and migrations (including 049/050 and **090**). No independent merging of stacked PRs into `main`. If the source HEAD changes, repeat CI/artifact verification.
- **Build in CI from that exact SHA:** full-install/integrated ZIP + manifest per file + migration inventory/digests + `SHA256SUMS` + source inventory; compare archive runtime bytes against Git source. Validate build identity. The earlier #90/#106 artifacts prove only their own SHA and must not be reused as this candidate.
- **CI scope:** PHP lint/runtime, JS/template contracts, role/tenant/CSRF tests, pricing/FOC, AI diff/conflict, B2B/B2C/LeadHub UTM/withdraw/dedup, real disposable MariaDB, document exports, responsive smoke and security checks. Log each workflow run URL and exact SHA. `PASS_CI_FIXTURE` is never real staging.
- **Authorized data-prep only, under VIE-6:** encrypted backup of the **authorized existing staging** DB/media/private state outside webroot, verify checksum, restore to isolated clone and independently reconcile table counts, row-count/digests, `schema_migrations`, `migration_checksums`, dependencies/order (core, B2B, Marketing, 090). Flag legacy duplicate-number migration names, drift and unknown migrations; no auto-baselining.
- Rehearse **reviewed forward-only migrations and restore/rollback only on approved clone** with distinct credentials. Do not treat MariaDB DDL as transaction-rollback-safe; use proven restore/snapshot to recover. Readiness CLI is **SELECT-only** and must fail with `MIGRATION_REVIEW_REQUIRED` / `RELEASE_BLOCKED` on mismatch. A successful clone smoke does not authorize changing the target staging DB.
- Obtain operator signoff on backup/restore inventory, ledger reconciliation, rollback and approved host/docroot/DB/storage **before** requesting VIE-11 staging deployment authorization.

## E. Deployment decision tree (future **operator** action, not executed here)

```text
START: Draft PR with exact integrated candidate HEAD
   |
   +-- source graph / owner-approved scope / exact HEAD CI or artifact missing?
   |      -> BLOCKED_SCOPE; NO UPLOAD
   +-- source ZIP / manifest / SHA256SUMS / source inventory mismatched?
   |      -> BLOCKED_PACKAGE; NO UPLOAD
   +-- isolated stage host/TLS/docroot/owner/config/DB/storage PIN absent or wrong?
   |      -> BLOCKED_HOST / BLOCKED_ENV; NO UPLOAD
   +-- current-data encrypted backup -> independent clone restore -> migration
   |   ledger/checksum/rollback evidence and operator approval missing?
   |      -> BLOCKED_DATA; NO UPLOAD
   +-- deployed controller refuses branch/host/DB/owner/SHA/DDL?
   |      -> BLOCKED_CONTROLLER; STOP, separate reviewed change request
   +-- explicit staging-only operation authorization missing?
   |      -> BLOCKED_APPROVAL; NO UPLOAD
   +-- ONLY THEN authorized operator may request a controlled staging change
   |      and independently read back exact runtime identity
   +-- authenticated Golden Path / visual / exports / privacy / rollback fail?
   |      -> FAIL_STAGING / ROLLBACK under operator's authorized plan
   +-- all scoped tests PASS and owner signs for exact SHA?
          -> STAGING ACCEPTED FOR DOCUMENTED SCOPE ONLY
             PRODUCTION STILL REQUIRES SEPARATE GO
```

**Never** run fixture test scripts or synthetic data creation against existing staging/production; no direct ZIP overwrite, unconditional migration, `--force`, branch/DB/host guard override, production DNS/TLS update, provider message, real booking or payment. A separately authorized staging change is not production authorization.

## F. Real authenticated UAT — evidence per test case, exact running SHA

| ID | Workflow | Minimum expected/negative path |
| --- | --- | --- |
| ST-01 | Runtime | SSL/cert, origin, health, source inventory/ZIP/manifest/read-back all match independent exact SHA; docroot/DB/storage isolated |
| ST-02 | Master Library + V6 Gallery/Editor | 7D6N/8D7N real source tour → clone working Draft → edit/reorder/day binding → undo/save/reopen; visual side-by-side demo at 1366/1440/390; no placeholder/duplicate insertion; unchanged source |
| ST-03 | DOCX/PDF | Import real DOCX + best-effort PDF; export actual editable DOCX + paginated PDF with approved images; record filename/size/SHA-256/page/content inspection; no NET |
| ST-04 | Smart Cost and Sales | 7 total / 6 paying / 1 FOC; supplier-reviewed Hotel 3★ + Cruise 5★ and separate Private/SIC; pricing matrix, approval and immutable SENT snapshot |
| ST-05 | B2B | Agency A/B, COMMON/EXCLUSIVE, revocation/ownership/direct-ID, correct white-label B2B proposal, denied confidential supplier values |
| ST-06 | AI Editor | Reviewed JSON preview/diff → NEW Draft, original immutable; 4 differentiated HTML styles, 5 private group tiers, null→On request, forged/foreign JSON/CSRF/conflict/dedup negative cases |
| ST-07 | B2C/Ads → LeadHub | Approved publication + consent + tagged link → one **NEW Lead** with source/UTM read-back; retry dedup, withdraw invalidation, cross-company forbidden; no Booking created; WP if separately in approved scope |
| ST-08 | Golden Path | Existing lead → Inquiry → Tour → Cost → approved Quote → SENT → accepted Booking → Ops handover → Finance AR/AP; trace continuous IDs, no fictitious fulfilment or receipts |
| ST-09 | Browser/recovery | Desktop 1440/1366, 390 mobile & real Android, permissions/read-only, login/session/CSRF, PWA update/offline/reconnect/save recovery, TLS/proxy checks |
| ST-10 | Rollback/owner | Restore of staged code + authorized DB/media recovery independently tested, rollback time/artefact recorded; operator then owner explicitly sign for the **same SHA/scope** |

For each test case, capture **operator, UTC timestamp, env URL, exact running SHA, build/manifest checksum, role/tenant/record IDs redacted, expected vs actual, screenshots or log/file SHA, tester/reviewer and verdict**. Valid verdicts: `NOT_TESTED`, `BLOCKED_ENV`, `BLOCKED_DATA`, `PASS_CI_FIXTURE`, `PASS_STAGING_REAL_AUTH`, `FAIL`, `OWNER_ACCEPTED`. No screenshot-only or CI-only promotion to `PASS_STAGING_REAL_AUTH`.

## G. Handoff checklist and next owner decisions

- [ ] Codex/release engineer confirms **one** canonical integrated branch, exact new SHA, source graph and ALL changed filenames; marks PR Draft.
- [ ] Exact-HEAD CI and verifiable full-install runtime ZIP/manifest/SHA256SUMS exported; no secret/material excluded incorrectly.
- [ ] Operator supplies approved stage vHost/TLS/docroot/PHP/DB/storage/owner/controller identity **as redacted evidence**.
- [ ] VIE-6 current-data backup→isolated clone restore/checksum/ledger, migration `090` decision and rollback rehearsal signed.
- [ ] VIE-11 approves **one staged operation** against exact source/artifact/host, with guard-preserving deployment path. Without authorization: `BLOCKED_GATE / NO UPLOAD`.
- [ ] QA completes all scoped ST-01–ST-10 with actual roles/browser/downloaded files and source IDs; owner reviews V6 visual parity Issue #99 and B2C scope/deferrals.
- [ ] Owner gives independent written staging acceptance for pinned SHA/scope. Production remains NO-GO without separate change approval.

**Evidence register template:** [RC62_STAGING_EVIDENCE_TEMPLATE_20261108.md](RC62_STAGING_EVIDENCE_TEMPLATE_20261108.md). Store **metadata and redacted links only** in GitHub; encrypted backups, DB dumps, customer PII, private config, secret pins and full export files remain in the authorized restricted evidence store.

## H. Reference map

- [Issue #45 — candidate integration](https://github.com/Vietnam202/Tour-Operator-/issues/45), [#97 — staged rollout directive/history](https://github.com/Vietnam202/Tour-Operator-/issues/97), [#99 — V6 UX rejection](https://github.com/Vietnam202/Tour-Operator-/issues/99), [#105 — AI editor](https://github.com/Vietnam202/Tour-Operator-/issues/105).
- [PR #98](https://github.com/Vietnam202/Tour-Operator-/pull/98), [#102](https://github.com/Vietnam202/Tour-Operator-/pull/102), [#103](https://github.com/Vietnam202/Tour-Operator-/pull/103), [#104](https://github.com/Vietnam202/Tour-Operator-/pull/104), [#106](https://github.com/Vietnam202/Tour-Operator-/pull/106), [#107](https://github.com/Vietnam202/Tour-Operator-/pull/107).
- [VIE-5](https://linear.app/vietnamtraveladvisor/issue/VIE-5), [VIE-6](https://linear.app/vietnamtraveladvisor/issue/VIE-6), [VIE-7](https://linear.app/vietnamtraveladvisor/issue/VIE-7), [VIE-8](https://linear.app/vietnamtraveladvisor/issue/VIE-8), [VIE-9](https://linear.app/vietnamtraveladvisor/issue/VIE-9), [VIE-10](https://linear.app/vietnamtraveladvisor/issue/VIE-10), [VIE-11](https://linear.app/vietnamtraveladvisor/issue/VIE-11), [VIE-14](https://linear.app/vietnamtraveladvisor/issue/VIE-14), [VIE-16](https://linear.app/vietnamtraveladvisor/issue/VIE-16).
- [Existing VIE-16 UAT gate](RC62_VIE16_AI_EDITOR_STAGING_GATE_VI.md), [current read-only control center design](RC62_RELEASE_CONTROL_CENTER_MODULE_V1_20261020.md), [full-install/staging baseline](RC62_INTEGRATED_STAGING_RELEASE_VI.md), [operator evidence](RC62_OPERATOR_STAGING_EVIDENCE_HANDOFF.md).

**Final current decision (2026-11-08): `DRAFT / BLOCKED_GATE / NO UPLOAD / NO MERGE / NO PRODUCTION WRITE`.**
