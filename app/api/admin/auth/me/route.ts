import { NextResponse } from "next/server";
import { getStaffSession } from "@/lib/admin-auth";

export async function GET(request:Request) {
  const session=await getStaffSession(request);
  if(!session) return NextResponse.json({authenticated:false},{status:401});
  return NextResponse.json({authenticated:true,user:{name:session.user.name,email:session.user.email,role:session.user.role}});
}
