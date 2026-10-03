import { createHash } from "node:crypto";
import { getClientIp } from "@/lib/client-ip";
import { consumeRateLimit, type RateLimitResult } from "@/lib/rate-limit";

const digest = (value: string) => createHash("sha256").update(value).digest("hex");

export async function staffLoginThrottle(request: Request, email: string): Promise<RateLimitResult> {
  const ip = getClientIp(request);
  const account = digest(email.trim().toLowerCase());
  const address = digest(ip);
  return consumeRateLimit([
    { key: "staff-login:aggregate", limit: 80, windowMs: 60_000 },
    { key: `staff-login:ip:${address}`, limit: 30, windowMs: 5 * 60_000 },
    { key: `staff-login:account:${account}`, limit: 8, windowMs: 5 * 60_000 },
  ]);
}
