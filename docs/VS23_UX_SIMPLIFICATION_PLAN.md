# VTA VS2.3 UX simplification

Baseline: `codex/Vietnam/rc6.2-testing`, commit `9d50a623edc414b8a92442c91d45b309c1e1d189`. Work is isolated in `codex/Vietnam/rc6.2-document-first`; the commercial VS2.3 draft PR remains separate.

## Architecture and reuse

The inquiry, trip, quote and quote_version remain authoritative. Smart Costing owns guest dependencies, requirements, variant cost lines, approved rate resolution, deterministic decimal arithmetic and pricing. QuoteOptions and Vs2Snapshots own approval, Sent, acceptance and booking. QuoteProposal owns presentation in existing proposal_json and media assignments. ProposalOutput renders the same public bundle to HTML, PDF and DOCX.

Document View is the default presentation of the existing itinerary. Advanced View retains the Day Builder. A bounded typed public document representation in proposal_json preserves formatting; schedule_json still supplies day identity and operational fields. Imports are previews and require explicit confirmation; imported price/pax/FOC metadata never changes commercial inputs.

Cost defaults to a compact guest form and one spreadsheet over three existing variants. A batch command on the existing dispatcher calls existing requirement, variant and line writers under one version lock and transaction. Totals come exclusively from SmartCosting. Approved rates are used only after the existing resolver validates them; ambiguity remains an exception. Manual cost keeps supplier evidence and review requirements. Hotel and cruise labels use existing proposal accommodation rows.

## Files

Modify visual-proposal.js/css, smart-costing.js/css, app.js at the quote integration points, index.html/service-worker.js asset references, DocumentParser, ScheduleImport, QuoteProposal, ProposalOutput and QuoteVs2. Add a small cost-sheet frontend view, focused tests and this report. Keep the existing Advanced controls available.

Do not modify migrations, deterministic costing/rate formulas, RateRules, approval/send/accept/booking lifecycle, RBAC grants, finance, operations or historical records. No database migration or breaking replacement API is planned.

## Compatibility

Legacy proposal_json without document blocks remains supported. Historical frozen output uses its recorded blocks and snapshots. No upgrade rewrites historical data. New commands require current permissions, tenant ownership, mutable version and expected_revision. A financial projection is never loaded for a user without quote.view_cost. Profit controls additionally require quote.view_profit. Public output exposes selling prices and public accommodation labels only.

## Sequence and verification

1. Document shell, outline and lazy media drawer; retain Advanced View.
2. Paste/DOCX/PDF preview, typed editing, consistency warnings and reused output.
3. Compact guest summary preserving the existing non-paying population and additional FOC semantics.
4. Three-variant spreadsheet, atomic shared/separate edits, approved matching and manual evidence.
5. Three pricing summaries, Price and Proposal integration.
6. Focused parser/API/UI tests, existing regression suites and actual local browser acceptance. Report partial PDF extraction and formatting limitations accurately. No staging or production deployment is included in this UX change.
