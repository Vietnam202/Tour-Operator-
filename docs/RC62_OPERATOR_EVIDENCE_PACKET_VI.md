# RC6.2 — Gói bằng chứng nghiệm thu staging (VIE-11)

**Trạng thái: NO-GO, mẫu chưa có dữ liệu nghiệm thu thật.** Đây là bước kiểm tra tính đầy đủ và thống nhất của hồ sơ. Không phải chữ ký số, xác nhận danh tính, quyền triển khai hay bằng chứng hệ thống đang chạy trên CyberPanel.

## 1. Bản phát hành phải độc lập, đúng một commit

Sử dụng đúng ba file được GitHub Actions `sha-pinned-package` xuất ở **cùng một run/commit**:

- `vta-rc62-integrated-staging-<12_SHA>.zip`
- `vta-rc62-manifest-<12_SHA>.json`
- `SHA256SUMS.txt`

Operator phải tự xác nhận chuỗi `APPROVED_SHA` 40 ký tự dựa trên quyết định xem xét PR, **không tự lấy từ manifest để coi là đã được phê duyệt**. Script kiểm tra từng thành phần ZIP so với manifest, checksum độc lập của ZIP/manifest và nguồn SHA. Một lỗi thì dừng; script không giải nén ra docroot hoặc chạy SQL.

## 2. Mẫu hồ sơ operator-only

Tạo `operator-evidence.json` **ngoài GitHub, ngoài public_html, quyền chỉ đọc cho người nghiệm thu**, không chứa mật khẩu/SQL dump, dữ liệu khách hàng, tên cá nhân khách hàng, email, guest list, giá supplier hoặc access token. Các dấu tham chiếu `private://...` chỉ là **mã tham chiếu nội bộ đã khử định danh**, không phải đường dẫn tệp hoặc URL công khai. Khi kết thúc, operator phải xác minh rằng tài liệu/thao tác được tham chiếu thực sự tồn tại, có nội dung đúng, và được bảo quản theo chính sách bảo mật của công ty.

Cấu trúc JSON yêu cầu:

| Khối | Trường bắt buộc |
|---|---|
| Metadata | `schema="vta.rc62.operator-evidence.v1"`, `source_kind="OPERATOR_COLLECTED_NOT_CI"`, `release_authorized=false`, `candidate_sha`, `artifact_zip_sha256`, `manifest_sha256` |
| `staging` | `url` HTTPS hostname riêng chứa `stag`, `docroot_isolated=true`, `tls_verified=true`, `private_config_outside_webroot=true`, `php_83_extensions_checked=true`, `clone_db`, `evidence_ref` |
| `recovery` | `encrypted_backup_sha256`, `source_and_clone_isolated=true`, `restore_verified=true`, `rollback_rehearsed=true`, `migration_ledgers_equal=true`, `read_only_preflight="READ_ONLY_PREFLIGHT_PASS"`, `preflight_release_authorized=false`, `clone_db`, `evidence_ref`, **`preflight_ref`** trỏ tới JSON stdout thực tế của `api/bin/rc62-release-readiness.php`, không phải dòng chữ tự nhập |
| `roles` | Đúng 7 khóa: `Admin`, `Sales`, `Operations`, `Finance`, `Read-only`, `Agency A`, `Agency B` |
| `scenarios` | Đúng 8 khóa `E2E-01` tới `E2E-08` (đối chiếu `docs/RC62_E2E_WORKFLOW_ACCEPTANCE.md`) |
| `cost` | `paying_pax=6`, `foc_pax=1`, `hotel_stars=3`, `cruise_stars=5`, `supplier_availability_reviewed=true`, `net_price_source_approved=true`, `evidence_ref` |
| `real_documents` | Đúng **50 mục** DOCX/PDF thật, hash nguồn SHA-256 duy nhất, có ít nhất 1 DOCX và 1 PDF; từng mục phải xác nhận `saved_reopened`, `images_tables_reviewed`, `docx_export_checked`, `pdf_export_checked`, `source_kind="REAL_OPERATOR_SUPPLIED"`, `evidence_ref`, `source_ref` (tệp DOCX/PDF gốc, hash phải đúng `source_sha256`). Không ghi tên file chứa PII |
| `evidence_index` | Bảng ánh xạ từng `private://...` tham chiếu (kể cả `source_ref` của 50 tài liệu và **`preflight_ref`**) tới `{ "path": "refs/ma-so.bin", "sha256": "<64_hex>" }`. `path` tương đối với `--evidence-root`, không chứa đường dẫn tuyệt đối, `..` hoặc symlink. Mỗi tham chiếu cần 1 tệp chứng cứ riêng; không được bỏ sót hoặc thêm bản ghi thừa |
| `operator_review` | `result="READY_FOR_INDEPENDENT_RELEASE_REVIEW"`, `candidate_sha`, `change_window_reviewed=true`, `rollback_responsible_person_named=true`, `evidence_ref`. **Không có trường GO_PRODUCTION** |

Mỗi mục `roles` và `scenarios` yêu cầu **giá trị** `status="PASS_STAGING_REAL_AUTH"`, `candidate_sha`, `origin="OPERATOR_HTTPS_STAGING"`, `evidence_ref="private://..."`, `reviewer`, và `observed_at` chuẩn ISO-8601 có múi giờ (ví dụ định dạng `YYYY-MM-DDTHH:MM:SS+07:00`).

Một ví dụ **một mục**; không copy thành nghiệm thu giả:

```json
{
  "status": "NOT_TESTED",
  "candidate_sha": "<40_SHA>",
  "origin": "OPERATOR_HTTPS_STAGING",
  "evidence_ref": "private://redacted-evidence-placeholder",
  "reviewer": "PENDING_OPERATOR",
  "observed_at": "2026-10-12T16:00:00+07:00"
}
```

Để được kiểm tra đầy đủ, tất cả mục bắt buộc phải được **operator thực sự thực hiện** và đổi `NOT_TESTED` thành `PASS_STAGING_REAL_AUTH` kèm bản ghi gốc; không dùng dữ liệu CI giả để điền.

## 3. Kiểm tra cục bộ (không ghi dữ liệu)

```bash
python3 deploy/check-rc62-evidence.py \
  --expected-sha="$APPROVED_SHA" \
  --zip="/private/release/vta-rc62-integrated-staging-<SHA>.zip" \
  --manifest="/private/release/vta-rc62-manifest-<SHA>.json" \
  --sha256sums="/private/release/SHA256SUMS.txt" \
  --evidence="/private/release/operator-evidence.json" \
  --evidence-root="/private/release/evidence-files" \
  --signature="/private/release/operator-evidence.sig" \
  --trusted-public-key="/private/independent-review/trusted-approver.pem" \
  --trusted-public-key-sha256="$APPROVED_PUBLIC_KEY_SHA256"
```

- Exit **2**, `STAGING_EVIDENCE_BLOCKED`: thiếu hoặc sai bằng chứng/SHA/ZIP/chữ ký/khóa hoặc chưa nghiệm thu; **dừng**.
- Exit **0**, `EVIDENCE_PACKET_STRUCTURALLY_COMPLETE`: hồ sơ đúng cấu trúc và byte ZIP trùng checksum đã cung cấp. **Vẫn `release_authorized=false`**; công cụ không thể chứng minh ảnh/log là thật hoặc người ký có thẩm quyền.
- Tuyệt đối không dùng kết quả này làm điều kiện tự động upload, merge, migrate, đổi nhánh controller hoặc GO production.

**Điều kiện GO độc lập:** Người phụ trách xác thực nguồn bằng chứng, độ tin cậy backup/restore thực tế, UAT nguyên bản, chữ ký/ủy quyền và kế hoạch rollback, rồi quyết định bằng quy trình thay đổi production riêng. Giữ nguyên `RuntimeGuard` và pin `codex/Vietnam/rc6.2-testing` cho đến khi có phê duyệt thay đổi đúng quy trình. Namecheap full-server restore có thể ghi đè toàn bộ website/database/email: **không dùng làm clone phục hồi không mất dữ liệu**.


### Chữ ký số độc lập cho hồ sơ nghiệm thu (bước 2K)

Bộ kiểm tra offline bắt buộc chữ ký tách rời `operator-evidence.sig` do người phụ trách được ủy quyền ký **sau khi hoàn thành JSON**, và khóa công khai `trusted-approver.pem` cùng SHA-256 được **người kiểm tra thứ hai xác nhận độc lập** (không sao chép dấu vân tay một cách mù quáng từ JSON, ZIP, hoặc chính khóa vừa tải). Cặp khóa riêng tư/công khai phải được tạo và quản lý bởi người phụ trách bảo mật theo chính sách công ty; không tải private key lên GitHub, Linear, webroot, artifact hoặc gửi trong chat.

Cách ký trên thiết bị được ủy quyền có OpenSSL, đối với **khóa ký đã được cấp phát an toàn**:

```bash
openssl dgst -sha256 -sign /private/keys/approver-private.pem \
  -out /private/release/operator-evidence.sig \
  /private/release/operator-evidence.json
```

Đội kiểm tra độc lập lấy `APPROVED_PUBLIC_KEY_SHA256` bằng kênh đáng tin cậy, ghim cùng `APPROVED_SHA` của candidate, rồi sử dụng lệnh kiểm tra ở phần 3. Nếu không có khóa công khai đã xác minh hoặc chữ ký hợp lệ, checker phải trả `STAGING_EVIDENCE_BLOCKED`. Mọi thay đổi dù chỉ một byte trong JSON sau khi ký đều cần được người có thẩm quyền xem xét và ký lại. **Không chấp nhận việc một người tự phát sinh khóa và tự kê khai nó được ủy quyền**.

**Giới hạn:** chữ ký hợp lệ chứng minh khóa riêng tương ứng đã ký bytes của JSON; không chứng minh hóa đơn/ảnh/log hoặc tên người tham gia là thật. Các hồ sơ DOCX/PDF nguồn vẫn phải được kiểm tra thực tế, staging phải được bảo vệ, và phê duyệt triển khai production **là quyết định riêng**. Công cụ luôn trả `release_authorized=false`.

### Ràng buộc kết quả preflight thực tế

Bộ hồ sơ từ bước 2J phải lưu **JSON stdout thực tế** từ `api/bin/rc62-release-readiness.php` vào thư mục bằng chứng riêng của operator và khai báo `recovery.preflight_ref` trong `evidence_index`. Không chấp nhận một dòng ký tay `READ_ONLY_PREFLIGHT_PASS` thay cho tệp.

Công cụ kiểm tra tập tin này có nội dung `ok=true`, `status=READ_ONLY_PREFLIGHT_PASS`, `source_commit` bằng commit được ghim độc lập, `runtime_files` và `migrations_checked` bằng **manifest runtime đã xác minh**, `release_authorized=false`, và chính xác ba yêu cầu `restore_rehearsal`, `multirole_staging_uat`, `operator_approval`. Tệp JSON phải có checksum riêng; sửa số migration hoặc giả kết quả cho SHA khác sẽ bị từ chối kể cả khi đã cập nhật checksum trong chỉ mục. ZIP cũng bị chặn nếu kích thước vượt ngưỡng an toàn hoặc tỷ lệ nén bất thường.

**Giới hạn bằng chứng:** SHA/JSON đúng chỉ xác nhận tính nhất quán giữa tệp và báo cáo, không chứng minh preflight đã được chạy trên database clone thật. Người nghiệm thu độc lập phải đối chiếu dấu vết phiên chạy, người vận hành, account SELECT-only, thời điểm, thông tin DB, và backup/rollback trước khi ký duyệt.

### Kiểm tra bytes chứng cứ riêng tư

Kể từ bước 2I, điền `private://...` **không đủ**. Mỗi mã chứng cứ phải có bản ghi trong `evidence_index` và tệp thực tế bên trong `--evidence-root` (thư mục riêng ngoài source repo và webroot). Công cụ đọc chunk, so sánh SHA-256 với chỉ mục, kiểm tra đường dẫn không vượt thư mục, không qua symbolic link, không dùng trùng tệp, giới hạn 50 MiB mỗi tệp. Đối với 50 `source_ref`, công cụ kiểm tra bytes gốc PDF bắt đầu bằng `%PDF-` và DOCX là ZIP có `word/document.xml` cùng `[Content_Types].xml`, CRC ZIP nguyên vẹn và hash trùng `source_sha256`. **Không** kết luận chất lượng ảnh/layout từ magic/header; người kiểm duyệt vẫn phải trực tiếp mở và đối chiếu xuất Word/PDF.

Ví dụ **một mục trong `evidence_index`** (tạo từ bằng chứng đã mã hoá/khử định danh tại máy operator, không đẩy lên GitHub):

```json
{
  "private://redacted-evidence-placeholder": {
    "path": "refs/review-0001.pdf",
    "sha256": "<sha256-of-actual-file>"
  }
}
```

Trong mỗi mục tài liệu `real_documents`, `source_ref` phải trỏ tới **một tệp DOCX/PDF thực tế khác với biên bản review**; `source_sha256` phải bằng SHA-256 của chính tệp này. Tài liệu gốc có thể chứa thông tin nhạy cảm: lưu trong kho operator có quyền truy cập riêng, không sao chép vào repo, artifact, Linear hoặc thư mục `public_html`. Công cụ không in tên file, nội dung hay đường dẫn tuyệt đối khi gặp lỗi. File đã tồn tại và khớp SHA vẫn **không chứng minh đây là hồ sơ khách hàng thật, người review đúng thẩm quyền, supplier đã duyệt hoặc hệ thống staging đã chạy**; các xác minh này bắt buộc phải do operator độc lập thực hiện.

## 4. Phạm vi kiểm thử tự động

`tests/rc62-operator-evidence-unit.py` tạo **dữ liệu giả hoàn toàn** để xác minh công cụ từ chối SHA sai, ZIP/manifest bị sửa, nhầm CI thành staging, thiếu role/FOC/tài liệu, trùng hash và thiếu rollback. Một fixture JSON đầy đủ về hình thức **không đồng nghĩa có bằng chứng thật**. GitHub Actions chỉ đánh dấu `PASS_CI_FIXTURE`; tất cả mục staging trong hồ sơ chính thức mặc định chưa được duyệt.
