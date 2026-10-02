import { NextResponse } from "next/server";
import { sameOrigin } from "@/lib/csrf";

export const staffResponseHeaders = {
  "Cache-Control": "private, no-store, max-age=0",
  "Referrer-Policy": "no-referrer",
  "X-Robots-Tag": "noindex, nofollow, noarchive",
  "Vary": "Cookie, Origin, Sec-Fetch-Site",
};
export class StaffHttpError extends Error {
  constructor(message: string, public readonly status: number) { super(message); this.name = "StaffHttpError"; }
}
export function requireStaffOrigin(request: Request): void {
  if (!sameOrigin(request)) throw new StaffHttpError("Invalid request origin.", 403);
}
export function staffHttpError(error: unknown) {
  if (error instanceof StaffHttpError) return NextResponse.json({ error: error.message }, { status: error.status, headers: staffResponseHeaders });
  return NextResponse.json({ error: "Staff service is temporarily unavailable. Please try again." }, { status: 503, headers: staffResponseHeaders });
}

// Limit the stream as well as Content-Length; do not allocate an unbounded body.
export async function readStaffJson(request: Request): Promise<Record<string, unknown>> {
  if (request.headers.get("content-type")?.split(";")[0].trim().toLowerCase() !== "application/json") {
    throw new StaffHttpError("Content-Type must be application/json.", 415);
  }
  const limit = 8192;
  const length = request.headers.get("content-length");
  if (length !== null && (!/^\d+$/.test(length) || Number(length) > limit)) {
    throw new StaffHttpError("Request body is too large or has an invalid length.", 413);
  }
  const reader = request.body?.getReader();
  if (!reader) throw new StaffHttpError("A JSON object is required.", 400);
  const chunks: Uint8Array[] = [];
  let size = 0;
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.byteLength;
      if (size > limit) {
        await reader.cancel();
        throw new StaffHttpError("Request body is too large.", 413);
      }
      chunks.push(value);
    }
  } finally { reader.releaseLock(); }
  let value: unknown;
  try { value = JSON.parse(Buffer.concat(chunks).toString("utf8")); }
  catch { throw new StaffHttpError("Invalid JSON request.", 400); }
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new StaffHttpError("A JSON object is required.", 400);
  return value as Record<string, unknown>;
}
