# Halong Cruise Advisor

English-first cruise booking MVP for international travellers visiting Halong Bay and Lan Ha Bay.

## Stack

- Next.js 15 + React 19 + TypeScript
- PostgreSQL + Prisma
- Server-side cruise pricing and inventory confirmation
- Provider-agnostic payment ledger, payment requests and webhook model

## Core flow

Cruise inventory → dynamic cruise page → server quote → booking enquiry → operations confirmation → inventory reservation → deposit/balance request → payment webhook → travel voucher.

## Local setup

1. Copy `.env.example` to `.env`.
2. Set a PostgreSQL `DATABASE_URL`.
3. Run `npm install`.
4. Run `npm run db:migrate:dev -- --name init` for a new development database.
5. Run `npm run db:seed`.
6. Run `npm run dev`.

## Required environment variables

- `DATABASE_URL` — PostgreSQL connection string.
- `ADMIN_API_KEY` — long random secret used by the MVP admin APIs.
- `NEXT_PUBLIC_SITE_URL` — canonical application URL.
- `BOOKING_WEBHOOK_URL` — optional automation endpoint for booking/payment events.
- `PAYMENT_WEBHOOK_SECRET` — shared secret for the generic payment webhook until a provider-specific signature verifier is implemented.

## Production database

Use committed Prisma migrations and run:

`npm run db:migrate`

Do not use `prisma db push` as the production deployment workflow.

## Verification

`npm run typecheck`

`npm run build`

GitHub Actions runs Prisma validation, TypeScript checking and a production build for pushes to `main` and pull requests.

## Admin

- `/admin` — booking enquiries, lead status and payment status.
- `/admin/inventory` — cruise, cabin, departure and rate management.

The current admin API-key mechanism is an MVP safeguard, not final staff authentication. Before exposing the admin publicly, replace it with authenticated staff sessions and role-based access control.

## Payments

The data model supports deposits, balances, refunds, idempotent transactions and expiring customer payment requests. No real payment provider is connected yet. Provider-specific signature verification and checkout session creation must be implemented before accepting card payments.

## Production notes

The current public API rate limiter is process-memory based. Replace it with a shared Redis/KV limiter for serverless or multi-instance deployment. Configure HTTPS, security headers, backups, monitoring and error reporting before launch.

Seed cruise content, prices and reviews are demo data and must be replaced with verified operator inventory before going live.
