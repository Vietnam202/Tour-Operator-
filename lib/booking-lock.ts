import { Prisma } from "@prisma/client";
import { prisma } from "@/lib/prisma";

export class BookingOperationError extends Error {
  constructor(message: string, public readonly status = 409) {
    super(message);
    this.name = "BookingOperationError";
  }
}

// Lock order is booking -> departure -> dependent rows. No network I/O in callbacks.
// The SQL template binds IDs as parameters, never as SQL text.
export function withBookingLock<T>(bookingId: string, action: (tx: Prisma.TransactionClient) => Promise<T>): Promise<T> {
  return prisma.$transaction(async tx => {
    const rows = await tx.$queryRaw<{ id: string }[]>`SELECT "id" FROM "BookingInquiry" WHERE "id" = ${bookingId} FOR UPDATE`;
    if (rows.length !== 1) throw new BookingOperationError("Booking not found.", 404);
    return action(tx);
  }, { isolationLevel: Prisma.TransactionIsolationLevel.ReadCommitted, maxWait: 5000, timeout: 15000 });
}
