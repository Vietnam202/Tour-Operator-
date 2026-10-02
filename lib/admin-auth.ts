import { timingSafeEqual } from "crypto";

export function isAdminAuthorized(request: Request) {
  const expected = process.env.ADMIN_API_KEY;
  const supplied = request.headers.get("x-admin-key");
  if (!expected || !supplied) return false;
  const a = Buffer.from(expected);
  const b = Buffer.from(supplied);
  return a.length === b.length && timingSafeEqual(a,b);
}
