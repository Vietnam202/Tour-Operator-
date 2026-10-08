# VTA RC6.2 Marketing P7 — Signed Meta Messenger and Instagram Unified Inbox

**Delivery status:** stacked draft GitHub PR, no staging migration or live Meta connection.
**Provider transport:** Facebook Page Messenger & Instagram Professional DM text webhooks via *Meta Facebook Login* app. 
WhatsApp, Instagram Login webhooks, attachments, echoes, delivery/read receipts, replies and OAuth onboarding are not implemented.

## Architectural flow
Meta callback (GET challenge / POST X-Hub-Signature-256) → synchronous HMAC validation → durable MariaDB event queue → gated internal CLI worker → existing `social_conversations`, `social_messages` → Unified Inbox → manual Lead Hub handoff.
Meta replies remain **disabled**; staff can save internal drafts, not deliver DMs from RC6.
No account credentials, names or identity links are inferred from arbitrary sender IDs. Source and tenant derive only from private server-allowlisted Page/IG entity ID.
Individual messages and raw body are not logged. The queue stores **minimal text** and opaque platform IDs only, not raw original webhook JSON.

## Prerequisites
- Apply previous migrations 023–025, 037–040 before the new **041_meta_unified_inbox.sql** on a backed-up staging database.
- Ensure strict host allowlist on RC6. No production deployment by this PR.
- Meta developer app, callback URL, verify token, and app secret from Meta app dashboard; Page/IG account must be owned or authorized by VTA.
- Messenger: Page token and `pages_manage_metadata`, `pages_read_engagement`, `pages_messaging` where required; subscribed Page messaging webhook.
- Instagram DM via Facebook Login: linked Instagram Professional + Facebook Page, appropriate permissions including `instagram_basic`, `instagram_manage_messages`, `pages_manage_metadata`, business verification and app review as applicable. Confirm actual fields and permissions in Meta developer app before enabling.
- Do not mix this with Instagram API with Instagram Login; that uses different token/endpoint/permission flows.

## Private config sketch (outside webroot and Git)
```php
'integrations'=>[
  'meta_inbox'=>[
    'enabled'=>false, // default: keep OFF until staging approval
    'app_secret'=>getenv('VTA_META_APP_SECRET'),
    'verify_token'=>getenv('VTA_META_WEBHOOK_VERIFY_TOKEN'),
    'accounts'=>[
      'vta_page'=>[
        'company_id'=>1,'campaign_id'=>1,
        'object'=>'page','entity_id'=>getenv('VTA_META_PAGE_ID'),
        'enabled'=>false,'app_review_confirmed'=>false
      ],
      'vta_ig'=>[
        'company_id'=>1,'campaign_id'=>1,
        'object'=>'instagram','entity_id'=>getenv('VTA_META_IG_USER_ID'),
        'enabled'=>false,'app_review_confirmed'=>false
      ]
    ]
  ]
]
```
Use actual company/campaign IDs, Meta entity IDs and secrets only on staging. The campaign ID MUST be ACTIVE.
The exact config registration in the Meta app needs a publicly reachable HTTPS callback with the following route:
`https://<RC6-STAGING-HOST>/api/index.php?route=webhooks/meta-messaging`

## Webhook handling
- GET with query `hub.mode=subscribe&hub.verify_token=...&hub.challenge=...` returns the literal challenge only when verify token is valid.
- POST requires exact raw request HMAC SHA-256 App Secret comparison with header `X-Hub-Signature-256`.
- Supports text-only inbound `object=page` or `object=instagram`, `entry[].id`, `entry[].messaging[].sender/recipient/message.mid/text`.
- Only account entries that map to exactly one configured company, approved subscription and active campaign are enqueued.
- Echoes, non-text attachments, delivery receipts, read receipts and events without valid message ID are ignored, **not falsely displayed as received conversations**.
- Idempotency is scoped by tenant + Meta Page/IG source + event key, so retries cannot create duplicate conversation messages.
- Receiver acknowledges only after durable insertion. Queuing errors return 503 to permit Meta retry. No token, person data or raw request logged.

## Processing and UI
- Worker `php api/bin/meta-inbox-worker.php` is **NOOP by default**.
- To process staging verified inbox data, set `VTA_ALLOW_META_INBOUND_PROCESSING=I_APPROVE_STAGING_META_INBOX` and run `--process` under allowed staging host and reviewed private config. It performs **NO Graph API outbound send**.
- Worker claims each event, ingests through existing `WebsiteInbox::ingest`, records conversation id. Retries transient failures only up to the configured cap; cases requiring staff attention become FAILED.
- Unified Inbox displays Website / Messenger / Instagram source chips from trusted source codes.
- Website staff replies continue to use the first-party website relay; Meta source never exposes website-relay send. Meta conversation can save a staff internal reply draft or transfer lead to Sales after collecting validated contact/guest details.
- Lead source for Meta conversation is `SOCIAL_DM`, with attribution `VERIFIED_META_DM` and its original source code. Website leads remain `WEB_CHAT`.

## Safety / current limits
- No OAuth UI, token lifecycle management, live webhook subscriptions, Messenger/Instagram API outbound replies, read receipts, chat media or WhatsApp Cloud API.
- Do not interpret `CONFIGURED_NOT_LIVE_VERIFIED` as connected/working.
- Raw guest text is PII; limit retention, protect database backups and restrict access via `lead.view`.
- Existing Webhook Center shows website journal events separately; Meta status available in authenticated `GET marketing/meta-inbox`. Future dashboard can aggregate counts.
- Validate Meta app `entry[].messaging` shapes, versioned subscription payloads, account association and error/reporting with real app test users on staging before claiming live compatibility.
- Maintain customer consent/privacy notices. Never auto-merge contacts by display name or platform ID.

## Quality gates
- CI PHP/JS syntax and MariaDB integration: signed challenge, HMAC tamper, batch routing, echo ignored, tenant isolation, durable replay, worker insert, source-specific Lead Hub attribution.
- Staging: curl handshake, signed test webhook from authorized Meta app, no false sends, website chat regression, multisource UX on PC and mobile.
- API retries/failures and company permission review required before merge.
- No reset or migration against production.
 
## References
- Meta Messenger API official collection: https://www.postman.com/meta/messenger-platform-api/
- Meta Instagram API official collection: https://www.postman.com/meta/instagram/
- Graph API Webhooks overview: https://developers.facebook.com/docs/graph-api/webhooks/
