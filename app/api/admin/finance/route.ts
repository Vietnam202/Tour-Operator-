import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";

export async function GET(request:Request){
  if(!(await requireAdminPermission(request,"payments:read"))) return NextResponse.json({error:"Unauthorized"},{status:401});

  const bookings=await prisma.bookingInquiry.findMany({
    where:{status:{in:["QUOTED","CONFIRMED"]}},
    select:{
      id:true,reference:true,cruiseName:true,departureDate:true,status:true,paymentStatus:true,
      estimatedTotal:true,quotedCost:true,quotedMargin:true,amountPaid:true,amountRefunded:true,currency:true
    },
    orderBy:{createdAt:"desc"},
    take:250
  });

  const active=bookings.filter(b=>b.status!=="CANCELLED");
  const totals=active.reduce((a,b)=>{
    const revenue=b.estimatedTotal||0;
    const cost=b.quotedCost||0;
    const margin=b.quotedMargin ?? (revenue-cost);
    const netPaid=Math.max(0,(b.amountPaid||0)-(b.amountRefunded||0));
    a.revenue+=revenue;a.cost+=cost;a.margin+=margin;a.collected+=netPaid;
    a.outstanding+=Math.max(0,revenue-netPaid);
    return a;
  },{revenue:0,cost:0,margin:0,collected:0,outstanding:0});

  return NextResponse.json({
    currency:"USD",
    totals:{...totals,marginPct:totals.revenue?Math.round(totals.margin/totals.revenue*1000)/10:0},
    bookings
  },{headers:{"cache-control":"no-store"}});
}
