# RC6.2 VS2.1 — Verification

Audit/implementation ngày 05/10/2026 trên testing baseline `964d268`. Spec/Issue #15 là nguồn yêu cầu; chưa merge hoặc deploy production trong báo cáo này.

| Verification | Result | Evidence |
|---|---|---|
| PHP syntax | 82 files parsed, PHP8.3.6 | `tests/php-lint-all.php` |
| Deterministic formulas | 33 assertions pass | `arithmetic-test.txt` |
| MariaDB upgrade022→023 | Only023 applied, rerun no-op, sent/version/option/bundle bytes unchanged | `test-vs2-migration-upgrade.txt` |
| VS2 DB integration | Guest/requirements, approved seasonal rate, expiration, overrides, presets, hybrid, copied review, immutable send/booking | `test-vs2-costing-db.txt` |
| Regression DB/SQL | 13 suites pass including VS2 suites; mixed MariaDB and explicit SQLite fixtures | `db-results.json` and test logs |
| VS1 handover regression | 113 assertions; historical migrations001–021 compared to baseline | `test-vs1-handover.txt` |
| Legacy quote/reuse/finance/operations | Pass; issued snapshots and public financial redaction covered | `test-quote-options.txt`, `test-rc1*.txt`, finance/operations logs |
| JavaScript/DOM | Full npm test including new costing UI suite passes | `js-tests.txt` |
| Real browser / HTTP | 10 acceptance checks pass, Chromium153 | `browser-results.json`, `browser-test.txt` |
| Desktop/mobile visual | 1440×1100 + 390×844; no outer page overflow on mobile | `smart-costing-desktop.png`, `smart-costing-mobile.png` |
| Security | Real CSRF419, cost-read403; cross-company quote/supplier/rate tests | Browser + DB logs |

Browser steps executed: login → create inquiry/quote → save one itinerary → PRIVATE defaults → remove inapplicable cruise and set hotel nights → persist requirements → generate template → manual supplier VND inputs → calculate/save → Simple/Advanced view → mobile layout → CSRF/permission rejection → change guests → approve/send → reject mutation of sent quote → create revision and verify variants/requirements retained.

Fixture notes: RC1 reuse target explicitly sets reviewed guest segments because VS1 does not infer adult counts from intake totals. SQLite quote fixtures include new metadata/profile/requirements schema. PWA activation assertion now checks that VS1.1 cache is removed when VS2.1 activates. These are fixture updates; production guest/snapshot guard behavior is retained.

Limits: local synthetic suppliers/rates, not contract validity or real supplier availability. Provider email/Drive/OAuth/PDF-Word template/pax-band/policy integrations belong to later increments and were not exercised. Static preview disables VS2 arithmetic; the browser result uses the actual PHP/MariaDB API. Confirmed/Actual/Variance in quote view remain unavailable until Operations records those stages.
