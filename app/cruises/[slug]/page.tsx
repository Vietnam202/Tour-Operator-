import { notFound } from "next/navigation";
import { getPublicCruise } from "@/lib/public-cruises";

export const dynamic = "force-dynamic";
const money = (amount: number, currency: string) => new Intl.NumberFormat("en-US", { style: "currency", currency }).format(amount);

export default async function CruiseDetail({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  let cruise: Awaited<ReturnType<typeof getPublicCruise>>;
  try { cruise = await getPublicCruise(slug); } catch {
    return <main className="shell notFoundCruise"><h1>Cruise details are temporarily unavailable</h1><p>Please try again shortly. No availability or price has been confirmed.</p><a className="button" href="/cruises">Browse cruises</a></main>;
  }
  if (!cruise) notFound();
  const available = cruise.cabins.length > 0 && cruise.departures.length > 0;
  const bookUrl = `/booking?cruise=${encodeURIComponent(cruise.slug)}`;
  const hero = cruise.heroImage;
  return <main>
    <nav className="shell detailNav" aria-label="Breadcrumb"><a href="/">Home</a><span>›</span><a href="/cruises">Cruises</a><span>› {cruise.name}</span></nav>
    <header className="shell detailHeader"><div>{cruise.badge && <span className="badge staticBadge">{cruise.badge}</span>}<h1>{cruise.name}</h1><p>{cruise.route}</p>{cruise.reviewCount > 0 && <p className="rating">{cruise.rating.toFixed(1)} / 5 <span>({cruise.reviewCount} reviews supplied in inventory)</span></p>}</div></header>
    {hero && <section className="shell gallery" aria-label="Cruise gallery"><div className="galleryMain" role="img" aria-label={cruise.name} style={{ backgroundImage: `url(${JSON.stringify(hero)})` }}/><div className="gallerySide one" role="img" aria-label="Cabin preview" style={{ backgroundImage: `url(${JSON.stringify(cruise.cabins[0]?.image || hero)})` }}/><div className="gallerySide two" role="img" aria-label="Cabin preview" style={{ backgroundImage: `url(${JSON.stringify(cruise.cabins[1]?.image || hero)})` }}/></section>}
    <nav className="anchorNav" aria-label="Cruise sections"><div className="shell"><a href="#overview">Overview</a><a href="#cabins">Cabins</a><a href="#departures">Departures</a><a href="#booking-terms">Booking information</a></div></nav>
    <div className="shell detailLayout"><div className="detailContent">
      <section id="overview" className="detailSection"><span className="eyebrow">CRUISE OVERVIEW</span><h2>Explore {cruise.name}</h2><p>{cruise.summary || "Contact our team for the latest itinerary and operator information."}</p><div className="highlights"><div><b>{cruise.cabins.length}</b><span>Published cabin types</span></div><div><b>{cruise.departures.length}</b><span>Published future departures</span></div></div></section>
      <section id="cabins" className="detailSection"><span className="eyebrow">CABIN OPTIONS</span><h2>Choose your cabin</h2><p>Starting prices are indicative. Final cabin-specific rates and occupancy rules are confirmed by the operator.</p><div className="cabinList">{cruise.cabins.map(cabin => <article className="cabin" key={cabin.id}>{(cabin.image || hero) && <div className="cabinImg" role="img" aria-label={cabin.name} style={{ backgroundImage: `url(${JSON.stringify(cabin.image || hero)})` }}/>}<div className="cabinInfo"><h3>{cabin.name}</h3><p>{cabin.sizeSqm ? `${cabin.sizeSqm} m² · ` : ""}Up to {cabin.capacity} guests</p><p>{cabin.description}</p></div><div className="cabinPrice"><small>Indicative starting price</small><strong>{money(cabin.basePrice, cabin.currency)}</strong><span>per person</span>{available && <a className="darkButton linkButton" href={`${bookUrl}&cabin=${encodeURIComponent(cabin.id)}`}>Choose departure</a>}</div></article>)}</div>{!cruise.cabins.length && <p>No cabins are currently published.</p>}</section>
      <section id="departures" className="detailSection"><span className="eyebrow">PUBLISHED DEPARTURES</span><h2>Select your travel date</h2>{available ? <div className="departureGrid">{cruise.departures.map(departure => <a className="departureCard" key={departure.id} href={`${bookUrl}&departure=${encodeURIComponent(departure.id)}`}><div><b>{departure.departureDate.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" })}</b><span>{departure.durationNights + 1} days / {departure.durationNights} nights</span></div><div><small>Starting from</small><strong>{money(departure.priceFrom, departure.currency)}</strong><span>Operator confirmation required</span></div></a>)}</div> : <p>No online booking options are currently published for this cruise. Please check again or <a href="/cruises">browse other cruises</a>.</p>}</section>
      <section id="booking-terms" className="detailSection"><h2>Before requesting your cruise</h2><p>Cabin-specific rates, itinerary, meals, activities, transfers and cancellation terms must be confirmed in your final offer. A request does not reserve inventory or charge a payment.</p></section>
    </div><aside className="bookingBox"><h2>Plan your cruise</h2><p>Choose a cabin and published departure to receive an indicative estimate.</p>{available ? <a className="button bookingCta" href={bookUrl}>Request availability</a> : <a className="button bookingCta" href="/cruises">Browse other cruises</a>}<p>No payment at the enquiry stage.</p></aside></div>
    {available && <div className="mobileBooking"><span>Operator confirmation required</span><a className="button" href={bookUrl}>Request availability</a></div>}
  </main>;
}
