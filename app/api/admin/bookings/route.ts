import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { isAdminAuthorized } from "@/lib/admin-auth";


export async function GET(request: Request) {
  if (!isAdminAuthorized(request)) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const bookings = await prisma.bookingInquiry.findMany({
    orderBy: { createdAt: "desc" },
    take: 100,
    include: { payments: { orderBy: { createdAt: "desc" } } },
  });

  return NextResponse.json({ bookings });
}
