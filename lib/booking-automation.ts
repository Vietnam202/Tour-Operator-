import { BookingTaskType, BookingTaskStatus, PaymentStatus } from "@prisma/client";
import { prisma } from "@/lib/prisma";

const DAY = 24 * 60 * 60 * 1000;

function dueBefore(departure: Date, days: number, now = new Date()) {
  const target = new Date(departure.getTime() - days * DAY);
  return target < now ? now : target;
}

async function ensureTask(
  bookingId: string,
  type: BookingTaskType,
  title: string,
  dueAt: Date | null,
  ownerId?: string | null
) {
  const existing = await prisma.bookingTask.findFirst({
    where: { bookingId, type },
    orderBy: { createdAt: "asc" }
  });
  if (existing) return existing;

  const task = await prisma.bookingTask.create({
    data: { bookingId, type, title, dueAt, ownerId: ownerId || null }
  });

  await prisma.bookingActivity.create({
    data: {
      bookingId,
      type: "AUTOMATION_TASK_CREATED",
      message: "Automation created task: " + title,
      metadata: { taskId: task.id, taskType: type }
    }
  });
  return task;
}

export async function ensureConfirmedBookingTasks(bookingId: string) {
  const booking = await prisma.bookingInquiry.findUnique({
    where: { id: bookingId },
    select: {
      id: true,
      departureDate: true,
      assignedToId: true,
      supplierConfirmationStatus: true,
      paymentStatus: true,
      transferType: true
    }
  });
  if (!booking) return;

  const now = new Date();
  const paymentDue = new Date(Math.min(
    now.getTime() + DAY,
    dueBefore(booking.departureDate, 3, now).getTime()
  ));

  if (booking.supplierConfirmationStatus !== "CONFIRMED") {
    await ensureTask(
      booking.id,
      BookingTaskType.SUPPLIER_CONFIRMATION,
      "Confirm reservation with cruise operator",
      now,
      booking.assignedToId
    );
  }

  if (booking.paymentStatus !== PaymentStatus.PAID) {
    await ensureTask(
      booking.id,
      BookingTaskType.PAYMENT,
      "Collect required guest payment",
      paymentDue,
      booking.assignedToId
    );
  }

  await ensureTask(
    booking.id,
    BookingTaskType.PASSPORT,
    "Collect and verify passport details",
    dueBefore(booking.departureDate, 3, now),
    booking.assignedToId
  );

  if (booking.transferType && booking.transferType !== "none") {
    await ensureTask(
      booking.id,
      BookingTaskType.TRANSFER_DETAILS,
      "Confirm Hanoi pickup and transfer details",
      dueBefore(booking.departureDate, 2, now),
      booking.assignedToId
    );
  }

  await ensureTask(
    booking.id,
    BookingTaskType.PRE_DEPARTURE,
    "Complete pre-departure readiness check",
    dueBefore(booking.departureDate, 1, now),
    booking.assignedToId
  );
}

export async function completeTasksByType(
  bookingId: string,
  type: BookingTaskType,
  message: string
) {
  const now = new Date();
  const updated = await prisma.bookingTask.updateMany({
    where: {
      bookingId,
      type,
      status: { in: [BookingTaskStatus.OPEN, BookingTaskStatus.IN_PROGRESS] }
    },
    data: { status: BookingTaskStatus.DONE, completedAt: now }
  });

  if (updated.count > 0) {
    await prisma.bookingActivity.create({
      data: {
        bookingId,
        type: "AUTOMATION_TASK_COMPLETED",
        message,
        metadata: { taskType: type, count: updated.count }
      }
    });
  }
}

export async function syncPaymentTasks(bookingId: string, paymentStatus: PaymentStatus) {
  if (paymentStatus === PaymentStatus.PAID) {
    await completeTasksByType(
      bookingId,
      BookingTaskType.PAYMENT,
      "Automation completed payment task after booking was paid in full"
    );
  }
}

export async function markPaymentTaskInProgress(bookingId: string) {
  const updated = await prisma.bookingTask.updateMany({
    where: {
      bookingId,
      type: BookingTaskType.PAYMENT,
      status: BookingTaskStatus.OPEN
    },
    data: { status: BookingTaskStatus.IN_PROGRESS }
  });

  if (updated.count > 0) {
    await prisma.bookingActivity.create({
      data: {
        bookingId,
        type: "AUTOMATION_TASK_PROGRESS",
        message: "Payment task moved to in progress after payment request was created"
      }
    });
  }
}
