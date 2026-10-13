# RC6.2 — Cài mới trên v2quote

Đây là build ứng viên nghiệm thu. ZIP không tự triển khai và không xác nhận production GO.

## CyberPanel File Manager

1. Xác nhận website `v2quote.vietnamtraveladvisor.com.vn`, HTTPS và document root thực tế trong CyberPanel. Không chọn website quote hoặc website khác.
2. Tạo database MariaDB **mới, độc lập** và user chỉ được cấp quyền trên database đó. Ghi lại tên chính xác; không DROP hoặc tái sử dụng database chỉ vì tên giống v2quote.
3. Lưu bản sao thư mục ứng dụng hiện tại trước khi thay code. Upload ZIP vào thư mục riêng ngoài public_html, giải nén. So sánh `SHA256SUMS.txt` và `manifest.json` với build được chọn.
4. Dùng File Manager đưa **nội dung runtime/** vào document root của v2quote sau khi đã xác nhận triển khai. Không đưa toàn bộ ZIP hoặc thư mục cấu hình/evidence vào public_html. File Manager phải hiển thị `.htaccess` và `.user.ini`.
5. Tạo `/home/v2quote.vietnamtraveladvisor.com.vn/vta_private/` bên cạnh public_html. Sao chép `configuration/config.example.php` thành `config.php` tại đó. Điền database mới, username/password mới. Giữ `app.env=staging`, base_url và allowed_origin là `https://v2quote.vietnamtraveladvisor.com.vn`. Tạo thư mục documents riêng; cấu hình quyền để user PHP đọc config và ghi documents; config 0640, thư mục 0750 hoặc 0770 phù hợp owner/group.
6. Nếu document root không ở public_html như trên, đặt `VTA_CONFIG_FILE` ở cấu hình môi trường PHP/LiteSpeed trỏ đến config ngoài document root. Không đặt secrets trong JavaScript hoặc Git.

## Khởi tạo database

Web setup bị khóa có chủ ý. File Manager chuẩn bị code/config; một lần chạy PHP CLI là bắt buộc, qua terminal VPS hoặc người quản trị hosting. PHP 8.3 cần pdo_mysql, mbstring, dom, zip, gd, curl. Không dùng PHP 7/8.1 thay cho 8.3.

Chạy tại document root đã xác nhận:

```bash
read -s -p 'Admin password: ' VTA_ADMIN_PASSWORD
export VTA_ADMIN_PASSWORD
/usr/local/lsws/lsphp83/bin/php api/bin/install.php --email=YOUR_ADMIN_EMAIL --name='VTA Administrator'
unset VTA_ADMIN_PASSWORD
```

Thay YOUR_ADMIN_EMAIL bằng email quản trị thực. Installer áp dụng full migration theo dependency core trước Marketing, tạo agency VTA và 7 roles: ADMIN, SALES, PRODUCT, OPERATIONS, FINANCE, MARKETING, PARTNER. Installer từ chối database đã có users. Nếu migration thất bại, đọc ledger trước khi xử lý; không chạy SQL thủ công hoặc xóa ledger để ép retry.

`migration-plan.json` trong ZIP ghi thứ tự và SHA-256 SQL. Trạng thái NOT_EXECUTED nghĩa là chưa chạy trên hosting. Sau install xuất `schema_migrations` và `migration_checksums` để đối chiếu; mọi hàng phải APPLIED và hash khớp.

## Nghiệm thu

Mở index.html, đăng nhập admin, kiểm tra API health và build identity. Tạo các tài khoản 7 vai trò, 2 agency thử; PARTNER phải được gán membership agency qua Partner Hub. Không có supplier rate, FX, margin hoặc thông tin dịch vụ thật được bịa sẵn. Nhập các giá đã xác nhận trước khi chốt Price/Proposal.

Companion hỗ trợ đưa ngữ cảnh sang ChatGPT và nhập kết quả đã review. Tính năng gọi OpenAI API có cấu hình/billing riêng; ChatGPT Plus không tự cấp API key. Marketing kết nối Meta/Drive và gửi thật cần credentials/quyền riêng của tài khoản; kiểm thử tổng hợp không chứng minh quyền provider thật.

Sau khi người dùng triển khai đúng SHA và cấp quyền kiểm thử, chạy các luồng có đăng nhập trên v2quote, lưu ảnh/log PASS/FAIL, kiểm tra dữ liệu của lần cài mới. Không coi ảnh preview hoặc CI là live acceptance. Không merge hoặc production GO trước xác nhận nghiệm thu.
