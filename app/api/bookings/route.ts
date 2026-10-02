import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { notifyNewBooking } from "@/lib/notifications";
import { calculateQuote } from "@/lib/pricing";
import { validateBookingInput } from "@/lib/validation";
import { rateLimit } from "@/lib/rate-limit";

function bookingReference() {
  const stamp = new Date().toISOString().slice(0, 10).replaceAll("-", "");
  const suffix = Math.random().toString(36).slice(2, 7).toUpperCase();
  return `HCA-${stamp}-${suffix}`;
}

export async function POST(request: Request) {
  try {
    const limit = rateLimit(request,"booking",8,60_000);
    if (!limit.allowed) return NextResponse.json({ error:"Too many booking attempts. Please try again shortly." },{status:429,headers:{"retry-after":String(limit.retryAfter)}});
    const body = await request.json();
    const required = ["cruiseId", "cabinId", "departureDate", "primaryGuest", "email"];
    for (const field of required) {
      if (!body[field]) return NextResponse.json({ error: `Missing ${field}` }, { status: 400 });
    }

    const valid = validateBookingInput(body);

    const quote = await calculateQuote({
      cruiseId: body.cruiseId,
      cabinId: body.cabinId,
      departureId: body.departureId || null,
      adults: valid.adults,
      children: valid.children,
      transferType: body.transferType || "none"
    });

    const booking = await prisma.bookingInquiry.create({
      data: {
        reference: bookingReference(),
        cruiseId: quote.cruise.id,
        cruiseName: quote.cruise.name,
        cabinName: quote.cabin.name,
        departureDate: quote.departure?.departureDate ?? new Date(body.departureDate),
        durationNights: quote.departure?.durationNights ?? Number(body.durationNights || 1),
        departureId: quote.departure?.id ?? null,
        adults: quote.adults,
        children: quote.children,
        primaryGuest: valid.primaryGuest,
        nationality: valid.nationality || null,
        email: valid.email,
        phone: valid.phone || null,
        transferType: body.transferType || null,
        specialRequests: valid.specialRequests || null,
        estimatedTotal: quote.total,
        quotedSubtotal: quote.passengerSubtotal,
        quotedSurcharge: quote.holidaySurcharge + quote.singleSupplement,
        quotedTransfer: quote.transfer,
        currency: quote.currency
      }
    });

    await notifyNewBooking({
      reference: booking.reference,
      cruiseName: booking.cruiseName,
      departureDate: booking.departureDate.toISOString(),
      primaryGuest: booking.primaryGuest,
      email: booking.email,
      phone: booking.phone,
      adults: booking.adults,
      children: booking.children,
      estimatedTotal: booking.estimatedTotal
    });

    return NextResponse.json({
      reference: booking.reference,
      status: booking.status,
      quote: {
        currency: quote.currency,
        total: quote.total,
        passengerSubtotal: quote.passengerSubtotal,
        surcharge: quote.holidaySurcharge + quote.singleSupplement,
        transfer: quote.transfer
      }
    }, { status: 201 });
  } catch (error) {
    return NextResponse.json(
      { error: error instanceof Error ? error.message : "We could not save this booking request." },
      { status: 400 }
    );
  }
}
