# RC6.2 VS2.2 visual itinerary and proposal release

The existing VS1 inquiry/trip/quote/booking system and VS2.1 Smart Costing remain authoritative. Baseline: `a2eb8e9c750905eb8005fd28b11dc78d13ba367b` on `codex/Vietnam/rc6.2-testing`. VS2.2 adds presentation and private media; it creates no parallel quote, itinerary, price engine or revision ledger.

## Completed scope

- Three-column visual editor: stable day identities, add/duplicate/reorder/remove, existing Program Library import, public itinerary fields and permission-projected internal notes. Requirements remain editable through the existing Smart Costing workspace; the visual editor displays the shared requirements.
- Private originals, optimized JPEG derivatives and thumbnails; JPEG/PNG/WebP decode validation, 12 MB/16 MP limits, duplicate detection, low-resolution warnings, favourites, personal quote media and approved company library. SVG/GIF/HEIC are rejected with a format message.
- Separate cover/day/gallery/hotel/cruise/service/logo assignments. Template changes reuse those assignments.
- Four controlled templates: VTA Standard B2B, partner White Label, Explorer B2C and Premium B2C. Branding, section order, policies, independent hotel/cruise lists and existing offered option selection are presentation settings.
- Desktop/mobile/print preview, structured UTF-8 PDF, native editable DOCX and expiring/revocable public links. All consume the same customer-safe snapshot candidate.
- Existing approval/send/accept/booking/revision flow. Sent presentation, public HTML, structured blocks and media content hashes are frozen in the existing sent bundle.

## Commercial boundaries

Net, PRIVATE/SIC/HYBRID and selected-option displays use reviewed selling amounts from VS2.1. The Pax Band view displays the exact current guest profile with the available reviewed 3/4/5-star variants. Additional guest-count bands require separately reviewed costing scenarios; this release does not invent rates or introduce a new scenario pricing engine. Gross/commission/net-payable fields are not introduced without an existing commercial policy. B2C does not expose agent metadata, supplier costs or profit.

CTA links support Book Now/Request Change/contact. Acceptance remains the existing authenticated quote acceptance flow; no new anonymous booking or payment transaction endpoint is introduced.

## Selective reuse assessment

| Existing component | Decision | Reason |
|---|---|---|
| VS1/VS2.1 quote, schedule, requirements, rates, variants, approvals, sent bundles, booking snapshots | REUSE | One authoritative business graph and validator |
| Existing `quote_versions.proposal_json` | REUSE | Draft presentation configuration; no new configs table |
| Earlier `codex/Vietnam/rc6.2-vs2.2` output/Drive prototypes | REWORK | Selective renderer/client reuse, rebuilt tenant, permission, storage and frozen snapshot boundaries |
| tFPDF 1.33 and DejaVu font | REUSE | Structured Unicode PDF; upstream licenses included |
| Earlier candidate 023/024 schema/costing/quote revision approach | DISCARD | Conflicts with approved VS2.1 additive baseline |
| Whole candidate branch/PR merge | DISCARD | No wholesale merge or cherry-pick of obsolete costing |

The old prototype was read at commit `47981f` (full identity retained in local audit evidence). Production, main merge, VS2.3/VS2.4 and historical migration rewrites remain outside this release.

See `VS2_2_API_SCHEMA.md`, `VS2_2_TEST_MATRIX.csv`, `VS2_2_REGRESSION_REPORT.md` and `VS2_2_RELEASE_MIGRATION_ROLLBACK.md`.
