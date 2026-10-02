import { PaymentEventStatus, PaymentKind } from "@prisma/client";
import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { refreshBookingPaymentStatus } from "@/lib/payments";
import { notifyPaymentUpdated } from "@/lib/notifications";
import { syncPaymentTasks } from "@/lib/booking-automation";

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
      await tx.webhookEvent.update({ where: { id: event.id }, data: { processedAt: new Date() } });
      return { duplicate: false, bookingId: body.bookingId };
    });
    if (!result.duplicate) {
      const booking = await refreshBookingPaymentStatus(result.bookingId);
      await syncPaymentTasks(booking.id, booking.paymentStatus);
      await notifyPaymentUpdated({ reference: booking.reference, paymentStatus: booking.paymentStatus,
        amountPaid: booking.amountPaid, amountRefunded: booking.amountRefunded, currency: booking.currency,
        // Never send a reference-only URL as a guest credential. Staff must issue a private grant.
        voucherUrl: null, voucherLinkRequiresStaffIssuance: true });
    }
    return NextResponse.json({ received: true, duplicate: result.duplicate });
  } catch {
    return NextResponse.json({ error: "Unable to process payment event" }, { status: 400 });
  }
}
