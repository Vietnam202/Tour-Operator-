> Historical v2.x reference only. For this v3 staging candidate, follow V3_STAGING_UPGRADE.md and V3_AUDIT.md instead of the deployment instructions below.

# Upgrade: Phase 1 Build 1 → Build 2

Use staging first. Do not overwrite the current V1.x production application while VTA v2 is still being validated.

## Before upgrade

1. Back up the Build 1 database.
2. Back up the Build 1 application directory.
3. Confirm the private VTA config remains outside `public_html`.
4. Confirm Google Drive credentials / local staging document storage are unchanged.

## Upgrade

1. Replace the Build 1 application code with Build 2 code while keeping the private server configuration.
2. Run the installer again from CLI using the same admin email. The migration runner applies only migrations not yet recorded in `schema_migrations`.
3. Confirm `002_phase1_rate_rules_import.sql` is recorded in `schema_migrations`.
4. Hard refresh the browser / close and reopen the PWA to clear the old service-worker shell.

## Smoke test

1. Login.
2. Open Product → Rate Master.
3. Create one Hotel rate and verify Hotel Rate Rules.
4. Create one Transport rate and verify Transport Rate Rules.
5. Open a supplier XLSX document → Bulk Import Matrix → create draft rates.
6. Confirm imported rows are DRAFT / NEEDS REVIEW.
7. Create an overlapping draft rate and attempt approval; verify Conflict Review appears.
8. Import a small legacy JSON/CSV sample; verify `LEGACY` appears and missing mappings are not guessed.
9. Open a source-linked rate and verify source document traceability.

## Production caution

Build 2 adds database tables and one new `rates.source_origin` field. Database backup is mandatory before applying migration 002.
