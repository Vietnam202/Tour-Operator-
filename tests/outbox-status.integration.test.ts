import assert from "node:assert/strict";
import { createHash, randomBytes, randomUUID } from "node:crypto";
import test from "node:test";
import { PrismaClient } from "@prisma/client";

const database = new URL(process.env.DATABASE_URL || "postgresql://invalid/invalid");
if (process.env.HCA_INTEGRATION_TESTS !== "1" || !["localhost", "127.0.0.1"].includes(database.hostname) || database.pathname !== "/hca_ci") {
  throw new Error("Outbox status integration tests require the disposable local hca_ci database and HCA_INTEGRATION_TESTS=1.");
}
const base = process.env.HCA_TEST_BASE_URL || "http://127.0.0.1:3000";
if (!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base)) throw new Error("Only a local test HTTP server is allowed.");
const prisma = new PrismaClient();

test("protected outbox status exposes operational aggregates without event data", async () => {
  const suffix = randomUUID();
  const token = randomBytes(32).toString("hex");
  const user = await prisma.staffUser.create({
    data: {
      email: `outbox-status-${suffix}@example.invalid`,
      name: "Synthetic Outbox Observer",
      role: "OPERATIONS",
      passwordHash: "fixture-only:not-used",
    },
  });
  await prisma.staffSession.create({
    data: {
      userId: user.id,
      tokenHash: createHash("sha256").update(token).digest("hex"),
      expiresAt: new Date(Date.now() + 3600000),
    },
  });

  const ids = [randomUUID(), randomUUID(), randomUUID()];
  const now = Date.now();
  await prisma.notificationOutbox.createMany({
    data: [
      {
        id: ids[0],
        eventType: "booking.created",
        idempotencyKey: `ci:status:pending:${suffix}`,
        payload: { marker: "must-not-leak-pending" },
        status: "PENDING",
        nextAttemptAt: new Date(now - 60000),
        lastError: "must-not-leak-error",
        createdAt: new Date(now - 120000),
        updatedAt: new Date(now - 120000),
      },
      {
        id: ids[1],
        eventType: "payment.updated",
        idempotencyKey: `ci:status:failed:${suffix}`,
        payload: { marker: "must-not-leak-failed" },
        status: "FAILED",
        nextAttemptAt: new Date(now - 60000),
        lastError: "must-not-leak-failed-error",
      },
      {
        id: ids[2],
        eventType: "booking.confirmed",
        idempotencyKey: `ci:status:processing:${suffix}`,
        payload: { marker: "must-not-leak-processing" },
        status: "PROCESSING",
        nextAttemptAt: new Date(now - 60000),
        lockedAt: new Date(now - 10 * 60 * 1000),
        lockedBy: "ci-worker-must-not-leak",
      },
    ],
  });

  try {
    const unauthorized = await fetch(base + "/api/admin/notifications/outbox-status");
    assert.equal(unauthorized.status, 401);

    const response = await fetch(base + "/api/admin/notifications/outbox-status", {
      headers: { Cookie: "hca_staff_session=" + token },
      signal: AbortSignal.timeout(20000),
    });
    assert.equal(response.status, 200);
    assert.match(response.headers.get("cache-control") || "", /no-store/);
    assert.match(response.headers.get("vary") || "", /Cookie/i);

    const data = await response.json();
    assert.equal(typeof data.counts, "object");
    assert.ok(data.counts.PENDING >= 1);
    assert.ok(data.counts.FAILED >= 1);
    assert.ok(data.counts.PROCESSING >= 1);
    assert.ok(data.duePending >= 1);
    assert.ok(data.staleProcessing >= 1);
    assert.ok(data.oldestPendingAgeSeconds >= 0);
    assert.ok(Number.isFinite(Date.parse(data.generatedAt)));
    assert.deepEqual(Object.keys(data).sort(), ["counts", "duePending", "generatedAt", "oldestPendingAgeSeconds", "staleProcessing"].sort());

    const serialized = JSON.stringify(data);
    for (const forbidden of [
      "must-not-leak-pending",
      "must-not-leak-error",
      "must-not-leak-failed",
      "must-not-leak-failed-error",
      "must-not-leak-processing",
      "ci-worker-must-not-leak",
      ids[0],
      ids[1],
      ids[2],
    ]) assert.ok(!serialized.includes(forbidden), `Sensitive event detail leaked: ${forbidden}`);
  } finally {
    await prisma.notificationOutbox.deleteMany({ where: { id: { in: ids } } });
    await prisma.staffSession.deleteMany({ where: { userId: user.id } });
    await prisma.staffUser.delete({ where: { id: user.id } });
    await prisma.$disconnect();
  }
});
