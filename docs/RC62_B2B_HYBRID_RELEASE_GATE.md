# RC6.2 B2B Hybrid Distribution — implementation / staging gate

Base: PR #44 commit acf19d5076ad665a4d297313da2aea95bf6162db. Demo: VTA_RC62_B2B_Hybrid_Partner_Hub_Interactive_Demo.html (reference, not production authentication/source).
This change extends existing V5 Master Tour and PartnerHub; it must not duplicate the catalog/pricing/booking engine.

## Distribution
- COMMON is visible to authenticated active B2B agencies in the same company.
- EXCLUSIVE requires a single active agency at publish time; denied to other agencies in list, detail, copy, price and booking, including direct URL/API access.
- Admin can inspect all publications; partner agency is derived from authenticated memberships, never trusted from UI alone.
- Existing publications migrate as COMMON for compatibility. New restricted publications do not inherit wide access.
- Copies are independent; revoked or withdrawn access blocks NEW exports/quotes/bookings while historical booked snapshots are immutable.

## Commercial / booking
- NET only from SENT/CONFIRMED approved quote versions. Missing/expired/unmatched rates return REQUEST_NET. No import of legacy Word figures as commercial NET.
- Server calculates selling; no supplier fields in partner/client output. FOC and rooming changes require reviewed pricing enhancement before promises.
- Booking request stays REQUESTED and waits for Operations confirmation.
- Travel-date validity and quote acceptance expiry must be modeled separately before accepting distant departures.

## Acceptance / blockers
- Clone and backup MariaDB; review migration 038 checksum and 037 dependencies; never change production.
- Stage two genuine agency sessions: COMMON both, EXCLUSIVE A only; attempt URL/API access as B incl. previously-copied item, export and booking, expect 403/404.
- Check role/CSRF/tenant boundaries, source-data privacy, DOCX/PDF rich media fidelity, pricing 6+1 FOC/hotel 3 cruise 5, save/reopen, desktop/mobile.
- Rehearse publishing/revoke/rollback; do not merge or deploy automatically.
