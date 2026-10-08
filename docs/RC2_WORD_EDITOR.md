# RC2 blank document editor

The default proposal/document view is one Tiptap 3.31.3 / ProseMirror editor on a blank A4-style page. Application navigation collapses while editing and restores when leaving. Existing itinerary/quote/costing modules remain separate.

## Storage and compatibility

- Uses the existing quote-version proposal API and `proposal_json` column. No table or migration is added.
- `VTA_DOC_2` contains one validated document tree (paragraphs, headings, marks, lists, tables, uploaded image IDs, page breaks). It never stores executable HTML, external image URLs or supplier cost structures.
- Existing `VTA_DOC_1` data is retained in the validated `legacy` field when a new document is saved. Insert → Existing itinerary/proposal copies it into the document only on request.
- Simply opening and closing a pristine blank editor does not save or change a quote. Save Draft, editing/autosave and Preview/Export persist the selected document.
- Existing revision checks, tenant checks, permissions and Sent/Confirmed/Superseded immutability stay in the existing backend save/snapshot machinery. Document-only saves do not rewrite `schedule_json`, supplier rates, guest profiles or 3★/4★/5★ cost lines/results. The existing approval invalidation remains in place when a document is actually saved.
- Images use the existing quote media upload/assignment API; inserting/deleting document images does not delete immutable media bytes or historical snapshots.

## Supported editing

One engine handles cursor/selection, keyboard shortcuts, undo/redo, rich paste, headings, bold/italic/underline, 8–72 pt size, text colors, alignment, bullet/numbered lists, resizable tables and row/column/cell actions, uploaded images (resize handle, move, delete), page breaks and long-document scrolling. No mandatory sections, day blocks or outline are generated.

Import inserts at the selection end without replacing selected text/images. DOCX imports supported headings, direct inline styles, simple lists, tables and embedded top-level images into editable content. PDF.js 5.6.205 converts searchable PDF text to paragraphs inside the browser; it needs no VPS PDF executable and uses a self-hosted worker with eval/XFA disabled. DOCX/PDF/TXT imports are capped at 10 MB. Uploaded images require the existing media permissions.

DOCX output contains native editable Word paragraphs, numbering, tables (including merged cells), images and page breaks. HTML Preview consumes only the unified tree. PDF output uses the existing Unicode renderer.

## Exact limits

- No OCR. Scanned/image-only PDFs return an explicit error without changing the draft. PDF import does not reconstruct columns, tables, images or inline formatting; reading order needs review. Maximum 200 pages / 250 KB extracted text.
- DOCX conversion does not reproduce arbitrary Word layout, theme/inherited styles, headers/footers, complex list nesting or merged source tables exactly. The parser warns where supported conversion is limited. Embedded images inside source table cells require separate upload; top-level embedded images are supported.
- Printable PDF uses a simplified layout: inline font marks/color are not fully painted, merged cells are expanded to a regular grid, and images inside cells are placed after the table. HTML/Word preserve the richer supported document structure.
- Inserting selling prices requires explicit confirmation that the prices have been reviewed in Costing. Freeform content is a document copy. Inserting itinerary/pricing text does not turn it into live operational/cost inputs or refresh it silently when costing changes.

## Reproducible build

From `editor-build`, run `pnpm install --frozen-lockfile --ignore-scripts` and `pnpm run build`. Commit the generated `assets/document-engine.js`, `assets/document-pdf.js`, `assets/document-pdf-worker.js`. No build or dependency download runs on the VPS. Dependency licenses are in `DOCUMENT_EDITOR_THIRD_PARTY.md`.

## Verification

- `tests/document-freeform-unit.php`: typed/security/compatibility checks and real DOCX/PDF fixtures. Set `FREEFORM_FIXTURE_DIR` to a disposable private output directory and enable PHP DOM, mbstring, ZipArchive and GD.
- `tests/document-freeform-native.php`: existing document/cost regression on a fresh local MariaDB fixture (127.0.0.1:33317, `VTA_TEST_DB_PASSWORD`) plus freeform save, stale-write rejection, tenant isolation and unchanged historical snapshots. Never run this fixture on staging.
- `tests/http/freeform-router.php`: start a loopback PHP test server with `FREEFORM_FIXTURE_DIR`; it uses the production parser/validator/export with synthetic file-based quote state, without a database.
- `tests/document-freeform-browser.cjs`: run against that local fixture on port 18876 after unit fixtures; set `FREEFORM_FIXTURE_DIR`, optional `PLAYWRIGHT_MODULE` and `CHROME_PATH`. Uses real Chrome clipboard, file upload/download, save→refresh/reopen, table/image controls, PDF import, long documents and read-only behavior. Screenshots clearly belong to the local fixture, not staging.

## Staging and rollback

Use only the existing private controller at `/home/v2quote.vietnamtraveladvisor.com.vn/vta_private/fast-deploy/deploy-staging.sh`, under `vquot8508`; follow `FAST_STAGING_DEPLOY.md` to update the private controller safely if required. It checks staging identity, required extensions, existing migration ledgers, immutable historical SQL, source cleanliness and the approved VS2.4 baseline, verifies backups, journals cutover and validates health.

No migration is required by this editor. Never reset or recreate a database. Use the controller `--rollback` for a failed release; preserve its backups/journal/HALTED evidence. Staging browser acceptance must run separately after deployment: login → open quote → Document Editor → blank page → import DOCX/PDF → paste/edit text/table/image → Save → refresh/reopen → DOCX/PDF export and Preview → return to unchanged 3★/4★/5★ Costing.