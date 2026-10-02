CREATE TABLE "VoucherGrant" (
  "id" TEXT NOT NULL,
  "bookingId" TEXT NOT NULL,
  "tokenHash" TEXT NOT NULL,
  "expiresAt" TIMESTAMP(3) NOT NULL,
  "revokedAt" TIMESTAMP(3),
  "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT "VoucherGrant_pkey" PRIMARY KEY ("id")
);
CREATE UNIQUE INDEX "VoucherGrant_tokenHash_key" ON "VoucherGrant"("tokenHash");
CREATE INDEX "VoucherGrant_bookingId_expiresAt_idx" ON "VoucherGrant"("bookingId", "expiresAt");
ALTER TABLE "VoucherGrant" ADD CONSTRAINT "VoucherGrant_bookingId_fkey"
  FOREIGN KEY ("bookingId") REFERENCES "BookingInquiry"("id") ON DELETE CASCADE ON UPDATE CASCADE;
