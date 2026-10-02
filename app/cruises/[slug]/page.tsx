type Cabin={id:string;name:string;sizeSqm:number|null;description:string|null;basePrice:number;currency:string;image:string|null};
type Departure={id:string;departureDate:string;durationNights:number;priceFrom:number;currency:string;cabinsLeft:number|null};
type Cruise={id:string;slug:string;name:string;summary:string|null;route:string;stars:number;rating:number;reviewCount:number;badge:string|null;heroImage:string|null;cabins:Cabin[];departures:Departure[]};

const fallback:Record<string,Cruise>={
 "stellar-of-the-seas":{
  id:"fallback-stellar",slug:"stellar-of-the-seas",name:"Stellar of the Seas",summary:"A refined luxury cruise combining contemporary cabins, spacious public areas and a quieter Lan Ha Bay itinerary.",route:"Halong Bay - Lan Ha Bay",stars:5,rating:4.9,reviewCount:328,badge:"Best Seller",heroImage:"https://images.unsplash.com/photo-1528127269322-539801943592?auto=format&fit=crop&w=1400&q=88",
  cabins:[
   {id:"junior",name:"Junior Suite",sizeSqm:28,description:"Private balcony - Bay view",basePrice:320,currency:"USD",image:"https://images.unsplash.com/photo-1618773928121-c32242e63f39?auto=format&fit=crop&w=900&q=85"},
   {id:"senior",name:"Senior Suite",sizeSqm:32,description:"Private balcony - Upper deck",basePrice:375,currency:"USD",image:"https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=900&q=85"},
   {id:"executive",name:"Executive Suite",sizeSqm:45,description:"Panoramic bay view - Bathtub",basePrice:460,currency:"USD",image:"https://images.unsplash.com/photo-1566665797739-1674de7a421a?auto=format&fit=crop&w=900&q=85"}
  ],
  departures:[]
 }
};

async function getCruise(slug:string){
 try{
  const base=process.env.NEXT_PUBLIC_SITE_URL;
  if(!base)return fallback[slug]||null;
  const res=await fetch(base+"/api/cruises/"+slug,{next:{revalidate:60}});
  if(!res.ok)return fallback[slug]||null;
  const data=await res.json();
  return data.cruise as Cruise;
 }catch{return fallback[slug]||null}
}

export default async function CruiseDetail({params}:{params:Promise<{slug:string}>}){
 const {slug}=await params;
 const cruise=await getCruise(slug);
 if(!cruise)return <main className="shell notFoundCruise"><h1>Cruise not found</h1><p>This cruise may be unpublished or unavailable.</p><a className="button" href="/cruises">Browse cruises</a></main>;
 const from=cruise.departures[0]?.priceFrom??cruise.cabins[0]?.basePrice??0;
 const hero=cruise.heroImage||"https://images.unsplash.com/photo-1573270689103-d7a4e42b609a?auto=format&fit=crop&w=1400&q=85";
 return <main>
  <div className="detailNav shell"><a href="/">Home</a><span>›</span><a href="/cruises">Cruises</a><span>›</span><b>{cruise.name}</b></div>
  <section className="shell detailHeader"><div>{cruise.badge&&<span className="badge staticBadge">{cruise.badge}</span>}<h1>{cruise.name}</h1><div className="rating">★ {cruise.rating.toFixed(1)} <span>({cruise.reviewCount} guest reviews)</span></div><p>⌖ {cruise.route} &nbsp; · &nbsp; {cruise.stars}-star cruise</p></div><div className="detailActions"><button>♡ Save</button><button>↗ Share</button></div></section>
  <section className="shell gallery"><div className="galleryMain" style={{backgroundImage:`url("${hero}")`}}></div><div className="gallerySide one" style={{backgroundImage:`url("${cruise.cabins[0]?.image||hero}")`}}></div><div className="gallerySide two" style={{backgroundImage:`linear-gradient(rgba(0,38,55,.25),rgba(0,38,55,.25)),url("${cruise.cabins[1]?.image||hero}")`}}><button>▣ View all photos</button></div></section>
  <nav className="anchorNav"><div className="shell"><a href="#overview">Overview</a><a href="#cabins">Cabins & Rates</a><a href="#departures">Departures</a><a href="#included">What's Included</a></div></nav>
  <div className="shell detailLayout"><div className="detailContent">
   <section id="overview" className="detailSection"><span className="eyebrow">CRUISE OVERVIEW</span><h2>{cruise.name}</h2><p>{cruise.summary||"A handpicked cruise for international travellers looking for a comfortable way to explore Halong Bay and nearby routes."}</p><div className="highlights"><div><b>{cruise.stars}-star</b><span>Cruise class</span></div><div><b>{cruise.cabins.length} cabin types</b><span>Current inventory</span></div><div><b>Hanoi</b><span>Transfer available</span></div><div><b>English</b><span>Guest support</span></div></div></section>
   <section id="cabins" className="detailSection"><span className="eyebrow">CABINS & RATES</span><h2>Choose your cabin</h2><p className="sectionIntro">Select a cabin to continue. Final availability is confirmed before payment.</p><div className="cabinList">{cruise.cabins.map(c=><article className="cabin" key={c.id}><div className="cabinImg" style={{backgroundImage:`url("${c.image||hero}")`}}></div><div className="cabinInfo"><h3>{c.name}</h3><p>{c.sizeSqm?c.sizeSqm+" m² · ":""}{c.description||"Comfortable private cabin"}</p><div className="cabinFeatures"><span>✓ Ensuite bathroom</span><span>✓ Air conditioning</span><span>✓ Meals included</span></div></div><div className="cabinPrice"><small>From</small><strong>{c.currency} {c.basePrice}</strong><span>/ person</span><a className="darkButton linkButton" href={"/booking?cruise="+encodeURIComponent(cruise.slug)+"&cabin="+encodeURIComponent(c.id)}>Select cabin</a></div></article>)}</div></section>
   <section id="departures" className="detailSection"><span className="eyebrow">UPCOMING AVAILABILITY</span><h2>Departure dates</h2>{cruise.departures.length?<div className="departureGrid">{cruise.departures.map(d=><a key={d.id} className="departureCard" href={"/booking?cruise="+encodeURIComponent(cruise.slug)+"&departure="+encodeURIComponent(d.id)}><div><b>{new Date(d.departureDate).toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"})}</b><span>{d.durationNights+1} days / {d.durationNights} night{d.durationNights>1?"s":""}</span></div><div><small>From</small><strong>{d.currency} {d.priceFrom}</strong><span>{d.cabinsLeft==null?"Check availability":d.cabinsLeft+" cabins left"}</span></div></a>)}</div>:<p>No dated departures are published yet. Submit a booking request and our team will check live availability.</p>}</section>
   <section id="included" className="detailSection"><span className="eyebrow">GOOD TO KNOW</span><h2>Typical inclusions</h2><div className="includedGrid"><div><h3>Included</h3><p>✓ Private cabin</p><p>✓ Meals stated in itinerary</p><p>✓ Onboard activities</p><p>✓ English-speaking cruise staff</p><p>✓ Sightseeing fees</p></div><div><h3>Usually not included</h3><p>– Hanoi transfers unless selected</p><p>– Drinks and personal expenses</p><p>– Spa treatments</p><p>– Tips / gratuities</p><p>– Travel insurance</p></div></div></section>
  </div>
  <aside className="bookingBox"><div className="bookingPrice"><small>From</small><strong>US$ {from}</strong><span>/ person</span></div><p>Choose your date and cabin to request live availability.</p><a className="button bookingCta" href={"/booking?cruise="+encodeURIComponent(cruise.slug)}>Check Availability</a><div className="bookingTrust"><span>✓ No booking fee</span><span>✓ Secure confirmation process</span><span>✓ Vietnam-based support</span></div></aside></div>
  <div className="mobileBooking"><div><small>From</small><b>US$ {from}</b><span>/ person</span></div><a className="button" href={"/booking?cruise="+encodeURIComponent(cruise.slug)}>Check Availability</a></div>
 </main>
}
