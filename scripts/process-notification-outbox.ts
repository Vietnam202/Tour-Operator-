import { prisma } from "../lib/prisma";
import { processNotificationOutbox, requeueFailedNotifications } from "../lib/notification-outbox";

let stopping = false;
for (const signal of ["SIGINT", "SIGTERM"] as const) {
  process.on(signal, () => {
    stopping = true;
    console.log(`Received ${signal}; stopping after the current delivery batch.`);
  });
}

async function main() {
  const once = process.argv.includes("--once");
  if (process.argv.includes("--requeue-failed")) {
    const result = await requeueFailedNotifications();
    console.log(`Requeued ${result.requeued} failed notification event(s).`);
    return;
  }

  do {
    const result = await processNotificationOutbox();
    if (once || stopping) break;
    if (result.claimed === 0) await new Promise(resolve => setTimeout(resolve, 2_000));
  } while (!stopping);
}

main()
  .catch(error => {
    console.error(error);
    process.exitCode = 1;
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
