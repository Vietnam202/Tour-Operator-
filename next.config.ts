import type { NextConfig } from "next";

const securityHeaders = [
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
  { key: "Cross-Origin-Opener-Policy", value: "same-origin" },
];
const privateHeaders = [
  { key: "Referrer-Policy", value: "no-referrer" },
  { key: "X-Robots-Tag", value: "noindex, nofollow, noarchive, nosnippet" },
];
const nextConfig: NextConfig = {
  poweredByHeader: false,
  async headers() {
    return [
      { source: "/(.*)", headers: securityHeaders },
      { source: "/voucher/:path*", headers: privateHeaders },
      { source: "/api/voucher/:path*", headers: privateHeaders },
      { source: "/admin/:path*", headers: privateHeaders },
      { source: "/pay/:path*", headers: privateHeaders },
    ];
  },
};
export default nextConfig;
