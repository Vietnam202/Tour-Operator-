"use client";

import { FormEvent, useState } from "react";

type Booking = {
  id: string; reference: string; cruiseName: string; departureDate: string; adults: number;
  children: number; primaryGuest: string; nationality?: string; email: string; phone?: string;
  estimatedTotal?: number; currency: string; status: string; paymentStatus?: string; depositAmount?: number; balanceAmount?: number; amountPaid?: number; createdAt: string;
};

export default function AdminPage() {
  const [key, setKey] = useState("");
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  async function load(e: FormEvent) {
    e.preventDefault(); setLoading(true); setError("");
    try {
      const res = await fetch("/api/admin/bookings", { headers: { "x-admin-key": key } });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Unable to load bookings");
      setBookings(data.bookings || []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to load bookings");
    } finally { setLoading(false); }
  }

  async function createPaymentRequest(b: Booking, kind: "DEPOSIT"|"BALANCE") {
    const total=b.estimatedTotal||0; const paid=b.amountPaid||0;
    const amount=kind==="DEPOSIT"?(b.depositAmount||Math.max(1,Math.round(total*.3))):Math.max(1,total-paid);
    setError("");
    const res=await fetch("/api/admin/payments/request",{method:"POST",headers:{"content-type":"application/json","x-admin-key":key},body:JSON.stringify({bookingId:b.id,kind,amount})});
    const data=await res.json();
    if(!res.ok){setError(data.error||"Unable to create payment request");return}
    const url=window.location.origin+data.paymentUrl;
    await navigator.clipboard?.writeText(url);
    window.prompt("Payment link created. Copy and send this secure link to the guest:",url);
  }

  async function updateStatus(id: string, status: string) {
    setError("");
    const res = await fetch("/api/admin/bookings/" + id, {
      method: "PATCH",
      headers: { "content-type": "application/json", "x-admin-key": key },
      body: JSON.stringify({ status }),
    });
    const data = await res.json();
    if (!res.ok) { setError(data.error || "Unable to update booking"); return; }
    setBookings(items => items.map(item => item.id === id ? { ...item, status: data.booking.status } : item));
  }

  const pipeline = bookings.reduce((n, b) => n + (b.estimatedTotal || 0), 0);

  return <main className="adminPage">
    <header className="adminHeader"><div className="shell"><a className="brand" href="/"><span className="brandMark">≋</span><span><b>HALONG CRUISE<br/>ADVISOR</b><small>Operations</small></span></a><nav className="adminNav"><a className="active" href="/admin">Bookings</a><a href="/admin/inventory">Inventory</a><a href="/">Website</a></nav></div></header>
    <div className="shell adminWrap">
      <div className="adminTitle"><span className="eyebrow">OPERATIONS MVP</span><h1>Booking enquiries</h1><p>Review the latest international guest requests and follow up through email or WhatsApp.</p></div>
      <form className="adminLogin" onSubmit={load}><label><span>Admin API key</span><input type="password" value={key} onChange={e => setKey(e.target.value)} placeholder="Enter admin key"/></label><button className="darkButton" disabled={loading}>{loading ? "Loading..." : "Load bookings"}</button></form>
      {error && <div className="adminError">{error}</div>}
      <div className="adminStats"><div><span>Total loaded</span><strong>{bookings.length}</strong></div><div><span>New enquiries</span><strong>{bookings.filter(b => b.status === "NEW").length}</strong></div><div><span>Estimated pipeline</span><strong>US$ {pipeline.toLocaleString()}</strong></div></div>
      <div className="adminTableWrap"><table className="adminTable"><thead><tr><th>Reference</th><th>Guest</th><th>Cruise / Date</th><th>Guests</th><th>Contact</th><th>Value</th><th>Payment</th><th>Status</th></tr></thead><tbody>
        {bookings.length === 0 ? <tr><td colSpan={8} className="emptyAdmin">No bookings loaded yet.</td></tr> : bookings.map(b => <tr key={b.id}><td><b>{b.reference}</b><small>{new Date(b.createdAt).toLocaleDateString("en-US")}</small></td><td>{b.primaryGuest}<small>{b.nationality || "-"}</small></td><td>{b.cruiseName}<small>{new Date(b.departureDate).toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" })}</small></td><td>{b.adults} adult{b.adults !== 1 ? "s" : ""}{b.children ? ", " + b.children + " child" : ""}</td><td><a href={"mailto:" + b.email}>{b.email}</a><small>{b.phone || "-"}</small></td><td>{b.estimatedTotal ? b.currency + " " + b.estimatedTotal : "-"}</td><td><b>{b.paymentStatus||"UNPAID"}</b><small>{b.amountPaid ? b.currency+" "+b.amountPaid+" paid" : "No payment"}</small>{b.status==="CONFIRMED"&&b.paymentStatus!=="PAID"&&<div className="paymentActions"><button onClick={()=>createPaymentRequest(b,"DEPOSIT")}>Deposit link</button><button onClick={()=>createPaymentRequest(b,"BALANCE")}>Balance link</button></div>}</td><td><select className={"statusSelect " + b.status.toLowerCase()} value={b.status} onChange={e => updateStatus(b.id, e.target.value)}><option>NEW</option><option>CONTACTED</option><option>QUOTED</option><option>CONFIRMED</option><option>CANCELLED</option></select></td></tr>)}
      </tbody></table></div>
    </div>
  </main>;
}
