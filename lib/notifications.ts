// Durable operational notifications are persisted in NotificationOutbox.
// HTTP request paths enqueue events transactionally via lib/notification-outbox.ts.
// External delivery is intentionally performed only by the independent processor.
export {
  enqueueNotificationTx,
  processNotificationOutbox,
  notificationEvents,
} from "@/lib/notification-outbox";
export type { NotificationEvent, ProcessOutboxOptions } from "@/lib/notification-outbox";
