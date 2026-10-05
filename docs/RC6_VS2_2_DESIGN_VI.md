# RC6.2 VS2.2 — Audit và quyết định thiết kế

Baseline: VS2.1 `8e0123b`; PR #16 vẫn mở. VS2.2 là PR nối tiếp, target nhánh VS2.1, không tự merge/deploy production.

| Spec | Hiện có | Gap / xung đột | Thay đổi VS2.2 |
|---|---|---|---|
| PC / Media Library | Supplier documents và tour source upload | Không phải thư viện ảnh; không có thumbnail/governance | Media assets immutable trong private storage; JPEG/PNG/WebP được decode, giới hạn pixel, re-encode và thumbnail |
| Selective Drive | OAuth/service-account server config cho document storage | Không có folder mapping/image picker; credentials dùng chung không đủ tenant isolation | Credentials và allowlist folder theo company; chỉ browse folder đã map, chọn file rõ ràng; import once hoặc linked; sync check/import tạo asset mới |
| Media governance | Document review permission | Chưa có company approval / personal / favourites / duplicates | DRAFT→APPROVED→ARCHIVED; personal chỉ owner, company approved dùng chung; favourite theo user; SHA duplicate detection |
| Day images / visual builder | Text itinerary editor; chỉ public text được sanitize | Không có day ID/route/media rail; reorder bằng index làm lệch ảnh | Stable day keys trong bảng riêng; ba vùng ngày/editor/media; quote/day/hotel/cruise/service assignments, reorder giữ key; explicit duplicate/remove |
| Template Gallery | Customer-safe HTML, HTML `.doc`, browser print | Chưa có 4 templates hoặc native PDF/DOCX | Standard B2B, B2B White Label, Explorer B2C, Premium B2C; structured public content và hotel/cruise rows; preview/PDF/DOCX/web link cùng một projection |
| Sent media freeze | Immutable price/text bundle | Live Drive URL sẽ đổi ảnh proposal đã gửi | Snapshot lưu asset ID + content hash immutable; public image route chỉ lấy đúng ảnh trong frozen proposal, không expose storage/Drive IDs |
| Legacy / revision | Sent bundles, approval hash, revision/reuse | Không được thêm field vào historical JSON | Opt-in proposal settings; bundle chỉ mở rộng khi bật VS2.2; legacy output giữ nguyên; revision copy context, không copy public token |

Migration 024 chỉ tạo bảng, permissions và grant theo permission hiện có; không sửa historical migrations 001–023, không rewrite snapshot. Approval hash gồm media assignments và presentation settings; sửa presentation draft làm mất approval.

Google Drive dùng server-side OAuth refresh token hoặc service account với read-only scope. Tài khoản và folder allowlist phải có trong private config theo company; không expose token cho browser, không quét root/whole Drive, không nhận arbitrary remote URL. Chưa cấu hình sẽ trả trạng thái unavailable rõ ràng; integration thật cần staging credentials. Sync không tự thay ảnh draft hoặc sent: user chọn bản mới để apply vào draft/revision.

PDF sử dụng tFPDF 1.33 (pinned upstream commit `050de12ab5359ce475dab49bae5cedbcf455f708`, LGPL-2.1) và DejaVu Unicode font; DOCX là OpenXML ZIP có embedded ảnh, không dùng HTML đổi đuôi. Các output chỉ dùng selling prices/public sections. B2B/B2C pricing/pax bands/margin policies vẫn thuộc VS2.3. Supplier email sending vẫn thuộc VS2.4.
