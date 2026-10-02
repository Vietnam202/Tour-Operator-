import { scrypt, timingSafeEqual } from "node:crypto";

// Keep the existing seed's salt:scrypt format so current staff can still sign in.
// A dummy derivation also runs for unknown, inactive and malformed-hash users.
const dummySalt = "0".repeat(32);
const dummyHash = Buffer.alloc(64);
export async function verifyStaffPassword(password: string, stored: string | null): Promise<boolean> {
  const valid = stored !== null && /^[a-f0-9]{32}:[a-f0-9]{128}$/.test(stored);
  const [salt, encoded] = valid ? stored!.split(":") : [dummySalt, ""];
  const expected = valid ? Buffer.from(encoded, "hex") : dummyHash;
  const candidate = await new Promise<Buffer>((resolve, reject) => {
    scrypt(password, salt, 64, (error, key) => error ? reject(error) : resolve(key));
  });
  const matches = timingSafeEqual(candidate, expected);
  return valid && matches;
}
