> Historical v2.x reference only. For this v3 staging candidate, follow V3_STAGING_UPGRADE.md and V3_AUDIT.md instead of the deployment instructions below.

# VTA v2.4 — Go-Live Checklist

## Server
- [ ] Subdomain VTA v2 riêng hoạt động qua HTTPS.
- [ ] PHP 8.2 hoặc compatible.
- [ ] MariaDB/MySQL database riêng.
- [ ] VTA private config nằm ngoài public_html.
- [ ] Private document folder không public.

## Core
- [ ] Admin login PASS.
- [ ] Supplier create/edit PASS.
- [ ] Supplier document upload/download PASS.
- [ ] Draft → Approved Rate PASS.
- [ ] Rate source traceability PASS.
- [ ] Customer/Agent/Inquiry PASS.
- [ ] Quote Info → Schedule → Cost → Price → Send PASS.
- [ ] Sent Quote snapshot/history PASS.
- [ ] Confirmed Quote → Master Booking PASS.
- [ ] Guests/Flights/Services PASS.
- [ ] Supplier Orders generate as DRAFT PASS.
- [ ] Send Supplier Order → REQUESTED PASS.
- [ ] Partial/Full Supplier Confirmation PASS.
- [ ] Planned/Confirmed/Actual Cost PASS.
- [ ] Customer AR/Payment PASS.
- [ ] Supplier AP/Payment PASS.
- [ ] Expected/Forecast/Actual Profit PASS.
- [ ] Customer-safe exports do not contain internal cost/margin/profit PASS.

## Operations
- [ ] TODAY task list PASS.
- [ ] Departure readiness PASS.
- [ ] Critical blocker visible PASS.
- [ ] Cron worker PASS.

## Backup / Recovery
- [ ] Database backup created.
- [ ] Private documents backup created.
- [ ] Restore procedure documented/tested.

## Cutover
- [ ] One real booking completed end-to-end.
- [ ] User acceptance PASS.
- [ ] V1.39 backup retained.
- [ ] Production DNS/domain cutover plan confirmed.
