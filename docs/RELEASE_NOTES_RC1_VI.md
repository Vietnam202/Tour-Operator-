# VTA Tour Operator OS v3.0.0 RC1 — Stability & Bilingual

Ngày đóng gói: 24/09/2026.
Nguồn: VTA_v3_staging_candidate_2026-09-22.zip (bản v3.0.0-dev.1).
Phạm vi: mã nguồn và kiểm thử cục bộ. Không kết nối hoặc thay đổi production/staging từ xa.

## 1. Thay đổi đã triển khai

### EN / VI
- Bộ từ điển i18n.js, bộ chọn EN/VI và lưu lựa chọn trên trình duyệt.
- Chuyển nhãn điều hướng, luồng báo giá/Schedule/Cost/Reuse, thông báo và lỗi được khai báo trong từ điển. Các nhãn chưa có bản dịch dùng English dự phòng.
- Đổi ngôn ngữ không tải lại màn hình, không làm mất nội dung đang nhập. Giá trị option/status/API vẫn là mã tiếng Anh.
- Chọn document_language riêng trong Quote Info; lưu vào snapshot báo giá khi phát hành. HTML báo giá khách hàng có nhãn EN/VI. Snapshot Travel Documents nhận hook document_language cho các mẫu tiếp theo.
- Không tự dịch nội dung chương trình do người dùng nhập; mẫu voucher/invoice cũ chưa được dịch toàn bộ.

### Schedule v2
- Nhập tay, Quick Paste và upload DOCX/PDF/TXT qua API schedule/preview có kiểm tra quyền và CSRF.
- Nhận tiêu đề Day / Ngày, giữ đầy đủ các dòng mô tả; nhận Meals/Bữa ăn/Ăn và Overnight/Nghỉ đêm/Lưu trú khi có dấu hai chấm. Nhận ký hiệu bữa ăn B/L/D trong ngoặc ở tiêu đề.
- Kết quả là DRAFT preview có thể sửa, kèm văn bản gốc và cảnh báo. Preview không ghi database. Người dùng chọn thêm vào cuối hoặc thay thế, Apply rồi Save.
- Nhân bản, di chuyển lên/xuống và xóa ngày; nội dung đang gõ được giữ khi thao tác. Số thứ tự được cập nhật; ngày lịch đã nhập được giữ để người dùng kiểm tra.
- Giới hạn 90 ngày; upload tối đa 10 MB; TXT UTF-8. Từ chối văn bản quá dài có nguy cơ bị cắt thay vì lưu lịch trình không đầy đủ.
- Cấu hình PHP upload_max_filesize/post_max_size phải đủ cho dung lượng upload mong muốn (ví dụ 10M/12M).
- DOCX cần PHP ZipArchive. PDF có chữ cần pdftotext trong PATH và shell_exec khả dụng. Không OCR PDF scan; khi không trích xuất được sẽ yêu cầu dán/nhập tay, không ghi lịch trình rỗng.
- Bộ tách văn bản là heuristic: bảng phức tạp, nhiều cột, ảnh và kiểu ghi bữa ăn/nơi nghỉ khác cần kiểm tra thủ công.

### Cost Editor v2
- PATCH/PUT cập nhật một phần dòng chi phí, giữ các trường không gửi; khóa phiên bản trong transaction trước khi sửa.
- Giao diện sửa trực tiếp Service/Pax/Qty/Unit Price/Notes, xem Total tức thời và Save row; form chi tiết sửa ngày, category, currency, charge basis.
- Dòng mới mặc định Pax = quote.paying_pax. TOTAL_GUESTS dùng tổng khách; room/vehicle/group/service và các basis không theo khách mặc định 1. Có thể điều chỉnh số phòng/xe cụ thể.
- Nhân bản nhanh đánh dấu Needs Review, xóa liên kết phê duyệt giá cũ.
- Dán Excel dạng tab, xem trước và lưu tối đa 200 dòng trong một transaction; một dòng sai thì toàn bộ lô được rollback.
- Cột theo thứ tự: service_name, pax, qty, unit_price, currency, category, service_date, notes, charge_basis. Header tùy chọn; dùng số thập phân không có dấu phân cách hàng nghìn. Notes là lý do rà soát bắt buộc.
- Giá lấy từ rate đã duyệt được xác minh lại phía server theo ngày/market/pax/trip, dùng effective_amount của server. Không thể đổi đơn giá rồi vẫn giữ nhãn Approved Rate; muốn override phải chuyển sang manual và ghi lý do.
- Nút Refresh rate tìm lại giá phù hợp; người dùng chọn giá thay thế. Không âm thầm thay toàn bộ giá.
- Option 3*/4*/5* cũng có sửa dòng, nhân bản, mặc định Pax theo basis và xác nhận rà soát giá sao chép. Vẫn giữ công thức Pax × Qty × Unit Price và kiểm tra sức chứa/phụ phí transport.

### Clone & Reuse
- Chọn Inquiry đích đã tồn tại và một trong ba chế độ.
- Program Only: chương trình; không có chi phí.
- Program + Cost Structure: chương trình và cấu trúc dòng/option, đơn giá bằng 0 để báo giá lại.
- Full Quote Draft: chương trình, dòng/option với đơn giá ước tính cũ, cấu hình giá và điều khoản; tính lại theo Pax đích.
- Mọi bản sao là quote mới, version 1, DRAFT. Ngày chương trình được đặt theo ngày bắt đầu đích; ngày dịch vụ giữ độ lệch so với ngày bắt đầu nguồn. Basis theo khách dùng Pax đích; số phòng/xe/group cũ được giữ và cần rà soát.
- Không sao chép trip/customer nguồn, passport/guest records, payment, invoice, supplier confirmation, voucher, booking hay trạng thái/approval/sent snapshot nguồn. Dùng thông tin khách từ Inquiry đích.
- Chỉ sao chép các trường chương trình/chi phí được cho phép. Bỏ proposal JSON, ghi chú nội bộ và rate/source-document snapshot. Loại tên/email/WhatsApp liên hệ chính đã biết khỏi văn bản được tái dùng.
- Mọi chi phí sao chép có review_required; không coi giá nguồn là Approved Rate. Chặn duyệt cho tới khi refresh bằng giá hợp lệ hoặc xác nhận rà soát manual với lý do.
- Văn bản tự do có thể chứa tên của khách khác hoặc nội dung cá nhân không có cấu trúc; đây không phải bộ nhận diện/xóa PII tự động. Người dùng phải rà soát nội dung chương trình và điều khoản trước khi gửi.
- Không đổi dữ liệu của báo giá nguồn. Không tự tạo Inquiry mới trong hộp thoại Reuse; tạo Inquiry đích trước.

### Lưu và bảo vệ nghiệp vụ
- Đổi lịch/ngôn ngữ/Info làm mất approval hiện hành; đổi ngày/Pax đánh dấu chi phí cần rà soát.
- Bản SENT/CONFIRMED/SUPERSEDED không sửa được.
- Customer HTML vẫn chỉ lấy trường công khai từ snapshot. Không đưa cost/rate/margin/internal notes vào output khách hàng.
- Các luồng Lead Hub, Inventory, Booking, Procurement, Operations, Travel Documents, Invoice, AR/AP/Profit giữ nguyên; đơn nhà cung cấp vẫn khởi đầu DRAFT.
- Demo preview.html không mô phỏng đầy đủ RC1; dùng API cục bộ hoặc staging để kiểm thử chức năng mới.

## 2. Migration và triển khai staging

Migration mới duy nhất: api/migrations/016_rc1_editing.sql.
- quote_cost_items: thêm charge_basis và review_required.
- quote_versions: thêm document_language mặc định en.
- Backfill basis từ rate_version khi có; dòng manual cũ dùng PER_SERVICE để giữ số đơn vị đã lưu. Không thay Pax/Qty/giá/trạng thái cũ.
- Không thay nội dung migration 001–015. Bộ migration hiện có quản lý checksum và bỏ qua bản đã áp dụng; không chạy SQL 016 thủ công nhiều lần.
- Backup database/file staging, giữ nguyên vta_private/config.php và documents. Dùng api/bin/migrate.php cho hệ thống đã có dữ liệu; không dùng installer để nâng cấp.
- Bản này vẫn bị RuntimeGuard giới hạn ở localhost hoặc v2quote.vietnamtraveladvisor.com.vn. Chưa kiểm tra cấu hình CyberPanel/PHP/database thật của staging.
- Runtime PHP/MariaDB/Xpdf, database kiểm thử, cấu hình riêng, session, tài liệu tải lên và mật khẩu thực thi không nằm trong gói.
- Nội dung ZIP có thư mục vta-v3/ giống baseline. Kiểm tra VERSION, app.js và index.html sau giải nén trước khi triển khai.

## 3. Kiểm thử

Các log trong verification/ ghi kết quả cục bộ với dữ liệu tổng hợp, không phải dữ liệu khách thật.
- PHP lint cho toàn bộ .php; JS syntax cho app.js và các script.
- tests/rc1.php: import EN/VI, TXT/DOCX, mô tả/bữa ăn/nghỉ đêm, lưu/đọc lại, Pax/basis, partial edit, rollback bulk, duplicate/review, ba chế độ clone, tenant isolation, bảo vệ quote đã gửi, refresh giá.
- tests/rc1-options.php: ba option, review gate phía server kể cả khi client bỏ marker, duyệt/gửi sau rà soát, output khách an toàn và nguồn CONFIRMED không đổi.
- tests/rc1-browser.cjs: Chrome ẩn riêng, EN/VI giữ nội dung chưa lưu, thao tác ngày, DRAFT preview, inline Cost, Excel paste, multipart TXT/DOCX/PDF có chữ, Clone UI/API, option editor.
- tests/documents-extended.php: chuỗi domain v3 đầy đủ; tests/legacy-costs.php: guard chi phí cũ.
- tests/upgrade.php, runtime-guard.php, install-cli.php: nâng cấp giữ tài khoản, checksum drift, staging guard, installer không reset user cũ.
- tests/http/extended-http.ps1: chuỗi API xác thực/CSRF/quyền, quote/booking/supplier orders/chứng từ/tài chính và khóa immutable.
- tests/frontend.cjs: cú pháp, query routing, JSON/CSRF và lỗi API.

Môi trường kiểm thử: PHP 8.3, MariaDB 11.4 trên 127.0.0.1:33317, API 127.0.0.1:8873, Chrome riêng; PDF dùng Xpdf pdftotext.
Để chạy lại browser test cần Node với playwright, pdf-lib, jszip; VTA_TEST_CHROME có thể chỉ đường dẫn Chrome. Chạy tests/http/setup.php trước để tạo tài khoản giả. Không chạy fixture vào database vận hành.

## 4. Giới hạn còn lại

- Chưa triển khai hoặc xác minh trên máy chủ staging/production thật.
- PDF scan không OCR. DOCX/PDF phức tạp cần kiểm tra nội dung trích xuất.
- EN/VI có English fallback; chưa Việt hóa mọi màn hình/voucher/invoice lịch sử.
- Excel paste không đọc workbook .xlsx, ô gộp, công thức hoặc số tiền theo locale; dùng bảng giá trị đơn giản.
- Không tự phát hiện mọi PII trong văn bản tự do; không bảo đảm giá cũ phù hợp ngày/Pax mới.
- Chưa chạy load/concurrency stress test hoặc mọi kích thước màn hình. API mutation không bổ sung khóa optimistic cho mọi form cũ; tránh hai nhân viên sửa đồng thời cùng draft.

