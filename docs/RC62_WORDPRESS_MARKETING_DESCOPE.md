# VTA RC6.2 — Marketing/Content moved to WordPress (core-only proposal)

**Status:** isolated code branch, not merged or deployed; requires staging UAT.

## Decision
WordPress owns: Marketing workspace, campaign/content planning, social publishing, ads management, content calendar, SEO and landing-page editing.
RC6.2 owns: inbound leads, customer/inquiry/CRM, tour programs and library, Smart Cost/quotes, sales handover, bookings, suppliers, tour operations, finance and tour AI assistance.

## Front-end changes in this branch
- Remove Marketing navigation from desktop/mobile and unified nav; disallow navigation to its route.
- Remove Marketing workspace + pending-content KPI from home.
- Remove Marketing/Landing JS and CSS from live and preview HTML and the offline PWA asset list.
- Bump PWA cache and JS asset query versions.
- Focus the Sales inbound lead view on qualification, handover and conversion, retaining existing server lead data.
- Remove campaign-conversion drill-down from the Tasks/Approvals screen; attribution data are not deleted.
- Keep the AI tour assistant; no removal of auth, CRM, pricing or operations.

## Data / integration boundary
- This branch does **not** delete migration scripts, database tables, marketing content records, campaign IDs, UTM/source fields, historic audit records, or lead-handover logic.
- Server-side Marketing/Landing/legacy endpoints remain for backward compatibility; removal or access restrictions require a separate decommission audit.
- The integrated B2C candidate adds a signed publication mirror and inquiry relay through VTA Connect (see `RC62_B2C_COMPLETION_GATE_VI.md`). This code has not yet been installed or verified on a real WordPress site. The desired flow is WordPress form/webhook -> validated server-to-server intake -> RC6 Lead Hub -> Sales -> Inquiry -> Quote -> Booking. Authentication, spam protection, idempotency, replay defense, mapping and consent need dedicated tests.
- Keep source metadata and existing leads. Do not expose RC6 credentials or supplier prices to WordPress/browser clients.

## Minimum release gate
1. Compare the proposed diff with the exact intended deployment branch and reconcile outstanding PRs/dependencies before merging.
2. On PC/mobile staging, check login, all desktop/mobile menus, Dashboard, direct/deep navigation rejection of marketing, refresh, PWA cache update and offline behavior.
3. Verify no Marketing/Landing assets load in the browser network tab and no runtime errors remain (including read-only permissions).
4. Confirm WordPress/manual/legacy lead ingestion still reaches Lead Hub and can be qualified, accepted, converted to Customer/Inquiry; verify tenant isolation, permissions and CSRF.
5. Regression: itinerary edit/paste/import/template -> Smart Cost (3★/4★/5★; independent Hotel/Cruise) -> Quote -> Booking -> DOCX/PDF; supplier and finance views.
6. No production data cleanup and no CyberPanel upload before staging acceptance and backup.

## Known limitation
The changes remove the front-end Marketing experience and precache, rather than hard-deleting old PHP classes or migrations. Backend cleanup belongs to a follow-up change after proving WordPress lead capture and retention requirements.
