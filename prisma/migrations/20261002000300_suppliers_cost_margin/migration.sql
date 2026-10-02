CREATE TABLE "Supplier" (
  "id" TEXT NOT NULL,
  "name" TEXT NOT NULL,
  "legalName" TEXT,
  "contactName" TEXT,
  "email" TEXT,
  "phone" TEXT,
  "currency" TEXT NOT NULL DEFAULT 'USD',
  "notes" TEXT,
  "isActive" BOOLEAN NOT NULL DEFAULT true,
  "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updatedAt" TIMESTAMP(3) NOT NULL,
  CONSTRAINT "Supplier_pkey" PRIMARY KEY ("id")
);
ALTER TABLE "Cruise" ADD COLUMN "supplierId" TEXT;
ALTER TABLE "Cabin" ADD COLUMN "netCost" INTEGER;
ALTER TABLE "Departure" ADD COLUMN "netCostFrom" INTEGER;
ALTER TABLE "BookingInquiry" ADD COLUMN "quotedCost" INTEGER;
ALTER TABLE "BookingInquiry" ADD COLUMN "quotedMargin" INTEGER;
CREATE INDEX "Supplier_name_idx" ON "Supplier"("name");
CREATE INDEX "Cruise_supplierId_idx" ON "Cruise"("supplierId");
ALTER TABLE "Cruise" ADD CONSTRAINT "Cruise_supplierId_fkey" FOREIGN KEY ("supplierId") REFERENCES "Supplier"("id") ON DELETE SET NULL ON UPDATE CASCADE;
