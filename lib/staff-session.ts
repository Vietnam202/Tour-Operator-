export const STAFF_COOKIE_NAME = "hca_staff_session";
export const STAFF_SESSION_SECONDS = 12 * 60 * 60;

export function readStaffCookie(request: Request): { present: boolean; token: string | null } {
  const raw = request.headers.get("cookie") || "";
  if (raw.length > 8192) return { present: true, token: null };
  const matches = raw.split(";").map(part => part.trim()).filter(part =>
    part === STAFF_COOKIE_NAME || part.startsWith(STAFF_COOKIE_NAME + "="));
  if (!matches.length) return { present: false, token: null };
  // Ambiguous duplicate cookies must not choose an arbitrary authenticated user.
  if (matches.length !== 1) return { present: true, token: null };
  try {
    const token = decodeURIComponent(matches[0].slice(STAFF_COOKIE_NAME.length + 1));
    return { present: true, token: /^[a-f0-9]{64}$/.test(token) ? token : null };
  } catch {
    return { present: true, token: null };
  }
}

export function staffCookieOptions(maxAge = STAFF_SESSION_SECONDS) {
  return { httpOnly: true, secure: process.env.NODE_ENV === "production",
    sameSite: "lax" as const, path: "/", maxAge };
}
