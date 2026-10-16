# Hermes Agent ↔ RC6.2 — Operations MCP Bridge (staging candidate)

**Status:** source integration candidate only. No VPS deployment, no production credentials, no production DB changes, and no live Hermes connection have been performed.

## Scope

Hermes connects to RC6.2 through the **existing PHP HTTP API** using a dedicated staff account and the application's existing session cookie + CSRF + RBAC checks. No new PHP endpoint, migration, bypass, direct SQL access, or generic arbitrary HTTP tool is introduced.

Verified target branch: `feat/rc62-inquiry-soft-delete-20261014` at SHA `3f0d86f19986229676479c2597c0f99920faa5ad` (PR #92). This feature branch does **not** include the separate live UI fixes from PR #91. Reconciliation is required before release.

Available MCP tools:

| Tool | RC6.2 endpoint | Operations |
| --- | --- | --- |
| `vta_connection_status` | `auth/login` | Confirm agent identity and company |
| `vta_operations_overview` | `workspace/home` | Read counts/alerts |
| `vta_sales_queues` | `workspace/sales` | Read sales metrics |
| `vta_list_inquiries` | `inquiries` | Read selected non-contact fields |
| `vta_list_master_tours` | `tour-library` | Search program library |
| `vta_get_master_tour` | `tour-library/{id}` | Read selected itinerary fields |
| `vta_list_bookings` | `bookings` | Read booking status without guest identity |
| `vta_booking_readiness` | `bookings/{id}/readiness` | Read approved operational indicators |
| `vta_operations_day` | `v3/operations` | Read movement and issue summaries |
| `vta_list_tasks` | `tasks` | Read tasks |
| `vta_create_task` | `tasks` | Staging-only manual task; **disabled by default** |

This candidate **does not** implement new autonomous quote creation, price calculation, supplier communications, booking confirmation, marketing publication, approval tokens, or DomainOutbox event delivery. These require scoped business contracts, idempotency and release approval rather than exposing arbitrary writes.

## Local configuration on Windows (isolated staging only)

Use Python 3.11+ (standard library only), with the server file in a fixed directory such as `C:\\VTA\\hermes-rc62\\vta_mcp_server.py`.

1. Create a **dedicated limited-permission RC6.2 staging staff account**, with only the necessary read permissions. Confirm the account belongs to the intended company and that the staging host is independently isolated from production.
2. Set the following environment variables **in the same PowerShell window that launches Hermes**, never in a tracked repo/config file:

```powershell
$env:VTA_API_URL = "https://YOUR-ISOLATED-STAGING-HOST/api/index.php"
$env:VTA_TARGET_ENV = "staging"
$env:VTA_AGENT_EMAIL = "agent-staging@your-company.example"
$env:VTA_AGENT_PASSWORD = Read-Host "Enter staging agent password"
$env:VTA_EXPECTED_COMPANY_ID = "YOUR_NUMERIC_COMPANY_ID"
$env:VTA_ENABLE_TASK_WRITES = "0"
```

The hostnames `v2quote.vietnamtraveladvisor.com.vn` and `quote.vietnamtraveladvisor.com.vn` are blocked in this candidate. Merely naming a production mirror "staging" does not provide isolation: verify the docroot, backend DB and account separation before enabling any writes.

3. Add to your local Hermes user `config.yaml`:

```yaml
mcp_servers:
  vta_rc62:
    command: "python"
    args: ["C:/VTA/hermes-rc62/vta_mcp_server.py"]
```

Run `hermes chat` from the same shell; ask **"Use vta_connection_status to confirm the authorized staging company"**. Hermes documentation: https://hermes-agent.nousresearch.com/docs/user-guide/features/mcp

4. Run contract tests without network:

```powershell
python -m unittest discover -s integrations/hermes -p "test_*.py" -v
```

## Optional low-risk task writing on isolated staging

After real-role UAT and authorized local staging setup, set `VTA_ENABLE_TASK_WRITES=1`, restart Hermes, and ask Hermes to create a **manual** task. The bridge cannot create linked quote/booking/inquiry tasks, send messages, modify rates, archive inquiries, confirm bookings or write to production.

Note: Existing `POST tasks` lacks a bridge-specific persisted idempotency receipt. **Do not retry task submissions after network timeouts without checking the task list first.** This is a staging limitation; a server-side idempotent command contract and durable audit evidence must be added before broader automated writes.

## Security and acceptance boundaries

- HTTPS only; redirects refused; no insecure TLS bypass.
- Dedicated staff identity; compare the login's company ID against `VTA_EXPECTED_COMPANY_ID`; existing app RBAC/CSRF remains authoritative.
- No credential logging or returned credentials. Customer personal data and financial fields are excluded from common read tools through explicit field selection.
- Staging is mandatory, and known production hosts are blocked.
- Real data can still appear in free-text tour descriptions and task/incident titles; grant access only to trusted operators and apply a separate data-classification review before live use.
- Permissions, CSRF and same-tenant checks depend on existing RC6.2 PHP APIs; mock tests do not prove real-role staging success.
- PRs #90 and #92 remain Draft; VIE-6 / VIE-11 and owner production signoff remain NO-GO.
- Before deployment: reconcile PR #91 runtime changes, verify host/docroot/DB/migration ledger, test real accounts on independent staging, review data exposure, add durable idempotency for writes, and obtain CEO approval.

## First staging smoke test

1. Read `vta_connection_status`: exact expected company and permitted role.
2. Read `vta_operations_overview` and `vta_list_tasks`; confirm no PII and accurate totals.
3. Read a selected `vta_get_master_tour` and `vta_booking_readiness`.
4. Only if separately enabled: create one harmless manual task; verify its ID in RC6.2 and its tenant ownership. Never treat a mocked return value as a real created task.
5. Save SHA, environment, staff role, sanitized HTTP evidence, expected/actual and PASS/FAIL. Do not post credentials, customer names, or supplier rates to GitHub.

The bridge is a starting control plane for Hermes. It is **not** a completed live autonomous operations deployment.
