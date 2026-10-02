import { getPublicCruises } from "@/lib/public-cruises";

export const dynamic = "force-dynamic";
type Search = Record<string, string | string[] | undefined>;
const value = (input: string | string[] | undefined) => typeof input === "string" ? input : "";
const money = (amount: number, currency: string) => new Intl.NumberFormat("en-US", { style: "currency", currency }).format(amount);

export default async function CruisesPage({ searchParams }: { searchParams: Promise<Search> }) {
  const query = await searchParams;
  const search = value(query.q).trim().slice(0, 120);
  const route = value(query.route);
  const date = value(query.date);
  const nights = value(query.nights);
  const sort = value(query.sort) || "recommended";
  const maximum = Number(value(query.maxPrice));
  const maxPrice = Number.isFinite(maximum) && maximum > 0 ? maximum : null;
  let inventory: Awaited<ReturnType<typeof getPublicCruises>>;
  try { inventory = await getPublicCruises(); } catch {
    return <main className="shell notFoundCruise"><h1>Cruise inventory is temporarily unavailable</h1><p>Please try again shortly. We are not showing sample prices as live availability.</p><a className="button" href="/">Back to homepage</a></main>;
  }
  const routes = [...new Set(inventory.map(cruise => cruise.route))].sort();
  const results = inventory.map(cruise => {
    const departures = cruise.departures.filter(item => (!date || item.departureDate.toISOString().slice(0, 10) === date) && (!nights || String(item.durationNights) === nights));
    const prices = departures.map(item => item.priceFrom);
    return { ...cruise, departures, from: prices.length ? Math.min(...prices) : null };
  }).filter(cruise => (!search || `${cruise.name} ${cruise.route}`.toLowerCase().includes(search.toLowerCase())) && (!route || cruise.route === route) && (!(date || nights) || cruise.departures.length > 0) && (maxPrice === null || (cruise.from !== null && cruise.from <= maxPrice)));
  if (sort === "price") results.sort((a, b) => (a.from ?? Infinity) - (b.from ?? Infinity));
  if (sort === "rating") results.sort((a, b) => b.rating - a.rating);
  return <main>
    <section className="listingHero"><div className="shell"><span className="eyebrow">FIND YOUR BAY JOURNEY</span><h1>Choose a cruise that fits your trip</h1><p>Search published cruises and departure dates. All rates remain subject to operator confirmation.</p></div></section>
    <div className="shell listingWrap"><form className="filters" action="/cruises" method="get"><div className="filterHead"><h2>Find your cruise</h2><a href="/cruises">Reset</a></div>
      <label><span>Cruise or route</span><input name="q" defaultValue={search} maxLength={120} placeholder="Search cruises" style={{ width: "100%", padding: 10 }}/></label>
      <label><span>Route</span><select name="route" defaultValue={route}><option value="">All routes</option>{routes.map(item => <option key={item} value={item}>{item}</option>)}</select></label>
      <label><span>Departure date</span><input name="date" type="date" defaultValue={date}/></label>
      <label><span>Duration</span><select name="nights" defaultValue={nights}><option value="">Any duration</option><option value="1">2 days / 1 night</option><option value="2">3 days / 2 nights</option></select></label>
      <label><span>Maximum starting price (USD)</span><input type="number" name="maxPrice" min="1" defaultValue={maxPrice ?? ""} style={{ width: "100%", padding: 10 }}/></label>
      <label><span>Sort by</span><select name="sort" defaultValue={sort}><option value="recommended">Recommended</option><option value="price">Starting price: low to high</option><option value="rating">Rating</option></select></label>
      <button className="button" type="submit" style={{ marginTop: 20, width: "100%" }}>Apply filters</button>
    </form><section className="results" aria-label="Cruise results"><header className="resultsTop"><h2>{results.length} cruise{results.length === 1 ? "" : "s"}</h2></header>
      <div className="listingCards">{results.map(cruise => <article className="listingCard" key={cruise.id}><a className="listingImage" href={`/cruises/${encodeURIComponent(cruise.slug)}`} aria-label={`View ${cruise.name}`} style={cruise.heroImage ? { backgroundImage: `url(${JSON.stringify(cruise.heroImage)})` } : { background: "var(--navy)" }}/><div className="listingInfo"><h3><a href={`/cruises/${encodeURIComponent(cruise.slug)}`}>{cruise.name}</a></h3><p>{cruise.route}</p>{cruise.reviewCount > 0 && <p className="rating">{cruise.rating.toFixed(1)} / 5 <span>({cruise.reviewCount} reviews in inventory)</span></p>}<p>{cruise.summary}</p><p>{cruise.departures.length} published future departures · {cruise.cabins.length} cabin types</p><div className="listingBottom"><div><small>Indicative starting price</small><strong>{cruise.from === null ? "Request information" : money(cruise.from, cruise.departures[0]?.currency || "USD")}</strong></div><a className="darkButton linkButton" href={`/cruises/${encodeURIComponent(cruise.slug)}`}>View cruise</a></div></div></article>)}</div>
      {!results.length && <div className="inventoryEmpty"><h3>No cruises match this selection</h3><p>Try a different date or remove a filter.</p><a className="button" href="/cruises">Reset filters</a></div>}
    </section></div>
  </main>;
}
