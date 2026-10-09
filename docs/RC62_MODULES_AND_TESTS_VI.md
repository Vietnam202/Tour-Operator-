# RC6.2 — Tổng hợp module và kiểm thử ngày 09/10/2026

Tham chiếu: https://vta-tour-workspace-demo-20261009.booking-vietnamtrave.chatgpt.site/

PR tích hợp: https://github.com/Vietnam202/Tour-Operator-/pull/44

## Module trong bản tích hợp

| Không gian | Chức năng đã có trong source | Kiểm chứng |
|---|---|---|
| Tổng quan | Dashboard, công việc, truy cập nhanh | Regression UI và Chromium |
| Sales & B2B | Pipeline, inquiry/CRM, agency/portal, Rate & Contract dùng giá phát hành | UI, quyền/company/agency và domain tests |
| Hồ sơ tour | 6 tab: Chương trình / Tính giá / Báo giá / Booking / Điều hành / Hồ sơ | Chromium; native quote → approve/send → booking |
| Kho chương trình | Kho chung và picker trong tour; Word canvas; áp dụng bản sao, hoàn tác và lưu mẫu; metadata; upload batch/ZIP/Drive/text | Domain, native MariaDB, DOM và Chromium |
| Nhà cung cấp | Supplier, bảng giá VND, phiên bản/duyệt giá, chứng từ nguồn | UI, native costing/group pricing |
| Marketing | P0–P12: webhook, website inbox/chat, Tour Advisor/Share, social publishing, Meta inbox/replies/connections, acceptance và schema rehearsal | Regression, 56 bố cục và schema rehearsal |
| Báo cáo | Doanh thu, chi phí, lợi nhuận hiện có | Regression, quyền truy cập, mở module trong Chromium |
| Cài đặt | Business settings, user/RBAC, tích hợp và PWA | Regression, quyền chỉnh settings, cache asset mới |

Pricing Engine vẫn dùng server, VND supplier rates, 3★/4★/5★, Private/SIC và nhóm khách; Hotel/Cruise chọn độc lập. Thiếu rate hiển thị cần bổ sung rate. Giá mẫu không trở thành giá thật.

Rate & Contract chưa có ký hợp đồng điện tử. Provider thật và PWA trên thiết bị thật cần nghiệm thu cấu hình riêng. ChatGPT Plus Companion ở PR #43 là nhánh riêng, không thuộc bản tích hợp này.

## Luồng Kho chương trình đã bổ sung

- Chọn mẫu ngay trong Hồ sơ tour → Chương trình, có tìm kiếm tên/mã/điểm đến và lọc ACTIVE/DRAFT, Private/SIC.
- API kiểm tra revision của tour và mẫu. Chỉ thay tài liệu và ảnh liên quan; giữ mã tour, khách/inquiry, ngày đi, PAX/FOC, FX, lịch trình tính giá, requirements, cost lines và kết quả giá.
- Bản sao có thể sửa/lưu/mở lại, hoàn tác lần chọn; mẫu nguồn không bị ghi.
- Lưu vào kho tạo mẫu mới, chống tạo trùng khi retry, không tự lấy ghi chú nội bộ hoặc structured dates. Người lưu xác nhận đã kiểm tra nội dung tự do.
- Bỏ bảng giá đã đánh dấu pricing. Mẫu cấu trúc chuyển sang Word giữ nội dung/hotel table, bỏ ma trận giá, ngày nguồn và ghi chú nội bộ.
- Ảnh quote được tạo bản sao unbound PERSONAL/DRAFT; không thay ảnh nguồn hoặc tự duyệt COMPANY.
- Mẫu Word sửa/lưu/import/export qua engine tài liệu hiện có. DOCX có bảng/ảnh nhúng; PDF dùng ảnh đã kiểm tra quyền/checksum.
- SENT/CONFIRMED/SUPERSEDED hoặc có sent bundle bị chặn trên server. Tạo phiên bản mới qua API hiện có.
- Response cũ không thay workspace đang mở; lỗi lưu giữ nội dung và cho phép retry.

## Kết quả kiểm thử cục bộ

Ảnh, logs và số kiểm tra cụ thể: verification/RC6.2/program-workspace/test-results.json. Dữ liệu fixture tổng hợp; không gọi provider thật hoặc sửa staging/production.

| Bộ kiểm tra | Kết quả |
|---|---|
| Frontend hiện có | PASS |
| Unified / approved UI-library / costing / V5 | PASS; gồm 26 nhóm tương tác quyền/navigation/save guard |
| Kho chương trình DOM | PASS: phân trang/tìm mã, readonly, retry, modal đóng, payload và response muộn |
| Kho chương trình Chromium | PASS: new version → chọn → áp dụng → hoàn tác → sửa/lưu → chuyển tab/mở lại → lưu mẫu → Word/metadata; 1440/390/360px |
| Unified Chromium | PASS 90 bố cục 1440/1024/820/390/360; 8 menu và toàn bộ tab tour/booking |
| Marketing Chromium | PASS 44 preview + 12 real-mode fixture layouts |
| Tour Library PHP | PASS 149 kiểm tra |
| Tour Program Workspace PHP | PASS 11 kiểm tra |
| B2B Partner Hub / Group Pricing domain | PASS |
| Freeform Word import/export unit | PASS 23 kiểm tra |
| Tour Program Workspace native | PASS 196 kiểm tra, gồm Lead/Sales, VS2.1/2.2, Word/costing/booking và luồng mẫu |
| Group Pricing native | PASS 227 kiểm tra |
| Full-schema P12 upgrade rehearsal | PASS 53 kiểm tra |
| Deploy / rollback safety | PASS 70 kiểm tra trên fixture |

Native dùng PHP 8.3 và MariaDB 10.11 trên instance/DB thử riêng. DOCX được nhập lại để kiểm tra nội dung, bảng và ảnh. Sent bundles/booking snapshots được so sánh từng byte; giá, cost lines và guest profile được đối chiếu khi áp dụng mẫu.

CI cũ thiếu FREEFORM_FIXTURE_DIR đã được sửa bằng thư mục fixture riêng. Native fixture cũ nay kiểm tra đủ manifest dù Marketing chạy sau core. Workflow unified có domain, Chromium và MariaDB native.

## Nghiệm thu còn cần trên máy chủ

- Upgrade/manifest trên clone DB hiện có, backup/rollback theo controller đã ghim.
- Login nhiều role, private config/storage, media approval và live DOCX/PDF downloads.
- Giá/availability thật và quote → booking → handover với dữ liệu đã duyệt.
- Provider credentials/webhook/social publishing và PWA trên thiết bị thật.

Source đã kiểm thử để bàn giao/nghiệm thu staging. Chưa merge main, chưa deploy CyberPanel hoặc chạy migration trên DB đang sử dụng.
