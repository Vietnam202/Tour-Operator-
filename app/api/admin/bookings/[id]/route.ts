import { BookingStatus } from "@prisma/client";
import { NextResponse } from "next/server";
import { requireAdminPermission } from "@/lib/admin-auth";
import { sameOrigin } from "@/lib/csrf";
import { enqueueNotificationTx } from "@/lib/notification-outbox";
import { ensureConfirmedBookingTasksTx } from "@/lib/booking-automation";
import { BookingOperationError, withBookingLock } from "@/lib/booking-lock";

const headers = { "Cache-Control": "private, no-store" };
const statuses = new Set<string>(Object.values(BookingStatus));
function nullableText(value: unknown, max: number): string | null {
  if (value === null || value === "") return null;
  if (typeof value !== "string" || value.length > max) throw new BookingOperationError("Invalid text field.", 400);
  return value.trim() || null;
}

export async function PATCH(request: Request, { params }: { params: Promise<{ id: string }> }) {
  if (!sameOrigin(request)) return NextResponse.json({ error: "Invalid request origin." }, { status: 403, headers });
  try {
    const actor = await requireAdminPermission(request, "bookings:write");
    if (!actor) return NextResponse.json({ error: "Unauthorized." }, { status: 401, headers });
    const { id } = await params;
    const body: unknown = await request.json();
    if (!body || typeof body !== "object" || Array.isArray(body)) throw new BookingOperationError("A JSON object is required.", 400);
    const input = body as Record<string, unknown>;
    const keys = Object.keys(input);
    if (!keys.length || keys.some(key => !["status", "assignedToId", "followUpAt", "internalNotes"].includes(key))) {
      throw new BookingOperationError("Unsupported booking fields.", 400);
    }
    let nextStatus: BookingStatus | undefined;
    if ("status" in input) {
      if (typeof input.status !== "string" || !statuses.has(input.status)) throw new BookingOperationError("Invalid booking status.", 400);
      nextStatus = input.status as BookingStatus;
    }
    const operational: { assignedToId?: string | null; followUpAt?: Date | null; internalNotes?: string | null } = {};
    if ("assignedToId" in input) operational.assignedToId = nullableText(input.assignedToId, 100);
    if ("internalNotes" in input) operational.internalNotes = nullableText(input.internalNotes, 5000);
    if ("followUpAt" in input) {
      const value = nullableText(input.followUpAt, 40);
      operational.followUpAt = value ? new Date(value) : null;
      if (operational.followUpAt && !Number.isFinite(operational.followUpAt.getTime())) throw new BookingOperationError("Invalid follow-up date.", 400);
    }
    const result = await withBookingLock(id, async tx => {
      const current = await tx.bookingInquiry.findUniqueOrThrow({ where: { id } });
      if (current.status === "CANCELLED" && nextStatus && nextStatus !== "CANCELLED") {
        throw new BookingOperationError("Cancelled bookings cannot be reopened. Review supplier release and create a new request.");
      }
      if (current.status === "CONFIRMED" && nextStatus && !["CONFIRMED", "CANCELLED"].includes(nextStatus)) {
        throw new BookingOperationError("A confirmed booking cannot return to the enquiry pipeline.");
      }
      if (operational.assignedToId) {
        const owner = await tx.staffUser.findFirst({ where: { id: operational.assignedToId, isActive: true }, select: { id: true } });
        if (!owner) throw new BookingOperationError("Select an active staff owner.", 400);
      }
      let inventoryCommitted = current.inventoryCommitted;
      if (nextStatus === "CONFIRMED" && !inventoryCommitted) {
        if (!current.departureId || !current.cruiseId) throw new BookingOperationError("A dated cruise allocation is required before confirmation.");
        await tx.$queryRaw`SELECT "id" FROM "Departure" WHERE "id" = ${current.departureId} FOR UPDATE`;
        const departure = await tx.departure.findUnique({ where: { id: current.departureId } });
        if (!departure || departure.cruiseId !== current.cruiseId || !departure.isAvailable || departure.departureDate.getTime() <= Date.now()) {
          throw new BookingOperationError("This departure is no longer available.");
        }
        if (departure.departureDate.getTime() !== current.departureDate.getTime() || departure.durationNights !== current.durationNights) {
          throw new BookingOperationError("Departure details changed. Review the booking before confirming.");
        }
        if (departure.cabinsLeft === null) throw new BookingOperationError("Set a verified cabin allocation before confirming.");
        const reserved = await tx.departure.updateMany({
          where: { id: departure.id, cabinsLeft: { gt: 0 }, isAvailable: true },
          data: { cabinsLeft: { decrement: 1 } },
        });
        if (reserved.count !== 1) throw new BookingOperationError("This departure is sold out.");
        await tx.departure.updateMany({ where: { id: departure.id, cabinsLeft: 0 }, data: { isAvailable: false } });
        inventoryCommitted = true;
      }
      const changed: string[] = [];
      if (nextStatus && nextStatus !== current.status) changed.push("status: " + current.status + " -> " + nextStatus);
      if ("assignedToId" in operational && operational.assignedToId !== current.assignedToId) changed.push("owner updated");
      if ("followUpAt" in operational && operational.followUpAt?.getTime() !== current.followUpAt?.getTime()) changed.push("follow-up updated");
      if ("internalNotes" in operational && operational.internalNotes !== current.internalNotes) changed.push("internal notes updated");
      const booking = await tx.bookingInquiry.update({ where: { id }, data: {
        ...operational, ...(nextStatus ? { status: nextStatus } : {}), inventoryCommitted,
      } });
      if (changed.length) await tx.bookingActivity.create({ data: {
        bookingId: id, actorId: actor.id === "legacy-api-key" ? null : actor.id,
        type: "BOOKING_UPDATED", message: changed.join("; "),
      } });
      if (booking.status === "CONFIRMED") await ensureConfirmedBookingTasksTx(tx, id);
      const newlyConfirmed = current.status !== "CONFIRMED" && booking.status === "CONFIRMED";
      if (newlyConfirmed) await enqueueNotificationTx(tx, {
        eventType: "booking.confirmed",
        idempotencyKey: `booking.confirmed:${booking.id}`,
        aggregateType: "BookingInquiry",
        aggregateId: booking.id,
        payload: { reference: booking.reference, cruiseName: booking.cruiseName,
          primaryGuest: booking.primaryGuest, email: booking.email,
          departureDate: booking.departureDate.toISOString(), estimatedTotal: booking.estimatedTotal,
          currency: booking.currency },
      });
      if (booking.status === "CANCELLED") {
        await tx.voucherGrant.updateMany({ where: { bookingId: id, revokedAt: null }, data: { revokedAt: new Date() } });
        await tx.bookingTask.updateMany({ where: { bookingId: id, status: { in: ["OPEN", "IN_PROGRESS"] } }, data: { status: "CANCELLED" } });
        // Supplier release, refunds and inventory restoration are explicit operations, not automatic.
      }
      return { booking, newlyConfirmed };
    });
    return NextResponse.json({ booking: result.booking }, { headers });
  } catch (error) {
    if (error instanceof BookingOperationError) return NextResponse.json({ error: error.message }, { status: error.status, headers });
    if (error instanceof SyntaxError) return NextResponse.json({ error: "Invalid JSON request." }, { status: 400, headers });
    return NextResponse.json({ error: "Unable to update booking. Please refresh and try again." }, { status: 503, headers });
  }
}
