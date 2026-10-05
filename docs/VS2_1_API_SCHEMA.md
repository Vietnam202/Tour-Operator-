# RC6.2 VS2.1 API and schema

VS2.1 extends the RC6.2 VS1 PHP/MariaDB application. Inquiry, trip, quote, quote_version, quote_option hotel parents, booking, suppliers, rates, quote_sent_bundles, quote_acceptances, booking_quote_snapshots, audit, Operations and Finance remain the existing masters. There is one shared schedule per quote_version. The canonical product authority remains RC6_VS2_FUNCTIONAL_SPEC_VI.md and the approved implementation decision pack for Issue #15.

## Storage

Migrations 025–028 add exactly six child tables. No 001–022 migration or historical snapshot is rewritten. Field types, purpose, ownership, migration, compatibility and snapshot effects are documented individually in [VS2_1_SCHEMA_FIELDS.csv](VS2_1_SCHEMA_FIELDS.csv).

| Table | Owner / purpose |
| --- | --- |
| quote_guest_profiles | One profile per quote_version; five explicit nullable service populations and human review metadata |
| quote_service_requirements | Shared typed commitments, stable day keys, dates, units, scope, PRIVATE/SIC/BOTH applicability and package relationships |
| rate_version_vs2_terms | Existing rate_version interpretation: formula, stars, capacity, eligibility source, approved package scope and evidence |
| rate_version_inclusions | Exact supplier-approved component scopes and populations; same rate_version |
| quote_option_variants | Child of existing unique hotel parent; independent cruise star, PRIVATE/SIC/HYBRID, offered flag and pricing cache |
| quote_variant_cost_lines | Inputs, named quantity binding/override, supplier/rate/manual contract, exact inclusion linkage, signed parent adjustment, server calculation and review trace |

Existing quote_versions gains costing_engine (default VS1) and costing_revision (default 0). Existing quote_acceptances and booking_quote_snapshots gain nullable variant_id. The historical unique quote_options(quote_version_id, hotel_level) index remains intact. New JSON day members extend schedule_json; no second itinerary table is added. An explicit activation leaves retained VS1 inputs inactive; old and new costs are never combined.

## Quantity and arithmetic

Sources are exactly TOTAL_GUESTS, PAYING_PAX, HOTEL_PAX, CRUISE_PAX, VISA_PAX, MEAL_PAX, TICKET_PAX, CUSTOM_QTY. TOTAL_GUESTS and PAYING_PAX reuse existing version counts. The other five populations are explicit profile values; null means unknown, zero means an explicitly reviewed eligible-zero decision. Suggestions for hotel/cruise/meals can be accepted once; subsequent total changes do not update them. FOC remains a separate existing segment, included in total guests; it is never subtracted twice or inferred from another segment.

A guest command diffs the named sources and runs arithmetic only for active, unoverridden lines bound to the changed source. A custom quantity edit targets its line. Unrelated amount, input_hash, trace and calculated_at bytes stay stable. Overrides retain quantity and amount, and require review after guest changes. Capacity, scope, rate eligibility and inclusion coverage can require review without multiplying an unrelated service by changed guests. Explicit recalculation and pre-send verification re-resolve current supplier sources.

| Formula | Calculation |
| --- | --- |
| TRANSFER_PACKAGE | Vehicle count × full tour package rate; units=1; guests validate capacity |
| GUIDE_DAY | Guide count × reviewed guide days × daily rate |
| HOTEL_PAX_NIGHT | Hotel pax × reviewed nights × rate per pax per night |
| CRUISE_PAX | Cruise pax × rate per pax; units=1 |
| VISA_PAX | Visa pax × visa fee; units=1 |
| MEAL_PAX_COUNT | Meal pax × reviewed meal count × rate per meal |
| TICKET_PAX | Eligible ticket pax × individual attraction rate; units=1 |
| SIC_PAX | Explicit SIC population/source × SIC package rate; units=1 |
| CUSTOM / LUMP_SUM | Explicit human-reviewed contract basis; lump sum requires CUSTOM_QTY=1 |

Fixed-point checked integer arithmetic preserves cents and rejects overflow. Supplier manual inputs default to VND. USD is accepted with explicit original currency and reviewed fixed FX. Aggregate VND is retained before presentation conversion. Markup uses cost as denominator; target margin uses selling price. Automated per-pax rounding moves upward to an explicit step; manual selling is explicit.

Stored rates are resolved by the existing RateEngine with tenant, supplier active state, approval, travel/booking validity, blackout, market, min/max pax, special-quote scope and every typed service date. Approved VS2 terms must establish formula/basis/stars/capacity. Room-night/cabin/km/day rates are never silently converted into pax/package rates. Conflicting or differing rates across dates require an explicit reviewed split. Human manual rates require an active supplier, reason, contract evidence, explicit formula and known tax basis. AI has no price write authority in this engine; posted stored-rate amounts or calculated fields reject.

## Mode, coverage and variants

PRIVATE defaults: Transfer, Guide, Hotel, Halong Cruise, Visa, Meals, Destination Tickets. SIC defaults: Transfer, Hotel, Halong Cruise, SIC Tours. Generation creates shared requirements and unresolved line inputs, never estimated rates, nights or meals. Repeated generation preserves existing inputs and IDs. HYBRID uses service-line modes over the same shared day/requirement graph.

Supplier-approved inclusions suppress a standalone service only when date/day/route/attraction/meal/eligibility/transport leg, dates/occurrences, quantity and units match. Included commitments remain visible at zero cost with package/rule trace. Invalid packages cannot cover; duplicate coverage or cycles reject. Partial populations/occurrences require an explicit contract-supported split. A full-tour transfer overlapping SIC transport remains intact and blocks until its approved remaining scope/rate is established.

Economy=hotel3/cruise3; Recommended=4/4; Premium=5/5. PRIVATE and SIC presets produce six child variants under three existing hotel parents. Custom Mix supports independent 4/5, 5/4 and HYBRID. No-cruise variants require reviewed N/A cruise commitments. Cruise-only edits preserve unrelated hotel inputs and arithmetic.

## HTTP contracts

All routes use the existing api/index.php?route=… dispatcher and session authentication. Mutations require the existing X-VTA-CSRF header. Every VS2 quote mutation and lifecycle action carries integer expected_revision. A successful draft mutation increments costing_revision once, removes approval and returns the new revision. Stale commands return 409 STALE_REVISION. Quote then version is the consistent lock order. Native transactions use READ COMMITTED to avoid an old discovery snapshot after waiting on a parent lock.

| Method / route | Input / result |
| --- | --- |
| POST quote-versions/{id}/smart-costing/activate | expected_revision; explicit opt-in, nullable profiles, stable schedule keys |
| GET quote-versions/{id}/smart-costing/context | Safe guests/profile/suggestions/shared schedule/requirements, revision and status |
| PUT quote-versions/{id}/smart-costing/context | Explicit guest/base counts; requirements; schedule; dates/text; reviewed FX requires cost access; result includes recalculated_line_ids |
| GET quote-versions/{id}/options | Existing route dispatches by engine; VS2 returns option_id+variant_id, lines/trace, pricing and authorized supplier/rate choices |
| POST quote-versions/{id}/smart-costing/template | modes PRIVATE/SIC/HYBRID; stable variant IDs |
| POST/PUT quote-versions/{id}/options | variant settings, stars, mode, label, offered flag and pricing; existing hotel parent reused |
| POST quote-versions/{id}/smart-costing/variants/{variant}/lines | Same-version requirement_id or explicit new requirement; source/override/rate or evidenced manual contract |
| PUT/DELETE …/lines/{line} | Allowed input edit / explicit removal; dependent parents cannot be removed |
| POST …/lines/{line}/duplicate | New stable line/requirement IDs; rate is server re-resolved; semantic duplicate validation remains |
| POST …/lines/{line}/reorder | sort_order; same stable ID |
| POST …/lines/{line}/review | Explicit review_reason; actor and current dependency/source hash saved |
| POST quote-versions/{id}/smart-costing/recalculate | Optional line_ids; explicit full recalculation otherwise |
| GET quote-versions/{id}/smart-costing/validation | valid, blocking errors and warnings; one central QuoteVs2Validator |
| GET/PUT rate-versions/{id}/vs2-terms | Existing rate permissions; interpretation and inclusion evidence only; publish also needs rate.approve and an approved existing rate |
| POST quotes/{id}/approve | expected_revision + reason; validate and freeze approval content hash using existing approval machinery |
| POST quotes/{id}/send | expected_revision; revalidate and compare hash; existing immutable sent bundle |
| POST quotes/{id}/confirm | expected_revision + exact option_id + variant_id + sent_content_hash |
| POST quotes/{id}/create-booking | expected_revision; existing booking/services and exact accepted snapshot, idempotent |
| POST quotes/{id}/new-version | expected_revision; existing revision route, new child IDs and review state; HTTP 201 |

Inputs never set total_vnd, trace, input_hash, approval status or inclusion authority. Common blockers include RATE_NEEDED, QUANTITY_NEEDED, REVIEW_REQUIRED, CONTEXT_REVIEW_REQUIRED, REPRICING_REQUIRED, HOTEL_NIGHTS_MISMATCH, GUIDE_DAYS_MISMATCH, MEAL_COUNT_MISMATCH, DUPLICATE_COST, DUPLICATE_PACKAGE_COVERAGE, PARTIAL_COVERAGE_REVIEW, TRANSFER_SCOPE_OVERLAP, MINIMUM_MARGIN, CURRENCY_ADJUSTMENT_REVIEW and BOOKING_AMOUNT_LIMIT. Missing minimum-margin policy gives POLICY_NOT_CONFIGURED warning, not an invented threshold.

## Snapshots, booking and permissions

QuoteVs2Validator gates every first-send path, including an activated version with no hotel parents. Existing bundle approvals, sent bundles, acceptances and booking snapshots are reused. New bundles are tagged VS2_1 and extend the existing format. Sent/confirmed/superseded versions reject all draft writers. Authorized Send retries return frozen JSON without consulting current rates. Public B2B/B2C data uses one allowlist and excludes supplier costs, rates, internal notes and profit. Customer-facing schedule uses the existing public projection.

Acceptance is exact to an offered frozen child. Parent-only selection, an unoffered sibling, a different tenant/version or stale hash rejects. Booking copies only priced supplier commitments; included/no-cost rows stay in the immutable quote evidence and create no duplicate payable. Signed adjustments fold into their same-variant service parent. Original USD/VND currency, net planned cost, frozen FX and handover notes survive. Negative service nets, unrepresentable USD adjustments and original-currency amounts above the existing 100,000,000 confirmation bound block before Send.

Revisions and reuse remap child relationships, clear offer/approval/acceptance/review state, shift typed dates where required and re-resolve rates on the new draft. Reuse resets explicit guest profile counts for the new inquiry. Source issued bytes remain unchanged.

Guest-only quote editors receive context without loading costs/trace/rate choices and cannot call monetary endpoints. quote.view_cost protects costs; explicit quote.view_profit DENY removes profit fields. Shared VS2-derived booking, order, service and Finance projections apply cost/profit/finance permissions. Tenant and same-version ownership are checked on every linked ID. CSRF, actor/reason audit and input/effect writes use existing controls and one atomic transaction.
