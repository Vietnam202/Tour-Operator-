# VTA Connect 0.1 — WordPress ↔ RC6.2 (stage 1)

**Status:** implementation candidate / staging only. No live WordPress install, provider transport, or CyberPanel database migration was performed.

## What works after PRIVATE staging configuration

- Installable, dependency-free WordPress plugin under `integrations/wordpress/vta-connect/`.
- Shortcode `[vta_chat]`: visitor message, deterministic FAQ (no paid LLM), opt-in quotation request, accessibility and PC/mobile layout.
- Signed server-to-server chat ingestion `webhooks/website-message` → existing RC6 `WebsiteInbox`.
- Signed lead ingestion `webhooks/website-lead` → existing `LeadHub`.
- Signed `webhooks/website-chat-relay` pull/ack lets replies **queued manually by authorized VTA staff** in RC6 appear on the same visitor's WordPress chat. ACK means relayed to the WordPress widget, **not read by customer**.
- UTM/referrer/landing attribution, stable request IDs, anonymous cookie-scoped conversations, source/company mapping, rate limits, HMAC and server-only secrets.
- The existing RC6 `MarketingTourAdvisor` and `MarketingTourShare` allow staff to review/approve public-safe *tour OUTLINES* and create short-lived links, which staff can paste into website replies. Only title/destination/day titles are shared; no internal price/source/supplier/NET.

**Not included:** OpenAI/LLM or remote AI, WhatsApp Cloud API, Messenger send API, TikTok API, full document/PDF file attachments, fully automated public tour recommendation, auto-replies from RC6, automatic CRM handover of every chat, WordPress production installation. Current FAQ runs in browser after a successful signed inbound chat event; staff replies require their RC6 inbox workflow.

## Staging configuration (never commit a live secret)

1. Set up separate WordPress **staging**, with TLS and outbound HTTPS to RC6 staging.
2. Confirm RC6 staging already has the needed website/marketing migrations, `WebsiteInbox`, `WebsiteChatDelivery`, `LeadHub`, `MarketingTourAdvisor`, `MarketingTourShare` and active company/campaign/form. Do **not** replay migrations on production. Run migrations only against disposable/approved staging clone after backup.
3. In RC6 Sales/Lead Hub, create an active campaign and active form with a 64-character `public_token`, scoped to VTA company. In RC6 **private config** outside webroot add:
   
   ```php
   'integrations' => [
      'website_webhooks' => [
          'wordpress_vta' => [
              'secret' => getenv('VTA_WP_BRIDGE_SECRET'), // generated 32+ random characters
              'form_token' => getenv('VTA_WP_BRIDGE_FORM_TOKEN') // 64 hexadecimal characters
          ]
      ]
   ],
   ```
   
   Merge with other existing `integrations` entries; do not replace them wholesale.
4. In WordPress **private wp-config.php**, before “That's all, stop editing”, set:
   
   ```php
   define('VTA_CONNECT_API_URL','https://v2quote.vietnamtraveladvisor.com.vn/api/index.php');
   define('VTA_CONNECT_SOURCE','wordpress_vta');
   define('VTA_CONNECT_SECRET', getenv('VTA_WP_BRIDGE_SECRET'));
   ```
   
   The secret values on WordPress and RC6 must match; configure separately in each server's private environment. Do not embed them in frontend JS, WordPress content, git or HTML.
5. Zip **only** the `vta-connect` folder (not the entire repository). WordPress → Plugins → Add New → Upload Plugin → Activate. Add `[vta_chat]` to a staging page. Settings → VTA Connect reports *configured*, not end-to-end verified.
6. Login to RC6 staging with VTA staff `lead.manage`; verify visitor website messages arrive under the expected VTA company. Staff can queue website replies through `marketing/website-chat/send` or the authorized inbox UI. Confirm the visitor sees replies after poll and a relay ACK.
7. Share a tour: staff approves its marketing-safe metadata, generates a time-limited outline link in RC6 via `marketing/tour-share/create`, then sends that URL in the website chat. Links must be revoked or expire and must not include net/supplier pricing.

## QA checklist (BLOCK live upload until passed)

1. Valid HMAC, wrong secret, missing config, SSL certificate check, invalid/stale source, 2XX vs 4XX/5XX/retries.
2. Two anonymous browser sessions do not see each other's conversations; cookie is HttpOnly/Secure on HTTPS, no client-selected conversation ID.
3. Contact/guest validation, invalid message, spam honeypot, same-origin and throttling. Multiple retries of the *same* signed event do not make two leads.
4. Staff website reply appears in the **correct** client widget; recipient ACK means relay, never claim read receipt. Verify offline recovery and page refresh.
5. Two B2B agency logins cannot query VTA website leads or another agency's leads; no supplier/NET/review-only content in chat or tour link. Public tour approval becomes invalid when itinerary metadata is edited or revoked.
6. Desktop/mobile UI, accessibility and WordPress theme CSS interaction; staging backup/rollback. No production install, CyberPanel edit or merge until approved.

## Code map

- `integrations/wordpress/vta-connect/vta-connect.php` — WP shortcode, REST endpoints, server relay, private settings and security.
- `integrations/wordpress/vta-connect/includes/class-vta-connect-protocol.php` — exact RC6 schema-compatible signed payloads.
- `integrations/wordpress/vta-connect/assets/widget.js`, `widget.css` — accessible responsive visitor widget.
- `tests/vta-wordpress-connect-unit.php` — isolated WP stubs validate signing, source, chat/lead/relay, origin.
- `.github/workflows/vta-connect.yml` — syntax and contract CI.

## Cost and provider boundary

The plugin has no licensing or paid SaaS dependency. Existing hosting and development/testing costs remain. **Meta and TikTok messaging policies/fees are unchanged.** WhatsApp/Messenger/TikTok buttons and account connections are separate future integrations; a link opened in their apps is not a confirmed API delivery.

The proposed Marketing de-scope to WordPress (PR #47) is a separate unmerged branch. This implementation starts on B2B integration PR #48 to avoid overwriting its permissions and should be reconciled with PR #47 before any eventual release.
