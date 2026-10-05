# RC6.2 VS2.2 — Media + Proposal

Baseline: VS2.1 `8e0123b`, PR #16. VS2.2 là PR nối tiếp target `codex/Vietnam/rc6.2-vs2.1`; cần merge/review VS2.1 trước khi đưa VS2.2 vào testing. Không tự deploy production.

## Sử dụng

1. Mở Quote → **VS2.2 Visual Proposal** trên PHP/MariaDB staging.
2. Chọn ngày ở bên trái; sửa route, activities, meals, transport, guide requirement, hotel/cruise và public notes ở giữa. Special requests giữ nội bộ. Add/duplicate/reorder/remove dùng stable day key; ảnh đi theo ngày khi reorder.
3. Ở Media Library: upload JPEG/PNG/WebP từ PC; nhập title/destination/category/tags; chọn Personal hoặc Company draft. Reviewer duyệt Company image trước khi finalization. Có favourites, tìm kiếm, trạng thái, duplicate detection và low-resolution warning.
4. Assign ảnh vào cover, day hero/gallery, hotel, cruise hoặc service. Gallery tối đa ba ảnh/ngày. Caption và day assignment chỉnh riêng; binary không được overwrite.
5. Chọn một trong bốn template; điền briefing/highlights, hotel/cruise rows và các policy/contact block. **Không tự sinh payment/cancellation policy hoặc giá.** B2B dùng selling data hiện có; pax bands, commission/net policies và margin guard sẽ đến ở VS2.3.
6. Save → preview/PDF/DOCX. Nếu itinerary thay đổi, rà soát Smart Costing/requirements trước approve/send. Static preview tắt editor VS2.2.
7. Sau Send/Lock, tạo web link 1–90 ngày (UI mặc định 14 ngày); link chỉ xem frozen public snapshot. Link là bearer access: người có link được xem proposal. Revoke khi cần. Draft không được tạo link. Revision không copy token.

## Google Drive chọn lọc

Private config dùng `media_drive.accounts[company_id]`, không dùng chung global account cho mọi company. Mỗi account có `allowed_folder_ids` và một trong hai:

- OAuth: `oauth_client_id`, `oauth_client_secret`, `oauth_refresh_token` (read-only Drive scope).
- Service account: `service_account_json` ngoài public root; folder phải được chia sẻ đúng account. Adapter request `drive.readonly`.

Reviewer map một folder trong allowlist với destination/category/tags. Picker chỉ list ảnh con trực tiếp của folder này, có pagination; không crawl root hoặc subfolders. Chọn 1–10 ảnh để import once hoặc Keep source linked. Provider metadata được xác minh lại: file ID, parent folder, MIME, size, modified time; nguồn thay đổi giữa download và metadata check sẽ bị từ chối.

**Check Drive** báo change. **Import latest** tạo asset mới, giữ asset/source metadata cũ. User duyệt và assign bản mới vào draft/revision; không tự thay ảnh ở bất kỳ quote nào. Batch import trả kết quả thành công/lỗi từng file; lỗi một file không che kết quả các file đã nhập.

Live Drive chưa kiểm tra bằng tài khoản VTA trong lượt này: cần cấu hình credential/folder trên staging. Khi chưa có config, UI hiện unavailable rõ ràng. Provider fixture đã kiểm tra folder isolation, file-parent verification, sync replacement và original preservation.

Nguồn API chính thức: https://developers.google.com/workspace/drive/api/guides/search-files và https://developers.google.com/workspace/drive/api/reference/rest/v3/files.

## Migration / hosting

1. Backup DB, code và private storage; lưu manifest/checksum 001–023.
2. Chuẩn bị **PHP 8.1+**, PDO MySQL, GD với JPEG/PNG/WebP, cURL, mbstring, ZipArchive, OpenSSL và zlib. Local tests dùng PHP8.3.6/MariaDB/Chromium153. Media/PDF dùng private local storage kể cả khi document storage driver là Google Drive.
3. Đặt private storage ngoài `public_html`, có quyền ghi media/thumbnail/PDF font cache. Không lưu binary/base64 trong MariaDB.
4. Deploy matched code trên staging; chạy migration runner đến **024_vs2_media_proposals.sql**. Migration chỉ tạo bảng/permissions và map role + user overrides từ quyền document tương ứng; không rewrite quote history hoặc tự bật VS2.2 cho quote cũ.
5. Kiểm tra user permissions mới `media.view`, `media.upload`, `media.review`; kiểm tra cả DENY override. Thử upload, approve, save proposal, PDF/DOCX, send, anonymous link/image, revoke, revision.

PDF dùng tFPDF1.33 pinned upstream `050de12ab5359ce475dab49bae5cedbcf455f708`, LGPL-2.1; DejaVuSans license nằm cùng vendor. Giữ source/license khi phân phối. Metrics/font subset cache nằm trong private storage; không yêu cầu thư mục code vendor có quyền ghi.

## Rollback

- Nếu 024 chưa chạy: quay lại VS2.1 code.
- Nếu 024 đã chạy nhưng chưa sử dụng: ưu tiên restore backup đồng bộ đã diễn tập. Không tạo destructive down migration, không xóa checksum để chạy lại.
- Nếu đã có VS2.2 sent proposals hoặc customer links: **không downgrade PHP riêng**. Restore code + DB + private storage từ cùng checkpoint; snapshot tham chiếu ảnh sẽ hỏng nếu thiếu binary.
- DDL partial failure: giữ trạng thái FAILED, so sánh schema với migration và rà soát trước retry. Không sửa byte của migration đã áp dụng.

Email sending vẫn thuộc VS2.4; VS2.2 tạo output/download/web link. Không gửi email cho khách hoặc supplier trong lượt này.
