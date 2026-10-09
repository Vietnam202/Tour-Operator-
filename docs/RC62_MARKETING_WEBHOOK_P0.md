# RC6.2 Marketing Webhook Center — P0

Status: code on an isolated branch. No production deployment, no Meta/WhatsApp connection.

## What is implemented
- Webhook Center replaces the Landing Pages tab in the Marketing workspace; historical Landing Builder files/routes and data are preserved.
- A signed, server-to-server website lead receiver is exposed through the existing API index route: webhooks/website-lead.
- The receiver checks HMAC-SHA256 of the exact raw JSON body, validates event type and ID, and reuses LeadHub to validate and deduplicate requests.
- A scoped, additive webhook event journal is created by migration 023; the journal does not retain raw guest PII.
- The authenticated marketing/webhooks endpoint exposes source names, readiness and 30 most recent accepted events; only campaign.manage users can read it.
- Social OAuth, Meta/WhatsApp receivers, outbound messages and automated publishing are NOT implemented in P0.

## Staging installation
1. On backup-protected staging only, apply migration 023 after previous migrations. Never reset the production database.
2. Add website_webhooks to the private VTA_CONFIG_FILE config array (do not commit credentials or form tokens):

    'integrations' => [
      'website_webhooks' => [
        'site-main' => [
          'secret' => getenv('VTA_MAIN_WEBHOOK_SECRET'),
          'form_token' => getenv('VTA_MAIN_LEAD_FORM_TOKEN'),
        ],
        'site-landing' => [
          'secret' => getenv('VTA_LANDING_WEBHOOK_SECRET'),
          'form_token' => getenv('VTA_LANDING_LEAD_FORM_TOKEN'),
        ],
      ],
    ],

The secret must be a random 32+ character value unique to each website. The form token must be the 64-character token of a pre-existing active lead form for the correct company. VTA site backend must own the signing secret. Never embed it into public HTML/JS.

3. Send from the website backend to: POST /api/index.php?route=webhooks/website-lead
   Request headers:
     Content-Type: application/json
     X-VTA-Webhook-Source: site-main
     X-VTA-Signature-256: sha256=<lowercase hex HMAC-SHA256 of exact raw body>
   Example JSON:
     {"event_type":"lead.created","event_id":"unique_site_event_0000001","lead":{"contact_name":"Example","email":"test@example.test","total_guests":2,"paying_pax":2,"foc":0,"destination":"Ha Long","message":"Please send a private tour"}}

4. Admin opens Marketing > Webhooks and checks source readiness and received event log.
5. Run pure PHP tests: php tests/webhook-center-test.php

## Expected responses
- First valid signed event: HTTP 200, accepted=true.
- Repeated event with same content: HTTP 200, replayed=true.
- Reuse event ID with different lead content: HTTP 409.
- Invalid signature / unknown source: HTTP 401.
- Invalid event payload: HTTP 422.
- Unconfigured source/form: HTTP 503.
- LeadHub form rate limit exceeded: HTTP 429.

## Limitations and next programming tasks
- First-party website leads only, not a general webhook endpoint for strangers.
- No frontend/browser signing. No tenant ID accepted from payload.
- Event handling is synchronous via existing LeadHub. Meta/WhatsApp require dedicated, signed, durable asynchronous ingestion and a background worker before production use at social scale.
- Full integration QA still requires database migration tests, 2-website sandbox tests, CSRF/RBAC checks and production-host allowlist review.
- Next: channel account registry + OAuth, source-specific signature verification, durable social event outbox, unified conversation/message schema, review-first AI response and Tour Library sharing.

## References
- Meta developer API/postman official docs: https://www.postman.com/meta/messenger-platform-api/
- Instagram: https://www.postman.com/meta/instagram/
- WhatsApp: https://www.postman.com/meta/whatsapp-business-platform/
- TikTok Direct Post: https://developers.tiktok.com/docs/en/content-posting-api-get-started
