import { BookingStatus } from "@prisma/client";
import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

const allowed = new Set(Object.values(BookingStatus));

function authorized(request: Request) {
  const configured = process.env.ADMIN_API_KEY;
  return Boolean(configured && request.headers.get("x-admin-key") === configured);
}

export async function PATCH(
  request: Request,
  { params }: { params: Promise<{ id: string }> }
) {
  if (!authorized(request)) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const { id } = await params;
  const body = await request.json();
  const status = String(body.status || "") as BookingStatus;

  if (!allowed.has(status)) {
    return NextResponse.json({ error: "Invalid booking status" }, { status: 400 });
  }

  try {
    const booking = await prisma.bookingInquiry.update({
      where: { id },
      data: { status },
    });
    return NextResponse.json({ booking });
  } catch {
    return NextResponse.json({ error: "Booking not found" }, { status: 404 });
  }
}
