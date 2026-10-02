> Historical v2.x reference only. For this v3 staging candidate, follow V3_STAGING_UPGRADE.md and V3_AUDIT.md instead of the deployment instructions below.

# VTA v2 Phase 1 — QA

## Automated/static checks run for this package

- PHP syntax lint: PASS for all PHP source files.
- JavaScript syntax check with Node: PASS.
- `manifest.json` parse: PASS.
- ZIP integrity: run at packaging time.
- No production database password, Google OAuth secret, refresh token, service-account private key or OpenAI API key is included in the package.

## Business rules implemented and reviewed in code

- Supplier file upload does not approve a rate.
- Human Rate Approval is a separate API action protected by `rate.approve`.
- Non-Manual rates require a source document before approval.
- Special Quote requires a linked Trip/Inquiry reference and is non-reusable.
- New approved rate version supersedes prior approved version rather than deleting it.
- Supplier edit uses `version_no` to detect stale concurrent updates.
- Server performs permission checks; frontend visibility is not the security authority.
- Original uploaded file is stored before the document transaction is created.
- Google credentials are read only from private server configuration.
- Rate expiry task worker deduplicates open expiry tasks.

## Tests that require the user's staging server

These cannot be truthfully certified in the build container because the production MariaDB, PHP-FPM and Google Drive credentials are not available here:

1. MariaDB migration execution on the actual server version.
2. Login/session cookie behavior under the actual HTTPS domain.
3. PHP-FPM access to the private config path.
4. Google OAuth/service-account upload into the VTA Drive folder.
5. Google Drive download/stream through the authenticated API.
6. CyberPanel cron execution.
7. Database backup/restore on the VPS.
8. Concurrent-edit test from two real browser sessions.

These must be completed in staging before Product cutover.

## Parser smoke tests executed in the build environment

The Phase-1 extraction preview was tested against representative files already available in the project workspace:

- DOCX: PASS — text extracted with `MEDIUM` review quality.
- XLSX: PASS — multiple worksheets extracted into a tabular text preview with `MEDIUM` review quality.
- Searchable PDF: PASS — text extracted with `MEDIUM` review quality.

These are intentionally classified as review-quality extracts, not authoritative rate imports. Original-file comparison and human approval remain required.
