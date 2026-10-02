> Historical v2.x reference only. For this v3 staging candidate, follow V3_STAGING_UPGRADE.md and V3_AUDIT.md instead of the deployment instructions below.

# VTA v2 Phase 1 — Staging → Product Cutover Checklist

Do not cut over solely because the UI loads. All mandatory items below should pass on staging with real VTA-like supplier data.

## Mandatory

- [ ] Current database backup created and restore location known.
- [ ] `001`, `002`, `003` database migrations recorded successfully.
- [ ] Admin login works over HTTPS.
- [ ] Product user permissions work; unauthorized roles cannot approve/reconcile rates.
- [ ] Supplier create/edit/search works.
- [ ] Original PDF/DOCX/XLSX upload and download works.
- [ ] Google Drive Document Vault works end-to-end, or approved temporary private-local staging fallback is documented.
- [ ] Hotel/Transport/Cruise rate rules save correctly.
- [ ] Guide/Tour/Attraction/Meal rate rules save correctly.
- [ ] Season/Blackout/Child/FOC rules save and display from the same rate version.
- [ ] Rule tester flags a known blackout and returns expected child/FOC result.
- [ ] Conflict review blocks silent approval of overlapping rates.
- [ ] Contract comparison shows a known old-vs-new rate correctly.
- [ ] Bulk supplier matrix creates draft rates only.
- [ ] Legacy incomplete rows remain unresolved instead of being guessed.
- [ ] Import Reconciliation creates a draft rate and retains audit history.
- [ ] Unsourced Legacy rate cannot masquerade as an approved supplier contract rate.
- [ ] Rate version history is not overwritten by later changes.
- [ ] TODAY rate-expiry worker creates/deduplicates tasks.
- [ ] Audit records key Product actions.
- [ ] Mobile/tablet layout remains usable.
- [ ] Browser 100% zoom remains readable.
- [ ] No real API keys, DB passwords or OAuth secrets exist in public frontend files.

## Real-data pilot

Pilot with a small controlled set before migrating the full Rate Library:

1. one hotel contract;
2. one transport tariff;
3. one cruise promotion;
4. one guide rate sheet;
5. one attraction or daily-tour tariff;
6. one restaurant/meal rate.

Only after those six flows reconcile to the source documents should Product migration scale up.
