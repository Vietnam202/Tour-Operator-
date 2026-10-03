import { isIP } from "node:net";

export class ClientIpConfigurationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "ClientIpConfigurationError";
  }
}

function trustedProxyHops(): number | null {
  const raw = process.env.TRUSTED_PROXY_HOPS?.trim();
  if (!raw) {
    if (process.env.NODE_ENV === "production") {
      throw new ClientIpConfigurationError("TRUSTED_PROXY_HOPS must be configured in production.");
    }
    return null;
  }
  if (!/^\d+$/.test(raw) || Number(raw) < 1 || Number(raw) > 16) {
    throw new ClientIpConfigurationError("TRUSTED_PROXY_HOPS must be an integer from 1 to 16.");
  }
  return Number(raw);
}

function parseForwardedFor(value: string): string[] {
  const entries = value.split(",").map(item => item.trim()).filter(Boolean);
  if (!entries.length || entries.some(item => isIP(item) === 0)) {
    throw new ClientIpConfigurationError("X-Forwarded-For contains an invalid IP chain.");
  }
  return entries;
}

/**
 * Resolve the client address without trusting the left-most value supplied by a caller.
 *
 * Production requires TRUSTED_PROXY_HOPS=N and must only expose the app through a
 * trusted proxy chain that appends one address per hop to X-Forwarded-For. We walk
 * from the right-hand side of that chain; arbitrary values prepended by a client do
 * not become the rate-limit identity.
 *
 * Local/test may omit TRUSTED_PROXY_HOPS. In that mode proxy headers are ignored and
 * all direct traffic shares the "local" identity unless X-Real-IP is explicitly set
 * by the local test harness.
 */
export function getClientIp(request: Request): string {
  const hops = trustedProxyHops();
  if (hops === null) {
    const local = request.headers.get("x-real-ip")?.trim();
    return local && isIP(local) ? local : "local";
  }

  const forwarded = request.headers.get("x-forwarded-for");
  if (!forwarded) throw new ClientIpConfigurationError("Trusted proxy request is missing X-Forwarded-For.");
  const chain = parseForwardedFor(forwarded);
  if (chain.length < hops) {
    throw new ClientIpConfigurationError("X-Forwarded-For is shorter than TRUSTED_PROXY_HOPS.");
  }
  return chain[chain.length - hops];
}
