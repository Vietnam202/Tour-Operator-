import CruiseDetail from "../[slug]/page";

export const dynamic = "force-dynamic";

// Keep the historical URL while using the same published inventory as every cruise.
export default function StellarCruisePage() {
  return CruiseDetail({ params: Promise.resolve({ slug: "stellar-of-the-seas" }) });
}
