import { BookingTaskType } from "@prisma/client";
import { NextResponse } from "next/server";
import { requireAdminPermission } from "@/lib/admin-auth";
import { sameOrigin } from "@/lib/csrf";
import { BookingOperationError, withBookingLock } from "@/lib/booking-lock";

export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  if (!sameOrigin(request)) return NextResponse.json({ error: "Invalid request origin." }, { status: 403 });
  try {
    const actor = await requireAdminPermission(request, "bookings:write");
    if (!actor) return NextResponse.json({ error: "Unauthorized." }, { status: 401 });
    const { id } = await params;
    const raw: unknown = await request.json();
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) throw new BookingOperationError("A JSON object is required.", 400);
    const body = raw as Record<string, unknown>;
    const type = body.type ?? "OTHER";
    if (typeof type !== "string" || !Object.values(BookingTaskType).includes(type as BookingTaskType)
      || typeof body.title !== "string" || !body.title.trim() || body.title.length > 180) {
      throw new BookingOperationError("A valid task type and title are required.", 400);
    }
    const title = body.title.trim();
    const ownerId = body.ownerId === undefined || body.ownerId === null || body.ownerId === "" ? null : body.ownerId;
    if (ownerId !== null && (typeof ownerId !== "string" || ownerId.length > 100)) throw new BookingOperationError("Invalid task owner.", 400);
    let dueAt: Date | null = null;
    if (body.dueAt !== undefined && body.dueAt !== null && body.dueAt !== "") {
      if (typeof body.dueAt !== "string" || body.dueAt.length > 40) throw new BookingOperationError("Invalid task deadline.", 400);
      dueAt = new Date(body.dueAt);
      if (!Number.isFinite(dueAt.getTime())) throw new BookingOperationError("Invalid task deadline.", 400);
    }
    const notes = body.notes === undefined || body.notes === null ? null : body.notes;
    if (notes !== null && (typeof notes !== "string" || notes.length > 1000)) throw new BookingOperationError("Invalid task notes.", 400);
    const task = await withBookingLock(id, async tx => {
      const booking = await tx.bookingInquiry.findUniqueOrThrow({ where: { id } });
      if (booking.status === "CANCELLED") throw new BookingOperationError("Tasks cannot be added to a cancelled booking.");
      if (ownerId && !await tx.staffUser.findFirst({ where: { id: ownerId, isActive: true }, select: { id: true } })) {
        throw new BookingOperationError("Select an active task owner.", 400);
      }
      const created = await tx.bookingTask.create({ data: { bookingId: id, type: type as BookingTaskType,
        title, ownerId, dueAt, notes: notes?.trim() || null } });
      await tx.bookingActivity.create({ data: { bookingId: id, actorId: actor.id === "legacy-api-key" ? null : actor.id,
        type: "TASK_CREATED", message: "Task created: " + created.title } });
      return created;
    });
    return NextResponse.json({ task }, { status: 201, headers: { "Cache-Control": "private, no-store" } });
  } catch (error) {
    if (error instanceof BookingOperationError) return NextResponse.json({ error: error.message }, { status: error.status });
    if (error instanceof SyntaxError) return NextResponse.json({ error: "Invalid JSON request." }, { status: 400 });
    return NextResponse.json({ error: "Unable to create task." }, { status: 503 });
  }
}
