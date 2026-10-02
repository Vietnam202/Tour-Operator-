"use client";
import {useEffect,useState} from "react";
import {useRouter} from "next/navigation";

type Row={id:string;reference:string;cruiseName:string;departureDate:string;status:string;paymentStatus:string;estimatedTotal:number|null;quotedCost:number|null;quotedMargin:number|null;amountPaid:number;amountRefunded:number;currency:string};
type Data={currency:string;totals:{revenue:number;cost:number;margin:number;collected:number;outstanding:number;marginPct:number};bookings:Row[]};

export default function FinancePage(){
 const router=useRouter();const [data,setData]=useState<Data|null>(null);const [error,setError]=useState("");
 useEffect(()=>{Promise.all([fetch("/api/admin/auth/me"),fetch("/api/admin/finance")]).then(async([me,res])=>{if(!me.ok){router.replace("/admin/login");return}const d=await res.json();if(!res.ok)throw new Error(d.error||"Unable to load finance dashboard");setData(d)}).catch(e=>setError(e.message))},[router]);
 const money=(n:number)=>"US$ "+n.toLocaleString("en-US");
 return <main className="adminPage"><header className="adminHeader"><div className="shell"><a className="brand" href="/"><span className="brandMark">≋</span><span><b>HALONG CRUISE<br/>ADVISOR</b><small>Finance</small></span></a><nav className="adminNav"><a href="/admin">Bookings</a><a href="/admin/inventory">Inventory</a><a className="active" href="/admin/finance">Finance</a></nav></div></header>
 <div className="shell adminWrap"><div className="adminTitle"><span className="eyebrow">COMMERCIAL PERFORMANCE</span><h1>Revenue & gross profit</h1><p>Internal commercial view based on booking quote snapshots and recorded payments.</p></div>
 {error&&<div className="adminError">{error}</div>}
 {!data?<div className="inventoryEmpty">Loading finance dashboard...</div>:<>
 <div className="adminStats"><div><span>Booked revenue</span><strong>{money(data.totals.revenue)}</strong></div><div><span>Supplier cost</span><strong>{money(data.totals.cost)}</strong></div><div><span>Gross profit</span><strong>{money(data.totals.margin)} · {data.totals.marginPct}%</strong></div><div><span>Collected</span><strong>{money(data.totals.collected)}</strong></div><div><span>Outstanding</span><strong>{money(data.totals.outstanding)}</strong></div></div>
 <div className="adminTableWrap"><table className="adminTable"><thead><tr><th>Reference</th><th>Cruise</th><th>Departure</th><th>Revenue</th><th>Cost</th><th>Gross profit</th><th>Collected</th><th>Outstanding</th></tr></thead><tbody>{data.bookings.length?data.bookings.map(b=>{const revenue=b.estimatedTotal||0,cost=b.quotedCost||0,margin=b.quotedMargin??revenue-cost,paid=Math.max(0,b.amountPaid-b.amountRefunded);return <tr key={b.id}><td><b>{b.reference}</b><small>{b.status} · {b.paymentStatus}</small></td><td>{b.cruiseName}</td><td>{new Date(b.departureDate).toLocaleDateString("en-US")}</td><td>{money(revenue)}</td><td>{money(cost)}</td><td>{money(margin)}<small>{revenue?Math.round(margin/revenue*1000)/10:0}%</small></td><td>{money(paid)}</td><td>{money(Math.max(0,revenue-paid))}</td></tr>}):<tr><td colSpan={8} className="emptyAdmin">No quoted or confirmed bookings yet.</td></tr>}</tbody></table></div></>}
 </div></main>
}
