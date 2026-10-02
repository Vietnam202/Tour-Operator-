# RC4 Marketing Studio — kiểm thử ngày 28/09/2026

**10 bộ kiểm tra cục bộ PASS.** Log và số nhóm kiểm tra ở `verification/rc4/`. Không tương đương chứng nhận chạy trên hosting hoặc kiểm thử tải thực.

| Bộ kiểm tra | Phạm vi |
|---|---|
| PHP lint | 57 file PHP parse bằng PHP 8.5.10 WASM |
| Runtime guard | 5 nhóm kiểm tra cấu hình riêng và giới hạn staging |
| AI provider | 27 nhóm kiểm tra định dạng API, giới hạn, phản hồi và owner scope |
| Marketing rules/storage | 26 nhóm kiểm tra metadata, UTC, media URL, batch/retry, rollback, tenant/campaign, revision và chuyển Lead Hub |
| Frontend | Parse 10 file JS, route/query, CSRF, lỗi API |
| Marketing legacy | 9 nhóm kiểm tra giao diện/duyệt cũ, vẫn giữ để hồi quy |
| Marketing preview legacy | 8 nhóm kiểm tra adapter demo RC3, không phải giao diện Studio mới |
| Marketing Studio UI | 14 nhóm: múi giờ, lịch lặp, 8 tab, nhiều kênh, duyệt, revision, kho, lịch, phân công, chuyển lead và không gọi mạng trong demo |
| AI UI / app shell | 9 nhóm kiểm tra AI và menu shell với các script Studio mới |
| PWA | 5 nhóm kiểm tra precache, không cache API/dữ liệu riêng, offline và icon |

Các thao tác lưu batch và chuyển lead được thực thi trên PDO SQLite với dữ liệu giả. Adapter test chỉ thay cú pháp INSERT IGNORE/FOR UPDATE/NOW để kiểm tra logic và lưu dữ liệu tuần tự. **Không xác nhận hành vi khóa hoặc concurrency của MariaDB.** Các phép gọi HTTP, authorization middleware và session cần kiểm thử riêng trên staging.

jsdom kiểm tra DOM và sự kiện; không render CSS như trình duyệt thực. Chưa xác nhận giao diện trên thiết bị thật, cài PWA hoặc cập nhật giữa nhiều tab. Chưa gọi OpenAI thật, đăng mạng xã hội, đồng bộ inbox hoặc nhập tài khoản OAuth.

Migration 019 chưa chạy trên MariaDB/hosting. Migrations 001–018 được so sánh nguyên byte với gói RC3 PreviewFix. Không có khóa API, dữ liệu khách thật hoặc thông tin đăng nhập được thêm vào gói.

Tái chạy JavaScript: `npm install --prefix tests` rồi `npm test --prefix tests`. PHP: `php tests/php-lint-all.php`, `php tests/runtime-guard.php`, `php tests/ai-provider.php`, `php tests/marketing-rules.php` (cần PDO SQLite cho fixture). Môi trường tạo gói dùng Node 24.19 và @php-wasm/cli 3.1.55.

Các tài liệu/log RC1–RC3 là lịch sử. Hướng dẫn triển khai và giới hạn tính năng mới: `docs/START_HERE_RC4_VI.md`.
