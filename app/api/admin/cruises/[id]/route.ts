import { CruiseStatus } from "@prisma/client";
import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";
import { sameOrigin } from "@/lib/csrf";

export async function PATCH(request: Request,{
  if (!sameOrigin(request)) return NextResponse.json({ error: "Invalid request origin" }, { status: 403 });params}:{params:Promise<{id:string}>}) {
  if (!(await requireAdminPermission(request,"inventory:write"))) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  const { id } = await params;
  const body = await request.json();

  const data: Record<string, unknown> = {};
  if (body.name !== undefined) data.name = String(body.name).trim().slice(0,120);
  if (body.route !== undefined) data.route = String(body.route).trim().slice(0,160);
  if (body.summary !== undefined) data.summary = String(body.summary).trim().slice(0,1200) || null;
  if (body.stars !== undefined) data.stars = Math.min(5,Math.max(1,Number(body.stars)));
  if (body.badge !== undefined) data.badge = String(body.badge).trim().slice(0,60) || null;
  if (body.heroImage !== undefined) data.heroImage = String(body.heroImage).trim().slice(0,1000) || null;
  if (body.slug !== undefined) data.slug = String(body.slug).trim().toLowerCase().replace(/[^a-z0-9-]/g,"-").replace(/-+/g,"-").replace(/^-|-$/g,"");
  if (body.status !== undefined) {
    const status = String(body.status) as CruiseStatus;
    if (!Object.values(CruiseStatus).includes(status)) return NextResponse.json({ error:"Invalid cruise status" },{status:400});
    data.status = status;
  }

  try {
    const cruise = await prisma.cruise.update({ where:{id}, data });
    return NextResponse.json({ cruise });
  } catch {
    return NextResponse.json({ error:"Unable to update cruise" },{status:400});
  }
}
