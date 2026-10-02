import type { Metadata } from "next";
import type { ReactNode } from "react";

export const metadata: Metadata = { title: "Private travel voucher", robots: { index: false, follow: false, nocache: true } };
export default function VoucherLayout({ children }: { children: ReactNode }) { return children; }
