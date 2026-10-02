import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export async function GET(request: Request) {
  const url = new URL(request.url);
  const slug = url.searchParams.get("cruise");
  if (!slug) return NextResponse.json({ error: "cruise is required" }, { status: 400 });

  try {
    const cruise = await prisma.cruise.findFirst({
      where: { slug, status: "PUBLISHED" },
      include: {
        cabins: { where: { isActive: true }, orderBy: { basePrice: "asc" } },
        departures: {
          where: { isAvailable: true, departureDate: { gte: new Date() } },
          orderBy: { departureDate: "asc" }
        }
      }
    });
    if (!cruise) return NextResponse.json({ error: "Cruise not found" }, { status: 404 });
    return NextResponse.json({ cruise });
  } catch {
    return NextResponse.json({ error: "Booking inventory unavailable" }, { status: 503 });
  }
}
