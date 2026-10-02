import assert from "node:assert/strict";
import { createHash, randomBytes, randomUUID } from "node:crypto";
import test from "node:test";
import { PrismaClient } from "@prisma/client";
import { isTrustedStaffOrigin } from "../lib/trusted-staff-origin";

const database = new URL(process.env.DATABASE_URL || "postgresql://invalid/invalid");
if (process.env.HCA_INTEGRATION_TESTS !== "1" || !["localhost", "127.0.0.1"].includes(database.hostname) || database.pathname !== "/hca_ci") {
  throw new Error("Policy tests require the disposable local hca_ci database and HCA_INTEGRATION_TESTS=1.");
}
const base = process.env.HCA_TEST_BASE_URL || "http://127.0.0.1:3000";
if (!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base)) throw new Error("A local HTTP test server is required.");

test("Trusted staff origins are explicit and independent of internal request URLs", async t => {
  const configuration = { siteUrl: "https://staff.example.invalid", additionalOrigins: "http://127.0.0.1:3000" };
  const make = (origin?: string, extra: Record<string, string> = {}) => new Request("http://internal-proxy.invalid:8080/voucher", {
    method: "POST", headers: { ...(origin === undefined ? {} : { Origin: origin }), ...extra },
  });
  await t.test("canonical origin works behind an internal proxy", () => {
    assert.equal(isTrustedStaffOrigin(make("https://staff.example.invalid"), configuration), true);
  });
  await t.test("the explicit loopback testing origin is accepted", () => {
    assert.equal(isTrustedStaffOrigin(make("http://127.0.0.1:3000"), configuration), true);
  });
  await t.test("unlisted, null, missing, prefix-match and malformed origins are denied", () => {
    for (const origin of [undefined, "null", "https://staff.example.invalid.evil.invalid", "https://evil.invalid", "http://staff.example.invalid",
      "https://staff.example.invalid:8443", "https://staff.example.invalid/path", "https://staff.example.invalid, https://evil.invalid"]) {
      assert.equal(isTrustedStaffOrigin(make(origin), configuration), false);
    }
  });
  await t.test("Host and Forwarded headers cannot grant an unlisted origin", () => {
    assert.equal(isTrustedStaffOrigin(make("https://evil.invalid", { Host: "evil.invalid", "X-Forwarded-Host": "evil.invalid", "X-Forwarded-Proto": "https" }), configuration), false);
  });
  await t.test("a cross-site fetch remains blocked even with a matching Origin", () => {
    assert.equal(isTrustedStaffOrigin(make("https://staff.example.invalid", { "Sec-Fetch-Site": "cross-site" }), configuration), false);
  });
  await t.test("unconfigured origins fail closed and insecure non-loopback exceptions are ignored", () => {
    assert.equal(isTrustedStaffOrigin(make("https://staff.example.invalid"), {}), false);
    assert.equal(isTrustedStaffOrigin(make("http://evil.invalid"), { additionalOrigins: "http://evil.invalid" }), false);
  });
});

test("Legacy operator confirmation without a reservation reference cannot unlock a voucher", async () => {
  const prisma = new PrismaClient();
  let userId: string | undefined, bookingId: string | undefined;
  try {
    const suffix = randomUUID();
    const user = await prisma.staffUser.create({ data: { email: `voucher-policy-${suffix}@example.invalid`, name: "Policy test admin", role: "ADMIN", passwordHash: "session-only-test-fixture" } });
    userId = user.id;
    const secret = randomBytes(32).toString("hex");
    await prisma.staffSession.create({ data: { userId, tokenHash: createHash("sha256").update(secret).digest("hex"), expiresAt: new Date(Date.now() + 3600000) } });
    const booking = await prisma.bookingInquiry.create({ data: {
      reference: `CI-POLICY-${suffix}`, cruiseName: "Policy fixture", primaryGuest: "Synthetic policy guest", email: "policy@example.invalid",
      departureDate: new Date(Date.now() + 20 * 86400000), durationNights: 1, adults: 2,
      status: "CONFIRMED", inventoryCommitted: true, supplierConfirmationStatus: "CONFIRMED", supplierConfirmationRef: null,
      estimatedTotal: 1000, amountPaid: 1000, paymentStatus: "PAID",
    } });
    bookingId = booking.id;
    const response = await fetch(`${base}/api/admin/bookings/${booking.id}/voucher`, {
      method: "POST", headers: { Origin: base, Cookie: `hca_staff_session=${secret}` }, signal: AbortSignal.timeout(15000),
    });
    assert.equal(response.status, 409);
    assert.match((await response.json()).error, /reservation reference/);
    assert.equal(await prisma.voucherGrant.count({ where: { bookingId } }), 0);
  } finally {
    if (bookingId) await prisma.bookingInquiry.delete({ where: { id: bookingId } });
    if (userId) await prisma.staffUser.delete({ where: { id: userId } });
    await prisma.$disconnect();
  }
});
