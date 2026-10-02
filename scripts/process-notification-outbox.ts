import { prisma } from "../lib/prisma";
import { processNotificationOutbox } from "../lib/notification-outbox";

async function main() {
  const once = process.argv.includes("--once");
  do {
    const result = await processNotificationOutbox();
    if (once) break;
    if (result.claimed === 0) await new Promise(resolve => setTimeout(resolve, 2_000));
  } while (true);
}

main()
  .catch(error => {
    console.error(error);
    process.exitCode = 1;
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
