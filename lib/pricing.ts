import { prisma } from "@/lib/prisma";
import { parseQuoteInput, QuoteInputError, type QuoteInput } from "@/lib/quote-input";
export type { QuoteInput } from "@/lib/quote-input";

export async function calculateQuote(rawInput: QuoteInput) {
  const input = parseQuoteInput(rawInput);
  const { adults, children } = input;
  const cabin = await prisma.cabin.findFirst({
    where: { id: input.cabinId, cruiseId: input.cruiseId, isActive: true, cruise: { status: "PUBLISHED" } },
    include: { cruise: true },
  });
  if (!cabin) throw new QuoteInputError("This cabin is not available for the selected cruise.", 409);
  // The existing inventory confirmation flow reserves one cabin per enquiry.
  // Reject oversized parties rather than silently selling several guests one cabin.
  if (adults + children > cabin.capacity) {
    throw new QuoteInputError("Your party exceeds this cabin's capacity. Please contact our team for multiple cabins.", 409);
  }
  const departure = await prisma.departure.findFirst({
    where: { id: input.departureId, cruiseId: input.cruiseId },
  });
  if (!departure || !departure.isAvailable || departure.departureDate.getTime() < Date.now() || (departure.cabinsLeft !== null && departure.cabinsLeft <= 0)) {
    throw new QuoteInputError("This departure is unavailable. Please select another published date.", 409);
  }
  if (input.departureDate !== undefined && input.departureDate !== departure.departureDate.toISOString().slice(0, 10)) {
    throw new QuoteInputError("The date no longer matches the selected departure. Please select it again.", 409);
  }
  if (input.durationNights !== undefined && input.durationNights !== departure.durationNights) {
    throw new QuoteInputError("The duration does not match the selected departure.", 409);
  }
  if (cabin.currency !== departure.currency || departure.currency !== "USD") {
    throw new QuoteInputError("This rate needs manual currency confirmation by our team.", 409);
  }
  const configuredAmounts = [departure.priceFrom, cabin.basePrice, cabin.childRatePct, cabin.singleSupplement, departure.holidaySurcharge];
  if (configuredAmounts.some(value => !Number.isSafeInteger(value) || value < 0) || departure.priceFrom <= 0 || cabin.childRatePct > 100) {
    throw new QuoteInputError("This rate needs review by our team.", 409);
  }
  // Preserve the existing starting-rate model. This is not a guaranteed cabin-specific fare.
  // Contracted cabin/date rates and transfer products require operator verification before launch.
  const adultRate = departure.priceFrom;
  const childRate = Math.round(adultRate * cabin.childRatePct / 100);
  const singleSupplement = adults + children === 1 ? cabin.singleSupplement : 0;
  const transfer = input.transferType === "shared" ? 35 : input.transferType === "private" ? 95 : 0;
  const transferCost = input.transferType === "shared" ? 20 : input.transferType === "private" ? 70 : 0;
  // Mutually exclusive rows: do not count the single supplement twice in the breakdown.
  const passengerSubtotal = adults * adultRate + children * childRate;
  const holidaySurcharge = departure.holidaySurcharge;
  const total = passengerSubtotal + singleSupplement + holidaySurcharge + transfer;
  const adultCostRate = departure.netCostFrom ?? cabin.netCost;
  const childCostRate = adultCostRate === null ? null : Math.round(adultCostRate * cabin.childRatePct / 100);
  // Missing supplier costs are unknown, never a zero-cost/100%-margin assumption.
  const quotedCost = adultCostRate === null || childCostRate === null ? null : adults * adultCostRate + children * childCostRate + transferCost;
  const quotedMargin = quotedCost === null ? null : total - quotedCost;
  return {
    currency: departure.currency,
    cruise: { id: cabin.cruise.id, slug: cabin.cruise.slug, name: cabin.cruise.name },
    cabin: { id: cabin.id, name: cabin.name },
    departure: { id: departure.id, departureDate: departure.departureDate, durationNights: departure.durationNights },
    adults, children, adultRate, childRate, singleSupplement, passengerSubtotal,
    holidaySurcharge, transfer, total,
    adultCostRate, childCostRate, transferCost, quotedCost, quotedMargin,
    marginPct: quotedMargin === null ? null : Math.round(quotedMargin / total * 1000) / 10,
  };
}
