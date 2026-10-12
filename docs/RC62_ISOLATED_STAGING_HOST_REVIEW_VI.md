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
5. `storage.driver=local` cần `storage.local_path` đã tồn tại và realpath nằm **ngoài source/webroot**. Với FPM phải nhận `HTTP_HOST` đúng host và `HTTPS=on` hoặc `1`; tuyệt đối không tin `X-Forwarded-Host` của client hay coi Host header là nguồn pin độc lập.

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

Hai biến server operator phải provision riêng sau khi chốt host và clone. **Không được sao chép giá trị mẫu trên vào hệ thống thật**. Tất cả cấu hình khác (session, upload, secrets, database user, company isolation) vẫn cần xem xét riêng.

## Kiểm thử và bằng chứng

Chạy trong disposable CI: `php tests/rc62-isolated-host-pin-unit.php`. Bộ kiểm thử bảo đảm mặc định deny, thiếu host/DB pin deny, HTTP/port/URL path/origin không khớp deny, Host sai/HTTPS off deny, DB production/remote deny, storage trong repo deny, host production/live deny và localhost fixture vẫn hoạt động như cũ.

Sau khi được cấp riêng HTTPS staging/clone/backup phục hồi, operator có thể dùng `tests/rc62-staging-readonly-smoke.cjs` ở PR #79 để thực hiện GET ẩn danh trước. Bật `--auth` chỉ khi được phê duyệt 7 sự kiện đăng nhập (vì backend ghi last_login_at và audit). Kiểm tra preflight CLI trên **MariaDB clone** phải dùng account SELECT-only, manifest exact SHA độc lập, và cùng host/DB pin. Giữ mọi bằng chứng ngoài repository, kiểm tra chữ ký bởi người khác và **không auto GO**.

## Ranh giới bảo vệ

- Bổ sung một allowlist có điều kiện vào code **không mở máy chủ staging** khi thiếu config/pin/SSL/clone thực tế.
- Không phát hành tới hostname v2quote kế thừa chỉ vì nó nằm trong allowlist; controller production/staging cũ vẫn pin nhánh và DB riêng.
- Không thay đổi application DB, không cài SQL, không tắt RuntimeGuard, không tạo staging subdomain DNS, không upload CyberPanel. Chưa có phê duyệt hạ tầng từ operator.
- E2E-01 đến E2E-08, 50 DOCX/PDF thật, real-auth A/B, 6 paying + 1 FOC Hotel 3★ + Cruise 5★ và rollback trên clone dữ liệu thật vẫn **BLOCKED_ENV**.

**Kết luận: code có đường opt-in an toàn; môi trường và release_authorized vẫn NO-GO cho đến khi có phê duyệt hạ tầng, nghiệm thu độc lập và chữ ký owner.**
