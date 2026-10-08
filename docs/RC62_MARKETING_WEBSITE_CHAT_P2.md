# VTA RC6.2 Marketing P2 — Native two-way website chat

STATUS: ISOLATED CODE ONLY. NOT DEPLOYED. NO PRODUCTION ACCESS.

## Delivered
- Migration 025: outbound response queue per company and website conversation.
- Protected staff send route: POST api/index.php?route=marketing/website-chat/send, authenticated, CSRF and lead.manage enforced.
- Protected staff outbound history route: GET api/index.php?route=marketing/website-chat/outbound&conversation_id=123, lead.view required.
- Signed website relay route: POST api/index.php?route=webhooks/website-chat-relay, HMAC-SHA256 and timestamp freshness +/- 300 seconds.
- Supported relay actions: pull after last ID, ack message IDs from a verified website backend.
- The status RELAYED proves the website backend acknowledged message collection, NOT that a visitor read the response.
- Direct first-party widget and PHP website relay in integrations/website-chat/. No SO9, Chatwoot, Make or external chatbot is required.
- Outbound queue messages are separate from internal reply drafts. Website backend holds the signing secret; browser never receives it.

## Staging setup order
1. Back up staging MariaDB. Apply migrations 023, 024 and 025 in order. Never run test fixture against production.
2. RC6 private VTA_CONFIG_FILE must contain integrations.website_webhooks for both site-main and site-landing, each with unique HMAC secret (32+ characters) and active lead_forms token. No secrets in git.
3. Deploy the three integrations/website-chat files on each website's SAME ORIGIN under /vta-chat/; chat-relay.php MUST execute as PHP, not return its source.
4. Configure each website BACKEND environment (not browser):
   VTA_CHAT_RC6_ENDPOINT=https://[reviewed-staging-host]/api/index.php
   VTA_CHAT_SOURCE=site-main (or site-landing)
   VTA_CHAT_SECRET=[source-specific-private-HMAC-secret]
   PHP cURL extension, HTTPS and sessions must work.
5. Add to each site's HTML layout, after security review:
   <link rel="stylesheet" href="/vta-chat/widget.css">
   <script defer src="/vta-chat/widget.js" data-relay="chat-relay.php"></script>
6. Browser tests: open chat, enter message, find in RC6 Marketing > Conversations, use Queue Reply to send staff message, verify browser shows reply after polling (12-second interval).
7. Repeat with site-landing; verify source isolation and independent website session state.

## Security
- Chat POST uses same-origin PHP session, httponly/secure SameSite=Lax cookie, CSRF token, session send limits, plus RC6 backend source/thread throttling.
- Website backend generates the visitor thread ID. Client cannot pass a thread ID to RC6.
- Webhook HMAC signs the exact JSON request bytes. Separate server-only keys per source; no token exposed to front-end.
- The relay RC6 endpoint URL is configured on the website server, restricted to HTTPS and API index.php; TLS certificate is verified, redirects disabled.
- No raw HTML inserted for message bodies; browser uses textContent and RC6 uses escaping.
- Backend polling/ACK checks signed source, company and thread. Timestamp replay window is 5 minutes.
- No provider OAuth, Facebook/WhatsApp adapter, AI autonomous messaging or social media publishing in P2.
- Avoid supplier cost, financial details or unapproved personalized quotes in replies.

## Limitations
- A PHP session stores a maximum of 100 chat messages; visitors do not have permanent cross-session identity.
- GET poll is a synchronous HTTPS request to RC6; test concurrency and hosting limits on staging before promoting.
- RELAYED is a transport acknowledgement, not a visitor read receipt.
- The website relay must be deployed on BOTH sites separately. RC6 code alone does not make chat appear on the sites.
- Production readiness still needs manual PC/mobile browser tests, guest privacy/consent notice, PHP session hardening, abuse monitoring and backup/recovery.
- Website markup shown above is installation guidance only and not an automatically deployed change.

## Automated checks
- php -l: RC6 delivery code, site relay and integration test.
- node --check: staff Inbox and website widget.
- tests/website-chat-delivery-db-test.php: outbound idempotency, HMAC relay protocol parsing, tenant and source isolation, cursor, acknowledgement and closed conversation.
- GitHub workflow: marketing-website-chat-p2.yml.
