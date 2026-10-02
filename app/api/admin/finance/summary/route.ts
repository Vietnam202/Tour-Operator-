import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";

export async function GET(request:Request){
  if(!(await requireAdminPermission(request,"payments:read"))) return NextResponse.json({error:"Unauthorized"},{status:401});
  const bookings=await prisma.bookingInquiry.findMany({
    where:{status:{in:["QUOTED","CONFIRMED"]}},
    select:{estimatedTotal:true,quotedCost:true,quotedMargin:true,amountPaid:true,balanceAmount:true,paymentStatus:true,currency:true}
  });
  const summary=bookings.reduce((a,b)=>{
    const revenue=b.estimatedTotal||0;
    const cost=b.quotedCost||0;
    a.revenue+=revenue;
    a.cost+=cost;
    a.grossProfit+=b.quotedMargin??(revenue-cost);
    a.collected+=b.amountPaid||0;
    a.outstanding+=Math.max(0,revenue-(b.amountPaid||0));
    return a;
  },{revenue:0,cost:0,grossProfit:0,collected:0,outstanding:0});
  return NextResponse.json({summary,bookingCount:bookings.length,currency:"USD"},{headers:{"cache-control":"no-store"}});
}
