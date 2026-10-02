CREATE TABLE IF NOT EXISTS customer_receipts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 party_kind ENUM('CUSTOMER','AGENT','BOOKING') NOT NULL,
 party_id BIGINT UNSIGNED NOT NULL,
 receipt_ref VARCHAR(64) NOT NULL,
 payment_date DATE NOT NULL,
 amount DECIMAL(18,2) NOT NULL,
 currency CHAR(3) NOT NULL,
 transaction_reference VARCHAR(190) NOT NULL,
 idempotency_key VARCHAR(80) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 recorded_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_receipt_key(company_id,idempotency_key),
 UNIQUE KEY uq_receipt_reference(company_id,receipt_ref),
 KEY idx_receipt_party(company_id,party_kind,party_id,payment_date),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(recorded_by) REFERENCES users(id),
 CHECK(amount>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_allocations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 receipt_id BIGINT UNSIGNED NOT NULL,
 invoice_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(18,2) NOT NULL,
 allocation_date DATE NOT NULL,
 request_key VARCHAR(80) NOT NULL,
 allocated_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_allocation_retry(receipt_id,request_key,invoice_id),
 FOREIGN KEY(receipt_id) REFERENCES customer_receipts(id),
 FOREIGN KEY(invoice_id) REFERENCES customer_invoices(id),
 FOREIGN KEY(allocated_by) REFERENCES users(id),
 CHECK(amount>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_issue_snapshots (
 invoice_id BIGINT UNSIGNED PRIMARY KEY,
 public_snapshot_json JSON NOT NULL,
 content_hash CHAR(64) NOT NULL,
 credit_for_invoice_id BIGINT UNSIGNED NULL,
 schedule_id BIGINT UNSIGNED NULL,
 issued_by BIGINT UNSIGNED NOT NULL,
 issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(invoice_id) REFERENCES customer_invoices(id),
 FOREIGN KEY(credit_for_invoice_id) REFERENCES customer_invoices(id),
 FOREIGN KEY(schedule_id) REFERENCES payment_schedules(id),
 FOREIGN KEY(issued_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_invoice_reconciliations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 payable_id BIGINT UNSIGNED NOT NULL,
 supplier_invoice_no VARCHAR(120) NOT NULL,
 currency CHAR(3) NOT NULL,
 confirmed_amount DECIMAL(18,2) NOT NULL,
 invoice_amount DECIMAL(18,2) NOT NULL,
 variance DECIMAL(18,2) NOT NULL,
 status ENUM('REVIEW_REQUIRED','APPROVED') NOT NULL DEFAULT 'REVIEW_REQUIRED',
 reason VARCHAR(1000) NOT NULL,
 recorded_by BIGINT UNSIGNED NOT NULL,
 approved_by BIGINT UNSIGNED NULL,
 approved_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_reconciled_payable(payable_id),
 FOREIGN KEY(payable_id) REFERENCES supplier_payables(id),
 FOREIGN KEY(recorded_by) REFERENCES users(id),
 FOREIGN KEY(approved_by) REFERENCES users(id),
 CHECK(invoice_amount>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_payment_keys (
 company_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(80) NOT NULL,
 payment_id BIGINT UNSIGNED NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 PRIMARY KEY(company_id,request_key),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(payment_id) REFERENCES supplier_payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
