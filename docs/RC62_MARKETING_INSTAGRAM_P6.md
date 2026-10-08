# RC6.2 Marketing P6 — Instagram single-JPEG Publishing

**Status:** GitHub draft PR, stacked after P0–P5. NO production, staging host deployment, real API POST or account authorization has been performed.

## Capabilities
- Reuse existing Marketing Studio `APPROVED` channel-specific posts and P5 publishing queue. Facebook Page text publishing remains unchanged.
- Add one Instagram Professional Business/Creator account through **Instagram API with Facebook Login**, using a linked Facebook Page.
- Instagram single-photo feed post requires a **public HTTPS .jpg/.jpeg URL** from an explicitly allowed media hostname, licensing/rights note, caption max 2,200 bytes, format POST, channel Instagram.
- P5 queue acquires immutable caption/image URL fingerprint and separately reviewed job. A second user must approve.
- P6 worker executes `POST /{ig-user-id}/media` → GET container status until FINISHED (limited polls) → `POST /{ig-user-id}/media_publish`.
- Store container ID in database **before** `media_publish`, never print tokens or full Meta errors.
- Ambiguous status, network failures, worker interruption: `UNCERTAIN` and **no automatic retry**, because replay may duplicate public posts.
- No in-browser posting. Existing PHP CLI worker remains NOOP by default and strict staging/test RuntimeGuard applies.
- Read-only `php api/bin/meta-account-check.php --verify --alias=vta-ig-main` verifies the configured account ID against Meta without posting; the process prints no token.

## Configure server privately — never commit credentials
```php
'integrations'=>[
  'social_publishing'=>[
    'enabled'=>false,  // keep OFF until explicit staging authorization
    'accounts'=>[
      'vta-ig-main'=>[
        'company_id'=>1,
        'provider'=>'INSTAGRAM_BUSINESS',
        'name'=>'VTA Instagram',
        'login_type'=>'FACEBOOK', // Instagram API with Facebook Login
        'ig_user_id'=>getenv('VTA_IG_USER_ID'),
        'page_id'=>getenv('VTA_IG_LINKED_PAGE_ID'),
        'access_token'=>getenv('VTA_IG_PAGE_ACCESS_TOKEN'),
        'graph_version'=>'v26.0', // verify supported Graph version before enabling
        'allowed_media_hosts'=>['media.vietnamtraveladvisor.com.vn'],
        'permissions'=>['instagram_basic','instagram_content_publish','pages_read_engagement','pages_show_list'],
        'app_review_confirmed'=>false,
        'enabled'=>false
      ]
    ]
  ]
]
```
This example media hostname is illustrative. **Only add a host after verifying you control it and serve publicly reachable, direct JPEG files there.** The Graph API fetches the media URL: confirm content-type `image/jpeg`, URL availability, image dimensions and media permissions with a manual staging check.

## Authentication and actual account connection
The account must be an **Instagram Professional account**, linked to the configured Facebook Page for this Facebook Login flow. Meta permissions and app review/advanced access vary by app/account relationship. The private token must be created using Meta's official authorization workflow by an authorized administrator, renewed/rotated securely, and checked with the read-only CLI.
**No self-service OAuth login button, token refresh automation, or remote connection has been implemented here.** UI indicates only configured readiness; it does not claim the token has been live verified.

## Migration and staging sequence
1. Back up a real staging copy of MariaDB and review migrations P0 through P5. Migrate **040_instagram_publishing.sql** after 039.
2. Configure private account alias and permitted media host. Start disabled.
3. Confirm Meta app product and publishing permissions, Page / Instagram account association and ownership, content usage rights, relevant Graph version and account publishing availability.
4. Read-only CLI verification can use `VTA_VERIFY_COMPANY_ID=<company_id> php api/bin/meta-account-check.php --verify --alias=vta-ig-main` only when config enabled for staging.
5. Test Publishing tab on desktop/mobile, pick approved Instagram content and account, schedule using **Vietnam UTC+7** (explicit +07:00 conversion), approve as a different user, verify queued state.
6. `php api/bin/social-publisher.php` always NOOP without `--send`. Actual provider posting additionally requires `VTA_ALLOW_PROVIDER_POSTING=I_ACKNOWLEDGE_REAL_POSTS` and private enabled settings. **Do not turn this on until explicit business approval** and tests on a dedicated permitted test account.
7. Verify actual provider ID, delivery state, container status, failed/unknown handling, quota and rate limits. No automatic resend.

## Security and limitations
- Only known Meta Graph host and version+ID API paths are permitted; no arbitrary remote URLs in API calls.
- Allowed image host is an exact list, not suffix match. Rejects HTTP, credentialed URLs, ports, URL query tokens, IP hosts, PNG and unsupported video/reels.
- Two-person approval, immutable content hash, tenant/company scope and human-controlled scheduling.
- In-flight and uncertain requests cannot be cancelled/retried blindly. Investigate provider post status first.
- No token in front-end, database, CI fixtures other than fake token, API logs or GitHub.
- No actual media upload, alt-text UI, Instagram Story/Reel/carousel, comments/messages or WhatsApp Cloud API in P6.
- Page Publishing Authorization (PPA), API rate limits and Meta policy requirements must be confirmed against official documentation before live staging.

## Official source
- Meta official Instagram API Postman: https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api
- Meta official Pages API: https://developers.facebook.com/docs/pages-api/posts/

## Automated test
GitHub Actions `.github/workflows/marketing-instagram-p6.yml` runs PHP/JS syntax, Meta endpoint/host validation, P5 Facebook MariaDB regression, and P6 staged image container + media_publish with a **fake transport** (no real requests).
