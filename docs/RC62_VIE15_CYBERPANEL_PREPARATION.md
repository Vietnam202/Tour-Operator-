# VIE-15 / PR #92 — Chuẩn bị triển khai CyberPanel

Ngày lập: **14/10/2026, UTC+07:00**. Trạng thái: **NO-GO production**.

Tài liệu này ghi kế hoạch và điều kiện kiểm chứng. Không cấp quyền chạy migration, ghi file live, thay cấu hình, merge PR hoặc triển khai. Owner yêu cầu giữ production nguyên trạng đến khi có phê duyệt GO cụ thể. PR #92 tiếp tục ở trạng thái Draft.

## 1. Nguồn và bản đang chạy

- [Bàn giao PR #92](https://github.com/Vietnam202/Tour-Operator-/pull/92#issuecomment-6162360111): nhánh `feat/rc62-inquiry-soft-delete-20261014`, HEAD bàn giao `c037d5357ff30635e73e01b1aff2da8044835a5e`.
- Base PR #92: `5b89a90049aed32ed4a45b3fc4cfcb29c6aa47ad` (PR #90).
- Bản PR #91 đã triển khai ở lượt trước có identity `d7f0343993a1d7d3be396df1104ae7c4eddfb06f`, source inventory `405b45fc830e8f9150666de1206dcb615b8e302c1592ba0b89814f8a6231cff0`, 253 source files. Đây là bằng chứng health của lượt trước; phải đọc lại health và đối chiếu bytes file trước cutover. Health tự khai identity không thay thế kiểm tra hash file.
- File Manager đã được xác nhận trong phiên Chrome: `/home/v2quote.vietnamtraveladvisor.com.vn/public_html`; API thực thi là `public_html/api` qua `api/index.php` → `api/bootstrap.php`. Thư mục `/home/v2quote.vietnamtraveladvisor.com.vn/api` khác với API đang phục vụ web.
- Đích kiểm tra: `https://v2quote.vietnamtraveladvisor.com.vn`; File Manager: `https://server1.vietnamtraveladvisor.vn:8090/filemanager/v2quote.vietnamtraveladvisor.com.vn`.
- DB identity, phiên bản PHP/MariaDB và migration ledger hiện tại: **chưa được xác minh trong đợt VIE-15**. Không suy ra tên DB từ domain hoặc từ fixture cục bộ.

Mọi sửa đổi sau HEAD bàn giao phải có commit mới, CI và artifact của đúng commit đó trước khi xin GO. Không gắn identity `c037d535...` vào nội dung đã sửa nhưng chưa commit.

## 2. Giữ đầy đủ bản vá PR #91

PR #92 tách từ nền cũ hơn bản đang chạy. Chép nguyên candidate #92 hiện tại có thể làm mất các sửa lỗi đã triển khai. Đối chiếu [diff PR #91](https://github.com/Vietnam202/Tour-Operator-/pull/91/files) và tích hợp vào feature branch trước khi đóng gói:

| File | Hành vi phải giữ |
| --- | --- |
| `api/lib/CoreOS.php` | Danh sách quote lấy tên tour, ngày, tổng khách, paying PAX và FOC từ `quote_versions` hiện tại; không quay lại lấy dữ liệu cũ từ `trips`. |
| `b2c-studio.js` | Hướng dẫn kho trống, Làm mới/Thử lại, kiểm tra response, bỏ response cũ sau chuyển màn hình, xử lý không có Master khả dụng. |
| `document-editor.js` | Nhãn/hướng dẫn đang xem trước Master và cách vào sửa. |
| `tour-library.js` | Nhãn xem trước và số ngày chưa xác định. |
| `unified-workspace.js` | Hướng dẫn Booking bền trên màn hình khi chưa có booking; không tạo booking tự động. |
| `index.html`, `preview.html`, `service-worker.js` | Giữ các asset PR #91; bổ sung phiên bản mới cho những asset VIE-15 thay đổi. |

Giữ các regression test PR #91 cùng CI liên quan. Không coi việc chỉ áp dụng hunk xóa Lead vào một bản CoreOS cũ là một release đồng nhất.

## 3. Nội dung runtime và đóng gói

Tập thay đổi phải được tính lại từ **hash của runtime thực tế** đến candidate cuối cùng. Danh sách dưới đây là tập dự kiến để review, chưa phải manifest được phê duyệt:

| Nhóm | File dự kiến |
| --- | --- |
| Schema | `api/migrations/049_inquiry_soft_delete.sql`, `api/migrations/050_inquiry_lifecycle_version.sql` |
| API | `api/lib/CoreOS.php`, `api/lib/WorkspaceCenter.php`, `api/lib/InquiryLifecycle.php`, `api/bootstrap.php`; mọi nơi chuyển trạng thái quote/booking được sửa để dùng cùng quy tắc khóa |
| Giao diện | `app.js`, `unified-workspace.css`, `unified-workspace.js` |
| Phiên bản trình duyệt | `index.html`, `preview.html`, `service-worker.js` |
| Tương thích PR #91 | Các file ở mục 2 nếu hash đích khác hash thực tế trên máy chủ |
| Định danh | `api/runtime-build-identity.json` được sinh từ candidate cuối cùng |

`deploy/build-integrated-package.py` chọn file qua `git ls-files`, tự lấy file PHP/SQL dưới `api/` và JS/CSS/HTML ở root. Vì vậy migration 049/050 và helper PHP được đưa vào tự động **khi đã tracked và commit**. File helper chưa tracked sẽ bị bỏ qua; phải kiểm tra tồn tại của helper trong manifest/ZIP, không chỉ kiểm tra builder chạy thành công.

Builder từ chối tracked edits chưa commit và kiểm tra bytes khớp commit. `deploy/build-full-install.py` dùng manifest để tạo migration plan; thứ tự dùng core SQL rồi danh sách Marketing deferred trong `Migrations.php`, nên 049/050 tự có trong plan. Trên DB đã đủ 048, chỉ 049 rồi 050 được phép pending; nếu có migration khác pending, unknown hoặc checksum sai thì dừng để review. Migration 050 bổ sung `version_no` với mặc định 1 để phát hiện request xóa/khôi phục dùng phiên bản cũ; không sửa 049 đã bàn giao để nhét thêm cột.

Không sử dụng nguyên trạng `deploy/staging-release.php` cho PR #92: controller đang ghim `codex/Vietnam/rc6.2-testing` và baseline `9de9f6c...`, đồng thời từ chối migration mới qua `MIGRATION_REVIEW_REQUIRED`. Không đổi pin hoặc bỏ chặn để ép deploy. Kế hoạch operator cho candidate này cần được duyệt riêng.

Sau khi candidate đã commit và CI đạt, build trên checkout sạch của chính commit đó:

```text
python deploy/build-integrated-package.py --output dist
python deploy/build-full-install.py dist
```

Lưu ngoài webroot: exact source SHA, CI run ID, hash ZIP, hash manifest, số file, số migration, inventory hash, hash từng file trước/sau và người kiểm tra. ZIP full-install là artifact nghiệm thu; không giải nén chồng trực tiếp lên live. Không đưa config, mật khẩu, uploads, DB dump hoặc log có PII vào ZIP/repo/issue.

## 4. Cache và trình duyệt

- Bump token của `app.js` và `unified-workspace.css` trong **cả** `index.html`, `preview.html` và `service-worker.js`; bump `unified-workspace.js` nếu sửa logic tương ứng.
- Dùng một tên cache mới, khác cache live PR #91 `vta-RC62-AUDIT-20261014-1`. Đồng bộ kiểm tra cache trong builder và các test có pin token.
- Xác minh HTML chính/preview nạp cùng phiên bản, mọi URL precache tồn tại, cập nhật service worker từ phiên đã có cache PR #91 và phiên mới.
- Test trình duyệt đã mở từ trước khi cập nhật: có thông báo cập nhật, kích hoạt phiên bản mới, thấy nút × và modal đúng kiểu, không nạp cặp JS/CSS/API khác phiên bản. Không xóa bản nháp hay dữ liệu người dùng để xử lý cache.

## 5. Bằng chứng còn thiếu — VIE-6 và VIE-11

| Gate | Bằng chứng phải có | Trạng thái |
| --- | --- | --- |
| Runtime đang chạy | Health mới, docroot/include chain, PHP/extensions, hash tập file thay đổi | Một phần có từ phiên trước; cần xác minh lại trước cutover |
| DB hiện tại | MariaDB version, DB identity đối chiếu cấu hình và `SELECT DATABASE()`, ledger/checksum trước migration | Chưa xác minh |
| Backup mới | File + DB cùng mốc thời gian, checksum, quyền truy cập riêng, kế hoạch giữ dữ liệu sinh sau backup | Chưa có evidence được xác minh |
| Restore thật | Khôi phục backup vào clone độc lập; đối chiếu row count/metadata và khả năng chạy lại | Chưa thực hiện |
| Migration clone | 049 + 050 append-only, checksum trước/sau, lần chạy lại không đổi; không chỉnh migration cũ | Chưa thực hiện trên clone DB hiện tại |
| UAT staging | Xóa/khôi phục qua đăng nhập thật và regression Quote/Booking/Operations/PR #91 | Chưa nghiệm thu |
| Candidate cuối | CI đúng SHA, manifest/ZIP khớp bytes, reviewer signoff | Chờ hoàn tất code và CI |
| GO owner | Phê duyệt candidate SHA, đích, cửa sổ triển khai và operator rollback | Chưa có |

MariaDB fixture cục bộ/CI chỉ được ghi `PASS_CI_FIXTURE` hoặc kiểm thử cục bộ; không đổi thành `PASS_STAGING_REAL_AUTH`. Giữ trạng thái NO-GO cho [VIE-6](https://linear.app/vietnamtraveladvisor/issue/VIE-6) và [VIE-11](https://linear.app/vietnamtraveladvisor/issue/VIE-11) cho đến khi bằng chứng thật được kiểm chứng.

## 6. Rehearsal trên staging cô lập

1. Operator xác nhận hostname/docroot riêng, cấu hình riêng ngoài webroot, clone DB độc lập và tài khoản riêng. Giữ RuntimeGuard; không dùng cấu hình production để chạy test.
2. Sao lưu mới và restore thử thật. Chụp ledger/checksum cùng inventory trước migration. Không dùng full-server restore mù vì có thể ghi đè dữ liệu/tài khoản khác.
3. Kiểm tra trạng thái migration 049/050 và không có drift. Chạy đúng phần còn pending qua runner đã review trên clone; không sửa ledger/checksum thủ công, không chạy lại ALTER nếu trạng thái FAILED/RUNNING chưa được điều tra.
4. Xác minh `deleted_at`, `deleted_by`, `version_no`, index và FK; ledger/checksum đúng; chạy lại runner không thêm thay đổi. Đối chiếu row count của inquiries/trips/leads/quotes/quote_versions/bookings trước/sau migration.
5. Chạy API với Admin/Sales có quyền, người không có quyền và tenant khác: archive, restore, CSRF thiếu/sai, 403/404/409, request lặp, phiên bản cũ, atomicity, race với tạo quote/booking; kiểm tra audit log và không hard-delete liên kết.
6. Qua UI: × chỉ hiện khi có quyền; xác nhận theo mã hồ sơ, hủy không đổi; active/Pipeline/follow-up/KPI ẩn bản xóa; Thùng rác khôi phục; quote approved/sent/confirmed và booking chặn xóa; draft được giữ nguyên. Test phiên mở trước khi xóa để tránh cập nhật/tạo quote từ Lead đã bị xóa.
7. Regression PR #91: quote list/detail khớp PAX/FOC và ngày/tên; Master preview/edit; B2C trống/lỗi; Booking đã có mở đúng, chưa có chỉ hướng dẫn. Tiếp tục Lead → Quote và luồng nghiệp vụ theo ma trận VIE-11.
8. Chạy readiness CLI với tài khoản SELECT-only, expected SHA/DB được operator xác nhận độc lập; lưu stdout gốc. `READ_ONLY_PREFLIGHT_PASS` vẫn có `release_authorized=false`.

Mỗi mục ghi expected/actual, SHA, môi trường, vai trò, thời điểm có múi giờ, người vận hành, evidence đã khử PII, PASS/FAIL/BLOCKED/NOT_TESTED. Không đưa thông tin xác thực vào báo cáo.

## 7. Trình tự production chỉ sau GO

1. Xác minh lại candidate và backup ngay trước cửa sổ thay đổi. Dừng ghi nghiệp vụ theo kế hoạch để không xuất hiện request trộn phiên bản; ghi mốc dữ liệu phải giữ.
2. Recheck DB identity/ledger/hash; đưa migration 049/050 đã kiểm chứng vào vị trí operator staging riêng và chạy đúng thứ tự qua runner được duyệt. Đối chiếu hậu kiểm trước bước tiếp theo. MySQL/MariaDB DDL có thể đã commit khi lỗi, không giả định transaction sẽ hoàn tác ALTER.
3. Cài đồng bộ helper/bootstrap/API của candidate vào **API active `public_html/api`**. Kiểm tra PHP syntax, health và endpoint khi còn trong cửa sổ bảo trì.
4. Cài JS/CSS rồi HTML/service worker/version identity từ cùng artifact. Kiểm tra hash toàn bộ manifest thay đổi và OPcache theo cấu hình đã xác thực; không tạo endpoint web để chạy lệnh triển khai.
5. Smoke test được phê duyệt, xác nhận cache/PWA, quan sát lỗi; chỉ mở lại nghiệp vụ sau khi operator chấp nhận. Ghi deployment evidence và thời điểm kết thúc.

## 8. Hoàn tác và dữ liệu phát sinh

- Lưu bộ file trước triển khai ngoài webroot cùng manifest; backup PR #91 cũ không thay thế backup mới cho VIE-15.
- Nếu lỗi trước khi có nghiệp vụ xóa mềm, có thể khôi phục **cả bộ code** đã sao lưu theo kế hoạch; giữ nguyên cột nullable/index/FK, `version_no` và ledger 049/050 đã áp dụng. Không DROP cột, xóa ledger hoặc sửa checksum để lùi schema.
- Nếu đã có Lead bị xóa mềm, code trước VIE-15 có thể hiện lại những Lead đó vì không lọc `deleted_at`. Dừng ghi và dùng bản sửa tiến hoặc candidate rollback có hiểu schema xóa mềm đã được review; không tự đổi toàn bộ `deleted_at` về NULL.
- Restore toàn DB chỉ theo kế hoạch có phê duyệt riêng, sau khi đối chiếu mọi thay đổi kể từ backup và phương án bảo toàn chúng. Không ghi đè DB mới bằng backup cũ để chữa một lỗi giao diện.
- Giữ audit, quote snapshots và liên kết trip/lead/booking. Bảo quản artifact lỗi, log khử PII và ledger để điều tra.

**Quyết định hiện tại: chuẩn bị/review và kiểm thử; chưa deploy production.** Chỉ xin GO sau khi candidate và hồ sơ nghiệm thu cụ thể ở trên hoàn tất.
