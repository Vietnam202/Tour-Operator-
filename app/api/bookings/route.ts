import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { notifyNewBooking } from "@/lib/notifications";

function bookingReference() {
  const stamp = new Date().toISOString().slice(0, 10).replaceAll("-", "");
  const suffix = Math.random().toString(36).slice(2, 7).toUpperCase();
  return `HCA-${stamp}-${suffix}`;
}

export async function POST(request: Request) {
  try {
    const body = await request.json();
    const required = ["cruiseName", "departureDate", "primaryGuest", "email"];

    for (const field of required) {
      if (!body[field]) {
        return NextResponse.json({ error: `Missing ${field}` }, { status: 400 });
      }
    }

    const booking = await prisma.bookingInquiry.create({
      data: {
        reference: bookingReference(),
        cruiseId: body.cruiseId || null,
        cruiseName: body.cruiseName,
        cabinName: body.cabinName || null,
        departureDate: new Date(body.departureDate),
        durationNights: Number(body.durationNights || 1),
        adults: Math.max(1, Number(body.adults || 1)),
        children: Math.max(0, Number(body.children || 0)),
        primaryGuest: body.primaryGuest,
        nationality: body.nationality || null,
        email: body.email,
        phone: body.phone || null,
        transferType: body.transferType || null,
        specialRequests: body.specialRequests || null,
        estimatedTotal: body.estimatedTotal ? Number(body.estimatedTotal) : null,
        currency: "USD",
      },
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
      estimatedTotal: booking.estimatedTotal,
    });

    return NextResponse.json(
      { reference: booking.reference, status: booking.status },
      { status: 201 }
    );
  } catch {
    return NextResponse.json(
      { error: "We could not save this booking request. Please contact our team." },
      { status: 500 }
    );
  }
}
