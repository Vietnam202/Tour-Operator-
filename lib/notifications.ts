type NoticePayload = Record<string, unknown>;

async function emit(event: string, payload: NoticePayload) {
  const url = process.env.BOOKING_WEBHOOK_URL;
  if (!url) return;
  try {
    await fetch(url, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ event, ...payload }),
      cache: "no-store"
    });
  } catch {
    // Operational notifications must never break booking or payment flows.
  }
}

export async function notifyNewBooking(booking: NoticePayload) {
  return emit("booking.created", { booking });
}

export async function notifyBookingConfirmed(booking: NoticePayload) {
  return emit("booking.confirmed", { booking });
}

export async function notifyPaymentRequested(payment: NoticePayload) {
  return emit("payment.requested", { payment });
}

export async function notifyPaymentUpdated(payment: NoticePayload) {
  return emit("payment.updated", { payment });
}
