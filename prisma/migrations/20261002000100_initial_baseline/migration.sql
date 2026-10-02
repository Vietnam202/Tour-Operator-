-- Initial production baseline for Halong Cruise Advisor.
CREATE TYPE "CruiseStatus" AS ENUM ('DRAFT','PUBLISHED','ARCHIVED');
CREATE TYPE "BookingStatus" AS ENUM ('NEW','CONTACTED','QUOTED','CONFIRMED','CANCELLED');
CREATE TYPE "PaymentStatus" AS ENUM ('UNPAID','PENDING','PARTIALLY_PAID','PAID','PARTIALLY_REFUNDED','REFUNDED','FAILED');
CREATE TYPE "PaymentKind" AS ENUM ('DEPOSIT','BALANCE','FULL','REFUND');
CREATE TYPE "PaymentEventStatus" AS ENUM ('PENDING','SUCCEEDED','FAILED','REFUNDED');
CREATE TYPE "PaymentRequestStatus" AS ENUM ('ACTIVE','PAID','EXPIRED','CANCELLED');
CREATE TYPE "StaffRole" AS ENUM ('ADMIN','OPERATIONS','SALES','FINANCE');

CREATE TABLE "Cruise" ("id" TEXT NOT NULL,"slug" TEXT NOT NULL,"name" TEXT NOT NULL,"summary" TEXT,"route" TEXT NOT NULL,"stars" INTEGER NOT NULL DEFAULT 5,"rating" DOUBLE PRECISION NOT NULL DEFAULT 0,"reviewCount" INTEGER NOT NULL DEFAULT 0,"badge" TEXT,"heroImage" TEXT,"status" "CruiseStatus" NOT NULL DEFAULT 'DRAFT',"createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,"updatedAt" TIMESTAMP(3) NOT NULL,CONSTRAINT "Cruise_pkey" PRIMARY KEY ("id"));
CREATE TABLE "Cabin" ("id" TEXT NOT NULL,"cruiseId" TEXT NOT NULL,"name" TEXT NOT NULL,"sizeSqm" INTEGER,"description" TEXT,"capacity" INTEGER NOT NULL DEFAULT 2,"basePrice" INTEGER NOT NULL,"childRatePct" INTEGER NOT NULL DEFAULT 70,"singleSupplement" INTEGER NOT NULL DEFAULT 0,"currency" TEXT NOT NULL DEFAULT 'USD',"image" TEXT,"isActive" BOOLEAN NOT NULL DEFAULT true,"createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,"updatedAt" TIMESTAMP(3) NOT NULL,CONSTRAINT "Cabin_pkey" PRIMARY KEY ("id"));
CREATE TABLE "Departure" ("id" TEXT NOT NULL,"cruiseId" TEXT NOT NULL,"departureDate" TIMESTAMP(3) NOT NULL,"durationNights" INTEGER NOT NULL,"priceFrom" INTEGER NOT NULL,"holidaySurcharge" INTEGER NOT NULL DEFAULT 0,"currency" TEXT NOT NULL DEFAULT 'USD',"cabinsLeft" INTEGER,"isAvailable" BOOLEAN NOT NULL DEFAULT true,"createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,"updatedAt" TIMESTAMP(3) NOT NULL,CONSTRAINT "Departure_pkey" PRIMARY KEY ("id"));
CREATE TABLE "BookingInquiry" ("id" TEXT NOT NULL,"reference" TEXT NOT NULL,"cruiseId" TEXT,"cruiseName" TEXT NOT NULL,"cabinName" TEXT,"departureDate" TIMESTAMP(3) NOT NULL,"durationNights" INTEGER NOT NULL,"departureId" TEXT,"inventoryCommitted" BOOLEAN NOT NULL DEFAULT false,"adults" INTEGER NOT NULL,"children" INTEGER NOT NULL DEFAULT 0,"primaryGuest" TEXT NOT NULL,"nationality" TEXT,"email" TEXT NOT NULL,"phone" TEXT,"transferType" TEXT,"specialRequests" TEXT,"estimatedTotal" INTEGER,"quotedSubtotal" INTEGER,"quotedSurcharge" INTEGER,"quotedTransfer" INTEGER,"currency" TEXT NOT NULL DEFAULT 'USD',"status" "BookingStatus" NOT NULL DEFAULT 'NEW',"paymentStatus" "PaymentStatus" NOT NULL DEFAULT 'UNPAID',"depositAmount" INTEGER,"balanceAmount" INTEGER,"amountPaid" INTEGER NOT NULL DEFAULT 0,"amountRefunded" INTEGER NOT NULL DEFAULT 0,"createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,"updatedAt" TIMESTAMP(3) NOT NULL,CONSTRAINT "BookingInquiry_pkey" PRIMARY KEY ("id"));
CREATE TABLE "PaymentTransaction" ("id" TEXT NOT NULL,"bookingId" TEXT NOT NULL,"kind" "PaymentKind" NOT NULL,"status" "PaymentEventStatus" NOT NULL DEFAULT 'PENDING',"provider" TEXT NOT NULL,"providerRef" TEXT,"idempotencyKey" TEXT NOT NULL,"amount" INTEGER NOT NULL,"currency" TEXT NOT NULL DEFAULT 'USD',"metadata" JSONB,"createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,"updatedAt" TIMESTAMP(3) NOT NULL,CONSTRAINT "PaymentTransaction_pkey" PRIMARY KEY ("id"));
CREATE TABLE "WebhookEvent" ("id" TEXT NOT NULL,"provider" TEXT NOT NULL,"externalEventId" TEXT NOT NULL,"eventType" TEXT NOT NULL,"payload" JSONB,"processedAt" TIMESTAMP(3),"createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,CONSTRAINT "WebhookEvent_pkey" PRIMARY KEY ("id"));
CREATE TABLE "PaymentRequest" ("id" TEXT NOT NULL,"bookingId" TEXT NOT NULL,"token" TEXT NOT NULL,"kind" "PaymentKind" NOT NULL,"amount" INTEGER NOT NULL,"currency" TEXT NOT NULL DEFAULT 'USD',"status" "PaymentRequestStatus" NOT NULL DEFAULT 'ACTIVE',"expiresAt" TIMESTAMP(3),"providerUrl" TEXT,"createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,"updatedAt" TIMESTAMP(3) NOT NULL,CONSTRAINT "PaymentRequest_pkey" PRIMARY KEY ("id"));
CREATE TABLE "StaffUser" ("id" TEXT NOT NULL,"email" TEXT NOT NULL,"name" TEXT NOT NULL,"passwordHash" TEXT NOT NULL,"role" "StaffRole" NOT NULL DEFAULT 'OPERATIONS',"isActive" BOOLEAN NOT NULL DEFAULT true,"createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,"updatedAt" TIMESTAMP(3) NOT NULL,CONSTRAINT "StaffUser_pkey" PRIMARY KEY ("id"));
CREATE TABLE "StaffSession" ("id" TEXT NOT NULL,"userId" TEXT NOT NULL,"tokenHash" TEXT NOT NULL,"expiresAt" TIMESTAMP(3) NOT NULL,"createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,CONSTRAINT "StaffSession_pkey" PRIMARY KEY ("id"));

CREATE UNIQUE INDEX "Cruise_slug_key" ON "Cruise"("slug");
CREATE INDEX "Cabin_cruiseId_idx" ON "Cabin"("cruiseId");
CREATE INDEX "Departure_cruiseId_departureDate_idx" ON "Departure"("cruiseId","departureDate");
CREATE UNIQUE INDEX "BookingInquiry_reference_key" ON "BookingInquiry"("reference");
CREATE INDEX "BookingInquiry_status_createdAt_idx" ON "BookingInquiry"("status","createdAt");
CREATE INDEX "BookingInquiry_email_idx" ON "BookingInquiry"("email");
CREATE UNIQUE INDEX "PaymentTransaction_idempotencyKey_key" ON "PaymentTransaction"("idempotencyKey");
CREATE INDEX "PaymentTransaction_bookingId_createdAt_idx" ON "PaymentTransaction"("bookingId","createdAt");
CREATE INDEX "PaymentTransaction_provider_providerRef_idx" ON "PaymentTransaction"("provider","providerRef");
CREATE UNIQUE INDEX "WebhookEvent_provider_externalEventId_key" ON "WebhookEvent"("provider","externalEventId");
CREATE INDEX "WebhookEvent_createdAt_idx" ON "WebhookEvent"("createdAt");
CREATE UNIQUE INDEX "PaymentRequest_token_key" ON "PaymentRequest"("token");
CREATE INDEX "PaymentRequest_bookingId_createdAt_idx" ON "PaymentRequest"("bookingId","createdAt");
CREATE INDEX "PaymentRequest_status_expiresAt_idx" ON "PaymentRequest"("status","expiresAt");
CREATE UNIQUE INDEX "StaffUser_email_key" ON "StaffUser"("email");
CREATE UNIQUE INDEX "StaffSession_tokenHash_key" ON "StaffSession"("tokenHash");
CREATE INDEX "StaffSession_userId_expiresAt_idx" ON "StaffSession"("userId","expiresAt");

ALTER TABLE "Cabin" ADD CONSTRAINT "Cabin_cruiseId_fkey" FOREIGN KEY ("cruiseId") REFERENCES "Cruise"("id") ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "Departure" ADD CONSTRAINT "Departure_cruiseId_fkey" FOREIGN KEY ("cruiseId") REFERENCES "Cruise"("id") ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "BookingInquiry" ADD CONSTRAINT "BookingInquiry_cruiseId_fkey" FOREIGN KEY ("cruiseId") REFERENCES "Cruise"("id") ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE "PaymentTransaction" ADD CONSTRAINT "PaymentTransaction_bookingId_fkey" FOREIGN KEY ("bookingId") REFERENCES "BookingInquiry"("id") ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "PaymentRequest" ADD CONSTRAINT "PaymentRequest_bookingId_fkey" FOREIGN KEY ("bookingId") REFERENCES "BookingInquiry"("id") ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "StaffSession" ADD CONSTRAINT "StaffSession_userId_fkey" FOREIGN KEY ("userId") REFERENCES "StaffUser"("id") ON DELETE CASCADE ON UPDATE CASCADE;
