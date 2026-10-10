# RC6 Smart Cost — Bàn giao và nghiệm thu UI/UX

**Scope:** PR #22 trên nhánh `codex/Vietnam/rc6-smart-cost-direct-edit-v1` (chưa merge/deploy).

## Giao diện đã triển khai
- PC: bảng dịch vụ trực tiếp, các phần A. Common Services, B. Hotel & Cruise, C. Total Tour Cost. Điện thoại: một thẻ cho mỗi dòng dịch vụ.
- `+ Add Service` hỗ trợ Hotel, Cruise, Transfer, Tickets, Group Tour/SIC, Tour Guide, Meals, Visa, Other; `×` xóa mềm có lý do, `Undo` khôi phục.
- Nhập trực tiếp tên dịch vụ, điểm đến, Pax/Unit, Qty/Nights/Packages, ngày bắt đầu lưu trú và Rate. Tự lưu qua API và hiển thị trạng thái `Saved`/`Save failed`.
- Giá VND của Hotel theo pax/night, Cruise theo pax/package; Transfer theo vehicle/trip, Guide theo guide/day, meal/ticket/tour theo pax/event.
- Mỗi điểm đến có requirement Hotel khác nhau, tên khách sạn riêng theo hạng 3/4/5. Các phương án Cost dùng chung dịch vụ không phải Hotel/Cruise.
- Lựa chọn Hotel và Cruise độc lập cho từng **phương án cấp tour**; cả ba phương án Cost và Price sử dụng cùng các variant ID.
- Cùng một yêu cầu Hotel/Cruise, cùng một hạng sao ở các phương án đang xem: chỉnh giá trên một phương án sẽ lưu cho tất cả các phương án đó; điểm đến khác không đổi.
- Bằng chứng giá supplier và thao tác review được đặt ngay trong dòng; thiếu giá vẫn hiển thị thiếu, không thay bằng 0.

## Các bài kiểm thử đã tự động hóa
- `node tests/vta-cost-editor-ui.cjs`: sửa giá trực tiếp, các điểm đến độc lập, ngày bắt đầu và số đêm, FOC/population bounds, add/remove/undo, giữ tên hotel khi nhập nhanh, mix 3★/5★, review, thông báo save failure, quyền tài chính/read-only.
- `node tests/document-cost-ui.cjs` và `node tests/vs21-ui.cjs`: tương thích chức năng Cost cũ.
- `php tests/rc6-smart-cost-native.php`: chạy trong MariaDB **giả lập dùng riêng**, dựa trên fixture `tests/vs21-native.php`, xác nhận lưu mix độc lập, giữ nhiều hotel destination, không tạo trùng mix, xóa mềm/Undo.

## Checklist nghiệm thu thực tế (chưa tự động hóa)
1. Ở desktop 1366–1440 px: không có nút thừa, bảng đọc được, Tab/Enter chuyển ô, tên dịch vụ/điểm đến/đơn giá không bị che, tổng cost thể hiện rõ.
2. Ở Android/viewport 390 px: giá nhập dễ chạm, thẻ không tràn ngang, không bị bàn phím che mất ô đang sửa; luồng quay lại Cost giữ nguyên kết quả.
3. Tour mẫu 6 paying pax + 1 FOC; Hanoi Hotel 2N, Sapa Hotel 2N, Halong Cruise 2D1N: giá hotel từng điểm đến độc lập và Cost/Pax chia cho 6, không phải 7.
4. Chọn gói Hotel 3★ + Cruise 5★: xem Cost và Price khớp; test hai option cùng dùng Cruise 5★ nhận cùng giá thay đổi; cảnh báo nếu rate supplier chưa review.
5. Thêm Group Tour / SIC và Tickets, nhập ngày/giá; xóa → Undo; mở lại quote đảm bảo dữ liệu bền; xác nhận quyền Read Only cho quote đã SENT.
6. Chụp hai màn hình và xác nhận UI/UX của VTA trước khi merge/deploy staging.

## Phạm vi còn giới hạn
- **Hạng Hotel lựa chọn ở cấp phương án tour**, chưa hỗ trợ hạng sao hỗn hợp theo *từng điểm đến trong cùng một phương án* (ví dụ Hanoi 3★ + Sapa 4★ cùng Option A). Tên/đơn giá Hotel cho từng điểm đến và mỗi hạng đã độc lập. Cần thay đổi domain model và validator trước khi hứa tính năng trộn hạng theo điểm đến.
- Chưa chứng minh bằng test ảnh/browser thực tế và chưa deploy lên website. CI/PHP test không thay thế nghiệm thu PC/mobile.
- Không thay đổi baseline production, Marketing/AI Chat, supplier rate approvals hay sent quote snapshots.

## Release gate
**Giữ PR Draft.** Chỉ merge sau khi: CI xanh trên đúng commit head, kiểm thử thật 2 kích thước màn hình, dữ liệu thử DMC, quyền/CSRF/FOC, đồng bộ Cost→Price→Proposal và người sử dụng duyệt giao diện. Deploy cần kế hoạch backup/rollback riêng.
