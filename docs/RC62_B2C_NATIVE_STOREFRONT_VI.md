# RC6.2 — B2C đầy đủ nghiệp vụ, WordPress chỉ là giao diện tùy chọn

Ngày: 12/10/2026. Nền: PR #62 `b859d4f7d29c1dfe6fee77375c6287d896e21a18`. Owner xác nhận giữ nguyên tính năng B2C và có thể bỏ WordPress khi không thích ứng. Không tự suy ra bỏ B2C, duyệt giá, inquiry hoặc luồng Quote/Booking/Operations/Finance.

## Source bổ sung

- `journeys.php`: catalogue phân trang 50 bản/trang, detail từ publication hiện hành, Word prose/table/image và text formatting, canonical/description/OG, Service microdata không tạo offers/giá/rating giả.
- Giao diện dùng PHP/API/MariaDB RC6 trực tiếp, không yêu cầu WordPress, cron đồng bộ hoặc HMAC feed nội bộ. Company cố định từ private config, không nhận company từ URL hoặc request khách.
- Inquiry → Lead Hub: phone/WhatsApp optional, ngày đi, khoảng ngày linh hoạt, số khách, UTM/referrer/landing URL, consent được kiểm server, CSRF theo session, retry giữ request ID, không tạo booking hoặc thu tiền. Ngày bắt đầu lưu trường `travel_date`; ngày cuối và consent lưu trong message, không giả vờ có cột schema mới.
- Publication provenance được ghi trong cùng transaction Lead Hub; khóa chương trình và kiểm publication lại trước commit để không tạo lead orphan khi thu hồi cạnh tranh.
- Sửa/withdraw làm event cũ không còn truy cập được từ catalogue/detail/inquiry và ảnh. Public HTML/API không cache, PWA không chặn/cất `journeys.php`; page cache/CDN cần operator loại trừ thực tế.
- Adapter WordPress giữ nguyên, thêm ngày/phone/UTM/referrer và kiểm consent phía server cho B2C, giữ bold/italic/link an toàn khi render. Không yêu cầu cài adapter để dùng B2C.
- Master/B2B, revisions, approval, immutable SENT/Booking, pricing, quote copy, DOCX/PDF và Companion giữ nguyên. Không thêm migration; đủ 47 file migration của nền #62, gồm 044.

## Bật native catalogue trên staging đã được duyệt

Tạo campaign + active form bằng Lead Hub hiện có, theo đúng company. Operator cấu hình **ngoài webroot**, không đưa token/config vào GitHub:

```php
'integrations' => [
    // Merge với các integrations hiện có, không ghi đè toàn bộ private config.
    'b2c_storefront' => [
        'enabled' => true,
        'company_id' => /* company đã đối chiếu */ 1,
        'form_token' => /* token 64 hex của active Lead Hub form */ '<private-token>',
    ],
],
```

Không bật theo company mẫu nếu chưa đối chiếu. Native mặc định disabled; sai company/form/publication phải fail closed. Truy cập HTTPS `/journeys.php` trên cùng runtime. Form khách gửi tới API cùng origin; không dùng secret HMAC trong browser. Keep `VTA_CONFIG_FILE`, base URL/origin, TLS và RuntimeGuard phù hợp môi trường đã được duyệt.

Loại trừ `journeys.php` (mọi query), `/api/*` và ảnh publication khỏi LiteSpeed/page cache/CDN. `Cache-Control: no-store` chỉ có hiệu lực khi proxy/CDN tôn trọng. Không đặt catalogue động vào GitHub Pages.

## Kiểm thử và release gate

Workflow `RC6.2 Native B2C Storefront QA` checkout raw HEAD: PHP 8.3, MariaDB 10.11 disposable; unit validation/rendering + native transaction/provenance/withdrawal + browser→PHP→MariaDB inquiry. Kiểm 1440/820/390/360px, CSRF/consent/idempotency, dữ liệu contact/date/UTM và withdrawal 404. Kết quả chỉ là `PASS_CI_FIXTURE`, không phải `PASS_STAGING_REAL_AUTH`.

Gate bắt buộc:

1. VIE-5: khóa candidate HEAD, CI/artifact đúng HEAD, chốt dependency/sibling còn lại. Đã có quyết định WordPress tùy chọn; không dùng quyết định này như signoff nghiệp vụ.
2. VIE-6: HTTPS/docroot/private config + encrypted backup/restore clone dữ liệu VTA thật + ledger/checksum + migration rehearsal/rollback được xác nhận. Không bypass controller pin `rc6.2-testing` hoặc sửa production.
3. B2C thật: copy/Word/revision/review/approve/publish → native catalogue/detail/approved ảnh → inquiry → Lead/Customer/Inquiry → Quote/approved SENT → Booking/Operations/Finance. Test stale event, sửa cần duyệt lại, thu hồi, concurrent/network retry, 50 chương trình thật và PII review. Không tự tạo 50 bộ dữ liệu giả để đóng gate.
4. B2B partner A/B Common/Exclusive/NET; supplier evidence/mixed Hotel 3★ + Cruise 5★/6 paying + 1 FOC; DOCX/PDF Unicode/ảnh/pagination; Android/PWA/session/logout/offline thật.
5. VIE-11 owner signoff trước Ready for Review theo policy project. Production cần quyết định riêng.

WordPress cron/cache/feed/SEO theme UAT là gate của adapter **nếu chọn WordPress**; không chặn native B2C chỉ vì adapter chưa tương thích. Gate chức năng B2C chung vẫn bắt buộc ở giao diện được chọn. Không xóa bảng/API legacy hoặc coi toàn bộ module DONE từ fixture.
