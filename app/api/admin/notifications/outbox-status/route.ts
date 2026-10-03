import { NextResponse } from "next/server";
import { requireAdminPermission } from "@/lib/admin-auth";
import { getNotificationOutboxStatus } from "@/lib/notification-outbox";

export async function GET(request: Request) {
  if (!(await requireAdminPermission(request, "bookings:read"))) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const status = await getNotificationOutboxStatus();
  return NextResponse.json(
    {
      counts: status.counts,
      duePending: status.duePending,
      staleProcessing: status.staleProcessing,
      oldestPendingAgeSeconds: status.oldestPending?.ageSeconds ?? null,
      generatedAt: new Date().toISOString(),
    },
    {
      headers: {
        "Cache-Control": "no-store",
        Vary: "Cookie",
      },
    },
  );
}
