import { NextResponse } from "next/server";
import { calculateQuote } from "@/lib/pricing";
import { parseQuoteInput, QuoteInputError } from "@/lib/quote-input";
import { toPublicQuote } from "@/lib/public-quote";
import { rateLimit } from "@/lib/rate-limit";

export async function POST(request: Request) {
  try {
    const limit = await rateLimit(request, "quote", 60, 60_000);
    if (!limit.allowed) return NextResponse.json({ error: "Too many quote requests. Please try again shortly." }, { status: 429, headers: { "Retry-After": String(limit.retryAfter) } });
    const quote = await calculateQuote(parseQuoteInput(await request.json()));
    return NextResponse.json({ quote: toPublicQuote(quote) }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    if (error instanceof QuoteInputError) return NextResponse.json({ error: error.message }, { status: error.status });
    if (error instanceof SyntaxError) return NextResponse.json({ error: "Invalid JSON request." }, { status: 400 });
    // Never send database errors, connection details or internal pricing records to the browser.
    return NextResponse.json({ error: "Unable to calculate your quote right now. Please try again." }, { status: 503 });
  }
}
