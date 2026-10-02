import type { Metadata } from "next";
import type { ReactNode } from "react";
import AccountSecurityNav from "./AccountSecurityNav";

export const metadata: Metadata = {
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

export default function AdminLayout({ children }: { children: ReactNode }) {
  return <><AccountSecurityNav />{children}</>;
}
