# CyberPanel staging install and upgrade — VTA v3

Target: **https://v2quote.vietnamtraveladvisor.com.vn only**. This package must not be copied to the production website or connected to its database, private files, credentials or cron jobs. The runtime and CLI refuse production configuration. No remote deployment was performed during development.

## 1. Prepare the staging environment

- Identify the website account, document root, PHP binary and database version actually assigned to the v2quote virtual host in CyberPanel. Use its matching PHP CLI for installation and migrations.
- Local verification used PHP 8.3.30 and MariaDB 11.4.5. Use PHP 8.2+ with PDO MySQL/JSON/session; enable the extensions needed by existing uploads/parsers and optional integrations (including mbstring, fileinfo, ZIP/cURL/OpenSSL where used). Other server versions need their own verification.
- Create a separate staging database and database account. For an upgrade, first clone/backup the existing **staging** database and files, including private configuration. Restore that backup into a disposable database to verify recovery before changing the staging copy.
- Compare the deployed schema and `schema_migrations` with the supplied baseline 001–006. Check existing changes, data types, foreign keys and current balances. Do not assume the live database is identical because a package has the same version label.

Recommended layout:

```text
/home/v2quote.vietnamtraveladvisor.com.vn/
  public_html/                 application files from this package
  vta_private/
    config.php                 secrets and environment settings
    documents/                 private uploaded files
```

Copy `api/config.example.php` to the private configuration location and set actual staging values. Keep `app.env=staging`, `app.base_url=https://v2quote.vietnamtraveladvisor.com.vn`, matching `security.allowed_origin`, and a distinct staging session name. Choose `storage.driver=local` for isolated staging tests and set its absolute private directory. Optional Drive credentials must also be private and staging-specific.

Give only the staging website/CLI account the required access (for example private directories 0750 and config 0640 with correct ownership). Do not make credentials world-readable. `api/config.php` fallback is no longer supported. Configure `VTA_CONFIG_FILE` in both the PHP web handler and CLI when using a non-default path; the default layout above needs no override.

## 2. Upgrade an existing staging database

Pause staging writes and its staging cron while migrating. Keep the old application and database backup together as a rollback unit. Place the new source in the reviewed staging application directory.

From that directory, using the correct staging PHP binary:

```sh
export VTA_CONFIG_FILE=/home/v2quote.vietnamtraveladvisor.com.vn/vta_private/config.php
php api/bin/migrate.php
```

Replace `php` with the actual selected binary when necessary. This applies missing migrations through 015; rerunning a successful migration is a no-op. It does not reset users or passwords.

The runner takes a database advisory lock, records SHA-256 hashes and status, and stops if an applied file changed or an earlier migration is RUNNING/FAILED. MySQL/MariaDB DDL can commit partially. On failure, inspect the specific migration and database, then restore or explicitly repair the staging copy. Do not delete ledger rows to force a retry. There is no automatic destructive down-migration.

First-time checksum baselining records hashes for old migration files; it is not a live-schema equivalence check. Apply the upgrade only after resolving staging drift. Old financial records without v3 evidence may require reconciliation before using the new actual-profit report.

## 3. Fresh staging installation

Use a new empty staging database and private config. The web setup endpoint is disabled. The CLI installer refuses any database that already has users.

```sh
export VTA_CONFIG_FILE=/home/v2quote.vietnamtraveladvisor.com.vn/vta_private/config.php
read -s VTA_ADMIN_PASSWORD
export VTA_ADMIN_PASSWORD
php api/bin/install.php --email=YOUR_STAGING_ADMIN_EMAIL --name="VTA Staging Admin"
unset VTA_ADMIN_PASSWORD
```

The password is entered interactively rather than saved in command history. The installer initializes migrations, company, roles and the first admin. For existing databases always use `migrate.php`.

## 4. Web server and private-file checks

- Serve PHP correctly with HTTPS on v2quote. Keep `display_errors` off and send errors to a private server log.
- Load the supplied `.htaccess` rewrite/deny rules in the staging virtual host. Verify the effective OpenLiteSpeed configuration; merely having the file on disk is insufficient.
- `/api/bin/`, `/api/lib/`, `/api/migrations/`, `/tests/`, `/docs/`, `/setup.php` and configuration files must not be publicly downloadable. CLI scripts independently reject web execution.
- Verify that `/vta_private/` is outside the web root and cannot be served. Application checks reject private config/storage paths that resolve inside the application directory.
- Visit `api/?route=health`, log in with the staging account and confirm the v3 version. Clear the old application cache/reload if upgrading a previously installed web app.
- Confirm anonymous API access fails, mutations without CSRF fail, and a restricted user cannot access cost/finance/approval routes outside their permissions.

## 5. Staging acceptance with synthetic records

Run the tests in V3_TESTING.md on an isolated local database first. On remote staging use dedicated synthetic records and review each step:

1. Campaign → embedded/public Form → Request with UTM/ad attribution → qualified Lead → Inquiry, selecting the intended customer/agent.
2. Create component, program version, daily itinerary, blueprints and hotel/market/agent variants. Publish and inspect Product Health for dates/pax. Copy into an empty draft quote.
3. Complete Info and Schedule. Save 3*/4*/5* costs/prices, including reviewed manual lines where appropriate. Confirm total guests versus paying/FOC and markup versus margin. Approve with rationale, issue and print the customer quotation.
4. Select the sent option and create master booking. Generate Supplier Order **DRAFT**. Review before the explicit request transition; record explicit service confirmation costs. Check AP starts with those confirmed costs.
5. Assign resources, inspect overlap/capacity/readiness, record and resolve an issue. Enter customer voucher details. Add guests/flights as applicable.
6. Create, review, ready and issue all required documents, including the full itinerary and Travel Pack. Confirm no internal rates/costs are visible. Change source details and verify the old document remains unchanged and readiness requests a new version.
7. Create payment schedule/invoices. Issue proforma and, when appropriate, its linked commercial document. Verify this does not double the receivable. Record one receipt and allocate across invoices; inspect statement and aging at two dates.
8. Reconcile supplier invoice variance, approve and record supplier payment. Confirm expected, forecast and actual profit differ as intended; final actual profit requires the documented completion/settlement conditions.
9. Re-login and verify persistence; create a quote revision and confirm it does not alter the accepted booking. Review shared queues, permissions and audit logs.

Send transitions are workflow records, not external email/WhatsApp delivery. HTML can be printed/saved to PDF by the reviewer; no server PDF renderer or messaging account is included. Do not tell a supplier/customer that a message was delivered solely because a state changed.

## 6. Recovery and cron

On a migration or acceptance failure, keep staging writes paused and restore the matched staging database/application backup. Record the failed migration and error for diagnosis. Do not point this candidate at production as a workaround.

Run `php api/bin/healthcheck.php` with the same private staging config. Enable only the staging cron after acceptance, using the verified PHP binary, staging config and a private log. Existing cron behavior should be reviewed against real staging data before scheduling; it must never reuse the production job/environment.

Remote schema comparison, final full browser acceptance and actual CyberPanel deployment checks remain required. See V3_AUDIT.md for limitations; successful local tests are not evidence that those remote checks occurred.