import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { BookingOperationError } from "@/lib/booking-lock";
import { issueVoucherGrant, revokeVoucherGrants, voucherBookingSelect, voucherReadiness } from "@/lib/voucher-access";
import { canManageVoucher, requireVoucherOrigin, requireVoucherStaff, voucherError, voucherHeaders } from "@/lib/voucher-http";
import { rateLimit } from "@/lib/rate-limit";

type Context = { params: Promise<{ id: string }> };
export const dynamic = "force-dynamic";

export async function GET(request: Request, { params }: Context) {
  try {
    const staff = await requireVoucherStaff(request);
    const { id } = await params;
    const booking = await prisma.bookingInquiry.findUnique({ where: { id }, select: voucherBookingSelect });
    if (!booking) throw new BookingOperationError("Booking not found.", 404);
    const grants = await prisma.voucherGrant.findMany({ where: { bookingId: id },
      select: { id: true, createdAt: true, expiresAt: true, revokedAt: true }, orderBy: { createdAt: "desc" }, take: 20 });
    const blockedReason = voucherReadiness(booking);
    return NextResponse.json({ reference: booking.reference, canManage: canManageVoucher(staff.role),
      ready: blockedReason === null, blockedReason, grants }, { headers: voucherHeaders });
  } catch (error) { return voucherError(error); }
}

export async function POST(request: Request, { params }: Context) {
  try {
    requireVoucherOrigin(request);
    const staff = await requireVoucherStaff(request, true);
    const limit = rateLimit(request, "voucher-issue", 20, 60000);
    if (!limit.allowed) return NextResponse.json({ error: "Too many voucher requests." },
      { status: 429, headers: { ...voucherHeaders, "Retry-After": String(limit.retryAfter) } });
    const { id } = await params;
    return NextResponse.json(await issueVoucherGrant(id, staff.id), { status: 201, headers: voucherHeaders });
  } catch (error) { return voucherError(error); }
}

export async function DELETE(request: Request, { params }: Context) {
  try {
    requireVoucherOrigin(request);
    const staff = await requireVoucherStaff(request, true);
    const { id } = await params;
    return NextResponse.json(await revokeVoucherGrants(id, staff.id), { headers: voucherHeaders });
  } catch (error) { return voucherError(error); }
}
