import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getStaffSession } from "@/lib/admin-auth";
import { hashVoucherToken, publicVoucher, voucherBookingSelect, voucherReadiness } from "@/lib/voucher-access";
import { canManageVoucher, voucherError, voucherHeaders } from "@/lib/voucher-http";
import { rateLimit } from "@/lib/rate-limit";

export const dynamic = "force-dynamic";
const unavailable = () => NextResponse.json({ error: "Voucher unavailable. Please contact our team for a new private link." },
  { status: 404, headers: voucherHeaders });

export async function GET(request: Request, { params }: { params: Promise<{ reference: string }> }) {
  const limit = rateLimit(request, "voucher-read", 120, 60000);
  if (!limit.allowed) return NextResponse.json({ error: "Please try again shortly." },
    { status: 429, headers: { ...voucherHeaders, "Retry-After": String(limit.retryAfter) } });
  try {
    const { reference } = await params;
    if (!reference || reference.length > 120) return unavailable();
    const authorization = request.headers.get("authorization");
    if (authorization !== null) {
      const match = /^Bearer ([a-f0-9]{64})$/.exec(authorization);
      if (!match) return unavailable();
      const grant = await prisma.voucherGrant.findFirst({
        where: { tokenHash: hashVoucherToken(match[1]), revokedAt: null, expiresAt: { gt: new Date() }, booking: { reference } },
        select: { booking: { select: voucherBookingSelect } },
      });
      if (!grant || voucherReadiness(grant.booking)) return unavailable();
      return NextResponse.json({ voucher: publicVoucher(grant.booking) }, { headers: voucherHeaders });
    }
    // Reference-only URLs remain useful for authenticated staff previews, never for guests.
    const session = await getStaffSession(request);
    if (!session) return unavailable();
    const booking = await prisma.bookingInquiry.findUnique({ where: { reference }, select: voucherBookingSelect });
    if (!booking) return unavailable();
    const blockedReason = voucherReadiness(booking);
    const access = { mode: "staff", bookingId: booking.id, canManage: canManageVoucher(session.user.role) };
    if (blockedReason) return NextResponse.json({ error: blockedReason, access }, { status: 409, headers: voucherHeaders });
    return NextResponse.json({ voucher: publicVoucher(booking), access }, { headers: voucherHeaders });
  } catch (error) { return voucherError(error); }
}
