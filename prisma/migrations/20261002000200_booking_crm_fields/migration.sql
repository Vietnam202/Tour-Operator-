ALTER TABLE "BookingInquiry" ADD COLUMN "assignedToId" TEXT;
ALTER TABLE "BookingInquiry" ADD COLUMN "followUpAt" TIMESTAMP(3);
ALTER TABLE "BookingInquiry" ADD COLUMN "internalNotes" TEXT;

CREATE INDEX "BookingInquiry_assignedToId_followUpAt_idx" ON "BookingInquiry"("assignedToId","followUpAt");

ALTER TABLE "BookingInquiry" ADD CONSTRAINT "BookingInquiry_assignedToId_fkey"
FOREIGN KEY ("assignedToId") REFERENCES "StaffUser"("id") ON DELETE SET NULL ON UPDATE CASCADE;
