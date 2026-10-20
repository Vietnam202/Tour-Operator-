# RC6.2 Release Control Center — Module V1 (design and read-only preview)

> **Status: DESIGN ONLY / NOT RUNTIME / NOT DEPLOYED.** Prepared 2026-10-20 against Draft PR #106 at `5dbbe09d55395346108ff757843fc3aedeb2048f`. No assertion of current staging or owner approval. All current issues remain in their real Linear states. **Release is Draft/NO-GO** until separately authorized evidence satisfies the gates.

## 1. Product placement and scope

- **Location:** RC6.2 / Admin / System / **Release Readiness**. An optional read-only "Release: Draft / NO-GO" indicator may appear on VIE-16 AI Edit Itinerary, but the governance module is **not** a subfeature of the AI editor and **not** part of the separate VTA Marketing Tool.
- **Target:** consolidate Linear VIE-5/6/7/8/9/10/11/12/14/16 + GitHub PRs and operator evidence **per immutable release candidate**.
- **Non-goals:** no automatic merge, upload to CyberPanel, migration, secret retrieval, browser authentication bypass, real publishing or production write; this is not a replacement for existing deploy/controller guards.
- **Phase 1:** static operator-facing view, acceptance matrix, hard stops and evidence checklist; no live Linear/GitHub connector in runtime. Status shown in preview is an **explicit historical snapshot**, not current sync.
- **Phase 2 (separately approved):** server-side read-only source sync + independent secure operator evidence capture; backend enforcement of any release action (not advisory UI only).

## 2. Truth model and hierarchy

Every release candidate MUST be identified by:
- Repository, base branch/base SHA, **full integrated candidate HEAD SHA**, PR dependency graph, GitHub run IDs, ZIP SHA-256, manifest SHA-256, source inventory SHA-256, migration set/checksum digest.
- Independent operator-pinned hostname, docroot, DB identity, clone DB identity, approved storage path; never auto-trust remote health JSON.
- Evidence source URI + evidence category + scope + timestamp + actor role + redaction state + digest + input/output IDs + expected/actual + independent reviewer + status.
- Scope and approvals for B2B, B2C, and VIE-16 independently.

Evidence levels are **NOT interchangeable**:
1. `CODE_PRESENT`: inspected source files and feature code.
2. `CI_FIXTURE_PASS`: exact-SHA GitHub checks on synthetic/disposable infrastructure, including real byte artifacts in CI.
3. `STAGING_REAL_PASS`: approved isolated staging with independently pinned runtime identity and real-auth/device evidence.
4. `OPERATOR_SIGNOFF`: environment/data/rollback approved by authorized operator for the exact SHA.
5. `OWNER_SIGNOFF`: formal scope-specific decision. Stage approval != production approval.

Missing, failed, stale, wrong-SHA or unverifiable evidence = `BLOCKED` by default. A CI `success` must **never** be displayed as STAGING PASS.

## 3. Dependency gates

```text
VIE-5 scope/integration & fixed candidate SHA
    |
    +--> VIE-6 read-only host/docroot/DB/storage/ACL preflight
    |       -> encrypted current-data backup and checksum
    |       -> isolated clone restore + data/ledger/checksum reconciliation
    |       -> reviewed forward-only clone migration & rollback rehearsal
    |       -> operator VIE-6 evidence/signoff
    |
    +--> VIE-11 authorizes *staging only* after VIE-6, approved artifact/host
            -> exact deployed SHA/source inventory + independent docroot verification
            -> VIE-16 staging real-auth UAT (AI preview/draft/pricing/export)
            -> VIE-7/8/9/10/12/14 and B2C/WordPress/LeadHub/PWA gates
            -> owner signoff by scope + verified rollback
            -> VIE-11 release decision (PRODUCTION REQUIRES DISTINCT APPROVAL)
```

VIE-16 can edit/create **new DRAFT revisions** with existing RBAC/review checks in a safe development or authorized staging workflow. The release gate blocks **publish, external sending and deploy**, not legitimate draft editing. An offline demo approval does not count as runtime release approval.

## 4. Acceptance matrix (starting snapshot 2026-10-20)

| Gate | Issue | Code/CI evidence | Real staging | Owner/operator decision | Result |
| --- | --- | --- | --- | --- | --- |
| Data safety: backup/restore/ledger | VIE-6 Todo, Urgent | SELECT-only/clone fixture in Draft PR #73/#80/#85 | Existing-data clone and migration rehearsal not verified | No completed operator approval | BLOCKED_DATA |
| Runtime identity and deploy | VIE-11 Todo, Urgent | Identity/TLS fixture in Draft PR #86/#87 | CyberPanel stage actual SHA/docroot/TLS not verified | No staging release signature | BLOCKED_ENV |
| AI Edit Itinerary | VIE-16 In Progress | PR #106 SHA `5dbbe09d55395346108ff757843fc3aedeb2048f`, 14/14 GitHub Actions success | Real-auth + visual parity not verified | Offline design/conditional staging approved only | BLOCKED_UAT |
| B2C/LeadHub/Ads | VIE-11 dependency | Draft PR #104 CI fixture; migration `090_ads_landing_publish.sql` included | Published WP, signed intake, UTM/consent real-UAT not verified | No B2C scope signoff | BLOCKED_SCOPE |
| Release decision | VIE-11 | CI checks are not a release decision | No complete E2E UAT/rollback evidence | Production owner approval absent | BLOCKED_APPROVAL |

PR stacked order: #102 `096a38b8...` -> #103 `ca23b61d...` -> #104 `ee394f96...` -> #106 `5dbbe09d...`. All Draft at inspection. Source: links in section 9.

## 5. Proposed module panels

1. **Overview** — pinned candidate and evidence freshness, risk summary, computed gate state (fail-closed).
2. **Dependency graph** — VIE-5→VIE-6→VIE-11 (staging authorization)→VIE-16 and full feature UAT→VIE-11 (final signoff). Display owners and real blockers.
3. **Acceptance Matrix** — rows grouped Code/CI, data, staging, UAT, rollback, owner with exact SHA and evidence URI.
4. **Evidence Register** — view metadata of independently verified operator evidence; no raw secrets, dumps, tokens or client PII.
5. **UAT Workbench** — record input, expected/actual, screenshots/bytes hashes, role, device, exact staging SHA, test verdict; never self-mark PASS based on form completion.
6. **Owner Decision** — read-only summary of explicit GO/NO-GO evidence, scope-specific signatures and approved deferrals; UI cannot manufacture approval.

## 6. Essential acceptance tests

### VIE-6
- Correct clone DB identity; SELECT-only account has no INSERT/UPDATE/DDL/global/GRANT.
- Encrypted backup of **actual VTA existing data** outside docroot; verified SHA; restore into **separate isolated clone**, reconcile row counts/digests, ledger and checksums.
- Special-case legacy 025 and duplicate 037 filenames (B2B / Marketing); reviewed migration 090 under Draft PR #104 only on approved clone. Record migration ledger **before and after**.
- Check misconfigured host/DB/storage and checksum drift hard stop; rollback proves restore works (DDL is not transactionally reversible).
- Never access or overwrite production DB, old `v2quote` private storage, WordPress live data or production config.

### VIE-11
- Independently pin ZIP SHA/manifest/source inventory and compare with actual stage docroot; `health.build` response alone is insufficient.
- Stage host+TLS/CORS/Host/proxy/DB isolation and approved PHP environment; no wildcard or legacy-host bypass.
- Real authenticated Admin/Sales/Ops and Partner A/B, Common/Exclusive isolation, CSRF, direct-ID, revoke, signed intake and customer-facing privacy.
- Golden Path Lead→Tour→Cost→Quote→immutable SENT→Booking→Operations→Finance, and B2C immutable publication→signed WordPress publish→revoke/cache purge→LeadHub.
- Supplier-approved 6 paying + 1 FOC, Hotel 3★ + Cruise 5★; real output DOCX/PDF, 50 genuine source documents if required by release scope, Android/PWA/network recovery.
- Signed rollback and operator & owner approvals; **separate** production GO decision.

### VIE-16
- Human-reviewed ChatGPT/Hermes JSON fallback; no fake claim of automatic provider API.
- 7D6N→8D7N added Ninh Binh: original unchanged, preview/diff, commit new **DRAFT**, concurrent revision/409, CSRF, RBAC, audit and dedup.
- Four distinct draft previews at 1366/390 widths; five groups (4/6/8/10/12 pax) × 3★/4★/5★; null price => `On request`.
- Real DOCX and PDF downloaded under authorized stage, count pages and verify prices/approved imagery, no NET/supplier costs.
- Master draft changes do not autopublish or mutate B2C, B2B partner working copies or immutable SENT/Booking snapshots.

## 7. Hard-stop logic (server-enforced only after separate implementation review)

```text
if missing candidate SHA or unchecked PR dependencies: BLOCKED_SCOPE
if clone/backup/checksum/ledger/preflight not PASS: BLOCKED_DATA
if exact host/docroot/TLS/DB/storage/running SHA not PASS: BLOCKED_ENV
if real-auth, privacy, DOCX/PDF, WordPress/LeadHub/PWA not PASS: BLOCKED_UAT
if owner/operator signatures for approved scope or rollback missing: BLOCKED_APPROVAL
otherwise: ELIGIBLE_FOR_MANUAL_OWNER_DECISION  # NEVER auto production GO
```

Evidence expiration/change invalidation:
- **Any new code SHA or artifact digest** invalidates CI + runtime/UAT evidence tied to old candidate.
- DB schema/host/TLS/storage change invalidates relevant VIE-6/VIE-11 operator preflight and stage checks.
- Source tour/revision/price change invalidates VIE-16 approval/pricing/publication evidence, not immutable old SENT.
- A deferred B2C scope requires explicit owner-authored decision; absence does not mean permission.

## 8. Acceptance and rollout plan

1. Introduce this page as **isolated read-only preview** (not wired to release actions or deployment controller).
2. Review architecture with owner/SRE; select canonical operator evidence storage and signer/ACL.
3. Add server-side evidence validation, independent SHA pinning and tests in **separate Draft PR**; nothing in this design changes deployment authorization.
4. Perform VIE-6 operator preflight and approved backup→clone restore. Only after VIE-6 PASS request **staging-only** authorization under VIE-11.
5. Execute authenticated UAT and obtain owner decision. Until then all business/production actions remain NO-GO.

## 9. References

- [VIE-6](https://linear.app/vietnamtraveladvisor/issue/VIE-6/rc62-p0-mariadb-clone-migration-ledger-va-cyberpanel-fail-closed)
- [VIE-11](https://linear.app/vietnamtraveladvisor/issue/VIE-11/rc62-p0-release-gate-exact-sha-staging-signoff-cyberpanel-upload-and)
- [VIE-16](https://linear.app/vietnamtraveladvisor/issue/VIE-16/rc62-ai-edit-itinerary-chatgpthermes-4-templates-5-private-group-tiers)
- [RC6.2 integration issue #45](https://github.com/Vietnam202/Tour-Operator-/issues/45)
- [PR #106](https://github.com/Vietnam202/Tour-Operator-/pull/106), [PR #104](https://github.com/Vietnam202/Tour-Operator-/pull/104)
- [PR #73](https://github.com/Vietnam202/Tour-Operator-/pull/73), [PR #80](https://github.com/Vietnam202/Tour-Operator-/pull/80), [PR #85](https://github.com/Vietnam202/Tour-Operator-/pull/85), [PR #86](https://github.com/Vietnam202/Tour-Operator-/pull/86), [PR #87](https://github.com/Vietnam202/Tour-Operator-/pull/87)
