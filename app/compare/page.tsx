const rows=[
 ["From price","US$ 320","US$ 315","US$ 450"],
 ["Guest rating","4.9 / 5","4.8 / 5","4.9 / 5"],
 ["Route","Halong + Lan Ha","Lan Ha Bay","Halong + Lan Ha"],
 ["Best for","Couples & honeymoon","Active travellers","Luxury & culture"],
 ["Cabins","22","24","20"],
 ["Private balcony","✓","✓","✓"],
 ["Swimming pool","✓","✓","✓"],
 ["Spa","✓","—","✓"],
 ["Family friendly","✓","✓","✓"],
 ["Hanoi transfer","Optional","Optional","Optional"],
 ["2D1N itinerary","✓","✓","✓"],
 ["3D2N itinerary","✓","✓","✓"]
];
export default function ComparePage(){
 return <main>
  <section className="compareHero"><div className="shell"><span className="eyebrow light">COMPARE CRUISES</span><h1>Choose the cruise that fits your trip</h1><p>Compare the essentials side by side. For exact availability and inclusions, check your travel date before booking.</p></div></section>
  <section className="shell compareWrap">
   <div className="compareTable">
    <div className="compareRow compareCards">
      <div className="compareLabel"><b>3 cruises</b><span>Side-by-side comparison</span></div>
      <article><div className="compareImg stellar"></div><span className="badge staticBadge">Best Seller</span><h2>Stellar of the Seas</h2><div className="rating">★ 4.9 <span>(328)</span></div><a href="/cruises/stellar-of-the-seas">View cruise →</a></article>
      <article><div className="compareImg lacasta"></div><span className="badge staticBadge greenBadge">New Cruise</span><h2>La Casta Regal</h2><div className="rating">★ 4.8 <span>(216)</span></div><a href="/cruises">View cruise →</a></article>
      <article><div className="compareImg heritage"></div><span className="badge staticBadge goldBadge">Luxury Choice</span><h2>Heritage Binh Chuan</h2><div className="rating">★ 4.9 <span>(412)</span></div><a href="/cruises">View cruise →</a></article>
    </div>
    {rows.map((r,i)=><div className={"compareRow "+(i%2?"alt":"")} key={r[0]}><b className="compareLabel">{r[0]}</b><span>{r[1]}</span><span>{r[2]}</span><span>{r[3]}</span></div>)}
    <div className="compareRow compareActions"><span></span><a className="button" href="/booking">Check Stellar</a><a className="darkButton linkButton" href="/cruises">Check La Casta</a><a className="darkButton linkButton" href="/cruises">Check Heritage</a></div>
   </div>
   <div className="compareHelp"><div><span className="eyebrow">NEED A SHORTLIST?</span><h2>Tell us what matters most.</h2><p>Share your dates, budget, group type and preferences. Our Vietnam-based team can suggest 2–3 suitable cruises.</p></div><a className="button" href="mailto:hello@halongcruiseadvisor.com">Ask a Cruise Expert →</a></div>
  </section>
 </main>
}
