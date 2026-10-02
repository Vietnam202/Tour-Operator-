# V3 source/schema audit and implementation status

Audit basis: the user-confirmed local `VTA_Tour_Operator_OS_v2.4_Final_Core_Complete` package. This is a package audit, not an audit of a live server or database. Production and remote staging have not been accessed or changed.

The original application uses vanilla JavaScript and a PHP/PDO API. `CoreOS.php` supplies monolithic Sales/Booking/Operations/Finance routes; migrations 001–006 define the v2.4 schema. Existing module names and UI screens were not treated as evidence that the requested v3 rules were complete.

## Findings and implemented changes

| Priority | Baseline finding | Implemented v3 behavior |
|---|---|---|
| P0.1 | Installer mixed schema setup with admin provisioning; no migration checksum recovery guard. | Separate upgrade CLI; advisory lock, applied/failed ledger and SHA-256 drift detection. Fresh installer refuses existing users. Additive migrations 007–015. |
| P0.2 | Inquiry source existed, but no campaign/form/request/qualified-lead model. | Campaign/form creation, public capture, UTM/ad attribution, retry hashing, rate limiting, qualification and transactional conversion. Optional same-company customer/agent and market selection. |
| P0.3 | Quote itinerary existed without a versioned master product. | Programs, published versions, day itinerary, component snapshots, Service Blueprint, multiple hotel/market/agent variants, Product Health and copy into a draft quote. |
| P0.4 | Legacy matching omitted date-period policies and trusted submitted prices. | Approved-rate matching checks company, active supplier, pax bands, market, booking/travel windows, seasons, blackouts and special-quote scope. Ambiguous rates require review. Transport rules feed option costing, capacity uses total guests, supplements are explicit cost lines. Legacy rate-backed cost writes recalculate server-side. |
| P0.5 | Single quote workflow had approval/immutability gaps and unsafe arbitrary schedule projection. | Independent 3*/4*/5* option snapshots, markup/margin/manual pricing, approval fingerprints, immutable sent bundles, selected-option acceptance, customer-only HTML and revision copying. Legacy cost changes invalidate approval and sent writes are rejected. |
| P0.6 | Supplier confirmation and AP could diverge; order membership needed enforcement. | Booking/services copied from the accepted snapshot; orders start DRAFT; explicit request transition; order-member validation; explicit per-service confirmed costs; AP generated from those costs. Retries do not duplicate booking/orders/AP. |
| P0.7 | Resources were free-text contacts and issues were notes. | Guide/driver/vehicle resources, interval conflict checks, capacity readiness, audited assignment cancellation, Movement, structured incidents with root cause/resolution/lessons and current Travel Pack readiness. |
| P0.8 | Upload/download existed without a document issue lifecycle. | Full itinerary, hotel/transfer/activity/cruise vouchers and Guest Travel Pack. Draft/review/ready/issue/sent/superseded versions; customer-safe whitelist; stale-content detection; rooming/address/cabin/meeting details and flight projection; authenticated print-ready HTML. |
| P0.9 | Payments were tied to a single booking/invoice; reconciliation and historical statements were incomplete. | Payment schedules linked to invoices; immutable issued invoice snapshots; one receipt allocated across eligible same-party invoices; historical statement/aging; AP reconciliation with variance approval; supplier payments; expected/forecast/actual profit. Commercial document from proforma reuses the receivable and preserves old content and allocations. |
| P0.10 | Shared controls existed but needed domain permissions and connected queues. | Tasks/quote-review/supplier-review/incident queues, campaign conversion report, permission-filtered search, audit events and guards for task owners/entities. Existing reports remain alongside the new views. |

## Migration map

- 007: campaigns, forms, requests, attribution, qualified leads and capture throttling.
- 008: tour programs/versions/days/components/blueprints/variants and quote source snapshots.
- 009: quote options, approval fingerprints, sent bundles, acceptance and booking snapshots.
- 010: travel-document identities and immutable version payloads.
- 011: customer receipts/allocations, invoice snapshots, supplier reconciliation and payment retry keys.
- 012: operational resources, assignments and structured issues.
- 013: invoice-to-payment-schedule linkage.
- 014: whitelisted customer travel details per service.
- 015: commercial document linked to an existing proforma/payment-request receivable.

The runner also maintains `migration_checksums`. Migrations 001–006 were preserved byte-for-byte. First-time checksum baselining of an existing database does not prove that its deployed schema matches the original SQL; compare the real staging schema before upgrading.

## Verified business rules

Total Guests, Paying Pax and FOC remain distinct. Markup and target margin produce different correct prices. Every saved cost line preserves Pax × Qty × Unit Price; transport supplements use separate lines. Customer documents project public fields and never automatically include supplier prices, cost, margin, passport data or internal notes. Orders remain DRAFT until an explicit request transition. Quote/document issuance stores snapshots; changing source records requires a new document version. AP starts with confirmed cost and actual cost requires invoice reconciliation. Backend permissions, tenant ownership checks, CSRF and private-file path checks protect the new routes. Private config and files must be outside the application root; runtime and CLI reject production configuration.

## Limits and outstanding deployment checks

1. **Live staging comparison is outstanding.** No SSH/CyberPanel session, staging database dump or deployed-file inventory was available. Local upgrade tests use the supplied v2.4 schema; they cannot certify undocumented staging changes.
2. **Latest full browser walkthrough is outstanding.** Earlier browser checks verified login, inventory and Operations/Movement/Readiness. The browser runtime later failed with `registered Core setup has not completed`. Final JavaScript syntax, API-client behavior and HTTP business flows were verified separately. This is not a claim that every screen has passed a final browser acceptance review.
3. Quote/order/document send actions record explicit workflow state. They do not deliver external email/WhatsApp messages. No external messaging account has been configured or contacted. Documents are authenticated HTML for viewing/printing, not a server-side PDF renderer or public guest portal.
4. Automated conversion is intentionally limited to reviewed USD/VND snapshots. Tax-exclusive/unknown rates, ambiguous rate matches and unsupported combinations require a reviewed manual cost. Child/FOC contracting matrices and all exceptional supplier amendments/cancellations/refunds are not a comprehensive automated policy engine.
5. Commercial/proforma documents are not Vietnamese VAT e-invoices. Refund/credit-note/reversal workflows, bank-feed matching, general ledger accounting and government e-invoice integration are not implemented by this increment.
6. Old issued records without v3 snapshots and historical unallocated v2.4 receipts require reconciliation; the upgrade does not invent missing source evidence or silently reallocate old transactions. Confirm real historical balances before financial use.
7. Product Health currently matches the exact component name plus rate context; it is not a fuzzy service-mapping engine. Campaign forms use the implemented fixed capture fields, not a general landing-page/form designer.
8. No production deployment, load/concurrency stress test, remote mail/Drive integration test or OpenLiteSpeed configuration test has been performed. Database administrators can modify data directly; application immutability is not claimed as protection from privileged DBA changes.

This is a locally verified staging candidate, not certification that the entire broader DMC roadmap or every production scenario is finished.