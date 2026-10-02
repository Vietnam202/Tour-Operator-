# Private vouchers and concurrent booking confirmation

## Scope and database change

This release adds `VoucherGrant` through migration `20261002000600_voucher_grants`. Apply all committed migrations before starting this application revision. Do not apply these commands to a live database without its normal backup and release procedure. No existing migration is rewritten and no guest data is migrated into publicly usable tokens.

## Staff workflow

Open **Private voucher links** from a booking workspace, or `/admin/bookings/[id]/voucher`. Staff can inspect readiness and recent grant history. ADMIN, OPERATIONS and SALES can issue or revoke customer links; FINANCE has read-only access. A real active staff session is required. A legacy shared admin API key cannot issue voucher credentials.

Issue a link only after confirming the booking, the verified cabin allocation, the operator reservation reference and the required payment. If an agreed deposit is stored, that deposit must have been received. Without a deposit plan the entire quoted total is required. Refund states and ended trips are blocked pending operational review. Eligibility is evaluated again on each read, not just at issuance.

A private link lasts up to 72 hours, capped at one day after the scheduled trip end. Issuing another link revokes older grants in the same transaction. Only a SHA-256 digest of the 32-byte random token is stored. The raw link is displayed once and cannot be recovered from grant history or activity records. Share it only with the verified guest. It is a bearer credential; anyone who receives a copy can use it while valid.

The token is carried in the URL fragment, removed from the address bar by the voucher page and submitted in the Authorization header to the voucher API. It is not stored in localStorage, sessionStorage or cookies. Refreshing requires reopening the original private link. Infrastructure must redact Authorization headers and must not log clipboard contents or front-end error state. Pages and API responses use no-referrer and no-index headers; sensitive API responses use private/no-store.

Reference-only URLs no longer disclose guest vouchers. Existing staff links open an authenticated preview and link-management entry point. Existing guest reference-only links must be replaced with a newly issued private link. Payment notifications no longer contain reference-only guest URLs; they indicate that staff issuance is required. This release does not send email or WhatsApp messages automatically.

## Transaction and inventory rules

Booking mutation obtains a PostgreSQL row lock on the booking before reading its commitment flag, then locks its departure. Cabin reservation, booking status, activity and automated checklist generation are committed together. Repeating confirmation does not reserve another cabin or create another automated checklist. Different bookings competing for the last cabin cannot both confirm through this path.

A future dated departure belonging to the booking's cruise, matching date/duration and a verified numeric cabin allocation are required. Unknown allocation is not unlimited inventory. One enquiry still reserves one cabin; multi-cabin allocation is outside this release.

Manual task writes and supplier confirmation use the same booking lock. A task's booking association is checked before mutation, and the activity entry is in the same transaction. Repeating an unchanged task status preserves completedAt and adds no duplicate status activity. Manual tasks of the same type are still permitted intentionally.

Cancellation revokes customer voucher grants and cancels open tasks atomically. It does not restore supplier inventory or refund money. Cancelled bookings cannot be reopened via a status toggle; a new enquiry and supplier-release review are required. Withdrawing supplier confirmation revokes current grants, and those grants do not reactivate after a later confirmation.

## Verification and limits

`npm run test:integration` executes the existing published-inventory tests plus the operations/security suite against the production HTTP server and the disposable local `hca_ci` PostgreSQL database. Cases cover simultaneous confirmations, last-cabin contention, unknown allocation, mismatched dates, role enforcement, task association, token hashing, expiry, rotation, revocation, payment eligibility, cancellation, staff sessions and public response fields. The test entry point refuses non-local/customer databases.

These are HTTP/database integration tests, not browser E2E or a full penetration test. Generic payment webhook reconciliation, supplier payment accounting, money precision, distributed rate limiting, staff MFA, durable notification delivery and browser regression coverage remain separate release gates. The workflow still needs a committed dependency lockfile and a supported-runtime upgrade before production. No payment provider is enabled and no live deployment is performed by this release.
