# RC6.2 V6.8 — Publish for Ads + Tracking + LeadHub

**Status:** code candidate on Draft PR #104 only. Do **not** treat CI/fixture as permission to deploy to production. Use the gates in VIE-6 (backup→restore clone→migration ledger/checksum) and VIE-11 (exact SHA/staging host/DB/rollback/owner signoff).

## Intended operator workflow

1. Create or edit a B2C programme from Master Tour in **B2C Studio**. Review its public `VTA_DOC_2` itinerary, textual rates, hotels, media rights, personal data and supplier/commercial confidentiality. The B2C owner uses **Review → Approve (public_content_reviewed=true) → Publish**. All images in the B2C public snapshot must be approved `COMPANY/SYNCED` media; B2CStudio enforces this at publication.
2. Create an ACTIVE marketing campaign and active company-scoped Lead Form in LeadHub.
3. Authorized staff (`b2c.approve` **and** `campaign.manage`) opens Marketing → Landing pages → **Publish for Ads** (or `ads-landing-publisher.html` directly while logged in). Select a **PUBLISHED** B2C event, active Lead Form/Campaign, unique slug, one of four templates and default UTM campaign. Tick public-content review and publish.
4. The generated stable **HTTPS** link is `https://{approved-app-base}/api/index.php?route=ads/landing/{slug}`. For another layout, add `&layout=brochure` / `detailed` / `quick` / `b2b`. Use the UI to copy Facebook, Google or WhatsApp URL with UTM. Do not invent `/tours/{slug}` until routing from the main website is implemented and tested.
5. Customer opens the public page without authentication and sees **full approved B2C document** and images, 4 styled views, Hotel & Cruise and Tour Price tables (**On request**, not VTA supplier NET). Clicking the enquiry form's Request Booking/Get a Quote creates a **LeadHub NEW enquiry**, not a Booking, payment, issued quotation or supplier confirmation.
6. Staff reviews campaign-level aggregate `VIEW`, `CTA` and `LEAD` counts by approved UTM grouping. Contact details remain only in the canonical `lead_requests` and CRM, not analytics. An owner can **Withdraw** the landing, making it unavailable immediately. Any B2C publish revision/withdraw also invalidates the landing until explicitly republished to a new slug.

## Design variants

| Key | Client-facing layout | Source |
| --- | --- | --- |
| `detailed` | Professional Detailed | Same reviewed B2C `VTA_DOC_2` |
| `brochure` | Photo-rich Brochure | Same document and approved media, not random web imagery |
| `quick` | Quick Itinerary | Same document in condensed typography, not rewritten content |
| `b2b` | Tour Proposal presentation (public quote request, **not** internal Partner Edition editor) | Same reviewed B2C document, no agency NET |

All four include Hotel & Cruise and price-request tables. Actual category hotel names, prices and detailed service promises are shown **only if already present in the separately approved B2C document**. The fallback public tables do not invent confirmed inventory or selling rates.

## Security and privacy boundaries

- Public URL is globally unique, but publisher, campaign, Lead Form and live B2C event are company-scoped. No publicly writable document or pricing fields.
- Approval is rechecked **on every GET and POST**, including again within the `LeadHub::submit` transaction before accepting a conversion. Withdrawn, stale, wrong-company, inactive-form/campaign or changed publication fails closed.
- Anonymous request validates contact consent, session CSRF, honeypot, 16–80-character request ID, hotel class, travel date, guest count, canonical LeadHub rate limit and idempotency. `ads_landing_lead_links` prevents duplicate conversion counts for retries.
- UTM tracking is sanitized and stored with LeadHub. Aggregate metrics bucket source/medium into known channels and campaign into the publisher-approved default/other, limiting high-cardinality abuse. Analytics tables contain no IP, user agent, fingerprint, session ID, email, phone or other customer PII.
- An optional third-party marketing measurement consent field is presented, but **no GA4, Meta Pixel, Google Ads tag or Conversion API is currently loaded or fired**. Turning on provider-side tags needs private credentials, verified domains, a lawful consent implementation and separate staging UAT. Do **not** claim Meta/Google conversion is verified at this phase.
- Public assets are rendered only through the existing B2C approved-image route; publisher does not expose `proposal_json`, supplier pricing, NET, margin, markup, internal quote or PartnerHub editing tools.
- Landing page defaults to `noindex,follow` to reduce duplicate SEO exposure; actual ad-provider landing page review must be performed before paying for traffic.

## Required REAL staging acceptance, strict order

1. **Gate VIE-6**: full staging backup, **restore into a clone with existing data**, migration ledger/checksum pre/post, apply `api/migrations/090_ads_landing_publish.sql` on clone only, verified rollback rehearsal, DB operator signoff. If the target schema/FKs differ, halt and write a corrective migration; never disable FK checks on live DB to bypass.
2. **Gate VIE-11**: exact deployed source SHA, canonical HTTPS vhost/domain/docroot, PHP 8.3, runtime guard, stage DB isolation, secret config, rollback and release-owner approval. Do not point ads to v2quote or the public `.com.vn` site without explicit domain routing approval.
3. With authorized Marketing staff, publish approved B2C content; verify anonymous GET for four templates on desktop 1366px and mobile 390px, including day text, approved images, Hotel & Cruise, price-on-request and CTAs.
4. Click Facebook, Google and WhatsApp links carrying UTM. Submit a *synthetic* `STAGING QA` enquiry using explicit contact consent. Authenticated staff must **read back a single NEW Lead** in LeadHub with correct company, campaign, source, publication ID, ad ID, UTM, audit and no Booking/Quote side effects. Capture redacted evidence.
5. Retry identical request ID and ensure no duplicate Lead or conversion; different payload same ID must 409. Cross-company ID, withdrawn landing, unpublished/revised B2C event, missing consent, forged CSRF, disabled form/campaign, XSS or oversized payload must fail closed.
6. Verify aggregate `VIEW/CTA/LEAD` counters against real requests; verify no PII in metrics; verify support for SSL/advertiser landing review and no third-party tracking without consent. Sign off media licensing and public copy, screenshot parity and rollback.
7. Only when ALL of the above pass may owner issue separate staging acceptance. Production release requires additional explicit approval.

## Evidence and gate status

Automated workflow: `RC6.2 Ads Publish Tracking Lead QA`, with PHP 8.3 syntactic/source contract, publisher UI JS contract and a **real ephemeral MariaDB** migration→publish→LeadHub NEW readback→UTM→dedup→withdraw test. This CI database is `vta_ads_ci` at 127.0.0.1 only. It is not any VTA staging or production DB.

**Release flag:** `CODE_REVIEW_CANDIDATE`, `STAGING_NOT_DEPLOYED`, `REAL_AD_TRAFFIC_NOT_ENABLED`, `REAL_STAGING_LEAD_NOT_VERIFIED`, `PRODUCTION_NO_GO`.
