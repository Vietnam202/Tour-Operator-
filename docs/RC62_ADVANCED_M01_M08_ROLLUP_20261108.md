# RC6.2 · Bản tổng hợp chuyên sâu M01–M08

Ngày kiểm kê: 08/11/2026. Nguồn nền RC6.2 là [Draft #108](https://github.com/Vietnam202/Tour-Operator-/pull/108) tại `d9a5d2619fce0f3dd5505dc9e61b73d2468029c0`. Bản mã hợp nhất M01–M08 là [Draft #133](https://github.com/Vietnam202/Tour-Operator-/pull/133) tại `dd6fe1d910f9e661740463ce6fbc7f1e4db3a239`. [So sánh hai SHA](https://github.com/Vietnam202/Tour-Operator-/compare/d9a5d2619fce0f3dd5505dc9e61b73d2468029c0...dd6fe1d910f9e661740463ce6fbc7f1e4db3a239) cho thấy 39 commit bổ sung, 0 commit thiếu và 31 tệp thay đổi. Cả hai mốc thuộc chuỗi Draft.

## Các phần đã tích hợp trong cùng cây Git

| Module | Draft và SHA của module | Phần tích hợp | CI đã ghi nhận tại SHA của module |
| --- | --- | --- | --- |
| M01, M03, M04 | [#127](https://github.com/Vietnam202/Tour-Operator-/pull/127) · `a462bb971065857589b8f246fae63dd63b3c476d` | Kiểm tra hành trình, dữ liệu giá nhà cung cấp và PriceMatrix | [5/5 thành công](https://github.com/Vietnam202/Tour-Operator-/actions/runs/42451476661) |
| M01, M03, M04 | [#128](https://github.com/Vietnam202/Tour-Operator-/pull/128) · `66c03b26a1e5ffd4221e55c4c3657f02b0daa848` | API nội bộ opt-in cho ba phần kiểm tra | [16/16 thành công](https://github.com/Vietnam202/Tour-Operator-/actions/runs/42452330582) |
| M05 | [#129](https://github.com/Vietnam202/Tour-Operator-/pull/129) · `a71e1961ce8218e84e3216e2dd2fb7a718441a92` | Đối chiếu xuất DOCX/PDF, bố cục và redaction trên dữ liệu mẫu | [5/5 thành công](https://github.com/Vietnam202/Tour-Operator-/actions/runs/42459108892) |
| M02 | [#130](https://github.com/Vietnam202/Tour-Operator-/pull/130) · `c16d6613076e21f7d9a5e6044db8a33e46ec2231` | Đối chiếu bản nháp hành trình AI với nguồn và revision | [6/6 thành công](https://github.com/Vietnam202/Tour-Operator-/actions/runs/42460120538) |
| M06 | [#131](https://github.com/Vietnam202/Tour-Operator-/pull/131) · `1d9aa43c5cd9fb8d01ebf783d4b2dc5d3c7602aa` | Kiểm tra hành trình lead, trùng lặp, phân quyền và khoảng trống consent | [5/5 thành công](https://github.com/Vietnam202/Tour-Operator-/actions/runs/42461117882) |
| M07 | [#132](https://github.com/Vietnam202/Tour-Operator-/pull/132) · `faa1d7e7ab04c48aa05c16aa78863cc183e024e6` | Kiểm tra B2B theo agency, NET và xuất tài liệu white-label | [7/7 thành công](https://github.com/Vietnam202/Tour-Operator-/actions/runs/42462752649) |
| M08 | [#133](https://github.com/Vietnam202/Tour-Operator-/pull/133) · `dd6fe1d910f9e661740463ce6fbc7f1e4db3a239` | Đối chiếu publication/withdraw, Ads, UTM và LeadHub | [6/6 thành công](https://github.com/Vietnam202/Tour-Operator-/actions/runs/42467385893) |

Chuỗi nguồn đi từ #108 → #127 → #128 → #129 → #130 → #131 → #132 → #133. Diff tích lũy có 26 tệp mới và 5 tệp gốc chỉ thêm dòng, với 0 dòng bị xóa. Năm tệp nhận thêm điểm nối là `api/index.php`, `api/lib/LeadHub.php`, `api/lib/PartnerHub.php`, `api/lib/B2CStudio.php` và `api/lib/AdsLandingPages.php`. Các chức năng nội bộ có cờ opt-in riêng mặc định OFF. Quick Cost trên nhánh làm việc riêng thuộc phạm vi theo dõi khác.

## Xác minh bản tổng hợp

PR tổng hợp dự kiến đặt trên đúng head của #133 và thêm bản kiểm kê này cùng workflow CI. Workflow kiểm tra cây mã giữ nguyên so với #133 rồi chạy các bộ kiểm thử module trên một SHA, gồm fixture SQLite, xuất DOCX/PDF với dữ liệu mẫu và luồng Ads/LeadHub trên MariaDB cô lập. Các workflow riêng của từng Draft vẫn phục vụ việc rà soát từng module.

Các lượt CI trong bảng thuộc các SHA riêng. Tại #133, [CI M08](https://github.com/Vietnam202/Tour-Operator-/actions/runs/42467385893) xác nhận phân quyền, tenant, OFF, revision, publish/withdraw, UTM và LeadHub NEW. Fixture MariaDB kiểm tra retry tuần tự và liên kết lead không trùng. Consent liên hệ bắt buộc ở đầu vào. Bằng chứng consent có phiên bản, first touch bền vững và tình huống publish/withdraw đồng thời vẫn còn thiếu. Query cũ của Ads options cần đối chiếu với schema thật trước UAT do đang tham chiếu `p.title,p.destination` trong khi migration 044 lưu nội dung vào `content_json`.

## Trạng thái release

- [Gói RC6.2 tại SHA #133](https://github.com/Vietnam202/Tour-Operator-/actions/runs/42467385721/artifacts/13611539199) là artifact CI. Runtime staging, đăng nhập theo vai trò, UAT và duyệt của owner vẫn cần bằng chứng riêng.
- [VIE-5](https://linear.app/vietnamtraveladvisor/issue/VIE-5) theo dõi SHA ứng viên và đối chiếu module. [VIE-6](https://linear.app/vietnamtraveladvisor/issue/VIE-6) theo dõi backup, khôi phục trên clone và ledger. [VIE-11](https://linear.app/vietnamtraveladvisor/issue/VIE-11) theo dõi xác nhận SHA đang chạy, rollback và quyền triển khai. [#99](https://github.com/Vietnam202/Tour-Operator-/issues/99) theo dõi nghiệm thu giao diện.
- Chuỗi [#109](https://github.com/Vietnam202/Tour-Operator-/issues/109) giữ Draft. Merge, upload staging, deploy và ghi production nằm ngoài lần cập nhật này.
