"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";

type Booking = {
  id: string; reference: string; cruiseName: string; departureDate: string; adults: number;
  children: number; primaryGuest: string; nationality?: string; email: string; phone?: string;
  estimatedTotal?: number; currency: string; status: string; assignedToId?:string|null; assignedTo?:{id:string;name:string}|null; followUpAt?:string|null; internalNotes?:string|null; paymentStatus?: string; depositAmount?: number; balanceAmount?: number; amountPaid?: number; createdAt: string;
};

export default function AdminPage() {
  const router = useRouter();
  const [staff,setStaff]=useState<{name:string;role:string}|null>(null);
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [staffList,setStaffList]=useState<{id:string;name:string}[]>([]);

  async function load() {
    setLoading(true); setError("");
    try {
      const res = await fetch("/api/admin/bookings");
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Unable to load bookings");
      setBookings(data.bookings || []);
      fetch("/api/admin/staff").then(r=>r.ok?r.json():null).then(d=>d&&setStaffList(d.staff||[])).catch(()=>{});
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to load bookings");
    } finally { setLoading(false); }
  }

  useEffect(()=>{fetch("/api/admin/auth/me").then(async r=>{if(!r.ok){router.replace("/admin/login");return null}return r.json()}).then(d=>{if(d?.user){setStaff(d.user);load()}}).catch(()=>router.replace("/admin/login"))},[router]);

  async function logout(){await fetch("/api/admin/auth/logout",{method:"POST"});router.replace("/admin/login");router.refresh()}

  async function createPaymentRequest(b: Booking, kind: "DEPOSIT"|"BALANCE") {
    const total=b.estimatedTotal||0; const paid=b.amountPaid||0;
    const amount=kind==="DEPOSIT"?(b.depositAmount||Math.max(1,Math.round(total*.3))):Math.max(1,total-paid);
    setError("");
    const res=await fetch("/api/admin/payments/request",{method:"POST",headers:{"content-type":"application/json"},body:JSON.stringify({bookingId:b.id,kind,amount})});
    const data=await res.json();
    if(!res.ok){setError(data.error||"Unable to create payment request");return}
    const url=window.location.origin+data.paymentUrl;
    await navigator.clipboard?.writeText(url);
    window.prompt("Payment link created. Copy and send this secure link to the guest:",url);
  }

  async function updateBooking(id:string,patch:Record<string,unknown>){
    setError("");
    const res=await fetch("/api/admin/bookings/"+id,{method:"PATCH",headers:{"content-type":"application/json"},body:JSON.stringify(patch)});
    const data=await res.json();
    if(!res.ok){setError(data.error||"Unable to update booking");return null}
    setBookings(items=>items.map(item=>item.id===id?{...item,...data.booking}:item));
    return data.booking;
  }

  async function updateStatus(id: string, status: string) {
    await updateBooking(id,{status});
  }

  const canRequestPayment=staff?.role==="ADMIN"||staff?.role==="SALES"||staff?.role==="FINANCE";
  const pipeline = bookings.reduce((n, b) => n + (b.estimatedTotal || 0), 0);

  return <main className="adminPage">
    <header className="adminHeader"><div className="shell"><a className="brand" href="/"><span className="brandMark">≋</span><span><b>HALONG CRUISE<br/>ADVISOR</b><small>Operations</small></span></a><nav className="adminNav"><a className="active" href="/admin">Bookings</a><a href="/admin/inventory">Inventory</a><a href="/">Website</a></nav></div></header>
    <div className="shell adminWrap">
      <div className="adminTitle"><span className="eyebrow">OPERATIONS MVP</span><h1>Booking enquiries</h1><p>Review the latest international guest requests and follow up through email or WhatsApp.</p></div>
      <div className="staffBar"><span>{staff?staff.name+" · "+staff.role:"Checking session..."}</span><div><button onClick={()=>load()} disabled={loading}>{loading?"Refreshing...":"Refresh"}</button><button onClick={logout}>Sign out</button></div></div>
      {error && <div className="adminError">{error}</div>}
      <div className="adminStats"><div><span>Total loaded</span><strong>{bookings.length}</strong></div><div><span>New enquiries</span><strong>{bookings.filter(b => b.status === "NEW").length}</strong></div><div><span>Estimated pipeline</span><strong>US$ {pipeline.toLocaleString()}</strong></div></div>
      <div className="adminTableWrap"><table className="adminTable"><thead><tr><th>Reference</th><th>Guest</th><th>Cruise / Date</th><th>Guests</th><th>Contact</th><th>Value</th><th>Owner / Follow-up</th><th>Payment</th><th>Status</th></tr></thead><tbody>
        {bookings.length === 0 ? <tr><td colSpan={9} className="emptyAdmin">No bookings loaded yet.</td></tr> : bookings.map(b => <tr key={b.id}><td><b>{b.reference}</b><small>{new Date(b.createdAt).toLocaleDateString("en-US")}</small></td><td>{b.primaryGuest}<small>{b.nationality || "-"}</small></td><td>{b.cruiseName}<small>{new Date(b.departureDate).toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" })}</small></td><td>{b.adults} adult{b.adults !== 1 ? "s" : ""}{b.children ? ", " + b.children + " child" : ""}</td><td><a href={"mailto:" + b.email}>{b.email}</a><small>{b.phone || "-"}</small></td><td>{b.estimatedTotal ? b.currency + " " + b.estimatedTotal : "-"}</td><td><select value={b.assignedToId||""} onChange={e=>updateBooking(b.id,{assignedToId:e.target.value||null})}><option value="">Unassigned</option>{staffList.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select><input type="date" value={b.followUpAt?b.followUpAt.slice(0,10):""} onChange={e=>updateBooking(b.id,{followUpAt:e.target.value||null})}/><button onClick={()=>{const note=window.prompt("Internal note",b.internalNotes||"");if(note!==null)updateBooking(b.id,{internalNotes:note})}}>Notes</button></td><td><b>{b.paymentStatus||"UNPAID"}</b><small>{b.amountPaid ? b.currency+" "+b.amountPaid+" paid" : "No payment"}</small>{canRequestPayment&&b.status==="CONFIRMED"&&b.paymentStatus!=="PAID"&&<div className="paymentActions"><button onClick={()=>createPaymentRequest(b,"DEPOSIT")}>Deposit link</button><button onClick={()=>createPaymentRequest(b,"BALANCE")}>Balance link</button></div>}</td><td><select className={"statusSelect " + b.status.toLowerCase()} value={b.status} onChange={e => updateStatus(b.id, e.target.value)}><option>NEW</option><option>CONTACTED</option><option>QUOTED</option><option>CONFIRMED</option><option>CANCELLED</option></select></td></tr>)}
      </tbody></table></div>
    </div>
  </main>;
}
