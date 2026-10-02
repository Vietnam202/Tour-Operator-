import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export async function GET() {
  try {
    const cruises = await prisma.cruise.findMany({
      where: { status: "PUBLISHED" },
      include: {
        cabins: { where: { isActive: true }, orderBy: { basePrice: "asc" } },
        departures: {
          where: { isAvailable: true, departureDate: { gte: new Date() } },
          orderBy: { departureDate: "asc" },
          take: 8,
        },
      },
      orderBy: [{ rating: "desc" }, { reviewCount: "desc" }],
    });

    return NextResponse.json({ cruises });
  } catch {
    return NextResponse.json(
      { error: "Cruise inventory is not available yet." },
      { status: 503 }
    );
  }
}
