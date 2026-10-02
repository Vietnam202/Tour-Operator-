# Published-inventory booking reliability

## Customer flow

Cruise listing filters now use published database inventory. Listing and detail pages do not call their own HTTP API or substitute demo inventory during a database outage. The historical Stellar URL uses the shared dynamic detail template. Unknown and unpublished cruise slugs are not bookable.

Checkout requires a published future departure and an active cabin from the same cruise. The departure selector includes its date and duration, so an edited free-form date cannot conflict with a hidden departure ID. Invalid selections must be chosen again rather than silently replaced.

Quotes are invalidated on every cabin, departure, guest or transfer change. Superseded requests are aborted. Only a quote matching the current selection can enable submission. The receipt uses the server response, not a browser-generated reference or total.

## Public data boundary

`lib/public-cruises.ts` explicitly selects customer-facing fields. Supplier IDs, contract net costs and internal metadata are not returned by the list, detail or booking-context API. `lib/public-quote.ts` explicitly selects public price rows and totals; commercial costs and margins stay server-side. Database exceptions are not echoed to customers.

The booking API requires contact consent, recomputes pricing, ignores client-supplied totals and names, and stores the enquiry and consent activity in one transaction. This does not reserve a cabin or confirm a booking. Existing operations confirmation remains a separate workflow.

## Pricing limitations

The current schema still has departure-level starting prices rather than an explicit cabin-by-departure fare table. The legacy child percentages and transfer estimates remain indicative, not verified supplier contracts. Multi-cabin groups and currency mismatches require manual review. Unknown contract cost is stored as null, never assumed to be zero; finance reporting must preserve this distinction when interpreting older snapshots. This release does not validate historic profit reports or enable real payments.

## Verification

CI now provisions a disposable PostgreSQL database, applies all committed migrations, compares the migrated schema with Prisma, runs TypeScript and the production build, starts the production HTTP server, and executes `npm run test:integration`. The suite uses synthetic cruises and guests, checks both public API responses and persisted records, and deletes only its own fixtures. It includes selection mismatch, sold-out/past inventory, oversized parties, malformed input, tampered totals, consent, missing contract costs and private-field leakage.

Tests require `HCA_INTEGRATION_TESTS=1`, a local database named `hca_ci`, and a local HTTP server. Do not point tests at staging/customer databases. These are HTTP/database integration tests; they are not browser interaction or payment-provider tests.

## Remaining release gates

A committed dependency lockfile, verified operator content and fares, audited payment-provider integration, secure customer voucher authorization, concurrency testing for staff inventory confirmation, distributed rate limiting and a real staging deployment remain separate release gates. Passing this test suite is not evidence that those gates have been completed.
