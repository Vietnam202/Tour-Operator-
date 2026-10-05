# RC6.2 VS2.2 — Verification

Implementation 05/10/2026 trên VS2.1 `8e0123b`. Tất cả supplier/rate/media và Drive provider responses dùng trong tests là fixture tổng hợp. Chưa merge/deploy production, chưa test live Drive VTA.

| Check | Kết quả / evidence |
|---|---|
| PHP lint | 90 files parsed với PHP8.3.6; `php-lint.txt` |
| Media / proposal integration | MariaDB: private thumbnails, PNG→JPEG optimization, duplicate detection, tenant/user isolation, approval, favourite, SVG/size rejection, stable day IDs, public field whitelist, 4 templates, embedded PDF/DOCX, approval invalidation, immutable sent media, token revoke/expiry, revision; `test-vs2-media-db.txt` |
| Drive adapter | Fake HTTP provider, real adapter: folder-constrained list, allowlist, actual parent/MIME validation, immutable sync replacement; `test-vs2-media-db.txt` |
| Upgrade023→024 | Only024, rerun no-op, historical JSON/hash bytes unchanged; explicit ALLOW/DENY override mapping; no silent proposal opt-in; `test-vs2-media-upgrade.txt` |
| Regression DB/SQL | 15 suites pass, gồm VS2.1 và legacy VS1/commercial/finance/operations; MariaDB và các SQLite fixtures được ghi rõ; `db-results.json` |
| JS/DOM | Full npm test, thêm visual proposal suite; `js-tests.txt` |
| Real browser + HTTP | 17 checks; Chromium153; PC upload/review/assignment, day operations, previews/exports, all templates, anonymous link/image, archive/revoke, revision, CSRF419, permission403, no uncaught JS; `browser-results.json`, `browser-test.txt` |
| Desktop / mobile | 1440×1100 và 390×844, không outer page overflow; screenshots |
| PDF / DOCX quality | PDF render kiểm tra layout/image/tables; pdftotext đọc đúng `Hạ Long`; DOCX XML/relationships parse và JPEG embedded; `output-validation.txt` |
| Migration integrity | 001–023 byte-for-byte khớp baseline VS2.1; `migration-integrity.json` |

Browser runner: `tests/vs2-proposal-browser.cjs`, cần disposable fixture của `tests/http/setup.php`; dùng `VTA_TEST_BASE_URL`, `VTA_TEST_CHROME` nếu browser binary custom. MariaDB tests yêu cầu `VTA_TEST_DB_PASSWORD` và local disposable MariaDB tại127.0.0.1:33317. Npm tests trong `tests/`.

Giới hạn thực tế: provider fixture không chứng minh quyền Drive, quota hoặc network trên VPS VTA. Cần staging credentials/company allowlist để chạy thử real folder. Ảnh test là synthetic, giá test không dùng để bán tour. VS2.3 pax bands/commercial policies và VS2.4 email/Operations vẫn chưa triển khai. Không có email outbound trong verification.
