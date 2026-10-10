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

## Unified RC6.2 integration hardening (11 October 2026)

This integrated candidate is based on the #44 unified workspace and includes the #46 hybrid B2B implementation. It does not depend on Marketing's proposed WordPress de-scope (#47) or automatically deploy to v2quote.

- **Unified Sales & B2B > Rate & Contract** now requests `b2b/tours?agency_id=<verified member agency>` for partner-only users. Multi-agency members can switch agency without exposing a third agency's tour list; a stale/forged client-side ID is discarded using the server-returned membership list. Server ACL remains authoritative.
- **My Tours** for partners excludes revoked publications, inactive agencies, and exclusive publications assigned to another agency, including an old working copy. Admin retains audit visibility; no old snapshot is changed.
- A disposable SQLite test calls actual private `PartnerHub` access methods for Common/Exclusive, cross-tenant, cross-agency, revoked and direct-ID attempts.
- A CI job runs the existing full-schema/core+Marketing P12 MariaDB rehearsal and inspects the actual additive migration `038_b2b_hybrid_distribution` constraints. The test fixture is disposable and cannot be treated as a restored staging backup.
- The admin NET Request queue is a **request workflow**, not an approved quote/Operations booking. No supplier price, departure availability, rich-media 50-file round-trip or 6+1 FOC quote correctness is asserted by these new tests.

**Release decision:** remain Draft until exact-commit CI is green; run the two-agency authentic-session browser/API matrix, clone upgrade + backup/restore, 3-star hotel/5-star cruise with 6+1 FOC, real DOCX/PDF privacy and Operations request handover in staging. Review migration 038 and the private CyberPanel deployment controller separately. No production database migration/merge/upload is authorized.
