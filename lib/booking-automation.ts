import { BookingTaskType, BookingTaskStatus, Prisma } from "@prisma/client";
import { withBookingLock } from "@/lib/booking-lock";

const DAY = 24 * 60 * 60 * 1000;
function dueBefore(departure: Date, days: number, now: Date) {
  return new Date(Math.max(now.getTime(), departure.getTime() - days * DAY));
}

// Caller holds the booking row lock. Manual tasks remain supported and are never deleted.
async function ensureTask(tx: Prisma.TransactionClient, bookingId: string, type: BookingTaskType,
  title: string, dueAt: Date, ownerId: string | null) {
  const existing = await tx.bookingTask.findFirst({ where: { bookingId, type }, orderBy: { createdAt: "asc" } });
  if (existing) return;
  const task = await tx.bookingTask.create({ data: { bookingId, type, title, dueAt, ownerId } });
  await tx.bookingActivity.create({ data: {
    bookingId, type: "AUTOMATION_TASK_CREATED", message: "Automation created task: " + title,
    metadata: { taskId: task.id, taskType: type },
  } });
}

export async function ensureConfirmedBookingTasksTx(tx: Prisma.TransactionClient, bookingId: string) {
  const booking = await tx.bookingInquiry.findUniqueOrThrow({ where: { id: bookingId } });
  if (booking.status !== "CONFIRMED") return;
  const now = new Date();
  if (booking.supplierConfirmationStatus !== "CONFIRMED") {
    await ensureTask(tx, bookingId, BookingTaskType.SUPPLIER_CONFIRMATION,
      "Confirm reservation with cruise operator", now, booking.assignedToId);
  }
  await ensureTask(tx, bookingId, BookingTaskType.PASSPORT, "Collect and verify passport details",
    dueBefore(booking.departureDate, 3, now), booking.assignedToId);
  if (booking.transferType && booking.transferType !== "none") {
    await ensureTask(tx, bookingId, BookingTaskType.TRANSFER_DETAILS, "Confirm Hanoi pickup and transfer details",
      dueBefore(booking.departureDate, 2, now), booking.assignedToId);
  }
  await ensureTask(tx, bookingId, BookingTaskType.PRE_DEPARTURE, "Complete pre-departure readiness check",
    dueBefore(booking.departureDate, 1, now), booking.assignedToId);
}

export function ensureConfirmedBookingTasks(bookingId: string) {
  return withBookingLock(bookingId, tx => ensureConfirmedBookingTasksTx(tx, bookingId));
}

async function completeTx(tx: Prisma.TransactionClient, bookingId: string, type: BookingTaskType, message: string) {
  const updated = await tx.bookingTask.updateMany({
    where: { bookingId, type, status: { in: [BookingTaskStatus.OPEN, BookingTaskStatus.IN_PROGRESS] } },
    data: { status: BookingTaskStatus.DONE, completedAt: new Date() },
  });
  if (updated.count) await tx.bookingActivity.create({ data: {
    bookingId, type: "AUTOMATION_TASK_COMPLETED", message, metadata: { taskType: type, count: updated.count },
  } });
}

export function completeTasksByType(bookingId: string, type: BookingTaskType, message: string) {
  return withBookingLock(bookingId, async tx => {
    const b = await tx.bookingInquiry.findUniqueOrThrow({ where: { id: bookingId } });
    if (b.status === "CANCELLED") return;
    if (type === BookingTaskType.SUPPLIER_CONFIRMATION && b.supplierConfirmationStatus !== "CONFIRMED") return;
    await completeTx(tx, bookingId, type, message);
  });
}

