> Historical v2.x reference only. For this v3 staging candidate, follow V3_STAGING_UPGRADE.md and V3_AUDIT.md instead of the deployment instructions below.

# VTA v2.0 Phase 1 — Build 2 Status

## Build identifier

`VTA v2.0 Phase 1 Product Master — Build 2`

## New in Build 2

### 1. Service-specific Rate Rules
Structured rule tables and UI were added for:

- Hotel: room/meal/season, single/twin/triple, extra bed, child rates, weekend/peak/gala, minimum stay, blackout notes.
- Transport: route, vehicle, operational capacity, service type, included hours/km, overtime, extra km, driver overnight, parking/toll/airport/holiday charges.
- Cruise: cruise/route/duration/cabin/deck, single/double/triple/child, single supplement, transfer/entrance/kayak inclusion, holiday/gala charges.

The common Rate Master remains the parent record; specialized rules are tied to one immutable rate version.

### 2. Overlap / Conflict Review
Potential overlapping approved rates are detected for the same supplier + category + destination + product + option + market when validity dates overlap.

A conflicting draft cannot be approved silently. The reviewer sees the overlapping approved rate(s) and must explicitly confirm the review before approval.

No historical approved rate is overwritten.

### 3. Bulk Supplier Matrix Import
From an extracted supplier XLSX/table, Product can:

1. choose Product/Route column;
2. select actual price columns;
3. set common supplier/category/rate basis/currency/validity;
4. preview candidate count;
5. create reviewed **DRAFT** rates in one import batch.

Every created rate keeps the original supplier document as source and remains unapproved until human review.

### 4. Legacy V1 Rate Library Import
A JSON/CSV migration entry point is included.

Legacy rows are tagged `source_origin=LEGACY` and remain draft/unreviewed. Missing supplier/product/amount/rate-basis rows are skipped and reported instead of guessed.

Legacy rates without a supplier source document cannot be approved as normal sourced rates until reconciled.

### 5. Rate Health UI
Product Overview / Rate Master now surface:

- conflicts;
- legacy rates;
- needs-review rates;
- expiring rates;
- source origin.

### 6. Database migration runner
The installer now applies all ordered SQL migrations and records them in `schema_migrations`, allowing Build 2 to upgrade a Build 1 database without replacing existing business records.

## Still deliberately outside Build 2

- AI automatic rate extraction/approval.
- Guide / Attraction / Daily Tour / Meal specialized rule tables.
- Advanced season date-range engine and structured child/FOC rule engine.
- Email ingestion.
- Sales/Inquiry/Quotation v2.
- Master Booking/Operations v2.
- Finance v2.

## Recommended next increment

After Build 2 is verified on staging with real supplier files:

1. Guide / Attraction / Tour / Meal rate schemas.
2. Structured seasons, blackout ranges, child and FOC rules.
3. Rate comparison / contract year-over-year diff.
4. Import reconciliation workspace with unresolved rows.
5. Product master cleanup and staging-to-production cutover checklist.
