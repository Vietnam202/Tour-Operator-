const cruises = [
  {
    name: "Stellar of the Seas",
    badge: "Best Seller",
    rating: "4.9",
    reviews: "328 reviews",
    route: "Halong Bay – Lan Ha Bay",
    duration: "2D1N / 3D2N",
    price: 320,
    oldPrice: 480,
    image: "https://images.unsplash.com/photo-1528127269322-539801943592?auto=format&fit=crop&w=1200&q=85",
  },
  {
    name: "La Casta Regal Cruise",
    badge: "New Cruise",
    rating: "4.8",
    reviews: "216 reviews",
    route: "Lan Ha Bay – Dark & Bright Cave",
    duration: "2D1N / 3D2N",
    price: 315,
    oldPrice: 450,
    image: "https://images.unsplash.com/photo-1559592413-7cec4d0cae2b?auto=format&fit=crop&w=1200&q=85",
  },
  {
    name: "Heritage Binh Chuan",
    badge: "Luxury Choice",
    rating: "4.9",
    reviews: "412 reviews",
    route: "Halong Bay – Lan Ha Bay",
    duration: "2D1N / 3D2N",
    price: 450,
    oldPrice: 590,
    image: "https://images.unsplash.com/photo-1504457047772-27faf1c00561?auto=format&fit=crop&w=1200&q=85",
  },
];

const routes = [
  ["Halong Bay", "Iconic limestone islands & famous landmarks", "https://images.unsplash.com/photo-1573270689103-d7a4e42b609a?auto=format&fit=crop&w=900&q=85"],
  ["Lan Ha Bay", "Quieter waters, pristine beaches and caves", "https://images.unsplash.com/photo-1528181304800-259b08848526?auto=format&fit=crop&w=900&q=85"],
  ["Bai Tu Long Bay", "Off the beaten path and more authentic", "https://images.unsplash.com/photo-1521993117367-b7f70ccd029d?auto=format&fit=crop&w=900&q=85"],
];

function Icon({ children }: { children: React.ReactNode }) {
  return <span className="icon">{children}</span>;
}

export default function Home() {
  return (
    <main>
      <header className="header shell">
        <a className="brand" href="#">
          <span className="brandMark">≋</span>
          <span><b>HALONG CRUISE<br/>ADVISOR</b><small>Your Local Cruise Expert</small></span>
        </a>
        <nav>
          <a href="/cruises">Cruises</a><a href="#destinations">Destinations</a>
          <a href="#guide">Travel Guide</a><a href="#why-us">About Us</a><a href="#contact">Contact</a>
        </nav>
        <div className="headerActions"><span>USD⌄</span><a className="button small" href="#search">Plan Your Trip</a></div>
      </header>

      <section className="hero">
        <div className="heroShade"/>
        <div className="shell heroContent">
          <div className="eyebrow light">CURATED BY VIETNAM-BASED CRUISE EXPERTS</div>
          <h1>Halong Bay Cruises.<br/>Unforgettable Journeys.</h1>
          <p>Compare handpicked luxury cruises, trusted guest reviews and the best available rates — with local experts by your side.</p>
        </div>
      </section>

      <section id="search" className="search shell">
        <label><span>Departure date</span><div>▣ <b>Select date</b></div></label>
        <label><span>Duration</span><div>◐ <b>1 Night</b></div></label>
        <label><span>Guests</span><div>● <b>2 Adults</b></div></label>
        <button className="button searchButton">⌕ &nbsp; Search Cruises</button>
      </section>

      <section className="trust shell">
        <div><Icon>◇</Icon><span><b>Best Price Guarantee</b><small>Book with confidence</small></span></div>
        <div><Icon>♙</Icon><span><b>Local Cruise Experts</b><small>Vietnam-based team</small></span></div>
        <div><Icon>♧</Icon><span><b>24/7 Trip Support</b><small>Before & during your journey</small></span></div>
        <div><Icon>☆</Icon><span><b>Verified Guest Reviews</b><small>Real travellers, real stays</small></span></div>
      </section>

      <section id="cruises" className="section shell">
        <div className="sectionHead"><div><span className="eyebrow">FEATURED CRUISES</span><h2>Most Popular Halong Bay Cruises</h2></div><a href="/cruises">View all cruises →</a></div>
        <div className="cruiseGrid">
          {cruises.map((c) => (
            <article className="cruiseCard" key={c.name}>
              <div className="photo" style={{backgroundImage:`url("${c.image}")`}}>
                <span className="badge">{c.badge}</span><button className="heart">♡</button>
                <span className="photos">▣ 32 photos</span>
              </div>
              <div className="cardBody">
                <h3>{c.name}</h3>
                <div className="rating">★ {c.rating} <span>({c.reviews})</span></div>
                <p>⌖ {c.route}</p>
                <div className="meta"><span>▣ {c.duration}</span><span>♨ All meals included</span><span>☆ 5-star</span></div>
                <div className="priceRow"><div><del>US$ {c.oldPrice}</del><strong>US$ {c.price}</strong><small>/ person</small></div><span className="save">Save {Math.round((1-c.price/c.oldPrice)*100)}%</span></div>
                <a className="darkButton linkButton homeCardButton" href="/cruises/stellar-of-the-seas">Check Availability →</a>
              </div>
            </article>
          ))}
        </div>
      </section>

      <section id="destinations" className="section shell">
        <div className="sectionHead"><div><span className="eyebrow">EXPLORE DESTINATIONS</span><h2>Choose Your Halong Bay Route</h2></div><a href="#">View all destinations →</a></div>
        <div className="routeGrid">{routes.map(([name,desc,img])=><a className="route" href="#" key={name} style={{backgroundImage:`linear-gradient(0deg,rgba(1,38,55,.86),rgba(1,38,55,.03)),url("${img}")`}}><div><h3>{name}</h3><p>{desc}</p></div><b>→</b></a>)}</div>
      </section>

      <section id="why-us" className="why">
        <div className="shell">
          <span className="eyebrow">WHY BOOK WITH US</span><h2>Your Trusted Cruise Advisor in Vietnam</h2>
          <div className="whyGrid">
            <div><Icon>♢</Icon><h3>Handpicked Cruises</h3><p>Only cruises our team would confidently recommend to international travellers.</p></div>
            <div><Icon>⌖</Icon><h3>Local Expertise</h3><p>Vietnam-based specialists with practical knowledge of routes, cabins and transfers.</p></div>
            <div><Icon>♙</Icon><h3>Tailored Advice</h3><p>Couples, families and groups get recommendations that fit their actual trip.</p></div>
            <div><Icon>✓</Icon><h3>Clear Booking Terms</h3><p>Rates, inclusions, pickup, cancellation and payment details explained before you book.</p></div>
          </div>
        </div>
      </section>

      <section className="section shell reviews">
        <span className="eyebrow">TRAVELLERS LOVE US</span><h2>What Our Guests Say</h2>
        <div className="reviewGrid">
          <blockquote><b>Sarah Johnson · United States</b><span>★★★★★</span><strong>Amazing experience!</strong><p>“The cruise, food and scenery were beyond expectations. Everything was clearly explained before we booked.”</p></blockquote>
          <blockquote><b>Mark & Lisa · Australia</b><span>★★★★★</span><strong>Highly recommended</strong><p>“Beautiful cruise, professional staff and unforgettable views. The transfer from Hanoi was easy.”</p></blockquote>
          <blockquote><b>David Chen · Singapore</b><span>★★★★★</span><strong>A must-do in Vietnam</strong><p>“Great service, comfortable cabin and a stunning itinerary. The advisor helped us choose the right route.”</p></blockquote>
        </div>
      </section>

      <section className="expertCta"><div className="shell"><div><span className="eyebrow light">NOT SURE WHICH CRUISE TO CHOOSE?</span><h2>Ask a Local Cruise Expert</h2><p>Tell us your travel dates, group size and preferences. We’ll shortlist the best options for you.</p></div><a className="button" href="mailto:hello@halongcruiseadvisor.com">Get Personal Advice →</a></div></section>

      <footer id="contact"><div className="shell footerGrid"><div className="brand footerBrand"><span className="brandMark">≋</span><span><b>HALONG CRUISE ADVISOR</b><small>Your Local Cruise Expert</small></span></div><div><b>Explore</b><a href="#cruises">Cruises</a><a href="#destinations">Destinations</a><a href="#guide">Travel Guide</a></div><div><b>Booking help</b><a href="#">Transfers from Hanoi</a><a href="#">Cancellation policy</a><a href="#">Payment & security</a></div><div><b>Contact</b><p>Hanoi, Vietnam</p><p>hello@halongcruiseadvisor.com</p><p>24/7 guest support</p></div></div><div className="shell copyright">© 2026 Halong Cruise Advisor. All rights reserved. <span>Terms · Privacy · Cookies</span></div></footer>
      <nav className="mobileNav"><a>⌂<span>Home</span></a><a>♨<span>Cruises</span></a><a>⌖<span>Destinations</span></a><a>▤<span>Guide</span></a><a>•••<span>More</span></a></nav>
    </main>
  );
}
