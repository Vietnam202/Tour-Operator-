# VTA Unified OS RC6.2 — VS2 Functional Specification

**Status:** Approved product specification  
**Target:** Codex implementation on top of `codex/Vietnam/rc6.2-testing`  
**Scope:** Build Itinerary, Media Sync, Smart Costing, B2B/B2C Proposal, Sales → Operations → Accounting workflow  
**Business owner:** Vietnam Travel Advisor (VTA)

---

## 1. Product objective

VS2 must turn the current Quote/Itinerary flow into one connected operating workflow:

```
Inquiry
→ Guest Profile
→ Build Itinerary
→ Media
→ Smart Costing
→ PRIVATE / SIC
→ 3★ / 4★ / 5★
→ Pax Band Pricing
→ B2B / B2C Proposal
→ Customer / Agent Confirm
→ Booking Snapshot
→ Sales Handover
→ Operations Supplier Booking
→ Accounting Deposit / Payment
→ Ready to Operate
→ Tour Operation
→ Actual Cost / Profit
```

**Core principle:** data confirmed once must flow through the full booking lifecycle without manual re-entry.

---

## 2. Hard business rules

1. **Supplier cost default currency = VND.**
2. Client/agent selling price may be USD or another supported selling currency.
3. AI must **never invent or calculate final supplier cost**.
4. AI may only assist with itinerary understanding, service mapping, missing-data detection, media suggestions, and drafting.
5. All financial calculations must be deterministic, traceable, auditable, and driven by stored rates/manual approved inputs.
6. Sent/accepted quotation snapshots are immutable. Any commercial change requires a new revision.
7. PRIVATE and SIC are costing templates, not rigid forms. Final costing remains editable before sending.
8. One itinerary is reused across 3★/4★/5★ pricing options; do not duplicate the itinerary per star category.
9. B2B and B2C use one underlying itinerary/costing dataset but different commercial output rules.
10. Existing RC6.2 VS1 behavior, permissions, tenant isolation, snapshots, migrations, Marketing and AI Chat must not regress.

---

## 3. Build Itinerary — Visual Itinerary Builder

### 3.1 Layout

Desktop workspace should use three logical areas:

- **Left:** Day / itinerary structure
- **Center:** Day editor
- **Right:** Media rail

### 3.2 Day editor

Each day supports:

- Day number and date
- Day title
- Route / destination
- Activities
- Meals: B / L / D
- Transport mode
- Guide requirement
- Hotel / overnight
- Cruise where applicable
- Notes / special requests
- Service requirements
- Images

Users can add, duplicate, reorder and remove days.

### 3.3 Media sources

Build Itinerary must allow media from:

- Upload from PC
- Google Drive
- VTA Media Library

Media can be assigned as:

- Tour cover
- Day hero
- Day gallery
- Hotel media
- Cruise media
- Service media

### 3.4 Google Drive integration

Do not sync the whole Drive by default.

Support selective folder mapping:

```
Google Drive folder
↔ VTA Media Library destination/category
```

Store source metadata such as:

- source type
- Drive file ID
- source folder ID
- modified time
- local/cache reference
- destination/category/tags
- approval state

Supported modes:

- Import once
- Keep source linked / sync status

A change to a Google Drive image must never mutate media inside an already sent/accepted proposal snapshot.

### 3.5 Media governance

Media states:

- Personal / quote-only
- Company media
- Synced media

Company media workflow:

`Draft → Approved → Archived`

Support image optimization, thumbnail generation, low-resolution warning, duplicate detection and favourites.

---

## 4. Smart Costing Engine

### 4.1 Principle

Costing is generated from:

```
Guest Profile
+ Itinerary
+ Service Requirements
+ Supplier Rates
+ Quantity Rules
= Cost Sheet
```

AI is not part of the arithmetic path.

### 4.2 Cost line states

Each cost line should show origin/status:

- AUTO
- EDITED
- MANUAL

Rate status:

- APPROVED RATE
- CONTRACT RATE
- MANUAL COST
- RATE NEEDED
- EXPIRED RATE
- NEEDS REVIEW

### 4.3 Calculation trace

Every cost line must expose a human-readable calculation trace.

Examples:

- `1 vehicle × 14,000,000 VND package = 14,000,000 VND`
- `1 guide × 4 days × 1,000,000 VND = 4,000,000 VND`
- `16 hotel pax × 3 nights × 800,000 VND = 38,400,000 VND`

Do not allow opaque totals unless the user explicitly selects a manual lump-sum cost basis.

---

## 5. Standard service categories and formulas

### 5.1 PRIVATE default template

PRIVATE must auto-create the following default category order:

1. Transfer
2. Guide
3. Hotel
4. Halong Cruise
5. Visa / E-visa
6. Meals
7. Destination Tickets

Additional services may be added manually or generated from itinerary requirements.

### 5.2 Transfer

**Business rule:** full-tour package.

Formula:

```
Vehicle Count × Full Tour Package Rate
```

Pax is used to validate/suggest vehicle capacity, not to multiply transport cost.

The transfer package stores itinerary route scope. If the itinerary changes materially or pax exceeds capacity, mark:

`REPRICING REQUIRED`

Do not invent the replacement rate.

### 5.3 Guide

**Business rule:** per day.

Formula:

```
Guide Count × Guide Days × Daily Rate
```

Guide Days can differ from total tour days.

### 5.4 Hotel

**Business rule:** per pax per night.

Formula:

```
Hotel Pax × Nights × Rate per Pax per Night
```

Hotel Pax defaults from the guest profile but can be overridden with audit history.

### 5.5 Halong Cruise

**Business rule:** per pax.

Formula:

```
Cruise Pax × Rate per Pax
```

Default costing must not use cabin pricing. A manual/custom override may be allowed only when explicitly selected.

### 5.6 Visa / E-visa

Formula:

```
Visa Pax × Visa Fee
```

Visa Pax may differ from Total Guests.

### 5.7 Meals

Formula:

```
Meal Pax × Number of Meals × Rate per Meal
```

Meal Pax may differ from Total Guests.

Support meal type and cuisine/dietary metadata.

Avoid double costing meals already included in cruise/SIC packages.

### 5.8 Destination Tickets

Each attraction is a separate cost line.

Formula:

```
Eligible Ticket Pax × Ticket Rate
```

Do not collapse unrelated attractions into one opaque ticket total.

### 5.9 SIC Tour

SIC is a package service calculated per pax:

```
SIC Pax × SIC Package Rate
```

Each SIC product must declare inclusions, e.g.:

- Shared transport
- Guide
- Meal
- Ticket(s)
- Other inclusions

Smart Costing must suppress duplicate standalone costs already included in that SIC package.

---

## 6. PRIVATE / SIC / Hybrid costing

### 6.1 PRIVATE default

```
Transfer
+ Guide
+ Hotel
+ Halong Cruise
+ Visa
+ Meals
+ Destination Tickets
```

### 6.2 SIC default

```
Transfer
+ Hotel
+ Halong Cruise
+ SIC Tours
```

### 6.3 Final edit

Before quotation is sent, Sales may:

- Add service/cost
- Edit quantity/rate
- Duplicate
- Remove
- Reorder
- Change supplier
- Change quantity basis
- Add supplement
- Add discount/adjustment
- Mark included/no cost
- Convert an individual day/service between PRIVATE and SIC

All edits must be auditable.

---

## 7. 3★ / 4★ / 5★ multi-option pricing

Both PRIVATE and SIC support:

- Hotel 3★ / 4★ / 5★
- Halong Cruise 3★ / 4★ / 5★

Default presets:

- Economy: Hotel 3★ + Cruise 3★
- Recommended: Hotel 4★ + Cruise 4★
- Premium: Hotel 5★ + Cruise 5★

Also support **Custom Mix**, e.g.:

- Hotel 4★ + Cruise 5★
- Hotel 5★ + Cruise 4★

These are pricing variants over one itinerary, not separate itineraries.

---

## 8. Guest & quantity model

Booking/quote level:

- Adults
- Children
- Infants
- Total Guests
- Paying Pax
- FOC

Service-level quantity sources may include:

- TOTAL_GUESTS
- PAYING_PAX
- VISA_PAX
- MEAL_PAX
- HOTEL_PAX
- TICKET_PAX
- CUSTOM_QTY

Changing guest count recalculates only cost lines whose quantity rule follows that guest field.

Manual quantity overrides remain stable and are flagged for review.

---

## 9. Cost views

### 9.1 Simple View

Spreadsheet-like and familiar to Sales:

`Service | Basis | Qty 1 | Qty 2 | Unit Rate | Total | Source`

### 9.2 Advanced View

For Manager / Operations / Accounting:

- Supplier
- Expected Cost
- Confirmed Supplier Cost
- Actual Cost
- Variance
- Payment status
- Rate source
- Audit trail

Maintain three financial stages:

1. Expected Cost
2. Confirmed Supplier Cost
3. Actual Cost

---

## 10. Commercial calculation

Internal supplier cost remains VND.

Store and display:

- Total Supplier Cost VND
- Cost / Paying Pax
- Markup %
- Margin %
- Selling Price
- Gross Profit
- FX Rate Snapshot
- Selling Price USD/pax where applicable

Markup and margin are distinct formulas and must not be conflated.

If no valid rate exists, return `RATE NEEDED`; never use an AI-estimated price.

---

## 11. Pax Band Pricing Generator

Support configurable non-overlapping pax bands, for example:

- 2 pax
- 3–4 pax
- 5–9 pax
- 10–14 pax
- 15–20 pax

For each scenario, rerun deterministic costing because transfer capacity/package and per-pax costs may differ.

Supported band pricing methods:

- Exact pax
- Representative pax
- Safe price

Default recommendation for VTA implementation: **Safe price** = use the highest required selling price per pax within a band, subject to configured policy.

Generate separate matrices for:

- PRIVATE 3★/4★/5★
- SIC 3★/4★/5★

Support custom mix columns.

Apply minimum-margin validation before finalization.

When a customer confirms exact pax, rerun exact-pax costing before Operations handover.

---

## 12. B2B and B2C commercial channels

### 12.1 Shared source data

B2B and B2C must reuse the same itinerary, service requirements and supplier cost data.

### 12.2 B2B

Audience: travel agent/company.

Support:

- Agent/company
- Contact person
- Market
- Net B2B rate
- Pax band matrix
- 3★/4★/5★
- PRIVATE / SIC
- Quote validity
- Payment terms
- Cancellation terms
- Optional commission/gross/net configuration
- White-label mode

### 12.3 B2C

Audience: direct traveller.

Support:

- Retail selling price
- Visual itinerary
- Highlights
- Recommended option
- 3★/4★/5★
- PRIVATE / SIC
- Deposit/payment information
- Customer-facing CTA

Never expose supplier cost, margin, rate source or internal notes.

---

## 13. Proposal templates

Initial template set:

1. **VTA Standard B2B**
2. **VTA B2B White Label**
3. **VTA Explorer B2C**
4. **VTA Premium B2C**

### 13.1 VTA Standard B2B

Based on VTA's existing operating proposal structure:

1. Brand header
2. Tour title
3. Briefing
4. Schedule summary
5. Best Price Offer
6. Hotel / Cruise list
7. Detailed itinerary with images
8. Included
9. Excluded
10. Children policy
11. Payment terms
12. Cancellation policy
13. Important notes
14. Contact

Support multiple price layouts:

- Net group rate
- Pax band 3★/4★/5★
- PRIVATE vs SIC

### 13.2 Hotel / Cruise list

Use structured rows:

`Destination | 3★ | 4★ | 5★ | Nights`

Halong Cruise must be represented separately as 3★/4★/5★ options where applicable.

### 13.3 Output

Support:

- Preview
- PDF
- Word
- Web proposal link
- Email sending

Template selection must affect presentation only, not underlying itinerary/cost data.

---

## 14. Finalization and revision control

Before finalization run validation for:

- Missing rates
- Duplicate cost
- SIC inclusion double-counting
- Hotel nights mismatch
- Guide days mismatch
- Transfer scope/capacity mismatch
- Missing ticket cost
- Missing meal cost
- Margin below policy
- Invalid/overlapping pax bands

State flow:

`Draft → Ready to Quote → Sent → Accepted / Rejected / Revision Required`

Once Sent:

- lock snapshot
- preserve image/media snapshot
- preserve FX snapshot
- preserve price matrix
- preserve hotel/cruise option
- preserve included/excluded/policy blocks

Changes require `Create Revision`.

---

## 15. Sales → Operations Service Handover

When the customer/agent confirms:

`Accepted Quote Option → Booking Snapshot → Handover to Operations`

Operations must receive the exact service commitments confirmed with the customer.

Each service must distinguish:

- Requested by customer
- Confirmed by Sales
- To be arranged by Operations

Also support:

- Must Match
- Flexible / equivalent allowed
- Source of commitment
- Special request
- Service notes

Operations must not need to re-read the customer quotation to understand what to book.

---

## 16. Operations Booking Workspace

Booking workspace should include:

- Booking header
- Customer/group
- Travel dates
- Pax
- Sales owner
- Operations owner
- Booking status
- Readiness %

Service list examples:

- Hotel
- Restaurant
- Car/Transfer
- Guide
- Halong Cruise
- Ticket/Activity
- Flight
- Other

Suggested service lifecycle:

`Need Booking → Request Sent → Awaiting Reply → On Hold → Confirmed → Deposit Required → Deposit Paid → Fully Paid → Reconfirmed → Ready`

Exceptions:

- Unavailable
- Alternative Needed
- Cancelled
- Refund Pending

---

## 17. Supplier email integration

From each Service Order, Operations can:

- Generate booking email from confirmed service data
- Preview/edit
- Send directly to supplier email
- Attach relevant documents
- Keep message linked to booking + service
- Track sent/reply timeline

Supplier contact roles:

- Reservation
- Sales
- Accounting
- Emergency

Email template types:

- Initial booking request
- Availability request
- Rate request
- Amendment
- Cancellation
- Final reconfirmation
- Payment confirmation
- Invoice request
- Refund request

Do not store mailbox passwords in application data. Use secure provider integration/OAuth or server-side secret configuration.

Supplier replies should be attachable/viewable in the relevant service timeline. AI may suggest extracted fields (rate, deposit %, deadline) but Operations must explicitly apply them.

---

## 18. Operations → Accounting payment workflow

After supplier confirmation, Operations can create a Payment Request containing:

- Booking
- Service
- Supplier
- Booking reference
- Confirmed cost
- Deposit/full-payment amount
- Currency (default VND)
- Due date
- Supporting document
- Note

Accounting states:

`Pending → Approved / Rejected / Clarification Required → Paid`

Capture:

- Paid amount
- Paid date
- Bank/payment account
- Transaction reference
- Payment proof

Support multi-stage supplier payment schedules.

Cost variance beyond configured thresholds must require approval before payment.

---

## 19. Booking readiness and live operation

Booking readiness checks may include:

- Hotel confirmed
- Cruise confirmed
- Car confirmed/assigned
- Guide assigned
- Restaurant confirmed
- Tickets ready
- Required supplier payments cleared
- Rooming/guest details complete
- Flight details complete
- Voucher/Travel Pack ready

Do not mark booking `READY TO OPERATE` while blocking readiness items remain unresolved.

After departure, the same booking data continues into Live Operation and then Actual Cost / Reconciliation / Profit.

---

## 20. Security, audit and privacy

Preserve and extend RC6 controls:

- RBAC
- Company/tenant isolation
- CSRF/session protections
- immutable sent/accepted snapshots
- audit events
- private storage
- permission-filtered data projections

Guide/driver/supplier messages should only expose fields required for that role/service.

Do not expose internal selling margin, supplier confidential rates, passport data or unrelated customer data in outbound supplier messages.

---

## 21. Required migration / architecture behavior

- Additive migrations only.
- Preserve current historical migrations byte-for-byte unless an existing project migration policy explicitly says otherwise.
- Existing sent/accepted/issued snapshots must not be rewritten.
- New tables/fields should be normalized around shared entities instead of duplicating quote/program/booking content.
- Media binaries should not be stored as base64 in MariaDB.
- Use reference IDs + metadata + private/object/file storage.
- Rate calculation must be deterministic and unit-tested.

---

## 22. Implementation sequence

### VS2.1 — Smart Itinerary + Costing foundation

- Guest/quantity model
- Service requirements
- Deterministic costing rules
- PRIVATE/SIC templates
- 3★/4★/5★ options
- Custom Mix
- Add/Edit/Remove cost lines
- Simple/Advanced cost views
- Finalization validation

### VS2.2 — Media + Proposal

- PC upload
- Media Library
- Google Drive selective picker/sync metadata
- Images by day
- Template Gallery
- VTA Standard B2B
- VTA B2C templates
- PDF/Word/Web preview/output

### VS2.3 — Pax bands + Sales handover

- Pax Band Generator
- Minimum margin guard
- B2B/B2C commercial policies
- quote snapshot/revision
- accepted-option handover
- Service Handover Sheet

### VS2.4 — Operations + supplier communication

- Operations Booking Workspace
- Supplier booking requests
- Supplier email integration
- communication timeline
- supplier confirmation
- payment request to Accounting
- readiness controls
- actual cost / variance linkage

---

## 23. Acceptance criteria

VS2 is not complete unless all of the following are demonstrable:

1. Create one itinerary and generate PRIVATE + SIC cost sheets.
2. Generate Hotel/Cruise 3★/4★/5★ variants without duplicating itinerary data.
3. Change pax and recalculate only rule-bound lines correctly.
4. Transfer remains full-tour package costing.
5. Guide remains per-day costing.
6. Hotel remains pax × nights.
7. Halong Cruise remains per-pax.
8. Visa, Meals and Tickets follow their approved formulas.
9. SIC inclusions do not double-count Guide/Meal/Tickets/Transfer included in the package.
10. No AI-generated supplier price can enter final costing.
11. Supplier costs are stored/displayed as VND by default.
12. B2B and B2C outputs hide all internal supplier/margin data.
13. Sent quotation is immutable; revision creates a new version.
14. Accepted quote creates an Operations handover with exact confirmed services.
15. Operations can create a supplier email from Service Order data.
16. Operations can create a payment request after supplier confirmation.
17. Accounting payment status returns to the booking/service workflow.
18. Booking readiness reflects unresolved service/payment issues.
19. Historical RC6.2 VS1 flows and permissions continue to pass regression tests.
20. Full workflow is covered by automated tests plus a browser acceptance walkthrough.

---

## 24. Reference examples used during product design

- VTA Google Sheet costing example:  
  https://docs.google.com/spreadsheets/d/1U5RPhzFmkCGu7DIPC2v1dQz2w2gD_Wqg5OMvUHn0bmI/edit
- VTA Hanoi–Halong–Sapa proposal example:  
  https://docs.google.com/document/d/1pQ1boZyqB6oQ9Oshi5JVU-ML8oAmbYAF/edit
- VTA uploaded B2B proposal sample:  
  `Danang - Hoian - Bana hill - HCM - Cu chi tunnel 5D4N 50- 3 FOC.docx`

These examples are product references. Implementation must use structured system data, not hard-coded content copied from the examples.

---

## 25. Codex instruction

Before implementation:

1. Audit the current RC6.2 testing branch against this specification.
2. Produce a gap matrix: Existing / Partial / Missing / Conflicting.
3. Identify schema changes and migrations required.
4. Do not remove or silently alter existing RC6.2 VS1 behavior.
5. Implement in small reviewable increments following the sequence above.
6. Add regression tests for every costing rule and snapshot invariant.
7. Keep a release note and migration/rollback instructions.
8. Do not deploy to production automatically.
