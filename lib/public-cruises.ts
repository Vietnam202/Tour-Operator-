import { Prisma } from "@prisma/client";
import { prisma } from "@/lib/prisma";

// Select, rather than omit, so future internal fields remain private by default.
export function publicCruiseSelect(now = new Date()) {
  return {
    id: true, slug: true, name: true, summary: true, route: true,
    stars: true, rating: true, reviewCount: true, badge: true, heroImage: true,
    cabins: {
      where: { isActive: true },
      orderBy: { basePrice: "asc" },
      select: {
        id: true, name: true, sizeSqm: true, description: true, capacity: true,
        basePrice: true, currency: true, image: true,
      },
    },
    departures: {
      where: {
        isAvailable: true,
        departureDate: { gte: now },
        OR: [{ cabinsLeft: null }, { cabinsLeft: { gt: 0 } }],
      },
      orderBy: { departureDate: "asc" },
      select: {
        id: true, departureDate: true, durationNights: true,
        priceFrom: true, currency: true, cabinsLeft: true,
      },
    },
  } satisfies Prisma.CruiseSelect;
}

export function getPublicCruise(slug: string) {
  return prisma.cruise.findFirst({
    where: { slug, status: "PUBLISHED" },
    select: publicCruiseSelect(),
  });
}

export function getPublicCruises() {
  return prisma.cruise.findMany({
    where: { status: "PUBLISHED" },
    select: publicCruiseSelect(),
    orderBy: [{ rating: "desc" }, { reviewCount: "desc" }],
  });
}
