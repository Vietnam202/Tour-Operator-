import { createClient } from "redis";
import { getClientIp } from "@/lib/client-ip";

type Bucket = { count: number; resetAt: number };
type Rule = { key: string; limit: number; windowMs: number };
export type RateLimitResult = { allowed: boolean; retryAfter: number };

const buckets = new Map<string, Bucket>();
const KEY_PREFIX = "hca:ratelimit:v1:";
let redisClientPromise: Promise<ReturnType<typeof createClient>> | undefined;

const redisScript = `
local blocked = 0
local retry_ms = 0
for i, key in ipairs(KEYS) do
  local limit = tonumber(ARGV[(i - 1) * 2 + 1])
  local current = tonumber(redis.call("GET", key) or "0")
  local ttl = redis.call("PTTL", key)
  if current >= limit then
    blocked = 1
    if ttl > retry_ms then retry_ms = ttl end
  end
end
if blocked == 1 then
  if retry_ms < 1 then retry_ms = 1000 end
  return {0, retry_ms}
end
for i, key in ipairs(KEYS) do
  local window_ms = tonumber(ARGV[(i - 1) * 2 + 2])
  local count = redis.call("INCR", key)
  local ttl = redis.call("PTTL", key)
  if count == 1 or ttl < 0 then
    redis.call("PEXPIRE", key, window_ms)
  end
end
return {1, 0}
`;

export class RateLimitUnavailableError extends Error {
  constructor(cause?: unknown) {
    super("Rate limiter unavailable.", { cause });
    this.name = "RateLimitUnavailableError";
  }
}

function backend(): "memory" | "redis" {
  const configured = process.env.RATE_LIMIT_BACKEND?.trim().toLowerCase();
  if (configured && configured !== "memory" && configured !== "redis") {
    throw new Error("RATE_LIMIT_BACKEND must be memory or redis.");
  }
  if (process.env.NODE_ENV === "production") {
    if (configured === "memory") throw new Error("Production rate limiting cannot use the memory backend.");
    return "redis";
  }
  return configured === "redis" ? "redis" : "memory";
}

function validateRules(rules: Rule[]) {
  if (!rules.length) throw new Error("At least one rate-limit rule is required.");
  for (const rule of rules) {
    if (!rule.key || !Number.isInteger(rule.limit) || rule.limit < 1 || !Number.isInteger(rule.windowMs) || rule.windowMs < 1) {
      throw new Error("Invalid rate-limit rule.");
    }
  }
}

function memoryConsume(rules: Rule[], now = Date.now()): RateLimitResult {
  for (const [key, bucket] of buckets) if (bucket.resetAt <= now) buckets.delete(key);

  let retryAfter = 0;
  for (const rule of rules) {
    const current = buckets.get(rule.key);
    if (current && current.count >= rule.limit) {
      retryAfter = Math.max(retryAfter, Math.max(1, Math.ceil((current.resetAt - now) / 1000)));
    }
  }
  if (retryAfter) return { allowed: false, retryAfter };

  for (const rule of rules) {
    const current = buckets.get(rule.key);
    if (!current || current.resetAt <= now) buckets.set(rule.key, { count: 1, resetAt: now + rule.windowMs });
    else current.count += 1;
  }
  return { allowed: true, retryAfter: 0 };
}

async function redisClient() {
  const url = process.env.REDIS_URL?.trim();
  if (!url) throw new Error("REDIS_URL is required for the Redis rate limiter.");
  if (!redisClientPromise) {
    redisClientPromise = (async () => {
      const client = createClient({ url });
      client.on("error", () => undefined);
      await client.connect();
      return client;
    })().catch(error => {
      redisClientPromise = undefined;
      throw error;
    });
  }
  return redisClientPromise;
}

async function redisConsume(rules: Rule[]): Promise<RateLimitResult> {
  const client = await redisClient();
  const keys = rules.map(rule => KEY_PREFIX + rule.key);
  const args = rules.flatMap(rule => [String(rule.limit), String(rule.windowMs)]);
  const raw = await client.eval(redisScript, { keys, arguments: args }) as unknown;
  if (!Array.isArray(raw) || raw.length < 2) throw new Error("Unexpected Redis limiter response.");
  const allowed = Number(raw[0]) === 1;
  const retryMs = Number(raw[1]) || 0;
  return { allowed, retryAfter: allowed ? 0 : Math.max(1, Math.ceil(retryMs / 1000)) };
}

export async function consumeRateLimit(rules: Rule[]): Promise<RateLimitResult> {
  try {
    validateRules(rules);
    return backend() === "redis" ? await redisConsume(rules) : memoryConsume(rules);
  } catch (error) {
    if (error instanceof RateLimitUnavailableError) throw error;
    throw new RateLimitUnavailableError(error);
  }
}

export async function rateLimit(request: Request, namespace: string, limit = 20, windowMs = 60_000) {
  try {
    const ip = getClientIp(request);
    return await consumeRateLimit([{ key: `${namespace}:ip:${ip}`, limit, windowMs }]);
  } catch (error) {
    if (error instanceof RateLimitUnavailableError) throw error;
    throw new RateLimitUnavailableError(error);
  }
}

export async function closeRateLimitRedisForTests() {
  const pending = redisClientPromise;
  redisClientPromise = undefined;
  if (!pending) return;
  const client = await pending.catch(() => null);
  if (client?.isOpen) await client.quit();
}
