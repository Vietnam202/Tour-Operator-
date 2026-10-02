import assert from "node:assert/strict";
import { createHash, randomBytes, randomUUID, scryptSync } from "node:crypto";
import test from "node:test";
import { PrismaClient, type StaffRole } from "@prisma/client";

const database = new URL(process.env.DATABASE_URL || "postgresql://invalid/invalid");
if (process.env.HCA_INTEGRATION_TESTS !== "1" || !["localhost", "127.0.0.1"].includes(database.hostname) || database.pathname !== "/hca_ci") {
  throw new Error("Staff integration tests require the disposable local hca_ci database and HCA_INTEGRATION_TESTS=1.");
}
const base = process.env.HCA_TEST_BASE_URL || "http://127.0.0.1:3000";
if (!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base)) throw new Error("Only a local test HTTP server is allowed.");
const prisma = new PrismaClient();
const digest = (value: string) => createHash("sha256").update(value).digest("hex");
const forbidden = new Set(["password", "passwordHash", "token", "tokenHash", "sessionToken"]);
function assertSafe(value: unknown): void {
  if (!value || typeof value !== "object") return;
  for (const [key, item] of Object.entries(value)) {
    assert.ok(!forbidden.has(key), `Credential field leaked: ${key}`); assertSafe(item);
  }
}
type Options = { method?: string; body?: unknown; raw?: string; cookie?: string; origin?: string | null; headers?: Record<string, string> };
async function request(path: string, options: Options = {}) {
  const method = options.method || "GET";
  const headers: Record<string, string> = { ...options.headers };
  if (options.cookie) headers.Cookie = options.cookie;
  if (method !== "GET" && options.origin !== null) headers.Origin = options.origin ?? base;
  if (options.body !== undefined || options.raw !== undefined) headers["Content-Type"] ??= "application/json";
  const response = await fetch(base + path, { method, headers,
    body: options.raw ?? (options.body === undefined ? undefined : JSON.stringify(options.body)),
    signal: AbortSignal.timeout(20000),
  });
  assert.match(response.headers.get("content-type") || "", /application\/json/);
  return { response, data: await response.json() };
}
function returnedCookie(response: Response): string {
  const value = response.headers.get("set-cookie") || "";
  assert.match(value, /^hca_staff_session=[a-f0-9]{64};/);
  assert.match(value, /HttpOnly/i); assert.match(value, /Secure/i);
  assert.match(value, /SameSite=lax/i); assert.match(value, /Path=\//i);
  assert.match(value, /Max-Age=43200/i); assert.doesNotMatch(value, /;\s*Domain=/i);
  return value.split(";")[0];
}
function assertPrivate(response: Response) {
  assert.match(response.headers.get("cache-control") || "", /no-store/);
  assert.match(response.headers.get("vary") || "", /Cookie/i);
}

test("Staff login, cookie isolation, revocation and trusted-origin enforcement", async t => {
  const ids: string[] = [];
  const suffix = randomUUID();
  const password = "Fixture only " + randomBytes(16).toString("hex");
  const salt = randomBytes(16).toString("hex");
  const passwordHash = salt + ":" + scryptSync(password, salt, 64).toString("hex");
  try {
    async function user(label: string, role: StaffRole = "ADMIN", isActive = true, hash = passwordHash) {
      const created = await prisma.staffUser.create({ data: {
        email: `${label}-${suffix}@example.invalid`, name: `Synthetic ${label}`, role, isActive, passwordHash: hash,
      } });
      ids.push(created.id); return created;
    }
    async function session(userId: string, expiresAt = new Date(Date.now() + 3600000)) {
      const token = randomBytes(32).toString("hex");
      const created = await prisma.staffSession.create({ data: { userId, tokenHash: digest(token), expiresAt } });
      return { id: created.id, token, cookie: "hca_staff_session=" + token };
    }
    const admin = await user("login");
    const other = await user("other");
    const disabled = await user("disabled", "SALES", false);
    const malformed = await user("malformed", "OPERATIONS", true, "invalid-stored-hash");
    const finance = await user("finance", "FINANCE");
    const otherSession = await session(other.id);
    const disabledSession = await session(disabled.id);
    const expiredSession = await session(other.id, new Date(Date.now() - 1000));
    const financeSession = await session(finance.id);
    let cookie = "";
    let previousCookie = "";
    let auxiliary = "";
    const login = (body: unknown, suppliedCookie?: string, headers?: Record<string, string>) => request("/api/admin/auth/login", {
      method: "POST", body, cookie: suppliedCookie, headers,
    });

    await t.test("all existing staff mutation families reject a missing Origin before a write", async () => {
      const targets: [string, string][] = [
        ["POST", "/api/admin/auth/login"], ["POST", "/api/admin/auth/logout"],
        ["DELETE", "/api/admin/auth/sessions"], ["PATCH", "/api/admin/bookings/not-a-booking"],
        ["POST", "/api/admin/bookings/not-a-booking/tasks"], ["PATCH", "/api/admin/bookings/not-a-booking/tasks/not-a-task"],
        ["PATCH", "/api/admin/bookings/not-a-booking/supplier-confirmation"], ["POST", "/api/admin/bookings/not-a-booking/voucher"],
        ["POST", "/api/admin/cabins"], ["POST", "/api/admin/departures"], ["POST", "/api/admin/cruises"],
        ["PATCH", "/api/admin/cruises/not-a-cruise"], ["POST", "/api/admin/suppliers"],
        ["POST", "/api/admin/payments/request"], ["POST", "/api/admin/payments/plan"],
        ["POST", "/api/admin/expenses"], ["POST", "/api/admin/payables"], ["PATCH", "/api/admin/payables/not-a-payable"],
      ];
      for (const [method, path] of targets) {
        const result = await request(path, { method, body: {}, origin: null, cookie: otherSession.cookie });
        assert.equal(result.response.status, 403, `Origin guard missing for ${method} ${path}`);
      }
      assert.equal((await request("/api/admin/auth/me", { cookie: otherSession.cookie })).response.status, 200);
    });
    await t.test("host or forwarded headers cannot authorize a foreign staff origin", async () => {
      for (const origin of ["null", "https://untrusted.example.invalid", "https://ci.example.invalid.attacker.invalid"]) {
        const result = await request("/api/admin/auth/logout", { method: "POST", cookie: otherSession.cookie, origin,
          headers: { "x-forwarded-host": origin.replace("https://", ""), "forwarded": "host=untrusted.example.invalid;proto=https" } });
        assert.equal(result.response.status, 403);
      }
      const result = await request("/api/admin/auth/logout", { method: "POST", cookie: otherSession.cookie,
        headers: { "sec-fetch-site": "cross-site" } });
      assert.equal(result.response.status, 403);
      assert.equal((await request("/api/admin/auth/me", { cookie: otherSession.cookie })).response.status, 200);
    });
    await t.test("malformed JSON, oversized bodies, invalid shapes and password coercion fail safely", async () => {
      assert.equal((await request("/api/admin/auth/login", { method: "POST", raw: "{broken" })).response.status, 400);
      assert.equal((await request("/api/admin/auth/login", { method: "POST", raw: "email=test", headers: { "Content-Type": "text/plain" } })).response.status, 415);
      for (const body of [null, [], { email: {}, password }, { email: admin.email, password: 12345 },
        { email: admin.email, password: "x".repeat(257) }, { email: admin.email, password, role: "ADMIN" }]) {
        const result = await login(body); assert.equal(result.response.status, 400); assertSafe(result.data); assertPrivate(result.response);
      }
      assert.equal((await login({ email: admin.email, password: "x".repeat(10000) })).response.status, 413);
      assert.equal(await prisma.staffSession.count({ where: { userId: admin.id } }), 0);
    });
    await t.test("wrong, unknown, disabled and malformed-hash accounts have the same credential failure", async () => {
      for (const email of [admin.email, `missing-${suffix}@example.invalid`, disabled.email, malformed.email]) {
        const result = await login({ email, password: email === admin.email ? "incorrect-password" : password });
        assert.equal(result.response.status, 401); assert.deepEqual(result.data, { error: "Invalid email or password." });
        assert.equal(result.response.headers.get("set-cookie"), null); assertPrivate(result.response);
      }
    });
    await t.test("real login normalizes email and issues a private 12-hour host-only cookie", async () => {
      const fixedToken = randomBytes(32).toString("hex");
      const result = await login({ email: " " + admin.email.toUpperCase() + " ", password }, "hca_staff_session=" + fixedToken);
      assert.equal(result.response.status, 200, result.data.error); assertSafe(result.data); assertPrivate(result.response);
      cookie = returnedCookie(result.response);
      const token = cookie.split("=")[1]; assert.notEqual(token, fixedToken);
      const stored = await prisma.staffSession.findUniqueOrThrow({ where: { tokenHash: digest(token) } });
      assert.equal(stored.userId, admin.id); assert.notEqual(stored.tokenHash, token);
      assert.ok(stored.expiresAt.getTime() > Date.now() + 43100 * 1000);
      assert.ok(stored.expiresAt.getTime() <= Date.now() + 43200 * 1000);
      const me = await request("/api/admin/auth/me", { cookie });
      assert.equal(me.response.status, 200); assert.equal(me.data.user.id, admin.id); assertSafe(me.data);
    });
    await t.test("signing in again rotates and invalidates the presented session", async () => {
      assert.ok(cookie); previousCookie = cookie;
      const result = await login({ email: admin.email, password }, cookie);
      assert.equal(result.response.status, 200); cookie = returnedCookie(result.response);
      assert.notEqual(cookie, previousCookie);
      assert.equal((await request("/api/admin/auth/me", { cookie: previousCookie })).response.status, 401);
      assert.equal((await request("/api/admin/auth/me", { cookie })).response.status, 200);
      assert.equal(await prisma.staffSession.count({ where: { userId: admin.id } }), 1);
    });
    await t.test("malformed and duplicate session cookies cannot crash or select an arbitrary user", async () => {
      assert.ok(cookie);
      for (const bad of ["hca_staff_session=%", "hca_staff_session=short", "hca_staff_session", cookie + "; " + cookie,
        cookie + "; " + otherSession.cookie, "hca_staff_session=short; " + cookie]) {
        const result = await request("/api/admin/auth/me", { cookie: bad });
        assert.equal(result.response.status, 401); assert.deepEqual(result.data, { authenticated: false }); assertPrivate(result.response);
      }
      assert.equal((await request("/api/admin/auth/me", { cookie: "unrelated=1; " + cookie })).response.status, 200);
    });
    await t.test("expired and disabled sessions are rejected and configured legacy keys stay off", async () => {
      const key = process.env.ADMIN_API_KEY;
      assert.ok(key); assert.notEqual(process.env.ENABLE_LEGACY_ADMIN_API_KEY, "true");
      for (const value of [undefined, disabledSession.cookie, expiredSession.cookie, "hca_staff_session=%"]) {
        const result = await request("/api/admin/bookings", { cookie: value, headers: { "x-admin-key": key } });
        assert.equal(result.response.status, 401);
      }
      for (const value of [disabledSession.cookie, expiredSession.cookie]) {
        assert.equal((await request("/api/admin/auth/sessions", { cookie: value })).response.status, 401);
      }
    });
    await t.test("session history includes only the active sessions for the current account", async () => {
      const extra = await session(admin.id); auxiliary = extra.cookie;
      await session(admin.id, new Date(Date.now() - 1000));
      const result = await request("/api/admin/auth/sessions", { cookie });
      assert.equal(result.response.status, 200); assertSafe(result.data); assertPrivate(result.response);
      assert.equal(result.data.sessions.length, 2);
      assert.equal(result.data.sessions.filter((item: { current: boolean }) => item.current).length, 1);
      assert.ok(result.data.sessions.every((item: { id: string }) => item.id !== otherSession.id));
      for (const item of result.data.sessions) assert.deepEqual(Object.keys(item).sort(), ["createdAt", "current", "expiresAt", "id"]);
    });
    await t.test("session revocation validates scope and cannot accept another user ID", async () => {
      for (const body of [{ scope: "other" }, { scope: "all", userId: other.id }, {}, []]) {
        const result = await request("/api/admin/auth/sessions", { method: "DELETE", cookie, body });
        assert.equal(result.response.status, 400);
      }
      assert.equal((await request("/api/admin/auth/sessions", { method: "DELETE", body: { scope: "all" } })).response.status, 401);
      assert.equal((await request("/api/admin/auth/me", { cookie: auxiliary })).response.status, 200);
    });
    await t.test("sign out others revokes only other sessions and repeat requests are idempotent", async () => {
      const result = await request("/api/admin/auth/sessions", { method: "DELETE", cookie, body: { scope: "others" } });
      assert.equal(result.response.status, 200); assert.equal(result.data.signedOut, false); assert.equal(result.data.revoked, 2);
      assert.equal((await request("/api/admin/auth/me", { cookie: auxiliary })).response.status, 401);
      assert.equal((await request("/api/admin/auth/me", { cookie })).response.status, 200);
      assert.equal((await request("/api/admin/auth/me", { cookie: otherSession.cookie })).response.status, 200);
      const retry = await request("/api/admin/auth/sessions", { method: "DELETE", cookie, body: { scope: "others" } });
      assert.equal(retry.response.status, 200); assert.equal(retry.data.revoked, 0);
    });
    await t.test("concurrent revoke-other requests preserve the current session and count each removed session once", async () => {
      await session(admin.id); await session(admin.id);
      const results = await Promise.all(Array.from({ length: 5 }, () => request("/api/admin/auth/sessions", {
        method: "DELETE", cookie, body: { scope: "others" },
      })));
      for (const result of results) assert.equal(result.response.status, 200);
      assert.equal(results.reduce((sum, result) => sum + result.data.revoked, 0), 2);
      assert.equal((await request("/api/admin/auth/me", { cookie })).response.status, 200);
    });
    await t.test("Finance can manage its own sessions without obtaining booking write access", async () => {
      assert.equal((await request("/api/admin/auth/sessions", { cookie: financeSession.cookie })).response.status, 200);
      const mutation = await request("/api/admin/bookings/not-a-booking", { method: "PATCH", cookie: financeSession.cookie, body: { status: "CONFIRMED" } });
      assert.equal(mutation.response.status, 401);
      const revoke = await request("/api/admin/auth/sessions", { method: "DELETE", cookie: financeSession.cookie, body: { scope: "others" } });
      assert.equal(revoke.response.status, 200); assert.equal(revoke.data.revoked, 0);
    });
    await t.test("sign out everywhere invalidates the current cookie without affecting another account", async () => {
      const extra = await session(admin.id);
      const result = await request("/api/admin/auth/sessions", { method: "DELETE", cookie, body: { scope: "all" } });
      assert.equal(result.response.status, 200); assert.equal(result.data.signedOut, true); assert.equal(result.data.revoked, 2);
      assert.match(result.response.headers.get("set-cookie") || "", /Max-Age=0/i);
      assert.equal((await request("/api/admin/auth/me", { cookie })).response.status, 401);
      assert.equal((await request("/api/admin/auth/me", { cookie: extra.cookie })).response.status, 401);
      assert.equal((await request("/api/admin/auth/me", { cookie: otherSession.cookie })).response.status, 200);
      assert.equal((await request("/api/admin/auth/sessions", { method: "DELETE", cookie, body: { scope: "all" } })).response.status, 401);
    });
    await t.test("logout revokes the server session and handles retries and malformed cookies safely", async () => {
      for (const value of [otherSession.cookie, otherSession.cookie, "hca_staff_session=%"]) {
        const result = await request("/api/admin/auth/logout", { method: "POST", cookie: value });
        assert.equal(result.response.status, 200); assert.deepEqual(result.data, { ok: true }); assertPrivate(result.response);
        assert.match(result.response.headers.get("set-cookie") || "", /Max-Age=0/i);
      }
      assert.equal((await request("/api/admin/auth/me", { cookie: otherSession.cookie })).response.status, 401);
    });
    await t.test("changing spoofed forwarded IPs does not bypass normalized-account login throttling", async () => {
      const email = `throttle-${suffix}@example.invalid`;
      for (let attempt = 0; attempt < 8; attempt += 1) {
        const result = await login({ email: attempt % 2 ? " " + email.toUpperCase() + " " : email, password: "incorrect" }, undefined,
          { "x-forwarded-for": `198.51.100.${attempt + 1}`, "x-real-ip": `203.0.113.${attempt + 1}` });
        assert.equal(result.response.status, 401);
      }
      const result = await login({ email, password: "incorrect" }, undefined, { "x-forwarded-for": "192.0.2.199" });
      assert.equal(result.response.status, 429); assert.ok(Number(result.response.headers.get("retry-after")) > 0); assertPrivate(result.response);
    });
    await t.test("account security page renders without exposing private account data in HTML", async () => {
      const response = await fetch(base + "/admin/security");
      assert.equal(response.status, 200);
      const html = await response.text(); assert.ok(html.includes("Account security"));
      assert.ok(!html.includes(admin.email)); assert.ok(!html.includes(passwordHash));
    });
  } finally {
    if (ids.length) await prisma.staffUser.deleteMany({ where: { id: { in: ids } } });
    await prisma.$disconnect();
  }
});
