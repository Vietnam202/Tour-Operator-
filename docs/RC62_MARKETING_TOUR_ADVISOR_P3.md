# Marketing RC6.2 – VTA Tour Advisor P3

## Scope
Adds a **first-party deterministic PHP** tour recommendation/draft module inside Marketing > Website Inbox.
No AI vendor service or third-party chatbot integration is used by this feature.
This is a **rules-and-approved-template assistant**, not a generative language model.
Human Sales still reviews and explicitly queues every outbound reply.

### What is implemented
- Marketing tour metadata search scoped by company, constrained to ACTIVE programs.
- Separate **Marketing Share Approval** using an explicit Product/Admin action. Internal ACTIVE is not sufficient.
- The approval hash is computed from the exact fields allowed in a customer-facing draft: title, destination, language, tags and each day's title.
- Only metadata with a valid current approval hash is shown to regular Marketing/Sales users.
- English and Vietnamese customer-response drafts based solely on the approved snapshot. No prices, supplier costs, source text, proposal fees, rate/margin or private notes.
- Approved drafts are stored immutably with idempotency keys; staff must click "Use in Reply", review, then separately "Queue Reply".
- If a draft-linked send is made, the RC6 delivery API validates the original draft text, company, conversation, ACTIVE program and approval hash again.
- Staff can still compose manual replies without an advisor draft. No autonomous sending or PDF generation.

### Migrations and staging
1. Apply existing reviewed P0–P2 migrations 023–025 on backup-protected staging.
2. Apply **037_marketing_tour_advisor_p3.sql**, which adds approval metadata to tour_library_programs and the draft journal.
3. Open Marketing > Unified Inbox, select website conversation. Search existing ACTIVE programs.
4. User with `tour_library.manage` can review public metadata and explicitly approve it.
5. User with `lead.manage` creates English/Vietnamese reply, reviews it in Inbox and queues it for first-party website relay.
6. Revise any approved tour title, destination, tags or itinerary day title. Prior approval becomes stale and draft-linked send is rejected, requiring reapproval and new draft.

### Privacy and security
- Tenant/company scope on every program/conversation/draft query; permissions `lead.view`, `lead.manage`, `tour_library.manage`.
- Prompt injection not applicable to generation because text is deterministic and no external model is called.
- Untrusted internal program strings are displayed escaped and only reviewed metadata fields enter draft.
- No private cost pricing, commissions, passport/payment fields or source_text queried or exposed by these API endpoints.
- The approval hash detects metadata changes. If a program is archived, it immediately becomes unavailable.
- This approval is for short public **metadata outline** only, NOT approval to send original DOCX/PDF, price tables or a personalized proposal.
- Draft remains internal until staff deliberately sends it. Status QUEUED / RELAYED is transport status, not guest read.
- If Marketing managers need detailed itinerary brochures, a separate Document Share Center with versioned, expiring links and approval is required.

### Test coverage
- PHP/JS parse and MariaDB tests in GitHub Actions.
- Tests cover zero default public programs, ACTIVE vs DRAFT filtering, tenant isolation, separate share approval, English/Vi drafts, duplicate request protection, mutable program invalidation, wrong-conversation and wrong-body rejection.
- Manual staging review still required: UI responsive, staff permissions, source identity, copy/use/send, privacy and data-retention.

### Known limitations
- Not a generative model, not an AI that speaks without staff approval, and not a public document/PDF sender.
- Searches tour title/destination only. It does not prove availability or calculate rates.
- The server currently scans max 200 most recently updated ACTIVE programs per tenant, returns max 25 results; indexed search/pagination required when Tour Library grows.
- This PR is stacked on prior P0, P1 and P2. None should be merged or deployed without staging QA.
- No Meta, Instagram, TikTok, WhatsApp, YouTube OAuth/API connection in P3.
