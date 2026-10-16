# VTA RC6.2 — Master Tour Library / Advanced Itinerary V6 Approval

**Status:** DRAFT / code candidate only — NOT staging accepted, NOT production.
**Reference baseline:** PR #90 exact commit `5b89a90049aed32ed4a45b3fc4cfcb29c6aa47ad`.
**Scope:** Tour Library and existing editable Word document interface. No Pricing, Booking, supplier/NET, CRM or Marketing change. No DB schema migration.

## Reviewable functionality
1. Existing `product` Tour Library: Master and **Template Center** tabs, searchable filters including day-count, **Gallery/List** presentation, existing import and archive actions.
2. Templates are `VTA_TEMPLATE`-tagged company-scoped content programs already stored by the existing Save-as-Template pathway.
3. For text/structured programs without rich Word document, **Create from template** creates a separate `DRAFT` with a fresh creation key; source provenance is cleared and manually entered group/private selling prices are not carried forward. Real backend and ACL must validate the POST.
4. **Rich Word document and linked media templates are not silently cloned**: action is unavailable until a permission-checked deep-copy implementation exists. Staff may still open/edit the source according to existing permissions.
5. Existing Tiptap Word editor now includes a heading-based **Document Outline**, six editable content-block shortcuts, and four **screen-only layout** options. It retains current authoritative saving, original DOCX/PDF import and output engine.
6. Four layout options are **UI presentation only**; do not claim dedicated professional DOCX/PDF template engine. This is part of the owner acceptance backlog.

## Critical product boundaries
- The legacy `inventory` service-blueprint screen remains available via existing menu, but this PR does **not** merge its tables with `tour_library_programs`. A migration requires explicit owner-authorized data mapping and independent staging approval.
- Document versioning remains the existing server revision model; the new Outline and content blocks create no second database.
- Source documents, images, pricing provenance, quotations, bookings, B2C publication and authorization remain governed by existing backend paths.
- Screen layouts are not export presets; no new pricing values are invented.
- Existing document backups/restore, GDPR/consent, pricing and publication releases still require their release gates.

## Verification on exact head
- `node --check tour-library.js`
- `node --check document-editor.js`
- `node tests/v6-master-editor-ui.cjs`
- `npm test --prefix tests` and `npm run test:unified --prefix tests`
- Browser 1440 / 1024 / 390: Gallery/List, Template Center, user read-only, mobile overflow; create/reopen a 7D6N Word document, modify Heading outline, content block insertion, save with server revision and refresh.
- Real-auth staging: verify company A/B ACL; original template remains unchanged; content/images retained on actual DOCX/PDF; HTTPS, PWA update and owner signoff.

## Explicit NO-GO boundaries
- CI fixtures ≠ staging. Owner must authorize the staging environment, verify actual deployed exact SHA, HTTPS and a restored existing-data MariaDB clone (VIE-6) before mutation.
- Retain VIE-11 release gate; do not merge/upload/install/migrate production without approval.
- No claims of complete Word/PDF customer output or media-rich template cloning until E2E verification and signoff.

## Approval artifacts
- `VTA_RC62_V6_APPROVAL_MASTER_ITINERARY.html`: standalone interactive visual reference stored with the ChatGPT handoff, not loaded by the production app.
- GitHub Draft PR: source integration. Review differences and compare with the HTML reference, then request changes before staging.
