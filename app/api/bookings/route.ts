import { randomBytes } from "node:crypto";
import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { calculateQuote } from "@/lib/pricing";
import { asObject, parseQuoteInput, QuoteInputError, text } from "@/lib/quote-input";
import { toPublicQuote } from "@/lib/public-quote";
import { notifyNewBooking } from "@/lib/notifications";
import { rateLimit } from "@/lib/rate-limit";

export async function POST(request: Request) {
  const limit = rateLimit(request, "booking", 8, 60_000);
  if (!limit.allowed) return NextResponse.json({ error: "Too many booking attempts. Please try again shortly." }, { status: 429, headers: { "Retry-After": String(limit.retryAfter) } });
  try {
    const body = asObject(await request.json());
    const input = parseQuoteInput(body);
    if (body.consent !== true) throw new QuoteInputError("Please agree to be contacted about this booking request.");
    const primaryGuest = text(body.primaryGuest, "guest name", 120);
    const email = text(body.email, "email address", 200).toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new QuoteInputError("Please provide a valid email address.");
    const nationality = text(body.nationality, "nationality", 80, false);
    const phone = text(body.phone, "phone number", 40, false);
    const specialRequests = text(body.specialRequests, "special requests", 1200, false);
    // Ignore all client-supplied totals/costs/names and calculate again on submission.
    const quote = await calculateQuote(input);
    const reference = `HCA-${new Date().toISOString().slice(0, 10).replaceAll("-", "")}-${randomBytes(8).toString("hex").toUpperCase()}`;
    const booking = await prisma.$transaction(async tx => {
      const created = await tx.bookingInquiry.create({ data: {
        reference, cruiseId: quote.cruise.id, cruiseName: quote.cruise.name, cabinName: quote.cabin.name,
        departureId: quote.departure.id, departureDate: quote.departure.departureDate,
        durationNights: quote.departure.durationNights, adults: quote.adults, children: quote.children,
        primaryGuest, email, nationality: nationality || null, phone: phone || null,
        transferType: input.transferType, specialRequests: specialRequests || null,
        estimatedTotal: quote.total, quotedSubtotal: quote.passengerSubtotal,
        quotedSurcharge: quote.singleSupplement + quote.holidaySurcharge, quotedTransfer: quote.transfer,
        quotedCost: quote.quotedCost, quotedMargin: quote.quotedMargin, currency: quote.currency,
      } });
      await tx.bookingActivity.create({ data: {
        bookingId: created.id, type: "BOOKING_CREATED", message: "Guest submitted an availability request and contact consent.",
        metadata: { source: "website", consentVersion: "booking-contact-v1", consentAt: new Date().toISOString() },
      } });
      return created;
    });
    // A notification integration is optional; persistence is the source of success.
    await notifyNewBooking({ reference: booking.reference, cruiseName: booking.cruiseName,
      departureDate: booking.departureDate.toISOString(), primaryGuest: booking.primaryGuest,
      email: booking.email, phone: booking.phone, adults: booking.adults, children: booking.children,
      estimatedTotal: booking.estimatedTotal });
    return NextResponse.json({ reference: booking.reference, status: booking.status, quote: toPublicQuote(quote) },
      { status: 201, headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    if (error instanceof QuoteInputError) return NextResponse.json({ error: error.message }, { status: error.status });
    if (error instanceof SyntaxError) return NextResponse.json({ error: "Invalid JSON request." }, { status: 400 });
    return NextResponse.json({ error: "We could not save your request. Please try again or contact our team." }, { status: 503 });
  }
}
