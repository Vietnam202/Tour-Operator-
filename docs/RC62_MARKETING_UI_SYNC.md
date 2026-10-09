# RC6.2 Marketing — đồng bộ giao diện và kiểm thử

Ngày kiểm tra: 2026-10-09. Nền mã: P12 `1f43b14837f9b714d03756547db66a2cfb31fc99`.

## Lỗi và cách khắc phục

| Lỗi xác nhận | Khắc phục |
|---|---|
| `preview.html` thiếu 5 bộ JS/CSS dù Marketing có tab Đăng bài, Kết nối Meta và Hộp thư chung | Nạp cùng các module và thứ tự phụ thuộc như `index.html`; preview vẫn chỉ dùng dữ liệu minh họa |
| Test giữ 9 tab và tab Landing Pages trong khi giao diện hiện có 11 tab, dùng Webhook | Kiểm tra đúng danh sách tab; giữ toàn bộ test builder độc lập |
| Nhãn cũ khẳng định toàn bộ đăng bài/inbox chưa kết nối dù có module quản lý trạng thái | Phân biệt bản nháp, lịch đăng riêng, trạng thái tài khoản, hàng chờ và kết quả đã gửi; Facebook/Instagram dẫn tới Kết nối Meta |
| Lỗi tải tài khoản có thể hiển thị thành danh sách trống như chưa cấu hình | Thêm trạng thái tải/lỗi và nút thử lại; thiếu module có thông báo thay vì trang trống |
| Khối hero, tab và thẻ chưa khớp giao diện mới | Đồng bộ nền trắng, navy/teal, viền, khoảng cách và focus; desktop xuống dòng tab, điện thoại cuộn tab trong vùng riêng |
| Nút hủy lịch vẫn xuất hiện với tài khoản chỉ đọc | Ẩn thao tác tạo/duyệt/hủy theo quyền; giữ điều kiện người thứ hai duyệt |

CSS mới chỉ áp dụng màn hình và phạm vi Marketing. Phiên bản asset Marketing/PWA được cập nhật đồng thời trong hai entrypoint.

## Kết quả kiểm thử tại máy kiểm tra

- `npm test --prefix tests`: **27 nhóm thành công**, 345 dòng kiểm tra PASS. Hai test lỗi của lần audit trước đã qua.
- Kiểm thử integration: cả 11 tab preview có nội dung; không gọi API/provider; lỗi và thử lại của 4 controller; phục hồi khi thiếu module; escape dữ liệu; quyền chỉ đọc; phản hồi webhook muộn không ghi đè tab mới.
- Chromium 153: **56 bố cục thành công** — 44 tab preview ở 1440/820/390/360 px và 12 bố cục dữ liệu giả lập ở 1440/390/360 px. Không tràn ngang trang, không tab trống, không lỗi JavaScript và không gọi dịch vụ ngoài.
- Đã xem ảnh chụp desktop và điện thoại. Dữ liệu tài khoản và trạng thái ở test browser là fixture, không phải tài khoản Meta thật.
- JavaScript syntax và diff whitespace đạt; giữ quy ước CRLF có sẵn ở các file liên quan.

Ảnh: [Desktop](../verification/RC6.2/marketing-ui/marketing-1440.png), [Điện thoại](../verification/RC6.2/marketing-ui/marketing-390.png).

## Chạy lại

```bash
npm ci --prefix tests
npm test --prefix tests
npm install --prefix tests --no-save --package-lock=false playwright@1.64.0
tests/node_modules/.bin/playwright install --with-deps chromium
VTA_MARKETING_SCREENSHOTS=verification/RC6.2/marketing-ui node tests/marketing-browser.cjs
```

Nếu dùng Chromium có sẵn, đặt `CHROME_PATH`. Có thể đặt `PLAYWRIGHT_MODULE` để dùng đường dẫn module Playwright riêng. Workflow `Marketing UI Sync` chạy regression và browser, lưu ảnh thành artifact.

## Trạng thái triển khai

Chỉ sửa giao diện, test và workflow. Chưa merge hoặc upload lên CyberPanel. Chưa kiểm tra API Meta, dữ liệu staging, cấu hình máy chủ hay provider delivery thật; các kiểm thử trên không xác nhận môi trường đó đã sẵn sàng.
