# VTA RC6.2 Marketing P9 — Meta Connection Center / Token Health

**Stage:** isolated GitHub draft PR, built on P8. No accounts connected, OAuth flow, provider API calls or database migrations executed on VTA hosting.

## Delivered
- Meta Connection Center: new Marketing > Connections tab with tenant-isolated configured Facebook Messenger, Instagram DM, Facebook Page publishing and Instagram publishing accounts.
- Read-only health checks via Meta Graph **debug_token** and exact Page/IG entity identity, including: correct Meta App ID, provider `is_valid`, scopes, token expiry and configured account identity. A green check means **read-only verification**, not verified Meta app review, subscription, or permission to send messages.
- Backend only, private Meta credentials. No passwords/access tokens/app secrets sent to browser or written into MariaDB, Git or application logs. Metadata stored: product/alias, entity, timestamp, status, expiry and granted scopes.
- A guarded **staging-only opt-in CLI** runs actual read-only checks; the HTTP UI can refresh stored status but cannot trigger provider API traffic or accidentally send messages.
- Optional `enforce_messenger_verified=true` (recommended before any real Page messaging) blocks P8 Messenger replies if no VERIFIED health check in the past 12 hours. The existing separate P8 window, Page token, Page task, Meta scope and manual-only send rules stay in place.
- No changes to public website, pricing or Sales/Operations schema beyond a diagnostic table.

## Config and CLI
Add to the existing **private** `VTA_CONFIG_FILE` in staging only:
```php
'integrations'=>[
  'meta_connection_check'=>[
    'graph_version'=>'v26.0', // verify active version with Meta
    'app_id'=>getenv('VTA_META_APP_ID'),
    'app_secret'=>getenv('VTA_META_APP_SECRET'),
    'enforce_messenger_verified'=>true
  ],
  // Existing meta_inbox.accounts and social_publishing.accounts
  // retain Page/IG IDs and private access tokens already required by P7/P8/P5/P6.
]
```
Do not commit actual credentials. Never copy Meta access tokens into ChatGPT, GitHub issues, email or browser UI.

**Before calling Meta:** backup staging DB, apply additive migrations 023–025, 037–042 then **043_meta_connection_health.sql**. Check env is staging/testing and RuntimeGuard host allowlisted.

Default command `php api/bin/meta-connection-check.php` is **NOOP**. For one authorized staging Page only:
```bash
VTA_ALLOW_META_CONNECTION_CHECK=STAGING_READ_ONLY \
  php api/bin/meta-connection-check.php --check --company=1 \
  --alias=vta_page --product=MESSENGER
```
IDs are examples; substitute the actual tenant and alias from your private configuration. This command checks only, never subscribes, publishes or sends messages.

## API / UI
- `GET api/index.php?route=marketing/meta-connections` under normal VTA session auth and `lead.view` or `campaign.manage`; no POST available.
- All outgoing verification HTTP requests go to fixed `https://graph.facebook.com/vXX.X` with TLS verification, redirects disabled and strictly constrained Graph paths.
- Status values: VERIFIED, INVALID, MISSING_PERMISSIONS, EXPIRED, NOT_VERIFIED and NEVER_CHECKED. Checked time is distinct from app review and token issue date.
- A token might change without the diagnostic record changing. Freshness is at most 12 hours; a new check must confirm active authorization before Messenger staff replies when enforcement is on.
- Token expiration from Meta `expires_at=0` / absent is treated as not provided; it **does not** mean unlimited authorization.

## Limitations
- **No end-user OAuth connect button, Facebook Login callback, credential vault, token rotation or Meta subscription management** in P9. These require explicit secure app setup, valid redirect URIs, consent, secure storage, and Meta business authorization before enabling.
- Existing Meta Inbox / Social Publishing accounts are defined in private config; P9 observes their token health but does not activate them.
- Real API response compatibility and permissions must be tested with an authorized Meta test Page/IG Professional account. Mocked CI proves logic only.
- The diagnostics are not a replacement for Meta App Review or user consent and do not grant extra `pages_messaging` / `instagram_manage_messages` / `instagram_content_publish` privileges.
- Provider token is part of the documented `debug_token?input_token=...` query sent **only server-to-Meta**. Redact URL query in APM/proxy logs. Never log transport URLs or raw provider responses.
- Instagram DM reply remains disabled from P8 until separate official API adapter and permissions are implemented.
- Before deploying, verify browser/mobile rendering, RBAC, staging deployment on backed-up DB and Meta API with a permitted test account.

## Sources
- Meta official Instagram API with Facebook Login: https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api
- Meta official Messenger Platform API: https://www.postman.com/meta/messenger-platform-api/documentation/iyp204x/messenger-platform-api
- Meta official debug_token example: https://www.postman.com/meta/whatsapp-business-platform/request/i1mz7w8/debug-token
