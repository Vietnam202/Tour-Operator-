# VTA RC6.2 — V6.7 B2B Partner Edition Integration & Acceptance

**Owner-approved reference:** original offline `VTA_V6_7_B2B_Partner_Editable_WhiteLabel.html` (7D6N VTA707 design). Source committed as an approved-design adaptation, not a new low-fidelity UI.

## Where to use

1. Authenticate in RC6.2 **Partner Hub** as a B2B partner account authorized for an ACTIVE agency.
2. In **Tour Library**, copy an already `PUBLISHED` B2B tour into **My Tours** (or open an existing working copy). The program is still VTA-owned, and the agency receives an independent working copy.
3. From **My Tours → V6.7 Partner Edition · PDF** or **Edit Tour → V6.7 White-label · Export PDF**, a separate tab opens `/b2b-partner-edition.html?tour_id=<working-tour-id>` (same secure authenticated session).
4. The screen reproduces the owner-approved V6.7 side-by-side **Agency Branding & Selling Prices** editor and A4 **Customer Tour Proposal**. Tour title, overview, day-by-day descriptions, Hotel & Cruise 3★/4★/5★, inclusions/exclusions and policies load from the **published, company/agency-authorized** working copy, not a fixed VTA707 demo.
5. The partner can edit **company name, tagline, logo (local PNG/JPEG/WebP ≤3MB), email, phone, WhatsApp, website, address, currency, reference, 3★/4★/5★ customer selling prices and single supplements, validity date and proposal notes**. All changes stay within the page. NET and original Master are not modified.
6. **Save Edited HTML for Partner** downloads a self-contained HTML that opens offline with the partner’s edits, without the API loader. This edited file holds their customer selling prices — send only to the appropriate intended recipient. **Export PDF · Chrome Print** prints an A4 customer copy without edit sidebar. Chrome Print → Save as PDF, background graphics on.

## API contract

Authenticated GET `api/index.php?route=b2b/my-tours/{workingTourId}/v67-preview`.

- Uses existing `PartnerHub::working()`, `PartnerHub::agency()`, active B2B publication and membership ACL.
- Returns `schema=VTA_B2B_PARTNER_V67_PUBLIC_PREVIEW`, safe normalized public tour document and public branding only.
- Excludes NET, markup, source quote, supplier prices, internal day notes, internal cost and customer PII.
- No writes to Master, Quote, Booking, or database from V6.7 page. No new migration.
- No anonymous editing. A B2B partner without authorized login sees no itinerary. A final customer gets **PDF, not the editable portal URL**.
- Existing Partner Hub persistable branding/logo and authoritative server PDF/DOCX exports are unchanged. This V6.7 page uses Chrome print as the owner-approved offline reference does.

## Test evidence

Run `RC6.2 V6.7 B2B Partner Edition QA` at exact HEAD (PHP 8.3 syntax, Node/JS contract and Playwright Chromium 1366/390 mobile, 4 editable prices, logo brand defaults, HTML download and offline reopen, Chrome A4 PDF generated with print editor hidden, no horizontal overflow, invalid owner/tenant denied). The workflow uploads `vta-v67-b2b-visual-evidence` screenshots.

**Passing CI verifies mocked authorized HTTP + browser behavior, not real staging user authentication, real database or production-ready PDF.**

## Staging UAT / fail-closed gate

Owner-approved hostname: `https://rc62-staging.vietnamtraveladvisor.com.vn`. Before deploying: VIE-6 backup→restore clone→migration checksum/ledger, VIE-11 exact SHA/host/docroot/DB/TLS/rollback, staging operator and owner signoff. No fallback to any v2quote or production host.

After controlled authorized staging deployment:

- Validate exact running SHA in /api health/build identity.
- Sign in as **two different real role test users from distinct agencies**. Same-company authorized agency copy works; wrong agency/tenant/archived/revoked publication cannot view content.
- Compare screenshots for 1366/1440 and 390px against approved V6.7 HTML, including exact left editor / right branded A4 layout and Hotel & Cruise 3-tier and Price Table sections. No VTA707 demo data should appear for another tour.
- Upload JPG/PNG/WebP logo and check clear/reupload and file oversize/wrong media fail. Verify changes appear in both preview and PDF. Confirm VTA Master/NET/approved publication record unchanged. Verify no internal NET or markup in document/page API source.
- Set 3/4/5★ selling prices, single supplements, currency, contact, expiry; save HTML; reopen offline and compare every field. Export PDF from Chrome with background graphics, inspect **each actual page** for unclipped layout, no control panels or NET; screenshot evidence + sample PDF (synthetic data only).
- Ensure prior PR #102 Tour Share→Lead feature unaffected; do not infer a Lead from B2B PDF export.

**Status to owner:** DRAFT source review until CI full-pass; STAGING_UNVERIFIED / PRODUCTION_NO_GO until real-auth UAT. Do not mark Issue #99 or PR #103 as accepted based on mock data alone.
