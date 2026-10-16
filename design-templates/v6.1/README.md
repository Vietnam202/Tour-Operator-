# VTA RC6.2 — Professional Design Template Pack V6.1

**Status:** Visual template candidate for owner review. Source and demo only. **Not merged, not deployed, not UAT approved.**

[Standalone preview](./standalone.html) · [Modular preview](./preview.html)

This package adds four actual HTML design renderers for itinerary presentation in a read-only preview, not four color variations of the existing Tiptap canvas.

| Layout ID | Display name | Purpose | Distinct features |
|---|---|---|---|
| `detailed` | Professional Detailed | B2B / B2C full program | branded cover, overview, highlights, day timeline, hotels, service notes |
| `brochure` | Photo-rich Brochure | inspiring B2C editorial | illustrated full-height cover, destination mosaic, richly styled days |
| `quick` | Quick Itinerary | traveler / sales summary | concise overview, 8-day route table and condensed details |
| `b2b` | B2B Tour Proposal | partner quotation presentation | formal trade header, reference/version, day schedule, **price status only** |

### Files

- `standalone.html` — self-contained HTML gallery, safe to open locally.
- `preview.html` — equivalent modular gallery loading the separate source files.
- `design-templates.js` — pure renderers; CSS-specific art illustrations embedded as demo data URIs. Public `window.VTATemplateDesignV6` exposes `render`, `validate`, `templates`, `escape`.
- `design-templates.css` — isolated `vta-` classes, responsive and A4 print rules.
- `sample-data.js` — illustrative 8D7N travel program, **not real company inventory**.
- `tests/validate-templates.cjs` — structural, input safety and no-fabricated-price smoke test.

### Integration contract for Codex (Issue #99)

1. **Keep the existing editor.** Tiptap, saved `VTA_DOC_2` document, version/revision and the existing server DOCX/PDF exporters are authoritative. Do not replace the editor or push rendered HTML back into the editable document model.
2. **Separate two concepts.** Design template (`detailed / brochure / quick / b2b`) is a selectable *presentation*, distinct from a persistent company content template carrying `VTA_TEMPLATE` tag.
3. **Add approved snapshot adapter.** Map only public, ACL-approved Master Tour or Quote data from existing APIs to the render model: `name`, `code`, `version`, `route`, `intro`, `highlights`, `days` with stable identities, `accommodation`, `inclusions`, `exclusions`, `terms` and approved media. No client PII, credentials, supplier rates, NET/profit, internal notes or unpublished images in public-facing output.
4. **Do not fabricate.** If a field is absent, show a genuine empty/needs-review state. Illustrative sample data must never be inserted into a real saved Master/Quote. Never repeat Inclusions/Exclusions on every click, overwrite document blocks or auto-save on visual layout change.
5. **Safe asset model.** Demo inline SVG artworks are *illustrations*, not authenticated destination photos. Production media must be served through an approved, tenant-safe media mapping. No arbitrary remote URL or untrusted `javascript:` schemes; do not expose private assets.
6. **Read-only rendering:** `VTATemplateDesignV6.render(approvedPreview, 'brochure')` returns a safe HTML fragment. Switching a style never mutates source data, bookings, quoted/sent snapshots, pricing or company ACL.
7. **Print/export scope:** CSS supports browser A4 print preview. This does NOT prove or implement 4 editable DOCX export presets or production PDF equivalence. The existing server exporters need separate real-file E2E evidence.
8. **Visual parity:** compare all 4 templates to the owner-approved V6 design, browser 1440/1366/390px; ensure editor paper width and non-overflow toolbar in actual RC6.2 after integration. Fix Issue #99 before closing any staging visual acceptance.
9. **CI ≠ staging.** Test at exact integration SHA; after source review and explicit permission, validate real-auth staging `https://rc62-staging.vietnamtraveladvisor.com.vn` under VIE-6 / VIE-7 / VIE-11; protect other RC6.2 modules. No automatic merge/deploy.

### Running local checks

```bash
node --check design-templates/v6.1/design-templates.js
node design-templates/v6.1/tests/validate-templates.cjs
```

To preview, open `standalone.html` locally in Chrome or view `preview.html` through a static development server. The HTML gallery is standalone/read-only: no database, credentials or WordPress connection.

### Next acceptance deliverables
Codex integration Draft PR (separate from this design PR), real authenticated side-by-side screenshots, 7D6N master data tests, Save/Reopen/version/ACL evidence, actual DOCX/PDF with approved media, and an explicit **owner** staging UX signoff. Until then Issue #99 remains **FAIL_STAGING_UX / NO-GO**.
