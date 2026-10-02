import { validateRuntimeConfig } from "@/lib/runtime-config";

export async function register() {
  validateRuntimeConfig();
}
