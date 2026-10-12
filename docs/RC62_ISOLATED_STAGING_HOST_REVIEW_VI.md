# RC6.2 — Bước 2M: Allowlist staging HTTPS riêng, mặc định từ chối

**TRẠNG THÁI: CHỈ TRIỂN KHAI CODE / CHƯA ĐƯỢC CẤP PHÉP CÀI ĐẶT.** Tài liệu này hướng dẫn xem xét cấu hình, **không phải lệnh upload hay cấp quyền production**.

## Vấn đề được giải quyết

Trong PR #79, bộ kiểm thử staging đã sẵn sàng nhưng RuntimeGuard chỉ nhận hostname kế thừa `v2quote.vietnamtraveladvisor.com.vn` hoặc loopback. Bước này bổ sung một đường cho **chính xác một hostname HTTPS staging riêng**, không phải wildcard. Không tự cấp phép bất kỳ tên miền nào và không sửa `deploy/staging-release.php`, `deploy/deploy-staging.sh` hay branch được pin `codex/Vietnam/rc6.2-testing`.

## Quy tắc opt-in

`RuntimeGuard::config()` vẫn chấp nhận legacy localhost và v2quote theo quy định cũ. Đối với **mọi hostname mới**, tất cả điều kiện sau phải đồng thời PASS:

1. `app.env=staging` (không production, không testing remote), URL `app.base_url` là HTTPS dạng `https://<exact-host>` không port/credentials/path/query. Host cần nhãn DNS riêng `stage` hoặc `staging`; loại production, live, IP và host hiện có.
2. `security.allowed_origin` phải khớp hoàn toàn origin chuẩn, `security.approved_staging_host` phải khớp hostname.
3. Biến môi trường **chỉ server operator kiểm soát** `VTA_RC62_STAGING_HOST_PIN` phải khớp 100% hostname trong cấu hình private. Không tự trích pin từ URL hoặc header request.
4. Database: `db.host=127.0.0.1`, `db.database` là tên tách biệt được đánh dấu clone/staging/test, không chứa prod/live hoặc tên database v2quote. `VTA_RC62_STAGING_DB_PIN` phải khớp chính xác tên DB trong cấu hình private.
5. **Bắt buộc** `storage.driver=local`, thư mục `storage.local_path` đã tồn tại, nằm ngoài source/webroot và có nhãn `stage`/`staging` rõ ràng. Biến môi trường **`VTA_RC62_STAGING_STORAGE_PIN`** phải khớp đúng đường dẫn `realpath` đã duyệt độc lập. Cấm `public_html`, `www`, `htdocs`, các thư mục `v2quote` và việc dùng lại production Google Drive OAuth/storage, kể cả khi đường dẫn cũ nằm ngoài docroot. Với FPM phải nhận `HTTP_HOST` đúng host và `HTTPS=on` hoặc `1`; tuyệt đối không tin `X-Forwarded-Host` của client hay coi Host header là nguồn pin độc lập.

**Không đặt cả hai pin chỉ trong cùng `config.php` rồi tuyên bố đã phê duyệt độc lập**. Pin môi trường phải được operator xác nhận qua quy trình riêng, cấu hình tại PHP-FPM/service và CLI trong private server, không lưu GitHub, public_html hay client JS. Nếu PHP-FPM đứng sau proxy và không có `HTTPS` được xác lập đáng tin cậy, request sẽ bị từ chối; cần kiểm tra và phê duyệt proxy/trust boundary riêng, không tắt kiểm soát.

### Ví dụ minh họa, KHÔNG phải hostname/database đã được phê duyệt

```php
'app' => [
  'env' => 'staging',
  'base_url' => 'https://staging-example.company.invalid',
],
'security' => [
  'allowed_origin' => 'https://staging-example.company.invalid',
  'approved_staging_host' => 'staging-example.company.invalid',
],
'db' => [
  'host' => '127.0.0.1',
  'database' => 'vta_rc62_clone_example',
],
'storage' => [
  'driver' => 'local',
  'local_path' => '/outside-webroot/private-stage-documents',
]
```

**Ba biến** host pin, DB pin và storage pin phải được operator provision riêng sau khi chốt host, database clone và kho tệp staging. **Không được sao chép giá trị mẫu trên vào hệ thống thật**. Tất cả cấu hình khác (session, upload, secrets, database user, company isolation) vẫn cần xem xét riêng.

## Kiểm thử và bằng chứng

Chạy trong disposable CI: `php tests/rc62-isolated-host-pin-unit.php`. Bộ kiểm thử bảo đảm mặc định deny, thiếu host/DB pin deny, HTTP/port/URL path/origin không khớp deny, Host sai/HTTPS off deny, DB production/remote deny, storage trong repo deny, host production/live deny và localhost fixture vẫn hoạt động như cũ.

Sau khi được cấp riêng HTTPS staging/clone/backup phục hồi, operator có thể dùng `tests/rc62-staging-readonly-smoke.cjs` ở PR #79 để thực hiện GET ẩn danh trước. Bật `--auth` chỉ khi được phê duyệt 7 sự kiện đăng nhập (vì backend ghi last_login_at và audit). Kiểm tra preflight CLI trên **MariaDB clone** phải dùng account SELECT-only, manifest exact SHA độc lập, và cùng host/DB pin. Giữ mọi bằng chứng ngoài repository, kiểm tra chữ ký bởi người khác và **không auto GO**.

## Bước 2N — kiểm thử tích hợp khởi động PHP, database và API trên fixture dùng một lần

CI `.github/workflows/b2c-native-storefront.yml` hiện có bộ thử nghiệm `tests/rc62-isolated-stage-config.php` và `tests/rc62-isolated-stage-http.cjs`. Trên **MariaDB disposable** đã dùng cho B2C/B2B, script tạo cấu hình staging giả trong thư mục riêng bên ngoài repository, tạo storage staging riêng, khởi chạy **PHP 8.3 built-in server** trên loopback với Host header `staging-fixture.example.invalid`, sau đó thực hiện request vào **API/bootstrap.php thật**, không gọi riêng `RuntimeGuard` như unit test.

Bộ thử xác minh: (1) đúng ba pin mới có health 200, (2) Host production hoặc Host có port bị chặn 503 trước khi xử lý API, (3) Origin sai bị chặn 403, (4) X-Forwarded-Host/Proto không thay thế Host/HTTPS tin cậy, (5) thiếu DB pin hoặc media-storage pin trả 503, (6) người ẩn danh không đọc được dữ liệu, (7) hai tài khoản PARTNER tổng hợp đăng nhập thật trên database disposable và không thể đọc KPI của nhau bằng cách sửa `agency_id`.

**Quan trọng:** bộ thử dùng một PHP router giả lập `$_SERVER['HTTPS']=on` trên kết nối HTTP loopback. Do đó nó **không chứng minh TLS thực tế, reverse proxy cấu hình đúng, DNS/staging thật hay an toàn CyberPanel**. Các login trong CI chỉ thay đổi audit/last_login_at trên database giả `vta_test_*` và được huỷ sau job. Không đưa tên miền `.invalid`, DB hoặc biến PIN test lên VPS thật. Phải kiểm tra **TLS/Host/IP/nguồn trusted proxy ngoài CI** trên máy chủ staging được cấp phép.

## Bước 2O — bắt tay TLS thực sự trên loopback (không phải TLS CyberPanel)

Test tích hợp `tests/rc62-isolated-stage-http.cjs` nay tạo một chứng chỉ tự ký có SAN `staging-fixture.example.invalid` và khóa riêng **tạm thời** bằng OpenSSL bên trong thư mục riêng chỉ có trên GitHub Actions. Node.js mở một HTTPS server trên `127.0.0.1:18873` và reverse-proxy các request API đến PHP 8.3 ở loopback; client Node bắt buộc `rejectUnauthorized=true`, trust chính xác cert thử nghiệm của phiên CI và sử dụng SNI `staging-fixture.example.invalid`.

Bài thử kiểm tra **một bắt tay TLS thật** đi tới `health`, kiểm chứng không chấp nhận chứng chỉ tự ký nếu không có CA pin, từ chối SNI sai, từ chối Host production giả mạo tại TLS terminator (421), Origin giả mạo tại PHP (403), bỏ qua `X-Forwarded-Host` do client gửi, và chạy đăng nhập/tách KPI Partner A/B qua TLS. Chứng chỉ và key chỉ tồn tại trong private temp, bị xóa khi runner kết thúc; **không đưa vào runtime artifact hoặc server thật**.

Giới hạn cực kỳ quan trọng: `stage-router.php` ở tầng PHP vẫn đặt `$_SERVER['HTTPS']='on'` bằng cơ chế giả lập, không phải PHP-FPM/nginx/OpenLiteSpeed thực tế. HTTPS proxy trong CI là Node.js, không phải CyberPanel. Vì vậy bài này **không chứng minh cấu hình TLS của VPS, chain chứng chỉ thật, HSTS, header proxy hoặc việc cổng PHP backend thực sự không thể truy cập từ internet**. Muốn qua gate staging thật, operator phải kiểm tra reverse proxy/trusted listener, DNS, SNI, cert public CA, request Host, HTTP→HTTPS redirect, private DB/docroot, và không bao giờ bỏ `RuntimeGuard` để vượt test.

## Ranh giới bảo vệ

- Bổ sung một allowlist có điều kiện vào code **không mở máy chủ staging** khi thiếu config/pin/SSL/clone thực tế.
- Không phát hành tới hostname v2quote kế thừa chỉ vì nó nằm trong allowlist; controller production/staging cũ vẫn pin nhánh và DB riêng.
- Không thay đổi application DB, không cài SQL, không tắt RuntimeGuard, không tạo staging subdomain DNS, không upload CyberPanel. Chưa có phê duyệt hạ tầng từ operator.
- E2E-01 đến E2E-08, 50 DOCX/PDF thật, real-auth A/B, 6 paying + 1 FOC Hotel 3★ + Cruise 5★ và rollback trên clone dữ liệu thật vẫn **BLOCKED_ENV**.

**Kết luận: code có đường opt-in ba pin mặc định từ chối; môi trường và release_authorized vẫn NO-GO cho đến khi có phê duyệt hạ tầng, nghiệm thu độc lập và chữ ký owner.**
