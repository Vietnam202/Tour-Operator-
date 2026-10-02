import { createHash, timingSafeEqual } from "node:crypto";
import { prisma } from "@/lib/prisma";
import { readStaffCookie } from "@/lib/staff-session";

export type StaffRole = "ADMIN" | "OPERATIONS" | "SALES" | "FINANCE";
const rolePermissions: Record<StaffRole, Set<string>> = {
  ADMIN: new Set(["bookings:read", "bookings:write", "inventory:read", "inventory:write", "payments:read", "payments:write", "staff:write"]),
  OPERATIONS: new Set(["bookings:read", "bookings:write", "inventory:read", "inventory:write", "payments:read"]),
  SALES: new Set(["bookings:read", "bookings:write", "inventory:read", "payments:read", "payments:write"]),
  FINANCE: new Set(["bookings:read", "payments:read", "payments:write"]),
};
export function hashSessionToken(token: string): string {
  return createHash("sha256").update(token).digest("hex");
}
export async function getStaffSession(request: Request) {
  const { token } = readStaffCookie(request);
  if (!token) return null;
  return prisma.staffSession.findFirst({
    where: { tokenHash: hashSessionToken(token), expiresAt: { gt: new Date() }, user: { isActive: true } },
    select: {
      id: true, userId: true, createdAt: true, expiresAt: true,
      user: { select: { id: true, name: true, email: true, role: true, isActive: true } },
    },
  });
}
export async function requireAdminPermission(request: Request, permission: string) {
  const cookie = readStaffCookie(request);
  const session = await getStaffSession(request);
  if (session) return rolePermissions[session.user.role]?.has(permission) ? session.user : null;
  // An expired, inactive, invalid or ambiguous browser session must never elevate
  // to the legacy administrator identity. Compatibility access is opt-in only.
  if (cookie.present || process.env.ENABLE_LEGACY_ADMIN_API_KEY !== "true") return null;
  const expected = process.env.ADMIN_API_KEY;
  const supplied = request.headers.get("x-admin-key");
  if (!expected || expected.length < 32 || !supplied || supplied.length > 512) return null;
  const left = createHash("sha256").update(expected).digest();
  const right = createHash("sha256").update(supplied).digest();
  if (!timingSafeEqual(left, right)) return null;
  return { id: "legacy-api-key", email: "legacy-admin", name: "Legacy admin", role: "ADMIN" as const, isActive: true };
}
export async function isAdminAuthorized(request: Request) {
  return Boolean(await requireAdminPermission(request, "bookings:read"));
}
