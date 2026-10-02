import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getStaffSession } from "@/lib/admin-auth";
import { STAFF_COOKIE_NAME, staffCookieOptions } from "@/lib/staff-session";
import { readStaffJson, requireStaffOrigin, StaffHttpError, staffHttpError, staffResponseHeaders } from "@/lib/staff-http";

export async function GET(request: Request) {
  try {
    const session = await getStaffSession(request);
    if (!session) throw new StaffHttpError("Please sign in with a staff account.", 401);
    const sessions = await prisma.staffSession.findMany({
      where: { userId: session.userId, expiresAt: { gt: new Date() } },
      select: { id: true, createdAt: true, expiresAt: true }, orderBy: { createdAt: "desc" },
    });
    return NextResponse.json({ sessions: sessions.map(item => ({
      id: item.id, createdAt: item.createdAt.toISOString(), expiresAt: item.expiresAt.toISOString(), current: item.id === session.id,
    })) }, { headers: staffResponseHeaders });
  } catch (error) { return staffHttpError(error); }
}

export async function DELETE(request: Request) {
  try {
    requireStaffOrigin(request);
    const session = await getStaffSession(request);
    if (!session) throw new StaffHttpError("Please sign in with a staff account.", 401);
    const input = await readStaffJson(request);
    if (Object.keys(input).length !== 1 || (input.scope !== "others" && input.scope !== "all")) {
      throw new StaffHttpError("Choose either other sessions or all sessions.", 400);
    }
    const scope = input.scope;
    const result = await prisma.$transaction(async tx => {
      await tx.$queryRaw`SELECT "id" FROM "StaffUser" WHERE "id" = ${session.userId} FOR UPDATE`;
      const active = await tx.staffSession.findFirst({ where: { id: session.id, userId: session.userId,
        expiresAt: { gt: new Date() }, user: { isActive: true } }, select: { id: true } });
      if (!active) throw new StaffHttpError("Please sign in again.", 401);
      return tx.staffSession.deleteMany({ where: { userId: session.userId, ...(scope === "others" ? { id: { not: session.id } } : {}) } });
    });
    const response = NextResponse.json({ revoked: result.count, signedOut: scope === "all" }, { headers: staffResponseHeaders });
    if (scope === "all") response.cookies.set(STAFF_COOKIE_NAME, "", staffCookieOptions(0));
    return response;
  } catch (error) { return staffHttpError(error); }
}
