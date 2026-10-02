# V3 reproducible verification

## Environment and boundaries

Tests use synthetic data on PHP 8.3.30 / MariaDB 11.4.5. The database server is explicitly `127.0.0.1:33317`; each PHP integration run creates a random `vta_test_*`, `vta_upgrade_*` or `vta_install_*` database. Use an isolated disposable server/account with CREATE DATABASE permission. Never forward this port to production. Test databases are retained for inspection, not automatically deleted.

Set `VTA_TEST_DB_PASSWORD` to the password of that disposable local root account. Test credentials are not staging/production credentials. Test configuration is written outside the application directory. Runtime binaries, private config, sessions, database files and uploaded files are not part of the source ZIP.

## PHP and JavaScript checks

With PDO MySQL enabled in the selected PHP runtime:

```sh
php tests/documents-extended.php
php tests/legacy-costs.php
php tests/upgrade.php
php tests/runtime-guard.php
php tests/install-cli.php
node tests/frontend.cjs
```

`documents-extended.php` includes the full domain chain: lead-hub → quote-options → procurement-documents → finance-flow → operations, then checks detailed vouchers/flights/customer quote HTML. Run it once; the lower-level files can also be run individually for diagnosis. Some fixtures deliberately corrupt a migration checksum to verify drift rejection; those disposable databases are not deployment candidates.

Run PHP syntax checks on every `.php` file. On Linux, from the application root:

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

`frontend.cjs` parses app/request/embed/service-worker code, verifies route/query separation, JSON/CSRF mutation behavior and propagation of API errors. It is not a replacement for a browser walkthrough.

## HTTP end-to-end smoke test

Use a separate synthetic HTTP fixture:

```sh
php tests/http/setup.php
```

This creates a random `vta_http_*` database and writes `../runtime/http-config.php` relative to the application root. Start the PHP development server on `127.0.0.1:8873` with `VTA_CONFIG_FILE` set to that absolute private configuration path and the document root set to this application directory. The fixture includes synthetic `admin@example.invalid` and `viewer@example.invalid` accounts with password `Local-Http-Test-2026`. These are test-only accounts and must never be installed into the operational staging database.

Run using PowerShell 7 (needed for `SkipHttpErrorCheck`):

```powershell
pwsh -File tests/http/extended-http.ps1
```

The scripts are deliberately fixed to loopback ports and synthetic accounts. They are not remote deployment or production smoke scripts. Do not change their target to a live customer system.

The HTTP chain covers public capture/qualification/inquiry, published program copy, three hotel options, approved immutable quote, selected booking, DRAFT/requested/confirmed supplier order, six issued document kinds, proforma/linked commercial documents, receipt allocation, historical statement, reconciled AP/payment and finality of profit. Extended checks cover restricted-user denial, permission-filtered search, campaign attribution, global AR, immutable cost guards, resource assignment/cancellation/reassignment, incident resolution, movement filters, quote revisions, customer HTML and stale Travel Pack readiness.

## What passed and what was not tested

See the delivered `verification/` logs for exact assertions and environment-dependent failures. Fresh install and legacy-schema upgrade are both exercised; installer rerun must refuse account reset. Database/quote/document/receipt retries and invalid-state/tenant/permission cases are covered.

Earlier native-browser checks covered login, inventory creation and Operations/Movement/Readiness against the local API. The final browser session was blocked by the tool runtime error `registered Core setup has not completed`. No claim is made that every latest UI screen passed a final visual walkthrough. PHP/API/client tests continued through approved local command execution.

No remote staging/production database, CyberPanel/OpenLiteSpeed runtime, external message delivery, external Drive service, real bank reconciliation, load test or full concurrency stress test was exercised. The ZIP is a locally verified staging candidate, with these explicit limits.