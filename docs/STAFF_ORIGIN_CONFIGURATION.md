# Browser-facing origins for private voucher mutations

Private voucher POST/DELETE endpoints require an explicit, exact trusted Origin, in addition to a real staff session and the appropriate role. A missing/null Origin and a cross-site Sec-Fetch-Site header remain blocked. Host and Forwarded headers cannot add trusted origins.

The canonical browser-facing origin is taken from `NEXT_PUBLIC_SITE_URL`. Set this to the application's HTTPS origin in production, such as `https://bookings.example.com`. Optional `ADDITIONAL_STAFF_ORIGINS` contains comma-separated exact origins under your control; leave it empty unless an additional origin is deliberately supported. Do not include wildcards, paths, credentials, query strings or fragments. Non-HTTPS exceptions are limited to exact loopback origins and must still be explicitly configured.

CI starts the production application directly on `http://127.0.0.1:3000`, while the canonical site URL is `https://ci.example.invalid`. CI therefore explicitly permits that loopback origin. This does not disable origin checking or change test expectations. The same guard must accept the configured browser origin even when a reverse proxy or framework normalizes the internal request URL to another hostname. Tests verify that missing/untrusted origins, domain-prefix tricks and forged Host/Forwarded headers remain rejected.

For guest voucher links, the target URL continues to use the canonical HTTPS origin only. Additional staff origins do not change where guest links point, and cannot be set by an HTTP request.

These stricter rules currently cover voucher issuance and revocation. Existing non-voucher staff mutations still have their prior same-origin helper; do not infer that their full CSRF review is complete.
