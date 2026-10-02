const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function cleanText(value: unknown, max: number) {
  return String(value ?? "").trim().slice(0, max);
}

export function validateBookingInput(body: Record<string, unknown>) {
  const primaryGuest = cleanText(body.primaryGuest, 120);
  const email = cleanText(body.email, 200).toLowerCase();
  const phone = cleanText(body.phone, 40);
  const nationality = cleanText(body.nationality, 80);
  const specialRequests = cleanText(body.specialRequests, 1200);
  const adults = Number(body.adults);
  const children = Number(body.children);

  if (!primaryGuest || !emailPattern.test(email)) throw new Error("Please provide a valid guest name and email.");
  if (!Number.isInteger(adults) || adults < 1 || adults > 12) throw new Error("Adults must be between 1 and 12.");
  if (!Number.isInteger(children) || children < 0 || children > 12) throw new Error("Children must be between 0 and 12.");
  if (adults + children > 16) throw new Error("Online requests support up to 16 guests. Please contact our team for groups.");
  if (!["shared","private","none"].includes(String(body.transferType || "none"))) throw new Error("Invalid transfer option.");

  return { primaryGuest,email,phone,nationality,specialRequests,adults,children };
}
