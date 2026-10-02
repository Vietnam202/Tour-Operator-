# RC6 — VS1: kiến trúc và tiêu chí trước triển khai

Baseline: RC6.1 VS0 đã kiểm thử; đọc lại Project Instructions, Start Prompt,
Master Orchestrator, Repo Audit, Blueprint, Delta Register và kế hoạch vertical slice.

| Phần | Gap | Tái sử dụng và thay đổi |
|---|---|---|
| Campaign/form/request/attribution | EXISTS | Giữ LeadHub, campaigns, lead_forms, lead_requests; không sửa nguồn đã tiếp nhận. |
| Qualify và convert | ENHANCE | Giữ leads và inquiry/trip; thêm bước Sales nhận/trả, version và retry có lịch sử. |
| Owner/SLA/next action | ENHANCE | Giữ users, tasks, inquiries.next_action; task phát sinh từ transition trong cùng transaction. |
| Nhận/trả/resubmit có lý do | MISSING | Migration 022+ cho trạng thái bàn giao, command receipts và lịch sử; không tạo CRM/opportunity master khác. |
| Kiểm tra trùng khách | ENHANCE | Gợi ý email/điện thoại cùng công ty; người dùng chọn khách hiện có hoặc xác nhận tạo mới. Không ghép tự động theo tên. |
| Customer 360 | MISSING | Projection từ customers → trips/inquiries/leads/quotes/bookings/tasks/documents/invoices; kiểm tra quyền từng section. |
| Domain event/task dedupe | MISSING | Outbox và execution keys tối thiểu trong transaction; dùng shared tasks/Audit, chưa gửi provider. |
| Merge phá hủy, alias/preferences đầy đủ | FUTURE | Slice đầu chỉ liên kết danh tính có review; giữ mọi lịch sử khách/booking. |
| CRM hoặc kho task phòng ban riêng | IGNORE | Không tạo nguồn dữ liệu thứ hai. |

## Luồng

Campaign → Request NEW → Qualify → PENDING Sales review → ACCEPTED hoặc RETURNED.
RETURNED cần lý do, Marketing bổ sung qualification rồi resubmit. ACCEPTED mới được
review danh tính và convert thành một Customer + Trip/Inquiry + next action.
Retry không tạo bản ghi downstream thứ hai. Lead đã convert ở bản trước vẫn đọc/retry
được; không tự đánh dấu lịch sử cũ thành accepted hoặc sửa snapshot đã gửi.

## Tác động

Backend: mở rộng LeadHub, helper bàn giao/outbox mới, Customer360 read service.
Schema: additive 022 trở lên; 001–021 byte-identical. UI: shared Marketing/Sales
handover cards, Customer360 từ CRM, Preview dùng dữ liệu mẫu trong phiên.
Quyền: lead.view/lead.manage, quyền nhận/trả Sales riêng, inquiry.manage khi convert,
customer.manage khi tạo customer; sales.view để đọc customer. Effective DENY áp dụng
trên server, không dựa vào nút UI. Customer360 dùng field allowlist, không trả raw
audit JSON, passport, notes hội thoại hay payment details cho vai trò thiếu quyền.

## Acceptance trước code

1. Qualify → accept/return → resubmit → convert giữ campaign/source và cùng ID downstream.
2. Retry cùng command không duplicate task/event/trip/inquiry/customer; stale version và
   payload khác phải conflict, failure rollback cả state/history/effect.
3. Lead chưa được Sales nhận không convert; returned cần lý do và requalification.
4. Email/phone suggestions chỉ cùng company; không tự merge vì tên giống; chọn khách
   inactive/foreign-tenant và owner/agent giả bị từ chối trước INSERT.
5. Customer360 không lộ section bị DENY, chi phí/profit/passport/notes/files private;
   finance có quyền thì nhóm theo currency, quote chỉ có selling.
6. Fresh/upgrade MariaDB, dispatcher login/CSRF/RBAC, JS UI/Preview, browser responsive;
   hồi quy quote snapshots/finance locks/VS0 permissions phải tiếp tục đạt.

## Release boundary

Hoàn tất VS1 trước khi bắt đầu Program-to-Quote VS2. Không tự triển khai lên hosting
hoặc gọi OAuth/social/AI provider trong slice này. Có migration mới nên rollout cần
backup database/private storage và chạy migration runner; rollback app không xóa bảng mới.
