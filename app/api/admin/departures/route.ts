import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

function authorized(request: Request) {
  const configured = process.env.ADMIN_API_KEY;
  return Boolean(configured && request.headers.get("x-admin-key") === configured);
}

export async function POST(request: Request) {
  if (!authorized(request)) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  const body = await request.json();

  if (!body.cruiseId || !body.departureDate || body.priceFrom === undefined) {
    return NextResponse.json({ error: "cruiseId, departureDate and priceFrom are required" }, { status: 400 });
  }

  try {
    const departure = await prisma.departure.create({
      data: {
        cruiseId: body.cruiseId,
        departureDate: new Date(body.departureDate),
        durationNights: Number(body.durationNights || 1),
        priceFrom: Number(body.priceFrom),
        holidaySurcharge: Number(body.holidaySurcharge || 0),
        currency: body.currency || "USD",
        cabinsLeft: body.cabinsLeft === "" || body.cabinsLeft == null ? null : Number(body.cabinsLeft),
        isAvailable: body.isAvailable !== false
      }
    });
    return NextResponse.json({ departure }, { status: 201 });
  } catch {
    return NextResponse.json({ error: "Unable to create departure" }, { status: 400 });
  }
}
