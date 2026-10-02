import { randomBytes } from "node:crypto";
import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { hashSessionToken } from "@/lib/admin-auth";
import { readStaffCookie, STAFF_COOKIE_NAME, STAFF_SESSION_SECONDS, staffCookieOptions } from "@/lib/staff-session";
import { readStaffJson, requireStaffOrigin, StaffHttpError, staffHttpError, staffResponseHeaders } from "@/lib/staff-http";
import { verifyStaffPassword } from "@/lib/staff-password";
import { staffLoginThrottle } from "@/lib/staff-login-throttle";

export async function POST(request: Request) {
  try {
    requireStaffOrigin(request);
    const body = await readStaffJson(request);
    if (Object.keys(body).some(key => key !== "email" && key !== "password") ||
      typeof body.email !== "string" || body.email.length > 200 ||
      typeof body.password !== "string" || body.password.length < 1 || body.password.length > 256 || Buffer.byteLength(body.password, "utf8") > 1024) {
      throw new StaffHttpError("Provide a valid email and password.", 400);
    }
    const email = body.email.trim().toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new StaffHttpError("Provide a valid email and password.", 400);
    const limit = staffLoginThrottle(email);
    if (!limit.allowed) return NextResponse.json({ error: "Too many sign-in attempts. Try again later." }, {
      status: 429, headers: { ...staffResponseHeaders, "Retry-After": String(limit.retryAfter) },
    });
    const user = await prisma.staffUser.findUnique({ where: { email },
      select: { id: true, name: true, email: true, role: true, isActive: true, passwordHash: true } });
    const verified = await verifyStaffPassword(body.password, user?.passwordHash ?? null);
    if (!user || !user.isActive || !verified) throw new StaffHttpError("Invalid email or password.", 401);
    const token = randomBytes(32).toString("hex");
    const oldToken = readStaffCookie(request).token;
    const publicUser = await prisma.$transaction(async tx => {
      // Session issuance and bulk revocation share this lock order. Recheck the
      // account after password derivation so a disabled/changed account cannot race.
      await tx.$queryRaw`SELECT "id" FROM "StaffUser" WHERE "id" = ${user.id} FOR UPDATE`;
      const fresh = await tx.staffUser.findUnique({ where: { id: user.id } });
      if (!fresh?.isActive || fresh.passwordHash !== user.passwordHash) throw new StaffHttpError("Invalid email or password.", 401);
      if (oldToken) await tx.staffSession.deleteMany({ where: { userId: user.id, tokenHash: hashSessionToken(oldToken) } });
      await tx.staffSession.deleteMany({ where: { userId: user.id, expiresAt: { lte: new Date() } } });
      await tx.staffSession.create({ data: { userId: user.id, tokenHash: hashSessionToken(token),
        expiresAt: new Date(Date.now() + STAFF_SESSION_SECONDS * 1000) } });
      return { id: fresh.id, name: fresh.name, email: fresh.email, role: fresh.role };
    });
    const response = NextResponse.json({ user: publicUser }, { headers: staffResponseHeaders });
    response.cookies.set(STAFF_COOKIE_NAME, token, staffCookieOptions());
    return response;
  } catch (error) { return staffHttpError(error); }
}
