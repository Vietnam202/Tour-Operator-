import type { Metadata } from "next";
import type { ReactNode } from "react";

export const metadata: Metadata = { robots: { index: false, follow: false } };
export default async function BookingTools({ children, params }: { children: ReactNode; params: Promise<{ id: string }> }) {
  const { id } = await params;
  const base = "/admin/bookings/" + encodeURIComponent(id);
  return <><nav aria-label="Booking tools" className="shell adminNav">
    <a href={base}>Booking overview</a><a href={base + "/voucher"}>Private voucher links</a>
  </nav>{children}</>;
}
