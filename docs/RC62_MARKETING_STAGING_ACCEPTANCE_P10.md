# RC6.2 Marketing – Staging Acceptance P10 (read-only integration gate)

## Decision as of 2026-10-09

**BLOCKED — DO NOT AUTO-DEPLOY.** This is not a production/staging deployment, not a merged release and not approval to migrate VTA hosting. GitHub-only isolated workflows P0–P9 previously passed, but they do **not** prove full production-schema compatibility or actual CyberPanel deployment.

### Three independently verified blockers

1. **Migration compatibility:** `api/lib/Migrations.php` contains an explicit `025_vs21_shared_requirements` guard that refuses any recorded `023_*`/`024_*` candidate history. The stacked marketing branch adds `023_marketing_webhook_core` and `024_website_unified_inbox` to the standard sorted migration directory before `025_vs21_shared_requirements`. The existing migrator will therefore halt when this dependency order is applied to a fresh compatible baseline. **Do not bypass the guard** or edit historical SQL checksum records.
2. **Staging deployment controller:** `deploy/staging-release.php` rejects any added/removed/changed SQL migration via `MIGRATION_REVIEW_REQUIRED`. The Marketing stack adds ten new migration files (023,024,025_outbound,037–043). This controller is **not** a schema migrator and cannot be instructed to install them without a separate, reviewed forward-migration plan.
3. **Branch safety:** `deploy/deploy-staging.sh` and `deploy/staging-release.php` are pinned to `codex/Vietnam/rc6.2-testing`. P0–P9 currently exist as **stacked draft PRs**, ending at PR #36, not necessarily in the deployed testing branch. Do not switch the target branch silently or blindly merge the stack.

### Added in P10

- `api/lib/MarketingStagingGate.php`: pure read-only checks on source files, migration order, legacy guard, deploy branch pin and migration controller. Supports optional read-only database migration checksum/ledger verification and staging config sanity.
- `api/bin/marketing-staging-preflight.php`: CLI defaults to **source-only** report; `--db` separately reads staging migration history (requires private staging config and RuntimeGuard). Returns JSON; nonzero exit **2** when blocked. No SQL writes, no Meta calls, no web operations.
- `tests/marketing-staging-readiness-p10.php`: disposable MariaDB tests for all three blockers, missing/pending migrations, status/checksum drift and staging-vs-production protection.
- `.github/workflows/marketing-staging-acceptance-p10.yml`: GitHub Actions verifies that fail-closed behavior passes. A green P10 workflow means the **block is correctly detected**, not that deployment is ready.

## Review gates before staged rollout

1. **Snapshot source SHA**: record reviewed head commits for P0→P9 and compare their actual ancestry and file diffs. Don't merge to testing until release candidate is reviewed.
2. **Inspect staging DB only**: review schema_migrations and migration_checksums, current `quote_options` schema/unique indexes, historical `023_*`/`024_*` records, and whether `025_vs21_shared_requirements` was applied. Verify schema compatibility with the running quotation/costing engine **without touching its tables**.
3. **Resolve migration version collision** by proposing new Marketing migration identifiers beyond the actual current schema head; preserve all applied SQL files/checksums. Different installation scenarios (fresh staging vs existing 025-applied DB) need a documented adapter. No reordering of already applied versions, zero manual `INSERT INTO schema_migrations` as a shortcut.
4. **Release controller design review**: develop an explicit, backup-gated, checksum-pinned **forward-only** schema upgrade process separate from normal code release. Verify rollback path for source; DB forward changes generally cannot be rolled back by replacing PHP files.
5. **Restore rehearsal**: take a database dump and a separate file snapshot; test restoration on a disposable copy. Protect customer names, messages, user accounts and quote data.
6. **Apply migrations to a clone** of staging first. Run all existing sales/costing, document, operations and Marketing P0–P9 regression checks; check role separation, audit logs, cross-company boundaries and rate calculations.
7. **Code cutover**: only after gate is clear, promote an immutable, reviewed commit to the approved testing branch. Exercise a dry deployment and rollback on a clone before touching CyberPanel.
8. **Browser acceptance** on `v2quote.vietnamtraveladvisor.com.vn`: Login, Marketing, Webhook Center, inbound website chat, Lead Hub handoff, Tour Advisor, approved share link + expiry, Publishing queue, Meta Connections; verify mobile and desktop. Do not infer completion from PHP lint alone.
9. **Meta real-world smoke**: configure an authorized test Page/Instagram Professional only after App Review/permission checks. Validate signed incoming text; leave outbound workers off until approved. Never include tokens in files, tickets, chat or test output.
10. **Release signoff**: formal pass/fail sheet for security, DB, UI, CRM attribution and rollback, and an operator-approved staging release. Production needs separate authorization.

## Command examples (no actual deployment)

Safe source-only check from checked-out candidate:
```bash
php api/bin/marketing-staging-preflight.php
```
Expected: **nonzero exit 2, BLOCKED**, until the three known blockers are resolved.

Optional **read-only** staging ledger inspection, with externally provisioned private configuration and no provider calls:
```bash
VTA_CONFIG_FILE=/home/v2quote.vietnamtraveladvisor.com.vn/vta_private/config.php \
 php api/bin/marketing-staging-preflight.php --db
```
Do not run from production or with production DB credentials.

## Acceptance checklist

- [ ] P0–P9 immutable PR ancestry and CI reviewed
- [ ] Migration version collision resolved by approved forward-only adapter
- [ ] Private staging backup plus restore rehearsal completed
- [ ] Controller permits reviewed, checksum-pinned forward schema changes only
- [ ] Scoped staging tests (including existing Smart Cost and quote) pass
- [ ] Website chat, Messenger and Instagram inbound payload flows smoke-tested
- [ ] Post/share permissions and account tokens do not leak to frontend/logs
- [ ] Real provider calls disabled during initial acceptance
- [ ] Staging run result and human signoff recorded
- [ ] Separate production release authorization

**Scope:** the current P10 PR is an executable safety gate and diagnostic report, not a staging deploy. It intentionally leaves P0–P9 draft and preserves the historical quotation DB.
