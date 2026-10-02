# Browser-facing origins for staff mutations

Staff POST/PATCH/PUT/DELETE endpoints using `sameOrigin` now share the explicit trusted-origin policy with private voucher issuance and revocation. Valid credentials do not bypass this check. Missing/null Origin and cross-site Sec-Fetch-Site are blocked. Host and Forwarded headers cannot add trusted origins.

The canonical browser-facing origin comes from `NEXT_PUBLIC_SITE_URL`. Set it to the application's HTTPS origin in production. Optional `ADDITIONAL_STAFF_ORIGINS` contains comma-separated exact origins under your control; leave empty unless an additional origin is deliberately supported. Do not include wildcards, paths, credentials, query strings or fragments. HTTP exceptions are limited to exact loopback origins and must be explicitly configured too.

CI starts the production application on `http://127.0.0.1:3000` while its canonical site URL is `https://ci.example.invalid`. CI explicitly permits that loopback origin. This does not disable checking or change expected authorization. Tests reject missing/untrusted origins, prefix tricks, and forged Host/Forwarded headers.

Guest voucher links still target the canonical HTTPS origin only. Additional staff origins cannot change where guest links point and cannot be set by a request. Origin verification is not authentication for scripts: non-browser clients can set Origin, so the staff permission/session requirements still apply.

Before rolling out, verify the deployed browser origin is configured and the reverse proxy preserves it. This change deliberately rejects older scripts without Origin. API-key compatibility is separately opt-in via ENABLE_LEGACY_ADMIN_API_KEY; see STAFF_ACCOUNT_SECURITY.md. Public booking and payment-webhook routes retain their separate policies and are not covered by the browser-admin guard.
