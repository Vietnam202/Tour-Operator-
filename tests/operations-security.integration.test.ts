import assert from "node:assert/strict";
import { createHash, randomBytes, randomUUID } from "node:crypto";
import test from "node:test";
import { PrismaClient, Prisma, StaffRole, type Departure } from "@prisma/client";

const database = new URL(process.env.DATABASE_URL || "postgresql://invalid/invalid");
if (process.env.HCA_INTEGRATION_TESTS !== "1" || !["localhost", "127.0.0.1"].includes(database.hostname) || database.pathname !== "/hca_ci") {
  throw new Error("Operations integration tests require the disposable local hca_ci database and HCA_INTEGRATION_TESTS=1.");
}
const base = process.env.HCA_TEST_BASE_URL || "http://127.0.0.1:3000";
if (!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base)) throw new Error("Only a local test HTTP server is allowed.");
const prisma = new PrismaClient();
const digest = (value: string) => createHash("sha256").update(value).digest("hex");
function future(days: number) { const value = new Date(); value.setUTCDate(value.getUTCDate() + days); value.setUTCHours(12, 0, 0, 0); return value; }

type Options = { method?: string; body?: unknown; cookie?: string; token?: string; origin?: string | null; headers?: Record<string, string> };
async function request(path: string, options: Options = {}) {
  const method = options.method || "GET";
  const headers: Record<string, string> = { "x-forwarded-for": "127.0.0.1", ...options.headers };
  if (options.cookie) headers.Cookie = options.cookie;
  if (options.token !== undefined) headers.Authorization = "Bearer " + options.token;
  if (method !== "GET" && options.origin !== null) headers.Origin = options.origin ?? base;
  if (options.body !== undefined) headers["Content-Type"] = "application/json";
  const response = await fetch(base + path, { method, headers,
    body: options.body === undefined ? undefined : JSON.stringify(options.body), signal: AbortSignal.timeout(20000) });
  assert.match(response.headers.get("content-type") || "", /application\/json/, `Expected JSON for ${method} ${path.split("?")[0]}`);
  return { response, data: await response.json() };
}
const forbidden = new Set(["tokenHash", "passwordHash", "email", "phone", "nationality", "quotedCost", "quotedMargin", "internalNotes", "supplierId", "supplierConfirmationRef"]);
function assertPublic(value: unknown): void {
  if (!value || typeof value !== "object") return;
  for (const [key, item] of Object.entries(value)) { assert.ok(!forbidden.has(key), `Private field leaked: ${key}`); assertPublic(item); }
}

test("Concurrent operations and private customer voucher access", async t => {
  const bookingIds: string[] = [], userIds: string[] = [], cruiseIds: string[] = [], outboxIds: string[] = [];
  const suffix = randomUUID();
  let issuedToken = "";
  try {
    async function session(role: StaffRole) {
      const user = await prisma.staffUser.create({ data: { email: `${role}-${suffix}@example.invalid`, name: `Test ${role}`, role, passwordHash: "test-session-only-no-login" } });
      userIds.push(user.id);
      const token = randomBytes(32).toString("hex");
      await prisma.staffSession.create({ data: { userId: user.id, tokenHash: digest(token), expiresAt: new Date(Date.now() + 3600000) } });
      return { cookie: `hca_staff_session=${token}`, userId: user.id };
    }
    const admin = await session(StaffRole.ADMIN), operations = await session(StaffRole.OPERATIONS);
    const finance = await session(StaffRole.FINANCE), sales = await session(StaffRole.SALES);
    const cruise = await prisma.cruise.create({ data: {
      slug: `ci-ops-${suffix}`, name: `Operations fixture ${suffix}`, route: "CI operations only", status: "PUBLISHED",
      departures: { create: [
        { departureDate: future(20), durationNights: 1, priceFrom: 500, cabinsLeft: 5 },
        { departureDate: future(21), durationNights: 1, priceFrom: 500, cabinsLeft: 1 },
        { departureDate: future(22), durationNights: 1, priceFrom: 500, cabinsLeft: null },
        { departureDate: new Date("2020-01-01T12:00:00Z"), durationNights: 1, priceFrom: 500, cabinsLeft: 2 },
      ] },
    }, include: { departures: true } });
    cruiseIds.push(cruise.id);
    const many = cruise.departures.find(item => item.cabinsLeft === 5)!;
    const last = cruise.departures.find(item => item.cabinsLeft === 1)!;
    const unknown = cruise.departures.find(item => item.cabinsLeft === null)!;
    const past = cruise.departures.find(item => item.departureDate.getUTCFullYear() === 2020)!;
    async function makeBooking(departure: Departure, overrides: Partial<Prisma.BookingInquiryUncheckedCreateInput> = {}) {
      const booking = await prisma.bookingInquiry.create({ data: {
        reference: `CI-${randomUUID()}`, cruiseId: cruise.id, cruiseName: cruise.name, cabinName: "Test cabin",
        departureId: departure.id, departureDate: departure.departureDate, durationNights: departure.durationNights,
        adults: 2, children: 0, primaryGuest: `Private guest ${randomUUID()}`, email: "fixture@example.invalid",
        estimatedTotal: 1000, currency: "USD", transferType: "shared", assignedToId: operations.userId,
        ...overrides,
      } });
      bookingIds.push(booking.id); return booking;
    }
    const same = await makeBooking(many);
    const first = await makeBooking(last), second = await makeBooking(last);
    const patch = (id: string, body: unknown, cookie = admin.cookie) => request(`/api/admin/bookings/${id}`, { method: "PATCH", body, cookie });
    const supplier = (status = "CONFIRMED", reference = "OPERATOR-CI-123") => request(`/api/admin/bookings/${same.id}/supplier-confirmation`,
      { method: "PATCH", body: { status, reference }, cookie: operations.cookie });
    const voucherAdmin = `/api/admin/bookings/${same.id}/voucher`;
    const voucherPublic = `/api/voucher/${same.reference}`;
    async function issue(cookie = admin.cookie) {
      const result = await request(voucherAdmin, { method: "POST", cookie });
      assert.equal(result.response.status, 201, result.data.error);
      const url = new URL(result.data.voucherUrl);
      assert.equal(url.protocol, "https:"); assert.equal(url.search, "");
      const token = new URLSearchParams(url.hash.slice(1)).get("access");
      assert.ok(token && /^[a-f0-9]{64}$/.test(token));
      return { ...result.data, token: token! };
    }

    await t.test("staff login account throttle is shared and returns Retry-After", async () => {
      const email = `missing-${randomUUID()}@example.invalid`;
      const headers = { "x-forwarded-for": `198.51.100.${Math.floor(Math.random() * 100) + 1}` };
      for (let attempt = 0; attempt < 8; attempt++) {
        const result = await request("/api/admin/auth/login", { method: "POST", body: { email, password: "wrong-password" }, headers });
        assert.equal(result.response.status, 401);
      }
      const blocked = await request("/api/admin/auth/login", { method: "POST", body: { email, password: "wrong-password" }, headers });
      assert.equal(blocked.response.status, 429);
      assert.ok(Number(blocked.response.headers.get("retry-after")) >= 1);
    });
    await t.test("eight simultaneous confirmations reserve one cabin and one automated checklist", async () => {
      const outcomes = await Promise.all(Array.from({ length: 8 }, () => patch(same.id, { status: "CONFIRMED" })));
      for (const result of outcomes) assert.equal(result.response.status, 200, result.data.error);
      const saved = await prisma.bookingInquiry.findUniqueOrThrow({ where: { id: same.id }, include: { tasks: true, activities: true } });
      assert.equal(saved.status, "CONFIRMED"); assert.equal(saved.inventoryCommitted, true);
      assert.equal((await prisma.departure.findUniqueOrThrow({ where: { id: many.id } })).cabinsLeft, 4);
      assert.equal(saved.tasks.length, 5); assert.equal(new Set(saved.tasks.map(item => item.type)).size, 5);
      assert.equal(saved.activities.filter(item => item.type === "BOOKING_UPDATED").length, 1);
      assert.equal(saved.activities.filter(item => item.type === "AUTOMATION_TASK_CREATED").length, 5);
    });
    await t.test("payment request persists a safe outbox event without bearer credentials", async () => {
      const result = await request("/api/admin/payments/request", {
        method: "POST", cookie: admin.cookie,
        body: { bookingId: same.id, kind: "DEPOSIT", amount: 100 },
      });
      assert.equal(result.response.status, 201, result.data.error);
      assert.match(result.data.paymentUrl, /^\/pay\/[a-f0-9]{64}$/);
      const outbox = await prisma.notificationOutbox.findUniqueOrThrow({
        where: { idempotencyKey: `payment.requested:${result.data.paymentRequest.id}` },
      });
      outboxIds.push(outbox.id);
      assert.equal(outbox.status, "PENDING");
      const payloadText = JSON.stringify(outbox.payload);
      assert.ok(!payloadText.includes(result.data.paymentRequest.token));
      assert.ok(!payloadText.includes(result.data.paymentUrl));
      assert.ok(!/token|secret|session|authorization|cookie/i.test(payloadText));
    });

    await t.test("payment webhook replay creates one payment.updated outbox event", async () => {
      const eventId = `ci-payment-${randomUUID()}`;
      const path = "/api/payments/webhook/ci";
      const body = { eventId, bookingId: same.id, amount: 100, status: "SUCCEEDED", kind: "DEPOSIT", currency: "USD" };
      const headers = { "x-webhook-secret": process.env.PAYMENT_WEBHOOK_SECRET || "" };
      const firstDelivery = await request(path, { method: "POST", body, headers });
      const replay = await request(path, { method: "POST", body, headers });
      assert.equal(firstDelivery.response.status, 200, firstDelivery.data.error);
      assert.equal(firstDelivery.data.duplicate, false);
      assert.equal(replay.response.status, 200, replay.data.error);
      assert.equal(replay.data.duplicate, true);
      const key = `payment.updated:ci:${eventId}`;
      assert.equal(await prisma.notificationOutbox.count({ where: { idempotencyKey: key } }), 1);
      const outbox = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey: key } });
      outboxIds.push(outbox.id);
      assert.equal(outbox.status, "PENDING");
      assert.ok(!/token|secret|session|authorization|cookie/i.test(JSON.stringify(outbox.payload)));
    });

    await t.test("two bookings racing for the final cabin produce one success and one conflict", async () => {
      const outcomes = await Promise.all([patch(first.id, { status: "CONFIRMED" }), patch(second.id, { status: "CONFIRMED" })]);
      assert.deepEqual(outcomes.map(result => result.response.status).sort(), [200, 409]);
      const bookings = await prisma.bookingInquiry.findMany({ where: { id: { in: [first.id, second.id] } }, include: { tasks: true } });
      assert.equal(bookings.filter(item => item.inventoryCommitted).length, 1);
      const loser = bookings.find(item => !item.inventoryCommitted)!;
      assert.equal(loser.status, "NEW"); assert.equal(loser.tasks.length, 0);
      const departure = await prisma.departure.findUniqueOrThrow({ where: { id: last.id } });
      assert.equal(departure.cabinsLeft, 0); assert.equal(departure.isAvailable, false);
    });
    await t.test("unknown allocation and past departures cannot be confirmed", async () => {
      for (const departure of [unknown, past]) {
        const booking = await makeBooking(departure);
        assert.equal((await patch(booking.id, { status: "CONFIRMED" })).response.status, 409);
        const saved = await prisma.bookingInquiry.findUniqueOrThrow({ where: { id: booking.id } });
        assert.equal(saved.inventoryCommitted, false); assert.equal(saved.status, "NEW");
      }
    });
    await t.test("mismatched departure details cannot change inventory", async () => {
      const booking = await makeBooking(many, { durationNights: 2 });
      assert.equal((await patch(booking.id, { status: "CONFIRMED" })).response.status, 409);
      assert.equal((await prisma.departure.findUniqueOrThrow({ where: { id: many.id } })).cabinsLeft, 4);
    });
    await t.test("invalid fields and inactive owners fail before reservation", async () => {
      const booking = await makeBooking(many);
      for (const body of [null, [], {}, { status: "" }, { status: "UNKNOWN" }, { estimatedTotal: 1 }, { followUpAt: "not-a-date" }]) {
        assert.equal((await patch(booking.id, body)).response.status, 400);
      }
      assert.equal((await patch(booking.id, { status: "CONFIRMED", assignedToId: "missing-staff" })).response.status, 400);
      assert.equal((await prisma.departure.findUniqueOrThrow({ where: { id: many.id } })).cabinsLeft, 4);
      assert.equal(await prisma.bookingTask.count({ where: { bookingId: booking.id } }), 0);
    });
    await t.test("Finance and unauthenticated callers cannot mutate booking status", async () => {
      assert.equal((await patch(same.id, { status: "CANCELLED" }, finance.cookie)).response.status, 401);
      assert.equal((await request(`/api/admin/bookings/${same.id}`, { method: "PATCH", body: { status: "CANCELLED" } })).response.status, 401);
      assert.equal((await prisma.bookingInquiry.findUniqueOrThrow({ where: { id: same.id } })).status, "CONFIRMED");
    });
    await t.test("task identifiers from another booking are rejected without a write", async () => {
      const task = await prisma.bookingTask.findFirstOrThrow({ where: { bookingId: same.id, type: "PASSPORT" } });
      const result = await request(`/api/admin/bookings/${first.id}/tasks/${task.id}`, { method: "PATCH", cookie: admin.cookie, body: { status: "DONE" } });
      assert.equal(result.response.status, 404);
      assert.equal((await prisma.bookingTask.findUniqueOrThrow({ where: { id: task.id } })).status, "OPEN");
    });
    await t.test("manual tasks preserve completedAt and do not duplicate status audit on retry", async () => {
      const created = await request(`/api/admin/bookings/${same.id}/tasks`, { method: "POST", cookie: operations.cookie,
        body: { type: "OTHER", title: "CI manual follow-up", ownerId: operations.userId } });
      assert.equal(created.response.status, 201);
      const endpoint = `/api/admin/bookings/${same.id}/tasks/${created.data.task.id}`;
      const done = await request(endpoint, { method: "PATCH", cookie: operations.cookie, body: { status: "DONE" } });
      const repeat = await request(endpoint, { method: "PATCH", cookie: operations.cookie, body: { status: "DONE" } });
      assert.equal(done.response.status, 200); assert.equal(repeat.response.status, 200);
      assert.equal(done.data.task.completedAt, repeat.data.task.completedAt);
      assert.equal(await prisma.bookingActivity.count({ where: { bookingId: same.id, type: "TASK_STATUS", message: { contains: "CI manual follow-up" } } }), 1);
    });
    await t.test("supplier confirmation requires a reference and closes tasks atomically", async () => {
      assert.equal((await supplier("CONFIRMED", "")).response.status, 400);
      assert.equal((await supplier()).response.status, 200);
      assert.equal((await supplier()).response.status, 200);
      const booking = await prisma.bookingInquiry.findUniqueOrThrow({ where: { id: same.id }, include: { tasks: true } });
      assert.equal(booking.supplierConfirmationStatus, "CONFIRMED");
      assert.equal(booking.tasks.find(item => item.type === "SUPPLIER_CONFIRMATION")!.status, "DONE");
      assert.equal(await prisma.bookingActivity.count({ where: { bookingId: same.id, type: "SUPPLIER_CONFIRMATION" } }), 1);
    });
    await t.test("a known reference alone reveals no voucher or guest details", async () => {
      const known = await request(voucherPublic), missing = await request(`/api/voucher/CI-missing-${suffix}`);
      assert.equal(known.response.status, 404); assert.equal(missing.response.status, 404);
      assert.deepEqual(known.data, missing.data); assert.ok(!JSON.stringify(known.data).includes(same.primaryGuest));
    });
    await t.test("voucher eligibility requires payment, agreed deposit and operator confirmation", async () => {
      assert.equal((await request(voucherAdmin, { method: "POST", cookie: admin.cookie })).response.status, 409);
      await prisma.bookingInquiry.update({ where: { id: same.id }, data: { paymentStatus: "PARTIALLY_PAID", amountPaid: 300 } });
      assert.equal((await request(voucherAdmin, { method: "POST", cookie: admin.cookie })).response.status, 409);
      await prisma.bookingInquiry.update({ where: { id: same.id }, data: { depositAmount: 300, supplierConfirmationStatus: "REQUESTED" } });
      assert.equal((await request(voucherAdmin, { method: "POST", cookie: admin.cookie })).response.status, 409);
      assert.equal((await supplier()).response.status, 200);
      const ready = await request(voucherAdmin, { cookie: admin.cookie });
      assert.equal(ready.data.ready, true);
    });
    await t.test("voucher issuance requires a real staff session, permitted role and request origin", async () => {
      assert.equal((await request(voucherAdmin, { method: "POST" })).response.status, 401);
      assert.equal((await request(voucherAdmin, { method: "POST", headers: { "x-admin-key": process.env.ADMIN_API_KEY || "" } })).response.status, 401);
      assert.equal((await request(voucherAdmin, { method: "POST", cookie: finance.cookie })).response.status, 403);
      assert.equal((await request(voucherAdmin, { method: "POST", cookie: admin.cookie, origin: null })).response.status, 403);
      assert.equal((await request(voucherAdmin, { method: "POST", cookie: admin.cookie, origin: "https://untrusted.example.invalid" })).response.status, 403);
      assert.equal((await request(voucherAdmin, { cookie: finance.cookie })).data.canManage, false);
      await issue(operations.cookie); await issue(sales.cookie);
    });
    await t.test("only a digest is stored and grant history never returns credentials", async () => {
      const issued = await issue(); issuedToken = issued.token;
      const grant = await prisma.voucherGrant.findUniqueOrThrow({ where: { id: issued.grantId } });
      assert.equal(grant.tokenHash, digest(issuedToken)); assert.notEqual(grant.tokenHash, issuedToken);
      assert.ok(grant.expiresAt.getTime() > Date.now() + 71 * 3600000);
      assert.ok(grant.expiresAt.getTime() <= Date.now() + 72 * 3600000);
      const history = await request(voucherAdmin, { cookie: admin.cookie });
      assert.ok(!JSON.stringify(history.data).includes(issuedToken)); assertPublic(history.data);
      const activities = await prisma.bookingActivity.findMany({ where: { bookingId: same.id } });
      assert.ok(!JSON.stringify(activities).includes(issuedToken));
    });
    await t.test("valid private link yields only public voucher data with privacy headers", async () => {
      const result = await request(voucherPublic, { token: issuedToken });
      assert.equal(result.response.status, 200); assertPublic(result.data);
      assert.equal(result.data.voucher.primaryGuest, same.primaryGuest);
      assert.equal(result.data.voucher.paymentLabel, "Agreed deposit received");
      assert.equal(result.data.access, undefined);
      assert.match(result.response.headers.get("cache-control") || "", /no-store/);
      assert.equal(result.response.headers.get("referrer-policy"), "no-referrer");
      assert.match(result.response.headers.get("x-robots-tag") || "", /noindex/);
    });
    await t.test("wrong booking, malformed token and query-string credentials are rejected", async () => {
      assert.equal((await request(`/api/voucher/${first.reference}`, { token: issuedToken })).response.status, 404);
      assert.equal((await request(voucherPublic, { token: "invalid" })).response.status, 404);
      assert.equal((await request(voucherPublic, { token: randomBytes(32).toString("hex") })).response.status, 404);
      assert.equal((await request(voucherPublic + "?access=" + issuedToken)).response.status, 404);
    });
    await t.test("expired grants are denied", async () => {
      await prisma.voucherGrant.update({ where: { tokenHash: digest(issuedToken) }, data: { expiresAt: new Date(Date.now() - 1000) } });
      assert.equal((await request(voucherPublic, { token: issuedToken })).response.status, 404);
    });
    await t.test("rotation revokes prior links and retains only one active credential", async () => {
      const previous = await issue(); const next = await issue(); issuedToken = next.token;
      assert.equal((await request(voucherPublic, { token: previous.token })).response.status, 404);
      assert.equal((await request(voucherPublic, { token: next.token })).response.status, 200);
      assert.equal(await prisma.voucherGrant.count({ where: { bookingId: same.id, revokedAt: null, expiresAt: { gt: new Date() } } }), 1);
    });
    await t.test("explicit revocation is immediate and retry does not duplicate its audit", async () => {
      const revoked = await request(voucherAdmin, { method: "DELETE", cookie: operations.cookie });
      assert.equal(revoked.response.status, 200); assert.equal(revoked.data.revoked, 1);
      assert.equal((await request(voucherPublic, { token: issuedToken })).response.status, 404);
      const count = await prisma.bookingActivity.count({ where: { bookingId: same.id, type: "VOUCHER_LINK_REVOKED" } });
      assert.equal((await request(voucherAdmin, { method: "DELETE", cookie: operations.cookie })).data.revoked, 0);
      assert.equal(await prisma.bookingActivity.count({ where: { bookingId: same.id, type: "VOUCHER_LINK_REVOKED" } }), count);
    });
    await t.test("operator withdrawal permanently revokes the old voucher even after reconfirmation", async () => {
      const issued = await issue();
      assert.equal((await supplier("REQUESTED", "")).response.status, 200);
      assert.equal((await request(voucherPublic, { token: issued.token })).response.status, 404);
      assert.equal((await supplier()).response.status, 200);
      assert.equal((await request(voucherPublic, { token: issued.token })).response.status, 404);
    });
    await t.test("current refund and payment state is rechecked on each voucher read", async () => {
      const issued = await issue();
      await prisma.bookingInquiry.update({ where: { id: same.id }, data: { amountRefunded: 50, paymentStatus: "PARTIALLY_REFUNDED" } });
      assert.equal((await request(voucherPublic, { token: issued.token })).response.status, 404);
      // Reset only this synthetic fixture to exercise subsequent cancellation tests.
      await prisma.bookingInquiry.update({ where: { id: same.id }, data: { amountRefunded: 0, paymentStatus: "PARTIALLY_PAID" } });
    });
    await t.test("staff previews work without granting guests reference-only access", async () => {
      const preview = await request(voucherPublic, { cookie: finance.cookie });
      assert.equal(preview.response.status, 200); assert.equal(preview.data.access.mode, "staff");
      assert.equal(preview.data.access.canManage, false);
      assert.equal((await request(voucherPublic)).response.status, 404);
    });
    await t.test("inactive or expired staff sessions cannot issue a voucher", async () => {
      await prisma.staffUser.update({ where: { id: sales.userId }, data: { isActive: false } });
      assert.equal((await request(voucherAdmin, { method: "POST", cookie: sales.cookie })).response.status, 401);
      await prisma.staffSession.updateMany({ where: { userId: operations.userId }, data: { expiresAt: new Date(Date.now() - 1000) } });
      assert.equal((await request(voucherAdmin, { method: "POST", cookie: operations.cookie })).response.status, 401);
    });
    await t.test("cancellation revokes links and open tasks without restoring unverified supplier inventory", async () => {
      const issued = await issue();
      const result = await patch(same.id, { status: "CANCELLED" });
      assert.equal(result.response.status, 200);
      assert.equal((await request(voucherPublic, { token: issued.token })).response.status, 404);
      assert.equal(await prisma.voucherGrant.count({ where: { bookingId: same.id, revokedAt: null } }), 0);
      assert.equal(await prisma.bookingTask.count({ where: { bookingId: same.id, status: { in: ["OPEN", "IN_PROGRESS"] } } }), 0);
      assert.equal((await prisma.departure.findUniqueOrThrow({ where: { id: many.id } })).cabinsLeft, 4);
      assert.equal((await patch(same.id, { status: "CONFIRMED" })).response.status, 409);
      assert.equal((await request(`/api/admin/bookings/${same.id}/tasks`, { method: "POST", cookie: admin.cookie, body: { title: "Must not create", type: "OTHER" } })).response.status, 409);
      const task = await prisma.bookingTask.findFirstOrThrow({ where: { bookingId: same.id } });
      assert.equal((await request(`/api/admin/bookings/${same.id}/tasks/${task.id}`, { method: "PATCH", cookie: admin.cookie, body: { status: "OPEN" } })).response.status, 409);
    });
    await t.test("public voucher HTML contains no guest data and disables indexing and referrer sharing", async () => {
      const response = await fetch(base + `/voucher/${same.reference}`);
      assert.equal(response.status, 200);
      assert.equal(response.headers.get("referrer-policy"), "no-referrer");
      assert.match(response.headers.get("x-robots-tag") || "", /noindex/);
      assert.ok(!(await response.text()).includes(same.primaryGuest));
    });
  } finally {
    // No broad deletes: only synthetic IDs allocated by this test run are removed.
    if (outboxIds.length) await prisma.notificationOutbox.deleteMany({ where: { id: { in: outboxIds } } });
    if (bookingIds.length) await prisma.bookingInquiry.deleteMany({ where: { id: { in: bookingIds } } });
    if (cruiseIds.length) await prisma.cruise.deleteMany({ where: { id: { in: cruiseIds } } });
    if (userIds.length) await prisma.staffUser.deleteMany({ where: { id: { in: userIds } } });
    await prisma.$disconnect();
  }
});
