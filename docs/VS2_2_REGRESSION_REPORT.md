# VS2.2 regression evidence

Local verification performed on PHP 8.3.35 and native MariaDB 11.4.11 with disposable databases and synthetic media. Staging uses its installed PHP/MariaDB and must pass the separate deployment smoke gate before READY is reported. No local test targets staging or production.

| Evidence | Result |
|---|---|
| Existing frontend/backend suite runner, including VS2.2 UI | 44/44 suites PASS; 1,751 PASS assertions |
| VS2.1 native coverage | PASS, existing approved L01–L52 gates retained |
| VS2.1 native edge/concurrency | PASS, one-booking acceptance race and existing operations/finance reconciliation |
| VS2.1 migration regression | PASS: isolated 001–028 release subset; four expected upgrades, historical bytes, no opt-in, no-op and partial/candidate rejection |
| VS2.2 native acceptance | PASS: real GD derivatives/private originals, approval/library, four templates, frozen media/Drive updates, permission projections, Send/Accept/Booking/revision |
| VS2.2 migration upgrade | PASS: exact 26-entry baseline → 029/030 only, six empty tables, 16 FK columns, old user/version/Sent bytes unchanged, NO_OP and orphan rejection |
| Independent HTTP API acceptance | 29 checks PASS using separate synthetic user sessions; CSRF, cost-denied projections/API denial, media permission denial, personal-owner isolation, public formats/bound images/revocation and existing Create Revision |
| Native output QA | Four template PDF/DOCX/HTML exports, all pages visually inspected; Vietnamese/3★/4★/5★ and multi-page oversized table; editable OOXML/ZIP parsed with repeated table headers; no private canary; legacy fallback has no PHP warnings; exports preserve issued checksums |
| Actual local browser | PC upload → day hero → Save → exact Draft preview; mobile preview tested; unreviewed selling amounts withheld |

The first broad run found a legacy SQLite revision regression: copying media for a non-VS2.2 version assumed the new table existed. The final code copies assignments only for configured VS2.2 sources. The failed suite and affected quote/options/schedule/VS2.1/UI/PWA suites were rerun successfully. Original failure evidence is retained alongside final results; this report does not relabel the first run as a pass.

The old VS2.1 migration test originally counted every future migration as a VS2.1 upgrade. Its final harness retains the original four-upgrade assertions against a temporary 001–028 release subset. The independent VS2.2 test still verifies the full head and its two additional migrations.

Drive transport is covered by a deterministic official-protocol fixture: allowlisted parent, selected file metadata, import-once/linked status and new-asset updates. Live Google authentication and a real organization folder are not claimed to have been tested. Word package validity/editability is verified by OOXML; no installed Microsoft Word visual comparison is claimed.

Local evidence is stored outside the source package: VS2_2_REGRESSION_FINAL_RESULTS.json, VS2_2_REGRESSION_FINAL_RERUN.log, VS2_2_RELEASE_NATIVE.log, VS2_2_MIGRATIONS.log, VS2_2_VS21_* logs, VS2_2_HTTP_RESULTS.json and VS2_2_OUTPUT_QA. Actual staging backup data/configuration/passwords are excluded from Git and the public release archive.
