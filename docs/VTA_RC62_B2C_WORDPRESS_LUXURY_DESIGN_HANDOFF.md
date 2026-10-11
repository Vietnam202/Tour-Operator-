# RC6.2 × WordPress — VTA B2C Luxury Studio design handoff

**Status: DESIGN REVIEW / DRAFT.** A local interactive HTML reference was prepared for the user in this conversation; it is **not** a published WordPress page, deployed PHP build or a backend integration. No WordPress installation or provider messaging was performed.

## Product contract

- **One Master Tour** stored in RC6.2; B2B and B2C are independent *distribution channels*, not separately maintained master itineraries.
- B2B Common/Exclusive, agency ACL, NET, markup, supplier details, partner copies, quotation and booking request flow remain unchanged. Preserve and test PR #48 authorization rules.
- RC6.2 B2C Studio owns Word-style itinerary editing, revisions, destination and media selection, retail-price approvals, publication workflow and quote/booking workflow.
- WordPress `vietnamtraveladvisor.vn` owns the premium client storefront, SEO pages, conversion forms, site navigation and publishing presentation. It is **not** the source of truth for tour costs, rates, supplier inventory or booking status.
- Website chat and signed lead intake should extend the **existing** `WebhookCenter`, `WebsiteInbox`, `LeadHub` and WP `VTA Connect` implementation (PR #49), not create a second CRM or an extra paid SaaS.

## Luxury experience reference

Brand visual system: deep midnight green (#11292b), warm ivory (#f8f5ee), muted champagne gold (#b99b68), editorial serif headings, large destination visuals, restrained navigation, whitespace and few actions.

Two coordinated views are represented in the standalone HTML design review:

1. **WordPress B2C Storefront:** immersive editorial hero, approved tour cards, filterable journeys, tour detail/itinerary, enquiry form and rule-based concierge chat.
2. **RC6.2 B2C Studio:** template/master-based programme list, direct Word-like editing, hotel and cruise levels independently selectable (3★/4★/5★), approval workflow, WordPress publication status and customer enquiries.

Local demo interactions: edit an approved tour -> move to draft/review (it disappears from the simulated public collection) -> admin approval -> publish to simulated storefront; customer submits enquiry -> see item in simulated RC6.2 Lead Hub; prepare a staff-approved draft reply. This is **memory-only mock state**, not production persistence.

The actual photos/media in the production WordPress theme must be approved/licensed VTA-owned assets. The review artifact uses original embedded stylized scenery illustrations, not claims of a particular hotel/cruise.

## Proposed real API boundary — not yet implemented by this design PR

### RC6 -> WordPress (B2C publication)

Server-to-server, signed & idempotent approved publication event using an immutable publication version. Never expose internal REST endpoints to WordPress visitors.

Allowlist ONLY:
`publication_id`, `revision`, `status`, `slug`, `title`, `destination`, `intro`, `approved day title/summary blocks`, `approved image references and alt captions`, `tour duration`, `approved client-facing inclusion/policy copy`, optional exact `approved customer retail quote reference`.

Do **not** transmit:
supplier names/contracts, NET, markup, service rates, customer identity, internal remarks, payment credentials, unpublished B2B/EXCLUSIVE tours, quote costing rows, supplier images without usage rights.

WordPress keeps a mapping `rc6_b2c_publication_id + revision -> WP post ID` for a dedicated `vta_journey` CPT (or equivalent), updates its own SEO presentation on signed events, and handles unpublish/revoke. Stale/out-of-order version changes rejected. Missing sign-off: fail-closed unpublished. A later publication revision must not inherit approval automatically.

### WordPress -> RC6 (Lead & Chat)

Use existing signed `webhooks/website-lead` and `webhooks/website-message` intake; HMAC, idempotency, source-bound company/campaign, rate limiting, bot trap, and separate staging secrets.

Lead fields: identity/contact, requested tour/publication reference, travellers (paying and FOC when captured), travel dates, notes, `utm_*`, landing URL, referrer and consent metadata as permitted. RC6 resolves the tour ID against a currently approved B2C publication; never trust a submitted supplier price.

Staff replies can use existing `WebsiteChatDelivery` relay after authorization. Messenger, WhatsApp and TikTok click-through **is not** verified send delivery; official outbound APIs require their own provider eligibility and acceptance tests.

## UI actions and safeguards

- Tour list: **Edit / Preview / Publish approval**; no duplicate tools or redundant editors.
- Staff can create an independent B2C working copy from Master without mutating Master or B2B.
- B2C mixed class selection (Hotel 3★ + Cruise 5★) remains independent; server Smart Cost and approved Quote own actual retail values.
- A program change requires review. Public sharing must never display a newly edited revision before approval. Revoke/outdated shares invalidate correctly; ensure exported Word/PDF retains approved snapshot privacy.
- Customer quote enquiry does not confirm booking or supplier availability.
- On production use server timestamps and DB transactions for publish/rollback; never trust mock browser state.

## Minimum release gate

1. Integrate only **after** reconciling PR #48 (B2B) / #49 (WP Connect) and proposed Marketing de-scope PR #47; no blind cherry-pick or production merge.
2. PHP MariaDB migration rehearsal on disposable clone and backup/restore on existing staging DB; schema unique constraints/version ordering.
3. Two B2B agency sessions and a B2C Sales session: B2B Common/Exclusive ACL unchanged; public WordPress cannot access any NET/cost/agency/customer record.
4. Edit -> review -> approved publish -> WordPress verify -> revision -> revoke -> purge/retry/out-of-order event tests.
5. WP website form -> signed server intake -> RC6 Lead Hub, duplicate replay, CSRF/origin/SSRF and invalid HMAC tests.
6. PC/mobile WordPress and RC6 browser UAT including SEO URLs, privacy, accessibility, DOCX/PDF and real supplier rates/FOC mixed 3★ hotel + 5★ cruise. Production upload only with operator approval.

This PR is a **design contract**, not a claim that site integration or a luxury WordPress theme has been deployed.
