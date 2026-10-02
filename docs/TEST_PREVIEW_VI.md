# Thử Marketing trước khi upload — Preview 1

1. Giải nén toàn bộ gói vào thư mục mới, ví dụ `D:\test-rc3`. Giữ cấu trúc các file/thư mục.
2. Mở `preview.html` bằng Chrome. Không mở `index.html` trong lần xem thử này.
3. Vào Marketing. Phải thấy nhãn **DEMO · MARKETING PREVIEW 1**, 2 chiến dịch và 3 nội dung mẫu.
4. Chọn nút + Campaign / Chiến dịch, điền brief rồi lưu. Đây là ngân sách giả lập, không có chi tiêu thật.
5. Chọn Compose / Soạn nội dung, chọn một trong 7 kênh, sửa văn bản và lưu nháp.
6. Chọn Submit for review / Trình duyệt → Approve / Duyệt hoặc Reject / Từ chối. Đây là mô phỏng quyết định, không phát hành.
7. Chọn New revision / Tạo bản sửa để tạo nháp mới; bản được duyệt trước đó vẫn giữ nguyên.
8. Chuyển sang màn hình khác rồi quay lại: dữ liệu thử vẫn còn. Chọn Reset demo / Đặt lại demo hoặc tải lại trang để trở về dữ liệu mẫu.

Mọi thay đổi Marketing preview chỉ nằm trong bộ nhớ của tab. Đóng/tải lại trang sẽ mất thay đổi; không ghi file hay database. Không gọi API, AI, Composio hoặc quảng cáo. Chiến dịch demo không đồng bộ với Lead Hub trong preview. AI Chat vẫn là hội thoại minh họa, chưa tạo câu trả lời thật.

Phần chạy qua `index.html` vẫn dùng máy chủ và phân quyền như RC3. Xem `START_HERE_RC3_VI.md` khi muốn kiểm thử PHP/MariaDB và OpenAI thật.

Nếu vẫn thấy thông báo “Marketing requires the authenticated PHP staging runtime”, anh đang mở bản cũ. Mở đúng `preview.html` trong thư mục vừa giải nén. Đường dẫn trên Chrome cần trỏ tới thư mục mới.
