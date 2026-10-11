# RC6.2 — End-to-End Workflow Continuity Acceptance

Date: 2026-10-11. **Candidate base:** PR #52 at `157261336e6b999a33bded252ad92144caee05d2`. This document belongs to a **separate Draft test PR**, not an approved RC1 deployment.

## 1. Fixed business chain

Website / B2B / internal intake → Lead → qualified Customer & Inquiry → Tour Profile → document-style Itinerary / Master Tour Library → supplier-reviewed Smart Cost in VND → commercial Price → approved immutable Proposal (DOCX/PDF) → confirmed Booking → Sales handover → Operations → Finance.

The same company, customer, inquiry, trip, quote, sent version, accepted option and booking must be traceable without duplicating records, changing customer/date/PAX/FOC, overwriting the master template or altering a previously sent snapshot. The system must **not** invent supplier availability, NET prices, FX, confirmations, invoices or collected payments.

## 2. Automated evidence and limitations

Existing `Unified VTA Workspace QA` runs PHP 8.3, disposable MariaDB and browser fixture checks. The additive `tests/rc62-golden-path-continuity-native.php` calls the existing `tour-program-workspace-native.php` once and verifies in the **same disposable database**:

- Booking joins exactly to the owning company, Quote, Trip, Inquiry and confirmed Quote Version.
- Paying PAX, FOC and Total Guests persist from the confirmed version into Booking.
- SENT bundle / acceptance / Booking snapshot IDs and SHA content hashes match.
- Booking selling total matches the accepted option's snapshot total; Booking service row count matches the accepted snapshot's lines.
- Retrying booking does **not** create a duplicate and does **not** alter issued/booking snapshots.
- The inherited Tour Program native checks cover template copy, editing, save/reopen, image permissions, idempotent Save-as-Template and export/reimport of a DOCX plus PDF creation.

**Only claim automated PASS after this PR's exact-HEAD CI runs successfully.** This is fixture continuity, **not** production/staging, real-user login, external provider or supplier evidence. It does not cover Website→CRM or Operations→Finance end to end; those still require authentic staging UAT.

## 3. Mandatory authentic staging scenarios

| ID | Flow / scenario | Pass evidence required | Owner ticket |
|---|---|---|---|
| E2E-01 | WordPress signed Website Lead / Chat → Lead Hub → qualified Customer + Inquiry (and internal Sales entry) | Valid/invalid HMAC, source & company, no duplicate lead after retry, customer & inquiry IDs, correct staff access, WordPress staging separate from RC6 | PR #49, VIE-9/10 |
| E2E-02 | Inquiry → Tour Profile → Word Itinerary → Use Template → edit/undo/save/reload → Save-as-Template | Identity, dates, PAX/FOC, FX and cost unchanged; Master Tour immutable; failed save retry and read-only denied | VIE-7 |
| E2E-03 | Supplier approved VND rates → hotel/cruise variants → Smart Cost / Price | Hotel 3★ + Cruise 5★, 6 paying + 1 FOC, 3★/4★/5★ independent, Private/SIC, no fake price/availability, rate evidence & server totals | VIE-8 |
| E2E-04 | Approved Price → Proposal → DOCX/PDF → SEND / Quick Send → guest acceptance | Customer-safe content, no NET/profit, exact approved version, expiry/revoke and correct file/image rendering | VIE-7/8/9, PR #51 |
| E2E-05 | Accepted Quote → Booking → Sales handover → Operations acceptance | Exactly one booking, immutable sent/accepted snapshots, services and pax reconciled, booking stays unconfirmed until required review/availability, role audit | VIE-9 |
| E2E-06 | Operations services → supplier confirmation → invoice/AR/AP → Finance / Reports | Exact booking linkage, no invented supplier confirmation or payment, rights and totals reconcile, immutable records | VIE-9 |
| E2E-07 | Partner A vs B, COMMON / EXCLUSIVE, withdrawn copies and direct API access | Real authenticated sessions and 403/404 for forbidden resources, no NET or other-company PII in search/export, existing snapshots preserved | VIE-12 |
| E2E-08 | PC/mobile/PWA and recovery | 1440/1024/390 and real device, refresh/navigation/offline cache, no stale content, backups and rollback | VIE-6/10/11 |

## 4. Safe order and release gate

1. **VIE-5**: Fix one candidate SHA and record integrate/defer decisions for sibling PR #21/#43/#47/#50; do not hide missing features under CI PASS.
2. **VIE-6**: Isolated staging host and clone MariaDB; verify private config, manifest/ledger, encrypted backup→restore, core-first migration rehearsal, read-only preflight. Never reset/write the production database or bypass CyberPanel branch pin.
3. Run E2E-01 to E2E-08 with authenticated Admin/Sales/Operations/Finance/Read-only, agency A/B and real browser devices. Record input, output ID, expected/actual, screenshots/redacted network/SQL evidence, operator, date and exact SHA per scenario.
4. **VIE-11**: Owner signs GO/NO-GO only after critical issues resolved, all release-scope scenarios PASS or explicitly deferred with owner-approved scope, rollback route proven and a separate production change authorized.

Status vocabulary: `PASS_CI_FIXTURE`, `PASS_STAGING_REAL_AUTH`, `FAIL`, `BLOCKED_ENV`, `NOT_TESTED`, `OUT_OF_SCOPE_APPROVED`. **Never label a CI fixture as `PASS_STAGING_REAL_AUTH`.**

## 5. Evidence register — blank until operator testing

| Scenario | Exact runtime SHA | Staging environment/role | Actual evidence link | Status | Approver |
|---|---|---|---|---|---|
| E2E-01 through E2E-08 | To be recorded | To be recorded | To be recorded | NOT_TESTED | Pending |

**Current release decision: NO-GO for CyberPanel production.** PR #52 remains Draft and is not merged or deployed by this test branch.
