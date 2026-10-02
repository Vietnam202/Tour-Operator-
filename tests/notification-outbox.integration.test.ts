import assert from "node:assert/strict";
import { createServer } from "node:http";
import test from "node:test";
import { PrismaClient } from "@prisma/client";
import { enqueueNotificationTx, processNotificationOutbox } from "../lib/notification-outbox";

const database = new URL(process.env.DATABASE_URL || "postgresql://invalid/invalid");
if (process.env.HCA_INTEGRATION_TESTS !== "1" || !["localhost", "127.0.0.1"].includes(database.hostname) || database.pathname !== "/hca_ci") {
  throw new Error("Integration tests require HCA_INTEGRATION_TESTS=1 and the disposable local hca_ci database. Never use production.");
}

const prisma = new PrismaClient();

test("durable notification outbox persists, retries without duplicate rows, delivers once, and replay is idempotent", async () => {
  let targetStatus = 503;
  let requests = 0;
  const receivedIds: string[] = [];
  const server = createServer((request, response) => {
    requests += 1;
    receivedIds.push(String(request.headers["x-notification-id"] || ""));
    response.statusCode = targetStatus;
    response.end();
  });
  await new Promise<void>(resolve => server.listen(0, "127.0.0.1", resolve));
  const address = server.address();
  assert.ok(address && typeof address !== "string");
  const previousUrl = process.env.BOOKING_WEBHOOK_URL;
  process.env.BOOKING_WEBHOOK_URL = `http://127.0.0.1:${address.port}`;

  const idempotencyKey = `ci:booking.created:${crypto.randomUUID()}`;
  try {
    const first = await prisma.$transaction(tx => enqueueNotificationTx(tx, {
      eventType: "booking.created",
      idempotencyKey,
      aggregateType: "BookingInquiry",
      aggregateId: "ci-booking",
      payload: { reference: "CI-REF", email: "guest@example.invalid" },
    }));
    const duplicate = await prisma.$transaction(tx => enqueueNotificationTx(tx, {
      eventType: "booking.created",
      idempotencyKey,
      aggregateType: "BookingInquiry",
      aggregateId: "ci-booking",
      payload: { reference: "CI-REF", email: "guest@example.invalid" },
    }));
    assert.equal(first.id, duplicate.id);
    assert.equal(await prisma.notificationOutbox.count({ where: { idempotencyKey } }), 1);

    const failedRun = await processNotificationOutbox({ client: prisma, baseDelayMs: 0, workerId: "ci-worker-fail" });
    assert.equal(failedRun.failed, 1);
    const afterFailure = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey } });
    assert.equal(afterFailure.status, "PENDING");
    assert.equal(afterFailure.attemptCount, 1);
    assert.ok(afterFailure.lastError);
    assert.equal(afterFailure.deliveredAt, null);
    assert.equal(requests, 1);

    targetStatus = 204;
    const successRun = await processNotificationOutbox({ client: prisma, baseDelayMs: 0, workerId: "ci-worker-success" });
    assert.equal(successRun.delivered, 1);
    const delivered = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey } });
    assert.equal(delivered.status, "DELIVERED");
    assert.equal(delivered.attemptCount, 2);
    assert.ok(delivered.deliveredAt);
    assert.equal(requests, 2);
    assert.deepEqual(receivedIds, [first.id, first.id]);

    const replay = await processNotificationOutbox({ client: prisma, baseDelayMs: 0, workerId: "ci-worker-replay" });
    assert.equal(replay.claimed, 0);
    assert.equal(requests, 2);
    assert.equal(await prisma.notificationOutbox.count({ where: { idempotencyKey } }), 1);

    await assert.rejects(
      prisma.$transaction(tx => enqueueNotificationTx(tx, {
        eventType: "payment.requested",
        idempotencyKey: `ci:unsafe:${crypto.randomUUID()}`,
        payload: { sessionToken: "must-not-persist" },
      })),
      /forbidden key/,
    );
  } finally {
    await prisma.notificationOutbox.deleteMany({ where: { idempotencyKey } });
    if (previousUrl === undefined) delete process.env.BOOKING_WEBHOOK_URL;
    else process.env.BOOKING_WEBHOOK_URL = previousUrl;
    await new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
    await prisma.$disconnect();
  }
});
