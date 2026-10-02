CREATE TABLE IF NOT EXISTS invoice_payment_schedules (
 invoice_id BIGINT UNSIGNED PRIMARY KEY,
 schedule_id BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY uq_schedule_invoice(schedule_id),
 FOREIGN KEY(invoice_id) REFERENCES customer_invoices(id),
 FOREIGN KEY(schedule_id) REFERENCES payment_schedules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
