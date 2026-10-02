# RC6.1 — VS0: kết quả triển khai

Ngày: 01/10/2026. Version: 3.4.0-RC6.1; build/cache: VS0.

Đã đối chiếu Blueprint + Delta Register trước khi sửa. VS0 hoàn tất việc ổn định
bản đang làm, dùng lại shared records và API dispatcher; chưa triển khai VS1–VS6.

| Phần | Thay đổi đã triển khai |
|---|---|
| Home / Sales | Quyền đọc Marketing dựa vào lead.view; chuyển đúng New Leads queue; count/pagination và focused IDs dùng cùng predicate/API, xử lý response trễ. |
| Shared navigation | Ba workspace, CRM, kho chương trình tour, Suppliers & Rates, Documents; quyền ghi tách khỏi quyền xem; finance deep link có fallback theo quyền. |
| Documents | Review giữ supplier khi danh sách rỗng/không chứa supplier hiện tại; thiếu supplier.view thì không gửi field supplier_id. Giữ REJECTED/ARCHIVED và quay lại Documents sau khi lưu. |
| CRM manual create | Owner/customer/agent phải ACTIVE, cùng company; reject trước khi INSERT, không để trip/inquiry dở. |
| Quote → Booking | Option và future sent bundle đóng băng adults/children/infants/FOC; booking lấy snapshot đã accepted; revision giữ provenance của 008 và 021. |
| Financial close | Booking lock trước child rows; closed marker chặn invoice/payment/allocation/reconciliation/procurement/resource/service writers, kể cả commercial issuance và travel details. Reopen có quyền và lý do. |
| Readiness | Cùng năm checks: supplier confirmation, resource coverage/capacity, guest list, current Travel Pack, high/critical incidents. API, persisted projection và cron dùng cùng policy. |
| Reports | Nhóm booked value/profit theo currency, ngày theo tháng và cohort rõ ràng; actual chỉ tổng booking đã chốt. Null/pending/restricted không biến thành 0. |
| PWA | Cache VS0 riêng; API, writes và private exports không được cache. |

Phạm vi source chính: app.js, workspace-centers.*, workspace-demo.js, service-worker.js,
CoreOS.php, QuoteOptions.php, FinanceLedger.php, Procurement.php, OperationsControl.php,
InvoiceCommercial.php, ServiceTravelDetails.php, cron.php; helpers mới TenantLinks,
BookingIntegrity và BookingReadiness. Reuse Auth/Audit/TravelDocuments/ScheduleImport/
TourLibrary và shared entities, không tạo ứng dụng hoặc database phòng ban riêng.

## Báo giá cũ thiếu phân loại khách

Nếu bundle đã gửi thiếu adults/children/infants và chưa có booking, API trả
`PAX_SEGMENT_REVIEW_REQUIRED` (409). Tạo revision, review phân loại khách, recost option,
approve, send và accept lại rồi tạo booking. Không suy đoán từ bản mutable hiện tại,
không backfill hoặc re-hash snapshot đã phát hành. Booking đã có và issued snapshot cũ
được giữ nguyên; retry đọc lại dùng dữ liệu lịch sử của chúng.

## Quy tắc khóa và ngoại lệ

Tài chính đóng thì các writer liên quan phải reopen trước khi sửa. Historical GET,
statement và tài liệu đã issued vẫn đọc được theo quyền. Có thể ghi nhận incident mới
sau close để lưu sự cố; resolution có financial_impact cần reopen. Readiness projection
không tự kéo ON_TOUR/OPERATION_COMPLETED/COMPLETED/CANCELLED về phase trước.
Transactions booking-owned trên MySQL dùng READ COMMITTED cho transaction kế tiếp;
không đổi isolation global hoặc default của connection. InnoDB lock-wait đã được test.

## Schema, staging và rollback

Không thêm migration. 001–021 byte-identical so với RC5.3; upgrade/install fixtures thật
đã chạy trên MariaDB riêng. Trước đưa lên staging của anh: backup database + private storage
+ private config, giải nén vào thư mục mới, cấu hình PHP 8.3/PDO MySQL và storage ngoài webroot,
chạy migration runner/healthcheck theo `V3_STAGING_UPGRADE.md`, kiểm tra login/quyền và một
booking có dữ liệu thật. Runtime examples không được dùng làm credentials production.

Không deploy tests hoặc runtime QA lên webroot production. Config `allowed_extensions`
phải chứa các loại file anh muốn upload; fixture CSV đã kiểm tra cấu hình này. Sau nâng cấp
PWA, lưu việc đang làm rồi chọn Reload khi có thông báo cập nhật.

Rollback application bằng bản RC5.3 đã backup; không xóa migrations/tables để rollback.
Schema không đổi nên dữ liệu/snapshot được giữ. Bundle mới có thêm pax fields; kiểm tra
khả năng đọc của bản rollback trên staging trước. Full restore database/private files chỉ
dùng backup đã xác minh, tránh mất các giao dịch phát sinh sau backup.

## Phần còn lại của RC6

VS1: Campaign → qualified lead → Sales Accepted → Customer360 và next action.
VS2: bridge/version kho chương trình → quote; VS3: booking handover;
VS4: amendment/change propagation; VS5: delivery/actual cost;
VS6: closeout/feedback/reporting. Đây là các slice tiếp theo trong kế hoạch,
không được đánh dấu hoàn tất bởi bản VS0.

Chưa gọi provider thật OpenAI, Google Drive/OAuth, social publishing hoặc SmartLinks.
Đã test browser responsive, chưa cài thử trên thiết bị Android/iPhone vật lý.
Không upload, publish, gửi tin cho nhà cung cấp hoặc deploy website trong lượt này.
