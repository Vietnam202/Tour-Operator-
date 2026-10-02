const requiredProduction = ["DATABASE_URL", "NEXT_PUBLIC_SITE_URL"] as const;

export function validateRuntimeConfig() {
  if (process.env.NODE_ENV !== "production") return;
  const missing = requiredProduction.filter((key) => !process.env[key]);
  if (missing.length) throw new Error("Missing required production environment variables: " + missing.join(", "));

  const adminKey = process.env.ADMIN_API_KEY;
  if (adminKey && adminKey.length < 32) throw new Error("ADMIN_API_KEY must be at least 32 characters when enabled.");
  const siteUrl = process.env.NEXT_PUBLIC_SITE_URL;
  if (siteUrl && !siteUrl.startsWith("https://")) throw new Error("NEXT_PUBLIC_SITE_URL must use HTTPS in production.");
}
