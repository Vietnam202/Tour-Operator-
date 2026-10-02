# VTA Unified OS 3.4.0-RC6.2 — VS1

Mở **START_RC6_2.html** để bắt đầu. Bản này kế thừa toàn bộ VS0 và bổ sung
Marketing → Sales Accept/Return/Resubmit → review danh tính → Customer/Inquiry + next action,
cùng Customer 360 theo quyền từng section. Giữ shared entities và 15 thẻ Operations.

Migration mới **022_lead_sales_handover.sql**; 001–021 giữ nguyên byte. Cần backup
database/private storage và chạy migration runner trước dùng server. Preview dùng dữ liệu
mẫu trong tab. Chưa nâng hosting hoặc gọi OAuth/social/AI provider bên ngoài.

Chi tiết mới: **docs/RC6_VS1_RELEASE_VI.md**, **docs/RC6_VS1_ARCHITECTURE_VI.md**,
**verification/RC6.2/RC6_VS1_TEST_REPORT_VI.md**. VS2 trở đi còn trong kế hoạch.

Phần dưới là lịch sử VS0 đã được kế thừa; các hướng dẫn START_RC6_1 và số kiểm thử
RC6.1 mô tả release trước, không phải báo cáo của RC6.2.

## Lịch sử RC6.1 — VS0

Bản ổn định RC6.1 dùng cùng kiến trúc PHP/PDO/MariaDB và các module RC5.3.

Mở **START_RC6_1.html** để xem hướng dẫn; mở **preview.html** để thử giao diện.
Home và ba workspace Marketing, Sales, Operations dùng chung CRM, kho chương trình,
nhà cung cấp, tài liệu và dữ liệu booking. Operations giữ đủ 15 thẻ công cụ.

VS0 sửa quyền/deep links, giữ liên kết nhà cung cấp khi review tài liệu, kiểm tra
owner/customer/agent cùng công ty, giữ phân loại khách trong snapshot báo giá đã chấp nhận,
bảo toàn nguồn chương trình khi tạo revision, khóa các writer tài chính và dùng cùng
readiness policy cho API/cron. Reports nhóm theo currency; actual chưa chốt không hiện thành 0.

Migration **001–021 giữ nguyên**, không có migration mới trong VS0. Sent/accepted/issued
snapshots cũ không được viết lại. Cache PWA có mã VS0 riêng để thay các asset RC6.1 cũ.

Kết quả: **1.231 lượt assert PASS**, gồm JS/PHP/SQLite, MariaDB thật, API login/CSRF/RBAC,
company isolation, immutable snapshots, multipart CSV upload, scoped document review và
InnoDB lock-wait. Đây là lượt kiểm tra, có chạy lại một số regression trên các runtime.
Chi tiết: **verification/RC6.1/RC6_VS0_TEST_REPORT_VI.md** và **release-validation.json**.

Preview dùng dữ liệu mẫu trong tab. Bản server cần cấu hình riêng ngoài webroot;
hướng dẫn và rollback tại **docs/RC6_VS0_RELEASE_VI.md**. Chưa upload lên hosting.
VS1–VS6 và các kết nối provider thật vẫn trong kế hoạch. Docs/verification RC1–RC5 là lịch sử.
