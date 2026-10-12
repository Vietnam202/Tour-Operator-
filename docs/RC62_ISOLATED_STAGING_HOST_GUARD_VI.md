# RC6.2 — Chính sách mở staging HTTPS riêng (VIE-6 / VIE-11)

**Code-only candidate. Không phải quyết định mở firewall/triển khai CyberPanel.** PR này làm cho backend có thể nhận đúng một hostname staging độc lập **chỉ khi operator thiết lập và phê duyệt các pin từ trước**. Không thay đổi controller cũ, không kết nối máy chủ thật hoặc thay đổi dữ liệu sản xuất.

## 1. Điều kiện bắt buộc trước khi chọn host

Operator phụ trách hạ tầng xác minh và ghi vào hồ sơ thay đổi nội bộ:

- Host staging thuộc domain công ty và có nhãn `stage` hoặc `staging` (ví dụ **minh hoạ** `stage-rc62.vietnamtraveladvisor.com.vn`), HTTPS SSL hợp lệ, **không phải** `quote.vietnamtraveladvisor.com.vn` hoặc `v2quote.vietnamtraveladvisor.com.vn`. Host minh họa **không tự động được cho phép triển khai**.
- Virtualhost và PHP 8.3 chạy với `DOCUMENT_ROOT` thực tế nằm ở thư mục staging **riêng**, không phải production/legacy. Không đặt config, tài liệu khách, session, hình, DB backup dưới public webroot.
- MariaDB đã được clone/restore độc lập, có tên định danh rõ `stage/staging/clone/sandbox/rc62`; tuyệt đối không dùng tên DB production hoặc tài khoản kết nối thật. Backup/restore/rollback phải có bằng chứng riêng trên clone.
- Chỉ dùng gói runtime đã ghim và đối chiếu SHA/manifest `source_commit` độc lập; đoạn guard này **không thể tự biết các bytes đang chạy khớp với commit**. Cần `rc62-release-readiness.php` để xác minh nội dung và migration ledger.

## 2. Ràng buộc trong private config, không đưa vào GitHub

Trong file config đặt **ngoài public_html** (không sửa file ví dụ thành file thật), tùy chọn `app.env='staging'` và cấu hình đã có `app.base_url`, `db.database`, `db.host`, `security.allowed_origin` phải khớp chính xác:

```php
'app' => [
    'env' => 'staging',
    'base_url' => 'https://stage-rc62.vietnamtraveladvisor.com.vn',
    // session_name: unique staging session name, NOT production
],
'db' => [
    'host' => '127.0.0.1',
    'database' => 'vta_rc62_clone_acceptance',
    // staging-ONLY DB account/credentials in the private config
],
'security' => [
    'allowed_origin' => 'https://stage-rc62.vietnamtraveladvisor.com.vn',
    'staging_guard' => [
        'host' => 'stage-rc62.vietnamtraveladvisor.com.vn',
        'database' => 'vta_rc62_clone_acceptance',
        'source_sha' => '<full-approved-40-character-git-sha>',
        'docroot' => '<absolute-verified-staging-document-root>',
    ],
],
```

Đây chỉ là **đoạn cấu trúc**, không phải config đầy đủ và tuyệt đối không copy mật khẩu/token vào tài liệu/chat.

Một kênh **tách biệt với private config** (cấu hình PHP-FPM/OpenLiteSpeed của virtualhost riêng, do operator kiểm soát) phải cung cấp:

```text
VTA_STAGING_PIN_HOST=<independently-approved-exact-staging-host>
VTA_STAGING_PIN_DB=<independently-approved-exact-clone-db>
VTA_STAGING_PIN_SHA=<independently-approved-40-hex-commit>
```

Nếu PHP-FPM có `clear_env` hoặc hệ thống hosting không chuyển các biến vào tiến trình PHP, **API phải trả 503 `UNSAFE_STAGING_CONFIGURATION`**. Không cố khắc phục bằng sửa điều kiện guard trong code hay dùng hostname production. Cần cấu hình process environment hợp lệ, kiểm tra kỹ phân quyền và khởi động lại riêng staging service theo kế hoạch được phê duyệt.

## 3. Điều kiện fail-closed khi nhận HTTP

`RuntimeGuard` bắt buộc HTTPS thật (`$_SERVER['HTTPS']` là `on` hoặc `1`) và `HTTP_HOST` khớp host đã duyệt; **không tin `X-Forwarded-Proto` hoặc `X-Forwarded-Host` do client gửi**. `DOCUMENT_ROOT` phải trùng `realpath` của root code và root được pin riêng trong private config. Nếu host/DB/commit/origin/TLS/root không khớp, từ chối trước khi `Database::connect` với mã lỗi an toàn. CI kiểm thử không truy cập máy chủ thật.

Cấu hình cũ `v2quote.vietnamtraveladvisor.com.vn` và loopback dành cho CI được giữ nguyên để tránh gây hồi quy. **Không được dùng cấu hình v2quote đó làm staging độc lập giả tạo**; controller `deploy/staging-release.php` vẫn pin branch `codex/Vietnam/rc6.2-testing`.

## 4. Thứ tự thử nghiệm khi hạ tầng đã được phê duyệt

1. DBA tạo backup mã hoá + restore vào clone riêng, đối chiếu ledger, xác minh tài khoản chỉ đọc; sau đó operator pin hostname/docroot/DB/SHA từ kế hoạch review.
2. Infra cấu hình hostname staging, SSL, PHP-FPM và private config ngoài webroot; kiểm tra `RuntimeGuard` không trả 503 khi các pin khớp, 503 nếu rút một pin ở staging clone.
3. Chạy `node tests/rc62-staging-readonly-smoke.cjs` với consent riêng. Chế độ mặc định **GET-only**; chỉ dùng `--auth` nếu cho phép 7 `POST auth/login` ghi audit trên clone.
4. Thực hiện E2E-01..08, A/B/Operations, 6 paying+1 FOC/Hotel3★ Cruise5★, 50 tài liệu DOCX/PDF **thật** và kiểm tra PWA bằng thiết bị thật. Lưu chứng cứ riêng, kiểm tra/tạo chữ ký bằng các công cụ operator.
5. Người sở hữu phê duyệt thay đổi production **bằng quyết định riêng** sau khi xác minh nguồn bằng chứng. CI/host-guard PASS không tự động đồng nghĩa GO.

**PR Draft / Release NO-GO mặc định** cho đến khi có đầy đủ bằng chứng thực tế; không chạy migration, reset, restore full-server hay upload vào production theo hướng dẫn này.
