import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";
import { paymentPlan } from "@/lib/payments";
import { sameOrigin } from "@/lib/csrf";


export async function POST(request: Request) {
  if (!sameOrigin(request)) return NextResponse.json({ error: "Invalid request origin" }, { status: 403 });
  if (!(await requireAdminPermission(request,"payments:write"))) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  const body = await request.json();
  const booking = await prisma.bookingInquiry.findUnique({ where: { id: body.bookingId } });
  if (!booking || !booking.estimatedTotal) return NextResponse.json({ error: "Booking or quoted total not found" }, { status: 404 });
  if (booking.status !== "CONFIRMED") return NextResponse.json({ error: "Confirm inventory before creating a payment plan" }, { status: 409 });

  const plan = paymentPlan(booking.estimatedTotal, Number(body.depositPct || 30));
  const updated = await prisma.bookingInquiry.update({
    where: { id: booking.id },
    data: { depositAmount: plan.deposit, balanceAmount: plan.balance }
  });
  return NextResponse.json({ booking: updated, plan });
}
