# RC6.2 VS2.1 — Smart Itinerary & Costing foundation

Ngày 05/10/2026. Implement Issue #15 increment VS2.1 trên baseline `964d2681163701839ac4bd5007c86f153b05712e` của `codex/Vietnam/rc6.2-testing`. Spec giữ nguyên. Xem [Gap Matrix](RC6_VS2_GAP_MATRIX_VI.md) cho toàn bộ VS2; các mục VS2.2–2.4 chưa được triển khai trong increment này.

## Hành vi mới

- Quote → **VS2.1 Smart Costing** mở guest quantities, service requirements, pricing variants, Simple / Advanced View.
- Mỗi quote version giữ một itinerary và một tập service requirements. Hotel/cruise 3★/4★/5★, PRIVATE/SIC/Hybrid và Custom Mix tham chiếu chung dữ liệu này.
- PRIVATE defaults: Transfer, Guide, Hotel, Halong Cruise, Visa, Meals, Destination Ticket. SIC defaults: Transfer, Hotel, Cruise, SIC Tour. Đây là yêu cầu cần review; ngày/đêm/bữa ban đầu là 1, không tự suy đoán giá hoặc số đêm. Xóa dịch vụ không áp dụng, tách từng điểm tham quan, đặt scope theo ngày/dịch vụ, rồi Save requirements.
- Hotel/Cruise preset Economy 3/3, Recommended 4/4, Premium 5/5; có thể chọn hai hạng độc lập. Mỗi phương án có định danh riêng; lựa chọn mode và hotel/cruise được giữ độc lập. Chọn supplier và approved rate từ danh sách nội bộ.
- Service quantities: total/paying/visa/meal/hotel/ticket/custom. Ô guest-profile trống bám theo Total Guests; số nhập cụ thể giữ cố định. Các số lượng nhập tay giữ nguyên khi khách thay đổi và cần review context.
- Giữ mô hình khách VS1: Adults + Children + Infants + FOC riêng = Total Guests. Paying Pax không được cộng FOC lại. Không suy luận tuổi khách lịch sử.
- Các công thức VND: vehicle × full-tour package; guide count × days; hotel pax × nights; cruise/visa/ticket/SIC pax; meal pax × meals. Custom/lump-sum basis cần lý do rõ ràng.
- Server là nguồn duy nhất cho arithmetic và rate matching. Không gọi AI trong costing; HTTP từ chối nguồn giá có nhãn AI/LLM. Giá manual là input nhà cung cấp đã review, có supplier, reason và approval theo quyền hiện có.
- Approved rates do server lấy lại theo company, date, market, pax, tax và trạng thái. Hotel/guide nhiều ngày phải có cùng effective rate trên các ngày sử dụng; nếu khác, tách dòng. Room/cabin hoặc currency khác VND không tự chuyển đổi; nhập manual VND có lý do sau khi supplier/Contracting duyệt.
- Rate hotel/cruise cần star metadata trong option_name hoặc xác nhận star có lý do. Approved transfer cần xác nhận full-tour package basis; capacity lấy từ transport rate rules, scope bám route/date snapshot. Đổi scope/capacity thiếu → REPRICING REQUIRED; hệ thống không tạo giá thay thế.
- Dòng cruise/SIC khai báo included requirement keys cùng scope. Các standalone service đúng key được ghi INCLUDED và tổng 0; không blanket-suppress toàn bộ meals/guide/tickets. Booking không nhân bản dòng standalone included/no-cost.
- Simple View là bảng Service/Basis/Qty1/Qty2/Rate/Total/Source, có trace. Advanced mở supplier, rate source, quantity/basis override, scope, included keys và review. Confirmed/Actual/Variance chưa có trước booking nên hiển thị trống; liên kết Operations VS2.4 chưa được claim.
- Add/Edit/Duplicate/Remove/Reorder, supplier change, custom basis, supplement, included/no-cost có thể thực hiện trước khi gửi. Duplicate tạo service bổ sung và bắt review lại; key của attraction không được tính hai lần.
- Save giữ được draft RATE NEEDED/EXPIRED/NEEDS REVIEW để Sales bổ sung. Approve và Send đều chạy lại final validation: missing service/rate, duplicate, nights/guide/meals mismatch, transfer scope/capacity, guest overrides, star mismatch, positive selling price, copied-cost review.
- Tổng supplier cost cộng trong VND rồi mới đổi FX, tránh làm tròn USD từng dòng. Markup và target margin dùng công thức riêng. Không đổi arithmetic của legacy VS1 option.
- Quote Info thay đổi guest/date/schedule sẽ recalculate VS2 followers và hủy approval. Legacy option tiếp tục gate recost/review cũ.
- Issued snapshot bất biến. Existing Create Revision copy variant metadata, guest profile và requirements; không update snapshot cũ. Clone/reuse VS2 chuyển supplier input sang reviewed-manual workflow và giữ copied-review gate đến khi explicit acknowledgement.

## Tích hợp và quyền

API mới dưới `quote-versions/{id}/smart-costing`: GET context; PUT requirements/profile; POST template/preview/variants; DELETE variants/{id}. RBAC tái sử dụng `quote.view_cost`, `quote.edit`, `quote.view_profit`; approve/send/confirm/booking dùng các quyền hiện có. Tất cả HTTP mutation qua session/CSRF front controller. Quote ownership được resolve qua company trước read/write; mutations và audit chạy chung transaction/quote-version lock.

Legacy hotel-only options vẫn giữ `variant_key=''`; VS2 keys không ghi đè legacy row. Public snapshot chỉ thêm costing mode và cruise category cho VS2; không xuất supplier price, margin, rate source, quantity-review reason hay internal service metadata. Legacy approval bundle shape được giữ nguyên.

Static Preview không chạy PHP/MariaDB: nút VS2.1 được disabled với hướng dẫn staging. Giao diện VS1 preview vẫn hoạt động. Không giả lập giá supplier mới trong demo.

## Migration / rollout staging

1. Maintenance window trên staging, backup MariaDB + private storage + source baseline. Không upload tests/verification/config vào webroot public.
2. Cấu hình `VTA_CONFIG_FILE` ngoài webroot với môi trường testing/staging được RuntimeGuard cho phép. Giữ host guard hiện có.
3. Chạy `php api/bin/migrate.php` bằng cấu hình staging riêng. Manifest tới **023_vs2_costing**; migrations001–022 phải giữ nguyên byte-for-byte.
4. Migration023 thêm `quote_guest_profiles`, `quote_service_requirements` và option metadata. Hotel-only unique index được thay bằng unique(version,hotel,variant key). Đây là thay đổi constraint cần thiết để PRIVATE/SIC và Custom Mix cùng tồn tại; không xóa column/table/data và không rewrite historical snapshot JSON.
5. MariaDB DDL không rollback transaction được. Nếu runner ghi FAILED/RUNNING, kiểm tra thực tế schema + migration_checksums trước repair theo quy trình hiện có; không sửa migration lịch sử hoặc tự xóa checksum để chạy lại.
6. Upload source VS2.1 đồng bộ sau migration; refresh/apply service-worker update. Cache VS2.1 chỉ cache static allowlist, không cache API hoặc quote data.
7. Chạy automated suites và walkthrough bên dưới; dùng quote draft riêng và rate fixture/approved rates staging. Phê duyệt triển khai hosting là bước riêng. Increment này không deploy production.

## Rollback

- Phương án an toàn: maintenance và restore **cặp source + database + private storage** từ backup cùng thời điểm; kiểm tra các quote phát sinh sau backup trước khi restore.
- Không downgrade chỉ PHP VS1 trong khi đang có nhiều VS2 variants cùng hotel star: code cũ không hiểu variant key và có thể chọn/ghi nhầm option.
- Không drop profile/requirements/metadata hoặc coerce nhiều variants về một legacy option. Nếu cần ngừng VS2 tạm thời, khóa mutation và giữ schema/snapshot để review; không có destructive down migration.
- Snapshot sent/accepted trước/sau nâng cấp luôn được giữ nguyên. Không chuyển đổi hàng loạt báo giá lịch sử để “khớp” schema mới.

## Kiểm thử / browser acceptance

Xem [verification report](../verification/RC6.2-VS2.1/REPORT_VI.md) và raw logs/json. Browser walkthrough thật dùng PHP8.3 + MariaDB10.11 + Chromium153, dữ liệu local disposable; không dùng hosting công ty.

Portable commands:

```sh
php tests/php-lint-all.php
php tests/vs2-costing.php
# Disposable MariaDB 127.0.0.1:33317 only; use test-only credentials in env.
php tests/vs2-costing-db.php
php tests/vs2-migration-upgrade.php
cd tests
npm ci
npm test
# Install Playwright separately for browser acceptance if unavailable.
node vs2-browser.cjs
```

MariaDB suites cần `VTA_TEST_DB_PASSWORD` và local test server. VS1 suite cần empty `vta_vs1_<hex>` DSN/password; `VS1_TEST_BASELINE_DIR` trỏ bản migrations001–021 baseline đã review. Browser cần `tests/http/setup.php`, config bên ngoài webroot, PHP server trên 127.0.0.1:8873, Playwright/Chromium; `VTA_TEST_CHROME` cho executable đã cài. Không chạy test fixture trên database thật.

## Scope tiếp theo

VS2.2: governed Media Library / selective Drive / B2B-B2C templates. VS2.3: pax bands, minimum-margin policy, sales commitment handover và revision thương mại đầy đủ. VS2.4: supplier email delivery/reply timeline, payment-request approvals và readiness/actual variance theo service. Không ghi nhận các phần này là hoàn tất qua UI placeholder hoặc VS1 modules.
