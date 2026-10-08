# VTA RC6.2 Marketing – P11 core-first migration adapter

## Scope and status
- **Source-only draft PR**, stacked on P10. No CyberPanel access, no migration executed on the real staging database, no production change.
- P10 identified a **real migration-order collision**: alphabetical SQL execution applies `023_marketing_webhook_core` and `024_website_unified_inbox` before `025_vs21_shared_requirements`. The historical VS2.1 guard rejects already recorded `023_*`/`024_*` entries to protect candidate schemas.
- P11 **does not modify historical SQL files, baseline hashes, pricing calculations, or quote data**.

## Exact behavior of the fix
1. `Migrations::orderedFiles()` produces a deterministic **core-first** order. Existing VS2.1+ quotations/smart-cost migrations run in their original lexical order, then the exactly enumerated Marketing migrations:
```text
023_marketing_webhook_core
024_website_unified_inbox
025_website_chat_outbound
037_marketing_tour_advisor_p3
038_marketing_tour_share_p4
039_social_publishing_core
040_instagram_publishing
041_meta_unified_inbox
042_meta_messenger_replies
043_meta_connection_health
```
2. The pre-existing `025_vs21_shared_requirements` **legacy candidate guard stays intact** on first application. Any unrelated `023_*`/`024_*` schema history still blocks the first VS2.1 migration.
3. The guard is not rerun after `025_vs21_shared_requirements` already appears in `schema_migrations`: a successful Marketing schema can therefore be safely inspected/re-run without false positives.
4. A **partial** Marketing SQL stack fails closed before any SQL is applied. Existing SQL checksums and `RUNNING`/`FAILED` statuses remain checked exactly as before.
5. P10 source preflight now reports `MIGRATION_ORDER_RECONCILED` but **still blocks overall staging release** for the unreviewed forward-only schema install, testing branch pin, and human/browser signoff.

## What this does NOT solve
- **P10 remaining blocker:** the deployed private Fast Staging controller `deploy/staging-release.php` rejects *new* SQL filenames in code rollout. It is installed outside webroot and is **not updated automatically from Git**. This P11 code cannot authorize a deployment by itself.
- Existing `schema_migrations` / `migration_checksums` state on the actual CyberPanel database was not inspected. A pre-existing unsupported 023/024 candidate history must be assessed, not blindly converted.
- P11's disposable MariaDB test runs **minimal compatible fixture SQL**, not a full production-schema clone. Passing it does not constitute successful integration against real quotes/costs.
- No schema migration was run on actual staging; no database backup or restore drill has been performed here.

## Safe next step (P12)
Develop an independently reviewed **forward-only Marketing schema installer** with immutable migration manifest/hash checks, staging-host+DB guard, explicit operator enablement, verified backup/restore rehearsal, migration lock, and dry-run by default. Review the private deployment controller and a matching clone-only code promotion path; do not alter its running copy without explicit authorization.

Only after database migration evidence and source hashes are reviewed should an authorized operator test staging deployment from the approved testing branch. Keep Meta outbound workers disabled.

## Tests
- `.github/workflows/marketing-core-first-migrations-p11.yml`: MariaDB dependency order, idempotent rerun, checksum tampering, 023 candidate-history rejection, missing Marketing migration denial, and P10 readiness still BLOCKED.
- The old P10 gate retains staging release guards. No force-push/merge to `codex/Vietnam/rc6.2-testing`.
