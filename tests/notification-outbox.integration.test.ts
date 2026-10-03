import assert from "node:assert/strict";
import crypto from "node:crypto";
import { createServer } from "node:http";
import test from "node:test";
import { PrismaClient } from "@prisma/client";
import { enqueueNotificationTx, getNotificationOutboxStatus, processNotificationOutbox, pruneDeliveredNotifications, requeueFailedNotifications } from "../lib/notification-outbox";

const database = new URL(process.env.DATABASE_URL || "postgresql://invalid/invalid");
if (process.env.HCA_INTEGRATION_TESTS !== "1" || !["localhost", "127.0.0.1"].includes(database.hostname) || database.pathname !== "/hca_ci") {
  throw new Error("Integration tests require HCA_INTEGRATION_TESTS=1 and the disposable local hca_ci database. Never use production.");
}

const prisma = new PrismaClient();

test("durable notification outbox persists, retries without duplicate rows, delivers once, and replay is idempotent", async () => {
  let targetStatus = 503;
  let targetRetryAfter: string | null = null;
  let requests = 0;
  const receivedIds: string[] = [];
  const receivedBodies: string[] = [];
  const receivedSignatures: string[] = [];
  const receivedTimestamps: string[] = [];
  const server = createServer((request, response) => {
    requests += 1;
    receivedIds.push(String(request.headers["x-notification-id"] || ""));
    receivedSignatures.push(String(request.headers["x-webhook-signature"] || ""));
    receivedTimestamps.push(String(request.headers["x-webhook-timestamp"] || ""));
    const chunks: Buffer[] = [];
    request.on("data", chunk => chunks.push(Buffer.from(chunk)));
    request.on("end", () => {
      receivedBodies.push(Buffer.concat(chunks).toString("utf8"));
      response.statusCode = targetStatus;
      if (targetRetryAfter) response.setHeader("retry-after", targetRetryAfter);
      response.end();
    });
  });
  await new Promise<void>(resolve => server.listen(0, "127.0.0.1", resolve));
  const address = server.address();
  assert.ok(address && typeof address !== "string");
  const previousUrl = process.env.BOOKING_WEBHOOK_URL;
  const previousSecret = process.env.BOOKING_WEBHOOK_SECRET;
  const webhookSecret = "ci-webhook-secret-do-not-persist";
  process.env.BOOKING_WEBHOOK_URL = `http://127.0.0.1:${address.port}`;
  process.env.BOOKING_WEBHOOK_SECRET = webhookSecret;

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
    assert.ok(failedRun.failed >= 1);
    const afterFailure = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey } });
    assert.equal(afterFailure.status, "PENDING");
    assert.equal(afterFailure.attemptCount, 1);
    assert.ok(afterFailure.lastError);
    assert.equal(afterFailure.deliveredAt, null);
    assert.equal(receivedIds.filter(id => id === first.id).length, 1);

    targetStatus = 204;
    const successRun = await processNotificationOutbox({ client: prisma, baseDelayMs: 0, workerId: "ci-worker-success" });
    assert.ok(successRun.delivered >= 1);
    const delivered = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey } });
    assert.equal(delivered.status, "DELIVERED");
    assert.equal(delivered.attemptCount, 2);
    assert.ok(delivered.deliveredAt);
    assert.equal(receivedIds.filter(id => id === first.id).length, 2);
    const matchingIndexes = receivedIds.map((id, index) => id === first.id ? index : -1).filter(index => index >= 0);
    for (const index of matchingIndexes) {
      const body = receivedBodies[index];
      const timestamp = receivedTimestamps[index];
      const signature = receivedSignatures[index];
      assert.ok(body);
      assert.match(timestamp, /^\d+$/);
      assert.match(signature, /^v1=[0-9a-f]{64}$/);
      const expected = crypto.createHmac("sha256", webhookSecret).update(`${timestamp}.${body}`).digest("hex");
      assert.equal(signature, `v1=${expected}`);
      assert.equal(body.includes(webhookSecret), false);
    }
    const persistedPayload = JSON.stringify(delivered.payload);
    assert.equal(persistedPayload.includes(webhookSecret), false);

    const replay = await processNotificationOutbox({ client: prisma, baseDelayMs: 0, workerId: "ci-worker-replay" });
    assert.ok(replay.claimed >= 0);
    assert.equal(receivedIds.filter(id => id === first.id).length, 2);
    assert.equal(await prisma.notificationOutbox.count({ where: { idempotencyKey } }), 1);

    const terminalKey = `ci:terminal:${crypto.randomUUID()}`;
    const terminal = await prisma.$transaction(tx => enqueueNotificationTx(tx, {
      eventType: "booking.created",
      idempotencyKey: terminalKey,
      aggregateType: "BookingInquiry",
      aggregateId: "ci-terminal-booking",
      payload: { reference: "CI-TERMINAL" },
    }));
    targetStatus = 503;
    await processNotificationOutbox({ client: prisma, baseDelayMs: 0, maxAttempts: 1, workerId: "ci-worker-terminal" });
    const terminalAfter = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey: terminalKey } });
    assert.equal(terminalAfter.id, terminal.id);
    assert.equal(terminalAfter.status, "FAILED");
    assert.equal(terminalAfter.attemptCount, 1);
    assert.ok(terminalAfter.lastError);
    assert.equal(terminalAfter.deliveredAt, null);

    const failedKey = `ci:failed:${crypto.randomUUID()}`;
    const failed = await prisma.notificationOutbox.create({
      data: {
        eventType: "booking.created",
        idempotencyKey: failedKey,
        payload: { reference: "CI-FAILED" },
        status: "FAILED",
        attemptCount: 10,
        lastAttemptAt: new Date(),
        lastError: "terminal failure",
      },
    });
    const deliveredKey = `ci:delivered:${crypto.randomUUID()}`;
    const deliveredControl = await prisma.notificationOutbox.create({
      data: {
        eventType: "booking.created",
        idempotencyKey: deliveredKey,
        payload: { reference: "CI-DELIVERED" },
        status: "DELIVERED",
        attemptCount: 1,
        deliveredAt: new Date(),
      },
    });
    const selectiveAKey = `ci:failed-selective-a:${crypto.randomUUID()}`;
    const selectiveBKey = `ci:failed-selective-b:${crypto.randomUUID()}`;
    const selectiveA = await prisma.notificationOutbox.create({
      data: {
        eventType: "booking.created",
        idempotencyKey: selectiveAKey,
        payload: { reference: "CI-FAILED-SELECTIVE-A" },
        status: "FAILED",
        attemptCount: 4,
        lastAttemptAt: new Date(),
        lastError: "selective-a failure",
      },
    });
    const selectiveB = await prisma.notificationOutbox.create({
      data: {
        eventType: "payment.updated",
        idempotencyKey: selectiveBKey,
        payload: { reference: "CI-FAILED-SELECTIVE-B" },
        status: "FAILED",
        attemptCount: 5,
        lastAttemptAt: new Date(),
        lastError: "selective-b failure",
      },
    });

    const byId = await requeueFailedNotifications(prisma, { id: selectiveA.id });
    assert.equal(byId.requeued, 1);
    const selectiveAAfter = await prisma.notificationOutbox.findUniqueOrThrow({ where: { id: selectiveA.id } });
    const selectiveBStillFailed = await prisma.notificationOutbox.findUniqueOrThrow({ where: { id: selectiveB.id } });
    assert.equal(selectiveAAfter.status, "PENDING");
    assert.equal(selectiveAAfter.attemptCount, 0);
    assert.equal(selectiveAAfter.lastError, null);
    assert.equal(selectiveAAfter.idempotencyKey, selectiveAKey);
    assert.equal(selectiveBStillFailed.status, "FAILED");
    assert.equal(selectiveBStillFailed.attemptCount, 5);
    assert.equal(selectiveBStillFailed.lastError, "selective-b failure");

    const byKey = await requeueFailedNotifications(prisma, { idempotencyKey: selectiveBKey });
    assert.equal(byKey.requeued, 1);
    const selectiveBAfter = await prisma.notificationOutbox.findUniqueOrThrow({ where: { id: selectiveB.id } });
    assert.equal(selectiveBAfter.status, "PENDING");
    assert.equal(selectiveBAfter.attemptCount, 0);
    assert.equal(selectiveBAfter.lastError, null);
    assert.equal(selectiveBAfter.idempotencyKey, selectiveBKey);

    assert.equal((await requeueFailedNotifications(prisma, { id: selectiveA.id })).requeued, 0);
    assert.equal((await requeueFailedNotifications(prisma, { idempotencyKey: "ci:does-not-exist" })).requeued, 0);
    await assert.rejects(
      requeueFailedNotifications(prisma, { id: selectiveA.id, idempotencyKey: selectiveAKey }),
      /Specify only one failed-notification selector/,
    );

    const requeued = await requeueFailedNotifications(prisma);
    assert.ok(requeued.requeued >= 1);
    const failedAfter = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey: failedKey } });
    assert.equal(failedAfter.id, failed.id);
    assert.equal(failedAfter.idempotencyKey, failedKey);
    assert.equal(failedAfter.status, "PENDING");
    assert.equal(failedAfter.attemptCount, 0);
    assert.equal(failedAfter.lastError, null);
    const deliveredAfter = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey: deliveredKey } });
    assert.equal(deliveredAfter.id, deliveredControl.id);
    assert.equal(deliveredAfter.status, "DELIVERED");
    assert.ok(deliveredAfter.deliveredAt);

    const status = await getNotificationOutboxStatus(prisma);
    assert.ok(status.counts.DELIVERED >= 2);
    assert.ok(status.counts.PENDING >= 1);
    assert.ok(status.duePending >= 1);
    assert.equal(status.staleProcessing, 0);
    assert.ok(status.oldestPending);
    assert.ok(status.oldestPending.ageSeconds >= 0);

    const unsafeUrlKey = `ci:unsafe-url:${crypto.randomUUID()}`;
    await prisma.$transaction(tx => enqueueNotificationTx(tx, {
      eventType: "booking.created",
      idempotencyKey: unsafeUrlKey,
      payload: { reference: "CI-UNSAFE-URL" },
    }));
    process.env.BOOKING_WEBHOOK_URL = "http://example.com/webhook";
    await assert.rejects(
      processNotificationOutbox({ client: prisma, baseDelayMs: 0, maxAttempts: 1, workerId: "ci-worker-insecure-url" }),
      /must use HTTPS outside localhost/,
    );
    const insecureUrlEvent = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey: unsafeUrlKey } });
    assert.equal(insecureUrlEvent.status, "PENDING");
    assert.equal(insecureUrlEvent.attemptCount, 0);
    assert.equal(insecureUrlEvent.lastError, null);

    const credentialUrlKey = `ci:credential-url:${crypto.randomUUID()}`;
    await prisma.$transaction(tx => enqueueNotificationTx(tx, {
      eventType: "booking.created",
      idempotencyKey: credentialUrlKey,
      payload: { reference: "CI-CREDENTIAL-URL" },
    }));
    process.env.BOOKING_WEBHOOK_URL = "https://user:password@example.com/webhook";
    await assert.rejects(
      processNotificationOutbox({ client: prisma, baseDelayMs: 0, maxAttempts: 1, workerId: "ci-worker-credential-url" }),
      /must not contain embedded credentials/,
    );
    const credentialUrlEvent = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey: credentialUrlKey } });
    assert.equal(credentialUrlEvent.status, "PENDING");
    assert.equal(credentialUrlEvent.attemptCount, 0);
    assert.equal(credentialUrlEvent.lastError, null);

    process.env.BOOKING_WEBHOOK_URL = `http://127.0.0.1:${address.port}`;

    const numericRetryKey = `ci:retry-after-seconds:${crypto.randomUUID()}`;
    await prisma.$transaction(tx => enqueueNotificationTx(tx, {
      eventType: "booking.created",
      idempotencyKey: numericRetryKey,
      payload: { reference: "CI-RETRY-AFTER-SECONDS" },
    }));
    targetStatus = 429;
    targetRetryAfter = "120";
    const numericRetryStartedAt = Date.now();
    await processNotificationOutbox({ client: prisma, baseDelayMs: 1_000, workerId: "ci-worker-retry-after-seconds" });
    const numericRetryEvent = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey: numericRetryKey } });
    assert.equal(numericRetryEvent.status, "PENDING");
    assert.equal(numericRetryEvent.attemptCount, 1);
    assert.ok(numericRetryEvent.nextAttemptAt.getTime() >= numericRetryStartedAt + 120_000);
    assert.ok(numericRetryEvent.nextAttemptAt.getTime() <= Date.now() + 121_000);

    const dateRetryKey = `ci:retry-after-date:${crypto.randomUUID()}`;
    await prisma.$transaction(tx => enqueueNotificationTx(tx, {
      eventType: "payment.updated",
      idempotencyKey: dateRetryKey,
      payload: { reference: "CI-RETRY-AFTER-DATE" },
    }));
    targetStatus = 503;
    const retryDate = new Date(Date.now() + 180_000);
    targetRetryAfter = retryDate.toUTCString();
    await processNotificationOutbox({ client: prisma, baseDelayMs: 1_000, workerId: "ci-worker-retry-after-date" });
    const dateRetryEvent = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey: dateRetryKey } });
    assert.equal(dateRetryEvent.status, "PENDING");
    assert.equal(dateRetryEvent.attemptCount, 1);
    assert.ok(Math.abs(dateRetryEvent.nextAttemptAt.getTime() - Date.parse(targetRetryAfter)) < 2_000);

    const invalidRetryKey = `ci:retry-after-invalid:${crypto.randomUUID()}`;
    const invalidRetryEventCreated = await prisma.$transaction(tx => enqueueNotificationTx(tx, {
      eventType: "booking.confirmed",
      idempotencyKey: invalidRetryKey,
      payload: { reference: "CI-RETRY-AFTER-INVALID" },
    }));
    targetStatus = 503;
    targetRetryAfter = "not-a-valid-retry-after";
    const invalidRetryStartedAt = Date.now();
    await processNotificationOutbox({ client: prisma, baseDelayMs: 1_000, workerId: "ci-worker-retry-after-invalid" });
    const invalidRetryEvent = await prisma.notificationOutbox.findUniqueOrThrow({ where: { idempotencyKey: invalidRetryKey } });
    assert.equal(invalidRetryEvent.status, "PENDING");
    assert.equal(invalidRetryEvent.attemptCount, 1);
    const digest = crypto.createHash("sha256").update(`${invalidRetryEventCreated.id}:1`).digest();
    const expectedFraction = digest.readUInt32BE(0) / 0xffffffff;
    const expectedDelay = 1_000 + Math.floor(1_000 * 0.2 * expectedFraction);
    assert.ok(invalidRetryEvent.nextAttemptAt.getTime() >= invalidRetryStartedAt + expectedDelay);
    assert.ok(invalidRetryEvent.nextAttemptAt.getTime() <= Date.now() + expectedDelay + 250);

    targetRetryAfter = null;
    targetStatus = 204;

    const pruneOldKey = `ci:prune-old:${crypto.randomUUID()}`;
    const pruneRecentKey = `ci:prune-recent:${crypto.randomUUID()}`;
    const pruneFailedKey = `ci:prune-failed:${crypto.randomUUID()}`;
    const oldDeliveredAt = new Date(Date.now() - 40 * 24 * 60 * 60 * 1000);
    await prisma.notificationOutbox.createMany({
      data: [
        {
          eventType: "booking.created",
          idempotencyKey: pruneOldKey,
          payload: { reference: "CI-PRUNE-OLD" },
          status: "DELIVERED",
          deliveredAt: oldDeliveredAt,
          nextAttemptAt: oldDeliveredAt,
        },
        {
          eventType: "booking.created",
          idempotencyKey: pruneRecentKey,
          payload: { reference: "CI-PRUNE-RECENT" },
          status: "DELIVERED",
          deliveredAt: new Date(),
          nextAttemptAt: new Date(),
        },
        {
          eventType: "booking.created",
          idempotencyKey: pruneFailedKey,
          payload: { reference: "CI-PRUNE-FAILED" },
          status: "FAILED",
          deliveredAt: oldDeliveredAt,
          nextAttemptAt: oldDeliveredAt,
        },
      ],
    });
    const pruneResult = await pruneDeliveredNotifications(prisma, 30);
    assert.equal(pruneResult.deleted, 1);
    assert.equal(await prisma.notificationOutbox.findUnique({ where: { idempotencyKey: pruneOldKey } }), null);
    assert.ok(await prisma.notificationOutbox.findUnique({ where: { idempotencyKey: pruneRecentKey } }));
    assert.ok(await prisma.notificationOutbox.findUnique({ where: { idempotencyKey: pruneFailedKey } }));

    await assert.rejects(
      prisma.$transaction(tx => enqueueNotificationTx(tx, {
        eventType: "payment.requested",
        idempotencyKey: `ci:unsafe:${crypto.randomUUID()}`,
        payload: { sessionToken: "must-not-persist" },
      })),
      /forbidden key/,
    );
  } finally {
    await prisma.notificationOutbox.deleteMany({ where: { idempotencyKey: { startsWith: "ci:" } } });
    if (previousUrl === undefined) delete process.env.BOOKING_WEBHOOK_URL;
    else process.env.BOOKING_WEBHOOK_URL = previousUrl;
    if (previousSecret === undefined) delete process.env.BOOKING_WEBHOOK_SECRET;
    else process.env.BOOKING_WEBHOOK_SECRET = previousSecret;
    await new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
    await prisma.$disconnect();
  }
});
