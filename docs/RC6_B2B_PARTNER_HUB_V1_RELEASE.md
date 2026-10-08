# VTA RC6 – B2B Partner Hub V1: Release & Acceptance

Status: **Draft PR / code committed only**. Do not merge to production before VTA owner acceptance. Production `quote.vietnamtraveladvisor.com.vn` remains untouched.

## Canonical source
- VTA Admin: `Tour Library → Template Builder V5`. Existing import accepts 20 DOCX/PDF/TXT files per batch; this feature adds **ZIP preview up to 50 documents** (<=200 MB compressed and combined uncompressed, <=10 MB per document, 50 entries).
- Each ZIP entry is staged in private server storage, has a temporary import token, and is saved through the **existing** Tour Library save workflow. Staff must review days, tables, hotels, historical rates, and policy content. The Word original remains in existing Tour Library import storage and can be downloaded by authorized staff.
- A Master tour must be marked `ACTIVE` to publish. VTA Partner Hub reads a safe immutable snapshot of that Master; agency edits save only to `b2b_partner_tours`. No two libraries of Master tour definitions exist.
- Legacy Word price values are historical and **NEVER** approved NET; only a completed VTA quotation snapshot containing current approved `B2B_AGENT` NET cells can supply a published commercial rate. Publishing without source quote is itinerary-only.
- The current Tour Library importer preserves **the original DOCX binary**, but the normalized V5 tour's narrative is text-centric; imported inline photos/rich Word tables are not automatically copied into the new Partner DOCX/PDF. This fidelity gap requires follow-up before 50-program final acceptance.

## Staging setup
1. Use a distinct staging host, backed-up isolated MariaDB, approved `VTA_CONFIG_FILE` and existing security allowlist. Do not disable RuntimeGuard or reset production.
2. Deploy **matching** code from `codex/Vietnam/rc6-b2b-partner-hub-v1` only to staging; run the existing migration command `php api/bin/migrate.php` in that environment after DB backup. New migration is additive `037_b2b_partner_hub.sql`; existing 001–036 are not edited.
3. Have an existing VTA Admin account. Migration grants `b2b.admin` to `ADMIN` roles and `b2b.portal` to the new `PARTNER` role for companies existing at migration. New companies require PARTNER role initialization.
4. Create a PARTNER user through the existing user-management workflow (no supplier, rate, finance, sales or audit permissions), then use `/partner.html` > VTA Publishing > Create Agency > Assign existing PARTNER user. Use company-specific membership, never unrestricted admin access. Administrators can also test with their own login.
5. Open `/partner.html` on staging and sign in. Tour Library shows only `PUBLISHED` VTA master versions.

## Smoke/acceptance test
- Import the supplied `VTA507` example via DOCX or a ZIP. Confirm five detailed days, hotel 3/4/5 categories, Includes/Excludes, terms and warnings are displayed. Compare original Word side-by-side; incorrect Day 3 flight and Halong cruise inclusion require manual correction. No unverified USD345/400/520 NET is published.
- From V5 save/activate, Publish a reviewed version via Partner Hub admin without price. Agency can copy/edit and export **itinerary-only DOCX/PDF** but pricing returns `REQUEST_NET` and priced exports/booking are blocked.
- Publish a *different reviewed snapshot* with legitimate, still-valid `SENT`/`CONFIRMED` Quote Version ID and B2B commercial matrix. Test valid PAX/Private or SIC/hotel/cruise matching, +fixed or +percent markup, expired/missing/ambiguous variant rejection.
- Upload agency JPEG/PNG logo (JPEG normalized privately), brand name/color/contact. Native DOCX is editable and PDF paginated. Verify logo and no supplier cost, markup or VTA NET in client-export bytes (including ZIP metadata/Word XML). Agency-only output may show VTA NET.
- Agency A vs B: list/edit/view/export IDs across tenants must return 404/403 with no disclosure. A user without b2b.portal must not access partner routes. Test CSRF 419. Revoked publication cannot be quoted or booked.
- Booking request uses immutable agency quote/tour snapshot and status REQUESTED, not CONFIRMED. VTA Operations must review supplier availability; no auto-booking.
- Repeat import, save/reload, PC 1440/1024 and mobile 390, correct file names, no console errors or unexpected page overflow.
- Verify basic previous V5 / Smart Cost / Marketing / AI Chat regressions.

## Non-goals / current limitations
- No automatic import of 50 actual user files: only the sample VTA507 was supplied in this conversation; upload the whole ZIP **to the staging website**, not to GitHub.
- Photo-rich and full-fidelity source Word preservation in generated client templates is not complete; implement V5 rich block/media preservation before declaring complete feature acceptance.
- Existing `ProposalOutput` is reused; there is no second pricing/export engine.
- Partner booking requests are listed by Admin in Partner Hub. They do **not** yet automatically produce confirmed Operations bookings.
- No email/WhatsApp notification sending, per-agency unpublished price overrides, selective tour ACL, fully automatic pricing for unmatched Custom Mix, or live hospitality supplier inventory in this increment.
- No production deployment and no real rate approval/contract confirmation performed in this PR.

## CI
`php -l api/lib/PartnerHub.php`, `php -l api/lib/TourLibrary.php`, `node --check partner-hub.js`, `node --check tour-library.js`, `php tests/b2b-partner-hub-unit.php`, `php tests/tour-library.php` in `.github/workflows/b2b-partner-hub.yml`. CI cannot replace authenticated staging/MariaDB/browser/OXML acceptance.
