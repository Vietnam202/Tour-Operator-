import {SupplierConfirmationStatus} from "@prisma/client";
import {NextResponse} from "next/server";
import {prisma} from "@/lib/prisma";
import {requireAdminPermission} from "@/lib/admin-auth";
import {sameOrigin} from "@/lib/csrf";
import {completeTasksByType} from "@/lib/booking-automation";
import {BookingTaskType} from "@prisma/client";
export async function PATCH(request:Request,{params}:{params:Promise<{id:string}>}){
 if(!sameOrigin(request))return NextResponse.json({error:"Invalid request origin"},{status:403});
 const actor=await requireAdminPermission(request,"bookings:write");if(!actor)return NextResponse.json({error:"Unauthorized"},{status:401});
 const {id}=await params;const body=await request.json();const status=String(body.status||"") as SupplierConfirmationStatus;
 if(!Object.values(SupplierConfirmationStatus).includes(status))return NextResponse.json({error:"Invalid supplier confirmation status"},{status:400});
 const ref=String(body.reference||"").trim().slice(0,120)||null;
 const booking=await prisma.$transaction(async tx=>{
  const updated=await tx.bookingInquiry.update({where:{id},data:{supplierConfirmationStatus:status,supplierConfirmationRef:ref,supplierConfirmedAt:status==="CONFIRMED"?new Date():null}});
  await tx.bookingActivity.create({data:{bookingId:id,actorId:actor.id==="legacy-api-key"?null:actor.id,type:"SUPPLIER_CONFIRMATION",message:"Supplier confirmation changed to "+status+(ref?" · "+ref:"")}});
  if(status==="CONFIRMED")await tx.bookingTask.updateMany({where:{bookingId:id,type:"SUPPLIER_CONFIRMATION",status:{in:["OPEN","IN_PROGRESS"]}},data:{status:"DONE",completedAt:new Date()}});
  return updated;
 });
 if(status==="CONFIRMED") await completeTasksByType(id,BookingTaskType.SUPPLIER_CONFIRMATION,"Automation completed supplier confirmation task");
 return NextResponse.json({booking});
}
