// Explicit allowlist: never spread an internal pricing object into a public response.
export type PublicQuote = {
  currency: string;
  cruise: { id: string; slug: string; name: string };
  cabin: { id: string; name: string };
  departure: { id: string; departureDate: string; durationNights: number };
  adults: number;
  children: number;
  adultRate: number;
  childRate: number;
  passengerSubtotal: number;
  singleSupplement: number;
  holidaySurcharge: number;
  transfer: number;
  total: number;
  estimateOnly: true;
  requiresOperatorConfirmation: true;
};

type QuoteSource = Omit<PublicQuote, "departure" | "estimateOnly" | "requiresOperatorConfirmation"> & {
  departure: { id: string; departureDate: Date; durationNights: number };
};

export function toPublicQuote(quote: QuoteSource): PublicQuote {
  return {
    currency: quote.currency,
    cruise: { id: quote.cruise.id, slug: quote.cruise.slug, name: quote.cruise.name },
    cabin: { id: quote.cabin.id, name: quote.cabin.name },
    departure: {
      id: quote.departure.id,
      departureDate: quote.departure.departureDate.toISOString(),
      durationNights: quote.departure.durationNights,
    },
    adults: quote.adults,
    children: quote.children,
    adultRate: quote.adultRate,
    childRate: quote.childRate,
    passengerSubtotal: quote.passengerSubtotal,
    singleSupplement: quote.singleSupplement,
    holidaySurcharge: quote.holidaySurcharge,
    transfer: quote.transfer,
    total: quote.total,
    estimateOnly: true,
    requiresOperatorConfirmation: true,
  };
}
