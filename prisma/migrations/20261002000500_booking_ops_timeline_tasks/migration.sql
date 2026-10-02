CREATE TYPE "SupplierConfirmationStatus" AS ENUM ('NOT_REQUESTED','REQUESTED','CONFIRMED','REJECTED');
CREATE TYPE "BookingTaskType" AS ENUM ('SUPPLIER_CONFIRMATION','PAYMENT','PASSPORT','TRANSFER_DETAILS','PRE_DEPARTURE','OTHER');
CREATE TYPE "BookingTaskStatus" AS ENUM ('OPEN','IN_PROGRESS','DONE','CANCELLED');

ALTER TABLE "BookingInquiry" ADD COLUMN "supplierConfirmationStatus" "SupplierConfirmationStatus" NOT NULL DEFAULT 'NOT_REQUESTED';
ALTER TABLE "BookingInquiry" ADD COLUMN "supplierConfirmationRef" TEXT;
ALTER TABLE "BookingInquiry" ADD COLUMN "supplierConfirmedAt" TIMESTAMP(3);

CREATE TABLE "BookingTask" (
 "id" TEXT NOT NULL,"bookingId" TEXT NOT NULL,"type" "BookingTaskType" NOT NULL,"title" TEXT NOT NULL,
 "status" "BookingTaskStatus" NOT NULL DEFAULT 'OPEN',"ownerId" TEXT,"dueAt" TIMESTAMP(3),"notes" TEXT,
 "completedAt" TIMESTAMP(3),"createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,"updatedAt" TIMESTAMP(3) NOT NULL,
 CONSTRAINT "BookingTask_pkey" PRIMARY KEY ("id")
);
CREATE TABLE "BookingActivity" (
 "id" TEXT NOT NULL,"bookingId" TEXT NOT NULL,"actorId" TEXT,"type" TEXT NOT NULL,"message" TEXT NOT NULL,
 "metadata" JSONB,"createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT "BookingActivity_pkey" PRIMARY KEY ("id")
);
CREATE INDEX "BookingTask_bookingId_status_dueAt_idx" ON "BookingTask"("bookingId","status","dueAt");
CREATE INDEX "BookingTask_ownerId_status_dueAt_idx" ON "BookingTask"("ownerId","status","dueAt");
CREATE INDEX "BookingActivity_bookingId_createdAt_idx" ON "BookingActivity"("bookingId","createdAt");
CREATE INDEX "BookingActivity_actorId_createdAt_idx" ON "BookingActivity"("actorId","createdAt");
ALTER TABLE "BookingTask" ADD CONSTRAINT "BookingTask_bookingId_fkey" FOREIGN KEY ("bookingId") REFERENCES "BookingInquiry"("id") ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "BookingTask" ADD CONSTRAINT "BookingTask_ownerId_fkey" FOREIGN KEY ("ownerId") REFERENCES "StaffUser"("id") ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE "BookingActivity" ADD CONSTRAINT "BookingActivity_bookingId_fkey" FOREIGN KEY ("bookingId") REFERENCES "BookingInquiry"("id") ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "BookingActivity" ADD CONSTRAINT "BookingActivity_actorId_fkey" FOREIGN KEY ("actorId") REFERENCES "StaffUser"("id") ON DELETE SET NULL ON UPDATE CASCADE;
