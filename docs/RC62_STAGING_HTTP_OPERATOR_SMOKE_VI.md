# RC6.2 — Operator staging HTTP smoke, bước 2L

**NO-GO: Chưa cài đặt/kiểm chứng trên staging CyberPanel thực tế.** Đây là script chẩn đoán dành riêng cho operator đã được ủy quyền, không phải công cụ triển khai production.

## Blocker cơ sở hạ tầng hiện tại

RuntimeGuard.php trong candidate vẫn chỉ cho app.env=staging/testing và host v2quote.vietnamtraveladvisor.com.vn, 127.0.0.1 hoặc localhost. Một hostname staging HTTPS mới sẽ trả 503 UNSAFE_STAGING_CONFIGURATION. Đây là blocker, **không bypass whitelist, không đổi controller pin**. Cần PR bảo mật riêng, phê duyệt exact hostname/SSL/docroot/origin/clone trước khi có staging mới.

## Chế độ mặc định: GET/không tài khoản, không có ghi dữ liệu

Trên máy tính đã được phép kiểm tra staging riêng, cung cấp biến môi trường (từ kênh operator độc lập):

- VTA_STAGE_URL — URL HTTPS staging, chỉ host, không path/credentials/port.
- VTA_STAGE_APPROVED_HOST — exact hostname staging được duyệt, không sao chép mù quáng từ URL.
- VTA_STAGE_APPROVED_SHA — SHA 40 ký tự đã được người duyệt ghim độc lập.
- VTA_STAGE_READONLY_APPROVAL = I_APPROVE_RC62_STAGING_READ_ONLY_UAT

Chạy: node tests/rc62-staging-readonly-smoke.cjs

Script chỉ thực hiện HTTP GET: health API phải hoạt động và no-store, auth/me, b2b/me, b2b/admin/agencies không có cookie phải trả 401; Origin giả không được phép phải trả 403. Redirect (kể cả chuyển sang production), HTTP, sai host/approval đều bị chặn. Không log hostname, tài khoản, cookies, tiền hoặc nội dung API vào output.

## Chế độ xác thực tùy chọn: 7 phiên độc lập, có ghi audit đăng nhập

**Cảnh báo:** POST auth/login trong backend hiện ghi users.last_login_at và Audit::log(LOGIN). Chỉ bật khi operator có phê duyệt riêng cho 7 lần đăng nhập trên staging clone (không trên production):

- VTA_STAGE_LOGIN_APPROVAL = I_APPROVE_RC62_STAGING_LOGIN_AUDIT_WRITES.
- VTA_STAGE_ADMIN_EMAIL/PASSWORD; tương tự cho SALES, OPERATIONS, FINANCE, READ_ONLY, AGENCY_A, AGENCY_B — bảy tài khoản staging độc lập, tạo sẵn bởi operator.
- VTA_STAGE_AGENCY_A_ID và VTA_STAGE_AGENCY_B_ID — hai mã agency đã kiểm tra độc lập.
- VTA_STAGE_COMMON_PUBLICATION_ID và VTA_STAGE_EXCLUSIVE_A_PUBLICATION_ID — hai publication đã tạo sẵn trong staging clone.

Chạy: node tests/rc62-staging-readonly-smoke.cjs --auth

Test đăng nhập thật lấy cookie nội bộ trong bộ nhớ, xác minh Secure/HttpOnly/SameSite=Strict, quyền, 7 phiên độc lập cùng company, agency membership A/B; kiểm tra GET trực tiếp của Common (A và B), Exclusive A (B 404), fake agency_id của B và KPI supplier/PII. **Sau khi login, script chỉ GET**, không publish/copy/revoke, không sửa tour, booking, supplier, pricing, finance hoặc media. Không lưu credential/cookie ra file hoặc artifact. Không tự chạy nếu thiếu approval hoặc tài khoản.

## Đọc kết quả

PASS_ANONYMOUS_STAGING_HTTP_ONLY: chỉ hợp đồng HTTP/Origin không đăng nhập PASS. PASS_READ_ONLY_STAGING_ROLE_AND_B2B_SMOKE: 7 login cùng một nhóm GET API B2B đã PASS. Hai trạng thái đều có release_authorized=false và không thay thế E2E-01..08, backup/restore, supplier-approved 6 paying + 1 FOC Hotel3★ Cruise5★, 50 DOCX/PDF thực tế, PC/mobile PWA và chữ ký người chịu trách nhiệm. Health hiện chứa nhãn RC6.1 lịch sử, **không xác minh exact HEAD từ health**; dùng offline SHA/manifest + preflight operator. Chỉ lưu kết quả đã che dữ liệu nhạy cảm vào kho operator private ngoài docroot, rồi ký/chứng thực bằng quy trình VIE-11 độc lập.

**Không merge Draft, không upload CyberPanel, không sửa production DB, không đổi RuntimeGuard/controller nếu chưa có phê duyệt và kế hoạch staging riêng.**
