# VS2.3 regression evidence

Local verification: 45/45 regression suites passed (1755 PASS assertions). Includes VS1/VS2.1/VS2.2, navigation, Operations Center and new matrix/handover UI. New native commercial lifecycle, additive migration upgrade, 70-cell PRIVATE/SIC/custom matrix, B2C channel acceptance and ten scoped scenario checks passed. Local authenticated HTTP suite: 30 gates passed (CSRF, tenant isolation, ADMIN-only new permissions, Sent write protection, incorrect hash retry and cost-denied projections).

Native runtime: PHP 8.3.35, MariaDB 11.4.11 on a disposable localhost database. Migration test upgrades exact VS2.2 baseline (28 ledger entries) with 031–034, preserves historical row/hash evidence, checks nine empty tables, exact eight ADMIN-only grants, orphan FK rejection and NO_OP rerun. No production access.

Reproduce with tests/package.json: test (jsdom), test:vs23:native. Native scenarios require VTA_TEST_DB_PASSWORD and VS23_FIXTURE_FILE pointing to fixture23.json created by native suite, plus the existing fixture runtime/baseline variables described by VS2.1/VS2.2 test setup. Test rates are explicitly synthetic and never real supplier prices. Published evidence is under verification/VS2.3. No live config, credentials, SQL dump or customer data is published.

Limits: PHP parse/source package checks and staging validation are recorded in the deployment report separately. Sequential stale-write gates are evidence for VS2.3 CAS; do not describe them as a newly executed multi-worker matrix stress test. Live Google Drive authentication and real supplier integrations remain separate existing integration setup. Owner testing follows deployment; local results alone do not establish READY FOR USER TEST.
