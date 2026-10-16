"""Black-box wire contract for the actual stdio MCP executable (no network)."""
import json
import os
import subprocess
import sys
import unittest
from pathlib import Path

SERVER = Path(__file__).with_name("vta_mcp_server.py")

class StdioWireTests(unittest.TestCase):
    def test_stdio_initialize_and_read_only_tool_registry(self):
        env = {
            **os.environ,
            "VTA_API_URL": "https://isolated-staging.example.org/api/index.php",
            "VTA_TARGET_ENV": "staging",
            "VTA_AGENT_EMAIL": "mock-agent@example.org",
            "VTA_AGENT_PASSWORD": "dummy-test-only",
            "VTA_EXPECTED_COMPANY_ID": "12",
            "VTA_ENABLE_TASK_WRITES": "0",
        }
        requests = [
            {"jsonrpc": "2.0", "id": 1, "method": "initialize",
             "params": {"protocolVersion": "2025-03-26", "capabilities": {},
                        "clientInfo": {"name": "test", "version": "1.0"}}},
            {"jsonrpc": "2.0", "method": "notifications/initialized"},
            {"jsonrpc": "2.0", "id": 2, "method": "tools/list"},
            {"jsonrpc": "2.0", "id": 3, "method": "tools/call",
             "params": {"name": "vta_create_task", "arguments": {"title": "Don't write"}}},
        ]
        proc = subprocess.run(
            [sys.executable, str(SERVER)],
            input="\n".join(json.dumps(q) for q in requests) + "\n",
            text=True, capture_output=True, timeout=8, env=env, check=False)
        self.assertEqual(proc.returncode, 0, proc.stderr)
        rows = [json.loads(line) for line in proc.stdout.splitlines()]
        self.assertEqual([r["id"] for r in rows], [1, 2, 3])
        self.assertEqual(rows[0]["result"]["serverInfo"]["name"], "vta-rc62-operations")
        tools = rows[1]["result"]["tools"]
        names = {tool["name"] for tool in tools}
        self.assertIn("vta_operations_overview", names)
        self.assertIn("vta_list_master_tours", names)
        self.assertNotIn("vta_create_task", names)
        self.assertTrue(rows[2]["result"]["isError"])

if __name__ == "__main__":
    unittest.main()
