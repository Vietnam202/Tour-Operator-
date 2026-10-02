# Marketing Preview 1 — 27/09/2026

Sửa nguyên nhân Marketing chỉ hiện thông báo cần PHP trong `preview.html`. Thêm adapter dữ liệu mẫu nằm trong bộ nhớ, dùng cùng giao diện Marketing với bản server. Không đổi schema hoặc API PHP.

Đã chạy lại 5 bộ kiểm thử JavaScript: frontend, marketing-unit, marketing-preview, ai-ui, pwa. Tất cả PASS. Riêng bộ marketing-preview có 8 kiểm tra cho dữ liệu mẫu, tạo chiến dịch, escape văn bản, 7 kênh, tạo nháp, duyệt/từ chối, revision, reset và không gọi mạng.

Kiểm thử giao diện dùng jsdom, không phải trình duyệt render thật. Không gọi AI hoặc triển khai hosting. Các kiểm thử PHP RC3 trước đó và giới hạn của chúng ghi trong `verification/RC3_TEST_REPORT_VI.md`.
