import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";


export async function GET(request: Request) {
  if (!(await requireAdminPermission(request,"bookings:read"))) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const bookings = await prisma.bookingInquiry.findMany({
    orderBy: { createdAt: "desc" },
    take: 100,
    include: { payments: { orderBy: { createdAt: "desc" } }, assignedTo: { select: { id:true, name:true, email:true, role:true } } },
  });

  return NextResponse.json({ bookings });
}
