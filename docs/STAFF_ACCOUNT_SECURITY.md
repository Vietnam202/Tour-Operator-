# Staff account and session security

## Staff workflow

Open **Account security** in the shared admin account navigation, or visit `/admin/security`. An active staff session is required for account data. Staff can inspect their own active session issue/expiry times, sign out all other sessions, or sign out everywhere. The latter also clears the current browser cookie and returns to the login page. Neither operation affects another staff account. The application does not invent device names, IP locations or last-active timestamps it has not recorded. Revocation does not change the password or prevent a later valid login.

The session list returns only IDs, issue/expiry times and the current-session flag. Raw tokens, token hashes and password hashes are not returned. Revocation locks the staff account and revalidates the caller inside the transaction. Login uses the same account-lock order and rechecks account activity and password hash before issuing a session. A retry of 'other sessions' safely reports zero when no sessions remain to revoke. Repeating 'all sessions' with the now-invalid cookie returns 401.

## Login and cookie behavior

Login accepts an application/json object containing only email and password. Request bodies are bounded to 8 KiB while reading the stream, even without Content-Length. Email and password sizes are bounded before password derivation. Passwords are not truncated. Existing salt:scrypt password hashes remain compatible. The app uses asynchronous scrypt and the same password-derivation path for unknown users or malformed stored hashes; credential failures return the same message for incorrect, unknown, disabled and malformed-hash accounts. This reduces obvious enumeration discrepancies; it is not a claim of perfectly identical response timing.

A successful login issues a new random 32-byte session secret. Only its SHA-256 digest is stored. Logging in again revokes the presented valid session for the same user and removes that user's expired sessions. Cookies are host-only, HttpOnly, Secure in production, SameSite=Lax, scoped to `/`, and expire after 12 hours. Invalid encodings, malformed token shapes and duplicate session cookies are rejected without decoding exceptions. Logout is idempotent; database errors are not reported as successful server-side revocation.

Sign-in has per-process limits of 8 attempts per normalized email per 5 minutes and 80 requests reaching the login throttle per minute in aggregate. Buckets store email digests, expire, and are capped. Forwarded IP headers are not used for this login throttle. These are limited safeguards, not a shared production rate limiter; process restarts reset them and multiple instances have independent limits. A deliberate attacker can also temporarily exhaust an account's allowance. An edge/WAF or shared Redis/KV limiter, monitored tuning, and request timeouts are still required.

## Origin enforcement and compatibility change

`lib/csrf.ts` now uses the same explicitly configured trusted-origin policy as private voucher mutations. This applies to existing staff POST/PATCH/PUT/DELETE handlers that already call the helper, including login/logout, bookings, tasks, supplier confirmation, inventory, payments, expenses and payables. Missing/null/untrusted Origin and cross-site Fetch Metadata are rejected. Host, Forwarded and X-Forwarded-Host do not add trusted origins. Staff session revocation uses the same guard. Public booking and provider webhook routes are not browser-admin mutations and retain their separate policies.

Set `NEXT_PUBLIC_SITE_URL` to the canonical browser-facing HTTPS origin before deployment. `ADDITIONAL_STAFF_ORIGINS` can list exact origins under your control; do not allow wildcards, credentials, paths or untrusted preview domains. Local development/CI origins must also be explicitly configured. Check reverse-proxy forwarding behavior before rollout. An API script performing staff writes must supply a configured Origin as well as its credentials. Origin is a browser CSRF defense, not authentication of a non-browser client.

The legacy `x-admin-key` path is now OFF unless `ENABLE_LEGACY_ADMIN_API_KEY=true` is explicitly set and a key of at least 32 characters is configured. The presence of ADMIN_API_KEY alone is not sufficient. Invalid, expired, inactive or ambiguous session cookies never fall back to that administrator identity. Voucher issuance and session controls always require real staff sessions, even during an opted-in legacy migration. Do not enable the compatibility mode in normal production operation.

## Verification and remaining gates

The staff integration suite exercises real login, session cookies, expiry, invalid encodings, rotation, own-account listing, cross-account isolation, both revocation modes, account throttling with spoofed forwarded addresses, and the shared origin guard across staff mutation routes. It runs only against the disposable local hca_ci database and production HTTP server; no customer database is allowed. Existing booking, concurrent confirmation and voucher tests continue to run.

This release adds no schema migration and sends no email, WhatsApp message or payment. It does not implement MFA, password reset delivery, a distributed limiter, browser E2E, immutable staff-login audit events, or a full security assessment. Public quote/booking rate limiting still uses its existing separate limiter and needs its own trusted-proxy review. Dependency locking, runtime support, payment reconciliation and deployment/provider review remain release gates. Do not interpret a green build as authorization to enable real payments.

Design references: OWASP Authentication Cheat Sheet and OWASP Cross-Site Request Forgery Prevention Cheat Sheet. No claim of formal OWASP certification is made.
