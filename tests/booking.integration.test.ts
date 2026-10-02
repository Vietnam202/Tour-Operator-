import assert from "node:assert/strict";
import { randomUUID } from "node:crypto";
import test from "node:test";
import { PrismaClient } from "@prisma/client";

const database = new URL(process.env.DATABASE_URL || "postgresql://invalid/invalid");
if (process.env.HCA_INTEGRATION_TESTS !== "1" || !["localhost", "127.0.0.1"].includes(database.hostname) || database.pathname !== "/hca_ci") {
  throw new Error("Integration tests require HCA_INTEGRATION_TESTS=1 and the disposable local hca_ci database. Never use production.");
}
const base = process.env.HCA_TEST_BASE_URL || "http://127.0.0.1:3000";
if (!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base)) throw new Error("Only a local test server is allowed.");
const prisma = new PrismaClient();
const blockedKeys = new Set(["netCost", "netCostFrom", "quotedCost", "quotedMargin", "adultCostRate", "childCostRate", "transferCost", "marginPct", "supplierId", "supplier", "internalNotes"]);
function assertPublic(value: unknown): void {
  if (!value || typeof value !== "object") return;
  for (const [key, child] of Object.entries(value)) {
    assert.ok(!blockedKeys.has(key), `Internal field leaked: ${key}`);
    assertPublic(child);
  }
}
async function request(path: string, body?: unknown) {
  const response = await fetch(base + path, body === undefined ? undefined : {
    method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body),
  });
  assert.match(response.headers.get("content-type") || "", /application\/json/, `Expected JSON from ${path}`);
  return { response, data: await response.json() };
}
function future(days = 20) { const date = new Date(); date.setUTCDate(date.getUTCDate() + days); date.setUTCHours(12, 0, 0, 0); return date; }

test("Published cruise booking against migrated PostgreSQL and the production HTTP server", async t => {
  const ids: string[] = [];
  const suffix = randomUUID();
  try {
    const cruise = await prisma.cruise.create({
      data: {
        slug: `ci-booking-${suffix}`, name: `Integration Cruise ${suffix}`, route: "CI Route", status: "PUBLISHED",
        cabins: { create: [
          { name: "Available suite", basePrice: 320, netCost: 240, capacity: 2, childRatePct: 70, singleSupplement: 80 },
          { name: "Inactive suite", basePrice: 500, capacity: 2, isActive: false },
          { name: "Zero child rate", basePrice: 320, netCost: 240, capacity: 3, childRatePct: 0 },
        ] },
        departures: { create: [
          { departureDate: future(), durationNights: 1, priceFrom: 320, netCostFrom: 250, holidaySurcharge: 25, cabinsLeft: 3 },
          { departureDate: future(21), durationNights: 1, priceFrom: 320, cabinsLeft: 0 },
          { departureDate: new Date("2020-01-01T12:00:00Z"), durationNights: 1, priceFrom: 320, cabinsLeft: 3 },
          { departureDate: future(22), durationNights: 1, priceFrom: 320, cabinsLeft: 3, isAvailable: false },
          { departureDate: future(23), durationNights: 1, priceFrom: 320, cabinsLeft: 3, currency: "EUR" },
        ] },
      }, include: { cabins: true, departures: true },
    });
    ids.push(cruise.id);
    const other = await prisma.cruise.create({ data: {
      slug: `ci-other-${suffix}`, name: "Other integration cruise", route: "CI Other", status: "PUBLISHED",
      cabins: { create: { name: "Unknown cost suite", capacity: 2, basePrice: 300 } },
      departures: { create: { departureDate: future(), durationNights: 1, priceFrom: 300, cabinsLeft: 2 } },
    }, include: { cabins: true, departures: true } });
    ids.push(other.id);
    const draft = await prisma.cruise.create({ data: {
      slug: `ci-draft-${suffix}`, name: "Unpublished integration cruise", route: "CI Draft", status: "DRAFT",
      cabins: { create: { name: "Draft suite", capacity: 2, basePrice: 100 } },
      departures: { create: { departureDate: future(), durationNights: 1, priceFrom: 100, cabinsLeft: 3 } },
    }, include: { cabins: true, departures: true } });
    ids.push(draft.id);
    const cabin = cruise.cabins.find(item => item.name === "Available suite")!;
    const departure = cruise.departures.find(item => item.netCostFrom === 250)!;
    const input = { cruiseId: cruise.id, cabinId: cabin.id, departureId: departure.id, adults: 2, children: 0, transferType: "shared" };
    const bookingInput = { ...input, primaryGuest: "Integration Guest", email: "integration@example.invalid", nationality: "Test", phone: "", specialRequests: "", consent: true };

    await t.test("public listing never exposes supplier costs or unpublished products", async () => {
      const { response, data } = await request("/api/cruises");
      assert.equal(response.status, 200); assertPublic(data);
      assert.ok(data.cruises.some((item: { id: string }) => item.id === cruise.id));
      assert.ok(!data.cruises.some((item: { id: string }) => item.id === draft.id));
      assert.match(response.headers.get("cache-control") || "", /no-store/);
    });
    await t.test("restored cruise-detail endpoint returns only public inventory", async () => {
      const { response, data } = await request(`/api/cruises/${cruise.slug}`);
      assert.equal(response.status, 200); assert.equal(data.cruise.id, cruise.id); assertPublic(data);
      assert.ok(!data.cruise.cabins.some((item: { name: string }) => item.name === "Inactive suite"));
      assert.ok(!data.cruise.departures.some((item: { id: string }) => item.id === cruise.departures.find(d => d.cabinsLeft === 0)!.id));
    });
    await t.test("unpublished and unknown cruise slugs return 404", async () => {
      assert.equal((await request(`/api/cruises/${draft.slug}`)).response.status, 404);
      assert.equal((await request(`/api/cruises/missing-${suffix}`)).response.status, 404);
    });
    await t.test("booking context uses the same safe public fields", async () => {
      const { response, data } = await request(`/api/booking-context?cruise=${cruise.slug}`);
      assert.equal(response.status, 200); assertPublic(data);
      assert.equal(data.cruise.id, cruise.id);
    });
    await t.test("quote breakdown adds up and excludes commercial costs", async () => {
      const { response, data } = await request("/api/quote", input);
      assert.equal(response.status, 200); assertPublic(data);
      assert.equal(data.quote.total, 700);
      assert.equal(data.quote.total, data.quote.passengerSubtotal + data.quote.singleSupplement + data.quote.holidaySurcharge + data.quote.transfer);
      assert.equal(data.quote.estimateOnly, true);
    });
    await t.test("invalid shapes and numeric coercion are rejected", async () => {
      for (const body of [null, [], { ...input, adults: "2" }, { ...input, adults: -1 }, { ...input, adults: 1.5 }, { ...input, transferType: "unknown" }]) {
        assert.equal((await request("/api/quote", body)).response.status, 400);
      }
    });
    await t.test("a published departure is mandatory and never silently replaced", async () => {
      assert.equal((await request("/api/quote", { ...input, departureId: null })).response.status, 400);
      assert.equal((await request("/api/quote", { ...input, departureId: "missing" })).response.status, 409);
    });
    await t.test("cross-cruise departure and cabin combinations are rejected", async () => {
      assert.equal((await request("/api/quote", { ...input, departureId: other.departures[0].id })).response.status, 409);
      assert.equal((await request("/api/quote", { ...input, cabinId: other.cabins[0].id })).response.status, 409);
    });
    await t.test("sold-out, past and disabled departures cannot be quoted", async () => {
      const unavailable = cruise.departures.filter(item => item.cabinsLeft === 0 || !item.isAvailable || item.departureDate.getTime() < Date.now());
      for (const item of unavailable) assert.equal((await request("/api/quote", { ...input, departureId: item.id })).response.status, 409);
    });
    await t.test("unpublished cabins, draft cruises and oversized parties are rejected", async () => {
      assert.equal((await request("/api/quote", { ...input, cabinId: cruise.cabins.find(item => !item.isActive)!.id })).response.status, 409);
      assert.equal((await request("/api/quote", { ...input, cruiseId: draft.id, cabinId: draft.cabins[0].id, departureId: draft.departures[0].id })).response.status, 409);
      assert.equal((await request("/api/quote", { ...input, adults: 3 })).response.status, 409);
    });
    await t.test("mismatched date and duration are rejected", async () => {
      assert.equal((await request("/api/quote", { ...input, departureDate: "2020-01-01" })).response.status, 409);
      assert.equal((await request("/api/quote", { ...input, durationNights: 2 })).response.status, 409);
      assert.equal((await request("/api/quote", { ...input, departureDate: "2026-02-31" })).response.status, 400);
    });
    await t.test("currency mismatches require manual review", async () => {
      assert.equal((await request("/api/quote", { ...input, departureId: cruise.departures.find(item => item.currency === "EUR")!.id })).response.status, 409);
    });
    await t.test("single supplements are counted once and zero child rates are preserved", async () => {
      const solo = await request("/api/quote", { ...input, adults: 1, transferType: "none" });
      assert.equal(solo.response.status, 200); assert.equal(solo.data.quote.total, 425);
      assert.equal(solo.data.quote.passengerSubtotal, 320); assert.equal(solo.data.quote.singleSupplement, 80);
      const family = await request("/api/quote", { ...input, cabinId: cruise.cabins.find(item => item.name === "Zero child rate")!.id, adults: 1, children: 1, transferType: "none" });
      assert.equal(family.response.status, 200); assert.equal(family.data.quote.childRate, 0);
    });
    await t.test("booking recalculates tampered totals and persists request plus consent audit", async () => {
      const { response, data } = await request("/api/bookings", { ...bookingInput, estimatedTotal: 1, quotedCost: 1, quotedMargin: 999999, cruiseName: "Injected name" });
      assert.equal(response.status, 201); assertPublic(data); assert.equal(data.quote.total, 700);
      const saved = await prisma.bookingInquiry.findUniqueOrThrow({ where: { reference: data.reference }, include: { activities: true } });
      assert.equal(saved.estimatedTotal, 700); assert.equal(saved.cruiseName, cruise.name);
      assert.equal(saved.departureId, departure.id); assert.equal(saved.departureDate.toISOString(), departure.departureDate.toISOString());
      assert.equal(saved.inventoryCommitted, false); assert.equal(saved.status, "NEW");
      assert.equal(saved.activities.filter(item => item.type === "BOOKING_CREATED").length, 1);
      const outbox = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey: `booking.created:${saved.id}` } });
      assert.equal(outbox.eventType, "booking.created");
      assert.equal(outbox.aggregateId, saved.id);
      assert.equal(outbox.status, "PENDING");
      const payloadText = JSON.stringify(outbox.payload);
      assert.ok(!/token|secret|session|authorization|cookie/i.test(payloadText));
      assert.equal((await prisma.departure.findUniqueOrThrow({ where: { id: departure.id } })).cabinsLeft, 3);
    });
    await t.test("missing consent and invalid email do not create bookings", async () => {
      const before = await prisma.bookingInquiry.count({ where: { cruiseId: cruise.id } });
      assert.equal((await request("/api/bookings", { ...bookingInput, consent: false })).response.status, 400);
      assert.equal((await request("/api/bookings", { ...bookingInput, email: "invalid" })).response.status, 400);
      assert.equal(await prisma.bookingInquiry.count({ where: { cruiseId: cruise.id } }), before);
    });
    await t.test("unknown contract cost is saved as null, not zero", async () => {
      const { response, data } = await request("/api/bookings", { ...bookingInput, cruiseId: other.id, cabinId: other.cabins[0].id, departureId: other.departures[0].id, transferType: "none" });
      assert.equal(response.status, 201);
      const saved = await prisma.bookingInquiry.findUniqueOrThrow({ where: { reference: data.reference } });
      assert.equal(saved.quotedCost, null); assert.equal(saved.quotedMargin, null);
    });
    await t.test("production pages render database inventory and working filters", async () => {
      const listing = await fetch(`${base}/cruises?q=${encodeURIComponent(suffix)}&route=CI%20Route`);
      assert.equal(listing.status, 200); assert.ok((await listing.text()).includes(cruise.name));
      const filtered = await fetch(`${base}/cruises?q=${encodeURIComponent(suffix)}&maxPrice=1`);
      assert.ok((await filtered.text()).includes("No cruises match this selection"));
      const detail = await fetch(`${base}/cruises/${cruise.slug}`);
      assert.equal(detail.status, 200); assert.ok((await detail.text()).includes(cruise.name));
    });
    await t.test("unauthenticated staff and malformed public requests are rejected", async () => {
      assert.equal((await request("/api/admin/bookings")).response.status, 401);
      const bad = await fetch(base + "/api/quote", { method: "POST", headers: { "Content-Type": "application/json" }, body: "{broken" });
      assert.equal(bad.status, 400); assert.deepEqual(await bad.json(), { error: "Invalid JSON request." });
    });
  } finally {
    // Remove only records owned by this test run, never unrelated records.
    if (ids.length) {
      await prisma.bookingInquiry.deleteMany({ where: { cruiseId: { in: ids } } });
      await prisma.cruise.deleteMany({ where: { id: { in: ids } } });
    }
    await prisma.$disconnect();
  }
});
