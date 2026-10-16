#!/usr/bin/env python3
"""VTA RC6.2 restricted stdio MCP server; uses existing PHP session/CSRF APIs.

No direct database access. Only staging is supported in this candidate.
"""
from __future__ import annotations
import datetime
import http.cookiejar
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BLOCKED_HOSTS = {"v2quote.vietnamtraveladvisor.com.vn",
                 "quote.vietnamtraveladvisor.com.vn", "vietnamtraveladvisor.com.vn"}
MAX_BYTES = 1000000

class BridgeError(Exception):
    pass

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def http_error_302(self, req, fp, code, msg, headers):
        raise BridgeError("Upstream redirect blocked")
    http_error_301 = http_error_302
    http_error_303 = http_error_302
    http_error_307 = http_error_302
    http_error_308 = http_error_302

def identifier(value):
    if isinstance(value, bool) or not isinstance(value, int) or not 1 <= value <= 2147483647:
        raise BridgeError("Expected positive record ID")
    return value

def select(row, fields):
    return {key: row.get(key) for key in fields if key in row}

def entries(response, key="items"):
    value = response.get(key)
    if not isinstance(value, list):
        raise BridgeError("Invalid RC6.2 list response")
    return [x for x in value if isinstance(x, dict)]

class RC62:
    def __init__(self, settings=None, opener=None):
        settings = os.environ if settings is None else settings
        self.url = settings.get("VTA_API_URL", "").rstrip("/")
        parsed = urllib.parse.urlsplit(self.url)
        if (parsed.scheme != "https" or not parsed.hostname or parsed.username
                or parsed.password or parsed.query or parsed.fragment
                or not parsed.path.endswith("/api/index.php")):
            raise BridgeError("VTA_API_URL must point to https://STAGING_HOST/api/index.php")
        self.host = parsed.hostname.lower()
        if (settings.get("VTA_TARGET_ENV") != "staging" or self.host in BLOCKED_HOSTS):
            raise BridgeError("This release accepts only an isolated staging endpoint")
        self.email = settings.get("VTA_AGENT_EMAIL", "")
        self.password = settings.get("VTA_AGENT_PASSWORD", "")
        if not self.email or not self.password:
            raise BridgeError("Agent login credentials are required")
        try:
            self.company_id = identifier(int(settings["VTA_EXPECTED_COMPANY_ID"]))
        except (KeyError, ValueError):
            raise BridgeError("VTA_EXPECTED_COMPANY_ID is required")
        self.allow_tasks = settings.get("VTA_ENABLE_TASK_WRITES") == "1"
        self.csrf = ""
        self.user = {}
        self.opener = opener or urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def request(self, method, route, params=None, payload=None, authenticated=True):
        if authenticated and not self.csrf:
            self.login()
        query = urllib.parse.urlencode(dict(route=route, **(params or {})))
        body = None if payload is None else json.dumps(payload).encode("utf-8")
        if body is not None and len(body) > MAX_BYTES:
            raise BridgeError("Request too large")
        headers = {"Accept": "application/json"}
        if body is not None:
            headers["Content-Type"] = "application/json"
        if authenticated and method != "GET":
            headers["X-VTA-CSRF"] = self.csrf
        req = urllib.request.Request(self.url + "?" + query, data=body,
                                     headers=headers, method=method)
        try:
            with self.opener.open(req, timeout=12) as response:
                raw = response.read(MAX_BYTES + 1)
                if len(raw) > MAX_BYTES:
                    raise BridgeError("Response too large")
                result = json.loads(raw)
        except urllib.error.HTTPError as exc:
            raise BridgeError("RC6.2 denied request: HTTP " + str(exc.code)) from exc
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            raise BridgeError("Unable to reach RC6.2 staging") from exc
        except (ValueError, UnicodeError) as exc:
            raise BridgeError("Invalid RC6.2 JSON response") from exc
        if not isinstance(result, dict) or result.get("ok") is not True:
            raise BridgeError("RC6.2 returned an unsuccessful result")
        return result

    def login(self):
        response = self.request("POST", "auth/login",
                                payload={"email": self.email, "password": self.password},
                                authenticated=False)
        user = response.get("user")
        if (not isinstance(user, dict) or user.get("status") != "ACTIVE"
                or user.get("company_id") != self.company_id
                or not isinstance(user.get("csrf"), str) or len(user["csrf"]) < 16):
            raise BridgeError("Identity, tenant or CSRF verification failed")
        self.user, self.csrf = user, user["csrf"]
        return select(user, ("id", "company_id", "status", "role_code"))

    def require(self, permission):
        if not self.csrf:
            self.login()
        if permission not in self.user.get("permissions", []):
            raise BridgeError("Agent lacks required RC6.2 role permission")

    def execute(self, tool, arguments):
        if tool == "vta_connection_status":
            return {"identity": self.login() if not self.csrf else
                    select(self.user, ("id", "company_id", "status", "role_code")),
                    "environment": "staging", "task_writes": self.allow_tasks}
        if tool == "vta_operations_overview":
            d = self.request("GET", "workspace/home")
            return {"metrics": d.get("metrics"), "access": d.get("access"),
                    "attention_counts": {k: v.get("total") for k, v in
                        d.get("attention", {}).items() if isinstance(v, dict)}}
        if tool == "vta_sales_queues":
            d = self.request("GET", "workspace/sales")
            return select(d, ("metrics", "definitions"))
        if tool == "vta_list_inquiries":
            self.require("sales.view")
            params = {}
            if "id" in arguments:
                params["id"] = identifier(arguments["id"])
            d = self.request("GET", "inquiries", params=params)
            rows = entries(d)
            fields = ("id", "status", "title", "trip_ref", "market", "start_date",
                      "end_date", "total_guests", "paying_pax", "foc", "next_action_due", "sales_owner")
            return {"items": [select(x, fields) for x in rows[:100]],
                    "truncated": len(rows) > 100}
        if tool == "vta_list_master_tours":
            limit = arguments.get("limit", 20)
            if isinstance(limit, bool) or not isinstance(limit, int) or not 1 <= limit <= 50:
                raise BridgeError("limit must be an integer from 1 to 50")
            q = arguments.get("q", "")
            if not isinstance(q, str) or len(q) > 100:
                raise BridgeError("Invalid search query")
            d = self.request("GET", "tour-library", {"limit": limit, "q": q})
            fields = ("id", "title", "destination", "language", "status", "day_count", "tour_code")
            return {"total": d.get("total"),
                    "items": [select(x, fields) for x in entries(d)]}
        if tool == "vta_get_master_tour":
            d = self.request("GET", "tour-library/" + str(identifier(arguments.get("id"))))
            p = d.get("program")
            if not isinstance(p, dict):
                raise BridgeError("Missing Master Tour")
            fields = ("id", "title", "destination", "language", "status", "day_count",
                      "days", "included_text", "excluded_text", "terms_text", "tour_type")
            return {"program": select(p, fields)}
        if tool == "vta_list_bookings":
            self.require("booking.view")
            filter_ = arguments.get("filter", "UPCOMING")
            if filter_ not in ("UPCOMING", "AT_RISK", "ON_TOUR"):
                raise BridgeError("Invalid bookings filter")
            rows = entries(self.request("GET", "bookings", {"filter": filter_}))
            fields = ("id", "booking_ref", "start_date", "end_date", "operations_status",
                      "risk_level", "readiness_pct", "service_count", "confirmed_services")
            return {"items": [select(x, fields) for x in rows[:100]],
                    "truncated": len(rows) > 100}
        if tool == "vta_booking_readiness":
            self.require("operations.view")
            id_ = identifier(arguments.get("booking_id"))
            d = self.request("GET", "bookings/" + str(id_) + "/readiness")
            return {"booking_id": id_, **select(d, ("ready", "status", "readiness_pct",
                                                     "blockers", "warnings"))}
        if tool == "vta_operations_day":
            self.require("operations.view")
            date = arguments.get("date", datetime.date.today().isoformat())
            if not isinstance(date, str):
                raise BridgeError("Invalid date")
            try:
                if datetime.date.fromisoformat(date).isoformat() != date:
                    raise ValueError()
            except ValueError as exc:
                raise BridgeError("Expected valid YYYY-MM-DD") from exc
            d = self.request("GET", "v3/operations", {"date": date})
            m = ("id", "booking_id", "booking_ref", "category", "service_name",
                 "service_date", "start_time", "booking_status")
            issues = ("id", "booking_id", "booking_ref", "title", "status", "severity")
            return {"date": date, "movement": [select(x, m) for x in entries(d, "movement")[:100]],
                    "issues": [select(x, issues) for x in entries(d, "issues")[:100]]}
        if tool == "vta_list_tasks":
            self.require("task.view")
            scope = arguments.get("scope", "mine")
            if scope not in ("mine", "company"):
                raise BridgeError("Invalid task scope")
            fields = ("id", "title", "owner_name", "due_at", "priority",
                      "status", "due_state", "entity_type", "entity_id")
            return {"items": [select(x, fields) for x in
                     entries(self.request("GET", "tasks", {"scope": scope}))]}
        if tool == "vta_create_task" and self.allow_tasks:
            self.require("task.view")
            title = arguments.get("title")
            if (not isinstance(title, str) or not 3 <= len(title.strip()) <= 200
                    or any(ord(c) < 32 for c in title)):
                raise BridgeError("Invalid task title")
            priority = arguments.get("priority", "NORMAL")
            if priority not in ("NORMAL", "HIGH", "CRITICAL"):
                raise BridgeError("Invalid priority")
            payload = {"title": title.strip(), "priority": priority,
                       "entity_type": "manual", "entity_id": 0}
            if "due_at" in arguments:
                due = arguments["due_at"]
                if not isinstance(due, str) or not re.fullmatch(
                        r"\d{4}-\d\d-\d\d \d\d:\d\d:\d\d", due):
                    raise BridgeError("Invalid due_at")
                try:
                    datetime.datetime.strptime(due, "%Y-%m-%d %H:%M:%S")
                except ValueError as exc:
                    raise BridgeError("Invalid due_at") from exc
                payload["due_at"] = due
            if "owner_user_id" in arguments:
                payload["owner_user_id"] = identifier(arguments["owner_user_id"])
            d = self.request("POST", "tasks", payload=payload)
            return {"created": True, "id": identifier(d.get("id")), "environment": "staging"}
        raise BridgeError("Tool is unknown or disabled")

SPECS = {
    "vta_connection_status": ("Verify isolated RC6.2 agent identity", {}),
    "vta_operations_overview": ("Read today's operational metrics without guest details", {}),
    "vta_sales_queues": ("Read sales and follow-up queue metrics", {}),
    "vta_list_inquiries": ("Read active inquiry records without customer contact data",
                           {"id": {"type": "integer", "minimum": 1}}),
    "vta_list_master_tours": ("Search Master Tour library", {
        "q": {"type": "string"}, "limit": {"type": "integer", "minimum": 1, "maximum": 50}}),
    "vta_get_master_tour": ("Read a Master Tour itinerary", {
        "id": {"type": "integer", "minimum": 1}}),
    "vta_list_bookings": ("Read booking status without customer identity", {
        "filter": {"type": "string", "enum": ["UPCOMING", "AT_RISK", "ON_TOUR"]}}),
    "vta_booking_readiness": ("Read booking operations readiness", {
        "booking_id": {"type": "integer", "minimum": 1}}),
    "vta_operations_day": ("Inspect operational movements and incidents", {
        "date": {"type": "string", "description": "YYYY-MM-DD"}}),
    "vta_list_tasks": ("Read open and snoozed operations tasks", {
        "scope": {"type": "string", "enum": ["mine", "company"]}}),
    "vta_create_task": ("Create a manual task in staging only, explicit opt-in required", {
        "title": {"type": "string"}, "priority": {"type": "string", "enum": ["NORMAL", "HIGH", "CRITICAL"]},
        "due_at": {"type": "string"}, "owner_user_id": {"type": "integer", "minimum": 1}})
}

class Server:
    def __init__(self, api):
        self.api = api

    def tools(self):
        return [{"name": name, "description": description, "inputSchema": {
            "type": "object", "properties": properties, "additionalProperties": False,
            "required": (["id"] if name == "vta_get_master_tour" else
                         ["booking_id"] if name == "vta_booking_readiness" else
                         ["title"] if name == "vta_create_task" else [])}}
                for name, (description, properties) in SPECS.items()
                if name != "vta_create_task" or self.api.allow_tasks]

    def dispatch(self, message):
        if not isinstance(message, dict) or message.get("jsonrpc") != "2.0":
            return {"jsonrpc": "2.0", "id": None,
                    "error": {"code": -32600, "message": "Invalid Request"}}
        if "id" not in message:
            return None  # notification
        method, id_ = message.get("method"), message["id"]
        try:
            if method == "initialize":
                requested = (message.get("params") or {}).get("protocolVersion", "2024-11-05")
                version = requested if requested in (
                    "2024-11-05", "2025-03-26", "2025-06-18") else "2024-11-05"
                result = {"protocolVersion": version, "capabilities": {"tools": {}},
                          "serverInfo": {"name": "vta-rc62-operations", "version": "0.1.0"}}
            elif method == "ping":
                result = {}
            elif method == "tools/list":
                result = {"tools": self.tools()}
            elif method == "tools/call":
                p = message.get("params") or {}
                if not isinstance(p, dict):
                    raise BridgeError("Invalid MCP parameters")
                name, args = p.get("name"), p.get("arguments") or {}
                if not isinstance(args, dict):
                    raise BridgeError("Tool arguments must be object")
                specs = {t["name"]: t for t in self.tools()}
                if name not in specs:
                    raise BridgeError("Unknown or disabled tool")
                schema = specs[name]["inputSchema"]
                if set(args) - set(schema["properties"]):
                    raise BridgeError("Unknown tool argument")
                if set(schema["required"]) - set(args):
                    raise BridgeError("Missing required argument")
                output = self.api.execute(name, args)
                result = {"content": [{"type": "text",
                         "text": json.dumps(output, ensure_ascii=False)}]}
            else:
                return {"jsonrpc": "2.0", "id": id_,
                        "error": {"code": -32601, "message": "Method not found"}}
        except (BridgeError, TypeError, ValueError) as exc:
            result = {"content": [{"type": "text", "text": str(exc)}], "isError": True}
        return {"jsonrpc": "2.0", "id": id_, "result": result}

def main():
    try:
        server = Server(RC62())
    except BridgeError as exc:
        print("VTA MCP configuration refused: " + str(exc), file=sys.stderr)
        sys.exit(2)
    for line in sys.stdin:
        if len(line) > MAX_BYTES:
            continue
        try:
            reply = server.dispatch(json.loads(line))
            if reply is not None:
                print(json.dumps(reply, ensure_ascii=False, separators=(",", ":")), flush=True)
        except (ValueError, TypeError):
            print('{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}',
                  flush=True)

if __name__ == "__main__":
    main()
