# VTA RC6.2 – Marketing P12 Full-schema Clone Rehearsal

## Status and explicit safety boundary
**P12 is code-only, not a staging deployment.** The application remains on a stacked draft PR branch, the CyberPanel fast-deploy controller is still pinned to `codex/Vietnam/rc6.2-testing`, and source/DB changes on the live staging host are not authorized by these tests.

P12 is the next step after P10's blocking readiness gate and P11's core-first migration planner. It does NOT remove the P10 blockers for the private deploy controller, immutable branch promotion, backup/restore signoff and browser acceptance.

## Implemented

- `api/lib/MarketingSchemaUpgrade.php` builds an ordered, **SHA-256 pinned** source manifest from real SQL 001–043, then reads `schema_migrations` and `migration_checksums` without writes.
- Refuses untracked historical migrations, unapproved SQL checksum drift, FAILED/RUNNING migration rows, incomplete core prerequisites, or non-contiguous Marketing ledger.
- `api/bin/marketing-schema-p12.php` has three modes:
  - `--manifest` is **default**: no DB credentials or connection, returns manifest fingerprint and versions.
  - `--inspect` reads migration ledgers using private staging/testing config and localhost MariaDB. No schema changes.
  - `--apply-clone` can write **ONLY** an isolated `vta_p12_clone_*` MySQL database on `localhost` / `127.0.0.1` with `app.env=testing` and `app.base_url` pointing to local testing. Refuses actual `v2qu_v2qu_vtaos` and all staging/prod environments.
- Clone-only execution additionally requires a matching source manifest digest, `VTA_ALLOW_P12_CLONE_APPLY=DISPOSABLE_CLONE_ONLY`, a separately stored readable backup file with expected SHA-256, and disabled external publishing/inbound integrations.
- The backup hash validates only file-byte integrity: it **does not prove that a backup can be restored**. Restoration rehearsal and human signoff are separate P10 conditions.
- Clone schema application delegates to reviewed `Migrations::run`, preserves its database advisory lock and migration checksum guard; P12 also validates planned state immediately before and after applying.
- CI on disposable MariaDB 11.4 applies actual core SQL 001–036, then all ten Marketing SQL migrations 023/024/025-outbound/037–043. It checks quoted cost schema unchanged, idempotence, required resulting tables and failure on altered hashes. No mocked DDL.

## Operational commands

Source manifest (no DB access):

```bash
php api/bin/marketing-schema-p12.php --manifest
```

Read-only inspection of approved staging **after code has been reviewed/installed into an isolated workspace**, not on production:

```bash
VTA_CONFIG_FILE=/path/outside/webroot/config.php \
  php api/bin/marketing-schema-p12.php --inspect
```

After creating a **disposable localhost test database** from an independently reviewed staging backup, with a private config for that local test database only:

```bash
VTA_CONFIG_FILE=/private/p12-clone-config.php \
VTA_ALLOW_P12_CLONE_APPLY=DISPOSABLE_CLONE_ONLY \
  php api/bin/marketing-schema-p12.php --apply-clone \
    --fingerprint=THE_SHA256_FROM_MANIFEST \
    --backup=/path/outside/webroot/backup.sql.gz \
    --backup-sha256=THE_BACKUP_FILE_SHA256
```

The code rejects staging/production credentials and refuses to write to any database other than a matching `vta_p12_clone_*` on local testing. Do not run a clone rehearsal inside the active CyberPanel webroot or with credentials that can access production.

## What remains before staging deployment

1. Inspect **real** staging migration ledger in read-only mode. Determine how current hosting handles 023/024/025 history, and whether any checksum-baseline differs.
2. Obtain and verify backup from real staging and conduct a **separate restore rehearsal**, with access-controlled logs and privacy restrictions.
3. Run P12 on a clone created from that backup, not only CI's fresh fixture schema. Compare quote/costing and user records before/after.
4. Design and review **the private Fast Staging release controller upgrade path**. Its `plan` currently rejects new SQL migrations, and cannot be updated by simply pushing this code. Preserve the private controller's existing safety and rollback guarantees.
5. Review promotion of P0–P12 to the approved testing branch, then run full unit, MariaDB integration and browser acceptance on a staging clone.
6. Do not enable Meta publishing or outbound workers during initial acceptance.
7. Only after explicit operator signoff may actual staging schema DDL be planned as a separate restricted migration job; no DDL on active staging was implemented here.

## Limitations
- CI uses an **empty MariaDB initialized by repository core migrations**, not a restored customer-data snapshot, therefore it cannot validate live schema drift, PII retention, stored credentials or real runtime compatibility.
- MariaDB DDL commits per statement. Changing application source or rolling back PHP **cannot reverse a partial schema upgrade**; a failed migration requires DBA review, not blind re-run.
- No real VPS connection, CyberPanel access, Meta OAuth, real customer messaging or social publishing was performed.
- No existing quote pricing, document, Operations or Smart Cost logic was altered.
