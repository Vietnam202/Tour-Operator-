# RC6.2 Marketing — Native Website Unified Inbox P1

**Status:** isolated branch; P0 prerequisite. No production deployment and no Meta/Instagram/WhatsApp account connected.

## Delivered
1. Additive migration 024 creates tenant-scoped website conversations, inbound messages, signed event journal and per-source/per-thread rate buckets.
2. Public signed route POST /api/index.php?route=webhooks/website-message receives only a first-party backend's conversation.message event. Events are transactionally persisted, scoped to company ID derived from the server-owned active lead form.
3. Marketing > Conversations contains a responsive Inbox (three panes on PC, stacked on mobile), message history, internal reply draft, status, and manual handoff to existing LeadHub when guest details are ready.
4. Historical manual marketing conversation notes remain accessible underneath native inbox, without data migration.
5. Handoff uses existing lead_requests + LeadHub validation (requires contact email or phone, valid paying pax counts); it never auto-accepts Sales, creates a quote, or sends outbound messages.
6. The code includes parsing/HMAC tests, a MariaDB integration fixture for conversation isolation, event replay, message uniqueness, handoff and rate limiting.

## Migration and host setup
- Create a DB snapshot before staging changes.
- Apply migrations 023 (from P0) and 024 on **staging only**; never reset a production DB. Validate foreign keys and role permissions.
- Use the existing private VTA_CONFIG_FILE setting from P0:
  'integrations' => [
    'website_webhooks' => [
      'site-main' => [
        'secret' => getenv('VTA_MAIN_WEBHOOK_SECRET'),
        'form_token' => getenv('VTA_MAIN_LEAD_FORM_TOKEN')
      ],
      'site-landing' => [
        'secret' => getenv('VTA_LANDING_WEBHOOK_SECRET'),
        'form_token' => getenv('VTA_LANDING_LEAD_FORM_TOKEN')
      ]
    ]
  ]
- Ensure each form token corresponds to an ACTIVE campaign and ACTIVE lead form belonging to the intended company.
- Do not put secrets or form tokens in browser JavaScript, HTML, CDN bundles or git.
- For a custom-PHP or WordPress website, implement a **server-side relay**: the public chat form must submit only to its own website backend, which validates origin, CSRF/session, abuse limits, and sends the signed webhook to RC6.2.
- This branch deliberately does not ship a public anonymous browser chat sender. Publishing a cross-site unsigned endpoint would expose RC6.2 to bot spam.

## Website backend → VTA signed event
POST https://<RC6-STAGING-HOST>/api/index.php?route=webhooks/website-message

Headers:
- Content-Type: application/json
- X-VTA-Webhook-Source: site-main (or site-landing)
- X-VTA-Signature-256: sha256=<HMAC-SHA256(exact request body, corresponding private secret)>

JSON body example:

    {
      "event_type": "conversation.message",
      "event_id": "evt_site_main_0000000001",
      "conversation": {
        "external_id": "visitor_session_000000001",
        "contact_name": "Guest",
        "email": "guest@example.test"
      },
      "message": {
        "message_id": "msg_site_main_0000000001",
        "text": "Please send Hanoi / Halong 6D5N itinerary."
      },
      "attribution": {
        "utm_source": "website",
        "utm_campaign": "northern_vietnam",
        "landing_url": "https://www.example.com/tour"
      }
    }

event_id and message_id must be stable across retries and unique per business event/message, respectively. external_id must be stable across messages for one session/visitor. All three IDs use letters/numbers/hyphen/underscore, with documented length checks. Never place personal contact data inside those IDs.

PHP signing example on the **website server**:

    $raw = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $signature = 'sha256=' . hash_hmac('sha256', $raw, $privateSecret);
    // Send $raw as-is with the signature above. Do not re-encode the JSON afterwards.

## Expected behavior
- First valid event → HTTP 202 accepted, conversation_id returned.
- Signed retry with exact same payload → HTTP 202, replayed=true.
- Event ID reused with altered content → HTTP 409 conflict.
- Duplicate message ID with altered text → HTTP 409, no overwrite.
- Unsigned/invalid HMAC → HTTP 401.
- Bad event fields → HTTP 422.
- Rate-limited (>30 accepted events/minute per conversation or >300/minute per source) → HTTP 429; source should retry later with stable event/message IDs.
- Config/form not active → HTTP 503.
- Handoff from the staff screen requires at least email or phone, validated guest numbers, and manual Sales qualification. The existing Sales accept/return/convert flow remains authoritative.

## Operation and security limits
- **No outgoing replies are delivered** from this version. The Reply Draft is internal and must not be represented as a sent message.
- **No automatic AI replies**, provider OAuth, Facebook/WhatsApp/TikTok APIs, social publishing, or customer-facing widget in this version.
- Do not auto-merge identities between sources based on display names. Anonymous website visitors can have a conversation without contact data; Lead Hub request is created only when complete information is supplied.
- All read/write staff routes require authenticated session, CSRF on writes and lead.view/lead.manage. All DB read/write queries scope by company_id.
- Apply SQL cleanup through a controlled recurring maintenance task in staging/production: DELETE FROM social_webhook_rate_windows WHERE window_start < DATE_SUB(NOW(), INTERVAL 2 DAY); (after backup/retention approval).
- Retain the last 100 conversations and last 100 messages per conversation in the UI/API view to prevent heavy first-load queries; implement pagination in the production hardening phase.
- The website relays must implement their own rate limits/bot protection. Central webhook windows are defense in depth, not a substitute for edge protection.
- Sensitive data retention, consent notices, deletion workflow, and disaster recovery require product/legal review before going live.

## QA
- Unit: php tests/website-inbox-test.php
- MariaDB: php tests/website-inbox-db-test.php in a **dedicated test database** only.
- CI: .github/workflows/marketing-website-inbox-p1.yml
- Manual staging: send signed messages from both website servers; verify UI rendering, source separation, replay behavior and Sales handover; also verify P0 lead.created still works.

## Further work after P1 review
- Trusted public web chat relay and visitor-session protocol on each website (separate audited website deployment).
- Staff outbound gateway with delivery status, no send-on-draft behavior.
- Facebook/Instagram Messenger and WhatsApp providers via official API and verified OAuth permissions.
- AI Tour Advisor: use approved Tour Library data, human review for personalized proposals, and never expose supplier net costs.

## Webhook Center combined monitoring
- The Marketing Webhooks tab now combines accepted website lead events from migration 023 and accepted website message events from migration 024, with a Lead # or Chat # target and tenant isolation.
- Both migrations are required before opening the live Webhook Center view on staging.
