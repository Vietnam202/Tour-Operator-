import { CruiseStatus } from "@prisma/client";
import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";
import { sameOrigin } from "@/lib/csrf";

export async function GET(request: Request) {
  if (!(await requireAdminPermission(request,"inventory:read"))) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });

  const cruises = await prisma.cruise.findMany({
    include: {
      cabins: { orderBy: { basePrice: "asc" } },
      departures: { orderBy: { departureDate: "asc" }, take: 30 }
    },
    orderBy: { updatedAt: "desc" }
  });

  return NextResponse.json({ cruises });
}

export async function POST(request: Request) {
  if (!sameOrigin(request)) return NextResponse.json({ error: "Invalid request origin" }, { status: 403 });
  if (!(await requireAdminPermission(request,"inventory:write"))) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  const body = await request.json();

  const name = String(body.name || "").trim().slice(0, 120);
  const slug = String(body.slug || "").trim().toLowerCase().replace(/[^a-z0-9-]/g, "-").replace(/-+/g, "-").replace(/^-|-$/g, "");
  const route = String(body.route || "").trim().slice(0, 160);
  const summary = String(body.summary || "").trim().slice(0, 1200);
  const status = String(body.status || "DRAFT") as CruiseStatus;

  if (!name || !slug || !route) return NextResponse.json({ error: "name, slug and route are required" }, { status: 400 });
  if (!Object.values(CruiseStatus).includes(status)) return NextResponse.json({ error: "Invalid cruise status" }, { status: 400 });

  try {
    const cruise = await prisma.cruise.create({
      data: {
        name, slug, route,
        summary: summary || null,
        stars: Math.min(5, Math.max(1, Number(body.stars || 5))),
        badge: String(body.badge || "").trim().slice(0, 60) || null,
        heroImage: String(body.heroImage || "").trim().slice(0, 1000) || null,
        status
      }
    });
    return NextResponse.json({ cruise }, { status: 201 });
  } catch {
    return NextResponse.json({ error: "Unable to create cruise. The slug may already exist." }, { status: 400 });
  }
}
