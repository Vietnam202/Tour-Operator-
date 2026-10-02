import { NextResponse } from "next/server";
import { calculateQuote } from "@/lib/pricing";

export async function POST(request: Request) {
  try {
    const body = await request.json();
    if (!body.cruiseId || !body.cabinId) {
      return NextResponse.json({ error: "cruiseId and cabinId are required" }, { status: 400 });
    }
    const quote = await calculateQuote({
      cruiseId: body.cruiseId,
      cabinId: body.cabinId,
      departureId: body.departureId || null,
      adults: Number(body.adults || 1),
      children: Number(body.children || 0),
      transferType: body.transferType || "none"
    });
    return NextResponse.json({ quote });
  } catch (error) {
    return NextResponse.json(
      { error: error instanceof Error ? error.message : "Unable to calculate quote" },
      { status: 400 }
    );
  }
}
