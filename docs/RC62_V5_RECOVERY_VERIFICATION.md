# RC6.2 V5 recovery — root causes and evidence

Date: 11 October 2026. Based on PR #58 head d0d9245318980b98f5927008e6bda465d218752d, inheriting #55 → #56 → #57 → #58. This is a code/CI correction, not staging or production approval.

## Reproduced causes and corrections

| Cause | Before | Correction / regression evidence |
|---|---|---|
| Passenger validation filtered nonfinite bounds before validating | `abc` or `Infinity` could reach save API; maximum 1000 not checked | Validate every row; both save paths reject noninteger, nonfinite, blank, reversed, oversized and overlapping bands before API |
| Day parser used `\s` around heading | Newline could become part of heading; indented Day/Ngày headings not parsed | Horizontal whitespace only; CRLF, indented bilingual headings and empty titles preserve descriptions |
| Save-as-Template discarded source creation key without making a fresh request key | Lost response followed by retry could create a second program | New operation-scoped creation key; unchanged retry reuses key; success resets operation. Never reuse source/import identity |
| Save aborted listeners before destination callback completed | Confirmed DB save plus failed reopening left editor unable to retry | Confirm save first; keep listener until destination succeeds; retry targets confirmed ID with PUT; distinguish save success from view failure |
| Save response had no mount lifetime check | Late response could close or overwrite the newer editor | Ignore success/failure after abort/remount |
| Hotel remove attribute lacked closing quote | Dataset index contained label text, so removal failed | Correct markup; regression removes intended hotel |
| Unified regression used default PR merge checkout | Artifact raw-head evidence and backend/browser evidence identified different commits | Unified and Marketing UI jobs explicitly checkout PR head with credentials persistence disabled; package already pinned |

## Validation

- `node tests/v5-parity-recovery.cjs`: 11 synthetic DOM behavioral groups, including lost-response retry, original/template save, stale response/failure, validation, parser, overnight Summary, hotel removal, read-only and preview.
- Existing `tour-proposal-studio.cjs`, `v5-template-gallery-ui.cjs` and `npm run test:unified --prefix tests` retain parity and permission coverage.
- `tests/v5-parity-browser.cjs`: actual Chromium interactions at 1440px and 390px, using a synthetic local UI API fixture. It is not authenticated staging acceptance.
- Native MariaDB suite adds replay/no-duplicate and changed-content key rejection assertions to the existing 18 checks (20 total).
- HTTP suite adds same-ID replay and no-duplicate list assertions to the existing 19 checks (21 total), using PHP loopback/session/CSRF and synthetic accounts.
- Studio JS/CSS asset references in index, preview and service worker use V5-4-RECOVERY.
- New CI head SHA and artifact checksums must be recorded from actual results; prior #56/#57/#58 PASS cannot be relabeled as this correction's PASS.

## Remaining operator acceptance

Still blocked until independently verified: artifact re-download/hash, authorized HTTPS staging host/docroot/private config and clone identity, encrypted backup and restore, existing-data migration ledger/checksum and rollback, authenticated browser → UI → API → DB Save-as-Template/reopen, roles and partner A/B ACL, copied binary-source/image fidelity, customer DOCX/PDF visual pagination/no NET leak, real device/PWA update and offline/network recovery, owner signoff VIE-11.

The disposable native/HTTP suites must never be pointed at an existing staging clone or production database. Their setup creates synthetic random vta_test_* schemas. Host/migration guards remain intact; do not bypass the current CyberPanel controller branch pin. Keep Draft / release NO-GO until operator evidence is complete.
