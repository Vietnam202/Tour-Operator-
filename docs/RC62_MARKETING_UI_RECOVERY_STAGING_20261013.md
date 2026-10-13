# RC6.2 Marketing UI recovery (review-only, 13 Oct 2026)

## Why the staging upload appears incomplete

PR #44 once exposed Marketing in the unified RC6 workspace. PR #47 explicitly removed the Marketing/Content/Landing navigation and client-script loading when Marketing was reassigned to WordPress. PR #62 carried the de-scope forward, and PR #88 inherited it. The shipped PR #88 runtime **still contains** `marketing-studio.js`, `landing-builder.js`, `meta-connections.js`, `social-publishing.js`, and supporting PHP endpoints, but the live/preview HTML does not load the core Marketing Studio scripts, and `app.js` redirects `marketing` to `leads`.

The restoration in **this separate Draft branch** responds to the operator's 13 Oct report that many approved workspaces are absent after a test upload. It restores the existing legacy Marketing workspace **without changing WordPress or production**. It does not imply that social APIs, Meta connection grants, or site/webhook provisioning are ready.

## Changed behavior

- Authorized `lead.view` users get a **Marketing** workspace link on desktop/mobile. Server-side actions still use their original permissions, including `campaign.manage`, `marketing.approve`, `lead.manage`, and integration-specific guards. Read-only users must not acquire write access.
- Native `VTAMarketing` Studio loads existing Content / Calendar / Publishing / Connections / Library / Webhooks / Campaigns / Inbox / Reports / Channels.
- **Landing pages** are reachable as an integrated Marketing tab via existing `VTALandingBuilder`. Existing tenant/revision/approval APIs are unchanged.
- Preview uses sample data, never transmits provider credentials or changes live records. JavaScript and CSS are versioned and included in a rotated PWA asset cache. Service worker keeps API, journeys and private URLs out of cache.
- No automatic publishing, rate changes, production SQL, WordPress configuration, migration or deployment. The de-scoped WordPress frontend remains independent and optional; a product owner must decide how content ownership is divided before production.

## Parity vs previously approved Tour Operator scope

| Capability | PR #88 runtime verified | Stage-specific acceptance |
|---|---|---|
| Master Tour Library; integrated Hồ sơ tour → Chương trình → Kho chương trình | Source modules present | Real Save/Reopen, image rights, template copy, undo, version conflict and role access |
| Word canvas, paste from ChatGPT, DOCX/PDF import, export | Source modules present | At least 50 real files incl images/tables, Word/PDF fidelity and private-media handling |
| Cost 3★/4★/5★; Hotel + Cruise independence, Private/SIC, 6 paying + 1 FOC | Source modules present; synthetic CI previously passed | Approved supplier price/availability, authoritative server calculations, Cost → Price → Proposal |
| B2B Common/Exclusive, agency copy, white-label, NET request | Source modules present; synthetic CI previously passed | Authenticated Partner A/B sessions, no supplier/guest/NET leaks, real bookings remain pending |
| Native B2C catalogue/detail/inquiry | Source modules present | End-to-end publication, withdraw, approved media and inquiry origin |
| Lead qualification, Not potential, Follow later, Sales handover | PR #88 targeted CI passed | Role-specific browser acceptance, records retained, reminders and concurrency |
| Marketing Studio & Landing | Previously hidden; restored by this Draft | CI on this exact HEAD, auth/role UAT, WordPress content ownership review |
| Meta/Inbox/Publishing | Source modules present | Only authorized official API integration; no fake delivery confirmation |
| Operations / Finance | Source modules present | Staff and supplier-approved booking/finance golden path |
| CyberPanel/HTTPS/MariaDB | Disposable CI tests only | Real stage CA/DNS/docroot, independent clone/restore/rollback and signoff |

## Verification and release rules

1. Base source pinned to PR #88 exact `119ebfc586f440c166903a2e25e32434867a9304`. **Do not reuse** its existing staging ZIP, build identity or checksum for this branch. Request a new CI package on the **new HEAD**, then verify SHA-256 manifest and every runtime byte.
2. `node --check` on changed JavaScript, all applicable GitHub Actions jobs and browser Marketing tab tests must pass on this branch's exact HEAD.
3. Real authenticated staging must verify Marketing read-only/write role distinctions, 1440/390/360 layouts, old lead conversion/marketing records and WordPress coexistence. Preview or local fixture alone is not staging PASS.
4. Production stays blocked until real CyberPanel TLS, a backup/clone/restore rollback rehearsal, full role tests, source-approved commercial data, document fidelity and owner release signature.
5. Never point the candidate at `quote.vietnamtraveladvisor.com.vn`'s live database or unpack over an existing production docroot. No merge/upload/migration is authorized by this PR.

**Release classification:** DRAFT / REVIEW_ONLY / NO-GO until the listed gates independently pass.
