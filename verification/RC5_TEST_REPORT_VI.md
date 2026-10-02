# RC5 — Báo cáo kiểm thử cục bộ, 28/09/2026

**13 bộ kiểm tra PASS, 174 nhóm kết quả PASS.** Đây là kiểm tra cục bộ, chưa phải nghiệm thu trên hosting hoặc trình duyệt/thiết bị thật.

| Bộ kiểm tra | Nhóm PASS | Kết quả |
|---|---:|---|
| ai-provider | 27 | PASS |
| ai-ui | 9 | PASS |
| frontend | 16 | PASS |
| landing-pages | 29 | PASS |
| landing-pro | 10 | PASS |
| landing-ui | 15 | PASS |
| marketing-preview | 8 | PASS |
| marketing-rules | 26 | PASS |
| marketing-studio-ui | 14 | PASS |
| marketing-unit | 9 | PASS |
| php-lint-all | 1 | PASS |
| pwa | 5 | PASS |
| runtime-guard | 5 | PASS |

PHP lint: 59 file PHP parse bằng PHP 8.5.10 WASM. JavaScript syntax: 13 script, cùng route/query/CSRF/error behavior. Các log thực tế nằm trong `verification/rc5/`.

Phạm vi mới: kéo khối từ palette vào đúng vị trí, kéo đổi vị trí, ↑/↓, nhân đôi ID riêng, xóa, undo/redo, sửa/nhập JSON, lưu/mở cùng bản demo, 3 kích thước preview, tích hợp shell thật từ thứ tự script trong `preview.html`. Mẫu Standard 6D5N và Pro, pricing item editor, deadline UTC+7, gallery, timeline, reviews và social được kiểm tra cấu trúc/render DOM.

HTML xuất dùng CSS riêng, CSP và runtime tin cậy. Kiểm tra escape HTML, link HTTPS, token form, gallery alt/quyền sử dụng, giới hạn số khối/mục, hết hạn countdown không reset, form chưa cấu hình bị khóa. Runtime form dùng mocked fetch: đúng JSON field của Lead Hub, UTM, endpoint và token; retry giữ submission key, đổi payload đổi key, thành công không gửi lại, file local không gửi vào live API. Không có lead thật được gửi trong kiểm thử.

Backend landing: validator thực thi trên PHP, lưu/update/load bằng PDO SQLite, idempotent create, optimistic version conflict, tenant/campaign scope, form thuộc chiến dịch, sai host/token bị từ chối. Auth fixture xác nhận handler yêu cầu lead.view/campaign.manage trước truy cập; đây không phải bài thử HTTP session/CSRF qua web server.

Đã sửa lỗi phát hiện trong integration: phản hồi dashboard Today đến muộn không được ghi đè workspace Marketing đã mở.

Các migration 001–019 được so sánh nguyên byte với RC4 và giữ nguyên. Migration mới 020 chưa chạy trên MariaDB. SQLite fixture chỉ đổi cú pháp INSERT IGNORE, FOR UPDATE và NOW; không xác nhận DDL/locking/concurrency MariaDB. Các tests RC4/AI/PWA được chạy hồi quy trong gói mới; không gọi OpenAI, Composio hoặc provider quảng cáo thật.

Chưa có Chromium trong môi trường kiểm thử: jsdom không xác nhận CSS render hay cảm giác kéo thả bằng chuột thật. Chưa test cài PWA/iPhone/Android, migration hosting, form nhận khách thật hay TLS hosting. Xem `docs/START_HERE_RC5_VI.md` để thử trước khi upload và nghiệm thu staging.

Tái chạy JS: `npm install --prefix tests` rồi `npm test --prefix tests`. PHP: `php tests/php-lint-all.php`, `php tests/runtime-guard.php`, `php tests/ai-provider.php`, `php tests/marketing-rules.php`, `php tests/landing-pages.php` (cần PDO SQLite). Môi trường tạo gói: Node 24.19, jsdom 26.1.0, @php-wasm/cli 3.1.55.

Log/tài liệu RC1–RC4 được giữ làm lịch sử. Phiên bản triển khai hiện tại: 3.3.0-RC5, chỉ staging/localhost.
