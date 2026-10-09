# VTA RC6 — Approved interactive demo UI v1
Branch: `codex/Vietnam/rc6-approved-demo-ui-v1` (review-only)

## Goal
Recreate the approved demo's three-option costing cards inside the **existing** production-grade Smart Cost screen, without substituting simulated frontend calculations for the authoritative VS2.1 costing API.

## Delivered
- Minimal navy/teal screen-only styling and VTA heading. Existing left sidebar, Marketing and AI Chat remain untouched.
- Guest Profile shows Paying PAX, FOC and Total Guests, plus server-managed Hotel Pax and Cruise Pax.
- Three top cards: Option A/B/C, with authoritative Total Cost and Cost/Paying Pax, independent Hotel/Cruise dropdowns, and rates/properties grouped by destination in each option.
- Each stay row saves property name and supplier-evidenced VND rate to the existing cost sheet API with revision checking; missing/invalid rates never silently become zero.
- Common services retain edit/add/remove/undo; the detailed 3-column accommodation matrix is available behind an optional advanced expander rather than crowding the main UI.
- Itinerary V5 retains its editing/storage and Template Gallery; screen-only visual treatment changes. The separate Word editor/export pipeline remains unchanged.
- Mobile cards and focus/keyboard styling are scoped to the new costing screen.
- CI wiring for preexisting JSDOM cost/itinerary tests and static integrity checks.

## Boundaries
Not deployed. No changes to backend prices, approval rules, supplier data, API endpoints, auth/RBAC, migrations, customer exports, Marketing or AI Chat. No local sample rates are injected into real quotations.

## Before merge/deploy
1. Green CI on exact PR commit (approved-ui and V5/Cost regression checks).
2. Manual browser snapshots at 1440px, 1024px, 390px; keyboard and touch input, no horizontal overflow.
3. Staging scenario: 6 paying + 1 FOC, Hanoi/Sapa independent hotels and Halong Cruise 3/4/5★; totals agree with API.
4. Edit hotel and cruise rates with supplier/evidence, test missing evidence and save errors, reopen quotation to confirm persistence.
5. Mix Hotel 3★ / Cruise 5★; confirm server response, review warning and Cost → Price → Proposal consistency.
6. Check read-only SENT and no cost access; verify V5/Word export and printed document remain unchanged.

### Known limitations
- Hotel class remains per OPTION across destinations; per-destination mixed-star hotels require a domain/schema change.
- This is a first integrated UI implementation, not a browser-verified staging build. Keep PR as **Draft** until VTA owner signs off.

## Private Tour — theo quy mô đoàn (increment #42)
- Bổ sung bảng nhóm khách Private 2, 4, 6, 8, 10, 12, 16 PAX dưới 3 thẻ Option A/B/C.
- Nhóm đã cấu hình trong VS2.3 Pricing Matrix được hiển thị đúng pax band/option đã lưu, lấy `cost_per_paying_pax_vnd` từ server.
- Không tự tạo các giả định chi phí xe, hướng dẫn, FOC, markup hoặc giá bán cho nhóm chưa lập kịch bản.
- Không gọi mutate API từ phần hiển thị; mở Price để cấu hình Review/Generate Matrix.
- Kiểm thử staging bắt buộc: band theo nhóm (ví dụ 2/4/6/8/10/12/16), mix Hotel/Cruise, dữ liệu thiếu, trạng thái review, thay PAX và Cost/Price đồng bộ.
