import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import test from "node:test";
import { randomUUID } from "node:crypto";
import { createClient } from "redis";

if (process.env.HCA_INTEGRATION_TESTS !== "1") throw new Error("Rate-limit integration tests require HCA_INTEGRATION_TESTS=1.");
const redisUrl = process.env.REDIS_URL || "";
if (!/^redis:\/\/(127\.0\.0\.1|localhost)(:\d+)?\/?$/.test(redisUrl)) {
  throw new Error("Rate-limit integration tests require a disposable local Redis.");
}

function child(key: string, attempts: number) {
  const script = `
    import { consumeRateLimit, closeRateLimitRedisForTests } from "./lib/rate-limit.ts";
    const key = process.argv[1], attempts = Number(process.argv[2]);
    let allowed = 0, blocked = 0, retryAfter = 0;
    for (let i = 0; i < attempts; i++) {
      const result = await consumeRateLimit([{ key, limit: 8, windowMs: 60000 }]);
      if (result.allowed) allowed++; else { blocked++; retryAfter = Math.max(retryAfter, result.retryAfter); }
    }
    await closeRateLimitRedisForTests();
    console.log(JSON.stringify({ allowed, blocked, retryAfter }));
  `;
  return new Promise<{ allowed: number; blocked: number; retryAfter: number }>((resolve, reject) => {
    const childProcess = spawn("npx", ["tsx", "-e", script, key, String(attempts)], {
      cwd: process.cwd(), env: { ...process.env, RATE_LIMIT_BACKEND: "redis" }, stdio: ["ignore", "pipe", "pipe"],
    });
    let stdout = "", stderr = "";
    childProcess.stdout.on("data", chunk => stdout += chunk);
    childProcess.stderr.on("data", chunk => stderr += chunk);
    childProcess.on("error", reject);
    childProcess.on("close", code => {
      if (code !== 0) return reject(new Error(stderr || `child exited ${code}`));
      try { resolve(JSON.parse(stdout.trim().split("\n").at(-1)!)); } catch (error) { reject(error); }
    });
  });
}

test("Redis limiter shares one atomic quota across independent processes", async () => {
  const client = createClient({ url: redisUrl });
  await client.connect();
  const key = `ci:multi-process:${randomUUID()}`;
  try {
    const results = await Promise.all([child(key, 5), child(key, 5)]);
    assert.equal(results.reduce((sum, item) => sum + item.allowed, 0), 8);
    assert.equal(results.reduce((sum, item) => sum + item.blocked, 0), 2);
    assert.ok(results.some(item => item.retryAfter > 0));
  } finally {
    await client.del(`hca:ratelimit:v1:${key}`);
    await client.quit();
  }
});
