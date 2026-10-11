# RC6.2 — Bản bổ sung B2C và điều kiện nghiệm thu

Ngày triển khai: 11/10/2026. Nền tích hợp: PR #61, commit `d203ed67db55af025d77a22275f2d6d4f84a6af3`. Commit mới phải lấy từ HEAD của PR bổ sung; kết quả CI cũ không đại diện cho bản mới. Bản bổ sung giữ Draft, không merge hoặc deploy.

## Phạm vi đã triển khai

- Kho chương trình tour → ⋯ Tùy chọn → Kho B2C. Giữ menu và sáu tab tour hiện có.
- Sao chép Master thành bản B2C độc lập, khóa company, revision và creation key; không sửa Master/B2B. Soạn thảo Word, nhập/export qua luồng tài liệu hiện có, lịch sử revision, audit.
- Draft → Review → Approved → Published; duyệt yêu cầu xác nhận đã loại bỏ thông tin riêng tư và kiểm tra ảnh. Sửa nội dung/thông tin sau duyệt làm mất approval; sửa bản published phát event withdrawal. Snapshot đã xuất bản và bản đã gửi được giữ nguyên.
- Dùng bản B2C trong báo giá nháp: lưu source revision, đổi channel/template B2C, giữ khách, ngày, PAX/FOC, itinerary costing và các snapshot đã gửi. Không tính thêm giá bán giả định.
- Feed server-to-server có HMAC hai chiều, source/company riêng, timestamp, nonce, cursor và thứ tự event. Chỉ project nội dung công khai; không đồng bộ NET, markup, supplier, agency hay thông tin khách.
- Plugin WordPress 0.2.0 mirror thành `vta_journey`, shortcodes `[vta_journeys]` và `[vta_journey]`; chỉ ảnh reusable APPROVED được truy cập bằng event còn hiệu lực. Form gửi publication ID qua intake hiện có, liên kết Lead Hub; publication cũ bị từ chối.
- Companion copy/open/paste thủ công trong Sales, Proposal và Word editor. Không gọi API AI hay tự xuất bản.
- Marketing/content/landing authoring chuyển ra WordPress. RC6 giữ inbound, CRM, tour, cost, quote, booking, operations và finance. Không xóa dữ liệu/endpoint cũ.

## Ranh giới sản phẩm

Giá website hiện là **Yêu cầu báo giá**. Đây là catalogue + inquiry, không phải checkout/thanh toán online hay hệ thống tồn chỗ tự động. Báo giá, duyệt/gửi và booking vẫn dùng luồng RC6. Nếu muốn giá retail công khai, phải chốt chính sách currency, validity, tax, pax, SIC/private, Hotel/Cruise, availability và owner approval trước khi bổ sung.

Public content được người có `b2c.approve` duyệt thủ công, gồm loại bỏ PII và nội dung riêng tư trong văn bản; bộ lọc cấu trúc không thay thế review nội dung. B2C view/edit chỉ cấp mặc định cho ADMIN/PRODUCT/SALES; approve cho ADMIN/PRODUCT. Existing roles cần rehearsal migration trên clone, không mặc định mọi role nghiệp vụ đã có đủ quyền.

## Cấu hình staging WordPress

Không ghi secret vào repository, JS, HTML hay log. Trong private RC6 config, thêm `integrations.b2c_publications.<source>` với `enabled=true`, `company_id` đúng và secret ngẫu nhiên ít nhất 32 byte; có thể dùng `bin2hex(random_bytes(32))`. Trên WordPress private config đặt `VTA_CONNECT_API_URL` là HTTPS `/api/index.php`, `VTA_B2C_SOURCE` trùng source và `VTA_B2C_SECRET` trùng secret. Signed lead relay dùng cấu hình VTA Connect hiện có, độc lập secret publication.

Cài ZIP plugin của đúng commit, kiểm checksum. Flush permalink sau đăng ký Journeys. Thêm shortcode vào trang B2C. Cấu hình cron thật chạy mỗi phút; WP-Cron theo lượt truy cập chưa phải SLA. Dùng nút Sync của admin để kiểm tra.

Loại trừ `/journeys/*`, archive Journeys và mọi trang có shortcode khỏi CyberPanel/LiteSpeed/page cache/CDN. Header no-cache của PHP không đảm bảo CDN đã tôn trọng. Plugin đồng bộ khi verification quá 60 giây; nếu verification quá 120 giây sẽ ẩn nội dung. Withdrawal có độ trễ hữu hạn, **chưa có bằng chứng propagation thực tế**. Sync cursor chỉ tiến sau áp dụng event; nguồn đổi URL/source bị chặn để operator reconciliation. Nếu process chết trong sync, mutex `vta_b2c_sync_lock` giữ lại: chỉ operator xóa option sau khi xác nhận không còn worker chạy; không tự phá khóa đang chạy.

## Bằng chứng và việc còn thiếu

| Nhóm | Kiểm tra bằng CI fixture | Gate thực tế còn thiếu |
|---|---|---|
| VIE-5 / integration scope | Candidate nối PR #61; tích hợp core Companion #43, de-scope UI #47 và triển khai B2C #50; khóa diff/HEAD và kiểm tra regression | Reviewer/owner chốt integrate/defer các sibling còn lại, gồm #21; không đóng issue từ CI |
| VIE-6 / staging hardening | Runtime package/manifest đúng HEAD, migration và synthetic clone restore/read-only preflight; không thay pin controller | Tải lại artifact độc lập; HTTPS host/docroot/private config; backup mã hóa, clone dữ liệu VTA, ledger/row reconciliation, migration rehearsal và rollback được ký |
| Functional UAT | Master/B2C isolation, revision, approval, snapshot, HMAC, company/permission HTTP và browser preview | HTTPS login từng role; B2C publish/withdraw/image/inquiry → customer/inquiry → quote; 50 chương trình thật; DOCX/PDF Unicode/ảnh thật |
| VIE-11 / owner | CI logs cung cấp dữ liệu review | Owner signoff theo checklist, quyết định scope/retail và nghiệm thu trên site thật |
| PWA | Cache version mới, asset rotation và private-API boundary fixture | Android install/update/offline thật, session/logout, stale cache |
| B2B / pricing | Giữ luồng NET, 5 pax bands và Hotel/Cruise độc lập | Private/SIC, 3/4/5 sao, mixed 3★ hotel + 5★ cruise, 6 paying + 1 FOC; finance/operations trên dữ liệu thực |

Không gắn DONE cho toàn bộ 23 nhóm module chỉ từ CI. Các module hiện có tiếp tục nằm trong phạm vi regression, chưa được chứng nhận hoàn thiện trên dữ liệu thật bởi bản bổ sung này.

## Draft hoặc Ready for Review

Giữ Draft khi CI của HEAD mới còn lỗi/chưa chạy, chưa có independent artifact verification hoặc chưa chốt evidence staging/owner. Ready for Review là quyền review code, không phải approval deploy: tối thiểu có diff rõ, mọi required exact-head check pass, package đúng HEAD được tải lại/verify, checklist và owner chịu trách nhiệm các gate chưa có bằng chứng. Nếu release policy đòi staging/owner trước Ready, tiếp tục Draft đến khi có các bằng chứng đó. Merge/release còn phải đủ staging HTTPS, clone rehearsal, browser/export/PWA UAT và VIE-11 signoff; không waive bằng fixture.
