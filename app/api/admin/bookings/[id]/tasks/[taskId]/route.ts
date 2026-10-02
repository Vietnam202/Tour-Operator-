import { BookingTaskStatus } from "@prisma/client";
import { NextResponse } from "next/server";
import { requireAdminPermission } from "@/lib/admin-auth";
import { sameOrigin } from "@/lib/csrf";
import { BookingOperationError, withBookingLock } from "@/lib/booking-lock";

export async function PATCH(request: Request, { params }: { params: Promise<{ id: string; taskId: string }> }) {
  if (!sameOrigin(request)) return NextResponse.json({ error: "Invalid request origin." }, { status: 403 });
  try {
    const actor = await requireAdminPermission(request, "bookings:write");
    if (!actor) return NextResponse.json({ error: "Unauthorized." }, { status: 401 });
    const { id, taskId } = await params;
    const body: unknown = await request.json();
    if (!body || typeof body !== "object" || Array.isArray(body)) throw new BookingOperationError("A JSON object is required.", 400);
    const status = (body as Record<string, unknown>).status;
    if (typeof status !== "string" || !Object.values(BookingTaskStatus).includes(status as BookingTaskStatus)) {
      throw new BookingOperationError("Invalid task status.", 400);
    }
    const task = await withBookingLock(id, async tx => {
      const booking = await tx.bookingInquiry.findUniqueOrThrow({ where: { id } });
      if (booking.status === "CANCELLED") throw new BookingOperationError("Tasks on a cancelled booking cannot be changed.");
      const current = await tx.bookingTask.findFirst({ where: { id: taskId, bookingId: id } });
      if (!current) throw new BookingOperationError("Task not found for this booking.", 404);
      if (current.status === status) return current;
      const updated = await tx.bookingTask.update({ where: { id: taskId, bookingId: id },
        data: { status: status as BookingTaskStatus, completedAt: status === "DONE" ? new Date() : null } });
      await tx.bookingActivity.create({ data: { bookingId: id, actorId: actor.id === "legacy-api-key" ? null : actor.id,
        type: "TASK_STATUS", message: "Task " + updated.title + " changed to " + status } });
      return updated;
    });
    return NextResponse.json({ task }, { headers: { "Cache-Control": "private, no-store" } });
  } catch (error) {
    if (error instanceof BookingOperationError) return NextResponse.json({ error: error.message }, { status: error.status });
    if (error instanceof SyntaxError) return NextResponse.json({ error: "Invalid JSON request." }, { status: 400 });
    return NextResponse.json({ error: "Unable to update task." }, { status: 503 });
  }
}
