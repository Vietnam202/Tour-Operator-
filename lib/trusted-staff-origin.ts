type OriginConfiguration = { siteUrl?: string; additionalOrigins?: string };

function parseConfiguredOrigin(value: string): string | null {
  try {
    const url = new URL(value);
    const loopback = ["localhost", "127.0.0.1", "[::1]"].includes(url.hostname);
    if (url.protocol !== "https:" && !(url.protocol === "http:" && loopback)) return null;
    if (url.username || url.password || url.search || url.hash || url.pathname !== "/") return null;
    return url.origin;
  } catch { return null; }
}

// Use explicitly configured browser-facing origins, not a framework's internal URL,
// Host or Forwarded headers. Reverse proxies may normalize the request URL differently.
export function isTrustedStaffOrigin(request: Request, configuration: OriginConfiguration = {
  siteUrl: process.env.NEXT_PUBLIC_SITE_URL,
  additionalOrigins: process.env.ADDITIONAL_STAFF_ORIGINS,
}): boolean {
  const origin = request.headers.get("origin");
  if (!origin || origin === "null" || request.headers.get("sec-fetch-site") === "cross-site") return false;
  const parsed = parseConfiguredOrigin(origin);
  // Browser Origin values are a serialized origin, never a URL path or a list.
  if (parsed !== origin) return false;
  const values = [configuration.siteUrl || "", ...(configuration.additionalOrigins || "").split(",")];
  const trusted = new Set(values.map(value => parseConfiguredOrigin(value.trim())).filter((value): value is string => value !== null));
  return trusted.has(origin);
}
