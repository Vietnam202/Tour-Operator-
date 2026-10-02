type Bucket = { count:number; resetAt:number };
const buckets = new Map<string,Bucket>();

export function rateLimit(request: Request, namespace: string, limit = 20, windowMs = 60_000) {
  const forwarded = request.headers.get("x-forwarded-for")?.split(",")[0]?.trim();
  const ip = forwarded || request.headers.get("x-real-ip") || "unknown";
  const key = namespace + ":" + ip;
  const now = Date.now();
  const current = buckets.get(key);

  if (!current || current.resetAt <= now) {
    buckets.set(key,{count:1,resetAt:now+windowMs});
    return { allowed:true, retryAfter:0 };
  }
  if (current.count >= limit) return { allowed:false,retryAfter:Math.ceil((current.resetAt-now)/1000) };
  current.count += 1;
  return { allowed:true,retryAfter:0 };
}
