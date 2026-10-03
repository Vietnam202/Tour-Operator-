import { createHash, randomBytes } from "node:crypto";
import { Prisma } from "@prisma/client";
import { BookingOperationError, withBookingLock } from "@/lib/booking-lock";

export const voucherBookingSelect = {
  id: true, reference: true, cruiseName: true, cabinName: true, departureDate: true,
  durationNights: true, adults: true, children: true, primaryGuest: true, transferType: true,
  estimatedTotal: true, currency: true, status: true, inventoryCommitted: true,
  supplierConfirmationStatus: true, supplierConfirmationRef: true,
} satisfies Prisma.BookingInquirySelect;
export type VoucherBooking = Prisma.BookingInquiryGetPayload<{ select: typeof voucherBookingSelect }>;

export function voucherReadiness(booking: VoucherBooking, now = new Date()): string | null {
  if (booking.status !== "CONFIRMED" || !booking.inventoryCommitted) return "Confirm the booking and its cabin allocation first.";
  if (booking.supplierConfirmationStatus !== "CONFIRMED" || !booking.supplierConfirmationRef?.trim()) return "Record the cruise operator's confirmation and reservation reference first.";
  const total = booking.estimatedTotal;
  if (total === null || !Number.isSafeInteger(total) || total <= 0) return "A verified positive booking total is required.";
  const tripEnd = booking.departureDate.getTime() + (booking.durationNights + 1) * 86400000;
  if (tripEnd <= now.getTime()) return "This trip has ended; a new travel voucher cannot be issued.";
  return null;
}

export function publicVoucher(booking: VoucherBooking) {
  return {
    reference: booking.reference, cruiseName: booking.cruiseName, cabinName: booking.cabinName,
    primaryGuest: booking.primaryGuest, departureDate: booking.departureDate.toISOString(),
    durationNights: booking.durationNights, adults: booking.adults, children: booking.children,
    transferType: booking.transferType, estimatedTotal: booking.estimatedTotal!, currency: booking.currency,
  };
}

export function hashVoucherToken(token: string): string {
  return createHash("sha256").update(token).digest("hex");
}

function canonicalOrigin(): string {
  try {
    const url = new URL(process.env.NEXT_PUBLIC_SITE_URL || "");
    if (url.username || url.password || url.protocol !== "https:") throw new Error("Invalid URL");
    return url.origin;
  } catch {
    throw new BookingOperationError("Configure the canonical HTTPS site URL before issuing customer links.", 503);
  }
}

export async function issueVoucherGrant(bookingId: string, actorId: string) {
  const origin = canonicalOrigin();
  return withBookingLock(bookingId, async tx => {
    const booking = await tx.bookingInquiry.findUniqueOrThrow({ where: { id: bookingId }, select: voucherBookingSelect });
    const now = new Date();
    const reason = voucherReadiness(booking, now);
    if (reason) throw new BookingOperationError(reason);
    const expiresAt = new Date(Math.min(now.getTime() + 72 * 3600000,
      booking.departureDate.getTime() + (booking.durationNights + 1) * 86400000));
    const token = randomBytes(32).toString("hex");
    await tx.voucherGrant.updateMany({ where: { bookingId, revokedAt: null }, data: { revokedAt: now } });
    const grant = await tx.voucherGrant.create({ data: { bookingId, tokenHash: hashVoucherToken(token), expiresAt } });
    await tx.bookingActivity.create({ data: {
      bookingId, actorId, type: "VOUCHER_LINK_ISSUED", message: "A time-limited customer voucher link was issued; older links were revoked.",
      metadata: { grantId: grant.id, expiresAt: expiresAt.toISOString() },
    } });
    // The page removes this fragment and sends its secret only in Authorization.
    return { grantId: grant.id, expiresAt: expiresAt.toISOString(),
      voucherUrl: `${origin}/voucher/${encodeURIComponent(booking.reference)}#access=${token}` };
  });
}

export function revokeVoucherGrants(bookingId: string, actorId: string) {
  return withBookingLock(bookingId, async tx => {
    const result = await tx.voucherGrant.updateMany({ where: { bookingId, revokedAt: null }, data: { revokedAt: new Date() } });
    if (result.count) await tx.bookingActivity.create({ data: {
      bookingId, actorId, type: "VOUCHER_LINK_REVOKED", message: "Customer voucher links were revoked.", metadata: { count: result.count },
    } });
    return { revoked: result.count };
  });
}
