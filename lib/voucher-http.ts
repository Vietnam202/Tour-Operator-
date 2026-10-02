import { NextResponse } from "next/server";
import { getStaffSession } from "@/lib/admin-auth";
import { BookingOperationError } from "@/lib/booking-lock";

export const voucherHeaders = {
  "Cache-Control": "private, no-store, max-age=0",
  "Referrer-Policy": "no-referrer",
  "X-Robots-Tag": "noindex, nofollow, noarchive, nosnippet",
  "Vary": "Cookie, Authorization",
};
export const canManageVoucher = (role: string) => ["ADMIN", "OPERATIONS", "SALES"].includes(role);

export async function requireVoucherStaff(request: Request, write = false) {
  // Never allow a legacy shared API key to issue a customer bearer credential.
  const session = await getStaffSession(request);
  if (!session) throw new BookingOperationError("Please sign in with a staff account.", 401);
  if (write && !canManageVoucher(session.user.role)) throw new BookingOperationError("Your role has read-only voucher access.", 403);
  return session.user;
}

export function requireVoucherOrigin(request: Request) {
  const origin = request.headers.get("origin");
  const trusted = new Set([new URL(request.url).origin]);
  try { trusted.add(new URL(process.env.NEXT_PUBLIC_SITE_URL || "").origin); } catch { /* Request URL remains available locally. */ }
  if (!origin || !trusted.has(origin) || request.headers.get("sec-fetch-site") === "cross-site") {
    throw new BookingOperationError("Invalid request origin.", 403);
  }
}

export function voucherError(error: unknown) {
  if (error instanceof BookingOperationError) return NextResponse.json({ error: error.message }, { status: error.status, headers: voucherHeaders });
  if (error instanceof SyntaxError) return NextResponse.json({ error: "Invalid JSON request." }, { status: 400, headers: voucherHeaders });
  return NextResponse.json({ error: "Voucher service is temporarily unavailable." }, { status: 503, headers: voucherHeaders });
}
