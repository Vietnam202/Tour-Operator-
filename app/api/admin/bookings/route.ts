import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

function isAuthorized(request: Request) {
  const configuredKey = process.env.ADMIN_API_KEY;
  const suppliedKey = request.headers.get("x-admin-key");
  return Boolean(configuredKey && suppliedKey && configuredKey === suppliedKey);
}

export async function GET(request: Request) {
  if (!isAuthorized(request)) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const bookings = await prisma.bookingInquiry.findMany({
    orderBy: { createdAt: "desc" },
    take: 100,
    include: { payments: { orderBy: { createdAt: "desc" } } },
  });

  return NextResponse.json({ bookings });
}
