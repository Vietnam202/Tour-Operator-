# RC6.2 PR #60 + PR #59 — combined candidate regression gate

Date: 11 October 2026. Status: **DRAFT / NO-GO FOR CYBERPANEL**.

## Parentage and intent

- Base / parent: PR #60 exact SHA `101fef611de8892be089a1e60f2470a680cd3e19` (synthetic MariaDB dump → isolated restore → read-only exact-SHA preflight).
- Integrated sibling fixes: PR #59 exact SHA `34fde2ddc232c9dc22f9f9494423a724ae186979` (V5 parser, passenger bands, idempotent Save-as-Template and stale save recovery, real synthetic Chromium 1440/390px; explicitly raw-head CI jobs).
- Both originated at PR #58 SHA `d0d9245318980b98f5927008e6bda465d218752d`. This candidate is a single integration branch; do **not** infer PR #59 and #60 can independently be merged to production.

## Integration checks

1. Keep all three PR #60 source changes, including negative checksum fixture reset and the `tests/rc62-clone-restore-preflight.sh` synthetic restore test.
2. Apply all PR #59 changed source, UI and test files, with only `.github/workflows/rc62-integrated-runtime.yml` manually reconciled to contain **both** restored-clone preflight and V5 parity triggers / regression tests.
3. New candidate exact SHA must pass Integrated Runtime (package + disposable schema/clone), Unified Workspace (backend-native, backend-domain, interface regression/Chromium), Marketing UI Sync and Marketing Full-schema Rehearsal. Assert all jobs are bound to the new SHA; prior #59/#60 workflow results are not evidence for this combined head.
4. Confirm `PASS_CI_FIXTURE`, `PASS_CI_HTTP_FIXTURE` and `READ_ONLY_PREFLIGHT_PASS` do **not** set `release_authorized=true` and do not claim real staging success.

## Blocking external acceptance

- Authorize and independently verify a new SHA-pinned ZIP/manifest/hash, PHP runtime and CyberPanel HTTPS staging docroot / allowlist with private secrets outside webroot.
- Operator-produced encrypted backup of an **existing-data** authorized source, isolated clone restore, table/row reconciliation, migration ledger/checksum and rollback rehearsal; do not connect synthetic CI scripts to staging/production.
- Real staging browser with authenticated Admin/Sales/Read-only and partner A/B Common/Exclusive tests; Tour Library original → Save-as-Template → reopen and edit; parser/bands/duplicate-save recovery; customer DOCX/PDF and no supplier NET/profit leakage.
- Real Android/mobile PWA, WordPress connector boundary, Golden Path E2E-01..08 and owner VIE-11 signed release gate.
- Current CyberPanel controller branch pin remains unchanged. Do not merge, upload, alter production or run production migrations as a side effect of this PR.

Outcome is only a combined **code/CI candidate** until the above evidence is recorded.

## Post-merge candidate PWA forensic finding (11 October 2026)

The combined PR #61 had V5-4-RECOVERY asset query versions, but its Service Worker still opened the historical `vta-RC62-SMART-P1-1` cache. A new worker could add assets into the same cache, leaving old entries behind. Worse, `caches.match()` searched all caches on the same origin, potentially serving an unrelated/older response under an identical URL.

**Fix:** rotate only the VTA PWA cache to `vta-RC62-V5-5-PWA`, read cached assets and offline fallback exclusively through `caches.open(CACHE).match()`, and use explicit HTTP 503 if the offline fallback is missing. Keep online navigation network-first/no-store; never cache API, exports, uploads, arbitrary origins or non-GET methods. Bump package verifier and Smart P1 contract to the same cache version.

**Automated regression:** `node tests/pwa-cache-upgrade.cjs` in exact-HEAD runtime package and unified UI CI tests cache rotation, old/foreign cache isolation, activation/claim, offline fallback 503, and no API/private resource interception. These checks use mock Cache API in Node, not physical Android/PWA UAT. No RuntimeGuard allowlist, SQL schema or staging controller changes.

**Release:** still DRAFT / NO-GO: before installation, independently verify artifact SHA and the CyberPanel stage hostname/docroot, HTTPS/private config, existing-data clone/rollback, real user/session ACL, and real-device PWA clear/update/offline recovery. Never use CI-only synthetic MariaDB scripts on existing data.

### CI fixture compatibility correction

The first cache-rotation commit deliberately failed existing Marketing/Unified tests because `tests/pwa.cjs` mocked `caches.open().addAll()` but omitted the standard per-cache `match()` method, and `tests/vta-approved-ui.cjs` still asserted the obsolete cache constant. Updated both existing tests to the real scoped Cache API contract; made global `caches.match()` throw in the test to prevent this regression. Retain the new behavioral test; do not claim PASS until a fresh exact-head run completes.

Additional exact-head Unified QA gate: the RC6.2 UI-refresh parity test also pinned the retired cache string; its expected version has been aligned without weakening stylesheet/order assertions. New CI must pass before a release candidate can be treated as reproducible.
