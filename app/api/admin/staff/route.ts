import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";

export async function GET(request:Request){
  if(!(await requireAdminPermission(request,"bookings:read"))) return NextResponse.json({error:"Unauthorized"},{status:401});
  const staff=await prisma.staffUser.findMany({where:{isActive:true},select:{id:true,name:true,email:true,role:true},orderBy:{name:"asc"}});
  return NextResponse.json({staff},{headers:{"cache-control":"no-store"}});
}
