# Document-first proposal authoring

Base: `codex/Vietnam/rc6.2-testing`, `9d50a623edc414b8a92442c91d45b309c1e1d189`. Implementation branch: `codex/Vietnam/rc6.2-document-first`. The clean VS2.2 testing commit is the checkpoint; VS2.3 PR #20 remains separate.

## Existing data flow

`app.js` opens `mountVisualProposal` with the existing quote/version. GET proposal resolves mutable `schedule_json`, `proposal_json` and media assignments, or the frozen public Sent bundle. PUT proposal locks the parent quote/version, checks `expected_revision`, rejects issued versions, preserves hidden internal notes, validates media ownership and updates the existing schedule/presentation. VS2.1 schedule changes run through `QuoteSmartCosting::context`; prose is excluded from transfer scope. Approval and Send use the existing `QuoteOptions` bundle/fingerprint. `QuoteProposal::extend` freezes presentation blocks and media hashes; `ProposalOutput` exports those same public blocks to HTML/PDF/DOCX. Accepted booking snapshots and revisions use existing machinery.

`ScheduleImport` currently extracts Day headings and a few commercial text sections. `DocumentParser` provides plain DOCX/PDF text but loses run formatting and table structure. Current Visual Proposal opens a structured Day Builder with a persistent media rail. These components will be extended in place.

## Implementation

1. Default Document View with section/day outline; preserve Advanced Day Builder; move the existing media rail to an on-demand drawer.
2. Extend proposal import preview for pasted itinerary and existing document parser outputs. Keep uncertain metadata and imported price text as review candidates, never update guests, rates, costing or agreed prices from guesses. Preserve source day numbers and warn about duplicates/gaps/conflicts.
3. Extend `DocumentParser` with ordered safe DOCX blocks (headings, runs, lists, tables); PDF uses existing searchable-text extraction or a graceful Needs Review response. Embedded image support is bounded and uses existing MediaLibrary on explicit import only.
4. Store optional typed public document blocks in the existing `proposal_json`; no HTML blob, schema migration or second renderer. Map day prose to the same stable day keys and schedule. Derive summary directly from draft days. Add compact formatting toolbar, save status, explicit import confirmation and reusable content insertion.
5. Validate document structure and block safety on the server, preserve opaque commercial state, immutable Sent/accepted/booking snapshots and existing public projection. Extend the current exporter for typed rich text/tables while retaining frozen legacy blocks.
6. Test actual authoring and output flow, legacy Advanced View, uncertain PDF, safe import, permissions, revisions, lifecycle and mobile overflow. Run existing regression after focused tests pass.

Database migrations: none. Existing APIs remain compatible; optional import preview commands and optional presentation JSON fields only. Stop if implementation proves a schema/API break or issued snapshot rewrite necessary. No production deployment or merge to main.
