import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";


export async function POST(request: Request) {
  if (!(await requireAdminPermission(request,"inventory:write"))) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  const body = await request.json();

  if (!body.cruiseId || !body.name || body.basePrice === undefined) {
    return NextResponse.json({ error: "cruiseId, name and basePrice are required" }, { status: 400 });
  }

  try {
    const cabin = await prisma.cabin.create({
      data: {
        cruiseId: body.cruiseId,
        name: body.name,
        sizeSqm: body.sizeSqm ? Number(body.sizeSqm) : null,
        description: body.description || null,
        capacity: Number(body.capacity || 2),
        basePrice: Number(body.basePrice),
        childRatePct: Number(body.childRatePct || 70),
        singleSupplement: Number(body.singleSupplement || 0),
        currency: body.currency || "USD",
        image: body.image || null,
        isActive: body.isActive !== false
      }
    });
    return NextResponse.json({ cabin }, { status: 201 });
  } catch {
    return NextResponse.json({ error: "Unable to create cabin" }, { status: 400 });
  }
}
