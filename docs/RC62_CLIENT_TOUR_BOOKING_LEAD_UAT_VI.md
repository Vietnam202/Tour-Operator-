# RC6.2 — Public Tour → Request Booking → Real LeadHub Request

**Implementation:** PR #102 — `MarketingShareBooking` + `ClientTourPresentation`. **Code only. NO live staging write/deploy was performed by this PR.** Never claim a real Lead exists without the UAT read-back below.

## Architecture

`GET api/index.php?route=marketing/tour-share/view&token={64-hex}&layout=detailed` displays an approved/revocable tour-sharing snapshot and an anonymous **Request Booking** form.

Four public presentation layouts: `detailed`, `brochure`, `quick`, `b2b`. One allowlisted, marketing-approved `VTA_PUBLIC_TOUR_OUTLINE_V1` snapshot is rendered in all four layouts, without modifying any Master, Quote or document source. The shared itinerary has only title/destination/day titles. **Do not call this full V6.5 brochure parity.** An expansion to actual detailed days/photos/hotel options/prices requires a separate explicit publication approval + safe snapshot/version mapping; it must NEVER read unapproved `proposal_json` or NET supplier data.

Two consistent customer-facing tables always appear: **Hotel & Cruise Options** (3★ / 4★ / 5★, no unapproved hotel promises) and **Tour Price** (3★ / 4★ / 5★, `Request quote` until a selling price has been independently approved).

`POST api/index.php?route=marketing/tour-share/request-booking&token={64-hex}` is a **regular HTML form** (no JS required). It validates anonymous CSRF, honeypot, explicit contact consent, traveller counts, departure date, hotel category, and live status/approval/expiry/tenant of same token; then calls **existing `LeadHub::submit`**. LeadHub stores a company-scoped `lead_requests` row, status `NEW`, attribution via UTM and immutable idempotency key, with audit entry `LEAD_REQUEST_RECEIVED`. The note includes Master program ID, shared link ID and marketing approval hash. A Lead is NOT a Booking, Quote SENT, paid invoice or supplier acceptance.

## Private operator configuration

The receiving company must have an ACTIVE Lead Form associated with an ACTIVE Campaign. Configure in an **authorized staging private config** (NEVER commit secrets):

```php
'integrations'=>[
  'tour_share_booking'=>[
    'enabled'=>true,
    'company_id'=>/* correct staging company ID */,
    'form_token'=>/* secret 64-char hex token for that company-scoped active lead form */,
  ],
],
```

If `tour_share_booking` is absent, the backend may reuse an already enabled, company-matched `integrations.b2c_storefront` lead form. Explicit `enabled=false`, wrong company, inactive form/campaign or malformed token means **no live input form**. No public form may choose a tenant, campaign or form token.

Ensure PHP session cookies are **Secure / HttpOnly / SameSite Lax**, exact stage vhost and document root verified, and SSL chain trusted. Do not use public production form tokens in staging.

## Current prerequisites / known scope gap

- Current `MarketingTourShare::create` requires an existing ACTIVE `social_conversations` ID and a marketing-approved ACTIVE program. This PR **does not** implement a new standalone Master Tour link database schema or force-create a social conversation; a new link creation workflow requires a separately reviewed model/migration or approved B2C publication route.
- A public share may be revoked and expiry is limited by existing handler to 1–14 days.
- There is **no** payment processing or auto-confirmed Booking.
- Existing MarketingTourShare approval covers a title/outline only. Hotel names/actual itinerary media/real selling prices remain unapproved and cannot be taken from internal costing. No NET, discount rules, margin/profit or suppliers are placed into public HTML.
- The prior standalone HTML demo V6.5 includes content that is NOT currently an approved public snapshot. Treat it as UX reference until a deliberate publish approval exposes equivalent public content.

## Minimum REAL staging acceptance (VIE-6, VIE-11 required first)

1. Read back `GET /api/index.php?route=health` exact running build SHA and audit approved staging vhost/docroot/DB, SSL/TLS, active backup → restore clone + ledger/checksum, rollback and operator signoff. **Block if mismatch**; no bypass of existing controller.
2. Authorized staff signs in, creates company-scoped active campaign/form and shares a marketing-approved Master program with a 64-hex token. Confirm the link is associated with the right company and expires.
3. Anonymous browser opens share, switches through four `layout=` styles and checks matching day titles and both tables; hidden internal fields MUST not be in page source. Refresh form to get session CSRF; submit **synthetic test identity (clearly prefixed `STAGING QA`)** with consent and a future date. No real customer personal data in logs/screenshots.
4. Read back through authenticated `lead-hub/requests` admin API / UI, filter status `NEW`, and verify exactly one request with `utm_source=vta_tour_share`, `utm_campaign=tour_program_{id}`, `utm_content=share_{id}`, no booking side effects. Assert business side `LeadRequest` ID and audit trace using redacted screenshots. Only this establishes **LEAD_CREATED_ON_STAGING**.
5. Same `request_id` + identical data on retry does not duplicate. A reused request ID with different payload fails `409`. Wrong company, expired/revoked, updated but unapproved Master, wrong CSRF, missing consent, invalid email/date, malicious HTML, and >rate-limit submissions fail closed.
6. Verify mobile 390px / desktop 1366px, all sections of real approved content, send-to-client privacy and source/version mapping. Run full CI **on the exact deployed SHA**; stage operator records SQL ledger and rollback evidence.

## Important gate decision

This Draft PR does not authorize uploading to production, merging RC6.2 or modifying a staging database without VIE-6/VIE-11. The owner has requested real staging functionality, **but no live deployment access/credential is available to this ChatGPT session** and current browser staging displays a signed-out/offline screen. Operator/Codex must complete actual secure staging deployment and read-back. Status until then: `CODE_READY_FOR_REVIEW`, `STAGING_DEPLOYMENT_UNVERIFIED`, `REAL_LEAD_NOT_YET_VERIFIED`.

Related: PR #98 (integrated runtime), PR #101 (full design HTML source), Issue #99 (visual parity), Issue #97 (staging operator), VIE-6/7/11.
