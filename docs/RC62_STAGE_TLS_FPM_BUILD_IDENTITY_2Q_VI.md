# RC6.2 bước 2Q — tích hợp kiểm chứng TLS/FPM và build identity (chỉ Draft)

**Phạm vi:** Nhánh này bắt đầu từ HEAD PR #86 (`51f5900d0b41a5050929c3a4f59d482d2291244b`) và chuyển các bài kiểm thử **disposable** của PR #85 (`0e8ff0427e44aa90662b2afb788bc058c4d72f80`) vào cùng ứng viên CI. PR #83/84/85/86 vẫn là Draft riêng; đây không phải quyết định merge hay phát hành.

## Kiểm chứng chung trên cùng một HEAD

1. Build identity của PR #86: ZIP sinh `api/runtime-build-identity.json` có commit SHA, source inventory SHA-256 và số file; operator HTTP smoke từ chối build không trùng hai pin độc lập.
2. TLS Node reverse proxy của PR #83: chứng chỉ và SNI fixture, Host/Origin, phân tách agency A/B; chỉ là loopback.
3. TLS nginx → PHP-FPM 8.3 → MariaDB 10.11 của PR #85: chứng chỉ tạm thời có SAN rõ ràng và CA local, chặn không tin CA/SNI sai/Host production/Origin lạ/forwarded headers, DB pin vắng, anonymous API và agency chéo; kiểm tra cookie Secure/HttpOnly/SameSite.
4. Workflow `.github/workflows/b2c-native-storefront.yml` chạy cả hai thử nghiệm trong cùng job với `RUNNER_TEMP` riêng và logs redacted. Hai script TLS/FPM là mã test, không được thêm vào ZIP runtime.

## Điều kiện quyết định

**Chỉ khi GitHub Actions trên HEAD của nhánh 2Q SUCCESS**, ghi `PASS_CI_INTEGRATED_FIXTURE`. Không thừa hưởng dấu PASS của PR #85/#86 trên HEAD khác. Các workflow marketing/Word/Cost và integrated package phải độc lập PASS cùng HEAD; xác minh ZIP/manifest SHA bằng artifact được tải từ run tương ứng. Bất kỳ workflow thiếu/chưa chạy được xem là chưa đủ chứng cứ.

**Vẫn NO-GO/CYBERPANEL BLOCKED:** Không có SSL DNS public CA staging thật, không có OpenLiteSpeed/PHP-FPM cấu hình trên VPS thật, chưa restore + rollback trên clone DB hiện hữu, chưa có 7 vai trò đăng nhập thật và hai agency thật, chưa có giá supplier-approved cho 6 paying + 1 FOC Hotel 3★/Cruise 5★, chưa có 50 DOCX/PDF thật và QA PWA trên máy thật. `health.build` chỉ là dấu hiệu nhất quán, không phải chứng thực mật mã trạng thái của docroot thực tế. Operator kiểm tra độc lập, ký evidence và VIE-11 cho phép mới xét release.

**Không merge, không thay nhánh controller, không upload CyberPanel, không SQL production, không dùng credential/domain/database sản xuất.**
