import json
import unittest
from urllib.parse import parse_qs, urlsplit
from vta_mcp_server import BridgeError, RC62, Server

SETTINGS = {
    "VTA_API_URL": "https://isolated-stage.example.com/api/index.php",
    "VTA_TARGET_ENV": "staging",
    "VTA_AGENT_EMAIL": "agent@example.com",
    "VTA_AGENT_PASSWORD": "test-only",
    "VTA_EXPECTED_COMPANY_ID": "12",
    "VTA_ENABLE_TASK_WRITES": "0",
}

class Response:
    def __init__(self, data):
        self.data = json.dumps(data).encode()
    def __enter__(self):
        return self
    def __exit__(self, *args):
        return None
    def read(self, amount):
        return self.data[:amount]

class Opener:
    def __init__(self, company=12, permissions=None):
        self.company = company
        self.permissions = permissions if permissions is not None else [
            "task.view", "sales.view", "booking.view", "operations.view"]
        self.calls = []
    def open(self, request, timeout):
        route = parse_qs(urlsplit(request.full_url).query)["route"][0]
        headers = dict(request.header_items())
        body = json.loads(request.data) if request.data else None
        self.calls.append((request.get_method(), route, body, headers))
        if route == "auth/login":
            return Response({"ok": True, "user": {
                "id": 7, "company_id": self.company,
                "csrf": "z" * 40, "status": "ACTIVE", "permissions": self.permissions}})
        if route == "workspace/home":
            return Response({"ok": True, "metrics": {"new_leads": 3}, "access": {},
                "attention": {"sales": {"total": 2, "items": [
                    {"lead_contact_name": "PRIVATE_PERSON"}]}}})
        if route == "workspace/sales":
            return Response({"ok": True, "metrics": {"new_leads": 3}, "definitions": {}})
        if route == "inquiries":
            return Response({"ok": True, "items": [{"id": 5, "status": "NEW",
                "title": "Hanoi", "lead_whatsapp": "PRIVATE_PHONE"}]})
        if route == "bookings":
            return Response({"ok": True, "items": [{"id": 2, "booking_ref": "BK-2",
                "lead_guest_name": "PRIVATE_PERSON"}]})
        if route == "tour-library":
            return Response({"ok": True, "total": 1, "items": [{"id": 4, "title": "Tour A"}]})
        if route == "tour-library/4":
            return Response({"ok": True, "program": {"id": 4, "title": "Tour A",
                "days": [{"day": 1, "description": "Hanoi"}], "source_url": "PRIVATE_LINK"}})
        if route == "v3/operations":
            return Response({"ok": True, "movement": [{"id": 3,
                "lead_guest_name": "PRIVATE_PERSON"}], "issues": [], "readiness": []})
        if route == "tasks" and request.get_method() == "POST":
            return Response({"ok": True, "id": 55})
        if route == "tasks":
            return Response({"ok": True, "items": []})
        if route == "bookings/2/readiness":
            return Response({"ok": True, "ready": False, "blockers": [],
                "guest_name": "PRIVATE_PERSON"})
        return Response({"ok": False})

class BridgeTest(unittest.TestCase):
    def test_refuses_non_tls_prod_and_unapproved_environment(self):
        for change in [
            {"VTA_API_URL": "http://isolated-stage.example.com/api/index.php"},
            {"VTA_API_URL": "https://v2quote.vietnamtraveladvisor.com.vn/api/index.php"},
            {"VTA_TARGET_ENV": "production"},
        ]:
            with self.subTest(change=change), self.assertRaises(BridgeError):
                RC62({**SETTINGS, **change}, Opener())

    def test_cross_tenant_is_denied(self):
        opener = Opener(company=88)
        client = RC62(SETTINGS, opener)
        with self.assertRaises(BridgeError):
            client.execute("vta_connection_status", {})
        self.assertEqual([call[1] for call in opener.calls], ["auth/login"])

    def test_sensitive_fields_are_not_shared(self):
        opener = Opener()
        client = RC62(SETTINGS, opener)
        output = [
            client.execute("vta_operations_overview", {}),
            client.execute("vta_list_inquiries", {}),
            client.execute("vta_list_bookings", {}),
            client.execute("vta_list_master_tours", {"limit": 2}),
            client.execute("vta_get_master_tour", {"id": 4}),
            client.execute("vta_operations_day", {"date": "2026-10-16"}),
            client.execute("vta_booking_readiness", {"booking_id": 2}),
        ]
        serialized = json.dumps(output)
        self.assertNotIn("PRIVATE_", serialized)
        self.assertEqual(output[3]["total"], 1)
        self.assertTrue(all(call[0] == "GET" for call in opener.calls[1:]))

    def test_write_off_by_default_and_no_generic_http(self):
        opener = Opener()
        client = RC62(SETTINGS, opener)
        tools = [t["name"] for t in Server(client).tools()]
        self.assertNotIn("vta_create_task", tools)
        self.assertNotIn("http_request", tools)
        with self.assertRaises(BridgeError):
            client.execute("vta_create_task", {"title": "Check supplier"})
        self.assertEqual(opener.calls, [])

    def test_staging_write_requires_role_and_csrf(self):
        opener = Opener()
        client = RC62({**SETTINGS, "VTA_ENABLE_TASK_WRITES": "1"}, opener)
        result = client.execute("vta_create_task", {
            "title": "Check transfer booking", "priority": "HIGH"})
        self.assertEqual(result["id"], 55)
        self.assertEqual(opener.calls[-1][1], "tasks")
        self.assertEqual(opener.calls[-1][3]["X-vta-csrf"], "z" * 40)
        self.assertEqual(opener.calls[-1][2]["entity_type"], "manual")
        denied = RC62({**SETTINGS, "VTA_ENABLE_TASK_WRITES": "1"},
                       Opener(permissions=["sales.view"]))
        with self.assertRaises(BridgeError):
            denied.execute("vta_create_task", {"title": "Check transfer booking"})

    def test_invalid_record_id_and_dates_no_http_request(self):
        opener = Opener()
        client = RC62(SETTINGS, opener)
        with self.assertRaises(BridgeError):
            client.execute("vta_get_master_tour", {"id": -7})
        with self.assertRaises(BridgeError):
            client.execute("vta_operations_day", {"date": "2026-99-99"})
        self.assertEqual(opener.calls, [])

    def test_jsonrpc_initialization_and_validation(self):
        server = Server(RC62(SETTINGS, Opener()))
        init = server.dispatch({"jsonrpc": "2.0", "id": 1, "method": "initialize",
            "params": {"protocolVersion": "2025-03-26"}})
        self.assertEqual(init["result"]["protocolVersion"], "2025-03-26")
        self.assertIsNone(server.dispatch({"jsonrpc": "2.0",
                                          "method": "notifications/initialized"}))
        fail = server.dispatch({"jsonrpc": "2.0", "id": 2, "method": "tools/call",
            "params": {"name": "vta_get_master_tour", "arguments": {
                "id": 4, "arbitrary_route": "auth/login"}}})
        self.assertTrue(fail["result"]["isError"])

if __name__ == "__main__":
    unittest.main()
