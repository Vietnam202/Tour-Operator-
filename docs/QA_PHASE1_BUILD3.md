> Historical v2.x reference only. For this v3 staging candidate, follow V3_STAGING_UPGRADE.md and V3_AUDIT.md instead of the deployment instructions below.

# QA — VTA v2.0 Phase 1 Build 3

## Completed in build environment

- PHP syntax lint: PASS for all PHP files.
- JavaScript syntax check: PASS.
- Service-worker cache key bumped to Build 3.
- Migration 003 file included in ordered migration directory.
- New permissions referenced by installer role mapping.
- New service schemas present for Guide/Tour/Attraction/Meal.
- Structured date-period/child/FOC tables and save/copy/fetch paths present.
- Rate evaluation API and UI tester present.
- Contract comparison API/UI present.
- Import batch list/detail/reconciliation API/UI present.
- Source-traceability and human-approval rules from Builds 1–2 retained.

## Not possible to certify in this build environment

The following require the VTA staging server and therefore remain staging QA items:

- MariaDB migration 003 execution against the actual Build 2 database;
- Google Drive OAuth/service-account upload and download;
- PHP-FPM session/cookie behavior over the real HTTPS domain;
- cron execution under the actual CyberPanel user;
- restore of a real database backup;
- real supplier workbook layouts and scan-only PDFs;
- multi-user concurrency on the real server.

## Required staging regression

Retest Builds 1–2 behavior after migration 003:

```text
Supplier → Document → Draft Rate → Conflict Review → Approval → Source Document
Bulk XLSX → Draft Rates
Legacy Import → Draft/Skipped rows
Rate expiry task generation
```

Then execute the Build 3 scenarios in `STAGING_CUTOVER_CHECKLIST.md`.
