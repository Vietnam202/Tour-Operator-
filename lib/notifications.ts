type BookingNotice = {
  reference: string;
  cruiseName: string;
  departureDate: string;
  primaryGuest: string;
  email: string;
  phone?: string | null;
  adults: number;
  children: number;
  estimatedTotal?: number | null;
};

export async function notifyNewBooking(booking: BookingNotice) {
  const url = process.env.BOOKING_WEBHOOK_URL;
  if (!url) return;

  try {
    await fetch(url, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({
        event: "booking.created",
        booking,
      }),
      cache: "no-store",
    });
  } catch {
    // Notification failure should never block booking creation.
  }
}
