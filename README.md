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
3. Run `npm ci` to install the exact dependency tree committed in `package-lock.json`. Use Node 20, matching CI and Docker.
4. Run `npm run db:migrate:dev -- --name init` for a new development database.
5. Run `npm run db:seed`.
6. Run `npm run dev`.

## Required environment variables

- `DATABASE_URL` — PostgreSQL connection string.
- `ADMIN_API_KEY` — optional legacy migration fallback for admin APIs; remove after staff-session rollout is complete.
- `NEXT_PUBLIC_SITE_URL` — canonical application URL.
- `BOOKING_WEBHOOK_URL` — optional automation endpoint for booking/payment events.
- `PAYMENT_WEBHOOK_SECRET` — shared secret for the generic payment webhook until a provider-specific signature verifier is implemented.

## Production database

Use committed Prisma migrations and run:

`npm run db:migrate`

Do not use `prisma db push` as the production deployment workflow.

## Reproducible installs

`package-lock.json` is committed and is the source of truth for dependency resolution. CI, Docker builds, and deployment setup use `npm ci`; if `package.json` and `package-lock.json` disagree, installation must fail instead of rewriting the lockfile. When intentionally changing dependencies, update both files with Node 20 and commit them together.

## Verification

`npm run typecheck`

`npm run build`

GitHub Actions installs with `npm ci`, validates and generates Prisma, checks migration/schema agreement, runs TypeScript checking and a production build, then runs the integration and smoke suites for pushes to `main` and pull requests.

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

The current public API rate limiter is process-memory based. Replace it with a shared Redis/KV limiter for serverless or multi-instance deployment. Configure HTTPS, security headers, backups, monitoring and error reporting before launch.

Seed cruise content, prices and reviews are demo data and must be replaced with verified operator inventory before going live.
