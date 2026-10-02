CREATE TYPE "ExpenseCategory" AS ENUM ('SUPPLIER','TRANSFER','GUIDE','COMMISSION','BANK_FEE','REFUND_COST','OTHER');
CREATE TYPE "PayableStatus" AS ENUM ('OPEN','PARTIALLY_PAID','PAID','CANCELLED');

CREATE TABLE "BookingExpense" (
  "id" TEXT NOT NULL,
  "bookingId" TEXT NOT NULL,
  "category" "ExpenseCategory" NOT NULL,
  "description" TEXT,
  "amount" INTEGER NOT NULL,
  "currency" TEXT NOT NULL DEFAULT 'USD',
  "paidAt" TIMESTAMP(3),
  "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updatedAt" TIMESTAMP(3) NOT NULL,
  CONSTRAINT "BookingExpense_pkey" PRIMARY KEY ("id")
);

CREATE TABLE "SupplierPayable" (
  "id" TEXT NOT NULL,
  "bookingId" TEXT NOT NULL,
  "supplierId" TEXT NOT NULL,
  "amount" INTEGER NOT NULL,
  "paidAmount" INTEGER NOT NULL DEFAULT 0,
  "currency" TEXT NOT NULL DEFAULT 'USD',
  "status" "PayableStatus" NOT NULL DEFAULT 'OPEN',
  "dueDate" TIMESTAMP(3),
  "notes" TEXT,
  "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updatedAt" TIMESTAMP(3) NOT NULL,
  CONSTRAINT "SupplierPayable_pkey" PRIMARY KEY ("id")
);

CREATE INDEX "BookingExpense_bookingId_createdAt_idx" ON "BookingExpense"("bookingId","createdAt");
CREATE INDEX "BookingExpense_category_createdAt_idx" ON "BookingExpense"("category","createdAt");
CREATE INDEX "SupplierPayable_bookingId_createdAt_idx" ON "SupplierPayable"("bookingId","createdAt");
CREATE INDEX "SupplierPayable_supplierId_status_dueDate_idx" ON "SupplierPayable"("supplierId","status","dueDate");

ALTER TABLE "BookingExpense" ADD CONSTRAINT "BookingExpense_bookingId_fkey" FOREIGN KEY ("bookingId") REFERENCES "BookingInquiry"("id") ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "SupplierPayable" ADD CONSTRAINT "SupplierPayable_bookingId_fkey" FOREIGN KEY ("bookingId") REFERENCES "BookingInquiry"("id") ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "SupplierPayable" ADD CONSTRAINT "SupplierPayable_supplierId_fkey" FOREIGN KEY ("supplierId") REFERENCES "Supplier"("id") ON DELETE RESTRICT ON UPDATE CASCADE;
