# Test RC6.2 trước khi upload hosting

Bản source nằm trên nhánh `codex/Vietnam/rc6.2-testing`. Bản Preview trực tuyến dùng dữ liệu mẫu trong tab; tải lại sẽ reset. Không dùng Preview để nhập dữ liệu khách thật.

## Test giao diện trên PC

1. GitHub → chọn nhánh `codex/Vietnam/rc6.2-testing` → Code → Download ZIP, rồi giải nén.
2. Mở `START_RC6_2.html`, chọn Mở bản xem thử. Nếu browser chặn file local, dùng Python 3:

```powershell
python -m http.server 8000 --bind 127.0.0.1
```

Mở http://127.0.0.1:8000/START_RC6_2.html hoặc http://127.0.0.1:8000/preview.html.

Nếu dùng Git:

```powershell
git clone --branch codex/Vietnam/rc6.2-testing --single-branch https://github.com/Vietnam202/Tour-Operator-.git
cd Tour-Operator-
python -m http.server 8000 --bind 127.0.0.1
```

## Luồng nên thử

- Marketing → Lead Hub → đánh giá lead mẫu, chọn owner và hạn xử lý, bàn giao Sales.
- Sales → hàng chờ bàn giao → nhận hoặc trả có lý do; Marketing bổ sung và gửi lại.
- Sau khi Sales nhận: review khách, chủ động chọn khách hiện có/tạo mới và xác nhận đã review, rồi tạo inquiry.
- CRM → bấm tên khách → Customer 360; kiểm tra inquiry, quote, booking, tasks và số dư theo currency.
- Operations: kiểm tra đủ 15 thẻ. Tour Program Library: thử upload nội dung mẫu từ PC/link Drive theo adapter hiện có.
- Included / Excluded / Terms: thử nội dung itinerary có/không có các heading và kiểm tra trước áp dụng.

## Test tự động giao diện

Node.js và npm cần được cài sẵn. Bộ test dùng jsdom, không phải server PHP:

```powershell
cd tests
npm install
npm test
```

## Test dữ liệu thật trên staging/local

GitHub Pages không chạy PHP/MariaDB. Dùng server PHP/PDO MySQL + MariaDB, cấu hình riêng ngoài webroot qua `VTA_CONFIG_FILE`, database riêng để test và migrate bằng `api/bin/migrate.php` tới migration022.

Đọc [release note RC6.2](docs/RC6_VS1_RELEASE_VI.md), [báo cáo kiểm thử](verification/RC6.2/RC6_VS1_TEST_REPORT_VI.md) và hướng dẫn cấu hình trong docs. Các migrations001–021 giữ nguyên; chỉ thêm022. Hướng dẫn staging đời trước trong docs cần được đọc cùng release note hiện tại.

Backup/restore database và private storage trước nâng hosting. Không đưa tests/verification hoặc cấu hình riêng vào production webroot. Không cấu hình Pages để phục vụ source branch: branch Preview chỉ chứa static asset allowlist.

## Bản xem thử GitHub Pages

Branch `codex/Vietnam/rc6.2-preview` chứa index/preview HTML, JavaScript, CSS và brand assets; không có api, tests, config hay database. Nếu Pages chưa bật: Settings → Pages → Deploy from a branch → chọn branch này, thư mục / (root). Preview URL: https://vietnam202.github.io/Tour-Operator-/.

VS1 đã hoàn tất; VS2–VS6 còn trong kế hoạch. Chưa triển khai lên hosting chính thức.
