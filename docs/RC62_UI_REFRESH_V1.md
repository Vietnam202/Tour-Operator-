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

## V5 Itinerary Template Gallery (UI-preview increment)
- Added **Chọn mẫu** in the existing Proposal Studio V5 header.
- Four visual-only layout previews: Professional Detailed, Photo-rich Brochure (typography emphasis; no new image engine), Quick Itinerary and B2B Tour Proposal.
- Selection updates CSS presentation without rerendering or replacing editable fields. It survives V5 preview toggle in the current mount, and does not mutate itinerary, price or supplier records.
- Gallery is responsive; existing library actions, blank Word editor and Smart Cost remain intact.
- **Explicit limitations:** template selection is session-only, not stored in Tour Library; "My Templates" is labeled unavailable; no new full DOCX/PDF export engine or image-import fidelity is implied.
- Regression: `tests/v5-template-gallery-ui.cjs` checks selection, unsaved day text, overview, rates, read-only behaviour and no extra server writes.
- Manual visual checks: all four gallery thumbnails at 1440/1024/390 widths, expanding/collapsing gallery, preview toggle, existing day operations and focus order; verify no output/PDF/print changes.
