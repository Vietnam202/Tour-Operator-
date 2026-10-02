import { isTrustedStaffOrigin } from "@/lib/trusted-staff-origin";

// Compatibility entry point for ALL existing staff mutations. Missing Origin is
// denied, and Host/Forwarded headers never add trusted origins. Webhooks do not
// use this browser-only guard and still require their own provider verification.
export function sameOrigin(request: Request): boolean {
  return isTrustedStaffOrigin(request);
}
