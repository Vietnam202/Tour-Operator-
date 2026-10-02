CREATE TABLE "NotificationOutbox" (
  "id" TEXT NOT NULL,
  "eventType" TEXT NOT NULL,
  "aggregateType" TEXT,
  "aggregateId" TEXT,
  "idempotencyKey" TEXT NOT NULL,
  "payload" JSONB NOT NULL,
  "status" TEXT NOT NULL DEFAULT 'PENDING',
  "attemptCount" INTEGER NOT NULL DEFAULT 0,
  "nextAttemptAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "lastAttemptAt" TIMESTAMP(3),
  "lastError" TEXT,
  "lockedAt" TIMESTAMP(3),
  "lockedBy" TEXT,
  "deliveredAt" TIMESTAMP(3),
  "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updatedAt" TIMESTAMP(3) NOT NULL,

  CONSTRAINT "NotificationOutbox_pkey" PRIMARY KEY ("id"),
  CONSTRAINT "NotificationOutbox_status_check" CHECK ("status" IN ('PENDING','PROCESSING','DELIVERED','FAILED'))
);

CREATE UNIQUE INDEX "NotificationOutbox_idempotencyKey_key"
  ON "NotificationOutbox"("idempotencyKey");

CREATE INDEX "NotificationOutbox_status_nextAttemptAt_idx"
  ON "NotificationOutbox"("status", "nextAttemptAt");

CREATE INDEX "NotificationOutbox_aggregateType_aggregateId_idx"
  ON "NotificationOutbox"("aggregateType", "aggregateId");
