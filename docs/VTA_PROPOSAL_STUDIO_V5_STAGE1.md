# VTA RC6.2 — Tour Program Library → Proposal Studio V5 (integration stage 1)

## Scope delivered

- **Tour Program Library:** a new "Tạo chương trình" action and direct edit/open integration with a document-style Studio. Existing search, import, archive, copy-to-quote and inventory navigation remain intact.
- **Document editor:** tour overview, highlights, auto-generated summary itinerary, day descriptions, meal/overnight, actions to add/duplicate/reorder/delete days, group selling prices for 3★/4★/5★, private price matrix by passenger bracket, hotel comparisons, and customer booking policies.
- **Design:** VTA corporate navy/blue/yellow palette in namespaced CSS. Desktop/mobile responsiveness, non-destructive preview, clear DRAFT cues.
- **Persistence:** additive migration `036_tour_library_proposal_studio.sql` adds `proposal_json` to existing `tour_library_programs`. `TourLibrary::normalizeProposal` stores only customer-facing allowlisted fields with bounds and nonnegative USD price validation. Existing clients that omit proposal data will preserve the stored proposal on update.
- **Security:** company-scoped existing library API, existing manage permissions, read-only view state, HTML escaping on render, plain-text day editing, no writes into supplier cost or commission tables.
- **Preview:** in-memory demo API round-trips the proposal object.
- **Test files:** browser-like Studio unit test and PHP field validation tests; draft PR CI checks JS/PHP and existing library test suites.

## Important limitations — NOT DONE

1. **Production DOCX/PDF export is NOT included in this stage.** Do not claim exports are customer-ready. This requires an approved, shared DOCX/PDF rendering pipeline with visual A4 pagination QA and tested customer-facing no-cost leaks.
2. Rich DOCX/PDF import still uses the legacy import/preview workflow. Studio editing stores day descriptions as plain text; it does not preserve inline images, rich tables, or imported document formatting.
3. The price matrix is manually editable draft selling price data. It is not authoritative, approved, or automatically synchronized with Pricing Engine. The UI explicitly warns that pricing requires review.
4. The output includes VTA text branding, but not yet the official high-resolution logo or image library.
5. Existing quotations are not changed by editing a master library program. Existing copy-to-quote remains the guarded source-snapshot workflow.
6. Staging/server deployment was NOT performed. The live v2quote domain remains unchanged.

## Migration/run order

1. Review migration 036 and compare it to current MariaDB schema; back up storage and database before migration on any server.
2. Run the repository migration runner in a staging environment, not production. Confirm migration checksum and the new JSON column.
3. Load `preview.html` for UI verification and create/edit an example tour. In server-backed staging, test company scoping, permissions, persistence and old-client backwards compatibility.
4. Execute `node tests/tour-proposal-studio.cjs`, `node tests/tour-library-ui.cjs`, `node tests/tour-library-demo.cjs` and `php tests/tour-library.php`; run lint first.
5. Only after review and passing tests, plan export-engine Phase 2. Do not merge without review.

## Phase 2 acceptance gate

Implement real editable `.docx` + professionally paginated A4 `.pdf` from a canonical proposal data model. Must include cover, approved logo/branding, tables, images, day-by-day itinerary, hotels, inclusions/exclusions, policies, page numbers and 3 proposal presets (Full / Quick / B2B). Run a visual page-by-page QA of the VTA 6D5N Word reference, including long-day content, single supplements and passenger-range rows. Never include internal supplier rates/margin or unauthorized B2B net data. Ensure price release requires explicit approval.

## Notes

Source branch: `codex/Vietnam/rc6.2-testing`. This feature is isolated in a separate branch / Draft PR. No changes to Marketing or AI Chat source.
