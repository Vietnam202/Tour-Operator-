import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";

export async function GET(_request: Request,{params}:{params:Promise<{reference:string}>}) {
  const { reference } = await params;
  const booking = await prisma.bookingInquiry.findUnique({
    where:{reference},
    select:{
      reference:true,cruiseName:true,cabinName:true,departureDate:true,durationNights:true,
      adults:true,children:true,primaryGuest:true,nationality:true,transferType:true,
      estimatedTotal:true,amountPaid:true,currency:true,status:true,paymentStatus:true
    }
  });
  if(!booking || booking.status!=="CONFIRMED") return NextResponse.json({error:"Voucher unavailable"},{status:404});
  if(booking.paymentStatus!=="PAID" && booking.paymentStatus!=="PARTIALLY_PAID") return NextResponse.json({error:"Voucher becomes available after payment"},{status:409});
  return NextResponse.json({voucher:booking});
}
