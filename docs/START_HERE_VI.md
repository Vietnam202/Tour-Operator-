> Historical v2.x reference only. For this v3 staging candidate, follow V3_STAGING_UPGRADE.md and V3_AUDIT.md instead of the deployment instructions below.

# VTA Tour Operator OS v2.4 — BẮT ĐẦU CÀI ĐẶT

Mục tiêu: cài VTA v2.4 trên một website/subdomain riêng để dùng thử ngay mà không ảnh hưởng V1.39 đang chạy.

## Khuyến nghị tên website

Dùng một subdomain riêng, ví dụ:

`v2.quote.vietnamtraveladvisor.com.vn`

Không ghi đè `quote.vietnamtraveladvisor.com.vn` ở bước đầu.

## Bước 1 — Backup V1.39

Trong CyberPanel, tạo Full Backup cho website hiện tại. Chỉ tiếp tục sau khi backup hoàn tất.

## Bước 2 — Tạo website/subdomain VTA v2

CyberPanel → Websites → Create Website.

- Domain: `v2.quote.vietnamtraveladvisor.com.vn` hoặc subdomain khác anh chọn.
- PHP: khuyến nghị PHP 8.2.
- SSL: bật SSL/Let's Encrypt sau khi DNS của subdomain đã trỏ đúng server.

Sau khi tạo xong, mở File Manager và xác định thư mục `public_html` của website mới.

## Bước 3 — Tạo MariaDB database

CyberPanel → Databases → Create Database.

Tạo một database riêng cho VTA v2, ví dụ:

- Database: `vta_os`
- User: `vta_os_user`
- Password: dùng mật khẩu mạnh, riêng biệt.

Lưu lại chính xác 3 giá trị Database Name / User / Password. Không gửi mật khẩu cho ChatGPT.

## Bước 4 — Upload ZIP

Upload file `VTA_Tour_Operator_OS_v2.4_Final_Core_Complete.zip` vào `public_html` của website VTA v2.

Extract ZIP. Sau khi extract, `public_html` phải có trực tiếp:

- `index.html`
- `app.js`
- `styles.css`
- `setup.php`
- `api/`
- `manifest.json`
- `service-worker.js`

Nếu sau khi giải nén lại có thêm một thư mục `VTA_Tour_Operator_OS_v2.4_Final_Core_Complete/`, hãy move toàn bộ file bên trong thư mục đó lên trực tiếp `public_html`.

## Bước 5 — Chạy Setup bằng trình duyệt

Mở:

`https://TEN-SUBDOMAIN/setup.php`

Ví dụ:

`https://v2.quote.vietnamtraveladvisor.com.vn/setup.php`

Điền:

- Database host: thường là `127.0.0.1`
- Database port: `3306`
- Database name
- Database user
- Database password
- VTA Base URL: URL chính xác của website VTA v2, bắt đầu bằng `https://`
- Admin name
- Admin email
- Admin password: tối thiểu 10 ký tự

Bấm **Install VTA**.

Setup sẽ tự:

1. Kết nối database.
2. Chạy migrations 001–006.
3. Tạo Company VTA.
4. Tạo Roles/Permissions.
5. Tạo tài khoản Administrator.
6. Tạo thư mục private ngoài `public_html`.
7. Mặc định dùng private local document storage để có thể sử dụng ngay.

## Bước 6 — Đăng nhập

Khi setup báo thành công, bấm **Open VTA**.

Đăng nhập bằng Admin Email/Password vừa tạo.

Nếu không login được, không chạy lại setup nhiều lần. Chụp màn hình lỗi và gửi cho ChatGPT.

## Bước 7 — Test nhanh trước khi nhập dữ liệu thật

Kiểm tra theo đúng thứ tự:

1. PRODUCT → tạo 1 Supplier test.
2. Upload một file rate test PDF/DOCX/XLSX không nhạy cảm.
3. Review document.
4. Tạo một Draft Rate.
5. Approve Rate.
6. SALES → tạo Customer/Agent test.
7. Tạo Inquiry.
8. Inquiry → Quote.
9. Đi qua Info → Schedule → Cost → Price → Send.
10. Confirm Quote → tạo Booking.
11. Generate Services.
12. Generate Supplier Orders — phải ở trạng thái DRAFT trước khi Send.
13. Record Supplier Confirmation.
14. FINANCE → tạo Customer Payment / Supplier Payment test.
15. Kiểm tra Expected / Forecast / Actual Profit.

## Bước 8 — Chỉ dùng dữ liệu thật khi 5 kiểm tra này PASS

- Login ổn định.
- Supplier/Rate Master lưu được sau refresh.
- Quote/Booking lưu được sau refresh.
- Upload/download document hoạt động.
- Finance không lộ internal cost trong customer-facing outputs.

## Bước 9 — Cron automation

Sau khi core flow chạy ổn, thêm cron hourly trong CyberPanel Cron Jobs:

`0 * * * * VTA_CONFIG_FILE=/home/.../vta_private/config.php /usr/bin/php /home/.../public_html/api/bin/cron.php >> /home/.../vta_private/cron.log 2>&1`

Đường dẫn `/home/.../` phải thay bằng home path thực tế của website VTA v2.

## Bước 10 — Không xóa setup.php ngay

`setup.php` tự khóa khi file config private đã tồn tại, nên không cài lại được từ web theo cách thông thường. Sau khi staging ổn định, có thể rename/remove `setup.php` để harden production nếu muốn.

## Khi nào có thể chuyển khỏi V1.39?

Chỉ cutover khi một booking thật chạy xuyên suốt:

Supplier Rate → Inquiry → Quote → Confirmed Booking → Services → Supplier Orders → Confirmation → AR/AP → Payments → Profit → Completed.

Cho đến lúc đó, giữ V1.39 làm fallback.
