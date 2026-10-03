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
- `ADMIN_API_KEY` — optional legacy migration fallback for admin APIs; remove after staff-session rollout is complete.
- `NEXT_PUBLIC_SITE_URL` — canonical application URL.
- `BOOKING_WEBHOOK_URL` — optional automation endpoint for booking/payment events.
- `PAYMENT_WEBHOOK_SECRET` — development/integration-only secret for the `local`/`ci` payment webhook adapters. It is not a production payment-provider credential and cannot enable a generic production verifier.

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

The data model supports deposits, balances, refunds, idempotent transactions, expiring customer payment requests, supplier payables and booking expenses. No real payment provider is connected yet.

Inbound payment webhooks are fail-closed. The route resolves `{provider}` through `lib/payment-webhook-verifiers.ts` before reading the request body or opening a database transaction. Unknown providers are rejected without creating `WebhookEvent`, `PaymentTransaction`, or notification-outbox rows. The shared-secret adapters are restricted to disposable CI (`ci`) or non-production local development (`local`); production cannot fall back to the generic secret.

Before accepting card payments, implement a provider-specific adapter that verifies the provider's native signature against the exact raw request bytes (plus provider timestamp/replay requirements), register only that adapter in the production verifier registry, add provider fixtures/tests for valid, invalid, and replayed events, and configure its credentials in the deployment secret store. Checkout/session creation must also be implemented for the selected provider.

## Production notes

The current public API rate limiter is process-memory based. Replace it with a shared Redis/KV limiter for serverless or multi-instance deployment. Configure HTTPS, security headers, backups, monitoring and error reporting before launch.

Seed cruise content, prices and reviews are demo data and must be replaced with verified operator inventory before going live.
