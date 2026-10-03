import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";

const DAY=24*60*60*1000;

function daysUntil(date:Date,now:Date){
  const a=Date.UTC(now.getUTCFullYear(),now.getUTCMonth(),now.getUTCDate());
  const b=Date.UTC(date.getUTCFullYear(),date.getUTCMonth(),date.getUTCDate());
  return Math.ceil((b-a)/DAY);
}

export async function GET(request:Request){
  if(!(await requireAdminPermission(request,"bookings:read"))) {
    return NextResponse.json({error:"Unauthorized"},{status:401});
  }

  const now=new Date();
  const horizon=new Date(now.getTime()+8*DAY);

  const bookings=await prisma.bookingInquiry.findMany({
    where:{
      status:{not:"CANCELLED"},
      OR:[
        {followUpAt:{lte:horizon}},
        {departureDate:{gte:new Date(now.getTime()-DAY),lte:horizon}},
        {supplierConfirmationStatus:{not:"CONFIRMED"}},
        {tasks:{some:{status:{in:["OPEN","IN_PROGRESS"]}}}}
      ]
    },
    include:{
      assignedTo:{select:{id:true,name:true,role:true}},
      tasks:{
        where:{status:{in:["OPEN","IN_PROGRESS"]}},
        include:{owner:{select:{id:true,name:true,role:true}}},
        orderBy:[{dueAt:"asc"},{createdAt:"asc"}]
      }
    },
    orderBy:[{departureDate:"asc"},{createdAt:"asc"}],
    take:300
  });

  const row=(b:any)=>({
    id:b.id,reference:b.reference,primaryGuest:b.primaryGuest,cruiseName:b.cruiseName,
    departureDate:b.departureDate,status:b.status,
    supplierConfirmationStatus:b.supplierConfirmationStatus,followUpAt:b.followUpAt,
    assignedTo:b.assignedTo,tasks:b.tasks,daysUntilDeparture:daysUntil(b.departureDate,now)
  });

  const normalized=bookings.map(row);
  const overdueFollowUps=normalized.filter(b=>b.followUpAt&&new Date(b.followUpAt)<now);
  const overdueTasks=normalized.flatMap(b=>b.tasks
    .filter((t:any)=>t.dueAt&&new Date(t.dueAt)<now)
    .map((t:any)=>({booking:b,task:t})));
  const supplierPending=normalized.filter(b=>b.status==="CONFIRMED"&&b.supplierConfirmationStatus!=="CONFIRMED");
  const passport=normalized.flatMap(b=>b.tasks.filter((t:any)=>t.type==="PASSPORT").map((t:any)=>({booking:b,task:t})));
  const transfer=normalized.flatMap(b=>b.tasks.filter((t:any)=>t.type==="TRANSFER_DETAILS").map((t:any)=>({booking:b,task:t})));
  const next1=normalized.filter(b=>b.status==="CONFIRMED"&&b.daysUntilDeparture>=0&&b.daysUntilDeparture<=1);
  const next3=normalized.filter(b=>b.status==="CONFIRMED"&&b.daysUntilDeparture>=0&&b.daysUntilDeparture<=3);
  const next7=normalized.filter(b=>b.status==="CONFIRMED"&&b.daysUntilDeparture>=0&&b.daysUntilDeparture<=7);

  return NextResponse.json({
    generatedAt:now.toISOString(),
    counts:{
      overdueFollowUps:overdueFollowUps.length,overdueTasks:overdueTasks.length,
      supplierPending:supplierPending.length,
      passport:passport.length,transfer:transfer.length,
      next1:next1.length,next3:next3.length,next7:next7.length
    },
    queues:{overdueFollowUps,overdueTasks,supplierPending,passport,transfer,next1,next3,next7}
  },{headers:{"cache-control":"no-store"}});
}
