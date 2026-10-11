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
