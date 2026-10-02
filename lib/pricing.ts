import { prisma } from "@/lib/prisma";

export type QuoteInput = {
  cruiseId: string;
  cabinId: string;
  departureId?: string | null;
  adults: number;
  children: number;
  transferType?: string | null;
};

export async function calculateQuote(input: QuoteInput) {
  const adults = Math.max(1, Math.floor(input.adults));
  const children = Math.max(0, Math.floor(input.children));

  const cabin = await prisma.cabin.findFirst({
    where: { id: input.cabinId, cruiseId: input.cruiseId, isActive: true },
    include: { cruise: true }
  });
  if (!cabin) throw new Error("Cabin not available");

  const departure = input.departureId
    ? await prisma.departure.findFirst({
        where: { id: input.departureId, cruiseId: input.cruiseId, isAvailable: true }
      })
    : null;

  if (departure?.cabinsLeft !== null && departure?.cabinsLeft !== undefined && departure.cabinsLeft <= 0) {
    throw new Error("This departure is sold out");
  }

  const adultRate = departure?.priceFrom ?? cabin.basePrice;
  const childRate = Math.round(adultRate * cabin.childRatePct / 100);
  const adultCostRate = departure?.netCostFrom ?? cabin.netCost ?? 0;
  const childCostRate = Math.round(adultCostRate * cabin.childRatePct / 100);
  const occupancy = adults + children;
  const singleSupplement = occupancy === 1 ? cabin.singleSupplement : 0;

  // MVP transfer pricing. Move to a TransferProduct table when multiple routes/providers are introduced.
  const transferPerBooking =
    input.transferType === "shared" ? 35 :
    input.transferType === "private" ? 95 : 0;

  // MVP supplier transfer costs. Move these into contracted transfer products later.
  const transferCost =
    input.transferType === "shared" ? 20 :
    input.transferType === "private" ? 70 : 0;

  const passengerSubtotal = adults * adultRate + children * childRate + singleSupplement;
  const supplierPassengerCost = adults * adultCostRate + children * childCostRate;
  const holidaySurcharge = departure?.holidaySurcharge ?? 0;
  const total = passengerSubtotal + holidaySurcharge + transferPerBooking;
  const quotedCost = supplierPassengerCost + transferCost;
  const quotedMargin = total - quotedCost;

  return {
    currency: departure?.currency ?? cabin.currency,
    cruise: { id: cabin.cruise.id, slug: cabin.cruise.slug, name: cabin.cruise.name },
    cabin: { id: cabin.id, name: cabin.name },
    departure: departure ? {
      id: departure.id,
      departureDate: departure.departureDate,
      durationNights: departure.durationNights
    } : null,
    adults,
    children,
    adultRate,
    childRate,
    adultCostRate,
    childCostRate,
    singleSupplement,
    passengerSubtotal,
    holidaySurcharge,
    transfer: transferPerBooking,
    transferCost,
    quotedCost,
    quotedMargin,
    marginPct: total > 0 ? Math.round((quotedMargin / total) * 1000) / 10 : 0,
    total
  };
}
