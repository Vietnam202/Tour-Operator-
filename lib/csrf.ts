export function sameOrigin(request: Request) {
  const origin = request.headers.get("origin");
  const host = request.headers.get("host");
  if (!origin || !host) return true;

  try {
    const url = new URL(origin);
    return url.host === host;
  } catch {
    return false;
  }
}
