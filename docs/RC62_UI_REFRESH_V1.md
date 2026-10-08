# VTA RC6.2 — Minimal UI Refresh V1

## Scope
Presentation-only upgrade for the **existing** RC6.2 application:
- Shared desktop shell (nav, header, search, spacing and focus states)
- Tour Program Library entry cards and forms
- Proposal Studio **V5** paper, outline, toolbar chrome and editable content styling
- Blank Word (existing Tiptap/ProseMirror editor) page and toolbar appearance
- Smart Cost Direct Edit spreadsheet rows, inputs, summaries and guest controls

**Unchanged:** Itinerary V5 creation/edit APIs, blank Word document editor engine, Smart Cost server computation and 3★/4★/5★ hotel/cruise mixing, Private/SIC, roles, permissions, migrations, data, export, Marketing and AI Chat.

The implementation intentionally retains the existing navigation and editor markup. No new 'single tour workspace' data migration or feature rewrite is included.

## Files
- `vta-ui-refresh.css`: responsive screen-only, scoped visual overrides loaded last.
- `index.html`, `preview.html`: CSS inclusion, without changing scripts or route handlers.
- `service-worker.js`: versioned offline precache asset.
- `tests/ui-refresh.cjs`, `.github/workflows/ui-refresh.yml`: static wiring checks + regression suites for existing V5, costing, Word document and Library.

## Manual owner acceptance (requires running staging app)
1. Desktop 1440x900: open Dashboard, My Tours and Library, verify navigation remains operable with no duplicated tools.
2. Open Proposal Studio V5, edit an itinerary day, preview, save and reload; verify unchanged content, day outline and pricing matrix.
3. Open blank Word editor, paste a detailed itinerary, edit a table/image, save/reopen, export DOCX/PDF; verify print layout unchanged.
4. Open Cost, edit a supplier value and hotel/cruise mix; verify server response, six Private/SIC price scenarios, no changes to totals beyond expected.
5. Test 1024px laptop, 390px phone, focus/keyboard navigation, scroll/no unwanted overflow, disabled/read-only user, older draft tour.
6. Revisit app after service worker update; verify CSS served online/offline with no stale UI. Check browser console for errors.

**Status:** source changes do not prove visual browser acceptance, CI success, merge, server deployment, or booking/price approval. These require separate evidence. Do not merge or deploy to production without owner confirmation.
