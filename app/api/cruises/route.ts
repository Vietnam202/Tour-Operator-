import { NextResponse } from "next/server";
import { getPublicCruises } from "@/lib/public-cruises";

export const dynamic = "force-dynamic";

export async function GET() {
  try {
    return NextResponse.json({ cruises: await getPublicCruises() }, { headers: { "Cache-Control": "no-store" } });
  } catch {
    return NextResponse.json({ error: "Cruise inventory is temporarily unavailable." }, { status: 503 });
  }
}
