import { BookingStatus } from "@prisma/client";
import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";
import { notifyBookingConfirmed } from "@/lib/notifications";
import { sameOrigin } from "@/lib/csrf";
import { ensureConfirmedBookingTasks } from "@/lib/booking-automation";

const allowed = new Set(Object.values(BookingStatus));


export async function PATCH(
  request: Request,
  { params }: { params: Promise<{ id: string }> }
) {
  if (!sameOrigin(request)) return NextResponse.json({ error: "Invalid request origin" }, { status: 403 });
  const actor=await requireAdminPermission(request,"bookings:write");
  if (!actor) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });

  const { id } = await params;
  const body = await request.json();
  const hasStatus=body.status!==undefined;
  const nextStatus=hasStatus?String(body.status) as BookingStatus:null;
  if (nextStatus && !allowed.has(nextStatus)) return NextResponse.json({ error: "Invalid booking status" }, { status: 400 });
  const operationalData={
    ...(body.assignedToId!==undefined?{assignedToId:body.assignedToId||null}:{}),
    ...(body.followUpAt!==undefined?{followUpAt:body.followUpAt?new Date(body.followUpAt):null}:{}),
    ...(body.internalNotes!==undefined?{internalNotes:String(body.internalNotes).trim().slice(0,5000)||null}:{})
  };

  try {
    const booking = await prisma.$transaction(async tx => {
      const current = await tx.bookingInquiry.findUnique({ where: { id } });
      if (!current) throw new Error("BOOKING_NOT_FOUND");

      if (nextStatus === "CONFIRMED" && !current.inventoryCommitted && current.departureId) {
        const departure = await tx.departure.findUnique({ where: { id: current.departureId } });
        if (!departure || !departure.isAvailable) throw new Error("DEPARTURE_UNAVAILABLE");

        if (departure.cabinsLeft !== null) {
          const updated = await tx.departure.updateMany({
            where: { id: departure.id, cabinsLeft: { gt: 0 }, isAvailable: true },
            data: { cabinsLeft: { decrement: 1 } }
          });
          if (updated.count !== 1) throw new Error("SOLD_OUT");

          const refreshed = await tx.departure.findUnique({ where: { id: departure.id } });
          if (refreshed?.cabinsLeft === 0) {
            await tx.departure.update({ where: { id: departure.id }, data: { isAvailable: false } });
          }
        }

        return tx.bookingInquiry.update({
          where: { id },
          data: { ...operationalData, status: nextStatus, inventoryCommitted: true }
        });
      }

      // Once inventory is committed we do not automatically restore it on cancellation.
      // Operations should explicitly reopen inventory after checking supplier terms.
      return tx.bookingInquiry.update({ where: { id }, data: { ...operationalData, ...(nextStatus?{status:nextStatus}:{}) } });
    });

    const changes:string[]=[];
    if(nextStatus) changes.push("status → "+nextStatus);
    if(body.assignedToId!==undefined) changes.push("owner updated");
    if(body.followUpAt!==undefined) changes.push("follow-up updated");
    if(body.internalNotes!==undefined) changes.push("internal notes updated");
    if(changes.length) await prisma.bookingActivity.create({data:{bookingId:id,actorId:actor.id==="legacy-api-key"?null:actor.id,type:"BOOKING_UPDATED",message:changes.join(" · ")}});

    if (nextStatus === "CONFIRMED") {
      await ensureConfirmedBookingTasks(booking.id);
      await notifyBookingConfirmed({
        reference: booking.reference,
        cruiseName: booking.cruiseName,
        primaryGuest: booking.primaryGuest,
        email: booking.email,
        departureDate: booking.departureDate.toISOString(),
        estimatedTotal: booking.estimatedTotal,
        currency: booking.currency
      });
    }
    return NextResponse.json({ booking });
  } catch (error) {
    const code = error instanceof Error ? error.message : "";
    if (code === "SOLD_OUT") return NextResponse.json({ error: "This departure is sold out and cannot be confirmed." }, { status: 409 });
    if (code === "DEPARTURE_UNAVAILABLE") return NextResponse.json({ error: "This departure is no longer available." }, { status: 409 });
    if (code === "BOOKING_NOT_FOUND") return NextResponse.json({ error: "Booking not found" }, { status: 404 });
    return NextResponse.json({ error: "Unable to update booking" }, { status: 400 });
  }
}
