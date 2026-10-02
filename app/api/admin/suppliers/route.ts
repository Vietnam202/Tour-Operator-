import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";
import { sameOrigin } from "@/lib/csrf";

export async function GET(request:Request){
  if(!(await requireAdminPermission(request,"inventory:read"))) return NextResponse.json({error:"Unauthorized"},{status:401});
  const suppliers=await prisma.supplier.findMany({include:{_count:{select:{cruises:true}}},orderBy:{name:"asc"}});
  return NextResponse.json({suppliers});
}
export async function POST(request:Request){
  if(!sameOrigin(request)) return NextResponse.json({error:"Invalid request origin"},{status:403});
  if(!(await requireAdminPermission(request,"inventory:write"))) return NextResponse.json({error:"Unauthorized"},{status:401});
  const body=await request.json();
  const name=String(body.name||"").trim().slice(0,160);
  if(!name) return NextResponse.json({error:"Supplier name is required"},{status:400});
  const supplier=await prisma.supplier.create({data:{
    name,
    legalName:String(body.legalName||"").trim().slice(0,200)||null,
    contactName:String(body.contactName||"").trim().slice(0,120)||null,
    email:String(body.email||"").trim().slice(0,200)||null,
    phone:String(body.phone||"").trim().slice(0,50)||null,
    currency:String(body.currency||"USD").trim().slice(0,3).toUpperCase(),
    notes:String(body.notes||"").trim().slice(0,3000)||null
  }});
  return NextResponse.json({supplier},{status:201});
}
