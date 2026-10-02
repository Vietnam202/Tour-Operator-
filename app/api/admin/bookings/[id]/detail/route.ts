import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";

export async function GET(request:Request,{params}:{params:Promise<{id:string}>}){
  if(!(await requireAdminPermission(request,"bookings:read"))) return NextResponse.json({error:"Unauthorized"},{status:401});
  const {id}=await params;
  const booking=await prisma.bookingInquiry.findUnique({
    where:{id},
    include:{
      assignedTo:{select:{id:true,name:true,email:true,role:true}},
      cruise:{select:{id:true,name:true,slug:true,supplierId:true,supplier:{select:{id:true,name:true,email:true,phone:true}}}},
      payments:{orderBy:{createdAt:"desc"}},
      paymentRequests:{orderBy:{createdAt:"desc"}},
      expenses:{orderBy:{createdAt:"desc"}},
      supplierPayables:{include:{supplier:{select:{id:true,name:true,email:true,phone:true}}},orderBy:{createdAt:"desc"}},
      tasks:{include:{owner:{select:{id:true,name:true,role:true}}},orderBy:[{status:"asc"},{dueAt:"asc"}]},
      activities:{include:{actor:{select:{id:true,name:true,role:true}}},orderBy:{createdAt:"desc"},take:100}
    }
  });
  if(!booking) return NextResponse.json({error:"Booking not found"},{status:404});
  return NextResponse.json({booking},{headers:{"cache-control":"no-store"}});
}
