> Historical v2.x reference only. For this v3 staging candidate, follow V3_STAGING_UPGRADE.md and V3_AUDIT.md instead of the deployment instructions below.

# VTA v2.0 Phase 1 — Build 3 Status

## Build identifier

`VTA v2.0 Phase 1 Product Master — Build 3`

## Implemented in Build 3

### 1. Guide / Tour / Attraction / Meal rate schemas

The common Rate Master remains the parent. Each immutable rate version can now carry structured service-specific data for:

- Guide: language, destination, service scope, overtime, meal/accommodation/intercity allowance and holiday surcharge.
- Daily Tour/SIC: SIC/private mode, adult/child rate, pickup zone/surcharge, departure schedule, cut-off, minimum pax, guide language, included/excluded.
- Attraction: adult/child/infant/senior rate, source age/height/group rules, meal/combo option and holiday surcharge.
- Meal: restaurant, cuisine, meal type, menu level, adult/child rate, beverage and guide-meal conditions.

Hotel, Transport and Cruise schemas from Build 2 remain unchanged and supported.

### 2. Structured contracting rules

Each rate version can store zero or more:

- `SEASON`, `BLACKOUT`, `PEAK`, `HOLIDAY` date periods;
- child pricing rules based on `AGE_YEARS`, `HEIGHT_CM` or retained source text;
- FOC rules based on paying-pax thresholds.

These rules belong to the immutable rate version. A new rate version copies the prior structured rules rather than silently changing history.

### 3. Rule evaluation tester

The Product UI can test a rate against:

- travel date;
- paying pax;
- child age;
- child height.

It reports matching periods, blackout condition, effective amount after structured fixed/percentage adjustments, child price when a structured rule matches, and eligible FOC units.

The tester is a costing aid. A blackout never becomes an automatic supplier availability decision.

### 4. Contract / rate comparison

From Supplier Document Review, Product can compare rates created from the current document with prior approved/superseded matching supplier rates.

The comparison shows previous amount, current amount, direction and percentage change. It is advisory only and cannot approve/change rates automatically.

### 5. Import Reconciliation

Build 2 already retained skipped legacy rows. Build 3 adds a review workspace for those unresolved rows.

A Product user can map supplier, category, destination, product, option, amount, currency, rate basis, validity and an optional source document. Resolving a row creates a new **draft** rate and records the reconciliation in audit/activity history.

If no real supplier source document is linked, a legacy record stays explicitly `LEGACY` and remains subject to the existing approval/source restrictions.

### 6. New permissions

- `rate.reconcile`
- `rate.compare`

The installer grants these to Admin and Product/Contracting by default.

## Deliberately still outside Build 3

- automatic AI approval;
- automatic supplier rate selection;
- Gmail ingestion;
- Sales/Inquiry/Quotation v2;
- Master Booking/Operations v2;
- Finance v2;
- full contract OCR for scan-only documents;
- external supplier portal.

## Recommended next increment

After Build 3 passes staging with real VTA supplier contracts, Phase 1 should be closed with hardening/migration QA, then development can move to **Phase 2 — Sales + Quotation Engine**, using the approved Rate Master as the cost-source foundation.
