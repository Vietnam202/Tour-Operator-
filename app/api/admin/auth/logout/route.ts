import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { hashSessionToken } from "@/lib/admin-auth";

function sessionToken(request:Request) {
  const raw=request.headers.get("cookie")||"";
  return raw.split(";").map(v=>v.trim()).find(v=>v.startsWith("hca_staff_session="))?.split("=")[1]||null;
}
export async function POST(request:Request) {
  const token=sessionToken(request);
  if(token) await prisma.staffSession.deleteMany({where:{tokenHash:hashSessionToken(decodeURIComponent(token))}});
  const response=NextResponse.json({ok:true});
  response.cookies.set("hca_staff_session","",{httpOnly:true,secure:process.env.NODE_ENV==="production",sameSite:"lax",path:"/",maxAge:0});
  return response;
}
