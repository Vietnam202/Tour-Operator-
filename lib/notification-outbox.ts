import { Prisma, PrismaClient } from "@prisma/client";
import { prisma } from "@/lib/prisma";

export const notificationEvents = [
  "booking.created",
  "booking.confirmed",
  "payment.requested",
  "payment.updated",
] as const;
export type NotificationEvent = (typeof notificationEvents)[number];

const forbiddenKey = /(secret|token|session|password|authorization|cookie)/i;

function assertSafePayload(value: unknown, path = "payload"): void {
  if (!value || typeof value !== "object") return;
  if (Array.isArray(value)) {
    value.forEach((item, index) => assertSafePayload(item, `${path}[${index}]`));
    return;
  }
  for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
    if (forbiddenKey.test(key)) throw new Error(`Notification payload contains forbidden key at ${path}.${key}`);
    assertSafePayload(child, `${path}.${key}`);
  }
}

export async function enqueueNotificationTx(
  tx: Prisma.TransactionClient,
  input: {
    eventType: NotificationEvent;
    idempotencyKey: string;
    aggregateType?: string;
    aggregateId?: string;
    payload: Prisma.InputJsonValue;
  },
) {
  assertSafePayload(input.payload);
  return tx.notificationOutbox.upsert({
    where: { idempotencyKey: input.idempotencyKey },
    update: {},
    create: {
      eventType: input.eventType,
      idempotencyKey: input.idempotencyKey,
      aggregateType: input.aggregateType ?? null,
      aggregateId: input.aggregateId ?? null,
      payload: input.payload,
    },
  });
}

type Claimed = {
  id: string;
  eventType: string;
  idempotencyKey: string;
  payload: Prisma.JsonValue;
  attemptCount: number;
};

export type ProcessOutboxOptions = {
  batchSize?: number;
  maxAttempts?: number;
  baseDelayMs?: number;
  workerId?: string;
  deliveryTimeoutMs?: number;
  client?: PrismaClient;
};

function retryDelayMs(attemptCount: number, baseDelayMs: number) {
  return Math.min(60 * 60 * 1000, baseDelayMs * 2 ** Math.max(0, attemptCount - 1));
}

export async function getNotificationOutboxStatus(client: PrismaClient = prisma) {
  const now = new Date();
  const [grouped, oldestPending, duePending, staleProcessing] = await Promise.all([
    client.notificationOutbox.groupBy({
      by: ["status"],
      _count: { _all: true },
    }),
    client.notificationOutbox.findFirst({
      where: { status: "PENDING" },
      orderBy: { createdAt: "asc" },
      select: { id: true, eventType: true, createdAt: true, nextAttemptAt: true, attemptCount: true },
    }),
    client.notificationOutbox.count({
      where: { status: "PENDING", nextAttemptAt: { lte: now } },
    }),
    client.notificationOutbox.count({
      where: {
        status: "PROCESSING",
        lockedAt: { lt: new Date(now.getTime() - 5 * 60 * 1000) },
      },
    }),
  ]);

  const counts = { PENDING: 0, PROCESSING: 0, DELIVERED: 0, FAILED: 0 };
  for (const row of grouped) {
    if (row.status in counts) counts[row.status as keyof typeof counts] = row._count._all;
  }

  return {
    counts,
    duePending,
    staleProcessing,
    oldestPending: oldestPending
      ? {
          ...oldestPending,
          ageSeconds: Math.max(0, Math.floor((now.getTime() - oldestPending.createdAt.getTime()) / 1000)),
        }
      : null,
  };
}

export async function pruneDeliveredNotifications(
  client: PrismaClient = prisma,
  retentionDays = 30,
) {
  const days = Math.max(1, Math.floor(retentionDays));
  const cutoff = new Date(Date.now() - days * 24 * 60 * 60 * 1000);
  const result = await client.notificationOutbox.deleteMany({
    where: {
      status: "DELIVERED",
      deliveredAt: { lt: cutoff },
    },
  });
  return { deleted: result.count, cutoff };
}

export async function requeueFailedNotifications(client: PrismaClient = prisma) {
  const result = await client.notificationOutbox.updateMany({
    where: { status: "FAILED" },
    data: {
      status: "PENDING",
      attemptCount: 0,
      nextAttemptAt: new Date(),
      lastAttemptAt: null,
      lastError: null,
      lockedAt: null,
      lockedBy: null,
      deliveredAt: null,
    },
  });
  return { requeued: result.count };
}

function resolveWebhookUrl(raw: string | undefined) {
  if (!raw) throw new Error("BOOKING_WEBHOOK_URL is not configured");
  let url: URL;
  try {
    url = new URL(raw);
  } catch {
    throw new Error("BOOKING_WEBHOOK_URL must be a valid absolute URL");
  }
  if (url.username || url.password) {
    throw new Error("BOOKING_WEBHOOK_URL must not contain embedded credentials");
  }
  const localHttp = url.protocol === "http:" && ["localhost", "127.0.0.1", "::1"].includes(url.hostname);
  if (url.protocol !== "https:" && !localHttp) {
    throw new Error("BOOKING_WEBHOOK_URL must use HTTPS outside localhost");
  }
  return url.toString();
}

export async function processNotificationOutbox(options: ProcessOutboxOptions = {}) {
  const client = options.client ?? prisma;
  const batchSize = Math.min(100, Math.max(1, options.batchSize ?? 25));
  const maxAttempts = Math.max(1, options.maxAttempts ?? 10);
  const baseDelayMs = Math.max(0, options.baseDelayMs ?? 5_000);
  const workerId = options.workerId ?? `worker-${process.pid}-${crypto.randomUUID()}`;
  const configuredTimeout = Number(process.env.BOOKING_WEBHOOK_TIMEOUT_MS || "10000");
  const deliveryTimeoutMs = Math.max(100, options.deliveryTimeoutMs ?? (Number.isFinite(configuredTimeout) ? configuredTimeout : 10_000));
  const url = resolveWebhookUrl(process.env.BOOKING_WEBHOOK_URL);

  const claimed = await client.$transaction(async tx => {
    const rows = await tx.$queryRaw<Array<{ id: string }>>(Prisma.sql`
      WITH candidates AS (
        SELECT "id"
        FROM "NotificationOutbox"
        WHERE
          "nextAttemptAt" <= NOW()
          AND (
            "status" = 'PENDING'
            OR ("status" = 'PROCESSING' AND "lockedAt" < NOW() - INTERVAL '5 minutes')
          )
        ORDER BY "createdAt"
        FOR UPDATE SKIP LOCKED
        LIMIT ${batchSize}
      )
      UPDATE "NotificationOutbox" AS outbox
      SET
        "status" = 'PROCESSING',
        "lockedAt" = NOW(),
        "lockedBy" = ${workerId},
        "lastAttemptAt" = NOW(),
        "attemptCount" = outbox."attemptCount" + 1,
        "updatedAt" = NOW()
      FROM candidates
      WHERE outbox."id" = candidates."id"
      RETURNING outbox."id"
    `);
    if (!rows.length) return [];
    return tx.notificationOutbox.findMany({
      where: { id: { in: rows.map(row => row.id) }, lockedBy: workerId },
      select: { id: true, eventType: true, idempotencyKey: true, payload: true, attemptCount: true },
    }) as Promise<Claimed[]>;
  });

  let delivered = 0;
  let failed = 0;

  for (const event of claimed) {
    try {
      const response = await fetch(url, {
        method: "POST",
        headers: {
          "content-type": "application/json",
          "idempotency-key": event.idempotencyKey,
          "x-notification-id": event.id,
        },
        body: JSON.stringify({ id: event.id, event: event.eventType, payload: event.payload }),
        cache: "no-store",
        signal: AbortSignal.timeout(deliveryTimeoutMs),
      });
      if (!response.ok) throw new Error(`Webhook responded with HTTP ${response.status}`);

      const updated = await client.notificationOutbox.updateMany({
        where: { id: event.id, status: "PROCESSING", lockedBy: workerId },
        data: {
          status: "DELIVERED",
          deliveredAt: new Date(),
          lastError: null,
          lockedAt: null,
          lockedBy: null,
        },
      });
      if (updated.count === 1) delivered += 1;
    } catch (error) {
      const message = error instanceof Error ? error.message.slice(0, 2000) : "Unknown delivery error";
      const terminal = event.attemptCount >= maxAttempts;
      const updated = await client.notificationOutbox.updateMany({
        where: { id: event.id, status: "PROCESSING", lockedBy: workerId },
        data: {
          status: terminal ? "FAILED" : "PENDING",
          nextAttemptAt: new Date(Date.now() + retryDelayMs(event.attemptCount, baseDelayMs)),
          lastError: message,
          lockedAt: null,
          lockedBy: null,
        },
      });
      if (updated.count === 1) failed += 1;
    }
  }

  return { claimed: claimed.length, delivered, failed };
}
