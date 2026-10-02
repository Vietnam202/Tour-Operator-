import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Halong Cruise Advisor | Handpicked Halong Bay Cruises",
  description:
    "Compare handpicked Halong Bay and Lan Ha Bay cruises, check availability, and book with local cruise experts in Vietnam.",
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
