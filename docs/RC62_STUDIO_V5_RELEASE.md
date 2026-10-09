# RC6.2 Tour Workspace and Proposal Studio V5

This release integrates the approved unified demo into the PHP/JavaScript
application on `codex/Vietnam/rc6.2-testing`. The previous branch contained the
Word editor but not the unified demo interface, so redeploying that commit
correctly returned `UNCHANGED`.

## Delivered behavior

- Navigation groups Dashboard, Sales & B2B, Tour Workspace, Tour Library,
  Suppliers, Marketing, Reports and Settings according to effective permissions.
- Tour records share Program, Costing, Quotation, Booking, Operations and Files
  navigation. Existing quoting, confirmation, booking, Marketing and AI modules
  remain the underlying workflows.
- Proposal Studio uses the existing single ProseMirror document and real proposal
  API. Import Word/PDF, paste content, insert a day, edit tables/images, save and
  export native editable DOCX or printable PDF from the same document.
- Library programs open in the same Studio. A reusable master and its quote copy
  save independently. Quote-bound images are cloned into company draft media
  before becoming reusable; existing access and approval rules still apply.
- Library updates carry a content hash. A stale save preserves the server version
  and leaves the operator's local document available for review/retry.
- The cost screen presents three options, per-destination hotel/cruise inputs,
  mixed tiers and common services. Supplier/evidence, revisions and calculated
  totals still come from the existing smart-costing API. Pending writes finish
  before navigation; SENT documents and cost controls remain read-only.
- Sales & B2B uses actual agent, CRM and rate records available in this branch.
  The standalone demo's portal routes and browser-only persistence are excluded.
- Versioned assets and the new service-worker cache refresh the installed UI.
  PDF import uses the same pinned PDF.js version's compatibility build for older
  browsers.

## Storage and deployment scope

No SQL migration, runtime configuration change or database reset is included.
The existing `tour_library_programs.source_text` MEDIUMTEXT field carries a
versioned envelope with the original source text and validated `VTA_DOC_2`
document. Plain legacy programs remain readable; metadata-only legacy clients
preserve existing rich documents. Gallery reads request metadata instead of full
document payloads.

Deploy only to `https://v2quote.vietnamtraveladvisor.com.vn` using the pinned
PowerShell script. It defaults to the verified SSH site user `vquot8508`, retains
the private deployment controller, backs up changed source files and refuses
migration/runtime changes. No production deployment or scheduled trigger is
part of this release.

## Validation

The frontend suite, library contracts and typed-document PHP checks pass locally.
The additional Studio tests cover source preservation, malformed nodes, stale
save protection, server-owned cost totals, immutable controls and safe template
retry after an uncertain network response.

Chromium browser acceptance passes for the actual editor, PHP document validator,
Word parser and native DOCX/PDF exporters: import, edit, save, refresh, reopen,
export, independent masters, stale conflicts, unified navigation and mobile menu.
The browser fixture uses loopback-only synthetic file storage without database
credentials. It does not establish live MySQL or hosting workflow acceptance.

Run from `tests/` with installed dependencies:

```sh
npm test
php document-freeform-unit.php
php program-studio-unit.php
PHP_CLI=php CHROME_PATH=/path/to/chromium \
PLAYWRIGHT_MODULE=/path/to/playwright \
FREEFORM_FIXTURE_DIR=/absolute/private/test-directory \
node run-studio-browser.cjs
```

The private fixture directory must already exist outside the application root.
The browser runner starts and stops its own local PHP server. No live credentials
are required. Server deployment and owner testing remain necessary after running
the updated PowerShell script.
