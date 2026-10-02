import {BookingTaskStatus} from "@prisma/client";
import {NextResponse} from "next/server";
import {prisma} from "@/lib/prisma";
import {requireAdminPermission} from "@/lib/admin-auth";
import {sameOrigin} from "@/lib/csrf";
export async function PATCH(request:Request,{params}:{params:Promise<{id:string;taskId:string}>}){
 if(!sameOrigin(request))return NextResponse.json({error:"Invalid request origin"},{status:403});
 const actor=await requireAdminPermission(request,"bookings:write");if(!actor)return NextResponse.json({error:"Unauthorized"},{status:401});
 const {id,taskId}=await params;const body=await request.json();const status=String(body.status||"") as BookingTaskStatus;
 if(!Object.values(BookingTaskStatus).includes(status))return NextResponse.json({error:"Invalid task status"},{status:400});
 const task=await prisma.bookingTask.update({where:{id:taskId},data:{status,completedAt:status==="DONE"?new Date():null}});
 if(task.bookingId!==id)return NextResponse.json({error:"Task does not belong to booking"},{status:400});
 await prisma.bookingActivity.create({data:{bookingId:id,actorId:actor.id==="legacy-api-key"?null:actor.id,type:"TASK_STATUS",message:"Task "+task.title+" changed to "+status}});
 return NextResponse.json({task});
}
