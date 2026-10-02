const cabins=[
 {name:"Junior Suite",size:"28 m²",view:"Private balcony · Bay view",price:320,rooms:"3 cabins left",image:"https://images.unsplash.com/photo-1618773928121-c32242e63f39?auto=format&fit=crop&w=900&q=85"},
 {name:"Senior Suite",size:"32 m²",view:"Private balcony · Upper deck",price:375,rooms:"2 cabins left",image:"https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=900&q=85"},
 {name:"Executive Suite",size:"45 m²",view:"Panoramic bay view · Bathtub",price:460,rooms:"On request",image:"https://images.unsplash.com/photo-1566665797739-1674de7a421a?auto=format&fit=crop&w=900&q=85"}
];
export default function CruiseDetail(){
 return <main>
  <div className="detailNav shell"><a href="/">Home</a><span>›</span><a href="/cruises">Halong Bay Cruises</a><span>›</span><b>Stellar of the Seas</b></div>
  <section className="shell detailHeader">
    <div><span className="badge staticBadge">Best Seller</span><h1>Stellar of the Seas</h1><div className="rating">★ 4.9 <span>(328 verified guest reviews)</span></div><p>⌖ Halong Bay – Lan Ha Bay &nbsp; · &nbsp; 5-star luxury cruise</p></div>
    <div className="detailActions"><button>♡ Save</button><button>↗ Share</button></div>
  </section>
  <section className="shell gallery">
    <div className="galleryMain"></div><div className="gallerySide one"></div><div className="gallerySide two"><button>▣ View all photos</button></div>
  </section>
  <nav className="anchorNav"><div className="shell"><a href="#overview">Overview</a><a href="#cabins">Cabins & Rates</a><a href="#itinerary">Itinerary</a><a href="#included">What's Included</a><a href="#reviews">Reviews</a></div></nav>
  <div className="shell detailLayout">
   <div className="detailContent">
    <section id="overview" className="detailSection"><span className="eyebrow">CRUISE OVERVIEW</span><h2>A refined way to experience Lan Ha Bay</h2><p>Stellar of the Seas combines contemporary cabins, spacious public areas and a quieter Lan Ha Bay itinerary. It is especially well suited to couples, honeymooners and travellers who value comfort without giving up kayaking, cave visits and time on the water.</p>
      <div className="highlights"><div><b>2D1N / 3D2N</b><span>Itineraries</span></div><div><b>22 cabins</b><span>Small-scale luxury</span></div><div><b>Hanoi</b><span>Transfer available</span></div><div><b>All meals</b><span>Included onboard</span></div></div>
    </section>
    <section id="cabins" className="detailSection"><span className="eyebrow">CABINS & RATES</span><h2>Choose your cabin</h2><p className="sectionIntro">Rates below are indicative per person. Select your date and guests to confirm live availability and the final total.</p>
      <div className="cabinList">{cabins.map(c=><article className="cabin" key={c.name}><div className="cabinImg" style={{backgroundImage:`url("${c.image}")`}}></div><div className="cabinInfo"><h3>{c.name}</h3><p>{c.size} · {c.view}</p><div className="cabinFeatures"><span>✓ Ensuite bathroom</span><span>✓ Air conditioning</span><span>✓ Breakfast & meals</span></div><small className="availability">{c.rooms}</small></div><div className="cabinPrice"><small>From</small><strong>US$ {c.price}</strong><span>/ person</span><button className="darkButton">Select cabin</button></div></article>)}</div>
    </section>
    <section id="itinerary" className="detailSection"><span className="eyebrow">2 DAYS / 1 NIGHT</span><h2>Sample itinerary</h2>
      <div className="timeline"><div><b>Day 1</b><article><h3>Hanoi → Halong Bay → Lan Ha Bay</h3><p>Optional pickup from Hanoi Old Quarter. Board the cruise around noon, enjoy lunch while sailing into the bay, then kayak or visit a local cave area. Sunset activity, dinner and evening onboard.</p></article></div><div><b>Day 2</b><article><h3>Morning activity → Brunch → Hanoi</h3><p>Start with a light morning activity, explore one final bay highlight and enjoy brunch while cruising back to the harbour. Optional shared limousine transfer returns to Hanoi.</p></article></div></div>
    </section>
    <section id="included" className="detailSection"><span className="eyebrow">GOOD TO KNOW</span><h2>What's included</h2><div className="includedGrid"><div><h3>Included</h3><p>✓ Private cabin</p><p>✓ Meals stated in itinerary</p><p>✓ Kayaking / local activities</p><p>✓ English-speaking cruise staff</p><p>✓ Entrance and sightseeing fees</p></div><div><h3>Not included</h3><p>– Hanoi transfers unless selected</p><p>– Drinks and personal expenses</p><p>– Spa treatments</p><p>– Tips / gratuities</p><p>– Travel insurance</p></div></div>
    </section>
    <section id="reviews" className="detailSection"><span className="eyebrow">GUEST REVIEWS</span><h2>4.9 / 5 <span className="stars">★★★★★</span></h2><p>Based on 328 guest reviews. Review data shown in this MVP is placeholder content pending production integration.</p></section>
   </div>
   <aside className="bookingBox">
    <div className="bookingPrice"><small>From</small><strong>US$ 320</strong><span>/ person</span></div>
    <p>Check your travel date for available cabins and the final price.</p>
    <label><span>Departure date</span><input type="date"/></label><label><span>Duration</span><select><option>2 days / 1 night</option><option>3 days / 2 nights</option></select></label><label><span>Guests</span><select><option>2 Adults</option><option>1 Adult</option><option>2 Adults, 1 Child</option></select></label>
    <button className="button bookingCta">Check Availability</button>
    <div className="bookingTrust"><span>✓ No booking fee</span><span>✓ Secure payment</span><span>✓ Local support 24/7</span></div>
    <div className="advisorMini"><b>Need help choosing?</b><p>Ask our Vietnam-based cruise team.</p><a href="mailto:hello@halongcruiseadvisor.com">Ask a Cruise Expert →</a></div>
   </aside>
  </div>
  <div className="mobileBooking"><div><small>From</small><b>US$ 320</b><span>/ person</span></div><button className="button">Check Availability</button></div>
 </main>
}
