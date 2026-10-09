# VTA RC6.2 — Bản tích hợp theo demo ngày 09/10/2026

Tham chiếu: https://vta-tour-workspace-demo-20261009.booking-vietnamtrave.chatgpt.site/

Code và CI: https://github.com/Vietnam202/Tour-Operator-/pull/44

## Sử dụng Kho chương trình trong tour

1. Mở Hồ sơ tour → chọn tour → Chương trình → Kho chương trình.
2. Tìm theo tên/mã/điểm đến, lọc trạng thái và Private/SIC; chọn Dùng cho tour này.
3. Bản sao tài liệu được lưu trong tour hiện tại. Tour, khách, ngày đi, PAX/FOC và tính giá giữ nguyên. Kiểm tra lịch trình và giá trước khi gửi khách.
4. Sửa trực tiếp trên trang Word: viết/dán nội dung, bảng, ảnh, định dạng, undo/redo, nhập DOCX/PDF/TXT. Khi chuyển tab, hệ thống chờ lưu thành công.
5. Hoàn tác chọn mẫu khôi phục tài liệu trước lần chọn vừa rồi. Undo/redo của Word vẫn dùng cho thay đổi nội dung.
6. Lưu vào kho tạo mẫu mới ở trạng thái nháp. Kiểm tra và bỏ thông tin riêng của khách, dữ liệu nội bộ và giá chưa đánh dấu trước khi xác nhận lưu. Mẫu nguồn không bị sửa.
7. Với báo giá đã gửi/xác nhận: chỉ xem kho; chọn Tạo phiên bản mới trước khi dùng/sửa mẫu.

Trong menu Kho chương trình, chọn Soạn thảo Word. Thông tin mẫu sửa tên, mã, điểm đến, ngôn ngữ, loại tour và trạng thái. Nhập nhiều tệp/ZIP/Google Drive và editor thông tin/giá mẫu cũ vẫn có sẵn cho mẫu cấu trúc.

Ảnh của tour được sao chép thành ảnh có thể tái sử dụng, giữ quyền PERSONAL/DRAFT. Để cả nhóm dùng ảnh, duyệt ảnh dùng chung theo luồng Kho ảnh hiện có. Bảng giá được đánh dấu khi chèn từ Tính giá được loại khỏi mẫu; giá viết tự do phải được người lưu kiểm tra.

## Xem thử và ứng dụng thật

- preview.html: dữ liệu mẫu trong bộ nhớ; không gọi API/provider thật, không xuất Word/PDF thật.
- index.html: cần PHP 8.3, MariaDB, cấu hình riêng và extension pdo_mysql, mbstring, dom, zip, gd, curl.
- Code mới dùng proposal_json hiện có; không thêm migration mới. Cần đầy đủ schema RC6.2, gồm 036_tour_library_proposal_studio và Media/Proposal.

## Upload / nghiệm thu staging

Gói ZIP là source tích hợp để nghiệm thu. Kết quả module/test: docs/RC62_MODULES_AND_TESTS_VI.md.

1. Kiểm tra CI trên commit PR #44 và SHA-256 của ZIP.
2. Review và đưa commit được chọn vào nhánh staging đã ghim theo quy trình hiện có. Controller trong deploy vẫn ghim codex/Vietnam/rc6.2-testing; không tự đổi nhánh hay bỏ kiểm tra.
3. Backup và thử nâng cấp trên clone riêng của DB staging. Giữ core trước Marketing; hai migration 037 có tên riêng. Khi gặp MIGRATION_REVIEW_REQUIRED, review checksum/manifest theo P11/P12, không bypass.
4. Giữ api/config.php, vta_private, storage, uploads và dữ liệu khách ngoài source. Không reset DB hoặc chép đè cấu hình private.
5. Nghiệm thu role Admin/Sales/Operations/Finance/Partner, giá thật → booking, media, DOCX/PDF và provider trên staging. Production là bước triển khai riêng.

Kiểm thử local/CI dùng dữ liệu tổng hợp, không thay thế nghiệm thu cấu hình và dữ liệu máy chủ đang chạy.
