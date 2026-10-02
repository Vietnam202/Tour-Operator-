# VTA Unified OS 3.3.0-RC5 — Landing Page Builder

RC5 bổ sung **Marketing → Landing Pages** theo file HTML mẫu anh gửi ngày 28/09/2026. Đây là bộ dựng trang tích hợp trong phần mềm PHP VTA; chưa được upload hoặc chạy migration trên hosting bởi việc tạo gói này.

## Thử trên máy trước khi upload

1. Giải nén **toàn bộ** ZIP vào thư mục mới, ví dụ `D:\VTA-RC5`. Không mở file ngay bên trong ZIP và không chỉ chép riêng `preview.html`.
2. Mở `preview.html` bằng Chrome. Góc phần mềm phải là **3.3.0-RC5**.
3. Chọn **Marketing → Landing Pages**. Màn hình đầu có mẫu Standard 6D5N với 12 khối.
4. Kéo một khối từ cột trái vào vị trí muốn thêm. Kéo nút **⠿** trên khối để đổi vị trí. Điện thoại hoặc bàn phím có thể dùng **↑ / ↓**.
5. Bấm **Sửa**, chỉnh nội dung ở cột phải. Với gallery, bảng giá, lịch trình chi tiết, review hoặc social: dùng **+ Thêm mục**, sửa từng mục, hoặc Xóa mục.
6. Chọn **Pro · Form đầu trang → + Trang mới** để thử mẫu thứ hai. Đồng ý thay trang hiện tại chỉ khi đã lưu/tải thiết kế cần giữ.
7. Thử **Hoàn tác / Làm lại**, **Xem máy tính / tablet / điện thoại**, rồi **Lưu demo** và **Mở bản nháp**.
8. Bấm **Tải file chỉnh sửa** để giữ thiết kế thành JSON. Khi mở phần mềm lần sau, bấm **Nhập file chỉnh sửa** để tiếp tục.
9. Bấm **Xuất HTML**, mở `vta-landing.html` bằng Chrome để kiểm tra bố cục và nút liên hệ. Có thể dùng **Xem / sao chép HTML** nếu cần lấy mã nguồn.

**Lưu demo chỉ giữ trong tab hiện tại.** Reload hoặc đóng tab sẽ mất các bản demo; JSON đã tải vẫn giữ được thiết kế. Ảnh dùng link HTTPS do anh nhập nên cần mạng. Không tự upload, đăng quảng cáo hay gửi tin nhắn.

## Các khối tích hợp

| Khối | Cách chỉnh |
|---|---|
| Hero Standard / Hero + form | Tiêu đề, mô tả, ảnh thật, nút liên hệ hoặc form Lead Hub |
| Tổng quan / đoạn văn / điểm nổi bật | Văn bản thuần; mỗi dòng điểm nổi bật thành một mục |
| Lịch trình | Danh sách đơn giản hoặc các ngày riêng có tiêu đề, mô tả và ảnh |
| Gallery điểm đến / ảnh khách hàng | Tối đa 20 ảnh/mục mỗi khối, kèm chú thích và quyền sử dụng |
| Bao gồm / không bao gồm | Hai cột, mỗi dòng một dịch vụ |
| Bảng giá / lựa chọn tour | Tên gói, giá công khai, mô tả, link yêu cầu từng gói |
| Review | Nội dung đã xác nhận, tên người/nguồn và đường dẫn đánh giá gốc |
| FAQ | Mỗi dòng theo dạng `Câu hỏi | Câu trả lời` |
| Social | Tên kênh + link HTTPS; mặc định chỉ có WhatsApp VTA |
| Đếm ngược | Chọn ngày/giờ Việt Nam UTC+7; hết hạn hiển thị đã kết thúc, không tự reset |
| Form yêu cầu tour | Chọn form hiện có của đúng chiến dịch trong Lead Hub |
| Ảnh / CTA riêng | Link ảnh hoặc nút liên hệ có thể đặt ở bất kỳ vị trí nào |

Các giá, số khách hài lòng, đánh giá 5 sao, thời hạn giảm giá và cam kết phản hồi trong file mẫu chưa được xác minh nên không tự điền thành thông tin thật. Mẫu 6D5N là tuyến gợi ý cần xác nhận với sản phẩm. Chưa nhập các ảnh HEIC/JPG chỉ có tên trong file mẫu; cần link ảnh công khai thật. Nên dùng JPG, PNG hoặc WebP. Có thể chọn link từ Kho nội dung nếu đã lưu quyền sử dụng; link trang Canva/Drive không phải link ảnh trực tiếp.

Thiết kế xuất có CSS riêng, không cần Tailwind/Font Awesome CDN. Nội dung và link được kiểm tra/escape, không cho nhập HTML/JavaScript tùy ý. Màu nút, thương hiệu, tên trang, SEO description và ngôn ngữ HTML chỉnh trong **Thiết lập trang & chiến dịch**. Giao diện quản trị bằng tiếng Việt, nội dung mẫu dành cho khách quốc tế bằng tiếng Anh.

## Form nhận khách thật sau khi cài trên staging

1. Vào **Lead Hub**, tạo/chọn một chiến dịch đang hoạt động và tạo form cho chiến dịch đó.
2. Trong **Marketing → Landing Pages → Thiết lập trang & chiến dịch**, chọn cùng chiến dịch.
3. Bấm **Tải danh sách**; chọn khối **Hero + form** hoặc **Form yêu cầu tour**, chọn form từ dropdown Lead Hub. Lưu bản nháp.
4. Xuất HTML, đặt trong thư mục landing riêng trên **cùng tên miền HTTPS với ứng dụng VTA**, ví dụ `public_html/landing/india-6d5n.html`. Không thay `index.html` của ứng dụng bằng landing page.
5. Mở URL staging của trang đó. Thử một yêu cầu có tên, email hoặc điện thoại, tổng khách, khách trả tiền, FOC và đồng ý liên hệ. Dùng dữ liệu test được nhận diện rõ.
6. Xác nhận yêu cầu xuất hiện trong Lead Hub, đúng form/chiến dịch. Thử link có UTM để kiểm tra nguồn quảng cáo.

Form gửi JSON tới API nhận khách hiện có, gồm token form công khai, `contact_name`, số khách, thời gian đi, nội dung và attribution. Có chống gửi lặp khi thử lại sau lỗi mạng. Chỉ hiển thị đã nhận khi API trả thành công. Form không tự qualify khách, tạo báo giá hoặc gửi WhatsApp/email.

Trong **preview của builder**, form bị khóa và CSP chặn kết nối mạng gửi dữ liệu. Trong file HTML mở từ ổ đĩa hoặc tên miền khác, form hướng dẫn mở trên tên miền ứng dụng và không gửi. Form chưa kết nối hiển thị rõ là bản nháp. Cross-domain form, upload media và tự xuất bản lên hosting chưa triển khai; có thể dùng nút dẫn tới link form Lead Hub nếu trang nằm trên tên miền khác.

## Nâng cấp server RC4 → RC5

Gói vẫn giới hạn **staging v2quote.vietnamtraveladvisor.com.vn / localhost**, giữ nguyên RuntimeGuard. Không chuyển cấu hình sang production bằng cách sửa bỏ kiểm tra này.

1. Sao lưu file và database ra ngoài thư mục web. Giữ cấu hình/kho dữ liệu riêng ngoài document root.
2. Chép nội dung ZIP vào đúng document root staging; không lồng thêm thư mục RC5.
3. Dùng PHP 8.1+ có PDO MySQL, session, JSON; AI Chat giữ yêu cầu cURL/API key riêng của RC3.
4. Từ document root chạy:

```bash
php api/bin/migrate.php
php api/bin/healthcheck.php
php api/bin/marketing-check.php
```

Migration mới **020_landing_pages**; migration 001–019 giữ nguyên byte so với RC4. Database cũ dùng migration, **không chạy installer**. Nếu migration/checksum lỗi hoặc FAILED, kiểm tra nguyên nhân trước khi tiếp tục; không xóa trạng thái để ép chạy lại.

5. Đăng nhập, xác nhận **3.3.0-RC5**. Nếu trình duyệt còn bản cũ, dùng chức năng cập nhật PWA hoặc tải lại trang sau khi hoàn tất upload.
6. Tạo trang, lưu, reload, mở lại; kiểm tra bằng tài khoản chỉ xem và tài khoản công ty khác. Thử hai tab cùng sửa: bản cũ phải bị báo xung đột. Khi xung đột, tải JSON của thay đổi đang làm trước khi mở lại bản server.
7. Kiểm thử form với dữ liệu giả trên MariaDB staging rồi kiểm tra Lead Hub. Chưa coi bản này là đã nghiệm thu production.

Quyền server: `lead.view` xem danh sách/mở bản nháp; `campaign.manage` lưu. Mỗi trang thuộc một công ty, form phải thuộc đúng chiến dịch, cập nhật dùng số phiên bản để tránh ghi đè bản của người khác. Lưu nháp không xuất bản. API luôn kiểm tra quyền riêng với UI.

Giới hạn: danh sách 200 trang gần nhất; tối đa 40 khối/trang, 20 mục/khối, thiết kế 450 KB; lịch sử hoàn tác trong tab 60 bước. JSON giữ cấu trúc nội dung để chỉnh sửa, không chứa bản sao file ảnh. Không nhập trực tiếp HTML bất kỳ vào builder.

## Kiểm thử và phạm vi còn lại

Xem `verification/RC5_TEST_REPORT_VI.md`. Bộ kiểm tra cục bộ dùng jsdom, PHP WASM và PDO SQLite; chưa render CSS bằng Chrome thật, chưa thử iPhone/Android và chưa chạy MariaDB trên hosting. Các luồng form được giả lập API, không gửi lead tới máy chủ thật.

Các chức năng Marketing RC4, Tour Operator OS, PWA và AI Chat được giữ lại. Tài khoản Composio/OAuth trên ChatGPT hoặc Sites không tự chuyển vào server PHP. Đăng quảng cáo/mạng xã hội và tự đồng bộ inbox vẫn chưa kết nối.

Khôi phục: dùng backup file nếu cần quay lại RC4. Bảng mới là bổ sung; không tự xóa dữ liệu landing page khi rollback file. Chỉ phục hồi database từ backup sau khi cân nhắc dữ liệu mới phát sinh.
