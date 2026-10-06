# VS2.2 additive schema and API

## Schema

Migration 029 creates five tables; 030 creates one. No existing column or historical row is rewritten. Foreign keys use unsigned BIGINT matching their existing parents and restrictive deletion. There are 16 FK columns in total.

| Table | Purpose and relationships | Nullable fields | Compatibility / snapshot impact |
|---|---|---|---|
| media_assets | Company, owner, optional quote; immutable original/optimized/thumbnail private paths and hashes; dimensions/size/MIME; visibility/status/source; destination/category/service/property/tags/markets; optional supersedes/reviewer | quote_id, supersedes_id, reviewed_by | Starts empty. No image bytes in MariaDB. New assets replace old versions; existing snapshot hashes continue to bind old bytes |
| media_favourites | Composite asset/user key | None | User preference only; no proposal mutation |
| media_drive_folders | Company/folder unique mapping; destination/category/tags and creator | None | Per-company selective allowlist; no global sync |
| media_drive_links | Asset primary key, mapping FK, source file ID/name/modified time, linked flag/status/check time | last_checked_at | Source updates create a new asset with supersedes_id; issued assignments remain frozen |
| media_assignments | Existing quote_version FK, asset FK, role/day_key/reference/caption/order; unique version/asset/role/day/reference | None; unused day/reference are empty strings | Independent from schedule JSON; only mutable draft assignments are edited; existing revision flow copies assignments |
| proposal_public_links | Company/version FK, unique SHA-256 token hash, expiry/revocation and creator | revoked_at | Only immutable sent bundles are exposed. No plaintext tokens stored; expired/revoked links return generic 404 |

Draft configuration reuses `quote_versions.proposal_json` only when `schema=VS2_2`. Public representation reuses `quote_sent_bundles.public_snapshot_json`; internal bundles retain existing costing/FX evidence. Existing `quote_acceptances` and `booking_quote_snapshots` remain unchanged. Legacy versions without VS2_2 configuration keep their established export and revision behavior.

029 adds media.view/manage/approve. 030 adds proposal.edit/send/white_label. Existing quote editors/senders receive the corresponding presentation capabilities; company approval/white label use existing ADMIN/CEO roles. Explicit user DENY remains effective. New roles must be assigned these capabilities deliberately.

## Authenticated routes

All routes use the existing API dispatcher, tenant identity, session, CSRF protections and audit mechanism. Route URLs follow `api/index.php?route=...`.

| Method / route | Contract / permissions |
|---|---|
| GET media | media.view; safe paginated items (40), destination/category/favourite filters; quote_id scopes personal quote assets |
| POST media/upload | media.manage plus view; multipart `file`, title/visibility/quote_id and metadata; original preserved; 201 safe DTO |
| GET media/{id} | Owner-personal or approved company/approver projection; no filesystem paths or hashes |
| GET media/{id}/thumbnail or image | Authorized private derivative stream; no public directory |
| GET media/{id}/original | media.manage plus asset access; attachment only |
| PATCH media/{id} | Manage own permitted metadata; media.approve for company approval; asset bytes remain immutable |
| POST media/{id}/favourite | Current user's preference |
| GET media-drive | media.view; `available` and company mappings; unavailable auth is a setup message |
| POST media-drive/mappings | media.approve; configured allowed folder only |
| GET media-drive/{mapping}/files | Company mapping; folder parent/MIME checks; 40-item page |
| POST media-drive/{mapping}/import | media.manage; 1–10 selected IDs; import-once or linked; individual errors |
| POST media-drive/assets/{asset}/check or sync | Authorized linked asset; changed status or newly imported immutable asset |
| GET quote-versions/{id}/proposal | sales.view; settings/days/assignments/requirements/safe offered variant choices/revision/immutable flag; cost permission projects internal fields |
| PUT quote-versions/{id}/proposal | sales.view, quote.edit, proposal.edit; expected_revision/settings/days/links; optional white_label permission; existing version lock/stale-write rejection |
| GET quote-versions/{id}/proposal/html, pdf or docx | sales.view; same public candidate or immutable Sent content; unreviewed prices withheld in Draft |
| GET quote-versions/{id}/proposal/images/{asset} | Image must belong to that candidate/snapshot and match its hash |
| GET/POST quote-versions/{id}/proposal/links | Existing quote.send plus proposal.send for creation; Sent only; expiry 1–90 days; creation returns token URL once |
| DELETE quote-versions/{id}/proposal/links/{link} | Existing quote.send plus proposal.send; tenant/version-scoped revocation |

Invalid input is 422, missing permissions 403, unknown/inaccessible objects 404, stale or immutable writes 409, missing CSRF 419. Errors do not expose database credentials or internal paths.

## Public routes

GET `public-proposals/{64-hex-token}` and its `/pdf`, `/docx`, `/images/{asset}` children resolve an unexpired, unrevoked link only when public-link policy permits. Only the existing frozen public bundle and its assigned media are available. Responses use no-store, noindex, no-referrer, nosniff and restricted content security policy. Public links do not expose raw supplier/cost APIs or arbitrary library images.

## Drive configuration

Private `media_drive.accounts[company_id]` supports approved folder IDs and a read-only OAuth refresh token or service account. Use the Google Drive read-only scope. Server folder allowlisting is required even if a credential can see other folders. Secrets never enter JavaScript, logs, metadata DTOs or the release package. Live authorization is optional for owner testing PC/library; no credentials are seeded by migrations.
