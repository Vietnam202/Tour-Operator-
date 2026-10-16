# VTA RC6.2 · Professional Tour Template Pack V6.1

**Scope:** Source-only templates for the owner-approved Master Tour/Advanced Itinerary V6 design. Do NOT deploy to production or staging automatically.

## Design styles
| Style ID | Name | Audience | Structure |
|---|---|---|---|
| detailed | Professional Detailed | B2B/B2C | A4 cover, highlights, numbered timeline, accommodation, inclusions/exclusions |
| brochure | Photo-rich Brochure | B2C | Large destination cover, visual story, editorial day layout |
| quick | Quick Itinerary | Sales/B2C | Compact route summary, day table, short notes |
| b2b | B2B Tour Proposal | Trade partners | Proposal ID, itinerary, service/room categories, approval notice |

The linked **full downloadable preview pack** was prepared for owner approval separately in the ChatGPT project. The GitHub source here is a standalone, no-backend visual coding reference to hand to Codex for integration, not the currently deployed V6 editor.

## Requirements for Codex
- Implement four **genuinely different HTML page structures**. Changing a top-border/heading color is not enough (see Issue #99).
- Keep authoritative existing **Tiptap VTA_DOC_2** editor. This pack is read-only presentation/reference; never replace/edit the stored document by re-rendering the preview.
- Separate **Design Template** (presentation skin) from **Content Template** (persistent company-scoped \u0060VTA_TEMPLATE\u0060 program). Style selection must not mutate Master or quote.
- Map real server-side day identity/order, route, meal, overnight, text, approved media, hotel/cruise; never inject \u0060Day [Number]\u0060, invented prices/supplier availability or duplicate Inclusion/Exclusion sections.
- Do not expose NET/profit, supplier rates or private data in customer outputs; validate source ACL/media.
- Four layout previews at 1366/1440/390 px and print A4; export through existing authorized DOCX/PDF engine after separate E2E verification.
- On staging require before/after screenshot parity relative to owner-approved HTML, template switch without content loss, Word save/reopen, no toolbar overflow; keep production NO-GO (VIE-6/VIE-7/VIE-11).

**Tracking:** Issue #99 (P0 visual parity), integration PR #98, source design PR #96. The gallery HTML contains example content only; it is not a ready-made quotation/booking.
