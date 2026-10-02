"use client";

import { FormEvent, useMemo, useState } from "react";

export default function BookingPage(){
  const [adults,setAdults]=useState(2);
  const [children,setChildren]=useState(0);
  const [transfer,setTransfer]=useState("shared");
  const [reference,setReference]=useState("");
  const [error,setError]=useState("");
  const [submitting,setSubmitting]=useState(false);

  const cabinPrice=320;
  const childPrice=220;
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
          cruiseName:"Stellar of the Seas",
          cabinName:"Junior Suite",
          departureDate:form.get("departureDate"),
          durationNights:Number(form.get("durationNights")||1),
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
      <p>We’ve received your booking request for Stellar of the Seas. A Vietnam-based cruise expert will review cabin availability, final pricing and transfer details before payment.</p>
      <div className="successMeta"><div><b>Reference</b><span>{reference}</span></div><div><b>Estimated total</b><span>US$ {subtotal}</span></div><div><b>Payment</b><span>Not charged yet</span></div></div>
      <a className="button" href="/">Back to Homepage</a>
    </div></main>
  }

  return <main className="checkoutPage">
    <div className="shell detailNav"><a href="/">Home</a><span>›</span><a href="/cruises">Cruises</a><span>›</span><a href="/cruises/stellar-of-the-seas">Stellar of the Seas</a><span>›</span><b>Booking</b></div>
    <section className="shell checkoutHeader"><span className="eyebrow">SECURE BOOKING REQUEST</span><h1>Complete your cruise details</h1><p>No payment is taken at this stage. We confirm live cabin availability and the final total first.</p></section>
    <div className="shell checkoutLayout">
      <form className="checkoutForm" onSubmit={submitBooking}>
        <section className="checkoutSection"><div className="stepTitle"><span>1</span><div><h2>Trip details</h2><p>Tell us when you plan to travel.</p></div></div><div className="formGrid">
          <label><span>Departure date</span><input required name="departureDate" type="date"/></label>
          <label><span>Duration</span><select name="durationNights" defaultValue="1"><option value="1">2 days / 1 night</option><option value="2">3 days / 2 nights</option></select></label>
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
      <aside className="summaryCard"><div className="summaryImage"></div><div className="summaryBody"><span className="eyebrow">YOUR CRUISE</span><h2>Stellar of the Seas</h2><div className="rating">★ 4.9 <span>(328 reviews)</span></div><p>Junior Suite · Private balcony</p><div className="summaryRows"><div><span>Adults × {adults}</span><b>US$ {adults*cabinPrice}</b></div>{children>0&&<div><span>Children × {children}</span><b>US$ {children*childPrice}</b></div>}<div><span>{transfer==="shared"?"Shared limousine":transfer==="private"?"Private car":"No transfer"}</span><b>US$ {transferPrice}</b></div></div><div className="summaryTotal"><span>Estimated total</span><strong>US$ {subtotal}</strong></div><small>Final pricing may vary by travel date, cabin availability, child age and operator supplements.</small><div className="secureList"><span>✓ Secure booking process</span><span>✓ No payment before confirmation</span><span>✓ Vietnam-based support</span></div></div></aside>
    </div>
  </main>
}
