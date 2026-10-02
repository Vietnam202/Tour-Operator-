# Báo cáo kiểm thử RC6.2 — VS1

Version **3.4.0-RC6.2**; tiếp tục trên RC6.1 VS0. Thời điểm ghi kết quả JS: `2026-10-02T01:38:31.303Z`;
PHP: `2026-10-01T16:02:03.014Z` (UTC, theo các JSON bằng chứng). Bản này hoàn tất Campaign/Lead Hub → MQL →
Sales nhận/trả/bàn giao lại → review danh tính → Customer/Inquiry + next action → Customer 360.
VS2 và các slice tiếp theo chưa được đánh dấu hoàn tất.

## Kết quả đã xác minh

| Nhóm | Kết quả và bằng chứng |
|---|---|
| JavaScript / jsdom | **19 suites PASS**; 20 file JS sản phẩm hợp lệ cú pháp. `results-js.json` ghi 282 dòng/nhóm PASS. |
| UI VS1 | **23 nhóm assert PASS** trong `vs1-workspace.log`; gồm dòng tổng kết thì có 24 dòng PASS. |
| Shell / navigation | 18 nhóm kiểm tra, gồm nút CRM mở Customer 360 bằng numeric customer ID thực tế; 15 thẻ Operations và các luồng VS0 tiếp tục đạt. |
| PHP native | **14 suites PASS**, PHP 8.3.30; lint 77 PHP files. `results-php.json` ghi 754 dòng/nhóm PASS. |
| VS1 MariaDB/InnoDB | **120 assertions PASS** trong `php-vs1-handover.log`: fresh install, retry/version, transaction, identity review, Customer 360 và upgrade RC5.3 → 022. |
| VS1 API qua dispatcher thật | **112 assertions PASS** trong `native-vs1-http.log`: login, CSRF, quyền/ADMIN DENY, tenant, handover, conversion và projection 360. |
| API hồi quy toàn bộ → extended → VS0 | Hoàn tất; `native-http.log` có 258 dòng PASS, gồm khóa tài chính, snapshot bất biến, phân trang hơn 300 hàng và CRM preflight. |
| Migration | **001–021 nguyên byte so với RC5.3**; chỉ thêm `022_lead_sales_handover.sql`. Native upgrade chạy bổ sung 022, retry migration là no-op. |
| PWA | Cache `vta-os-3.4.0-RC6.2-VS1.1`; xóa cache bản đã thay thế, giữ cache hiện tại/không liên quan và không cache API/private writes. |

Số dòng/nhóm PASS giữa các suites không phải số case độc lập. Các bộ kiểm thử có phần
lặp và dòng tổng kết, nên các số ở bảng không được cộng thành tổng assertions.
`native-vs1-handover.log` là một lượt chạy 120 assertions độc lập bổ sung bằng chứng,
không được cộng thêm như 120 case mới.

## Những hành vi quan trọng đã kiểm tra

- Bàn giao yêu cầu qualification note, owner hợp lệ và hạn xử lý. Pending chưa được
  convert; Sales phải nhận trước. Trả lại bắt buộc lý do, gửi lại dùng cùng lead/history.
- `expected_version` và `action_key` kiểm soát cập nhật đồng thời. Retry cùng actor/key/payload
  trả kết quả đã commit; payload khác hoặc version cũ bị reject, không tạo thêm task/customer.
- Review danh tính chỉ gợi ý email/điện thoại chuẩn hóa trong cùng company, không tự ghép theo
  tên. Nonce gắn actor/lead/version và có hạn; thay đổi candidates cần review lại. Tạo mới
  khi có liên hệ trùng cần lý do và quyền `customer.manage`.
- Customer, trip, inquiry, shared task, audit và internal event nằm trong cùng transaction.
  Lỗi downstream khi tạo khách mới rollback toàn bộ. Kiểm tra InnoDB lock-wait 1205 cho
  qualify và convert; default/global isolation của connection được giữ nguyên.
- Attribution campaign/source/payload gốc không bị viết lại. Total/paying/FOC được giữ;
  adults/children/infants chưa có thông tin không bị suy đoán để vượt commercial review VS0.
- Lead đã convert trước nâng cấp vẫn đọc lại inquiry/trip IDs; không tạo lịch sử nhận Sales,
  event/task hoặc customer giả cho dữ liệu lịch sử. Snapshot sent/accepted/issued vẫn bất biến.

## Customer 360 và quyền truy cập

API 360 là read projection có `sales.view` và company scope. Lead, booking, task, document
và finance tuân quyền section riêng; quyền DENY hiệu lực vẫn áp dụng cho ADMIN. Tasks chỉ
của người hiện tại, trừ `approval.manage` cho phép xem nhóm. Finance cần đồng thời
`finance.view` + `customer_ar.view`; tổng hóa đơn phát hành nhóm riêng từng currency.
Draft/cancelled/receipt/credit-note không được cộng thành issued invoice balances.

Expanded projection vẫn không lộ passport, conversations/notes, raw audit JSON, private
storage URL, snapshot/token tài liệu, chi phí/profit hay raw payments. Documents chỉ trả
metadata phiên bản mới nhất. Inquiry/quote chỉ có selling value; activity lấy thông tin an
toàn từ sections được phép. Foreign-tenant customer trả 404 không kèm metadata.
Mỗi section có cap/paging metadata để không biến danh sách bị giới hạn thành tổng giả.

## Kiểm tra giao diện trong trình duyệt

Các bước sau chạy trên **Preview**, dùng dữ liệu mẫu trong tab; không thay thế kiểm thử API thật ở trên.

| Màn hình / bước | Kết quả | Bằng chứng |
|---|---|---|
| Lead Hub / Sales handover | Marketing qualify → Sales accept → review CREATE_NEW → convert hoàn tất trên bản Preview RC6.2. Desktop viewport 1280×900, document width 1280. | [browser-handover-desktop.png](browser-handover-desktop.png), [browser-identity-review-desktop.png](browser-identity-review-desktop.png) |
| Customer 360 — khách vừa tạo | Customer 2 có 1 inquiry, 0 quotes, 0 bookings và 0 finance; không lẫn dữ liệu khách mẫu Customer 1. Regression bổ sung đã PASS sau sửa projection và xử lý ID trip singular của Preview. | [browser-customer360-new-desktop.png](browser-customer360-new-desktop.png) |
| Customer 360 — viewport mobile | Viewport 390×844, document width 375, không tràn ngang. Giao diện khách mới giữ đúng nội dung scoped như desktop; viewport đã trả về 1280×900 sau kiểm tra. Không phải thử trên điện thoại vật lý. | [browser-customer360-new-mobile.png](browser-customer360-new-mobile.png) |
| Customer 360 — khách mẫu hiện có / CRM | Nút tên khách CRM mở đúng Customer 1: 1 inquiry, 1 quote, 1 booking; USD total 4200, paid 1260, balance 2940. Bản xem thử cuối được mở trên origin local mới để tránh asset cache cũ; PWA cache release là RC6.2-VS1.1. | [browser-customer360-existing-desktop.png](browser-customer360-existing-desktop.png) |

## Môi trường và cách chạy lại

Test sử dụng Node 24.19.0/jsdom, PHP 8.3.30 và MariaDB 11.4.8 local/disposable. SQLite được
dùng ở các suites PHP phù hợp; các assertions VS1 native chạy production SQL trên InnoDB.
Runtime, database, private credentials và cấu hình HTTP test đặt ngoài source/ZIP.

Các suites JS nằm trong `tests/package.json`; cài dev dependencies tại môi trường test rồi
chạy `npm test` trong `tests`. Các suites PHP theo `results-php.json`. Fixture
`tests/vs1-handover.php` bắt buộc database mới/rỗng local `vta_vs1_<hex>` qua env
`VS1_TEST_MYSQL_DSN`, `VS1_UPGRADE_MYSQL_DSN`, `VS1_TEST_MYSQL_PASSWORD`; cần checkout
RC5.3 sibling để so bytes migration lịch sử. Không chạy fixture trên database đang sử dụng.
HTTP suite `tests/http/vs1-http.ps1` dùng dispatcher local với config test riêng và
`VTA_TEST_PHP`, `VTA_CONFIG_FILE`; tạo fixtures disposable trước, giữ private config ngoài webroot.

## Giới hạn và bước nâng cấp

Chưa deploy hosting, chưa chạy với production dataset và chưa xác minh restore backup của
website chính thức. Chưa gọi OpenAI/Google Drive OAuth/social/supplier messaging provider thật.
Outbox APPLIED chỉ chứng minh effects nội bộ đã commit, không có xác nhận giao tin từ provider.
Browser responsive không thay kiểm tra PWA trên Android/iPhone vật lý. Preview là dữ liệu mẫu
trong tab và không ghi database.

Destructive customer merge, alias/preferences đầy đủ, provider receipts và automation framework
tổng quát còn trong kế hoạch. VS2–VS6 tiếp tục sau VS1 theo vertical slice.
Tài liệu/log RC1–RC6.1 giữ làm lịch sử, không phải kết quả mới của RC6.2.

Trước nâng staging: backup database/private storage/config và thử restore; chạy migration
runner để thêm 022; test Marketing/Sales permissions rồi pilot một luồng thực tế.
Không đưa `tests`, `verification` hoặc runtime QA lên production webroot. Hướng dẫn schema,
PWA reload và rollback nằm trong [release note](../../docs/RC6_VS1_RELEASE_VI.md).
Rollback app cũ cần hạn chế convert vì bản cũ chưa có Sales accept gate; không DROP bảng 022.
