# VTA Content & Marketing Studio — RC4

Phiên bản **3.2.0-RC4**, ngày 28/09/2026. Bổ sung quy trình vận hành Marketing theo các nhóm chức năng anh yêu cầu trong bản phân tích SO9. Đây là phần mềm VTA tự phát triển, không phải sản phẩm hoặc bản tích hợp đã được SO9 xác nhận.

## Thử ngay trước khi upload

1. Giải nén toàn bộ ZIP vào thư mục mới, ví dụ `D:\vta-rc4`.
2. Mở `preview.html` bằng Chrome; vào Marketing.
3. Phải thấy **Content & Marketing Studio**, nhãn **DEMO · RC4** và 8 tab.
4. Dữ liệu demo chỉ giữ trong tab, tải lại trang hoặc chọn Đặt lại demo sẽ khôi phục dữ liệu mẫu. Không phát sinh chi tiêu, API AI hoặc bài đăng thật.

### Bài thử gợi ý cho VTA

1. **Chiến dịch:** tạo “India Family – Vietnam 7 days”, thị trường India, ghi sản phẩm ở mức concept nếu chưa xác nhận dịch vụ/giá. Ngân sách là dự kiến.
2. **Kho nội dung:** thêm mẫu hook/caption/kịch bản, chọn một trong 6 chủ đề VTA hoặc General. Có thể đính kèm link HTTPS tới Canva/Drive/media cùng ghi chú quyền sử dụng. Hệ thống chỉ lưu link tham chiếu.
3. **Soạn đa kênh:** chọn chiến dịch, Facebook + Instagram + TikTok + YouTube Shorts. Điền nội dung gốc và giờ dự kiến theo Việt Nam.
4. **Kiểm tra từng bản:** sửa caption cho từng kênh rồi Lưu tất cả bản nháp. Nội dung được lưu riêng cho từng kênh.
5. **Duyệt:** Trình duyệt → Duyệt/Từ chối. Nội dung đã duyệt nằm ở trạng thái chờ phát hành; không tự đăng. Chỉnh sửa bằng Bản sửa mới, không ghi đè bản đã duyệt.
6. **Lịch:** xem ngày dự kiến theo UTC+7, chuyển tháng và bấm ngày để lọc. Lịch dự kiến không đồng nghĩa lệnh đăng đã được gửi.
7. **Lặp lại:** ở Kho nội dung chọn Lên kế hoạch lặp. Chọn số lần và khoảng cách ngày; hệ thống tạo tập bản nháp hữu hạn để xem lại. Tối đa 8 lần và 30 bản nháp/lần lưu. Không có cron tự xoay kho/tái đăng.
8. **Trao đổi & lead:** thêm một bình luận/tin nhắn mẫu bằng tay, soạn nháp trả lời, phân công nhân viên. Khi có thông tin liên hệ và số khách đã xác nhận, chuyển thành yêu cầu Lead Hub.
9. **Báo cáo:** xem yêu cầu → qualified → inquiry theo chiến dịch. Trong preview là số mẫu; ở bản server là dữ liệu VTA thực. Đây là các mốc lũy kế, không cộng chúng thành tổng khách.

Chuyển Lead Hub ở preview là mô phỏng trong Marketing, chưa ghi vào màn hình Lead Hub demo riêng. Trên máy chủ, thao tác tạo bản ghi `lead_requests` thật với mã chiến dịch, nguồn nhập tay và mã trao đổi. Nhân viên tiếp tục qualify/chuyển inquiry trong Lead Hub hiện có; chưa tự tạo báo giá hay gửi khách.

## Tính năng đã có và giới hạn

| Nhóm | RC4 có thể làm | Chưa kết nối/triển khai |
|---|---|---|
| Kho nội dung | Lưu mẫu, chủ đề, thẻ, link media, ghi chú quyền sử dụng, tái sử dụng | Upload/lưu file media, biên tập video, đồng bộ Drive/Canva |
| Đa kênh | Soạn và sửa biến thể cho 12 kênh; lưu theo lô | Gửi bài, upload video tới nền tảng, first comment |
| Lịch | Lịch tháng, lọc ngày, kế hoạch UTC+7, xuất kế hoạch JSON | Tự đăng đến giờ, xử lý lỗi gửi/retry ở nhà cung cấp |
| Duyệt | DRAFT → PENDING → APPROVED/REJECTED; tạo revision mới | Duyệt không tự kích hoạt đăng |
| Nội dung lặp | Tạo tối đa 30 nháp từ một mẫu, lịch lặp hữu hạn | Xoay nhiều kho theo luật evergreen chạy nền |
| Trao đổi | Ghi nhận thủ công, nháp trả lời, phân công, trạng thái theo dõi | Tự đồng bộ inbox/comment, chatbot trả lời khách |
| Lead | Tạo yêu cầu Lead Hub thật, chống tạo trùng, giữ campaign attribution | Không tự qualify hoặc tạo báo giá |
| Báo cáo | Số yêu cầu/qualified/inquiry theo mã chiến dịch | Chưa có reach, impressions, spend, CPL hoặc ROAS từ quảng cáo |
| AI | Mở AI Chat RC3, chọn brief, tạo nháp và lưu Marketing khi đã cấu hình API | Preview không gọi AI; chưa sinh ảnh/video trong ứng dụng |
| Kênh | Trang trạng thái cho Facebook, Instagram, TikTok, Shorts, Google Ads, WhatsApp, Gmail, Threads, Google Business, WordPress, X, Pinterest | Chưa có OAuth/token máy chủ hoặc bộ xử lý phát hành |

Không chuyển tài khoản OAuth hoặc dữ liệu Sites/D1 từ bản CampaignPilot cũ. Tài khoản Composio trong ChatGPT không tự trở thành kết nối trên máy chủ PHP. Nếu dùng SO9/Make/n8n làm nhà cung cấp phát hành, cần thiết lập API, quyền và kiểm thử riêng; không nhập token vào nội dung bài hoặc file công khai.

## Cài bản server sau khi duyệt giao diện

1. Sao lưu file và database hiện có ra ngoài thư mục web. Xác nhận document root của staging `v2quote.vietnamtraveladvisor.com.vn`.
2. Chép nội dung gói vào document root; giữ cấu hình/kho tài liệu riêng ngoài web. Không lồng thêm một thư mục ứng dụng.
3. Dùng PHP 8.1+ có PDO MySQL, JSON và session. AI cần thêm cURL cùng cấu hình OpenAI riêng như RC3.
4. Từ document root, chạy:

```bash
php api/bin/migrate.php
php api/bin/healthcheck.php
php api/bin/marketing-check.php
```

Migration mới là **019_marketing_studio**. Các migration 001–018 giữ nguyên. Không chạy `install.php` trên database cũ. Nếu có lỗi checksum hoặc migration dở dang, dừng để kiểm tra; DDL có thể đã commit một phần. Không xóa trạng thái FAILED để ép chạy lại.

5. Đăng nhập lại và xác nhận giao diện/API health là **3.2.0-RC4**. Đăng nhập với hai tài khoản/công ty để kiểm tra quyền và ranh giới dữ liệu; thử batch, revision, phân công, chuyển lead, refresh để xác nhận lưu database.
6. Kiểm thử migration, khóa/concurrency và API session trên MariaDB staging trước khi dùng dữ liệu thật. Bản này vẫn bị giới hạn staging/localhost; chưa nâng cấp production.

Quyền: `lead.view` để xem Marketing; `campaign.manage` để soạn chiến dịch, kho, nháp; `marketing.approve` để duyệt; `lead.manage` để ghi nhận trao đổi/phân công/chuyển yêu cầu. Giao diện không thay thế kiểm tra quyền API.

Giới hạn tải: 500 nội dung mới nhất, 300 chiến dịch, 300 mẫu, 200 trao đổi. Bộ lọc và lịch chạy trên dữ liệu đã tải; chưa phải hệ thống lưu trữ/tìm kiếm vô hạn. Các chỉ số trạng thái tổng quan lấy số đếm trên server. Lịch lớn hơn phạm vi này cần bổ sung phân trang/lọc server ở bản sau.

## Kiểm thử và khôi phục

Xem `verification/RC4_TEST_REPORT_VI.md`. Kiểm thử cục bộ dùng dữ liệu giả, jsdom và PHP WASM/PDO SQLite; chưa phải chứng nhận MariaDB hoặc thiết bị thật.

Nếu cần quay lại RC3, phục hồi file đã sao lưu. Migration 019 bổ sung trường/bảng; không tự xóa dữ liệu mới. Khôi phục toàn bộ database chỉ theo bản backup đã kiểm tra và cân nhắc các thay đổi phát sinh sau thời điểm sao lưu.
