"use client";
import { useEffect,useState } from "react";
import { useParams } from "next/navigation";

type Payment={kind:string;amount:number;currency:string;expiresAt:string|null;providerUrl:string|null;booking:{reference:string;cruiseName:string;cabinName:string|null;departureDate:string;durationNights:number;adults:number;children:number;primaryGuest:string;estimatedTotal:number|null;amountPaid:number;paymentStatus:string;currency:string}};

export default function PayPage(){
 const params=useParams<{token:string}>(); const [payment,setPayment]=useState<Payment|null>(null); const [error,setError]=useState(""); const [loading,setLoading]=useState(true);
 useEffect(()=>{fetch("/api/payments/request/"+params.token).then(async r=>{const d=await r.json();if(!r.ok)throw new Error(d.error||"Payment request unavailable");return d}).then(d=>setPayment(d.payment)).catch(e=>setError(e.message)).finally(()=>setLoading(false))},[params.token]);
 if(loading)return <main className="paymentPage"><div className="paymentCard">Loading secure payment request...</div></main>;
 if(error||!payment)return <main className="paymentPage"><div className="paymentCard"><span className="eyebrow">PAYMENT REQUEST</span><h1>Payment link unavailable</h1><p>{error||"Please contact our cruise team for a new payment link."}</p><a className="darkButton linkButton" href="/">Back to website</a></div></main>;
 const b=payment.booking;
 return <main className="paymentPage"><div className="paymentShell">
  <section className="paymentCard"><span className="eyebrow">SECURE PAYMENT REQUEST</span><h1>{payment.kind==="DEPOSIT"?"Pay your cruise deposit":payment.kind==="BALANCE"?"Pay your remaining balance":"Complete your cruise payment"}</h1><p>Your cabin has been confirmed by our operations team. Review the booking details before continuing to the payment provider.</p>
   <div className="paymentAmount"><small>Amount due now</small><strong>{payment.currency} {payment.amount}</strong><span>{payment.kind}</span></div>
   {payment.providerUrl?<a className="button paymentButton" href={payment.providerUrl}>Continue to secure payment →</a>:<button className="button paymentButton" disabled>Payment provider not connected yet</button>}
   <div className="paymentSafety"><span>✓ Payment requested only after availability confirmation</span><span>✓ Booking reference shown before payment</span><span>✓ Do not send card details by email or WhatsApp</span></div>
  </section>
  <aside className="paymentSummary"><span className="eyebrow">BOOKING SUMMARY</span><h2>{b.cruiseName}</h2><p>{b.cabinName||"Confirmed cabin"}</p><dl><div><dt>Reference</dt><dd>{b.reference}</dd></div><div><dt>Guest</dt><dd>{b.primaryGuest}</dd></div><div><dt>Departure</dt><dd>{new Date(b.departureDate).toLocaleDateString("en-US",{month:"long",day:"numeric",year:"numeric"})}</dd></div><div><dt>Guests</dt><dd>{b.adults} adult{b.adults!==1?"s":""}{b.children?", "+b.children+" child":""}</dd></div><div><dt>Trip total</dt><dd>{b.currency} {b.estimatedTotal||0}</dd></div><div><dt>Paid so far</dt><dd>{b.currency} {b.amountPaid}</dd></div></dl>
   {payment.expiresAt&&<small className="paymentExpiry">This payment request expires {new Date(payment.expiresAt).toLocaleString("en-US")}.</small>}
  </aside>
 </div></main>
}
