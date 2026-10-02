> Historical v2.x reference only. For this v3 staging candidate, follow V3_STAGING_UPGRADE.md and V3_AUDIT.md instead of the deployment instructions below.

# QA — VTA v2.0 Phase 1 Build 2

## Static checks performed

- PHP syntax lint across all PHP files.
- JavaScript syntax check with Node.
- Service-worker cache key bumped for Build 2.
- ZIP integrity check.
- Source-code scan for obvious frontend API-key/password embedding.

## Business rules checked in code

- Supplier source document is preserved by the existing Document Vault flow.
- Bulk import produces DRAFT / UNREVIEWED rates only.
- Legacy rows are tagged `LEGACY`; missing mandatory mapping is skipped rather than guessed.
- Legacy rate without source document cannot be approved as a normal sourced rate.
- Specialized rules belong to a specific rate version.
- New versions copy prior service rules unless explicitly updated.
- Conflict detection does not overwrite existing approved rates.
- Conflict approval requires explicit reviewed confirmation.
- Special Quote remains non-reusable and requires a linked trip/inquiry reference.
- Rate validity/source/version architecture from Build 1 remains intact.

## Server-dependent tests still required on staging

- MariaDB migration 002 against the actual CyberPanel database version.
- Google Drive upload/download using the production/staging credentials.
- End-to-end session and CSRF behavior under the staging HTTPS domain.
- Real XLSX/PDF/DOCX supplier files.
- Real Build 1 database upgrade and rollback/restore test.

Do not treat these server-dependent items as passed until the staging environment has been exercised.

## Parser regression smoke test

A generated XLSX matrix with columns `Route | 4 seat | 7 seat | 16 seat` was passed through the Build 2 `DocumentParser`. The extractor returned the expected tabular text, and the client-side matrix mapping logic identified six numeric draft-rate candidates from two route rows.

This validates the Build 2 parser-to-matrix-review path at code level; real supplier workbook layouts still require staging validation.
