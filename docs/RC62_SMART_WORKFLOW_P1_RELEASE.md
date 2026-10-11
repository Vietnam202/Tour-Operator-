# RC6.2 Smart Workflow P1 — implementation & release gate

**Scope:** Smart Search, Smart Alerts, Quick Send; no UI redesign, WordPress dependency, database migration, supplier NET/profit exposure, or provider API sending. Stage on branch `codex/Vietnam/rc62-smart-workflow-p1-20261011` from current WP bridge integration branch; Draft PR only.

## Implemented

1. **Smart Search** uses the existing topbar search box, keyboard navigation (↑/↓/Enter/Esc), proper result grouping, loading debounce and stale-response rejection.
   - New GET `api/?route=smart/search&q=...` returns limited **safe fields** for Master Tour library, Quote/Tour file, Booking, Customer, Supplier.
   - Every SQL statement uses `company_id=?`, `Auth::can`, bounded results and bound query parameters. No rates, NET, profit, supplier contract terms, passports or costs returned.
   - Agency users with `b2b.portal` but not `b2b.admin` receive empty results even if roles are misconfigured.
   - Search now also indexes the existing Word-style `tour_library_programs` Master Tour table for holders of `tour_library.view`, `product.view` or `supplier.view` (matching the legacy library read policy). Opens the existing Kho chương trình screen with the selected title as search filter; does **not** deep-link to a private document editor.
2. **Smart Alerts** enhances the existing notification bell (no new dashboard) with authorized, read-only:
   - My OPEN/SNOOZED tasks due within 7 days and overdue;
   - bookings with HIGH/CRITICAL risk and upcoming travel within 30 days, requiring `operations.view`;
   - approved current-version supplier rates expiring within 30 days, requiring `rate.view`.
   - No new job/cron or auto changes; links open existing Operations, Booking or Supplier workspaces. Rate details are not returned.
3. **Quick Send** adds one compact topbar action for internal Sales:
   - Show only `SENT`, `CONFIRMED`, `SUPERSEDED` quote versions. Recheck latest version server-side.
   - Requires `sales.view`, `quote.send`, `proposal.send`; server `QuoteProposal::createLink` also requires an immutable sent visual proposal snapshot, approved configuration, tenant and quote read guard.
   - Requires explicit staff review checkbox. POST to existing audited endpoint `quote-versions/{version_id}/proposal/links`. The link expires (7, 14 or 30 days), can be revoked by existing API, and is customer-safe by existing Proposal snapshot design.
   - Creates **draft text only**, copy, native `mailto:` or manual `wa.me` composer; no automatic sending, delivery claim, CRM status change or client price recalculation.
   - Preview cannot create public proposal links, because the mock app does not provide a real immutable backend snapshot.

## Files

- `api/lib/SmartEnhancements.php` scoped GET routes.
- `api/bootstrap.php`, `api/index.php` route wiring **after session auth + CSRF**.
- `smart-enhancements.js`, `smart-enhancements.css` minimal topbar components.
- `app.js` one hook only, falls back to old search if the new script is unavailable.
- `index.html`, `preview.html`, `service-worker.js` versioned assets and PWA cache bump.
- `tests/vta-smart-enhancements-check.js` and `.github/workflows/rc62-smart-workflow.yml` syntax / static privacy contract gates.

## Mandatory staging UAT (not covered by static CI)

1. ADMIN and internal Sales: search exact/partial diacritics for correct Quote/Tour file, Booking, Customer and Supplier; browser back/forward and mobile controls.
2. Two real agency accounts with Common/Exclusive policies must receive **no internal result or alert**, including direct requests for smart routes, even with craft/suspect role combinations. Another company's quotes and booking names must not leak.
3. Alerts: create owned overdue task, imminent expiry and at-risk booking. Confirm badge, sorted list, destination navigation and prevention of alerts outside the user's permission scope. Existing `tasks/complete` workflow must remain unchanged.
4. Quick Send: verify user lacking `proposal.send` cannot create a public link even with direct POST; DRAFT/APPROVED-but-not-SENT must be rejected, valid SENT creates **one audited link**, link expiration/revocation succeeds, snapshot excludes NET and internal supplier data. Email and WhatsApp are *manual composition*, not verified delivery.
5. Regression: approved Itinerary → Smart Cost independent 3★/4★/5★ hotel & cruise with 6 paying + 1 FOC → Price → customer-safe Proposal DOCX/PDF → Booking; no migration; browser PC/mobile and PWA offline asset update.
6. Test against disposable/staging DB with backup/restore before production preparation. PR dependency chain and existing open #42/#43/#44/#48/#49 require reconciling; no production CyberPanel upload or merge without owner sign-off.

**Known omissions:** no full-text search within day-by-day itinerary paragraphs, no alerts for supplier booking status beyond existing task/booking-risk data, no live provider sending, no autosave / version history / AI automation in this P1 PR. These are separate, higher-risk follow-ups.
