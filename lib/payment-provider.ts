export type PaymentCheckoutInput = {
  paymentRequestId: string;
  token: string;
  amount: number;
  currency: string;
  description: string;
  customerEmail?: string | null;
  successUrl: string;
  cancelUrl: string;
};

export type PaymentCheckoutResult = {
  provider: string;
  checkoutUrl: string | null;
  providerRef?: string | null;
};

export interface PaymentProvider {
  name: string;
  createCheckout(input: PaymentCheckoutInput): Promise<PaymentCheckoutResult>;
}

class ManualProvider implements PaymentProvider {
  name = "manual";
  async createCheckout(): Promise<PaymentCheckoutResult> {
    return { provider: this.name, checkoutUrl: null };
  }
}

export function getPaymentProvider(): PaymentProvider {
  const selected = (process.env.PAYMENT_PROVIDER || "manual").toLowerCase();

  // Real providers are intentionally opt-in. Add an adapter only after credentials,
  // settlement currency, refund rules and webhook verification are confirmed.
  switch (selected) {
    default:
      return new ManualProvider();
  }
}
