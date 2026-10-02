import { SupplierConfirmationStatus } from "@prisma/client";
import { NextResponse } from "next/server";
import { requireAdminPermission } from "@/lib/admin-auth";
import { sameOrigin } from "@/lib/csrf";
import { BookingOperationError, withBookingLock } from "@/lib/booking-lock";

export async function PATCH(request: Request, { params }: { params: Promise<{ id: string }> }) {
  if (!sameOrigin(request)) return NextResponse.json({ error: "Invalid request origin." }, { status: 403 });
  try {
    const actor = await requireAdminPermission(request, "bookings:write");
    if (!actor) return NextResponse.json({ error: "Unauthorized." }, { status: 401 });
    const { id } = await params;
    const raw: unknown = await request.json();
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) throw new BookingOperationError("A JSON object is required.", 400);
    const body = raw as Record<string, unknown>;
    if (typeof body.status !== "string" || !Object.values(SupplierConfirmationStatus).includes(body.status as SupplierConfirmationStatus)) {
      throw new BookingOperationError("Invalid supplier confirmation status.", 400);
    }
    const status = body.status as SupplierConfirmationStatus;
    if (body.reference !== undefined && body.reference !== null && (typeof body.reference !== "string" || body.reference.length > 120)) {
      throw new BookingOperationError("Invalid operator reference.", 400);
    }
    const reference = typeof body.reference === "string" ? body.reference.trim() : null;
    if (status === "CONFIRMED" && !reference) throw new BookingOperationError("An operator reservation reference is required.", 400);
    const booking = await withBookingLock(id, async tx => {
      const current = await tx.bookingInquiry.findUniqueOrThrow({ where: { id } });
      if (current.status === "CANCELLED") throw new BookingOperationError("A cancelled booking cannot receive supplier confirmation.");
      const changed = current.supplierConfirmationStatus !== status || current.supplierConfirmationRef !== (reference || null);
      const updated = changed ? await tx.bookingInquiry.update({ where: { id }, data: {
        supplierConfirmationStatus: status, supplierConfirmationRef: reference || null,
        supplierConfirmedAt: status === "CONFIRMED" ? current.supplierConfirmedAt ?? new Date() : null,
      } }) : current;
      if (changed) await tx.bookingActivity.create({ data: { bookingId: id, actorId: actor.id === "legacy-api-key" ? null : actor.id,
        type: "SUPPLIER_CONFIRMATION", message: "Supplier confirmation changed to " + status } });
      if (status === "CONFIRMED") {
        const tasks = await tx.bookingTask.updateMany({ where: { bookingId: id, type: "SUPPLIER_CONFIRMATION", status: { in: ["OPEN", "IN_PROGRESS"] } },
          data: { status: "DONE", completedAt: new Date() } });
        if (tasks.count) await tx.bookingActivity.create({ data: { bookingId: id, type: "AUTOMATION_TASK_COMPLETED",
          message: "Supplier confirmation tasks completed after operator confirmation", metadata: { count: tasks.count } } });
      } else {
        // A previously issued voucher must not become valid again after operator rejection/reconfirmation.
        await tx.voucherGrant.updateMany({ where: { bookingId: id, revokedAt: null }, data: { revokedAt: new Date() } });
        if (current.supplierConfirmationStatus === "CONFIRMED") {
          await tx.bookingTask.updateMany({ where: { bookingId: id, type: "SUPPLIER_CONFIRMATION", status: "DONE" },
            data: { status: "OPEN", completedAt: null } });
        }
      }
      return updated;
    });
    return NextResponse.json({ booking }, { headers: { "Cache-Control": "private, no-store" } });
  } catch (error) {
    if (error instanceof BookingOperationError) return NextResponse.json({ error: error.message }, { status: error.status });
    if (error instanceof SyntaxError) return NextResponse.json({ error: "Invalid JSON request." }, { status: 400 });
    return NextResponse.json({ error: "Unable to update supplier confirmation." }, { status: 503 });
  }
}
