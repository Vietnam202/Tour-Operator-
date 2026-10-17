# VIE-16 — AI Edit Itinerary — staging-only operator acceptance (2026-10-17)

**Status: CODE / CI candidate; NO STAGING DEPLOYMENT VERIFIED.**
Related: [Draft PR #106](https://github.com/Vietnam202/Tour-Operator-/pull/106), [GitHub #105](https://github.com/Vietnam202/Tour-Operator-/issues/105), [VIE-16](https://linear.app/vietnamtraveladvisor/issue/VIE-16/rc62-ai-edit-itinerary-chatgpthermes-4-templates-5-private-group-tiers), [VIE-6](https://linear.app/vietnamtraveladvisor/issue/VIE-6/rc62-p0-mariadb-clone-migration-ledger-va-cyberpanel-fail-closed), [VIE-11](https://linear.app/vietnamtraveladvisor/issue/VIE-11/rc62-p0-release-gate-exact-sha-staging-signoff-cyberpanel-upload-and).

## Absolute safety boundary

- Target **only** `https://rc62-staging.vietnamtraveladvisor.com.vn` after checking vHost, SSL/SNI, actual docroot, PHP 8.3, dedicated MariaDB clone, media private paths, current runtime identity and rollback. DNS lookup of this host failed from the ChatGPT execution environment on 2026-10-17; **host availability is NOT proven**.
- Do **not** fall back to `v2quote.vietnamtraveladvisor.com.vn`, `quote.vietnamtraveladvisor.com.vn`, main PHP site, WordPress, any other host or production DB.
- This Draft PR is based on **Draft PR #104**, itself dependent on #103/#102; an isolated checkout of `main` is NOT equivalent. Verify latest `#106` HEAD and all parent PR SHAs **immediately before release**; never reuse an older CI result or ZIP.
- The legacy `deploy/deploy-staging.sh` pins `codex/Vietnam/rc6.2-testing` and `v2quote...`. It is **NOT authorized** for this target. Never edit, bypass or force the controller to accept the new host/branch. A separate reviewed deployment method, based on verified staging identity, is required.
- VIE-6 requires authorized, encrypted backup **of the actual target staging dataset** → restore into disposable isolated clone → migration ledger/checksums and PHP/API smoke → verified rollback. Existing PR #92 backup and 141/141 checksums do **not** transfer automatically to a different release source SHA.
- All secrets, personally identifiable data and DB dumps stay in private operator storage. No test may create real supplier confirmations, guest payments, SENT quotes or bookings. **Production remains NO-GO.**

## Design-reference provenance

Owner-approved **offline** visual reference: `VTA_RC62_FINAL_4_TEMPLATES_5_PRIVATE_GROUPS.html` (SHA-256 `06ddf7253a427f0eb78ed229d9026f678237c6b4e9331911c13ffe30eaed4d46`, 4,628,203 bytes). This file is an external ChatGPT handoff and is **not** assumed to be in the GitHub repository, available to CyberPanel or installed on staging. Validate actual screenshot parity in the browser rather than equating four CSS variant names with four fully approved designs.

## 0 — Exact-SHA read-only gate

1. Independent operator confirms actual staging DNS, TLS/SNI, hostname, runtime user, docroot, dedicated DB `SELECT DATABASE()`, private media storage, mounted config, schema/ledger and **exact runtime SHA**. Record only redacted identifiers.
2. Independently inspect the approved candidate ZIP, its file manifest and SHA-256. Never trust HTTP `health` build identity alone as an integrity proof.
3. Use the **existing read-only smoke** `node tests/rc62-staging-readonly-smoke.cjs` following [the maintained operator instructions](RC62_STAGING_HTTP_OPERATOR_SMOKE_VI.md). Required independent environment variables: `VTA_STAGE_URL`, `VTA_STAGE_APPROVED_HOST`, `VTA_STAGE_APPROVED_SHA`, `VTA_STAGE_APPROVED_SOURCE_INVENTORY_SHA256`, and `VTA_STAGE_READONLY_APPROVAL`. No credentials for anonymous mode; GET only. The `--auth` mode creates login audit records, so it requires the **separate operator login approval** documented there.
4. If hostname/SHA/docroot/DB mismatches, DNS fails, controller refuses, backup/clone cannot be verified, or VIE-6/VIE-11 not signed: **BLOCKED_ENV / BLOCKED_GATE — STOP BEFORE UPLOAD**.
5. A successful ephemeral-MariaDB GitHub workflow is a **CI fixture**, not proof the currently deployed staging database was recovered.

## 1 — Functional in-app UAT matrix (only on an authorized staging clone)

| ID | Operator action | Expected evidence |
|---|---|---|
| AI-01 | Login as authorized Master manager; open an existing editable Master Tour in Word/Tiptap | Original content/photos visible and unchanged, `AI Edit Itinerary` present only when `tour_library.manage` |
| AI-02 | Copy prompt, edit via ChatGPT/Hermes externally, paste `VTA_MASTER_ITINERARY_V1` JSON | Must explicitly say LIVE AI connector **not connected**; source identity and revision preserved |
| AI-03 | Preview change 7D6N → 8D7N, Ninh Binh Day 5 | View **meaningful actual changes** and route warnings; source Master untouched prior to approval |
| AI-04 | Change JSON title or one 4★ price **after** Preview and attempt commit | **REVIEW_REQUIRED**; must preview changed payload again. Expired/other-user/other-company token also denied |
| AI-05 | Commit reviewed proposal once, retry with same idempotency key | Exactly **one NEW DRAFT Master**; original binary/source/reference/history unchanged |
| AI-06 | Private group 4,6,8,10,12 (five rows) × 3★/4★/5★; replace a pax size; set only approved selling price | Persist/reopen same five rows. Unapproved/blank prices `On request`; NET never in customer payload |
| AI-07 | Switch 4 layout previews: Detailed / Brochure / Quick / Proposal | Each render consistent itinerary, approved images and same grid; capture desktop 1366 and mobile 390 screenshots; compare to external approved demo |
| AI-08 | Export **real** HTML/DOCX/PDF bytes from NEW Draft; re-import DOCX text | All show same approved price grid; verify images, page breaks, correct table values, no supplier NET |
| AI-09 | Try CSRF-invalid request, unauthorized role, cross-company Master ID, stale source revision, invalid JSON, duplicate/nonascending pax, negative price, injected script/media ID | Consistent 403/419/404/409/422 or appropriate fail-closed response, no disclosure/writes |
| AI-10 | Inspect B2B quote/SENT, B2C publication/withdraw, quote pricing, Booking/Ops/Finance before/after test | No regressions, no auto-B2C publication, no booking, no supplier order or production effects |

Record exact SHA, test user role (no credential), input fixture hash, expected/actual, redacted response and screenshot for each ID. A pass of `AI-01` Word Editor opening is **not** a pass of AI-07 visual parity or AI-08 real export. Explicitly flag `SIC` → Private operational mismatch.

## 2 — Exit decision

- **GO for staging-only operator deployment** is possible only after VIE-6 backup/restore clone/ledger/rollback and VIE-11 exact-SHA/host/controller evidence are approved and an authorized staging deployment path exists. An owner request for deployment does not override these protections.
- **PASS_STAGING_REAL_AUTH** requires actual persisted NEW Draft read-back, screenshots, authentic DOCX/PDF bytes and negative cases. Keep CI fixtures separate from real staging tests.
- **NO-GO for production** until the wider RC6.2 owner release signoff is recorded separately.

Use this file as the stable staging handoff; update exact SHAs/run IDs in the evidence record after code freeze, not by silently changing prior evidence.
