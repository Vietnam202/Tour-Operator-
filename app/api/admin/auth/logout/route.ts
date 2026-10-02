import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { hashSessionToken } from "@/lib/admin-auth";
import { readStaffCookie, STAFF_COOKIE_NAME, staffCookieOptions } from "@/lib/staff-session";
import { requireStaffOrigin, staffHttpError, staffResponseHeaders } from "@/lib/staff-http";

export async function POST(request: Request) {
  try {
    requireStaffOrigin(request);
    const { token } = readStaffCookie(request);
    if (token) await prisma.staffSession.deleteMany({ where: { tokenHash: hashSessionToken(token) } });
    const response = NextResponse.json({ ok: true }, { headers: staffResponseHeaders });
    response.cookies.set(STAFF_COOKIE_NAME, "", staffCookieOptions(0));
    return response;
  } catch (error) {
    // Do not claim server-side logout succeeded if database revocation failed.
    return staffHttpError(error);
  }
}
