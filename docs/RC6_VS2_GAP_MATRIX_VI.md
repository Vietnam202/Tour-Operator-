# RC6.2 VS2 — Gap Matrix

Audit ngày 05/10/2026. Nguồn: Issue #15; canonical spec `docs/RC6_VS2_FUNCTIONAL_SPEC_VI.md`; baseline testing `964d2681163701839ac4bd5007c86f153b05712e`. Đây là audit code/schema, không phải xác nhận tính năng đã chạy trên hosting.

| Requirement / spec | Existing | Partial | Missing | Conflict | Files / schema affected | Increment |
|---|---|---|---|---|---|---|
| Build Itinerary + service requirements (§3) | Add/duplicate/reorder/delete days; import schedule | Program blueprint services | Quote-level structured requirements; media rail | Schedule normalizer retains only text fields | app.js, ScheduleImport, TourInventory; new quote service requirements | 2.1 / media 2.2 |
| Media Library + PC upload (§3.3–3.5) | Tour document PC upload | Landing/media URLs | Governed image library, day assignments, optimization, duplicate detection | Document library is not image library | TourLibrary, LandingPages, Storage; new media entities | 2.2 |
| Google Drive selective sync (§3.4) | Single public Drive document import | URL validation/cache | OAuth picker, folder mapping, sync metadata | Current adapter rejects folders; cannot claim sync | TourLibrary; new media source mappings | 2.2 |
| Deterministic Smart Costing (§4–5) | Server RateEngine approved/date/market matching; manual reason; deterministic arithmetic | Generic pax × qty × unit | Category formulas, source/status/trace, requirements coverage | Transport supports day/km; hotel room-night; cruise cabin; option aggregation rounds USD per line | RateEngine, QuoteOptions, new SmartCosting | 2.1 |
| PRIVATE / SIC / Hybrid (§6) | Tour type text | Manual editable lines | Templates, day/service mode, scoped inclusion suppression | No SIC inclusion declaration or duplicate guard | new SmartCosting + requirements | 2.1 |
| Hotel 3★/4★/5★ (§7) | quote_options, shared schedule | Only one option per hotel star | Separate PRIVATE/SIC same-star options | uq_option_level(version,hotel) blocks variants | additive option metadata; widen unique index | 2.1 |
| Cruise 3★/4★/5★ + Custom Mix (§7) | Cruise cost lines | Manual labels | Independent cruise star and presets | No structured cruise option | quote_options metadata + supplier-rate eligibility checks | 2.1 |
| Guest/quantity (§8) | adults/children/infants/FOC/total/paying | manual_pax; copied cost review | Visa/meal/hotel/ticket followers, stable manual override review | Existing FOC is a separate guest segment; must preserve VS1 semantics explicitly | quote_versions + new profile; SmartCosting | 2.1 |
| Simple/Advanced cost views (§9) | Legacy cost table; booking finance stages | Rate source; editable generic qty | Traces, review statuses and VS2 views | Expected quote cost is not confirmed/actual | new costing UI; FinanceLedger linkage later | 2.1 / 2.4 |
| Commercial calculation (§10) | Markup / target margin / manual; FX snapshot | Cost USD + total VND display | Exact VND aggregation and per-paying-pax VND | Per-line USD rounding loses VND precision | SmartCosting; retain legacy pricing untouched | 2.1 |
| Pax Band Generator (§11) | Rate min/max pax | None | Scenario reruns, nonoverlap, safe band pricing | Rate applicability bounds are not quote price bands | new band entities/engine | 2.3 |
| B2B/B2C proposals (§12–13) | Customer-safe HTML/Word/print; included/excluded/terms | Shared basic proposal | Channel policies, template gallery, white label, hotel/cruise matrix | Existing output not approved template set | QuoteExport, TravelDocuments; templates | 2.2 / policies 2.3 |
| Quote snapshots/revisions (§14) | Approval fingerprint, immutable sent bundle, acceptance, new version | Generic copied option drafts | VS2 metadata copy and validation; media/price-band freeze | New variant uniqueness requires copying variant metadata | QuoteOptions, CoreOS revision flow | 2.1 groundwork / 2.3 |
| Final validation (§14) | Positive selling, approved rate, copied review, guest consistency | Capacity validation | Missing coverage/rates, duplicates, guide/hotel mismatch, route scope, SIC double costs | Current arbitrary qty can approve wrong category basis | SmartCosting + approve/send gate | 2.1; bands/margin policy 2.3 |
| Sales → Operations Service Handover (§15) | Accepted option → booking services + frozen snapshot | Exact price/service copy | Requested/confirmed/to-arrange, must-match/equivalent/source commitments | LeadSalesHandover handles Marketing→Sales, not this handover | QuoteOptions booking; service commitments | 2.3 |
| Operations Booking Workspace (§16) | Operations center, booking/services, supplier orders | Existing lifecycle vocabulary | Full VS2 exception/status mapping | Need reviewed transition mapping instead of changing enums blindly | OperationsControl, Procurement, Operations UI | 2.4 |
| Supplier email (§17) | Supplier order snapshot/request status, print documents | Confirmation source EMAIL | Secure delivery, preview/edit/send, attachments/reply timeline/contact roles | Procurement::send records state; does not send real email | Procurement, TravelDocuments; provider/outbox integration | 2.4 |
| Operations→Accounting Payment Request (§18) | Confirmed payable, supplier payment ledger/reconciliation | Schedules and evidence | Request approval/clarification workflow and variance policy | Direct payment ledger is not approval request | FinanceLedger, Procurement; payment requests | 2.4 |
| Booking readiness (§19) | BookingReadiness blockers and ready guard | Current service/payment/guest coverage | VS2 commitments/expanded readiness set | Preserve current blocking behavior | BookingReadiness, OperationsControl | 2.4 |
| Expected/Confirmed/Actual + variance (§9,19) | FinanceLedger profit stages/reconciliation; booking service costs | Booking-level rollups | VS2 per-service lifecycle/read-only projections; threshold approval | Quote cannot claim actual/confirmed before booking | FinanceLedger, booking_services | 2.4 |
| Security/audit/tenant isolation (§20–21) | RBAC, CSRF, company filtering, private storage, audit logs, migration hashes | Each new entry point needs same guards | VS2 regression coverage | Do not expose cost via customer output | Auth, QuoteOptions, new API/UI/tests | All |

## Decisions recorded before production code changes

1. Keep VS1 costing intact. VS2 opt-in uses typed category formulas and VND supplier inputs. Existing non-VND / room / cabin rates require an explicit reviewed manual conversion/basis override, never automatic price invention.
2. Preserve existing guest semantics: Adults + Children + Infants + separate FOC = Total Guests. Paying Pax may exclude infants/non-paying guests. Do not infer historical age breakdown or migrate snapshots.
3. Store service requirements once per quote version, referenced by cost variants. Hotel/cruise variants do not duplicate schedule data.
4. Migration 023 adds profile/requirements and option metadata. Replace only the hotel-only unique index with a wider variant unique index; no dropped data/columns, no historical JSON rewrite. Legacy key remains empty and keeps one legacy option per star. Revision and legacy save queries must preserve variant metadata and target only legacy key respectively.
5. Finalization gates run for VS2 options in both approve and send. Legacy options retain legacy behavior. Confirmed/actual costs stay unavailable in quote Advanced View until Operations work in VS2.4.
6. Do not mark VS2.2–2.4 complete and do not deploy production. Test on a disposable database / staging only.

## Implementation update — VS2.2 (05/10/2026)

The audit above remains the pre-implementation baseline. VS2.1 is in PR #16; VS2.2 is a stacked increment over that branch.

| Requirement | VS2.2 implementation | Verification / remaining integration |
|---|---|---|
| Media Library + PC upload | Immutable private JPEG cache + thumbnail; validated JPEG/PNG/WebP; personal/company/synced visibility; approval/archive, favourites, duplicate/resolution checks | MariaDB + real HTTP upload/browser; synthetic image fixture |
| Google Drive selective integration | Company account + folder allowlist; mapping; paginated image picker; selected import once/linked; check/sync creates replacement asset | Real adapter tested against synthetic provider; live VTA credentials/folders must be configured on staging |
| Visual Itinerary Builder | Three areas; stable day IDs; add/duplicate/reorder/remove; typed route/activity/transport/guide/accommodation/notes fields | Browser reorder preserves image identity; changed legacy schedule requires explicit image review |
| Media assignment | Cover/day hero/gallery/hotel/cruise/service; shared itinerary reused across price variants | Assignment bounds and cross-company rejection; no media binary in DB |
| Proposal Gallery | Standard B2B, B2B White Label, Explorer B2C, Premium B2C; hotel/cruise star rows, policies/contact/public content | Four templates render; white-label omits VTA contacts; no internal cost/source/notes |
| Output | Customer-safe HTML preview, native Unicode PDF, native DOCX with embedded images, expiring/revocable sent-proposal web link | HTTP/browser + PDF render/text extraction + DOCX XML/ZIP checks; email sending remains VS2.4 |
| Sent media / revision | Frozen media IDs + hashes in opt-in public bundle; library archive/Drive replacement cannot rewrite sent proposal; revision copies references, no token | Historical migration/snapshot bytes retained; approval invalidated by presentation edits |

VS2.3 and VS2.4 remain pending. Release/rollback: `docs/RC6_VS2_2_RELEASE_VI.md`; evidence: `verification/RC6.2-VS2.2/REPORT_VI.md`.
