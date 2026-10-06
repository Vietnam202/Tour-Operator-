# RC6.2 VS2.1 implementation report

Status: implemented for review. Production deployment, main merge and VS2.2 work were not performed.

## A. Source and architecture

Repository Vietnam202/Tour-Operator-, branch codex/Vietnam/rc6.2-vs21-approved, baseline 964d2681163701839ac4bd5007c86f153b05712e (codex/Vietnam/rc6.2-testing). The PHP/MariaDB VS1 architecture remains the application. Existing Inquiry → Trip → Quote/QuoteVersion → quote hotel parents → child pricing variants → existing sent bundle/acceptance → Booking/Services → existing Operations/Finance is preserved.

Seven implementation checkpoints already separate schema, quantities, fixed-point costing, templates/inclusions, variants/editor, validation/snapshots and UI. Checkpoint eight adds acceptance/regression tests, compatibility fixes and release documentation. No PR16/17 branch was merged wholesale. The only file copied from PR16 is tests/package-lock.json. All 117 candidate file decisions are recorded in VS2_1_PR_FILE_DISPOSITION.csv.

Implemented scope: explicit Quote Guest Profile; all eight quantity sources; typed shared requirements; deterministic Smart Costing; PRIVATE/SIC/HYBRID; independent hotel/cruise 3/4/5 presets and Custom Mix; exact supplier inclusions; trace; Simple/Advanced views; audited add/edit/remove/duplicate/reorder; one central first-send validator; existing snapshot, exact acceptance, booking and revision compatibility.

Supplier costs default to VND. AI does not supply rates. Vehicle/guide counts are explicit CUSTOM_QTY resources; guest counts validate transfer capacity. Standard formulas, approved stored rates and evidenced manual contracts use checked decimal arithmetic. Unresolved sources/units remain unresolved. FOC semantics are preserved. One schedule is shared by every pricing variant.

## B. Additive migrations

Exactly six child tables are added by 025_vs21_shared_requirements.sql, 026_vs21_rate_terms.sql, 027_vs21_variant_costs.sql and 028_vs21_acceptance_links.sql. Four existing columns and seven shared-day JSON members follow the approved field dictionary. All 22 historical migration files and the canonical specification have verified unchanged bytes. No backfill opts existing quotes into VS2, and no issued snapshot is rewritten. Historical hotel-parent uniqueness is retained. Candidate 023/024 history/schema stops for an approved adapter.

## C. Automated validation

All 42 combined regression suites passed again after the PR #18 review fixes: 20 JavaScript/UI suites (287 PASS groups) and 22 backend suites (1363 PASS groups). This includes the 19 original JavaScript suites and new VS2 UI checks. The 15 additional backend PASS groups are deterministic review-fix checks in `vs21-unit.php`.

| Additional evidence | PASS assertions/groups | Result |
| --- | ---: | --- |
| native | 84 | PASS |
| extended | 192 | PASS |
| coverage | 100 | PASS |
| migration | 9 | PASS |
| vs1 | 120 | PASS |
| installer | 4 | PASS |
| unit | 125 | PASS |
| ui | 5 | PASS |
| pwa | 5 | PASS |
| syntax | 1 (99 PHP files) | PASS |
| quote | 61 | PASS |
| vs0 | 76 | PASS |
| browser | 31 | PASS |
| review blocker integration | 94 (84 inherited native + 10 new) | PASS |

PHP 8.3.35, MariaDB 11.4.11, Node 24.19 and installed Chromium were exercised locally. PHP syntax checked 99 files. Fresh installation and 022-to-025–028 upgrades, no-op reruns, incompatible candidate history and partial-migration failure were tested on disposable databases. Dedicated VS1 handover/upgrade passed 120 assertions.

PR #18 review-fix evidence is in the workspace's local `outputs/VS2_1_REVIEW_JS_RESULTS.json`, `outputs/VS2_1_REVIEW_PHP_RESULTS.json`, `outputs/VS2_1_REVIEW_BLOCKERS.log`, `outputs/VS2_1_REVIEW_BROWSER.log`, and `outputs/VS2_1_REVIEW_VS1_UPGRADE.log` (sibling of the `work/` checkout, not tracked in Git). The blocker integration run includes the native suite plus deletion of a required HYBRID SIC line and invalid/valid hotel and guide date cases. The browser run uses the actual requirement and rate editors for a destination-specific approved rate, rejects a mismatched destination, tests cost-free privacy, and hides Send after SENT. These are local synthetic fixtures; staging verification remains required after merge.

VS2 native evidence includes every global quantity dependency against saved line bytes/hash/trace/timestamps, local CUSTOM_QTY, stable reviewed overrides, capacity/material-scope checks, six variants and independent cruise changes, exact package coverage, duplicate/cycle/partial/full-tour overlap blockers, signed adjustments, actual source/FX/terms/scope/policy/offer hash drift, immutable send retry and relational revision/reuse remapping. Parallel native writers prove one winning revision and idempotent concurrent initial Send, exact acceptance and booking.

A documented original USD100 contract at frozen FX25000 computes VND2500000 and books USD100. The existing supplier confirmation/AP, reconciliation, supplier payment, invoice/receipt/allocation and final actual-profit path passes. Unrepresentable original-currency adjustments and the existing confirmation bound block before Send.

All 52 approved release-gate IDs are mapped to executed evidence in VS2_1_TEST_COVERAGE.csv. Counts above are executed assertions/groups, including shared fixture/regression assertions; they are not counts of distinct business requirements or coverage percentages.

## D. Browser acceptance

31 real browser assertions passed with zero uncaught errors. The flow uses the existing Inquiry/Program Library/Quote workspace, explicit activation, presets, visible missing-rate validation, a real supplier line edit, real Approve/Send/Accept/Booking/Revision buttons and existing booking navigation. It creates or reuses an approved destination-specific Hanoi hotel rate, enters the destination through the actual requirement editor, prices and sends it, and confirms that changing the destination to Halong produces blocking `RATE_UNAVAILABLE`. Three distinct hotel nights are marked on the shared itinerary. Guest-only GET/PUT, monetary denial, confidential metadata/scope/itinerary-note redaction and preservation on cost-free edits, explicit profit DENY, missing CSRF and foreign-tenant access were tested against the local PHP server. Sent UI hides the Send action. Desktop and 390px mobile screenshots are included.

## E. API and schema documentation

VS2_1_API_SCHEMA.md documents every route, expected_revision, lock/transaction behavior, exact formula, ownership, engine selection, quantity binding, inclusion scope, variant and snapshot contract. VS2_1_SCHEMA_FIELDS.csv carries the approved per-table/per-column purpose, relationship, migration, compatibility, existing-data and snapshot impact.

## F. Migration notes

VS2_1_RELEASE_MIGRATION_ROLLBACK.md provides the ordered additive sequence, supported baseline, candidate-history preflight, checksum/partial-DDL handling and browser checklist. No destructive migration or production data operation was performed.

## G. Rollback notes

VTA_VS21_CREATION=0 prevents new explicit activation while retaining compatible readers and existing draft maintenance. A VS1-only code downgrade cannot interpret VS2 child variants. Retain this reader or use a verified matching application/database restore. Do not drop new tables or erase issued evidence. Production restore was not attempted.

## H. Release notes and compatibility fixes

VS1 remains the default engine. Legacy writers cannot contribute alongside VS2 costs. Sent/confirmed edits are rejected and accepted child IDs cannot be substituted. Public B2B/B2C output shares the underlying graph and excludes supplier/profit/internal notes. Original-currency services and handover notes reach existing booking and Finance.

Regression-driven fixes include binding stored-rate destination eligibility to the explicit service destination (mismatched or missing destination blocks a restricted rate), a Windows root-path normalization in the existing landing-page guard, preserving the legacy booking INSERT shape while adding VS2 notes, a standalone test dependency include, an explicit reviewed FOC fixture segment breakdown, and the current exact migration manifest in the existing VS1 test. Existing assertions remain active. The UI prevents overlapping saves, preserves variant/view on refresh and keeps booking navigation in Operations. The service-worker cache is advanced and includes the new assets; private API data stays uncached.

## I. Reuse/discard record

Approved classifications remain REUSE76, CHERRY-PICK1, REWORK28, DISCARD12 across PR16/17. VS2.2-only changes are deferred. Incompatible parallel quote/option/snapshot designs and candidate destructive/index-changing migrations were not imported. The complete file list includes actual disposition and the pinned candidate SHAs.

## J. Risks and review boundary

| Risk | Mitigation / practical limit |
| --- | --- |
| Supplier contract interpretation | No rates/units are invented or seeded. Actual contracts need approved formula, eligibility, stars, capacity and exact inclusion evidence. |
| Partial package/transfer overlap | Blocks until an explicit reviewed remaining scope/rate or split is provided; no automatic proration. |
| Historical/candidate migration state | Preserve 001–022/checksums and old JSON; stop candidate history/partial DDL; use an approved adapter. |
| Currency and legacy confirmation bound | Preserve original currency and frozen FX; reject negative/unrepresentable nets and amounts above existing confirmation limits before Send. |
| Code-only rollback | Keep the compatible reader or restore the matching database/application; no destructive down migration. |
| Release environment | Validation is synthetic/local. Production data, hosting, production backup/restore, physical-device PWA installation and deployment are outside this completed implementation. |
| Broader product phases | Full B2B/B2C document redesign and further Operations/accounting features remain VS2.2+; VS2.1 reuses existing flows. |

Review the source archive/commit patch, schema/API documentation, migration/rollback notes, 117-file disposition, 52-gate evidence matrix, logs and browser screenshots. Work stops before VS2.2.
