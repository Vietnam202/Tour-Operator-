import { PaymentEventStatus, PaymentKind, PaymentStatus } from "@prisma/client";
import { prisma } from "@/lib/prisma";

export function paymentPlan(total: number, depositPct = 30) {
  const deposit = Math.max(1, Math.round(total * depositPct / 100));
  return { deposit, balance: Math.max(0, total - deposit), depositPct };
}

export async function refreshBookingPaymentStatus(bookingId: string) {
  const booking = await prisma.bookingInquiry.findUnique({
    where: { id: bookingId },
    include: { payments: true }
  });
  if (!booking) throw new Error("Booking not found");

  const paid = booking.payments
    .filter(p => p.status === PaymentEventStatus.SUCCEEDED && p.kind !== PaymentKind.REFUND)
    .reduce((sum, p) => sum + p.amount, 0);
  const refunded = booking.payments
    .filter(p => p.kind === PaymentKind.REFUND && p.status === PaymentEventStatus.REFUNDED)
    .reduce((sum, p) => sum + p.amount, 0);
  const net = Math.max(0, paid - refunded);
  const total = booking.estimatedTotal ?? 0;

  let status: PaymentStatus = PaymentStatus.UNPAID;
  if (refunded > 0 && net === 0) status = PaymentStatus.REFUNDED;
  else if (refunded > 0) status = PaymentStatus.PARTIALLY_REFUNDED;
  else if (net >= total && total > 0) status = PaymentStatus.PAID;
  else if (net > 0) status = PaymentStatus.PARTIALLY_PAID;

  return prisma.bookingInquiry.update({
    where: { id: bookingId },
    data: { amountPaid: paid, amountRefunded: refunded, paymentStatus: status }
  });
}
