import { randomBytes, scryptSync, timingSafeEqual } from "crypto";
import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { hashSessionToken } from "@/lib/admin-auth";
import { sameOrigin } from "@/lib/csrf";
import { rateLimit } from "@/lib/rate-limit";

function verifyPassword(password:string,stored:string) {
  const [salt,hash]=stored.split(":");
  if(!salt||!hash) return false;
  const candidate=scryptSync(password,salt,64);
  const expected=Buffer.from(hash,"hex");
  return candidate.length===expected.length && timingSafeEqual(candidate,expected);
}

export async function POST(request:Request) {
  const limit=rateLimit(request,"admin-login",8,5*60_000);
  if(!limit.allowed) return NextResponse.json({error:"Too many sign-in attempts. Try again later."},{status:429,headers:{"retry-after":String(limit.retryAfter)}});
  if (!sameOrigin(request)) return NextResponse.json({ error: "Invalid request origin" }, { status: 403 });
  const body=await request.json();
  const email=String(body.email||"").trim().toLowerCase();
  const password=String(body.password||"");
  const user=await prisma.staffUser.findUnique({where:{email}});
  if(!user||!user.isActive||!verifyPassword(password,user.passwordHash)) {
    return NextResponse.json({error:"Invalid email or password"},{status:401});
  }

  const token=randomBytes(32).toString("hex");
  await prisma.staffSession.create({
    data:{userId:user.id,tokenHash:hashSessionToken(token),expiresAt:new Date(Date.now()+12*60*60*1000)}
  });

  const response=NextResponse.json({user:{name:user.name,email:user.email,role:user.role}});
  response.cookies.set("hca_staff_session",token,{httpOnly:true,secure:process.env.NODE_ENV==="production",sameSite:"lax",path:"/",maxAge:12*60*60});
  return response;
}
