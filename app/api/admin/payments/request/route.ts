import { PaymentEventStatus, PaymentKind, PaymentStatus } from "@prisma/client";
import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";
import { enqueueNotificationTx } from "@/lib/notification-outbox";
import { sameOrigin } from "@/lib/csrf";
import { markPaymentTaskInProgress } from "@/lib/booking-automation";


export async function POST(request: Request) {
  if (!sameOrigin(request)) return NextResponse.json({ error: "Invalid request origin" }, { status: 403 });
  if (!(await requireAdminPermission(request,"payments:write"))) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  const body = await request.json();
  if (!body.bookingId || !body.kind || !body.amount) return NextResponse.json({ error: "bookingId, kind and amount are required" }, { status: 400 });

  const booking = await prisma.bookingInquiry.findUnique({ where: { id: body.bookingId } });
  if (!booking) return NextResponse.json({ error: "Booking not found" }, { status: 404 });
  if (booking.status !== "CONFIRMED") return NextResponse.json({ error: "Booking must be confirmed before requesting payment" }, { status: 409 });

  const amount = Math.max(1, Number(body.amount));
  const kind = body.kind as PaymentKind;
  const nonce = crypto.randomUUID().replaceAll("-", "");
  const token = nonce + crypto.randomUUID().replaceAll("-", "");

  const result = await prisma.$transaction(async tx => {
    const transaction = await tx.paymentTransaction.create({
      data: {
        bookingId: booking.id, kind, status: PaymentEventStatus.PENDING, provider: "manual",
        idempotencyKey: `manual-request:${booking.id}:${kind}:${nonce}`,
        amount, currency: booking.currency
      }
    });
    const paymentRequest = await tx.paymentRequest.create({
      data: {
        bookingId: booking.id, token, kind, amount, currency: booking.currency,
        expiresAt: new Date(Date.now() + 72 * 60 * 60 * 1000)
      }
    });
    await tx.bookingInquiry.update({ where: { id: booking.id }, data: { paymentStatus: PaymentStatus.PENDING } });
    await enqueueNotificationTx(tx, {
      eventType: "payment.requested",
      idempotencyKey: `payment.requested:${paymentRequest.id}`,
      aggregateType: "PaymentRequest",
      aggregateId: paymentRequest.id,
      payload: { paymentRequestId: paymentRequest.id, bookingId: booking.id, reference: booking.reference,
        primaryGuest: booking.primaryGuest, email: booking.email, kind, amount, currency: booking.currency },
    });
    return { transaction, paymentRequest };

  });

  const paymentUrl = `/pay/${token}`;
  await markPaymentTaskInProgress(booking.id);
  return NextResponse.json({ ...result, paymentUrl }, { status: 201 });
}
