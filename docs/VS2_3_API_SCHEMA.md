# RC6.2 VS2.3 API and architecture

Extends existing inquiries → trips → quotes → quote_versions → quote_acceptances → bookings. No second quote, itinerary, costing, revision, procurement or payment system. PriceMatrix is a child projection of a quote version; SmartCosting::scenario calls the existing supplier resolver, formulas and scoped inclusions without writing source cost lines. Existing Vs2Snapshots and booking_quote_snapshots remain authoritative.

## Additive tables

| Migration | Tables | Parent / purpose |
|---|---|---|
|031_vs23_commercial_policies|commercial_policies|Company append-only policy versions; references existing users|
|032_vs23_price_matrices|quote_price_matrices, quote_price_matrix_cells, commercial_overrides|Existing quote version/variant; generation cells, frozen policy/FX/scenario graph, exact-context overrides|
|033_vs23_commercial_acceptance|quote_commercial_acceptances|PK/FK to existing quote_acceptances; composite FKs constrain matrix, version and cell; exact guest/recheck/commitment snapshot|
|034_vs23_sales_operations_handover|booking_handovers, booking_handover_services, booking_handover_events, booking_change_requests|Existing booking_quote_snapshots / bookings / service requirements / services / users|

Nine empty child tables at upgrade. No historical migrations or rows rewritten. Existing versions without matrices use the legacy paths. Sent/Accepted public and internal bundles remain byte-for-byte immutable; new revisions use the existing mechanism.

## Routes

Existing auth/session, tenant projection, CSRF and QuoteVs2 mutation locks apply. Writes require expected_revision from the corresponding parent response.

| Method | Route | Notes |
|---|---|---|
|GET/POST|commercial-policies|Read requires sales.view + quote.view_cost; POST commercial.policy_manage; append only|
|GET|quote-versions/{id}/price-matrix|Cost-denied users receive locked selling data only; no scenario/input/rate/policy data|
|POST|quote-versions/{id}/price-matrix/generate|quote.edit + quote.view_cost; CAS; DRAFT only|
|POST|quote-versions/{id}/price-matrix/validate, /lock, /unlock|Lock/unlock also commercial.matrix_lock; unlock requires reason|
|POST|quote-versions/{id}/price-matrix/select|cell_id, is_selected, is_recommended; DRAFT only|
|POST|quote-versions/{id}/price-matrix/override|commercial.margin_override; exact cell_id/context_hash and reason; optional paying_pax/guest_profile for exact recheck|
|POST|quote-versions/{id}/price-matrix/recheck|Sent offered cell_id + paying_pax + optional confirmed guest_profile; cost permission required|
|POST|quotes/{id}/confirm|Existing acceptance: option_id, variant_id, sent_content_hash, expected_revision plus cell_id/paying_pax/guest_profile when matrix exists|
|GET/POST|bookings/{id}/handover|Existing booking.view; POST create requires sales.handover_create|
|POST|bookings/{id}/handover/prepare, /submit, /accept, /return|CAS on handover revision; corresponding new permission; operations actions also operations.view; return requires reason|
|GET|operations/intake?view=...|operations.view; NEW_HANDOVERS, NEEDS_REVIEW, READY_TO_BOOK, CHANGES_ISSUES, UPCOMING_DEPARTURES; company-scoped|
|POST/PUT|bookings/{id}/change-requests[/{change_id}]|booking.change_request_manage; revision and reason for transition; status-only foundation|

Human-approved rollout: all eight new permissions are seeded to ADMIN only. No new grants to Sales/Operations/Finance/Product. Owner uses ADMIN for full workflow during user test.

## Calculation and freezing

Inclusive bands default 2, 3–4, 5–9, 10–14, 15–20. SAFE_PRICE evaluates every paying pax and chooses maximum required net selling/pax. REPRESENTATIVE_PAX evaluates the explicit representative; EXACT_PAX accepts singleton bands. Guest scenarios preserve FOC, children and infants unless an explicit confirmed composition is provided; FOC cannot change. Service population rules: FOLLOW_BASE_OFFSET, PAYING_PAX, TOTAL_GUESTS, FIXED. Explicit vehicle/guide counts require reviewed rate capacity. Stable manual overrides require recorded review. No rate invention, auto capacity substitution, partial inclusion proration or automatic margin uplift.

Existing quantity sources and formulas remain intact: TOTAL_GUESTS, PAYING_PAX, HOTEL_PAX, CRUISE_PAX, VISA_PAX, MEAL_PAX, TICKET_PAX, CUSTOM_QTY. Hotel/cruise 3/4/5 and Custom Mix reuse one itinerary. VND source cost is preserved; supported selling currencies USD/VND. B2B markup, target margin, manual net and commission are distinct; commission margin uses net payable. B2C has no agent commission. Deposit supports 0–100%. FX source/value/time/pair, policy version, parties, terms and scenarios freeze at lock/Send. Central VS2 validator blocks stale graph/rates, expiry, incomplete scenarios and unapproved below-policy cells. Exact acceptance reruns frozen graph with approved rate references and fixed agreed prices; below-policy exact margin needs its own contextual override.

Matrix states: DRAFT → VALIDATED → LOCKED → SENT. Handover: COMMERCIAL_CONFIRMED → HANDOVER_PREPARATION → HANDED_TO_OPERATIONS. Intake return records reason and requires resubmission. Supplier booking is not a prerequisite. Change states OPEN → UNDER_REVIEW → APPROVED/REJECTED → IMPLEMENTED record audit only; they never amend confirmed snapshot or create payments/supplier confirmations.
