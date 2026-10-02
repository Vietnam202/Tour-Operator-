# VTA Web & App + AI Chat — Hướng dẫn RC3

Phiên bản **3.1.0-RC3**, đóng gói 27/09/2026. Đây là gói nâng cấp; hosting chưa được cập nhật bởi việc tạo file này. Tiếp tục chỉ cho phép staging `v2quote.vietnamtraveladvisor.com.vn` và localhost.

## Chức năng

| Phần | RC3 |
|---|---|
| Web & App | Cùng tài khoản trên máy tính, tablet, điện thoại; PWA cài lên màn hình chính |
| Menu | 5 nút điện thoại; More mở đủ phân hệ, cài ứng dụng và đăng xuất |
| AI Chat | Trợ lý Sales, Marketing, thiết kế tour, điều hành; lịch sử riêng theo tài khoản và công ty |
| Ngữ cảnh | Chọn brief: tên chiến dịch, thị trường, sản phẩm, khách mục tiêu; xem trước khi gửi |
| Marketing | Sửa câu trả lời rồi lưu DRAFT; dùng luồng Marketing hiện có để trình duyệt |
| Cập nhật | Báo bản mới; lưu công việc trước khi tải lại |

Chưa có APK/IPA hoặc bản phát hành trên App Store/Google Play. AI chưa đọc trực tiếp toàn bộ CRM, giá, booking, tồn chỗ hay website. Không tự gửi khách, đăng bài, đặt dịch vụ hoặc chi quảng cáo. Kết nối kênh thật qua Composio còn cần cấp quyền và cấu hình riêng.

CampaignPilot trên Sites/D1 vẫn giữ nguyên. Gói PHP này chưa nhập dữ liệu, đồng bộ hai chiều hoặc chuyển các tài khoản OAuth từ bản Sites.

## 1. Chép file vào đúng website

1. Sao lưu database và file hiện tại ra ngoài `public_html`.
2. Xác nhận document root của **v2quote.vietnamtraveladvisor.com.vn** trong hosting. Nếu trang quản trị báo lỗi chứng chỉ, cần quản trị hosting sửa chứng chỉ trước khi tiếp tục.
3. Giải nén vào thư mục tạm ngoài vùng công khai. Gói có `index.html`, `app.js`, `ai-chat.js`, `platform.js`, `.htaccess`, `api/`, `assets/` cùng cấp. Chép vào document root thực tế, tránh lồng thêm một thư mục chứa ứng dụng.
4. Giữ cấu hình database và kho tài liệu riêng đang dùng ngoài web. Không thay cấu hình thật bằng `api/config.example.php`.
5. Hosting cần PHP 8.1+, PDO MySQL, JSON, session và cURL. Lần này cú pháp được kiểm tra bằng PHP 8.5.10; cần xác nhận trên runtime của hosting.

## 2. Nâng cấp database cũ

Trong Terminal/SSH, chuyển vào document root rồi chạy:

```bash
php api/bin/migrate.php
php api/bin/healthcheck.php
```

**Không chạy install.php trên database đã có người dùng.** Nếu đang dùng biến `VTA_CONFIG_FILE`, CLI và web PHP phải cùng trỏ tới file cấu hình riêng hiện có.

RC2 sẽ áp dụng `018_ai_chat`; RC1 có thể áp dụng thêm 017. Migration 018 tạo 3 bảng AI và cấp `ai.chat` cho ADMIN, SALES, PRODUCT, OPERATIONS. Quyền DENY riêng vẫn chặn API. Không sửa migration cũ hoặc ép thử lại khi có lỗi checksum/migration dở dang; cần kiểm tra database và log.

## 3. Kết nối AI

Thêm mục dưới đây ở cấp ngoài cùng của cấu hình riêng. Giữ nguyên các mục khác:

```php
'ai' => [
    'enabled' => true,
    'api_key' => getenv('OPENAI_API_KEY') ?: '',
    'model' => getenv('VTA_AI_MODEL') ?: '',
    'daily_limit' => 30,
    'max_output_tokens' => 2000,
],
```

Đặt `OPENAI_API_KEY` và `VTA_AI_MODEL` trong môi trường PHP hosting. Chọn tên model mà tài khoản được phép gọi qua Responses API. Nếu hosting không hỗ trợ biến môi trường, điền trực tiếp trong **file cấu hình riêng ngoài public_html**. Không đưa khóa vào JavaScript, gói ZIP công khai hoặc hội thoại hỗ trợ.

```bash
php api/bin/ai-check.php
```

Lệnh chỉ kiểm tra cấu hình/bảng, không in khóa và không gọi OpenAI. PASS chưa xác nhận key/model gọi thành công. Nếu CLI PASS nhưng giao diện “Chờ cấu hình”, kiểm tra web PHP và CLI có cùng cấu hình, môi trường và cURL. Hosting cần HTTPS ra `api.openai.com`; giữ xác minh TLS bật.

Mặc định 30 lần gọi/người/ngày theo ngày database. Lần gọi provider bị lỗi cũng tính hạn mức; không tự thử lại. Khi thử lại cùng yêu cầu, server trả kết quả đã lưu nếu có. Nếu chưa lưu, thử lại có thể phát sinh lượt tính phí mới. Theo dõi usage/billing trên tài khoản OpenAI.

Ứng dụng gửi yêu cầu, tối đa 10 lượt chat gần nhất và brief đã chọn. Không nhập hộ chiếu, thông tin thanh toán, khóa truy cập hoặc dữ liệu nhạy cảm không cần thiết. `store: false` được gửi tới Responses API; không đồng nghĩa nhà cung cấp không lưu dữ liệu ở mọi cấp. VTA vẫn lưu chat trong database để hiện lịch sử; RC3 chưa có nút xóa lịch sử.

## 4. Xác nhận đã cập nhật đúng bản

- Đăng nhập/menu phải ghi **3.1.0-RC3**.
- `https://v2quote.vietnamtraveladvisor.com.vn/api/?route=health` phải trả `ok: true`, `version: 3.1.0-RC3`.
- Giao diện mới/API cũ: kiểm tra thư mục `api/` đã chép đúng document root.
- Cả hai cũ: kiểm tra document root và cache hosting/CDN; thử cửa sổ riêng tư.
- Riêng tư mới/tab cũ: lưu việc, đóng các tab VTA rồi mở lại. Nếu service worker cũ vẫn giữ bản cũ, dùng DevTools → Application → Service Workers → Unregister rồi tải lại. Không xóa dữ liệu site khi còn biểu mẫu chưa lưu.

Service worker RC3 không cache API, chat hoặc HTML nghiệp vụ. Mất mạng chỉ hiện hướng dẫn kết nối lại; chưa hỗ trợ lưu/chỉnh nghiệp vụ offline.

## 5. Thử bằng dữ liệu mẫu

1. AI Chat → Hội thoại mới → Trợ lý nội dung. Đặt tên “Thử chiến dịch India”, có thể chọn brief rồi xem lại.
2. Nhập: “Soạn 3 hook tiếng Anh cho gia đình Ấn Độ đi Việt Nam 7 ngày. Chưa có giá/ngày đi. Không hứa dịch vụ chưa xác nhận.”
3. Xác nhận gửi dữ liệu → Gửi AI. Kiểm tra phản hồi thật; tải lại trang và xác nhận còn lịch sử.
4. Lưu nháp Marketing → sửa nội dung/chọn kênh. Trong Marketing phải là **DRAFT** và chưa đăng ra kênh.
5. Dùng tài khoản thứ hai để kiểm tra không thấy chat của tài khoản thứ nhất. Tài khoản thiếu `ai.chat` phải bị API chặn với 403.
6. Kỹ thuật cần chạy migration và kiểm thử đồng thời trên MariaDB staging trước khi sử dụng thật.

## 6. Cài PWA

- Android: Chrome → More trong VTA → Cài ứng dụng; hoặc mục cài trong menu trình duyệt khi có.
- iPhone/iPad: Safari → Chia sẻ → Thêm vào Màn hình chính.
- Máy tính: Chrome/Edge → nút cài hoặc mục Install app khi có.

Lời mời cài phụ thuộc trình duyệt/thiết bị. Chưa kiểm thử cài trên thiết bị thật trong lần đóng gói này. Cần online để dùng nghiệp vụ/AI; dữ liệu đồng bộ qua server.

## 7. Khôi phục

Tắt `ai.enabled` để ngừng lượt AI mới. Nếu giao diện lỗi, phục hồi file RC2 từ backup; bảng AI mới là bổ sung, không cần xóa ngay. Khôi phục database phải dùng backup phù hợp và tính đến dữ liệu phát sinh sau backup. Không chạy installer để sửa đăng nhập.

Tài liệu chính thức:

- https://developers.openai.com/api/docs/guides/migrate-to-responses
- https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable

Kết quả và giới hạn kiểm thử: `verification/RC3_TEST_REPORT_VI.md`.
