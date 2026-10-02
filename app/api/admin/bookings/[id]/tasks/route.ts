import {BookingTaskStatus,BookingTaskType} from "@prisma/client";
import {NextResponse} from "next/server";
import {prisma} from "@/lib/prisma";
import {requireAdminPermission} from "@/lib/admin-auth";
import {sameOrigin} from "@/lib/csrf";
export async function POST(request:Request,{params}:{params:Promise<{id:string}>}){
 if(!sameOrigin(request))return NextResponse.json({error:"Invalid request origin"},{status:403});
 const actor=await requireAdminPermission(request,"bookings:write");if(!actor)return NextResponse.json({error:"Unauthorized"},{status:401});
 const {id}=await params;const body=await request.json();const type=String(body.type||"OTHER") as BookingTaskType;
 if(!Object.values(BookingTaskType).includes(type)||!String(body.title||"").trim())return NextResponse.json({error:"Valid task type and title are required"},{status:400});
 const task=await prisma.bookingTask.create({data:{bookingId:id,type,title:String(body.title).trim().slice(0,180),ownerId:body.ownerId||null,dueAt:body.dueAt?new Date(body.dueAt):null,notes:String(body.notes||"").trim().slice(0,1000)||null}});
 await prisma.bookingActivity.create({data:{bookingId:id,actorId:actor.id==="legacy-api-key"?null:actor.id,type:"TASK_CREATED",message:"Task created: "+task.title}});
 return NextResponse.json({task},{status:201});
}
