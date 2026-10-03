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
