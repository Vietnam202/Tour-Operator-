import { prisma } from "../lib/prisma";
import { getNotificationOutboxStatus, processNotificationOutbox, pruneDeliveredNotifications, requeueFailedNotifications } from "../lib/notification-outbox";

let stopping = false;
for (const signal of ["SIGINT", "SIGTERM"] as const) {
  process.on(signal, () => {
    stopping = true;
    console.log(`Received ${signal}; stopping after the current delivery batch.`);
  });
}

async function main() {
  const once = process.argv.includes("--once");
  if (process.argv.includes("--status")) {
    console.log(JSON.stringify(await getNotificationOutboxStatus(), null, 2));
    return;
  }
  if (process.argv.includes("--prune-delivered")) {
    const retentionArg = process.argv.find(arg => arg.startsWith("--retention-days="));
    const retentionDays = retentionArg ? Number(retentionArg.split("=")[1]) : 30;
    if (!Number.isFinite(retentionDays) || retentionDays < 1) throw new Error("--retention-days must be at least 1");
    const result = await pruneDeliveredNotifications(prisma, retentionDays);
    console.log(`Deleted ${result.deleted} delivered notification event(s) older than ${Math.floor(retentionDays)} day(s).`);
    return;
  }
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
