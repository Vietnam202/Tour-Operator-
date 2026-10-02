"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import styles from "./security.module.css";

type StaffUser = { id: string; name: string; email: string; role: string };
type Session = { id: string; createdAt: string; expiresAt: string; current: boolean };
type Scope = "others" | "all";

export default function AccountSecurityPage() {
  const router = useRouter();
  const [user, setUser] = useState<StaffUser | null>(null);
  const [sessions, setSessions] = useState<Session[]>([]);
  const [loading, setLoading] = useState(true);
  const [pending, setPending] = useState(false);
  const [confirmation, setConfirmation] = useState<Scope | null>(null);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const load = useCallback(async (signal?: AbortSignal) => {
    setLoading(true); setError("");
    try {
      const [me, active] = await Promise.all([
        fetch("/api/admin/auth/me", { cache: "no-store", signal }),
        fetch("/api/admin/auth/sessions", { cache: "no-store", signal }),
      ]);
      if (me.status === 401 || active.status === 401) {
        setUser(null); setSessions([]); router.replace("/admin/login"); return;
      }
      if (!me.ok || !active.ok) throw new Error("Unable to load account security. Please refresh and try again.");
      const profile: { user: StaffUser } = await me.json();
      const result: { sessions: Session[] } = await active.json();
      if (!signal?.aborted) { setUser(profile.user); setSessions(result.sessions); }
    } catch (reason) {
      if (!signal?.aborted) setError(reason instanceof Error ? reason.message : "Unable to load your sessions.");
    } finally { if (!signal?.aborted) setLoading(false); }
  }, [router]);

  useEffect(() => {
    const controller = new AbortController();
    void load(controller.signal);
    return () => controller.abort();
  }, [load]);

  async function revoke(scope: Scope) {
    setPending(true); setError(""); setMessage("");
    try {
      const response = await fetch("/api/admin/auth/sessions", {
        method: "DELETE", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ scope }),
      });
      if (response.status === 401) { router.replace("/admin/login"); return; }
      const result: { revoked?: number; signedOut?: boolean; error?: string } = await response.json();
      if (!response.ok) throw new Error(result.error || "Sessions could not be revoked. Please try again.");
      setConfirmation(null);
      if (result.signedOut) { router.replace("/admin/login"); router.refresh(); return; }
      setMessage("Other sessions were signed out. This session remains active.");
      await load();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Unable to revoke sessions."); }
    finally { setPending(false); }
  }

  const date = (value: string) => new Date(value).toLocaleString("en-GB", { dateStyle: "medium", timeStyle: "short" });
  const otherCount = sessions.filter(item => !item.current).length;
  return <main className={styles.page}><div className={styles.content}>
    <header className={styles.header}>
      <span className="eyebrow">STAFF ACCOUNT</span><h1>Account security</h1>
      <p>Review your active sessions and sign out other browsers. Changes affect only your own account, not other staff members.</p>
    </header>
    {error && <div className={styles.error} role="alert">{error}</div>}
    {message && <div className={styles.success} role="status">{message}</div>}
    {loading && !user && <p role="status">Checking your staff session...</p>}
    {user && <>
      <section className={styles.card} aria-labelledby="account-heading">
        <h2 id="account-heading">{user.name}</h2><p>{user.email}<br /><strong>{user.role}</strong></p>
        <p className={styles.muted}>Sessions expire 12 hours after sign-in. This view shows issue and expiry times; device names, locations and last activity are not tracked.</p>
      </section>
      <section className={styles.card} aria-labelledby="session-heading" aria-busy={loading || pending}>
        <h2 id="session-heading">Active sessions ({sessions.length})</h2>
        <p className={styles.muted}>Times use this browser's local timezone. Refresh to check for new sessions.</p>
        <div className={styles.controls}>
          <button type="button" className={styles.button} disabled={loading || pending} onClick={() => { void load(); }}>Refresh sessions</button>
          <button type="button" className={`${styles.button} ${styles.primary}`} disabled={loading || pending || otherCount === 0} onClick={() => setConfirmation("others")}>Sign out other sessions ({otherCount})</button>
          <button type="button" className={`${styles.button} ${styles.danger}`} disabled={loading || pending} onClick={() => setConfirmation("all")}>Sign out everywhere</button>
        </div>
        {confirmation && <section className={styles.confirm} role="alert" aria-labelledby="confirm-signout">
          <h3 id="confirm-signout">{confirmation === "all" ? "Sign out of this session too?" : "Sign out of all other sessions?"}</h3>
          <p>{confirmation === "all" ? "All currently issued sessions for your account will be revoked. You will return to the staff sign-in page." : "Other browsers using your account will need to sign in again. Your current session stays active."}</p>
          <div className={styles.controls}>
            <button type="button" className={`${styles.button} ${styles.danger}`} disabled={pending} onClick={() => { void revoke(confirmation); }}>{pending ? "Signing out..." : "Confirm sign out"}</button>
            <button type="button" className={styles.button} disabled={pending} onClick={() => setConfirmation(null)}>Cancel</button>
          </div>
        </section>}
        <ul className={styles.sessions}>{sessions.map((item, index) => <li className={styles.session} key={item.id}>
          <strong>{item.current ? "This session" : `Other session ${index + 1}`}</strong>
          <dl><dt>Signed in</dt><dd>{date(item.createdAt)}</dd><dt>Expires</dt><dd>{date(item.expiresAt)}</dd></dl>
        </li>)}</ul>
        {!sessions.length && !loading && <p>No active sessions were returned. Please sign in again.</p>}
      </section>
      <section className={styles.card} aria-labelledby="help-heading">
        <h2 id="help-heading">An unfamiliar session?</h2>
        <p>Sign out other sessions and contact your administrator to review account access and arrange a password reset. Revoking sessions does not change your password or prevent a new sign-in with valid credentials.</p>
      </section>
    </>}
  </div></main>;
}
