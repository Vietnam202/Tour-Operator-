"use client";

import { FormEvent, useState } from "react";

type Booking = {
  id: string; reference: string; cruiseName: string; departureDate: string; adults: number;
  children: number; primaryGuest: string; nationality?: string; email: string; phone?: string;
  estimatedTotal?: number; currency: string; status: string; createdAt: string;
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

  const pipeline = bookings.reduce((n, b) => n + (b.estimatedTotal || 0), 0);

  return <main className="adminPage">
    <header className="adminHeader"><div className="shell"><a className="brand" href="/"><span className="brandMark">≋</span><span><b>HALONG CRUISE<br/>ADVISOR</b><small>Operations</small></span></a><a href="/">View website →</a></div></header>
    <div className="shell adminWrap">
      <div className="adminTitle"><span className="eyebrow">OPERATIONS MVP</span><h1>Booking enquiries</h1><p>Review the latest international guest requests and follow up through email or WhatsApp.</p></div>
      <form className="adminLogin" onSubmit={load}><label><span>Admin API key</span><input type="password" value={key} onChange={e => setKey(e.target.value)} placeholder="Enter admin key"/></label><button className="darkButton" disabled={loading}>{loading ? "Loading..." : "Load bookings"}</button></form>
      {error && <div className="adminError">{error}</div>}
      <div className="adminStats"><div><span>Total loaded</span><strong>{bookings.length}</strong></div><div><span>New enquiries</span><strong>{bookings.filter(b => b.status === "NEW").length}</strong></div><div><span>Estimated pipeline</span><strong>US$ {pipeline.toLocaleString()}</strong></div></div>
      <div className="adminTableWrap"><table className="adminTable"><thead><tr><th>Reference</th><th>Guest</th><th>Cruise / Date</th><th>Guests</th><th>Contact</th><th>Value</th><th>Status</th></tr></thead><tbody>
        {bookings.length === 0 ? <tr><td colSpan={7} className="emptyAdmin">No bookings loaded yet.</td></tr> : bookings.map(b => <tr key={b.id}><td><b>{b.reference}</b><small>{new Date(b.createdAt).toLocaleDateString("en-US")}</small></td><td>{b.primaryGuest}<small>{b.nationality || "-"}</small></td><td>{b.cruiseName}<small>{new Date(b.departureDate).toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" })}</small></td><td>{b.adults} adult{b.adults !== 1 ? "s" : ""}{b.children ? ", " + b.children + " child" : ""}</td><td><a href={"mailto:" + b.email}>{b.email}</a><small>{b.phone || "-"}</small></td><td>{b.estimatedTotal ? b.currency + " " + b.estimatedTotal : "-"}</td><td><span className={"statusPill " + b.status.toLowerCase()}>{b.status}</span></td></tr>)}
      </tbody></table></div>
    </div>
  </main>;
}
