import { NextResponse } from "next/server";
import { getStaffSession } from "@/lib/admin-auth";
import { staffHttpError, staffResponseHeaders } from "@/lib/staff-http";

export async function GET(request: Request) {
  try {
    const session = await getStaffSession(request);
    if (!session) return NextResponse.json({ authenticated: false }, { status: 401, headers: staffResponseHeaders });
    return NextResponse.json({ authenticated: true, user: {
      id: session.user.id, name: session.user.name, email: session.user.email, role: session.user.role,
    }, expiresAt: session.expiresAt.toISOString() }, { headers: staffResponseHeaders });
  } catch (error) { return staffHttpError(error); }
}
