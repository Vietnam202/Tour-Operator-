# RC6.2 — Native B2C staging GET smoke (VIE-5 / VIE-11)

**Phạm vi: PATCH / CI fixture; KHÔNG phải PASS_STAGING.** Chỉ chạy chủ động trên hostname HTTPS staging riêng, DB clone và storage private đã xác minh. Không chạy trên production, website công khai hoặc CyberPanel panel.

## Kiểm tra có trong bản vá

1. Reuse exact-SHA, source inventory SHA256, pinned staging hostname, health/runtime build identity, anonymous ACL và CORS từ smoke hiện có.
2. Native B2C catalogue: HTTP 200, no-store, UTM theo link, canonical/OG, không có field NET/supplier/private.
3. Detail publication PUBLISHED đã được pin: 200, canonical, Service markup, consent/CSRF/ngày/PAX và không có field nội bộ.
4. Approved image: public-image GET 200, image Content-Type và no-store.
5. Publication WITHDRAWN độc lập: detail và public-image đều 404.
6. Inquiry GET trả 405; smoke **không gửi form hay tạo lead**.

Script chỉ phát GET; redirect manual, giới hạn phản hồi, reject host/SHA sai. Tuy nhiên GET journeys.php có thể tạo PHP session/CSRF nonce; không coi là zero-side-effect đối với session storage.

## Chuẩn bị operator

Chỉ chạy sau khi operator chứng minh HTTPS host riêng, RuntimeGuard/host pin, clone MariaDB, storage private, đúng docroot và runtime identity. Native B2C được operator cấu hình chỉ trên staging; cần một publication approved có ảnh APPROVED và một publication WITHDRAWN khác.

| Biến môi trường | Ý nghĩa |
| --- | --- |
| VTA_STAGE_URL | HTTPS origin staging (không port/path/query) |
| VTA_STAGE_APPROVED_HOST | Host được duyệt độc lập |
| VTA_STAGE_APPROVED_SHA | Exact 40-hex candidate SHA mới |
| VTA_STAGE_APPROVED_SOURCE_INVENTORY_SHA256 | 64-hex hash inventory đã kiểm tra từ artifact offline |
| VTA_STAGE_READONLY_APPROVAL | I_APPROVE_RC62_STAGING_READ_ONLY_UAT |
| VTA_STAGE_B2C_APPROVED_PUBLICATION_ID | Publication PUBLISHED có ảnh, ID operator pin |
| VTA_STAGE_B2C_WITHDRAWN_PUBLICATION_ID | Publication WITHDRAWN độc lập |

Chạy tại terminal của máy operator đã được ủy quyền:

    node tests/rc62-stage-b2c-readonly.cjs

Output redacted chỉ có check labels và exact SHA; không log cookies, credentials, URL nhạy cảm hay IDs. PASS_STAGING_B2C_GET_SMOKE chỉ chứng minh GET route smoke, luôn release_authorized=false. Lỗi => BLOCK_* / exit 2.

## CI/QA và giới hạn

Workflow .github/workflows/rc62-stage-b2c-readonly.yml checkout exact PR HEAD, Node 22, syntax và fake-fetch tests; không cấp secret, không kết nối web/DB thật. Negative fixtures: wrong SHA, lộ field nội bộ, thiếu consent, withdrawal không thành công, ảnh thiếu. CI xanh chỉ PASS_CI_FIXTURE.

Còn thiếu để GO: VIE-6 backup→clone→ledger/rehearsal→rollback; VIE-7 DOCX/PDF thực; VIE-8 supplier-approved mix Hotel 3★/Cruise 5★ với 6 paying + 1 FOC; VIE-11 publish/revoke/inquiry→Lead→Quote→Booking, B2B A/B, WordPress nếu chọn, Android/PWA, owner signoff.

**NO-GO:** Không merge, không repin controller, không deploy CyberPanel, không thực thi migration hay sửa database production.
