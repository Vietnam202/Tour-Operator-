CREATE TABLE IF NOT EXISTS invoice_commercial_documents (
 invoice_id BIGINT UNSIGNED PRIMARY KEY,
 document_ref VARCHAR(90) NOT NULL,
 issue_date DATE NOT NULL,
 public_snapshot_json JSON NOT NULL,
 content_hash CHAR(64) NOT NULL,
 issued_by BIGINT UNSIGNED NOT NULL,
 issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_commercial_ref(document_ref),
 FOREIGN KEY(invoice_id) REFERENCES customer_invoices(id),
 FOREIGN KEY(issued_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;