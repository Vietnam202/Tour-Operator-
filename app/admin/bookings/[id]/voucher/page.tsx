"use client";
import { useCallback, useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";

type Grant = { id: string; createdAt: string; expiresAt: string; revokedAt: string | null };
type Details = { reference: string; canManage: boolean; ready: boolean; blockedReason: string | null; grants: Grant[] };

export default function VoucherManagement() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const [data, setData] = useState<Details | null>(null);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [link, setLink] = useState("");
  const [busy, setBusy] = useState(false);
  const endpoint = "/api/admin/bookings/" + encodeURIComponent(id) + "/voucher";
  const load = useCallback(async () => {
    const response = await fetch(endpoint, { cache: "no-store" });
    if (response.status === 401) { router.replace("/admin/login"); return; }
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || "Unable to load voucher settings.");
    setData(result);
  }, [endpoint, router]);
  useEffect(() => { load().catch(reason => setError(reason instanceof Error ? reason.message : "Unable to load.")); }, [load]);
  async function mutate(method: "POST" | "DELETE") {
    if (method === "DELETE" && !window.confirm("Revoke all customer voucher links for this booking?")) return;
    if (method === "POST" && !window.confirm("Issue a new 72-hour link? All previous customer links will stop working.")) return;
    setBusy(true); setError(""); setMessage(""); setLink("");
    try {
      const response = await fetch(endpoint, { method, cache: "no-store" });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "Unable to update voucher links.");
      if (method === "POST") { setLink(result.voucherUrl); setMessage("Private link created. Share it only with the verified guest; it is shown here once."); }
      else setMessage("Customer voucher links revoked.");
      await load();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Unable to update."); }
    finally { setBusy(false); }
  }
  return <main className="adminPage"><div className="shell adminWrap">
    <nav className="adminNav"><a href={"/admin/bookings/" + id}>Back to booking</a><a href="/admin/operations">Today</a></nav>
    <div className="adminTitle"><span className="eyebrow">PRIVATE CUSTOMER ACCESS</span><h1>Travel voucher links</h1>
      <p>{data?.reference || "Loading booking..."}</p></div>
    {error && <p className="adminError" role="alert">{error}</p>}
    {message && <p className="adminSuccess" role="status">{message}</p>}
    {data && <section className="inventoryPanel">
      <p>Links expire after 72 hours or shortly after the trip ends, whichever comes first. Creating a new link revokes all previous links.</p>
      {!data.ready && <p className="adminError">{data.blockedReason}</p>}
      {data.canManage ? <div className="miniGrid">
        <button className="darkButton" disabled={busy || !data.ready} onClick={() => mutate("POST")}>Issue / replace private link</button>
        <button className="darkButton" disabled={busy} onClick={() => mutate("DELETE")}>Revoke customer links</button>
      </div> : <p>Your role has read-only access.</p>}
      {link && <div className="inventoryForm" style={{ marginTop: 20 }}><label><span>Private link — copy before leaving this page</span>
        <input readOnly value={link} onFocus={event => event.currentTarget.select()} /></label>
        <button className="darkButton" onClick={async () => {
          try { await navigator.clipboard.writeText(link); setMessage("Private link copied."); }
          catch { setMessage("Clipboard unavailable. Select the link and copy it manually."); }
        }}>Copy private link</button></div>}
      <h2>Recent issued links</h2><p>Secrets cannot be recovered from this history.</p>
      <div className="inventoryItems">{data.grants.map(grant => <div className="inventoryItem" key={grant.id}>
        <b>{grant.revokedAt ? "Revoked" : new Date(grant.expiresAt).getTime() <= Date.now() ? "Expired" : "Active"}</b>
        <span>Issued {new Date(grant.createdAt).toLocaleString("en-US")} · Expires {new Date(grant.expiresAt).toLocaleString("en-US")}</span>
      </div>)}{!data.grants.length && <p>No private voucher links have been issued.</p>}</div>
    </section>}
  </div></main>;
}
