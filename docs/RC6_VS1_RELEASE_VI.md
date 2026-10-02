# RC6.2 — VS1: Marketing → Sales → Customer 360

Version 3.4.0-RC6.2; build/cache VS1.1. Tiếp tục trên RC6.1 VS0, cùng PHP/PDO/MariaDB,
vanilla JS và shared records; giữ ba workspace và 15 thẻ Operations hiện có.

## Kết quả sử dụng

Marketing mở Lead Hub từ lead hoặc hàng chờ Sales, đánh giá nhu cầu, chọn người phụ trách
và hạn xử lý. Lead chuyển sang Chờ Sales nhận. Sales nhận với owner/hạn xử lý hoặc trả
lại có lý do; Marketing bổ sung qualification và bàn giao lại trên cùng lead.

Sau khi Sales nhận, chọn Review khách & tạo inquiry. Hệ thống gợi ý khách có email hoặc
điện thoại trùng trong cùng công ty; không tự ghép theo tên. Người dùng chọn khách hiện
có hoặc xác nhận tạo khách mới. Tạo mới khi có liên hệ trùng cần lý do và quyền quản lý
Customer. Review có thời hạn 15 phút; thay đổi danh sách gợi ý hoặc trạng thái lead cần
review lại. Inquiry, trip, customer và next action dùng cùng ID, giữ campaign/source gốc.

CRM → bấm tên khách mở Customer 360. Các phần lead, inquiry, quote, booking, task,
tài liệu và hóa đơn tuân quyền riêng. Không trả passport, raw audit JSON, snapshot tài
liệu, private storage URL, notes hội thoại hay cost/profit trong projection này.
Task chỉ của người dùng hiện tại; quyền approval.manage cho phép xem cả nhóm.
Finance hiển thị số dư hóa đơn phát hành theo từng currency, không phải full statement.

## Schema, API và reuse

Migration mới **022_lead_sales_handover.sql**; 001–021 giữ nguyên byte so với RC5.3.
022 thêm version/status/owner/due vào lead, lịch sử bàn giao, identity review receipts,
domain_outbox và domain_execution_keys. Outbox APPLIED chỉ nghĩa effect nội bộ đã hoàn
tất; không có tin nhắn hay giao nhận provider bên ngoài. Tasks/Audit hiện có được reuse.

API: mở rộng GET lead-hub/requests và lead-hub/leads với paging/total/history;
GET lead-hub/owners; POST requests/{id}/qualify; POST leads/{id}/accept, return, resubmit;
GET leads/{id}/customer-candidates; POST leads/{id}/convert;
GET customers/{id}/360. Prefix lead-hub áp dụng cho các route lead.
Các lệnh có expected_version/action_key, role check và CSRF qua dispatcher thật.
Quyền mới lead.sales_accept cấp mặc định ADMIN/SALES khi migrate; user DENY vẫn ưu tiên.

Lead đã convert từ bản cũ giữ nguyên inquiry/trip/snapshots và có thể retry đọc ID.
Lead cũ mới qualified sẽ ở PENDING, cần Sales review/owner/hạn xử lý trước conversion.
Retry cùng command trả kết quả đã commit; payload khác cùng key hoặc version cũ conflict.
Task/history/event/customer/trip/inquiry cùng transaction: lỗi downstream rollback toàn bộ.
Guest segments adults/children/infants không suy đoán từ tổng khách; commercial review
và các guard của VS0 vẫn áp dụng trước gửi/accept báo giá.

Source chính: LeadHub.php, LeadSalesHandover.php, DomainOutbox.php, Customer360.php,
bootstrap.php/index.php, app.js, workspace-centers.js/css, workspace-demo.js và PWA cache.
Kiến trúc trước code: RC6_VS1_ARCHITECTURE_VI.md. Kiểm thử: verification/RC6.2.

## Nâng cấp và rollback

Trước nâng staging: backup database, private storage và config; thử restore bản backup.
Giải nén vào thư mục mới, nối private config ngoài webroot theo V3_STAGING_UPGRADE.md,
chạy api/bin/migrate.php trong môi trường đã cấu hình. Runner ghi checksum và chặn
partial migration; không chạy lại SQL bằng tay nếu 022 dở, dùng backup và điều tra log.
Không deploy tests, verification hoặc portable QA runtime lên production webroot.

Tạo pilot với role Marketing/Sales, kiểm tra đúng quyền lead.view, lead.manage,
lead.sales_accept, sales.view, inquiry.manage, customer.manage theo công việc. Chọn
người phụ trách có quyền phù hợp; tài khoản chỉ đọc không có nút ghi dữ liệu.
Lưu việc đang làm rồi reload khi PWA báo cập nhật để nhận cache RC6.2-VS1.1.

Rollback app bằng release backup; giữ các bảng/cột 022 và lịch sử mới. Sau rollback,
LeadHub cũ không có gate Sales accept: hạn chế quyền convert khi dùng bản cũ, hoặc
restore database/private files từ backup đã kiểm chứng cùng app nếu cần rollback toàn
bộ. Full restore phải xét giao dịch mới để tránh mất dữ liệu. Không DROP các bảng mới.

## Phạm vi còn lại

VS1 này hoàn tất luồng bàn giao và liên kết danh tính có review. Destructive customer
merge, alias/preferences đầy đủ, message delivery/provider receipt và automation framework
tổng quát chưa triển khai. Preview là dữ liệu mẫu trong tab, không lưu database.
VS2 (Program → Quote), VS3 handover Operations, VS4 amendment, VS5 actual execution,
VS6 closeout/feedback vẫn là các slice tiếp theo. Chưa triển khai lên website chính thức.
