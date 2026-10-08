# VTA RC6.2 — Marketing Social Publishing P5

## Status and implementation boundary
**Draft branch. Not deployed. No real provider posts made.**

P5 builds on P0–P4. It adds a first-party publishing queue for **Facebook Page text-only feed posts** (one approved Marketing Studio content record to one Page per job). No SO9, Chatwoot, Make/n8n, or third-party social publisher.

**Not implemented**: automatic Facebook/Instagram OAuth connection or token renewal, IG photo/video/reel publishing, TikTok/YouTube, social inbox sync, WhatsApp messaging, paid ads, media upload, auto replies. WhatsApp is a messaging platform, not a Page feed publishing destination.

## Workflow
1. Admin provisions a Meta Facebook Page identity in private config (below). Tokens do not go to browser/database/repository.
2. Marketing Studio continues to produce channel-specific content: DRAFT → PENDING → APPROVED.
3. Publishing tab selects a previously APPROVED Facebook POST with no media and one configured Page, then chooses local Vietnam date/time.
4. Create a `DRAFT` posting job. A different user with permission `marketing.approve` checks and approves the job; the creator cannot self-approve.
5. CLI worker (never HTTP endpoint) claims due APPROVED jobs, rechecks account and immutable content fingerprint before dispatch, posts Page feed message to Graph API, records provider post ID.
6. Any ambiguous failure enters `UNCERTAIN`, **never retried automatically** (manual provider reconciliation is required). Interrupted SENDING >15m also marked UNCERTAIN.
7. Cancelling allowed only before the provider send begins.

## Staging configuration
The app refuses live dispatch unless:
- Existing strict staging/testing RuntimeGuard passes.
- Private `VTA_CONFIG_FILE` includes `integrations.social_publishing.enabled=true`.
- Every configured Page entry has `enabled=true`, exact company_id, page_id, access_token, required permissions (`pages_manage_posts`) and documented `app_review_confirmed=true`.
- Operator runs `php api/bin/social-publisher.php --send` under CLI with `VTA_ALLOW_PROVIDER_POSTING=I_ACKNOWLEDGE_REAL_POSTS`. No scheduled worker is installed or activated by this PR.

Private config shape (DO NOT commit real tokens):
```php
'integrations'=>[
  'social_publishing'=>[
    'enabled'=>false, // initial safe default
    'accounts'=>[
      'vta-fb-main'=>[
        'company_id'=>1, 'name'=>'VTA Facebook Page',
        'provider'=>'FACEBOOK_PAGE',
        'page_id'=>getenv('VTA_FB_PAGE_ID'),
        'access_token'=>getenv('VTA_FB_PAGE_TOKEN'),
        'permissions'=>['pages_manage_posts'],
        'app_review_confirmed'=>false,
        'enabled'=>false,
        'graph_version'=>'v26.0'
      ]
    ]
  ]
]
```
All credentials belong **outside webroot** and are loaded only into PHP. Do not use example company/page values as live IDs. Meta app review and Page access must be independently confirmed; this code does not grant permissions. Use a dedicated staging/test Page, never a live branded Page without explicit approval.

## API contracts
- `GET marketing/social-publishing`: account aliases/readiness and tenant-only jobs, requires `campaign.manage`. Never returns token.
- `POST marketing/social-publishing/create`: `{content_id, account_alias, scheduled_at: "2026-10-12T03:00:00Z", request_key}` requires `campaign.manage`. Starts DRAFT.
- `POST marketing/social-publishing/approve`: `{id}` requires `marketing.approve` and a second person.
- `POST marketing/social-publishing/cancel`: `{id}` requires `campaign.manage`. Only DRAFT/APPROVED.
- Staging-only worker: `php api/bin/social-publisher.php` is **NOOP**, even if jobs are due. Only explicit guarded `--send` uses Graph API.

Publishing result semantics:
- `PUBLISHED`: Page feed API confirmed an ID.
- `SENDING`: requested but not confirmed.
- `UNCERTAIN`: no reliable outcome; MUST reconcile provider-side before creating any new job.
- `BLOCKED`: the content or account readiness changed, or approval became invalid.
- `APPROVED`: internally scheduled, not evidence of publication.

## Security and staging QA
- Strict `company_id` on account listing, content, jobs and API actions.
- No personal/customer/supplier data in jobs; no media upload, rate/pricing export or unreviewed tour information.
- UI only queues/schedules. No browser network calls to Meta.
- HTTPS Graph API fixed host, version/page ID pattern enforced; Page token sent in POST body over TLS; no raw token/errors logged.
- No automatic repeat of ambiguous transport outcomes, including after process interruption.
- Restore point before migration 039. Verify migration on a **copy** of staging DB, never reset production.
- CI uses fake Page token/config and MariaDB; **no real social API requests**.
- Verify server account permissions, Meta app review, business verification, privacy, provider API changes and brand authorization before any live Page posting.
- Test desktop/mobile UI, scheduled local-to-UTC conversion, CSRF/RBAC, unknown results, Page quota and app scopes in staging.

## Official docs
- https://developers.facebook.com/docs/pages-api/posts/
- https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api
- https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api
