# VS2.3 / VS2.4 owner test checkpoint

P0 base: `6ef32cf05cfce7c8075bfa7b2c5f73d1eb6e50ab`.
VS2.3 checkpoint: `7a2b5f5a1067f768cc4e2209477ad60a96b4e691`.

## Implemented scope

- Existing inquiry, quote/version, issued snapshot, booking and handover remain the source of truth.
- ADMIN, SALES and OPERATIONS may confirm issued quotations with existing `quote.confirm`; explicit user DENY remains authoritative. MARKETING receives no confirmation grant. Other role permissions stay unchanged. The previously approved ADMIN-only commercial/handover permissions remain unchanged.
- Confirmation uses frozen public options or a frozen price-matrix cell and eligible exact paying pax. Booking retains the quotation reference, selected category, itinerary, selling price and accepted snapshot.
- A booking offers the existing reviewed Sales handover and Operations intake workflow. Service information is reused without re-entry.
- The service editor saves assignment, status, dates/times, hotel details, transport/driver details, guide language/contact and dispatch instructions in existing booking services/travel details. No new Operations table is introduced.
- Service saves use a booking lock and expected service hash. Closed/cancelled/completed bookings are read-only. Ordered services use the existing supplier order workflow for supplier/status/quantity changes. Cost fields cannot be edited through this endpoint.
- Public voucher details exclude internal dispatch notes. Quote and accepted booking snapshots remain immutable.

## Database and release

- Reused existing additive migrations 031–034 from the previously tested VS2.3/P0 composition; historical migrations were not edited.
- New `035_quote_confirmation_roles.sql` only inserts the approved role-permission links. It changes no schema, prices, customer records or historical snapshots.
- No staging/production deployment or real database migration was performed for this checkpoint. Database tests used disposable localhost databases only.
- Updated asset/cache versions include the new confirmation and service editor scripts.

## Verification

- 26 UI regression suites passed in the final round; outdated shell/card fixture assumptions were corrected and only affected/remaining suites rerun.
- Native Quote → Confirm → Booking → Prepare/Submit/Accept Handover passed, including ADMIN/SALES/OPERATIONS, denied Marketing, explicit user DENY and unchanged historical snapshots.
- Existing native VS2.3 commercial/matrix acceptance passed. Additive upgrade, historical-row preservation, ADMIN-only handover grants and migration rerun passed.
- Native service persistence, stale-write rejection, tenant isolation, supplier validation, ordered-service guards, financial close, public document projection, unchanged financial values and immutable snapshots passed.
- P0 itinerary persistence, independent formatted duplicate, hotel/cruise costing, HTML/DOCX and searchable PDF passed. The PDF recheck used the existing Poppler executable in the test process PATH; no product change was needed.
- All 23 changed PHP files and 13 changed JavaScript/test files parsed successfully; later quote-read changes were checked again.
- Real localhost browser flow: OPERATIONS confirms an issued 4-star choice, creates booking; ADMIN prepares/submits/accepts handover; hotel/transport/guide are saved; OPERATIONS edits notes; Refresh/Reopen preserves assignments, references and details. Voucher and issued proposal Preview open. No captured JavaScript errors.
- Authenticated localhost Operations quote/service responses were checked for absence of supplier costs/profit; service dispatch identity remains available through the safe service endpoint.

## Owner sequence

Open an issued quotation → choose the customer-confirmed option → Confirm → Create/Open Booking → check copied details → Handover to Operations → review and submit → accept intake → Services → edit Hotel/Transport/Guide → Save → Refresh → reopen and verify → Voucher → open source quotation → Preview.

Use existing authorized roles for handover acceptance. Confirmation permission does not grant quote editing, cost viewing, approval, payment or handover acceptance permissions.

Status: READY FOR OWNER TEST of the local checkpoint. Staging has not been updated by this work.
