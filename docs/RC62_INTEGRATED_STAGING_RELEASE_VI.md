# Candidate bổ sung 11/10/2026

Nền mới: PR #61 tại `d203ed67db55af025d77a22275f2d6d4f84a6af3`. B2C/WordPress/Companion và checklist hiện hành: [RC62_B2C_COMPLETION_GATE_VI.md](RC62_B2C_COMPLETION_GATE_VI.md). SHA, package và CI của candidate bổ sung phải lấy từ HEAD PR mới. Các SHA/PR52 hoặc RC1 bên dưới là mốc lịch sử, không dùng làm bằng chứng cho candidate mới. Không đổi pin của deploy controller hoặc bypass gate staging.

# VTA RC6.2 — Bản tổng hợp chuẩn / Staging Release Candidate RC1

**Ngày:** 11/10/2026 · **Loại:** Mã PHP/MariaDB chạy thật, gói staging có checksum · **KHÔNG PHẢI bản cài lên production hoặc demo HTML.**

## 1. Quyết định tích hợp

Nhánh RC1 dựa trực tiếp vào **Smart Workflow P1 PR #51** (đã có 13/13 workflow CI PASS ở SHA `dcb15587a74385aae16f51fc4bb6e5bfae8d4119`), kế thừa chuỗi code của:

- **PR #42 → #44**: UI RC6.2 duyệt, 8 workspace, 6 tab Hồ sơ tour, Kho chương trình + Word canvas, import DOCX/PDF/batch/ZIP/Drive, Smart Cost có Hotel/Cruise độc lập và giá 3★/4★/5★.
- **PR #46 → #48**: B2B Common/Exclusive, agency ACL, NET Request, white-label và khóa phiên bản giá phát hành. **#48 supersede #46, không merge độc lập cả hai**.
- **PR #49**: mã VTA Connect WordPress chat/lead nằm trong repository **nhưng không đi trong gói runtime RC6**. Không cài WordPress vào RC6.2.
- **PR #51**: Smart Search, Smart Alerts, Quick Send đã gửi báo giá; cập nhật UI header và bảo vệ nội bộ.

**Không tự động gộp:** PR #43 ChatGPT Plus Companion, PR #47 chuyển Marketing sang WP và PR #50 B2C Luxury Design. Chúng là nhánh khác, cần nghiệm thu/rebase riêng. RC6.2 độc lập WordPress. Phần UI B2C Luxury trong HTML review **chưa được port toàn bộ thành các màn hình có DB thật**; bản RC1 không được quảng cáo là hoàn thành phần này.

## 2. Gói source chạy thật

CI mới `RC6.2 Integrated Runtime Staging Package` lấy **chính xác commit HEAD của PR**, không phải preview nhánh hay merge ref, rồi tạo artifact **vta-rc62-integrated-staging-candidate**. Bên trong gồm:

- `vta-rc62-integrated-staging-<SHA>.zip`: thư mục `runtime/` có frontend chính, PHP API, thư viện, CLI/migrations và assets. Không phải static GitHub Pages demo.
- `vta-rc62-manifest-<SHA>.json`: danh sách SHA-256 từng file, số migration và commit nguồn.
- `SHA256SUMS.txt`: checksum của ZIP và manifest.

Build script: `deploy/build-integrated-package.py`. Dùng `git ls-files`, so byte với Git blob, allowlist tương thích với staging controller và kiểm tra lại từng file trong ZIP. Không kèm `api/config.php`, `vta_private`, `storage`, `uploads`, `tests`, `docs`, `verification`, `integrations/wordpress` hoặc secret. Không cài database và không gọi provider.

**Hướng dẫn xem artifact:** GitHub → Pull request RC1 → Checks → `RC6.2 Integrated Runtime Staging Package` → chạy `sha-pinned-package` → tải artifact. Giữ manifest/checksum để kiểm tra ZIP trước khi đưa sang staging.

## 3. Quy trình triển khai thật nhưng an toàn

1. Tạo **máy chủ staging tách biệt** hoặc ít nhất docroot và MariaDB **clone riêng biệt**, HTTPS, PHP 8.3 với pdo_mysql/mbstring/dom/zip/gd/curl. **Không** đặt file của gói ZIP vào docroot của `v2quote` trước khi xác minh current hosting.
2. Operator lấy snapshot: `mysqldump --single-transaction` trên DB được cho phép, lưu encrypted archive ngoài webroot; sao lưu đủ `vta_private`, uploads, images, documents, file ownership, symlink state. Kiểm thử restore vào **DB clone**.
3. So sánh migration hiện có theo `schema_migrations` và `migration_checksums` với gói ứng viên. **037_b2b_partner_hub, 038_b2b_hybrid_distribution** và Marketing 037/038 có tên tệp/ledger riêng. Migration script của repo chỉ cho `staging/testing` và một số host, mặc định **không** cho production. Chỉ thực hiện rehearsal với cấu hình PRIVATE dành cho staging clone; không xóa/ghi đè bảng hiện có; dừng khi checksum hoặc dữ liệu không tương thích.
4. Mở `runtime/index.html` **qua webserver PHP** staging có `VTA_CONFIG_FILE` trỏ đến file riêng ngoài docroot, cấu hình allowed_origin HTTPS, database clone, secrets khác môi trường thật. Kiểm tra `api/index.php?route=health`, login/CSRF và quyền user.
5. **Không chạy controller fast-deploy hiện tại trên bản RC1**: `deploy/deploy-staging.sh` và `deploy/staging-release.php` hiện ghim cứng `codex/Vietnam/rc6.2-testing`, chủ sở hữu, docroot, hostname, database và chỉ chấp nhận migration khớp ledger. Đó là biện pháp bảo vệ: cần operator duyệt một kế hoạch deploy mới theo exact SHA; **không sửa/bypass controller âm thầm**.
6. UAT thật trên staging: 2 agency A/B, B2B Common/Exclusive copy/revoke/export/direct ID; internal Sales và read-only; Word itinerary save/reopen/undo, supplier evidence; mixed **6 paying + 1 FOC, Hotel 3★ + Cruise 5★**; Smart Cost → Price → Proposal → DOCX/PDF → Booking; lead/chat/Quick Send (link expire/revoke), PWA trên PC và điện thoại; không lộ NET/supplier cost.
7. Chỉ sau **CI exact SHA xanh + MariaDB clone migration/restore + UAT ký duyệt + rollback rehearsal + operator approval** mới có thể chuyển PR khỏi Draft và duyệt triển khai production theo change window. Không chạy `migrate.php` trên database production trong bước chuẩn bị.

### Read-only readiness preflight (thêm trong RC1)

Tệp CLI **được đóng gói trong runtime**: `api/bin/rc62-release-readiness.php`. Chỉ chạy sau khi **giải nén vào docroot staging riêng** và cấu hình **database clone riêng**; không dùng trên CyberPanel production.

- Đưa file manifest từ artifact ZIP vào thư mục PRIVATE ngoài webroot (ví dụ `/home/staging/vta_private/rc62-manifest.json`); giữ `SHA256SUMS.txt` bên ngoài public.
- Sử dụng `VTA_CONFIG_FILE` trỏ đến config private có `app.env=staging/testing`, host thuộc RuntimeGuard allowlist, `security.allowed_origin` trùng `app.base_url` và DB đặt tên rõ `_staging` / `_clone` / `_test`. Tên DB CyberPanel cũ `v2qu_v2qu_vtaos` **bị chặn** cho preflight RC1.
- Chạy từ CLI PHP 8.3 có các extension bắt buộc, tốt nhất dùng user database **SELECT-only**:

```bash
# Lấy SHA từ phê duyệt RC độc lập, KHÔNG sao chép từ manifest cần kiểm chứng.
APPROVED_SHA='157261336e6b999a33bded252ad92144caee05d2'
APPROVED_DB='vta_rc62_clone'  # operator đã đối chiếu với MariaDB clone riêng
VTA_CONFIG_FILE=/path/to/private/staging-config.php \
php runtime/api/bin/rc62-release-readiness.php \
  --manifest=/path/to/private/rc62-manifest.json \
  --expected-sha="$APPROVED_SHA" \
  --expected-db="$APPROVED_DB"
```

**Lưu ý cho bản hardening VIE-6:** cú pháp `--expected-sha` và `--expected-db` là thay đổi đề xuất trên nhánh review riêng, **chưa có trong artifact PR #52 đã đóng gói**. Chỉ sử dụng sau khi CI, reviewer và artifact mới được duyệt; không thay thế hoặc vá trực tiếp PHP trong gói RC1 đã khóa. SHA ví dụ ở trên là RC1 ngày 11/10/2026, phải cập nhật bằng SHA của artifact thực sự được chọn nếu tạo candidate khác. Lỗi preflight chỉ được in mã an toàn, không được ghi tên database, DSN, mật khẩu hoặc chi tiết private config.

- Script chỉ thực hiện kiểm tra SHA-256 tất cả file runtime, extensions, base URL/origin, DB identity, toàn bộ `schema_migrations` & `migration_checksums`; **không chạy DDL/DML, baseline, migration, đổi cấu hình hay backup**. Output chỉ có số lượng, commit và trạng thái không chứa secret. Mọi mismatch trả mã lỗi `RELEASE_BLOCKED` (exit 2).
- `READ_ONLY_PREFLIGHT_PASS` chỉ là một gate nguồn/ledger; **không đồng nghĩa staging UAT, backup restore hay production release PASS**.

- `--expected-db` phải là **tên clone MariaDB đã được operator đối chiếu độc lập** (không sao chép mù quáng từ private config). Preflight từ chối tên có marker production/live, tên DB lịch sử CyberPanel và kết nối thực tế `SELECT DATABASE()` không trùng cả config lẫn giá trị pin. Giữ account PDO quyền SELECT-only; không ghi DSN/mật khẩu vào GitHub.
- Nếu còn migration pending trên clone, readiness **fail-closed** với mã `MIGRATIONS_PENDING_ON_CLONE`; không tự động nâng cấp. Operator phải có phê duyệt riêng trước khi rehearsal forward-only trên clone đã khôi phục.
- **Hồ sơ VIE-6 phải có trước khi đóng issue:** (a) exact SHA và artifact checksum, (b) host/docroot staging tách biệt, (c) backup được mã hóa và checksum (không đính kèm dữ liệu thật), (d) restore thử vào database clone và row-count/schema kiểm đối chiếu, (e) migration ledger trước/sau + core-first rồi Marketing + B2B riêng, (f) read-only preflight exit code và log đã che dữ liệu riêng, (g) PHP/API real-auth/HTTPS, (h) rollback phương án code/database được operator ký. Không sửa production, không bypass controller `rc6.2-testing`.

- CI đã được mở rộng bằng unit guard test, MariaDB P12 full core+Marketing rehearsal, B2B 038 schema, unit group pricing và staging deploy safety fixture. Phải đối chiếu exact-head CI cuối cùng trên PR #52; artifact SHA cũ không dùng lại sau commit mới.

## 4. Những gì đã chứng minh / chưa chứng minh

| Hạng mục | Bằng chứng |
|---|---|
| Source tích hợp nghiệp vụ RC6.2 trong một nhánh | Có, kế thừa PR #51 và chuỗi PR #44/#48/#49 |
| CI hồi quy PR #51 | 13/13 PASS tại commit nguồn (trước khi thêm file release tooling) |
| Gói staging PHP/MariaDB có SHA + checksum | Chỉ coi là PASS khi workflow của chính PR RC1 chạy xong và xuất artifact |
| Migration 036–043 + B2B 037/038 trên DB clone của server | **CHƯA CÓ** |
| Đăng nhập thực tế, role A/B, supplier rates/FOC, DOCX/PDF, PWA trên staging CyberPanel | **CHƯA CÓ** |
| WordPress B2C Luxury thật / plugin cài trên WP | **CHƯA CÓ**; không thuộc release RC6 standalone |
| Upload CyberPanel/production, đổi DNS, migrate production, gửi thật qua provider | **KHÔNG THỰC HIỆN** |

**Release Gate:** Đóng băng source RC1 trên SHA đã kiểm thử, giữ Draft khi chưa đạt UAT, không merge/khởi chạy production từ giả định hoặc kết quả HTML demo.

## 5. Checklist operator ngắn nhất

- [ ] Mã ZIP, manifest, file SHA-256 trùng commit chính xác.
- [ ] Backup và restore clone thử thành công; migrations 036–043/B2B 037/038 kiểm tra checksum và thứ tự.
- [ ] Xác nhận host/docroot/DB/owner đúng **staging mới**; private config tách biệt.
- [ ] Admin/Sales/Read-only và Partner A/B nghiệm thu không lộ NET/Common/Exclusive.
- [ ] Kiểm nghiệm tour 6 paying + 1 FOC / Hotel 3★ & Cruise 5★; proposal PDF/DOCX, Booking.
- [ ] Đồng ý bằng văn bản đối với cutover, rollback và thay controller pin SHA; không phá các chặn an toàn hiện có.
