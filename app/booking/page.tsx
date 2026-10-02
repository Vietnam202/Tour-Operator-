"use client";

import { FormEvent, Suspense, useEffect, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import type { PublicQuote } from "@/lib/public-quote";

type Cabin = { id: string; name: string; capacity: number; basePrice: number; currency: string };
type Departure = { id: string; departureDate: string; durationNights: number; priceFrom: number; currency: string };
type Cruise = { id: string; slug: string; name: string; cabins: Cabin[]; departures: Departure[] };
type QuoteState = { key: string; quote: PublicQuote };
type Receipt = { reference: string; quote: PublicQuote };
const money = (amount: number, currency = "USD") => new Intl.NumberFormat("en-US", { style: "currency", currency }).format(amount);
const dateLabel = (value: string) => new Date(value).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" });

function BookingContent() {
  const params = useSearchParams();
  const slug = params.get("cruise") || "stellar-of-the-seas";
  const requestedCabin = params.get("cabin") || "";
  const requestedDeparture = params.get("departure") || "";
  const [cruise, setCruise] = useState<Cruise | null>(null);
  const [cabinId, setCabinId] = useState("");
  const [departureId, setDepartureId] = useState("");
  const [adults, setAdults] = useState(2);
  const [children, setChildren] = useState(0);
  const [transfer, setTransfer] = useState("none");
  const [loading, setLoading] = useState(true);
  const [inventoryError, setInventoryError] = useState("");
  const [quoteState, setQuoteState] = useState<QuoteState | null>(null);
  const [quoteError, setQuoteError] = useState("");
  const [submitError, setSubmitError] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [receipt, setReceipt] = useState<Receipt | null>(null);
  const submissionLock = useRef(false);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true); setCruise(null); setQuoteState(null); setReceipt(null);
    setInventoryError(""); setSubmitError(""); setCabinId(""); setDepartureId("");
    void (async () => {
      try {
        const response = await fetch(`/api/booking-context?cruise=${encodeURIComponent(slug)}`, { signal: controller.signal, cache: "no-store" });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || "Unable to load this cruise.");
        if (controller.signal.aborted) return;
        const next = data.cruise as Cruise;
        setCruise(next);
        const validCabin = next.cabins.find(item => item.id === requestedCabin);
        const validDeparture = next.departures.find(item => item.id === requestedDeparture);
        setCabinId(validCabin?.id || (!requestedCabin ? next.cabins[0]?.id || "" : ""));
        setDepartureId(validDeparture?.id || "");
        if ((requestedCabin && !validCabin) || (requestedDeparture && !validDeparture)) {
          setInventoryError("Your previous selection is no longer available. Please choose a cabin and departure below.");
        }
      } catch (error) {
        if (!controller.signal.aborted) setInventoryError(error instanceof Error ? error.message : "Unable to load cruise options.");
      } finally {
        if (!controller.signal.aborted) setLoading(false);
      }
    })();
    return () => controller.abort();
  }, [slug, requestedCabin, requestedDeparture]);

  const cabin = cruise?.cabins.find(item => item.id === cabinId);
  const departure = cruise?.departures.find(item => item.id === departureId);
  const quoteKey = cruise && cabin && departure ? JSON.stringify({
    cruiseId: cruise.id, cabinId: cabin.id, departureId: departure.id,
    departureDate: departure.departureDate.slice(0, 10), durationNights: departure.durationNights,
    adults, children, transferType: transfer,
  }) : "";
  // Invalidate synchronously on any selection change, before the next effect runs.
  const quote = quoteState?.key === quoteKey ? quoteState.quote : null;

  useEffect(() => {
    if (!quoteKey || receipt) return;
    const controller = new AbortController();
    setQuoteState(null); setQuoteError("");
    const timer = setTimeout(() => {
      void (async () => {
        try {
          const response = await fetch("/api/quote", { method: "POST", headers: { "Content-Type": "application/json" }, body: quoteKey, signal: controller.signal });
          const data = await response.json();
          if (!response.ok) throw new Error(data.error || "Unable to calculate this quote.");
          if (!controller.signal.aborted) setQuoteState({ key: quoteKey, quote: data.quote });
        } catch (error) {
          if (!controller.signal.aborted) setQuoteError(error instanceof Error ? error.message : "Unable to calculate this quote.");
        }
      })();
    }, 250);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [quoteKey, receipt]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submissionLock.current || !quote || !quoteKey) return;
    const form = new FormData(event.currentTarget);
    submissionLock.current = true; setSubmitting(true); setSubmitError("");
    try {
      const response = await fetch("/api/bookings", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ...JSON.parse(quoteKey), primaryGuest: form.get("primaryGuest"),
          email: form.get("email"), phone: form.get("phone"), nationality: form.get("nationality"),
          specialRequests: form.get("specialRequests"), consent: form.get("consent") === "on" }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Unable to save your request.");
      setReceipt({ reference: data.reference, quote: data.quote });
      window.scrollTo({ top: 0, behavior: "smooth" });
    } catch (error) {
      setSubmitError(error instanceof Error ? error.message : "Unable to save your request.");
    } finally { submissionLock.current = false; setSubmitting(false); }
  }

  if (receipt) return <main className="bookingSuccess"><section className="shell successCard">
    <div className="successIcon" aria-hidden="true">✓</div><span className="eyebrow">REQUEST SAVED</span>
    <h1>Thank you. Your cruise request is with our team.</h1>
    <p>{receipt.quote.cruise.name} · {receipt.quote.cabin.name} · {dateLabel(receipt.quote.departure.departureDate)}</p>
    <p>This is an availability request, not a confirmed reservation. No payment has been taken.</p>
    <div className="successMeta"><div><b>Reference</b><span>{receipt.reference}</span></div><div><b>Server-calculated estimate</b><span>{money(receipt.quote.total, receipt.quote.currency)}</span></div><div><b>Next step</b><span>Operator confirmation</span></div></div>
    <a className="button" href="/cruises">Explore cruises</a>
  </section></main>;

  return <main className="checkoutPage">
    <nav className="shell detailNav" aria-label="Breadcrumb"><a href="/">Home</a><span>›</span><a href="/cruises">Cruises</a><span>›</span><a href={`/cruises/${encodeURIComponent(slug)}`}>{cruise?.name || "Selected cruise"}</a><span>› Booking request</span></nav>
    <header className="shell checkoutHeader"><span className="eyebrow">PLAN YOUR BAY JOURNEY</span><h1>Your cruise, your travel details</h1><p>Choose a published departure and cabin. We will confirm availability and final pricing before payment.</p></header>
    <div className="shell checkoutLayout">
      <form className="checkoutForm" onSubmit={submit}>
        {inventoryError && <div className="bookingError" role="alert">{inventoryError}</div>}
        <fieldset disabled={submitting || loading} style={{ border: 0, margin: 0, padding: 0, minWidth: 0, display: "grid", gap: 16 }}>
          <section className="checkoutSection"><div className="stepTitle"><span>1</span><div><h2>Trip & cabin</h2><p>Dates and durations come from published departures.</p></div></div>
            {loading ? <p role="status">Loading cruise options…</p> : <div className="formGrid">
              <label><span>Cabin</span><select required value={cabinId} onChange={event => setCabinId(event.target.value)}><option value="">Select a cabin</option>{cruise?.cabins.map(item => <option key={item.id} value={item.id}>{item.name} · up to {item.capacity} guests</option>)}</select></label>
              <label><span>Departure</span><select required value={departureId} onChange={event => setDepartureId(event.target.value)}><option value="">Select a departure</option>{cruise?.departures.map(item => <option key={item.id} value={item.id}>{dateLabel(item.departureDate)} · {item.durationNights + 1} days / {item.durationNights} nights</option>)}</select></label>
            </div>}
            {!loading && cruise && (!cruise.cabins.length || !cruise.departures.length) && <p role="status">There are no published online booking options for this cruise right now. <a href="/cruises">Browse other cruises</a>.</p>}
          </section>
          <section className="checkoutSection"><div className="stepTitle"><span>2</span><div><h2>Your travelling party</h2><p>One cabin per online request. Contact our team for groups or special child arrangements.</p></div></div>
            <div className="formGrid"><label><span>Adults</span><input type="number" min={1} max={12} required value={adults} onChange={event => setAdults(Number(event.target.value))}/></label><label><span>Children</span><input type="number" min={0} max={12} required value={children} onChange={event => setChildren(Number(event.target.value))}/></label></div>
          </section>
          <section className="checkoutSection"><div className="stepTitle"><span>3</span><div><h2>Hanoi transfer preference</h2><p>Transfer pricing is indicative and subject to operator confirmation.</p></div></div>
            <label><span>Transfer</span><select value={transfer} onChange={event => setTransfer(event.target.value)}><option value="none">No transfer</option><option value="shared">Shared limousine — request a quote</option><option value="private">Private car — request a quote</option></select></label>
          </section>
          <section className="checkoutSection"><div className="stepTitle"><span>4</span><div><h2>How can we contact you?</h2><p>We only need contact details at this stage. Do not enter passport numbers or card details.</p></div></div>
            <div className="formGrid"><label><span>Lead guest full name</span><input name="primaryGuest" required maxLength={120} autoComplete="name"/></label><label><span>Nationality (optional)</span><input name="nationality" maxLength={80}/></label><label><span>Email</span><input name="email" type="email" required maxLength={200} autoComplete="email"/></label><label><span>WhatsApp / phone (optional)</span><input name="phone" type="tel" maxLength={40} autoComplete="tel" placeholder="Include country code"/></label></div>
            <label className="fullLabel"><span>Special requests (optional)</span><textarea name="specialRequests" maxLength={1200} placeholder="Dietary preferences, child ages or transfer questions"/></label>
          </section>
          <section className="checkoutSection"><div className="stepTitle"><span>5</span><div><h2>Review & send</h2><p>Cancellation terms and the final offer will be confirmed before payment.</p></div></div>
            <label className="terms"><input name="consent" required type="checkbox"/><span>I understand this is an availability request and agree to be contacted about this booking.</span></label>
            {submitError && <div className="bookingError" role="alert">{submitError}</div>}
            <button className="button checkoutSubmit" type="submit" disabled={!quote || submitting}>{submitting ? "Saving your request…" : "Send booking request"}</button>
          </section>
        </fieldset>
      </form>
      <aside className="summaryCard" aria-label="Booking estimate"><div className="summaryBody"><span className="eyebrow">YOUR CRUISE REQUEST</span><h2>{cruise?.name || "Choose your cruise"}</h2><p>{cabin?.name || "Select a cabin"}</p><p>{departure ? `${dateLabel(departure.departureDate)} · ${departure.durationNights + 1} days / ${departure.durationNights} nights` : "Select a published departure"}</p>
        <div aria-live="polite" aria-atomic="true">
          {quote ? <><div className="summaryRows"><div><span>Adults × {quote.adults}</span><b>{money(quote.adults * quote.adultRate, quote.currency)}</b></div>{quote.children > 0 && <div><span>Children × {quote.children}</span><b>{money(quote.children * quote.childRate, quote.currency)}</b></div>}<div><span>Single supplement</span><b>{money(quote.singleSupplement, quote.currency)}</b></div><div><span>Departure surcharge</span><b>{money(quote.holidaySurcharge, quote.currency)}</b></div><div><span>Transfer estimate</span><b>{money(quote.transfer, quote.currency)}</b></div></div><div className="summaryTotal"><span>Estimated total</span><strong>{money(quote.total, quote.currency)}</strong></div></> : <p>{quoteKey ? quoteError || "Calculating your current selection…" : "Select a cabin and departure to see a server-calculated estimate."}</p>}
        </div><small>Indicative estimate, not a guaranteed cabin-specific fare. Availability, child policy, transfer and final cabin/date rate require operator confirmation. No payment is taken here.</small>
      </div></aside>
    </div>
  </main>;
}

export default function BookingPage() {
  return <Suspense fallback={<main className="checkoutPage"><div className="shell" role="status">Loading booking options…</div></main>}><BookingContent/></Suspense>;
}
