"use client";
import {useEffect,useState} from "react";
import {useParams} from "next/navigation";

type Voucher={reference:string;cruiseName:string;cabinName:string|null;departureDate:string;durationNights:number;adults:number;children:number;primaryGuest:string;nationality:string|null;transferType:string|null;estimatedTotal:number|null;amountPaid:number;currency:string;paymentStatus:string};

export default function VoucherPage(){
 const {reference}=useParams<{reference:string}>();const [v,setV]=useState<Voucher|null>(null);const [error,setError]=useState("");
 useEffect(()=>{fetch("/api/voucher/"+encodeURIComponent(reference)).then(async r=>{const d=await r.json();if(!r.ok)throw new Error(d.error);return d}).then(d=>setV(d.voucher)).catch(e=>setError(e.message))},[reference]);
 if(error)return <main className="voucherPage"><div className="voucher"><h1>Voucher unavailable</h1><p>{error}</p></div></main>;
 if(!v)return <main className="voucherPage"><div className="voucher">Loading travel voucher...</div></main>;
 return <main className="voucherPage"><div className="voucher">
  <header><div><span className="eyebrow">HALONG CRUISE ADVISOR</span><h1>Cruise Travel Voucher</h1></div><div className="voucherRef"><small>BOOKING REFERENCE</small><b>{v.reference}</b></div></header>
  <div className="voucherStatus">✓ Booking confirmed · {v.paymentStatus==="PAID"?"Paid in full":"Deposit received"}</div>
  <section><h2>{v.cruiseName}</h2><p>{v.cabinName||"Confirmed cabin"}</p></section>
  <div className="voucherGrid"><div><small>Lead guest</small><b>{v.primaryGuest}</b><span>{v.nationality||""}</span></div><div><small>Departure</small><b>{new Date(v.departureDate).toLocaleDateString("en-US",{month:"long",day:"numeric",year:"numeric"})}</b><span>{v.durationNights+1} days / {v.durationNights} night{v.durationNights>1?"s":""}</span></div><div><small>Guests</small><b>{v.adults} adult{v.adults!==1?"s":""}</b><span>{v.children?v.children+" child": "No children"}</span></div><div><small>Transfer</small><b>{v.transferType||"Not selected"}</b><span>Confirm pickup details with our team</span></div></div>
  <div className="voucherMoney"><div><span>Trip total</span><b>{v.currency} {v.estimatedTotal||0}</b></div><div><span>Amount received</span><b>{v.currency} {v.amountPaid}</b></div></div>
  <div className="voucherNotes"><h3>Before you travel</h3><p>Bring your passport and keep this booking reference available at check-in. Final harbour, pickup time and transfer details should be reconfirmed with our operations team before departure.</p><p>Travel insurance is recommended for international travellers.</p></div>
  <button className="darkButton voucherPrint" onClick={()=>window.print()}>Print / Save as PDF</button>
 </div></main>
}
