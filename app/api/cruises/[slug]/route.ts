import { NextResponse } from "next/server";
import { getPublicCruise } from "@/lib/public-cruises";

export const dynamic = "force-dynamic";

export async function GET(_request: Request, { params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  if (!slug || slug.length > 200) return NextResponse.json({ error: "Cruise not found." }, { status: 404 });
  try {
    const cruise = await getPublicCruise(slug);
    if (!cruise) return NextResponse.json({ error: "Cruise not found." }, { status: 404 });
    return NextResponse.json({ cruise }, { headers: { "Cache-Control": "no-store" } });
  } catch {
    return NextResponse.json({ error: "Cruise inventory is temporarily unavailable." }, { status: 503 });
  }
}
