# RC6.2 VIE-16 — AI Itinerary Staging Release Gate

Owner approved the 4-layout / 5-private-group offline demo and CONDITIONAL deployment only to https://rc62-staging.vietnamtraveladvisor.com.vn. This is not production approval or permission to bypass release guards.

Sources: GitHub Draft PR #106, Issue #105, Linear VIE-16; ancestors Draft #104 -> #103 -> #102. Verify CURRENT PR SHA and all CI after every commit. No existing staging rollout is proven.

## Evidence register (2026-10-17)

| Gate | Latest observation | Evidence needed |
| --- | --- | --- |
| CODE | PR #106 contains Word Editor AI JSON bridge, review, new Master Draft, 4 HTML draft layouts and 5 price rows | exact HEAD CI and reviewer |
| HOST | BLOCKED_ENV: rc62-staging hostname did not resolve from this session | DNS A/AAAA/CNAME, valid HTTPS/TLS, vHost/docroot |
| DATA | VIE-6 Todo | encrypted backup, isolated MariaDB clone restore, ledger/checksum/rollback |
| UAT | VIE-11 Todo | exact deployed SHA, real-auth roles, actual DOCX/PDF, responsive screenshots |
| PRODUCTION | NO-GO | separate owner signoff; no production actions in this PR |

## Operator procedure — fail closed

1. Read only: establish DNS and TLS of the explicitly approved staging host, and independent docroot/PHP 8.3/database identities. Do not assume existing v2quote/quote hosts are staging.
2. Read only: compare the unauthenticated health endpoint runtime source_commit and source_inventory_sha256 to the independently verified ZIP manifest. An HTTP 200 without matching PINNED_PACKAGE identity is a blocker.
3. Before any staging writes, verify exact SHA artifact inventory and checksums. The existing deploy/deploy-staging.sh has a different pinned branch; a candidate on PR #106 cannot be uploaded by bypassing branch/host/database guards.
4. Authorized operator only: encrypted backup of current staging data/media/private config; restore to independent disposable clone and reconcile schema_migrations and checksum ledger. Rehearse upgrades and restore/rollback on clone.
5. When VIE-6 and VIE-11 gates are evidenced and signed, deploy through approved staging-only workflow, never by directly overwriting PHP docroot or running production migrations.
6. Immediately read back runtime health SHA, actual documents/API behavior and rollback readiness; any failure triggers rollback and NO-GO.

## VIE-16 staging UAT cases (must use real authenticated roles)

| ID | Action | Expected |
| --- | --- | --- |
| AI-01 | Open editable Master Tour Word Editor | AI Edit shown only for tour_library.manage; readonly/partner/quote SENT cannot edit |
| AI-02 | Submit proposed 7D6N to 8D7N with Ninh Binh Day 5 | Explicit changes and SIC/Private/transfer warning, no fictitious confirmed service |
| AI-03 | Switch detailed, brochure, quick, b2b previews | Four distinct draft HTML styles; source images retained; no NET/cost/commission |
| AI-04 | Edit exactly 5 groups: 4,6,8,10,12 pax x 3star/4star/5star | Empty prices On request; unique ascending sizes, USD selling rate validation |
| AI-05 | Tamper JSON after preview; cross-tenant/user; forged/expired token; stale revision | REVIEW_REQUIRED or STALE_REVISION; no draft written |
| AI-06 | Approve reviewed proposal then create NEW Master Draft | New draft ID, original source/revision unchanged, audit and idempotent retry |
| AI-07 | Reopen and edit an approved-rate itinerary or price matrix | Old rate approval revoked, customer export shows On request |
| AI-08 | Actually export DOCX/PDF/HTML with media and price table | Verified file bytes/pagination/images, mobile 390px and desktop 1366px screenshots |
| AI-09 | Denied RBAC/CSRF/direct-ID, malformed JSON, foreign images/XSS | Fail closed, no PII/credentials/internal pricing leak |
| AI-10 | Regress Tour Library, Quote/SENT, Smart Cost, B2B A/B ACL, LeadHub and B2C approval | No behavior or data regressions |

## Technical boundaries

The PR implements a human-reviewed external ChatGPT/Hermes JSON import fallback. It does not provide a live ChatGPT/Hermes API. Four HTML draft previews are not evidence that every DOCX/PDF style is implemented. Master Draft creation must not auto-publish to B2C/Ads. Existing source and public approvals stay unchanged. Internal NET/cost/profit never enter client exports.

For every real UAT record: operator, UTC timestamp, SHA, hostname, role, program/source IDs, expected and actual, sanitized log/screenshot/ZIP manifest link, and PASS_STAGING_REAL_AUTH/FAIL/BLOCKED_ENV. Do not post SQL dumps, customer records or secrets.

See docs/RC62_OPERATOR_STAGING_EVIDENCE_HANDOFF.md, docs/RC62_RUNTIME_BUILD_IDENTITY_VI.md, Linear VIE-6 and VIE-11 for mandatory release gates.

FINAL GATE: DRAFT / BLOCKED_GATE / NO DEPLOY until every preflight and recovery condition passes; production stays NO-GO.