# Staging / Production Runbook

## 1. Infrastructure
Provision PostgreSQL, an HTTPS application URL, and a secret store. Do not expose PostgreSQL publicly unless the provider requires controlled network access.

## 2. Required secrets
Set `DATABASE_URL` and `NEXT_PUBLIC_SITE_URL`. For the temporary migration period only, `ADMIN_API_KEY` may be set and must be at least 32 characters. Set `SEED_ADMIN_EMAIL` and `SEED_ADMIN_PASSWORD` only when bootstrapping the first staff account, then remove them from the runtime environment.

## 3. Database deployment
For a new empty database:

```sh
npm install
npx prisma generate
npm run db:migrate
npm run db:seed
```

Never run `prisma db push` against production.

If the database already contains schema/data created outside Prisma migrations, baseline it deliberately before running `migrate deploy`; do not apply the initial migration blindly.

## 4. Application verification
Before traffic:

```sh
npm run typecheck
npm run build
```

After deployment:
- `GET /api/live` must return HTTP 200. This verifies the process is alive.
- `GET /api/health` must return HTTP 200. This verifies PostgreSQL connectivity.
- Sign in at `/admin/login`.
- Confirm an Operations user cannot create payment requests.
- Create a test booking and confirm it. Verify supplier-confirmation, payment, passport and pre-departure tasks are created once only.
- Create a payment request and verify the PAYMENT task moves to `IN_PROGRESS`.
- Record a successful full payment event and verify the PAYMENT task becomes `DONE`.
- Confirm the supplier and verify the SUPPLIER_CONFIRMATION task becomes `DONE`.
- Verify `/admin/operations` shows overdue work/upcoming departures and `/admin/finance` shows supplier payable, expenses and actual/accrued profit.
- Verify the voucher remains unavailable until the required payment state is recorded.

## 5. Rollback
Application rollback: redeploy the previous known-good image/commit.
Database rollback: Prisma production migrations are forward-oriented. Take a database backup before schema changes and prepare an explicit corrective migration instead of editing an already-applied migration.

## 6. Before accepting real payments
Remove the legacy API-key fallback, use a shared Redis/KV rate limiter, configure provider-specific webhook signature verification, enable error monitoring, configure database backups, and verify transactional email delivery.

### Inbound payment webhook providers

The payment webhook endpoint is fail-closed: `/api/payments/webhook/{provider}` resolves a verifier before reading the request body or starting Prisma work. An unregistered provider is rejected and must not persist a webhook event, payment transaction, or notification-outbox event.

`PAYMENT_WEBHOOK_SECRET` is only for the disposable `ci` adapter and the non-production `local` adapter. Do not use it as a real provider secret. The `ci` adapter additionally requires `HCA_INTEGRATION_TESTS=1` and the local `/hca_ci` database; the `local` adapter is unavailable when `NODE_ENV=production`. There is intentionally no environment switch that enables a generic shared-secret verifier in production.

To add a real provider:

1. Implement a `PaymentWebhookVerifier` adapter in `lib/payment-webhook-verifiers.ts` (or a provider-specific module imported there).
2. Verify the provider's native signature over the exact raw request bytes before JSON parsing. Enforce its timestamp/replay rules and reject malformed or unverifiable input.
3. Add that provider explicitly to `productionVerifierRegistry`; never point a production entry at the generic shared-secret verifier.
4. Store provider signing credentials in the deployment secret store and document rotation. Do not persist signing secrets in webhook payloads, payment metadata, or outbox rows.
5. Add integration fixtures covering valid signatures, invalid signatures, malformed bodies, unknown providers, and replay/idempotency. Confirm rejected requests create no `WebhookEvent`, `PaymentTransaction`, or `NotificationOutbox` rows.
6. Run `npm run typecheck`, `npm run build`, and `npm run test:integration` against the disposable test database before enabling provider traffic.

Keep provider traffic disabled at the upstream dashboard/load balancer until the provider-specific adapter and checkout/session integration have passed staging verification.


## Notification outbox worker

Booking and payment HTTP handlers only persist notification events; they never depend on the external webhook being available.

Run one delivery batch:

```bash
npm run notifications:once
```

Run a long-lived worker:

```bash
npm run notifications:process
```

Inspect the queue before opening traffic and during incidents:

```bash
npm run notifications:status
```

The status command reports counts by state, due pending work, stale processing leases, and the age of the oldest pending event. A growing `duePending` count or old pending age indicates the worker is stopped or the destination is failing; any `FAILED` rows require operator inspection before requeueing.

The worker requires `BOOKING_WEBHOOK_URL` to use HTTPS in staging/production and rejects URLs with embedded credentials. Plain HTTP is accepted only for localhost integration/development targets. It claims due rows with PostgreSQL `FOR UPDATE SKIP LOCKED`, retries failures with exponential backoff, and marks successful rows as `DELIVERED`. Each HTTP attempt is bounded to 10 seconds by default; set `BOOKING_WEBHOOK_TIMEOUT_MS` to override it. Run at least one worker process in production. Multiple workers are supported.

Delivery is at-least-once across network ambiguity. Receivers should honor the stable `Idempotency-Key` header (and `X-Notification-Id`) so a webhook accepted upstream but followed by a lost response can be safely retried without duplicating side effects.

Do not place secrets, session tokens, authorization headers, cookies, or payment bearer tokens in notification payloads.

The long-running worker handles `SIGINT` and `SIGTERM` by finishing the current claimed batch and then disconnecting from PostgreSQL. Events that exhaust `maxAttempts` remain in `FAILED` for operator inspection. After fixing the destination or configuration, requeue them without creating duplicate rows:

```bash
npm run notifications:process -- --requeue-failed
```

Requeue resets delivery-attempt metadata but preserves each row's stable ID and idempotency key, so receivers can continue deduplicating retries.

### Outbox retention

Delivered notification rows are retained for 30 days by default and can be pruned independently of delivery processing:

```bash
npm run notifications:prune
npm run notifications:prune -- --retention-days=60
```

The prune command deletes only rows with `status = DELIVERED` whose `deliveredAt` is older than the retention cutoff. It never deletes `PENDING`, `PROCESSING`, or `FAILED` events. Keep failed events until an operator has inspected and either requeued or otherwise resolved them. Schedule pruning periodically (for example, daily) in the production scheduler rather than running it inside the delivery worker loop.

### Outbound webhook authentication

Set `BOOKING_WEBHOOK_SECRET` in the worker secret store to authenticate notification deliveries. When configured, every delivery includes:

- `x-webhook-timestamp`: Unix timestamp in seconds
- `x-webhook-signature`: `v1=<hex HMAC-SHA256>`

The signature input is the exact UTF-8 request body prefixed by the timestamp: `<timestamp>.<raw-body>`. Receivers should recompute the HMAC with `BOOKING_WEBHOOK_SECRET`, compare signatures using a timing-safe comparison, reject stale timestamps (for example, older than five minutes), and continue deduplicating by `idempotency-key` / `x-notification-id`.

The signing secret is read only from the worker environment at delivery time. It is never written to `NotificationOutbox.payload` or included in the request body. Rotate the secret through the deployment secret store; during a coordinated rotation, update receiver and worker together.


### Notification retry pacing

Failed outbound webhook deliveries use exponential backoff with bounded deterministic jitter to avoid synchronized retry bursts. A receiver may return `Retry-After` (delta-seconds or an HTTP date); the worker will not retry before that hint or its own backoff, whichever is later. Retry delays are capped at one hour, and terminal `maxAttempts` behavior is unchanged.


### Notification outbox monitoring endpoint

Authenticated staff with existing booking-read permission can query `GET /api/admin/notifications/outbox-status` for automation-friendly outbox health aggregates. The response is private/no-store and contains only status counts, due pending count, stale processing count, oldest pending age in seconds, and generation time. It intentionally excludes event payloads, event identifiers, lock owner values, and last-error text.

Use this endpoint from authenticated internal monitoring to alert on sustained pending age, any stale processing rows, or unexpected growth in FAILED rows. The existing `npm run notifications:status` CLI remains suitable for host-level diagnostics.
