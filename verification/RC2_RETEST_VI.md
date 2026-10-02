# RC2 — kiểm tra lại ngày 27/09/2026

Kết luận: vẫn là integration candidate, chưa đạt cổng phát hành production.

## Sửa trong lần kiểm tra này

1. Menu điện thoại thiếu đường vào Marketing: bổ sung theo quyền lead.view và bố cục tự chia số cột.
2. preview.html không nạp marketing.js: thêm trước app.js.
3. Test database vẫn kỳ vọng 16 migration: cập nhật lên 17, bổ sung tên migration 017 vào kỳ vọng nâng cấp. Đây là sửa test; không phải bằng chứng migration đã chạy thành công.

## Đã chạy

- `node tests/frontend.cjs`: 7 kiểm tra PASS (4 file JS, tham số route/query, CSRF request, phản hồi lỗi).
- `node tests/marketing-unit.cjs`: 9 kiểm tra PASS (escape markup, trạng thái nút, submit, approve, reject, chỉ đọc, Lead Hub, preview không gọi API, dây nối mobile/preview). Sử dụng DOM/API mô phỏng.
- `node --check marketing.js`: PASS.
- Bảng migration gốc 001–016 không đổi so với RC1.

## Chưa xác minh

- `tests/marketing-ui.cjs`: chưa chạy được vì không có Chromium; tải browser không thành công. Bộ test đã được kèm để chạy ở môi trường có Playwright/Chromium.
- PHP lint, migration MariaDB thực, tenant isolation, CSRF phía server, tranh chấp duyệt đồng thời và luồng Lead → Inquiry → Booking chưa chạy trong lần này vì thiếu runtime.
- GitHub connector trả về `repositories: []`. Chưa xác định repository/commit, chưa đọc hay chạy GitHub Actions. Cần URL repository và quyền truy cập để đối chiếu.
- Composio phát hành, chuyển dữ liệu D1 và triển khai hosting vẫn chưa hoàn tất như START_HERE_UNIFIED_VI.md.

## Cách chạy kiểm tra tiếp

Trên môi trường test có PHP + MariaDB riêng: cấu hình VTA_TEST_DB_PASSWORD và database server localhost:33317 theo tests/lead-hub.php; chạy tests/upgrade.php, tests/rc1.php và các regression liên quan. Không trỏ các fixture vào database đang vận hành.

Với Playwright đã cài và Chromium sẵn sàng: `node tests/marketing-ui.cjs`. Không coi các test mô phỏng là kiểm chứng phân quyền backend.
