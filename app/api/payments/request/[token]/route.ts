import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export async function GET(_request: Request,{ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const paymentRequest = await prisma.paymentRequest.findUnique({
    where: { token },
    include: { booking: { select: {
      reference:true,cruiseName:true,cabinName:true,departureDate:true,durationNights:true,
      adults:true,children:true,primaryGuest:true,estimatedTotal:true,amountPaid:true,paymentStatus:true,currency:true
    }}}
  });
  if (!paymentRequest || paymentRequest.status !== "ACTIVE") return NextResponse.json({ error: "Payment request is unavailable" }, { status: 404 });
  if (paymentRequest.expiresAt && paymentRequest.expiresAt < new Date()) {
    await prisma.paymentRequest.update({ where:{id:paymentRequest.id},data:{status:"EXPIRED"} });
    return NextResponse.json({ error: "Payment request has expired" }, { status: 410 });
  }
  return NextResponse.json({ payment: {
    token:paymentRequest.token,kind:paymentRequest.kind,amount:paymentRequest.amount,currency:paymentRequest.currency,
    expiresAt:paymentRequest.expiresAt,providerUrl:paymentRequest.providerUrl,booking:paymentRequest.booking
  }});
}
