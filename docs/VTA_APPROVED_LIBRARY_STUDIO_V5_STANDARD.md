# VTA Tour Program Library + Proposal Studio — Approved UX baseline

**Decision:** Use the interactive  `VTA_RC62_Tour_Library_Studio_V5_Interactive_Demo.html` supplied/approved in this ChatGPT project as the **visual and workflow acceptance reference** for RC6.2. It is a demo, not the production code or API.

## 1. Product surface / navigation

- Existing VTA Unified OS shell and module permissions must stay intact.
- Tour Program Library is the home for the master tour program.
- Compact internal navigation: **Tour Library · Upload / Import · Template Center**.
- Avoid four large metrics and excessive empty dashboard panels. Keep one compact at-a-glance summary at most.
- Tour cards: tour title, destination, duration, type, tour code, status, optional *authorized local* cover photo, and direct actions **Open / Edit**, **Duplicate**, **Use for quotation**, **Archive** (based on permission).
- Mobile: no horizontal page overflow; editor and price tables may have self-contained horizontal scrolling only where needed.
- Colors: proposed VTA navy `#0A2854`, blue `#1768B0`, yellow `#F1B834`, white/light neutral surfaces. Use verified corporate brand assets when supplied. Clean sans-serif (Inter/Arial fallback). These hex codes remain a *provisional design palette*.

## 2. Itinerary editing / proposal

- Opening/creating a tour navigates to **a single editable document surface**, as in the approved Studio V5 demo; do not replace it with dozens of day-edit forms.
- Direct editing; buttons for New, Import, Paste from ChatGPT, Add/Duplicate/Delete/Reorder Day, preview, save, back to library.
- Complete document sections: title/cover/overview, tour highlights/facts, derived schedule summary, Group/SIC and Private price matrices, hotels/cruise, full daily itineraries, inclusions/exclusions, child/payment/cancellation policies, VTA contact.
- Keep the original rich formatting and media on import (currently incomplete). It is not sufficient to store text-only day paragraphs and claim finished.
- **Private pricing** = five configurable *non-overlapping* passenger bands × 3★/4★/5★. Never silently adjust or invent rates. **Group/SIC pricing** kept separately. Display USD selling rates; supplier cost remains VND in Pricing Engine. No cost/margin/commission data in customer proposal payload.
- Tour Program Library is **master content**, not a customer quote. Customer quotations must use separate revisions/snapshots and must not overwrite original tour or supplier data.
- Template Center: reusable master templates, duplicate/edit/save as template, choose templates when creating a new tour; edit permission enforced server-side.

## 3. Document output (hard release gate)

- Full Tour Proposal, Quick Quotation, B2B Partner Proposal. User-selected sections control what is exported; no unapproved/hidden pricing or cost leaks.
- Real editable Word `.docx`, not MHTML or disguised HTML. Real paginated A4 PDF. Consistent source model and brand system.
- Cover, logo, images, well-sized 3★/4★/5★ price tables, correct image proportions, headings, headers/footers, page numbers. Visually inspect **every** page: no orphan page, clipped table, tiny font, excess whitespace, missing day, or raw markup.
- The existing V5 HTML `print()` exporter and previous document export are **references only**, not proof of compliant DOCX/PDF.
- No customer-facing release until approved example DOCX/PDF exactly meets the reference quality.

## 4. Non-negotiable compatibility

- Do not destroy existing programs/import tokens, search/filters, quotation references, source file provenance, authentication, permissions and company scoping.
- Do not modify Marketing or AI Chat.
- Additive schema migrations, no live DB reset, no production deployment/merge without approval.
- Older client update payloads must preserve current proposal content; reviewed quote snapshots remain isolated.
- Do not reuse the demo's localStorage/iframe as production persistence.
- All imported content treated as untrusted; sanitize rich text, validate uploads, defend stored output against XSS.

## 5. Practical acceptance checks

1. Open a stored tour from Library and edit; save and reopen. Days, highlights, prices, accommodation and policies round-trip exactly.
2. Create a tour, import/paste itinerary from a real 6D5N Word document, edit days and preserve original content/images/tables where supported. Never silently discard unsupported content.
3. Duplicate master tour, save it as a template, then create from that template. Original tour remains unchanged.
4. Review Full / Quick / B2B visibility and verify prices/terms. Reject overlapping passenger bands; require reviewed prices before actual customer publication.
5. Render DOCX and PDF for the supplied sample tour; visually QA each A4 page and verify no internal costs.
6. Existing import, search, archive, copy-to-quote, Marketing, AI Chat and permission tests remain green.

## Current implementation status

See PR #21. Initial Tour Program Library persistence and editing delivered; professional DOCX/PDF, full rich import, official imagery and complete V5 editing parity **remain incomplete**. Keep the PR as Draft until release-gate checks pass.

