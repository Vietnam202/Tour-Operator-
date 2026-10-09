# VTA RC6.2 — Marketing Tour Share Center P4 (first-party)

## Status
Isolated draft PR, no production/staging deployment. P4 builds on P0 → P1 → P2 → P3.

## Scope implemented
- Native encrypted-in-transit bearer links with a 64-character random hexadecimal token, SHA-256 hash stored in MariaDB.
- A customer-safe **printable HTML brochure outline** with approved tour title, destination and day titles only.
- Staff can create an expiring link for a known conversation, copy it, insert it in the staff reply composer, review, and separately queue outbound website chat reply.
- Company-scoped list of link metadata: created, expiry, revoked, opened counts (open is not a proof of reading).
- Staff can revoke links at any time. Stale Tour Library Marketing approval or ARCHIVED tour immediately makes link unusable.
- TTL 1, 3, 7, 14 days selectable (hard max 14). Duplicate create request keys never return bearer token a second time.
- Public web page uses HTML escaping, CSP with no scripts or remote assets, no caching, no indexing, no referrer transmission, no frame embedding.
- The page intentionally contains no supplier net rates, margins, commercial proposal, terms, customer details, original DOCX/PDF files or unreviewed itinerary body.

## Integration and endpoints
Migration: `api/migrations/038_marketing_tour_share_p4.sql` applied on a reviewed staging DB after P3 migration 037.

Routes (existing RC6 session + CSRF + RBAC):
- POST `marketing/tour-share/create`: `lead.manage`; body `{conversation_id, program_id, expires_in_days, request_key}`; returns one-time token.
- GET `marketing/tour-share/list?conversation_id=...`: `lead.view`; lists records without bearer tokens.
- POST `marketing/tour-share/revoke`: `lead.manage`; body `{id}`.
- PUBLIC GET `marketing/tour-share/view?token=...`: bearer-token-gated printable HTML, before session authentication; no access after expiry/revocation/stale approval.
  This endpoint lives at the RC6 API host and requires HTTPS, approved host allowlisting and public GET routing to `api/index.php`.

The staff frontend derives same-origin share URL from the RC6 `document.baseURI` plus `api/index.php`. Check real staging URL paths; no customer link should point at staging in production.

## Consent and staff review
Staff must have an actual customer conversation and an ACTIVE, separately Marketing-share-approved program. Click Create Link, review, then Use in Reply, then Queue Reply. Sending does NOT happen on link creation. Access is bearer based; anybody with the link can view while valid. Therefore DO NOT place guest PII, private quotes or payment information on this page.

## Security and limitations
- The existing P3 approval validates only a **high-level outline**. This PR does not approve or expose full paragraphs, pricing or original files.
- This P4 is not an actual binary PDF generator. Customers can use the browser's Print → Save as PDF for the public HTML outline. PDF or DOCX from approved, versioned full programs requires a separate reviewed export implementation.
- Raw bearer tokens are returned once and never persisted in the share table or exposed in list/logs.
- Avoid copy-pasting bearer URLs to third-party analytics or external chat services without assessing exposure. Web server access logs can contain query-string tokens; configure log redaction and short retention.
- RATE LIMIT public opens at reverse proxy/WAF, avoid link preview scrapers as evidence of guest engagement, monitor repeated unsuccessful attempts. No external images/fonts/scripts are loaded in public page.
- Staging: run migration 038 on backup-protected database, verify CSP, link path, guest browser anonymous access, expiry/revocation, staff role permissions, responsive and print.
- No social channel OAuth, WhatsApp/Instagram provider, auto-publishing, AI autonomous messaging, or document attachments implemented here.

## Tests
GitHub Actions: `.github/workflows/marketing-tour-share-p4.yml` runs PHP & JavaScript syntax plus disposable MariaDB integration tests, including P0–P3 regression fixture.
Manual staging verification is still mandatory before merge/deploy.
