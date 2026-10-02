import { PrismaClient } from "@prisma/client";
const prisma = new PrismaClient();

async function main() {
  const cruise = await prisma.cruise.upsert({
    where: { slug: "stellar-of-the-seas" },
    update: {},
    create: {
      slug: "stellar-of-the-seas",
      name: "Stellar of the Seas",
      summary: "Luxury Lan Ha Bay cruise for couples, families and premium leisure travellers.",
      route: "Halong Bay - Lan Ha Bay",
      stars: 5,
      rating: 4.9,
      reviewCount: 328,
      badge: "Best Seller",
      heroImage: "https://images.unsplash.com/photo-1528127269322-539801943592?auto=format&fit=crop&w=1400&q=85",
      status: "PUBLISHED",
      cabins: {
        create: [
          { name: "Junior Suite", sizeSqm: 28, capacity: 2, basePrice: 320, description: "Private balcony - Bay view" },
          { name: "Senior Suite", sizeSqm: 32, capacity: 2, basePrice: 375, description: "Private balcony - Upper deck" },
          { name: "Executive Suite", sizeSqm: 45, capacity: 2, basePrice: 460, description: "Panoramic bay view - Bathtub" }
        ]
      }
    }
  });

  const existing = await prisma.departure.count({ where: { cruiseId: cruise.id } });
  if (!existing) {
    const dates = [14, 21, 28, 35, 42].map(days => {
      const d = new Date();
      d.setUTCDate(d.getUTCDate() + days);
      d.setUTCHours(0, 0, 0, 0);
      return d;
    });
    await prisma.departure.createMany({
      data: dates.map((departureDate, index) => ({
        cruiseId: cruise.id,
        departureDate,
        durationNights: index % 2 === 0 ? 1 : 2,
        priceFrom: index % 2 === 0 ? 320 : 520,
        cabinsLeft: 2 + (index % 4),
        isAvailable: true
      }))
    });
  }
}

main().finally(async () => prisma.$disconnect());
