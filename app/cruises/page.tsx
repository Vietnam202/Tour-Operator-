const cruises = [
  {slug:"stellar-of-the-seas",name:"Stellar of the Seas",rating:4.9,reviews:328,route:"Halong Bay – Lan Ha Bay",duration:"2D1N / 3D2N",price:320,oldPrice:480,stars:5,tag:"Best Seller",features:["All meals included","Hanoi transfer available","Balcony cabins"],image:"https://images.unsplash.com/photo-1528127269322-539801943592?auto=format&fit=crop&w=1200&q=85"},
  {slug:"la-casta-regal",name:"La Casta Regal Cruise",rating:4.8,reviews:216,route:"Lan Ha Bay – Dark & Bright Cave",duration:"2D1N / 3D2N",price:315,oldPrice:450,stars:5,tag:"New Cruise",features:["All meals included","Kayaking","Outdoor pool"],image:"https://images.unsplash.com/photo-1559592413-7cec4d0cae2b?auto=format&fit=crop&w=1200&q=85"},
  {slug:"heritage-binh-chuan",name:"Heritage Binh Chuan",rating:4.9,reviews:412,route:"Halong Bay – Lan Ha Bay",duration:"2D1N / 3D2N",price:450,oldPrice:590,stars:5,tag:"Luxury Choice",features:["Fine dining","Spa","Large suites"],image:"https://images.unsplash.com/photo-1504457047772-27faf1c00561?auto=format&fit=crop&w=1200&q=85"},
  {slug:"ambassador-cruise",name:"Ambassador Cruise",rating:4.7,reviews:289,route:"Halong Bay – Sung Sot Cave",duration:"2D1N",price:285,oldPrice:390,stars:5,tag:"Great Value",features:["Live music","Sundeck","Family friendly"],image:"https://images.unsplash.com/photo-1528181304800-259b08848526?auto=format&fit=crop&w=1200&q=85"}
];

export default function CruisesPage(){
 return <main>
   <section className="listingHero">
     <div className="shell">
       <span className="eyebrow light">HALONG BAY CRUISES</span>
       <h1>Find the right cruise for your Vietnam trip</h1>
       <p>Compare routes, cabin styles, guest reviews and prices from handpicked cruises in Halong Bay and Lan Ha Bay.</p>
     </div>
   </section>
   <section className="shell listingWrap">
     <aside className="filters">
       <div className="filterHead"><h2>Filter cruises</h2><button>Reset</button></div>
       <label><span>Departure date</span><input type="date"/></label>
       <label><span>Duration</span><select defaultValue="any"><option value="any">Any duration</option><option>2 days / 1 night</option><option>3 days / 2 nights</option></select></label>
       <div className="filterGroup"><span>Price per person</span><div className="priceInputs"><input placeholder="Min USD"/><input placeholder="Max USD"/></div></div>
       <div className="filterGroup"><span>Cruise class</span><label><input type="checkbox"/> 5-star luxury</label><label><input type="checkbox"/> Premium</label></div>
       <div className="filterGroup"><span>Route</span><label><input type="checkbox"/> Halong Bay</label><label><input type="checkbox"/> Lan Ha Bay</label><label><input type="checkbox"/> Bai Tu Long Bay</label></div>
       <div className="filterGroup"><span>Popular features</span><label><input type="checkbox"/> Balcony cabin</label><label><input type="checkbox"/> Pool</label><label><input type="checkbox"/> Family friendly</label><label><input type="checkbox"/> Hanoi transfer</label></div>
     </aside>
     <div className="results"><div className="compareBanner"><span>Comparing several options?</span><a href="/compare">Compare our top cruises →</a></div>
       <div className="resultsTop"><div><span className="eyebrow">CURATED CRUISES</span><h2>{cruises.length} recommended cruises</h2></div><label>Sort by <select><option>Recommended</option><option>Price: low to high</option><option>Guest rating</option></select></label></div>
       <div className="listingCards">
         {cruises.map(c=><article className="listingCard" key={c.slug}>
           <a href={"/cruises/"+c.slug} className="listingImage" style={{backgroundImage:`url("${c.image}")`}}><span className="badge">{c.tag}</span><span className="photos">▣ Gallery</span></a>
           <div className="listingInfo">
             <div className="listingTitleRow"><div><h3><a href={"/cruises/"+c.slug}>{c.name}</a></h3><div className="rating">★ {c.rating} <span>({c.reviews} reviews)</span></div></div><button className="heart">♡</button></div>
             <p className="routeLine">⌖ {c.route}</p>
             <p className="durationLine">▣ {c.duration} · {c.stars}-star cruise</p>
             <ul>{c.features.map(f=><li key={f}>✓ {f}</li>)}</ul>
             <div className="listingBottom"><div><small>From</small><del>US$ {c.oldPrice}</del><strong>US$ {c.price}</strong><span>/ person</span></div><a className="darkButton linkButton" href={"/cruises/"+c.slug}>View Cruise →</a></div>
           </div>
         </article>)}
       </div>
     </div>
   </section>
 </main>
}
