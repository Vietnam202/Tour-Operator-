"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "next/navigation";

type BookingCruise={id:string;slug:string;name:string;cabins:{id:string;name:string;basePrice:number}[];departures:{id:string;departureDate:string;durationNights:number;priceFrom:number}[]};

export default function BookingPage(){
  const params=useSearchParams();
  const cruiseSlug=params.get("cruise")||"stellar-of-the-seas";
  const requestedCabin=params.get("cabin")||"";
  const requestedDeparture=params.get("departure")||"";
  const [cruise,setCruise]=useState<BookingCruise|null>(null);
  const [selectedCabin,setSelectedCabin]=useState(requestedCabin);
  const [selectedDeparture,setSelectedDeparture]=useState(requestedDeparture);
  const [adults,setAdults]=useState(2);
  const [children,setChildren]=useState(0);
  const [transfer,setTransfer]=useState("shared");
  const [reference,setReference]=useState("");
  const [error,setError]=useState("");
  const [submitting,setSubmitting]=useState(false);

  useEffect(()=>{fetch("/api/booking-context?cruise="+encodeURIComponent(cruiseSlug)).then(r=>r.ok?r.json():Promise.reject()).then(d=>{setCruise(d.cruise);if(!selectedCabin&&d.cruise.cabins[0])setSelectedCabin(d.cruise.cabins[0].id);if(!selectedDeparture&&d.cruise.departures[0])setSelectedDeparture(d.cruise.departures[0].id)}).catch(()=>setError("Live inventory is unavailable. Please return to the cruise page and try again."))},[cruiseSlug]);
  const cabin=cruise?.cabins.find(c=>c.id===selectedCabin)||cruise?.cabins[0];
  const departure=cruise?.departures.find(d=>d.id===selectedDeparture);
  const cabinPrice=departure?.priceFrom??cabin?.basePrice??320;
  const childPrice=Math.round(cabinPrice*0.7);
  const transferPrice=transfer==="shared"?35:transfer==="private"?95:0;
  const subtotal=useMemo(()=>adults*cabinPrice+children*childPrice+transferPrice,[adults,children,transferPrice]);

  async function submitBooking(e:FormEvent<HTMLFormElement>){
    e.preventDefault(); setSubmitting(true); setError("");
    const form=new FormData(e.currentTarget);
    try{
      const res=await fetch("/api/bookings",{
        method:"POST",
        headers:{"content-type":"application/json"},
        body:JSON.stringify({
          cruiseId:cruise?.id,
          cruiseName:cruise?.name||"cruise?.name||"Selected cruise"",
          cabinName:cabin?.name||"Selected cabin",
          departureDate:form.get("departureDate"),
          durationNights:Number(form.get("durationNights")||departure?.durationNights||1),
          adults,children,
          primaryGuest:form.get("primaryGuest"),
          nationality:form.get("nationality"),
          email:form.get("email"),
          phone:form.get("phone"),
          transferType:transfer,
          specialRequests:form.get("specialRequests"),
          estimatedTotal:subtotal
        })
      });
      const data=await res.json();
      if(!res.ok) throw new Error(data.error||"Unable to submit booking request");
      setReference(data.reference);
      window.scrollTo({top:0,behavior:"smooth"});
    }catch(err){
      setError(err instanceof Error?err.message:"Unable to submit booking request");
    }finally{setSubmitting(false)}
  }

  if(reference){
    return <main className="bookingSuccess"><div className="shell successCard">
      <div className="successIcon">✓</div><span className="eyebrow">REQUEST RECEIVED</span>
      <h1>Thanks — our cruise team will confirm availability.</h1>
      <p>We’ve received your booking request for cruise?.name||"Selected cruise". A Vietnam-based cruise expert will review cabin availability, final pricing and transfer details before payment.</p>
      <div className="successMeta"><div><b>Reference</b><span>{reference}</span></div><div><b>Estimated total</b><span>US$ {subtotal}</span></div><div><b>Payment</b><span>Not charged yet</span></div></div>
      <a className="button" href="/">Back to Homepage</a>
    </div></main>
  }

  return <main className="checkoutPage">
    <div className="shell detailNav"><a href="/">Home</a><span>›</span><a href="/cruises">Cruises</a><span>›</span><a href="/cruises/stellar-of-the-seas">cruise?.name||"Selected cruise"</a><span>›</span><b>Booking</b></div>
    <section className="shell checkoutHeader"><span className="eyebrow">SECURE BOOKING REQUEST</span><h1>Complete your cruise details</h1><p>No payment is taken at this stage. We confirm live cabin availability and the final total first.</p></section>
    <div className="shell checkoutLayout">
      <form className="checkoutForm" onSubmit={submitBooking}>
        <section className="checkoutSection"><div className="stepTitle"><span>1</span><div><h2>Trip details</h2><p>Tell us when you plan to travel.</p></div></div><div className="formGrid">
          <label><span>Departure date</span><input required name="departureDate" type="date" defaultValue={departure?departure.departureDate.slice(0,10):""}/></label>
          <label><span>Duration</span><select name="durationNights" defaultValue={String(departure?.durationNights||1)}><option value="1">2 days / 1 night</option><option value="2">3 days / 2 nights</option></select></label>
        </div></section>
        <section className="checkoutSection"><div className="stepTitle"><span>2</span><div><h2>Guests</h2><p>Passenger details help us confirm the correct cabin setup.</p></div></div>
          <div className="guestCounter"><div><b>Adults</b><small>Age 12+</small></div><div><button type="button" onClick={()=>setAdults(Math.max(1,adults-1))}>−</button><strong>{adults}</strong><button type="button" onClick={()=>setAdults(adults+1)}>+</button></div></div>
          <div className="guestCounter"><div><b>Children</b><small>Age 5–11</small></div><div><button type="button" onClick={()=>setChildren(Math.max(0,children-1))}>−</button><strong>{children}</strong><button type="button" onClick={()=>setChildren(children+1)}>+</button></div></div>
          <div className="formGrid"><label><span>Primary guest full name</span><input required name="primaryGuest" placeholder="As shown on passport"/></label><label><span>Nationality</span><input required name="nationality" placeholder="e.g. United Kingdom"/></label></div>
        </section>
        <section className="checkoutSection"><div className="stepTitle"><span>3</span><div><h2>Hanoi transfer</h2><p>Add a convenient transfer to and from the cruise harbour.</p></div></div>
          <div className="transferOptions">
            <label className={transfer==="shared"?"selected":""}><input type="radio" name="transfer" checked={transfer==="shared"} onChange={()=>setTransfer("shared")}/><div><b>Shared limousine</b><span>Hanoi Old Quarter pickup</span></div><strong>US$ 35</strong></label>
            <label className={transfer==="private"?"selected":""}><input type="radio" name="transfer" checked={transfer==="private"} onChange={()=>setTransfer("private")}/><div><b>Private car</b><span>Flexible Hanoi pickup</span></div><strong>US$ 95</strong></label>
            <label className={transfer==="none"?"selected":""}><input type="radio" name="transfer" checked={transfer==="none"} onChange={()=>setTransfer("none")}/><div><b>No transfer</b><span>I will reach the harbour independently</span></div><strong>US$ 0</strong></label>
          </div>
        </section>
        <section className="checkoutSection"><div className="stepTitle"><span>4</span><div><h2>Contact details</h2><p>We’ll send availability and payment instructions here.</p></div></div>
          <div className="formGrid"><label><span>Email address</span><input required name="email" type="email" placeholder="you@example.com"/></label><label><span>WhatsApp / phone</span><input required name="phone" placeholder="+44 ..."/></label></div>
          <label className="fullLabel"><span>Special requests</span><textarea name="specialRequests" placeholder="Dietary needs, honeymoon, child age, mobility needs, hotel pickup details..."></textarea></label>
        </section>
        <section className="checkoutSection"><div className="stepTitle"><span>5</span><div><h2>Before you submit</h2><p>Review the key booking terms.</p></div></div>
          <div className="policyBox"><p>✓ Final price is confirmed after live cabin availability is checked.</p><p>✓ Cancellation terms will be shown before payment.</p><p>✓ Passport details may be required by the cruise operator after confirmation.</p><p>✓ Travel insurance is recommended for international travellers.</p></div>
          <label className="terms"><input required type="checkbox"/> <span>I understand this is an availability request and agree to be contacted about this booking.</span></label>
          {error&&<div className="bookingError">{error}</div>}
          <button className="button checkoutSubmit" type="submit" disabled={submitting}>{submitting?"Submitting request...":"Request Booking Confirmation →"}</button>
        </section>
      </form>
      <aside className="summaryCard"><div className="summaryImage"></div><div className="summaryBody"><span className="eyebrow">YOUR CRUISE</span><h2>cruise?.name||"Selected cruise"</h2><div className="rating">★ International guest support</div><p>{cabin?.name||"Select cabin"} · {departure?new Date(departure.departureDate).toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"}):"Choose your date"}</p>{cruise&&cruise.cabins.length>1&&<label className="summaryCabinSelect"><span>Cabin</span><select value={selectedCabin} onChange={e=>setSelectedCabin(e.target.value)}>{cruise.cabins.map(c=><option key={c.id} value={c.id}>{c.name} - US$ {c.basePrice}</option>)}</select></label>}<div className="summaryRows"><div><span>Adults × {adults}</span><b>US$ {adults*cabinPrice}</b></div>{children>0&&<div><span>Children × {children}</span><b>US$ {children*childPrice}</b></div>}<div><span>{transfer==="shared"?"Shared limousine":transfer==="private"?"Private car":"No transfer"}</span><b>US$ {transferPrice}</b></div></div><div className="summaryTotal"><span>Estimated total</span><strong>US$ {subtotal}</strong></div><small>Final pricing may vary by travel date, cabin availability, child age and operator supplements.</small><div className="secureList"><span>✓ Secure booking process</span><span>✓ No payment before confirmation</span><span>✓ Vietnam-based support</span></div></div></aside>
    </div>
  </main>
}
