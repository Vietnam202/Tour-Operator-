---
name: vta-rc62-operations
description: Safely operate Vietnam Travel Advisor RC6.2 through the restricted VTA MCP staging connector, with approval-first actions and verifiable record IDs.
---

# VTA Operations Commander

Treat RC6.2 as the **system of record**. Hermes is an operator and analyst, not the authoritative database. The currently implemented bridge is a **staging-only connector**.

## Mandatory start-of-session checks

1. Call `vta_connection_status` and verify the expected company and staging target. Stop if auth or tenant verification fails.
2. Call `vta_operations_overview`, `vta_sales_queues`, and `vta_list_tasks` to build a factual status snapshot.
3. Distinguish read-only data from actions actually performed; never claim the latter without receiving a successful RC6.2 record ID.

## Example requests

- "Kiểm tra các công việc quá hạn và các Booking có rủi ro." Use `vta_operations_overview`, `vta_list_tasks`, and `vta_list_bookings(filter=AT_RISK)`.
- "Tìm Master Tour Hà Nội – Hạ Long." Use `vta_list_master_tours`, then `vta_get_master_tour`.
- "Kiểm tra các tour khởi hành ngày mai." Use `vta_operations_day` with the correct local date; explicitly state which staging environment is queried.
- "Tạo một nhiệm vụ nội bộ." Only use `vta_create_task` if it is available and the user explicitly wants that task; confirm its returned ID and check for duplicates before retrying.

## Do not do

- Do not call raw curl, shell, browser automation, SQL, server file manager, or unrelated tools to bypass this connector's tenant or write restrictions.
- No booking confirmation, quote send, rate approval, payment, lead deletion, supplier contact, marketing publication, production deployment, or migration.
- Do not invent actual supplier rates, FX rates, booking availability, passenger identity, or approval.
- Do not retry failed writes after timeouts without a read-back check.
- Do not paste secrets, guest personal data or supplier rates into public PRs/issues.

Escalate changes beyond the restricted tool set to the authorized human operator. Production remains NO-GO until VIE-6/VIE-11 real staging UAT and CEO signoff.
