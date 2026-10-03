import { createHash, timingSafeEqual } from "node:crypto";

export type PaymentWebhookPayload = Record<string, any>;

export type PaymentWebhookVerifier = {
  verify(request: Request): Promise<PaymentWebhookPayload>;
};

export class PaymentWebhookVerificationError extends Error {
  constructor(message: string, public readonly status: number) {
    super(message);
    this.name = "PaymentWebhookVerificationError";
  }
}

function timingSafeSecretEqual(candidate: string | null, expected: string) {
  if (!candidate || !expected) return false;
  const candidateDigest = createHash("sha256").update(candidate, "utf8").digest();
  const expectedDigest = createHash("sha256").update(expected, "utf8").digest();
  return timingSafeEqual(candidateDigest, expectedDigest);
}

function sharedSecretVerifier(): PaymentWebhookVerifier {
  return {
    async verify(request) {
      const secret = process.env.PAYMENT_WEBHOOK_SECRET;
      if (!secret || !timingSafeSecretEqual(request.headers.get("x-webhook-secret"), secret)) {
        throw new PaymentWebhookVerificationError("Invalid webhook signature", 401);
      }

      let payload: unknown;
      try {
        payload = await request.json();
      } catch {
        throw new PaymentWebhookVerificationError("Invalid payment event", 400);
      }
      if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
        throw new PaymentWebhookVerificationError("Invalid payment event", 400);
      }
      return payload as PaymentWebhookPayload;
    },
  };
}

function disposableCiModeEnabled() {
  if (process.env.HCA_INTEGRATION_TESTS !== "1") return false;
  try {
    const database = new URL(process.env.DATABASE_URL || "");
    return ["localhost", "127.0.0.1"].includes(database.hostname) && database.pathname === "/hca_ci";
  } catch {
    return false;
  }
}

/**
 * Production verifier registry.
 *
 * Add real provider adapters here only after they verify that provider's native
 * signature over the exact raw request bytes and enforce any provider-specific
 * replay/timestamp rules. Never register the generic shared-secret verifier here.
 */
const productionVerifierRegistry: Readonly<Record<string, PaymentWebhookVerifier>> = Object.freeze({});

export function getPaymentWebhookVerifier(provider: string): PaymentWebhookVerifier | null {
  const productionVerifier = productionVerifierRegistry[provider];
  if (productionVerifier) return productionVerifier;

  if (provider === "ci" && disposableCiModeEnabled()) return sharedSecretVerifier();

  if (provider === "local" && process.env.NODE_ENV !== "production") {
    return sharedSecretVerifier();
  }

  return null;
}
