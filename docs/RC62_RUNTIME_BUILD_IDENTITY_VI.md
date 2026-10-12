# RC6.2 bước 2P — Xác minh phiên bản PHP đang chạy trên staging

**Mục đích:** tránh tình huống ZIP/CI đạt nhưng operator truy cập vào phiên bản PHP cũ hoặc nhầm docroot. Không phải phương tiện tự động cho phép upload/merge/migration.

## Dấu định danh được tạo ngay lúc đóng gói

`deploy/build-integrated-package.py` tạo `runtime/api/runtime-build-identity.json` **chỉ bên trong ZIP**, không lưu như một file được theo dõi trong Git. Tệp gồm `schema=vta.rc62.runtime.identity.v1`, Git `source_commit`, `source_file_count`, và `source_inventory_sha256`. Giá trị cuối là SHA-256 của JSON chuẩn hóa từ cặp {đường-dẫn-nguồn:SHA256-bytes} trong manifest, **loại trừ chính tệp generated identity** để tránh vòng phụ thuộc. Tệp được băm SHA-256 và đăng ký như một runtime member trong manifest; không chứa mật khẩu, DSN, domain production, token hoặc thông tin khách.

Builder sẽ từ chối tệp identity có nguồn không khớp, số file lệch, nội dung ZIP bị thay đổi hoặc tham chiếu thiếu. `api/index.php?route=health` đọc và kiểm tra cấu trúc identity qua `RuntimeBuildIdentity::read()`, trả:

```json
{
  "ok": true,
  "service": "VTA API",
  "version": "3.4.0-RC6.2",
  "build": {
    "status": "PINNED_PACKAGE",
    "source_commit": "<40_HEX>",
    "source_inventory_sha256": "<64_HEX>",
    "source_file_count": 251
  }
}
```

`source_file_count` trong ví dụ chỉ để minh họa; xác định con số thật từ gói đã build. Nếu chạy từ checkout chưa đóng gói, `build.status=UNPACKAGED_SOURCE`; **không được coi là staging build đã xác minh**.

## Operator lấy nguồn xác minh độc lập (không lấy từ HTTP)

Sau khi xác minh **ZIP/manifest/SHA256SUMS đúng commit đã được phê duyệt** bằng `deploy/check-rc62-evidence.py` và quy trình VIE-11, operator lấy giá trị sau từ ZIP đã chứng thực:

```bash
python3 - "$APPROVED_ZIP" <<'PY'
import json,sys,zipfile
with zipfile.ZipFile(sys.argv[1]) as archive:
    identity=json.loads(archive.read('runtime/api/runtime-build-identity.json'))
print('Commit:',identity['source_commit'])
print('Approved source inventory:',identity['source_inventory_sha256'])
PY
```

Đặt `VTA_STAGE_APPROVED_SHA` và `VTA_STAGE_APPROVED_SOURCE_INVENTORY_SHA256` từ **nguồn artifact đã xác minh**. Chạy `node tests/rc62-staging-readonly-smoke.cjs` với tất cả pin/approval ở hướng dẫn [RC62_STAGING_HTTP_OPERATOR_SMOKE_VI.md](RC62_STAGING_HTTP_OPERATOR_SMOKE_VI.md). Script kiểm tra API `health` phải trả 200, `PINNED_PACKAGE`, commit chính xác và inventory hash trùng cả hai giá trị. HTTP 200 nhưng dấu định danh thiếu, stale hay khác sẽ thất bại với `BLOCK_STAGING_BUILD_IDENTITY_MISMATCH`.

## Giới hạn bằng chứng

Dấu định danh HTTP tự công bố có thể bị giả bởi server đã bị kiểm soát. Nó **không phải chữ ký số và không tự chứng minh file PHP hiện tại không bị chỉnh sửa sau khi giải nén**. Phải đối chiếu docroot thực tế, checksum tại máy chủ, bản ghi người upload, exact Git SHA của ZIP, migration ledger, kết quả backup/rollback, chứng thư SSL và hai-agency UAT bằng người kiểm tra độc lập. Không trả nguồn cấu hình riêng qua API.

Không cần upload runtime để kiểm thử unit; CI xác minh cấu trúc PHP, các tệp nguồn trong ZIP và tình huống thay đổi SHA/không có identity bằng dữ liệu tổng hợp. **Không thay thế nghiệm thu CyberPanel thực tế.** `release_authorized=false` giữ nguyên, PR Draft và NO-GO production.
