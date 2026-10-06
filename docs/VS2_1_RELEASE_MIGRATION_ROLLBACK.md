# RC6.2 VS2.1 release, migration and rollback notes

This is a reviewable implementation from testing baseline 964d2681163701839ac4bd5007c86f153b05712e. Production deployment, production hosting changes, main merge and VS2.2 work are excluded. The existing VS1 stack is retained.

## Release behavior

Explicit Smart Itinerary & Costing opt-in enables guest service populations, typed requirements, deterministic VND-first pricing, PRIVATE/SIC/HYBRID, independent hotel/cruise star variants, exact supplier inclusion suppression, trace, Simple/Advanced views and audited draft line editing. Existing quote approval/send/accept/booking/revision machinery remains central. Old versions default to VS1. FOC semantics are unchanged. Program Library is selected before explicit activation; legacy program writers cannot overwrite an activated graph.

Supplier terms must be interpreted and approved against actual contract evidence. No production rate or tour data is seeded by migrations. Generation intentionally leaves supplier rates and service units unresolved until a user selects a source and reviews scope. Confirmation/AP/reconciliation/payments continue through existing Operations and Finance. Advanced quote cost view labels those later stages as after-booking; it does not invent confirmed or actual amounts on a draft.

## Additive migration sequence

1. Take a consistent database backup and retain the matching currently deployed application package. Record the source revision and migration/checksum manifest. These are deployment instructions only; no production action was performed for this change.
2. Rehearse on a disposable restored copy. Confirm the supported 001–022 baseline, historical quote_options unique hotel index and absence of candidate 023/024 schema/history.
3. Run the existing migration command with the full approved manifest. 025 adds shared requirements/profiles and version engine/revision columns; 026 adds existing-rate terms/inclusions; 027 adds child variants/cost lines; 028 adds nullable acceptance/booking variant links.
4. Verify six child tables, four old-table additions, historical snapshot fingerprints, preserved hotel-parent uniqueness and no implicit VS2 activation/backfill.
5. Keep compatible VS2 readers and the four migrations together. Validate VS1 and new draft flows with the documented automated suites and browser checklist before any release approval.

The migration runner preserves its database migration lock/checksum ledger. Reruns are no-ops. Existing 001–022 files remain unchanged. Preserve the deployed file bytes, including line endings: Git checkout conversion can change a byte checksum even when SQL text is equivalent. Carry the deployed historical files forward unchanged alongside the four new files; do not normalize their line endings during release. Candidate 023/024 installations, candidate quote_options.costing_mode or a changed historical unique index cause a preflight stop. A failed/running/checksum-mismatched migration stops automatic retry. MariaDB DDL can auto-commit: preserve evidence and repair through an approved adapter; do not blindly retry or drop tables. Candidate branches are not merged wholesale and have no automatic destructive conversion path.

There is no data backfill into VS2. Old legacy costs/options remain retained and inactive only after explicit activation. Historical sent/accepted JSON/hash bytes are untouched. Version activation cannot occur on issued data. New variants use the existing hotel-parent index and separate child IDs.

The PR #18 review fixes use existing `quote_service_requirements.scope_json.destination` and `quote_versions.schedule_json.overnight_type`; no SQL migration or historical data rewrite is needed. Existing draft days without a typed overnight remain unreviewed for hotel-night pricing until a user classifies them in the shared itinerary. Existing Sent/Accepted snapshot bytes remain frozen. A destination-bound rate now has an explicit editable service destination and visible rate eligibility context. Cost-denied context omits confidential requirement metadata, while edits retain those saved fields.

## Rollback

Set VTA_VS21_CREATION=0 to stop explicit VS1-to-VS2 activation while retaining compatible readers for existing drafts, variants and snapshots. Existing VS2 draft maintenance/revision remains available. If all mutations must stop, use maintenance access control for the affected environment; do not uninstall the reader while VS2 data exists.

A code-only downgrade to the original VS1 release is unsafe once VS2 parents/snapshots exist: it cannot interpret the child variants. Retain this reader or restore a verified matching application and pre-VS2 database backup as one approved operation. Rehearse the matching restore offline. Do not drop new tables/columns or erase issued commercial evidence as a rollback procedure. No destructive down migration is supplied.

## Browser acceptance checklist

Use disposable local data and a real browser; never production bookings.

- Sign in; create an existing inquiry/quote; select Program Library; verify one shared itinerary.
- Activate Smart Costing; review explicit service populations and stable day/requirement scope.
- Mark each hotel, cruise, other or no-overnight day in the one shared itinerary; verify hotel nights and guide days use distinct eligible itinerary dates. Enter the service destination explicitly for any destination-bound approved rate, confirm its eligibility in the picker, and verify a wrong destination blocks Send.
- Offer a HYBRID variant, then remove its required SIC tour line on a disposable draft; verify `MISSING_REQUIRED_SERVICE` blocks Send. Restore the line before proceeding. Check that cost-denied users cannot read internal supplier notes and that Sent quotes have no active Send action.
- Generate PRIVATE/SIC presets, retain a Custom Mix, and confirm repeated generation keeps edits/IDs.
- Change HOTEL_PAX/VISA_PAX/MEAL_PAX independently; inspect trace and review flags. Override quantities remain stable. Transfer guest changes validate capacity and scope.
- Offer a draft variant with missing sources; Check Quote and first Send block. Enter/select documented supplier sources and review dates/units/contract basis.
- Confirm exact SIC components are included at zero while unrelated services remain priced; incomplete coverage or full-tour overlap blocks.
- Switch Simple/Advanced view; add/edit/duplicate/reorder/remove draft lines; derived totals are server-controlled. Preserve selected variant/view after refresh.
- Approve, Send, reject issued edits, accept exact option+variant+hash, create existing booking once, and create an existing revision with remapped children.
- Test guest-only editing, cost/profit DENY, foreign tenant IDs, missing/bad CSRF, stale revisions and shared booking/order projections.
- Check desktop and 390px mobile layout, zero uncaught browser errors, public output privacy and original-currency handover.

Automated local results and screenshots are delivered with the implementation report. Physical device PWA installation and production backup/restore/deployment are not claimed.

## Test commands

From tests/: npm ci; npm test; php vs21-unit.php; php vs21-edge.php; php vs21-coverage.php; php vs21-migrations.php. Set VTA_TEST_DB_PASSWORD for an isolated MariaDB at 127.0.0.1:33317; fixtures create uniquely named databases and never reset an existing database. vs21-worker.php is a subprocess helper, not a standalone suite. Use php vs1-handover.php with two empty named vta_vs1_<hex> databases, password and a verified 001–021 baseline directory. Run php install-cli.php on the disposable server.

For browser acceptance supply VS21_TEST_URL (loopback only), VS21_BROWSER_PASSWORD, VS21_BROWSER_OUTPUT and optionally VS21_CHROME for an installed Chromium executable. The fixture must expose synthetic users, Program Library and documented sample rates; use the API or a disposable fixture seed. No credential or runtime configuration is shipped in the source archive.
