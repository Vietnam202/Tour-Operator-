"use client";
import { useEffect, useRef, useState } from "react";
import { useParams } from "next/navigation";

type Voucher = { reference: string; cruiseName: string; cabinName: string | null; primaryGuest: string;
  departureDate: string; durationNights: number; adults: number; children: number; transferType: string | null;
  estimatedTotal: number; amountPaid: number; currency: string; paymentLabel: string };
type StaffAccess = { mode: "staff"; bookingId: string; canManage: boolean };

export default function VoucherPage() {
  const { reference } = useParams<{ reference: string }>();
  const [voucher, setVoucher] = useState<Voucher | null>(null);
  const [error, setError] = useState("");
  const [access, setAccess] = useState<StaffAccess | null>(null);
  const credential = useRef<{ reference: string; token: string } | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    const fragment = new URLSearchParams(window.location.hash.slice(1));
    const token = fragment.get("access");
    if (token !== null) {
      credential.current = { reference, token };
      window.history.replaceState(window.history.state, "", window.location.pathname);
    } else if (credential.current?.reference !== reference) credential.current = null;
    setVoucher(null); setAccess(null); setError("");
    const headers: Record<string, string> = {};
    if (credential.current) headers.Authorization = "Bearer " + credential.current.token;
    fetch("/api/voucher/" + encodeURIComponent(reference), { headers, cache: "no-store", referrerPolicy: "no-referrer", signal: controller.signal })
      .then(async response => {
        const data = await response.json();
        if (data.access?.mode === "staff") setAccess(data.access);
        if (!response.ok) throw new Error(data.error || "Voucher unavailable.");
        setVoucher(data.voucher);
      }).catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(reason instanceof Error ? reason.message : "Unable to load voucher.");
      });
    return () => controller.abort();
  }, [reference]);
  const money = (amount: number) => new Intl.NumberFormat("en-US", { style: "currency", currency: voucher?.currency || "USD" }).format(amount);
  return <main className="voucherPage"><article className="voucher">
    {access && <div className="voucherPrint"><p><strong>Staff preview.</strong> The address without a private access link will not work for guests.</p>
      <a className="darkButton linkButton" href={"/admin/bookings/" + access.bookingId + "/voucher"}>Manage customer voucher links</a></div>}
    {error ? <><h1>Voucher unavailable</h1><p role="alert">{error}</p><p>Reopen the private link sent by our team. Refreshing this page does not retain the access credential.</p></>
      : !voucher ? <p role="status">Loading private travel voucher...</p>
      : <>
        <header><div><span className="eyebrow">HALONG CRUISE ADVISOR</span><h1>Cruise Travel Voucher</h1></div>
          <div className="voucherRef"><small>BOOKING REFERENCE</small><b>{voucher.reference}</b></div></header>
        <div className="voucherStatus">Booking and operator confirmed · {voucher.paymentLabel}</div>
        <section><h2>{voucher.cruiseName}</h2><p>{voucher.cabinName || "Confirmed cabin"}</p></section>
        <div className="voucherGrid">
          <div><small>Lead guest</small><b>{voucher.primaryGuest}</b></div>
          <div><small>Departure</small><b>{new Date(voucher.departureDate).toLocaleDateString("en-US", { timeZone: "Asia/Ho_Chi_Minh", month: "long", day: "numeric", year: "numeric" })}</b>
            <span>{voucher.durationNights + 1} days / {voucher.durationNights} nights</span></div>
          <div><small>Guests</small><b>{voucher.adults} adults · {voucher.children} children</b></div>
          <div><small>Transfer</small><b>{voucher.transferType || "Not selected"}</b><span>Reconfirm pickup details with our team.</span></div>
        </div>
        <div className="voucherMoney"><div><span>Trip total</span><b>{money(voucher.estimatedTotal)}</b></div>
          <div><span>Payment received</span><b>{money(voucher.amountPaid)}</b></div></div>
        <div className="voucherNotes"><h3>Before departure</h3><p>Keep this voucher private. Reconfirm the harbour, boarding time and pickup details with our operations team.</p></div>
        <button className="darkButton voucherPrint" onClick={() => window.print()}>Print / Save as PDF</button>
      </>}
  </article></main>;
}
