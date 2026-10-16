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

## Windows native installation — DPAPI launcher (recommended)

**Important fix (2026-10-16):** Hermes filters child-process environment variables; setting PowerShell variables and calling Hermes is insufficient unless those variables are explicitly forwarded in \`mcp_servers.*.env\`. Never put a plaintext agent password inside \`~/.hermes/config.yaml\`. The Windows launcher loads an encrypted user-local DPAPI credential on each start instead.

Prerequisites: Python 3.11+, a Windows-native Hermes executable, a **separate** authenticated HTTPS staging hostname, staging docroot and MariaDB database, and a dedicated RC6.2 staff account with the minimal permitted roles. Do not use production \`v2quote\` or \`quote\`. The environment variables in this document are *not* real secrets or URLs.

1. Download/clone this PR's \`integrations/hermes/\` directory, preserving the \`windows/\` subfolder.
2. Open **Windows PowerShell** under the **same Windows user** that runs Hermes. From \`integrations/hermes/windows\`, run the following with **real isolated staging** values (example host is a placeholder):

~~~powershell
.\Install-VTAHermes.ps1 -StagingApiUrl "https://YOUR-ISOLATED-STAGING-HOST/api/index.php" -AgentEmail "hermes-staging@YOUR-EMAIL" -CompanyId 12
~~~

3. Confirm the staging environment is isolated by typing \`ISOLATED-STAGING\` when prompted, then enter the staging staff password as a SecureString. The installer writes the encrypted Windows DPAPI blob only to \`%LOCALAPPDATA%\VTA\HermesRC62\agent-password.dpapi\`; the current Windows user is required to decrypt it. The installer **does not modify** RC6.2 or any database.
4. The installer copies the MCP server and adds a \`vta-rc62-operations\` skill to \`~/.hermes/skills/\`, without overwriting an existing skill of that name. It attempts \`hermes mcp add vta_rc62\`. If the command is unavailable, manually merge **only** the \`vta_rc62\` server block from \`%LOCALAPPDATA%\VTA\HermesRC62\hermes-config-snippet.yaml\` into existing \`~/.hermes/config.yaml\`; never replace the entire config. Keep \`tools.include\` explicitly read-only.
5. Restart Hermes or run \`/reload-mcp\`, then:

~~~powershell
hermes mcp test vta_rc62
~~~

6. Ask Hermes to execute \`vta_connection_status\` and \`vta_operations_overview\`, verify the expected company and the **actual staging records**.

The Windows launcher defaults to **read-only** and explicitly refuses \`enable_task_writes != 0\`. To enable any operation that writes records, a separate reviewed release and durable idempotency contract are required. Do not bypass the launcher or edit the DPAPI file.

### No staging environment yet?

The GitHub CI and local mock tests are available without staging. They do not establish connection to your local Hermes or live RC6.2. Do not point the bridge at production to make the tool respond. Prepare a completely separate staging API, DB and staff account first; then run this installer.

### Verifying code without network

~~~powershell
python -m unittest discover -s integrations/hermes -p "test_*.py" -v
~~~

The tests also exercise an actual stdio subprocess handshake (\`initialize\`, \`tools/list\` and disabled-write refusal), but intentionally do not log in to any real RC6.2 server.

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
