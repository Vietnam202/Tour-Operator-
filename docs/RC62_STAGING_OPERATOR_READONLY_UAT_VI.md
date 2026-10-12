# RC6.2 bước 2L — Chạy UAT B2B trên HTTPS staging được operator phê duyệt

**Mặc định NO-GO, không gọi mạng.** Script tests/rc62-staging-operator-readonly.cjs dùng Node.js 22 và chỉ đọc API nghiệp vụ. Ngoại lệ: POST auth/login tạo session, audit và cập nhật last_login_at. Không POST/PUT/DELETE tour, rate, NET, booking, supplier, Finance hay media.

## Điều kiện bắt buộc trước khi chạy

1. Operator được ủy quyền xác nhận bằng kênh độc lập: một hostname HTTPS staging riêng, TLS hợp lệ, docroot tách production, private config, MariaDB clone và bản ZIP/manifest chính xác commit SHA (không lấy SHA ngược từ máy chủ không tin cậy). Phải có backup/restore đã nghiệm thu và preflight SELECT-only.
2. RuntimeGuard hiện chỉ cho phép v2quote.vietnamtraveladvisor.com.vn hoặc localhost; hostname staging mới sẽ bị chặn nếu chưa có thay đổi allowlist được chủ hệ thống phê duyệt riêng. **KHÔNG bypass RuntimeGuard, DNS, controller branch pin hay dùng production hostname để giả lập staging.**
3. Trong staging, operator tạo bằng giao diện được ủy quyền các bản ghi không chứa dữ liệu khách thật: một tour Common PUBLISHED, một tour EXCLUSIVE A, một tour REVOKED, bản sao A và bản sao B. Ghi nhận đúng 5 ID này.
4. Bảy người dùng staging độc lập cùng một company: Admin, Sales, Operations, Finance, Read-only, Agency A, Agency B. A/B mỗi tài khoản thuộc duy nhất một đại lý ACTIVE riêng biệt. Không dùng tài khoản production.

## Chạy thử OFFLINE (không kết nối mạng)

node tests/rc62-staging-operator-readonly.cjs --url=https://rc62-staging.example.invalid/ --approved-host=rc62-staging.example.invalid --expected-sha=40_HEX_REVIEWED --common-id=101 --exclusive-id=102 --revoked-id=103 --a-copy-id=201 --b-copy-id=202

Ví dụ trên chỉ là placeholder; phải thay commit 40 ký tự và các ID bằng dữ liệu được chuẩn bị thật trên staging. Không có --execute thì mã trả READY_FOR_APPROVED_HTTPS_STAGE_ONLY, exit 2 và 0 network requests.

## Chạy có quyền (operator trực tiếp thực hiện, không chạy qua ChatGPT)

Đặt thông tin đăng nhập trong biến môi trường bí mật (private env file quyền 0600, không commit, không đưa vào CI logs, không ghi trong chat):

- VTA_STAGE_UAT_ADMIN_EMAIL / VTA_STAGE_UAT_ADMIN_PASSWORD
- VTA_STAGE_UAT_SALES_EMAIL / VTA_STAGE_UAT_SALES_PASSWORD
- VTA_STAGE_UAT_OPERATIONS_EMAIL / VTA_STAGE_UAT_OPERATIONS_PASSWORD
- VTA_STAGE_UAT_FINANCE_EMAIL / VTA_STAGE_UAT_FINANCE_PASSWORD
- VTA_STAGE_UAT_READONLY_EMAIL / VTA_STAGE_UAT_READONLY_PASSWORD
- VTA_STAGE_UAT_AGENCY_A_EMAIL / VTA_STAGE_UAT_AGENCY_A_PASSWORD
- VTA_STAGE_UAT_AGENCY_B_EMAIL / VTA_STAGE_UAT_AGENCY_B_PASSWORD

Chỉ sau khi operator ký duyệt chính xác host, dữ liệu staging và commit: export VTA_STAGE_UAT_OPERATOR_APPROVED=YES_READ_ONLY_API_PROBES rồi chạy lại lệnh ở trên với --execute. Node sử dụng kiểm tra TLS mặc định; tuyệt đối không đặt NODE_TLS_REJECT_UNAUTHORIZED=0.

Kiểm thử gồm: health, anonymous B2B trả 401, 7 phiên auth/me độc lập, A/B mỗi người đúng một agency, KPI theo agency, Common cho A/B, Exclusive chỉ A, Revoked không xuất bản, truy cập nhầm agency/copy trả 404. Không in body, email, token, cookie, thông tin khách hoặc supplier NET. Không thực hiện thao tác tạo hay thay đổi dữ liệu nghiệp vụ.

**PASS_OPERATOR_READ_ONLY_API_PROBES không có nghĩa PASS_STAGING_REAL_AUTH toàn bộ E2E-01..08.** Công cụ không xác minh trực tiếp SHA runtime trên server, không kiểm chứng booking, Word/PDF, supplier hoặc backup thật. Output luôn release_authorized=false. Operator phải đối chiếu riêng phiên chạy với host/docroot/MariaDB clone, hoàn tất các bước nghiệm thu thủ công và được chủ hệ thống phê duyệt GO qua quy trình độc lập.

## Test trên GitHub Actions

tests/rc62-staging-operator-readonly-unit.cjs chỉ dùng HTTP mock trong bộ nhớ, không kết nối staging hay production. Các test kiểm tra host HTTPS không phải production, SHA pin, không có network khi thiếu --execute, không tự tạo phiên nếu thiếu phê duyệt, các GET quyền A/B và không có business POST. GitHub PASS chỉ được ghi PASS_CI_FIXTURE.