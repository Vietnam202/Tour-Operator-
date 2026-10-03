# Halong Cruise Advisor

English-first cruise booking MVP for international travellers visiting Halong Bay and Lan Ha Bay.

## Stack

- Next.js 15 + React 19 + TypeScript
- PostgreSQL + Prisma
- Server-side cruise pricing and inventory confirmation
- Provider-agnostic payment ledger, payment requests and webhook model

## Core flow

Cruise inventory → server quote → booking enquiry → CRM assignment/follow-up → operations confirmation → automated supplier/payment/passport/pre-departure tasks → inventory reservation → deposit/balance request → payment webhook → supplier payable/expenses → travel voucher → finance reporting.

## Local setup

1. Copy `.env.example` to `.env`.
2. Set a PostgreSQL `DATABASE_URL`.
3. Run `npm install`.
4. Run `npm run db:migrate:dev -- --name init` for a new development database.
5. Run `npm run db:seed`.
6. Run `npm run dev`.

## Required environment variables

- `DATABASE_URL` — PostgreSQL connection string.
- `REDIS_URL` — shared Redis connection string; required by production rate limiting.
- `TRUSTED_PROXY_HOPS` — number of trusted reverse proxies that append to `X-Forwarded-For`; required in production.
- `RATE_LIMIT_BACKEND` — optional local/test override. Defaults to memory outside production; production rejects the memory backend.
- `ADMIN_API_KEY` — optional legacy migration fallback for admin APIs; remove after staff-session rollout is complete.
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

- `/admin` — booking CRM, assignment, follow-up and status.
- `/admin/operations` — daily exception queue for overdue work and upcoming departures.
- `/admin/bookings/[id]` — end-to-end booking operations workspace, tasks and audit timeline.
- `/admin/inventory` — suppliers, cruises, cabins, departures, selling rates and contract net costs.
- `/admin/finance` — revenue, quoted/actual margin, supplier payable and guest outstanding balances.

Admin uses staff sessions with role-based access control. `ADMIN_API_KEY` remains only as a temporary legacy fallback and should be removed before public production launch.

## Payments

The data model supports deposits, balances, refunds, idempotent transactions, expiring customer payment requests, supplier payables and booking expenses. No real payment provider is connected yet. Provider-specific signature verification and checkout session creation must be implemented before accepting card payments.

## Production notes

Quote, booking and staff-login throttles use an atomic shared Redis limiter in production. Production requires `REDIS_URL` and never falls back to process memory if Redis is missing or unavailable. Local/test defaults to the in-memory adapter unless `RATE_LIMIT_BACKEND=redis` is selected.

Client IPs are resolved using a trusted-proxy policy rather than trusting the left-most `X-Forwarded-For` value. Set `TRUSTED_PROXY_HOPS` to the exact number of reverse proxies in front of the application, configure those proxies to append/overwrite forwarding headers safely, and do not expose the application origin directly. Configure HTTPS, security headers, backups, monitoring and error reporting before launch.

Seed cruise content, prices and reviews are demo data and must be replaced with verified operator inventory before going live.
