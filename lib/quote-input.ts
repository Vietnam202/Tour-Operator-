export class QuoteInputError extends Error {
  constructor(message: string, public readonly status = 400) {
    super(message);
    this.name = "QuoteInputError";
  }
}

export type TransferType = "none" | "shared" | "private";
export type QuoteInput = {
  cruiseId: string;
  cabinId: string;
  departureId: string;
  adults: number;
  children: number;
  transferType: TransferType;
  departureDate?: string;
  durationNights?: number;
};

export function asObject(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    throw new QuoteInputError("A JSON object is required.");
  }
  return value as Record<string, unknown>;
}

export function text(value: unknown, label: string, max: number, required = true): string {
  if ((value === undefined || value === null) && !required) return "";
  if (typeof value !== "string" || value.length > max || (required && !value.trim())) {
    throw new QuoteInputError(`Please provide a valid ${label}.`);
  }
  return value.trim();
}

function integer(value: unknown, label: string, min: number, max: number): number {
  if (typeof value !== "number" || !Number.isSafeInteger(value) || value < min || value > max) {
    throw new QuoteInputError(`${label} must be a whole number between ${min} and ${max}.`);
  }
  return value;
}

export function parseQuoteInput(value: unknown): QuoteInput {
  const body = asObject(value);
  const adults = integer(body.adults, "Adults", 1, 12);
  const children = integer(body.children ?? 0, "Children", 0, 12);
  if (adults + children > 16) throw new QuoteInputError("Please contact our team for group bookings.");
  const transferType = body.transferType ?? "none";
  if (transferType !== "none" && transferType !== "shared" && transferType !== "private") {
    throw new QuoteInputError("Please select a valid transfer option.");
  }
  const input: QuoteInput = {
    cruiseId: text(body.cruiseId, "cruise", 100),
    cabinId: text(body.cabinId, "cabin", 100),
    departureId: text(body.departureId, "published departure", 100),
    adults, children, transferType,
  };
  if (body.departureDate !== undefined) {
    const date = text(body.departureDate, "departure date", 10);
    const parsed = new Date(`${date}T00:00:00.000Z`);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !Number.isFinite(parsed.getTime()) || parsed.toISOString().slice(0, 10) !== date) {
      throw new QuoteInputError("Please select a valid departure date.");
    }
    input.departureDate = date;
  }
  if (body.durationNights !== undefined) input.durationNights = integer(body.durationNights, "Duration", 1, 30);
  return input;
}
