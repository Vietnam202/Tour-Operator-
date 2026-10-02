# RC3 verification

From the application root, with Node 18+ and PHP 8.1+:

```bash
npm install --prefix tests
npm test --prefix tests
php tests/php-lint-all.php
php tests/ai-provider.php
php tests/runtime-guard.php
```

AI query fixtures require PDO SQLite. Tests use synthetic data and no provider calls. This release was checked with Node 24.19 and @php-wasm/cli 3.1.55 (PHP 8.5.10), because native PHP/MariaDB were unavailable. Recheck using the hosting runtime.

jsdom and mocked APIs verify DOM/request behavior, not CSS layout, device installation, credentials or network concurrency. MariaDB fixtures need an isolated test database; never use business data. `upgrade.php` creates a synthetic database on localhost port 33317 using `VTA_TEST_DB_PASSWORD` and expects migrations through 018.

Dependencies are for developer testing only. Do not upload `tests/node_modules` to hosting.
