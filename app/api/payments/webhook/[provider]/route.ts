import { PaymentEventStatus, PaymentKind } from "@prisma/client";
import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

import { enqueueNotificationTx } from "@/lib/notification-outbox";


// This generic integration is not a production payment-provider signature verifier.
export async function POST(request: Request, { params }: { params: Promise<{ provider: string }> }) {
  const { provider } = await params;
  const secret = process.env.PAYMENT_WEBHOOK_SECRET;
  if (!secret || request.headers.get("x-webhook-secret") !== secret) {
    return NextResponse.json({ error: "Invalid webhook signature" }, { status: 401 });
  }
  const body = await request.json();
  const externalEventId = String(body.eventId || "");
  if (!externalEventId || !body.bookingId || !body.amount || !body.status) return NextResponse.json({ error: "Invalid payment event" }, { status: 400 });
  try {
    const result = await prisma.$transaction(async tx => {
      const existing = await tx.webhookEvent.findUnique({ where: { provider_externalEventId: { provider, externalEventId } } });
      if (existing?.processedAt) return { duplicate: true, bookingId: body.bookingId };
      const event = existing || await tx.webhookEvent.create({ data: { provider, externalEventId, eventType: String(body.type || "payment"), payload: body } });
      await tx.paymentTransaction.upsert({
        where: { idempotencyKey: provider + ":" + externalEventId },
        create: { bookingId: body.bookingId, kind: (body.kind || "DEPOSIT") as PaymentKind,
          status: body.status as PaymentEventStatus, provider, providerRef: body.providerRef || null,
          idempotencyKey: provider + ":" + externalEventId, amount: Number(body.amount),
          currency: body.currency || "USD", metadata: body.metadata || undefined },
        update: { status: body.status as PaymentEventStatus, providerRef: body.providerRef || null },
      });
      const payments = await tx.paymentTransaction.findMany({ where: { bookingId: body.bookingId } });
      const paid = payments.filter(p => p.status === PaymentEventStatus.SUCCEEDED && p.kind !== PaymentKind.REFUND).reduce((sum, p) => sum + p.amount, 0);
      const refunded = payments.filter(p => p.kind === PaymentKind.REFUND && p.status === PaymentEventStatus.REFUNDED).reduce((sum, p) => sum + p.amount, 0);
      const currentBooking = await tx.bookingInquiry.findUniqueOrThrow({ where: { id: body.bookingId } });
      const net = Math.max(0, paid - refunded);
      const total = currentBooking.estimatedTotal ?? 0;
      const paymentStatus = refunded > 0 && net === 0 ? "REFUNDED"
        : refunded > 0 ? "PARTIALLY_REFUNDED"
        : net >= total && total > 0 ? "PAID"
        : net > 0 ? "PARTIALLY_PAID" : "UNPAID";
      const booking = await tx.bookingInquiry.update({
        where: { id: body.bookingId },
        data: { amountPaid: paid, amountRefunded: refunded, paymentStatus },
      });
      if (paymentStatus === "PAID") {
        await tx.bookingTask.updateMany({ where: { bookingId: booking.id, type: "PAYMENT", status: { in: ["OPEN", "IN_PROGRESS"] } }, data: { status: "DONE", completedAt: new Date() } });
      } else if (paymentStatus !== "UNPAID") {
        await tx.bookingTask.updateMany({ where: { bookingId: booking.id, type: "PAYMENT", status: "OPEN" }, data: { status: "IN_PROGRESS" } });
      }
      await enqueueNotificationTx(tx, {
        eventType: "payment.updated",
        idempotencyKey: `payment.updated:${provider}:${externalEventId}`,
        aggregateType: "BookingInquiry",
        aggregateId: booking.id,
        payload: { bookingId: booking.id, reference: booking.reference, paymentStatus: booking.paymentStatus,
          amountPaid: booking.amountPaid, amountRefunded: booking.amountRefunded, currency: booking.currency,
          voucherLinkRequiresStaffIssuance: true },
      });
      await tx.webhookEvent.update({ where: { id: event.id }, data: { processedAt: new Date() } });
      return { duplicate: false, bookingId: body.bookingId };
    });
    return NextResponse.json({ received: true, duplicate: result.duplicate });
  } catch {
    return NextResponse.json({ error: "Unable to process payment event" }, { status: 400 });
  }
}
