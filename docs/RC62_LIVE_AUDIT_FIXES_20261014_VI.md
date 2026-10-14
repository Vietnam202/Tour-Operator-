# Bản vá sau kiểm tra v2quote — 14/10/2026

Trạng thái: đã chuẩn bị mã và kiểm thử cục bộ; chưa triển khai lên v2quote.
Nguồn: nhánh `codex/rc62-full-install-20261013`, commit `5b89a90049aed32ed4a45b3fc4cfcb29c6aa47ad` của PR #90. Chưa xác minh commit đang chạy trên máy chủ.

## Thay đổi

| Hạng mục | Kết quả kiểm tra | Bản vá |
| --- | --- | --- |
| PAX/FOC | Danh sách báo giá lấy số khách từ `trips`; chi tiết lấy từ phiên bản báo giá hiện tại | Danh sách dùng tên tour, ngày đi, tổng khách, paying PAX và FOC từ cùng `quote_versions` hiện tại |
| Master Tour | Bấm thẻ mở chế độ xem trước; nút **Mở & sửa** đã hoạt động. Đây không phải lỗi quyền sửa | Thêm nhãn xem trước, hướng dẫn vào chế độ sửa; số ngày chưa có hiển thị **Chưa rõ số ngày** |
| Kho B2C | Kho trống không giải thích bước tiếp theo | Thêm hướng dẫn theo quyền, Làm mới, thông báo dữ liệu lỗi và Thử lại; giải thích khi không có Master khả dụng; bỏ phản hồi cũ khi đã chuyển màn hình |
| Booking / Điều hành | Khi chưa có booking, chỉ hiện toast rồi vẫn ở màn hình báo giá | Giữ hướng dẫn trên màn hình: mở Báo giá → xác nhận báo giá đã phát hành → Tạo / Mở Booking |
| Bộ nhớ đệm | Người dùng có thể giữ JavaScript cũ sau cập nhật | Đồng bộ phiên bản 4 tài nguyên thay đổi và xoay cache service worker |

Bản vá không yêu cầu migration và không chỉnh dữ liệu khách, PAX/FOC hay nội dung các báo giá đã lưu. Không suy ra FOC từ tên tour.

## Kiểm thử

Đã chạy đạt cục bộ: 13 bộ kiểm thử JavaScript liên quan gồm Tour Library, Document editor, B2C, Unified Workspace, quyền truy cập và PWA; kiểm tra cú pháp 5 file JavaScript thay đổi.

`python3 tests/quote-list-current-version.py` chạy chính câu SQL danh sách báo giá của CoreOS trên SQLite với dữ liệu giả lập: hồ sơ chuyến đi khác phiên bản báo giá, phiên bản cũ/mới, hai báo giá cùng chuyến đi và công ty khác. Kết quả đạt. Đây không thay thế nghiệm thu PHP/MariaDB.

Máy cục bộ không có PHP. Workflow Unified Workspace được bổ sung PHP lint cho CoreOS và bài kiểm thử SQL; kết quả CI phải xem tại đúng commit của PR.

## Triển khai đang bị chặn

URL File Manager người dùng cung cấp trả về `502 Bad Gateway — Certificate verify failed: hostname mismatch`. Chưa vào được File Manager, chưa sao lưu file máy chủ, chưa ghi file hay cập nhật cơ sở dữ liệu tại v2quote.

Khi truy cập CyberPanel hợp lệ được khôi phục:

1. Xác minh phiên bản/commit máy chủ và so sánh với nguồn PR #90. Không chép đè bản vá nếu nền mã khác.
2. Sao lưu các file runtime liên quan bên ngoài thư mục web trước khi thay thế: `api/lib/CoreOS.php`, `tour-library.js`, `document-editor.js`, `b2c-studio.js`, `unified-workspace.js`, `index.html`, `preview.html`, `service-worker.js`.
3. Triển khai bộ file đã kiểm thử đồng nhất, kiểm tra PHP/HTTP rồi kiểm tra lại PWA; dùng bản sao lưu cùng bộ để hoàn tác khi cần.
4. Kiểm tra trên trình duyệt: PAX/FOC danh sách khớp chi tiết; Master xem/sửa rõ ràng; B2C trống/lỗi có hướng dẫn; Booking liên kết mở đúng và trường hợp chưa có không tự tạo booking.

## Còn cần xử lý riêng

- Đối chiếu tài liệu gốc của tour có số ngày không khớp (ví dụ tên 4D/3N nhưng metadata 8 ngày); không tự sửa lịch trình dựa vào tên.
- Nhập nhà cung cấp và giá NET thực, duyệt giá theo quy trình trước khi nghiệm thu tính giá có dữ liệu thật.
- Nghiệm thu đầy đủ Quote → Booking → Operations với dữ liệu thử được chỉ định và kiểm tra các kết nối Marketing thực tế.
