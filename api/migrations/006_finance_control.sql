SET NAMES utf8mb4;
SET time_zone = '+07:00';

CREATE TABLE IF NOT EXISTS payment_schedules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  direction ENUM('AR','AP') NOT NULL,
  supplier_id BIGINT UNSIGNED NULL,
  label VARCHAR(190) NOT NULL,
  percentage DECIMAL(10,4) NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  due_date DATE NULL,
  status ENUM('UNPAID','PART_PAID','PAID','CANCELLED') NOT NULL DEFAULT 'UNPAID',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payment_schedule(booking_id,direction,due_date,status),
  CONSTRAINT fk_schedule_booking FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_schedule_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  invoice_ref VARCHAR(64) NOT NULL,
  booking_id BIGINT UNSIGNED NOT NULL,
  invoice_type ENUM('PROFORMA','PAYMENT_REQUEST','DEPOSIT_REQUEST','FINAL_REQUEST','CUSTOMER_INVOICE','RECEIPT','CREDIT_NOTE') NOT NULL DEFAULT 'PROFORMA',
  status ENUM('DRAFT','ISSUED','SENT','PART_PAID','PAID','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  issue_date DATE NULL,
  due_date DATE NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  subtotal DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount DECIMAL(18,2) NOT NULL DEFAULT 0,
  service_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
  tax_percent DECIMAL(10,4) NOT NULL DEFAULT 0,
  total DECIMAL(18,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  balance DECIMAL(18,2) NOT NULL DEFAULT 0,
  line_items_json JSON NULL,
  payment_terms TEXT NULL,
  bank_details TEXT NULL,
  notes TEXT NULL,
  issued_by BIGINT UNSIGNED NULL,
  issued_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invoice_ref(company_id,invoice_ref),
  KEY idx_invoice_booking(booking_id,status,due_date),
  CONSTRAINT fk_invoice_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_invoice_booking FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_invoice_issued FOREIGN KEY(issued_by) REFERENCES users(id),
  CONSTRAINT fk_invoice_created FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  payment_ref VARCHAR(64) NOT NULL,
  booking_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NULL,
  schedule_id BIGINT UNSIGNED NULL,
  payment_date DATE NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  payment_method VARCHAR(80) NULL,
  bank_name VARCHAR(120) NULL,
  transaction_reference VARCHAR(190) NULL,
  fx_rate DECIMAL(18,6) NULL,
  notes VARCHAR(1000) NULL,
  recorded_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customer_payment_ref(company_id,payment_ref),
  KEY idx_customer_payment_booking(booking_id,payment_date),
  CONSTRAINT fk_cp_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_cp_booking FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_cp_invoice FOREIGN KEY(invoice_id) REFERENCES customer_invoices(id),
  CONSTRAINT fk_cp_schedule FOREIGN KEY(schedule_id) REFERENCES payment_schedules(id),
  CONSTRAINT fk_cp_user FOREIGN KEY(recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_payables (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  payable_ref VARCHAR(64) NOT NULL,
  booking_id BIGINT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  supplier_order_id BIGINT UNSIGNED NULL,
  service_id BIGINT UNSIGNED NULL,
  label VARCHAR(190) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'VND',
  total_amount DECIMAL(18,2) NOT NULL,
  paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  balance DECIMAL(18,2) NOT NULL,
  due_date DATE NULL,
  status ENUM('UNPAID','PART_PAID','PAID','CANCELLED') NOT NULL DEFAULT 'UNPAID',
  supplier_invoice_no VARCHAR(120) NULL,
  notes VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payable_ref(company_id,payable_ref),
  KEY idx_payable_due(company_id,status,due_date),
  CONSTRAINT fk_payable_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_payable_booking FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_payable_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_payable_order FOREIGN KEY(supplier_order_id) REFERENCES supplier_orders(id),
  CONSTRAINT fk_payable_service FOREIGN KEY(service_id) REFERENCES booking_services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  payment_ref VARCHAR(64) NOT NULL,
  payable_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  payment_date DATE NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  payment_method VARCHAR(80) NULL,
  transaction_reference VARCHAR(190) NULL,
  fx_rate DECIMAL(18,6) NULL,
  bank_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
  notes VARCHAR(1000) NULL,
  recorded_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_supplier_payment_ref(company_id,payment_ref),
  KEY idx_supplier_payment_booking(booking_id,payment_date),
  CONSTRAINT fk_sp_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_sp_payable FOREIGN KEY(payable_id) REFERENCES supplier_payables(id),
  CONSTRAINT fk_sp_booking FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_sp_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_sp_user FOREIGN KEY(recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_settings (
  company_id BIGINT UNSIGNED NOT NULL,
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(company_id,setting_key),
  CONSTRAINT fk_setting_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_setting_user FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alerts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  alert_type ENUM('SALES','PRODUCT','OPERATIONS','FINANCE','DATA_QUALITY','SECURITY') NOT NULL,
  severity ENUM('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'WARNING',
  title VARCHAR(255) NOT NULL,
  entity_type VARCHAR(48) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  alert_key VARCHAR(190) NOT NULL,
  status ENUM('OPEN','RESOLVED','DISMISSED') NOT NULL DEFAULT 'OPEN',
  detail VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  UNIQUE KEY uq_alert_active(company_id,alert_key,status),
  KEY idx_alert_company(company_id,status,severity,created_at),
  CONSTRAINT fk_alert_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions(code,name) VALUES
('finance.view','View Finance workspace'),
('customer_ar.view','View customer receivables'),
('customer_payment.record','Record customer payments'),
('supplier_ap.view','View supplier payables'),
('supplier_payment.record','Record supplier payments'),
('profit.view','View profitability'),
('finance.close','Close or reopen booking finance'),
('report.view','View management reports'),
('settings.manage','Manage company settings');

INSERT INTO schema_migrations(version) VALUES ('006_finance_control')
ON DUPLICATE KEY UPDATE version=VALUES(version);
