# VTA RC6.2 Marketing P8 — Staff-initiated Messenger replies

**Status:** isolated draft PR; no token connected, no provider posts/messages sent, no staging DB migrations performed.

## Exact shipped scope
- Facebook Page Messenger **text RESPONSE** only, through the official Page Send API `/{page-id}/messages`.
- The existing Unified Inbox displays Messenger reply eligibility and a manual staff send form only when configured and inside the standard reply window. It shows QUEUED, SENDING, SENT (provider confirmed only), UNCERTAIN and BLOCKED.
- Instagram DMs remain **inbound-only and staff-draft-only**; Instagram Facebook Login and Instagram Login are separate permission/transport flows and must not be mixed.
- No Facebook/Instagram self-service OAuth. Administrator provisions official Page token privately; the UI shows configuration readiness, NOT verified provider authorization.
- No AI auto-send, attachments, remarketing broadcasts, message tags, human-agent extension, sponsored messages or off-window messages.

## Timing and privacy
Messenger standard response requires customer initiating message within approximately 24 hours. This implementation enforces a **23h50m safety margin** measured from the **signed Meta event timestamp**, not the time the webhook arrived or the worker processed it.
- If timestamp is missing or invalid, the window is unavailable by default.
- An old event or late webhook delivery cannot extend the response window.
- Revalidated both when staff queues message and immediately before worker dispatch.
- No request body may be sent if the conversation is CLOSED, belongs to another company, mapped account lacks `pages_messaging`, Page token is missing, or Page messaging approval/task checks are disabled.
- Staff replies use signed-in `lead.manage`, existing session and CSRF; no browser receives Page token.

## Deploy order
1. Review/backup staging DB; apply previously approved migrations **023–025, 037–041**, then **042_meta_messenger_replies.sql**. CI uses disposable MariaDB only.
2. In the private `VTA_CONFIG_FILE`, existing `integrations.meta_inbox.enabled` must be enabled for an authorized staging Page, with `verify_token` and `app_secret`. The account remains the same mapping used in P7.
3. Add to the **Page account only**, in private config (illustrative, NOT an active token):
```php
'vta_page'=>[
  'company_id'=>1,'campaign_id'=>1,
  'object'=>'page','entity_id'=>getenv('VTA_META_PAGE_ID'),
  'enabled'=>false,'app_review_confirmed'=>false,
  'messaging_enabled'=>false,
  'messaging_permission_approved'=>false,
  'message_task_confirmed'=>false,
  'permissions'=>['pages_messaging'],
  'page_access_token'=>getenv('VTA_META_PAGE_TOKEN'),
  'graph_version'=>'v26.0'
]
```
4. After Meta app and Page permissions have been independently verified, explicitly authorize staging integration on a controlled Page with test users.
5. Start inbound P7 worker in reviewed staging so Meta signed inbound text messages are ingested. For each staff Messenger thread, check last inbound timestamp and eligibility status.
6. P8 outbound worker `php api/bin/meta-reply-worker.php` is **NOOP** by default. Actual sending requires `--send`, strict staging RuntimeGuard, enabled private config and `VTA_ALLOW_META_REPLIES=I_APPROVE_REAL_MESSENGER_RESPONSES`. Do not install cron until explicit human acceptance testing.
7. Observe Meta provider message ID on success. In unknown transport errors, status is **UNCERTAIN**, and it will never automatically retry. Manually reconcile Page Inbox to prevent duplicate messages.

## API
- `GET marketing/meta-replies/status?conversation_id=<ID>`: status + can_send + reason (no secrets).
- `GET marketing/meta-replies/list?conversation_id=<ID>`: tenant-scoped sent/queued/blocked history.
- `POST marketing/meta-replies/send`: `{conversation_id,request_key,body}` authenticated + CSRF; creates QUEUED only. Text max 1000 bytes.
- Actual provider dispatch is CLI worker-only; HTTP handlers never send to Meta.
- Page message transport uses fixed https://graph.facebook.com/vXX.X/{page-id}/messages, Authorization Bearer private Page token, messaging_type RESPONSE.

## Gaps and next hardening
- **Meta OAuth Connection Center** and token rotation/revocation/expiry monitoring remain future work. No live connection has been made.
- **Instagram reply adapter** remains separate work. Facebook Login vs Instagram Login permissions/endpoint must be verified against the account configuration in use.
- No rate monitoring/24h conversation visual countdown, attachment, media replies or read receipts.
- Webhook timestamp integrity depends on HMAC-SHA256 app-secret validation by P7. Multi-recipient and malformed events remain ignored.
- Deployment requires a staging browser walk-through and real test Page API capability review.
- On-going token storage, webhook event retention, GDPR/privacy and audit compliance should be reviewed before live access.
- Migrations were designed additive and do not wipe business data.

## Official Meta references
- Messenger API official collection: https://www.postman.com/meta/messenger-platform-api/documentation/iyp204x/messenger-platform-api
- Meta Page message example: https://www.postman.com/meta/messenger-platform-api/folder/vilwbh4/send-api
- Instagram API (different authentication flows): https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api
