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
- Create a test booking, confirm it, create a payment request, and verify the voucher remains unavailable until a payment event is recorded.

## 5. Rollback
Application rollback: redeploy the previous known-good image/commit.
Database rollback: Prisma production migrations are forward-oriented. Take a database backup before schema changes and prepare an explicit corrective migration instead of editing an already-applied migration.

## 6. Before accepting real payments
Remove the legacy API-key fallback, use a shared Redis/KV rate limiter, configure provider-specific webhook signature verification, enable error monitoring, configure database backups, and verify transactional email delivery.
