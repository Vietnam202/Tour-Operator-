import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";

export async function GET(request:Request){
  if(!(await requireAdminPermission(request,"payments:read"))) return NextResponse.json({error:"Unauthorized"},{status:401});

  const bookings=await prisma.bookingInquiry.findMany({
    where:{status:{in:["QUOTED","CONFIRMED"]}},
    select:{
      id:true,reference:true,cruiseName:true,departureDate:true,status:true,paymentStatus:true,
      estimatedTotal:true,quotedCost:true,quotedMargin:true,amountPaid:true,amountRefunded:true,currency:true,
      expenses:{select:{amount:true,category:true,paidAt:true}},
      supplierPayables:{select:{amount:true,paidAmount:true,status:true}}
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
    const expenses=b.expenses.reduce((n,e)=>n+e.amount,0);
    const supplierAccrued=b.supplierPayables.reduce((n,p)=>n+p.amount,0);
    const supplierPaid=b.supplierPayables.reduce((n,p)=>n+p.paidAmount,0);
    const actualCost=supplierAccrued+expenses;
    const actualProfit=revenue-actualCost;
    a.revenue+=revenue;a.cost+=cost;a.margin+=margin;a.collected+=netPaid;
    a.outstanding+=Math.max(0,revenue-netPaid);a.expenses+=expenses;a.supplierAccrued+=supplierAccrued;
    a.supplierPaid+=supplierPaid;a.supplierOutstanding+=Math.max(0,supplierAccrued-supplierPaid);a.actualCost+=actualCost;a.actualProfit+=actualProfit;
    return a;
  },{revenue:0,cost:0,margin:0,collected:0,outstanding:0,expenses:0,supplierAccrued:0,supplierPaid:0,supplierOutstanding:0,actualCost:0,actualProfit:0});

  return NextResponse.json({
    currency:"USD",
    totals:{...totals,marginPct:totals.revenue?Math.round(totals.margin/totals.revenue*1000)/10:0,actualMarginPct:totals.revenue?Math.round(totals.actualProfit/totals.revenue*1000)/10:0},
    bookings
  },{headers:{"cache-control":"no-store"}});
}
