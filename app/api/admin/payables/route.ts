import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";
import { sameOrigin } from "@/lib/csrf";

export async function POST(request:Request){
 if(!sameOrigin(request)) return NextResponse.json({error:"Invalid request origin"},{status:403});
 if(!(await requireAdminPermission(request,"payments:write"))) return NextResponse.json({error:"Unauthorized"},{status:401});
 const body=await request.json(); const amount=Math.round(Number(body.amount));
 if(!body.bookingId||!body.supplierId||!Number.isFinite(amount)||amount<=0) return NextResponse.json({error:"Booking, supplier and positive amount are required"},{status:400});
 const payable=await prisma.supplierPayable.create({data:{bookingId:String(body.bookingId),supplierId:String(body.supplierId),amount,currency:String(body.currency||"USD").slice(0,3).toUpperCase(),dueDate:body.dueDate?new Date(body.dueDate):null,notes:String(body.notes||"").trim().slice(0,1000)||null}});
 return NextResponse.json({payable},{status:201});
}
