import { createHash, timingSafeEqual } from "crypto";
import { prisma } from "@/lib/prisma";

export type StaffRole = "ADMIN" | "OPERATIONS" | "SALES" | "FINANCE";
const rolePermissions: Record<StaffRole, Set<string>> = {
  ADMIN: new Set(["bookings:read","bookings:write","inventory:read","inventory:write","payments:read","payments:write","staff:write"]),
  OPERATIONS: new Set(["bookings:read","bookings:write","inventory:read","inventory:write","payments:read"]),
  SALES: new Set(["bookings:read","bookings:write","inventory:read","payments:read","payments:write"]),
  FINANCE: new Set(["bookings:read","payments:read","payments:write"])
};

function safeEqual(a:string,b:string) {
  const aa=Buffer.from(a); const bb=Buffer.from(b);
  return aa.length===bb.length && timingSafeEqual(aa,bb);
}
function cookie(request:Request,name:string) {
  const raw=request.headers.get("cookie")||"";
  for(const part of raw.split(";")) {
    const [key,...rest]=part.trim().split("=");
    if(key===name) return decodeURIComponent(rest.join("="));
  }
  return null;
}
export function hashSessionToken(token:string) {
  return createHash("sha256").update(token).digest("hex");
}
export async function getStaffSession(request:Request) {
  const token=cookie(request,"hca_staff_session");
  if(!token) return null;
  return prisma.staffSession.findFirst({
    where:{tokenHash:hashSessionToken(token),expiresAt:{gt:new Date()},user:{isActive:true}},
    include:{user:true}
  });
}
export async function requireAdminPermission(request:Request,permission:string) {
  const session=await getStaffSession(request);
  if(session) {
    const role=session.user.role as StaffRole;
    return rolePermissions[role]?.has(permission) ? session.user : null;
  }

  // Temporary migration fallback. Remove after all staff accounts are active.
  const expected=process.env.ADMIN_API_KEY;
  const supplied=request.headers.get("x-admin-key");
  if(expected&&supplied&&safeEqual(expected,supplied)) {
    return {id:"legacy-api-key",email:"legacy-admin",name:"Legacy admin",role:"ADMIN",isActive:true};
  }
  return null;
}
export async function isAdminAuthorized(request:Request) {
  return Boolean(await requireAdminPermission(request,"bookings:read"));
}
