import { createHash } from "node:crypto";

type Bucket = { count: number; resetAt: number };
const accounts = new Map<string, Bucket>();
let aggregate: Bucket = { count: 0, resetAt: 0 };

// Per-process safeguards, NOT a distributed lockout. Do not trust user-supplied
// X-Forwarded-For/X-Real-IP for sign-in limits. A shared edge/KV limiter is still
// required for production multi-instance deployments.
export function staffLoginThrottle(email: string, now = Date.now()): { allowed: boolean; retryAfter: number } {
  if (aggregate.resetAt <= now) aggregate = { count: 0, resetAt: now + 60000 };
  if (aggregate.count >= 80) return { allowed: false, retryAfter: Math.max(1, Math.ceil((aggregate.resetAt - now) / 1000)) };
  aggregate.count += 1;
  for (const [key, bucket] of accounts) if (bucket.resetAt <= now) accounts.delete(key);
  const key = createHash("sha256").update(email.trim().toLowerCase()).digest("hex");
  let bucket = accounts.get(key);
  if (!bucket) {
    if (accounts.size >= 1000) return { allowed: false, retryAfter: 60 };
    bucket = { count: 0, resetAt: now + 5 * 60000 };
    accounts.set(key, bucket);
  }
  if (bucket.count >= 8) return { allowed: false, retryAfter: Math.max(1, Math.ceil((bucket.resetAt - now) / 1000)) };
  bucket.count += 1;
  return { allowed: true, retryAfter: 0 };
}
