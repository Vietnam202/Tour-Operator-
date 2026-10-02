import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";
import { sameOrigin } from "@/lib/csrf";

export async function PATCH(request:Request,{params}:{params:Promise<{id:string}>}){
 if(!sameOrigin(request)) return NextResponse.json({error:"Invalid request origin"},{status:403});
 if(!(await requireAdminPermission(request,"payments:write"))) return NextResponse.json({error:"Unauthorized"},{status:401});
 const {id}=await params; const body=await request.json(); const payment=Math.round(Number(body.payment));
 if(!Number.isFinite(payment)||payment<=0) return NextResponse.json({error:"Positive payment amount is required"},{status:400});
 try{
  const payable=await prisma.$transaction(async tx=>{
   const current=await tx.supplierPayable.findUnique({where:{id}});
   if(!current) throw new Error("NOT_FOUND");
   if(current.status==="CANCELLED") throw new Error("CANCELLED");
   const paidAmount=Math.min(current.amount,current.paidAmount+payment);
   const status=paidAmount>=current.amount?"PAID":"PARTIALLY_PAID";
   return tx.supplierPayable.update({where:{id},data:{paidAmount,status}});
  });
  return NextResponse.json({payable});
 }catch(e){
  const m=e instanceof Error?e.message:"";
  return NextResponse.json({error:m==="NOT_FOUND"?"Payable not found":m==="CANCELLED"?"Cancelled payable cannot be paid":"Unable to record supplier payment"},{status:m==="NOT_FOUND"?404:400});
 }
}
