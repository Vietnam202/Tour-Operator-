# Kiểm thử RC6.1 — VS0

**PASS 1.231 lượt assert** (JS/PHP WASM/SQLite 713; native PHP/MariaDB/API 518).
Tổng có một số regression chạy lại trên runtime khác, không phải 1.231 case độc lập.

| Nhóm | Runtime / kết quả |
|---|---|
| 18 JS suites + 9 PHP suites | Node/jsdom và PHP 8.3 WASM/SQLite: 713 PASS |
| Existing business flow / legacy costing / upgrade / installer / guard | PHP 8.3.30 + MariaDB 11.4.8, database local dùng một lần: 183 PASS |
| VS0 financial/readiness/concurrency | InnoDB: 70 PASS; real lock-wait timeout 1205, rollback, committed close marker và fresh reads |
| Full → extended → VS0 HTTP dispatcher | Login, CSRF, RBAC, ADMIN DENY, tenant, 300+ rows, queue/pagination/IDs, financial close: 256 PASS |
| Multipart CSV + document-only reviewer | 8 PASS; supplier list 403 nhưng review notes/status giữ supplier liên kết |
| Native syntax | 72 PHP files parsed; 20 production JS files syntax-valid |
| Migrations | 001–021 byte-identical; không có SQL mới |

Browser smoke: Home/Sales, đủ 15 Operations cards; responsive 390px iframe; login/Reports
trên API thật; đổi month sang 2027-01 cho fixture USD137.50/expected27.50/forecast17.50/
actual12.50; popup Documents giữ supplier1 và REJECTED. Các screenshots ở cùng thư mục;
ảnh Sales được capture ở chiều rộng compact khi panel trình duyệt đổi kích thước.

Các log và `release-validation.json` chứa bằng chứng theo suite. Test dùng dữ liệu tổng hợp,
database mới trên 127.0.0.1:33317 và HTTP 8873, không truy cập database/hosting thật của anh.
PHP/MariaDB portable được đặt ngoài source/webroot; credentials/runtime không có trong ZIP.

Giới hạn: chưa xác minh restore dataset production; chưa gọi provider thật; CSV multipart
đã test nhưng các format/library parser khác kiểm tra bằng fixture riêng; browser iframe
không thay thế kiểm thử cài PWA trên điện thoại vật lý. RC1–RC5 logs là lịch sử.

Chạy lại: `tests/package.json` cho JS; các PHP tests phù hợp; với database disposable chạy
`tests/http/setup.php`, bật PHP local8873, rồi `tests/http/vs0-http.ps1` và `document-review.ps1`.
Đặt VTA_TEST_DB_PASSWORD, VTA_TEST_PHP và VTA_CONFIG_FILE bên ngoài webroot.
`vs0-finance-operations.php` có mode MariaDB chỉ chấp nhận database local mới/rỗng
`vta_vs0_<hex>`; lần chạy mới dùng database mới. Không chạy fixture trên production.
