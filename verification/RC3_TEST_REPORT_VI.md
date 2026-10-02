# Kiểm thử RC3 — 27/09/2026

**7 bộ kiểm tra cục bộ PASS.** Đây là ứng viên triển khai staging; chưa chứng nhận chạy trên hosting/thiết bị thật.

| Bộ kiểm tra | Phạm vi |
|---|---|
| PHP lint | Toàn bộ file PHP parse bằng PHP 8.5.10 WASM |
| AI provider & ownership | 27 kiểm tra: cấu hình, giới hạn, payload, parser, dữ liệu sai, quyền/chủ hội thoại |
| RuntimeGuard | 5 kiểm tra: cấu hình riêng, giới hạn môi trường |
| Frontend | Parse 7 JavaScript; route/query, cookie, CSRF, lỗi API |
| Marketing | 9 kiểm tra giả lập: escape, trạng thái, điều khiển theo quyền, duyệt |
| AI UI | 9 kiểm tra jsdom: xác nhận, retry, bấm lặp, lưu nháp, khóa AI, demo, chuyển trang, menu |
| PWA | 5 kiểm tra giả lập: tài nguyên, loại trừ API, offline, cache riêng, kích thước icon |

Log mới ở `verification/rc3/`. Log RC1/RC2 bên ngoài thư mục này là lịch sử.

Các truy vấn ownership và quyền thực thi qua PDO SQLite với dữ liệu giả; **không thay thế MariaDB integration**. jsdom không kiểm tra render CSS hoặc API cài ứng dụng trên thiết bị thật. Service worker chạy trong môi trường mô phỏng.

Chưa chạy: migration 018 trên MariaDB; GET_LOCK/hạn mức dưới tải đồng thời; HTTP session trên web server; OpenAI key/model thật; cài/cập nhật Android/iOS; gửi/đăng qua Composio. Không phát sinh lượt AI hay đăng quảng cáo trong lần kiểm thử.

Test upgrade được cập nhật tới 018 nhưng chưa thực thi do thiếu MariaDB. Migration 001–017 được so sánh nguyên byte với gói RC2 đã tải. `rc3/changed-files.json` ghi thay đổi nguồn. Tái chạy theo `tests/README_RC3.md`; kiểm tra hosting theo `docs/START_HERE_RC3_VI.md`.
