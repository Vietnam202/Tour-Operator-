SET NAMES utf8mb4;
SET time_zone = '+07:00';

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(64) PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Ho_Chi_Minh',
  base_currency CHAR(3) NOT NULL DEFAULT 'USD',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(128) NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_role_company_code(company_id, code),
  CONSTRAINT fk_roles_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(96) NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NOT NULL,
  mobile VARCHAR(64) NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('ACTIVE','INACTIVE','LOCKED') NOT NULL DEFAULT 'ACTIVE',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_company_email(company_id,email),
  CONSTRAINT fk_users_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_users_role FOREIGN KEY(role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permissions (
  user_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  effect ENUM('ALLOW','DENY') NOT NULL,
  PRIMARY KEY(user_id,permission_id),
  CONSTRAINT fk_up_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_up_perm FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  supplier_ref VARCHAR(48) NOT NULL,
  name VARCHAR(190) NOT NULL,
  supplier_type ENUM('HOTEL','TRANSPORT','GUIDE','CRUISE','TOUR','ATTRACTION','RESTAURANT','VISA','OTHER') NOT NULL DEFAULT 'OTHER',
  city VARCHAR(120) NULL,
  country VARCHAR(120) NOT NULL DEFAULT 'Vietnam',
  currency CHAR(3) NOT NULL DEFAULT 'VND',
  primary_email VARCHAR(190) NULL,
  primary_whatsapp VARCHAR(64) NULL,
  payment_terms TEXT NULL,
  preferred TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('ACTIVE','INACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
  version_no INT UNSIGNED NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  archived_at DATETIME NULL,
  UNIQUE KEY uq_supplier_ref(company_id,supplier_ref),
  KEY idx_supplier_name(company_id,name),
  KEY idx_supplier_city(company_id,city),
  CONSTRAINT fk_sup_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_sup_created FOREIGN KEY(created_by) REFERENCES users(id),
  CONSTRAINT fk_sup_updated FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  role_title VARCHAR(120) NULL,
  email VARCHAR(190) NULL,
  whatsapp VARCHAR(64) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sc_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  document_ref VARCHAR(64) NOT NULL,
  supplier_id BIGINT UNSIGNED NULL,
  document_type ENUM('CONTRACT','RATE_SHEET','PROMOTION','SPECIAL_QUOTE','SERIES_QUOTE','SUPPLEMENT','TERMS','OTHER') NOT NULL DEFAULT 'OTHER',
  original_filename VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  storage_driver ENUM('GOOGLE_DRIVE','LOCAL') NOT NULL,
  storage_file_id VARCHAR(255) NULL,
  storage_path VARCHAR(500) NULL,
  review_status ENUM('NEW','PROCESSING','EXTRACTED','NEEDS_REVIEW','APPROVED','REJECTED','ARCHIVED') NOT NULL DEFAULT 'NEW',
  valid_from DATE NULL,
  valid_to DATE NULL,
  extracted_text MEDIUMTEXT NULL,
  extraction_quality ENUM('NONE','LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'NONE',
  notes TEXT NULL,
  version_no INT UNSIGNED NOT NULL DEFAULT 1,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_document_ref(company_id,document_ref),
  KEY idx_documents_supplier(supplier_id,review_status),
  KEY idx_documents_status(company_id,review_status),
  CONSTRAINT fk_doc_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_doc_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_doc_user FOREIGN KEY(uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  rate_ref VARCHAR(64) NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  category ENUM('HOTEL','TRANSPORT','GUIDE','CRUISE','TOUR','ATTRACTION','MEAL','VISA','OTHER') NOT NULL,
  destination VARCHAR(160) NULL,
  product_name VARCHAR(190) NOT NULL,
  option_name VARCHAR(190) NULL,
  rate_type ENUM('CONTRACT','PROMOTION','SPECIAL_QUOTE','SERIES','MANUAL_OVERRIDE') NOT NULL DEFAULT 'CONTRACT',
  market VARCHAR(120) NULL,
  min_pax INT UNSIGNED NULL,
  max_pax INT UNSIGNED NULL,
  reusable TINYINT(1) NOT NULL DEFAULT 1,
  linked_trip_ref VARCHAR(64) NULL,
  status ENUM('DRAFT','ACTIVE','INACTIVE','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
  version_no INT UNSIGNED NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  archived_at DATETIME NULL,
  UNIQUE KEY uq_rate_ref(company_id,rate_ref),
  KEY idx_rates_match(company_id,category,destination,supplier_id,status),
  CONSTRAINT fk_rates_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_rates_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_rates_created FOREIGN KEY(created_by) REFERENCES users(id),
  CONSTRAINT fk_rates_updated FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rate_id BIGINT UNSIGNED NOT NULL,
  version_no INT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  rate_basis ENUM('PER_PAX','PER_ROOM_NIGHT','PER_VEHICLE','PER_TRANSFER','PER_DAY','PER_GUIDE_DAY','PER_CABIN','PER_GROUP','PER_SERVICE') NOT NULL,
  valid_from DATE NULL,
  valid_to DATE NULL,
  booking_valid_from DATE NULL,
  booking_valid_to DATE NULL,
  tax_basis ENUM('UNKNOWN','NET','TAX_INCLUDED','TAX_EXCLUDED','PLUS_PLUS') NOT NULL DEFAULT 'UNKNOWN',
  source_document_id BIGINT UNSIGNED NULL,
  source_locator VARCHAR(255) NULL,
  terms TEXT NULL,
  internal_notes TEXT NULL,
  approval_status ENUM('UNREVIEWED','REVIEWED','APPROVED','REJECTED','SUPERSEDED') NOT NULL DEFAULT 'UNREVIEWED',
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rate_version(rate_id,version_no),
  KEY idx_rv_validity(valid_from,valid_to,approval_status),
  KEY idx_rv_document(source_document_id),
  CONSTRAINT fk_rv_rate FOREIGN KEY(rate_id) REFERENCES rates(id) ON DELETE CASCADE,
  CONSTRAINT fk_rv_doc FOREIGN KEY(source_document_id) REFERENCES documents(id),
  CONSTRAINT fk_rv_approved FOREIGN KEY(approved_by) REFERENCES users(id),
  CONSTRAINT fk_rv_created FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  entity_type VARCHAR(48) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  due_at DATETIME NULL,
  priority ENUM('NORMAL','HIGH','CRITICAL') NOT NULL DEFAULT 'NORMAL',
  status ENUM('OPEN','DONE','SNOOZED','CANCELLED') NOT NULL DEFAULT 'OPEN',
  source ENUM('MANUAL','AUTOMATION') NOT NULL DEFAULT 'AUTOMATION',
  rule_code VARCHAR(96) NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tasks_today(company_id,owner_user_id,status,due_at),
  CONSTRAINT fk_tasks_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_tasks_owner FOREIGN KEY(owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  entity_type VARCHAR(48) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  event_code VARCHAR(96) NOT NULL,
  summary VARCHAR(500) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_activity_entity(company_id,entity_type,entity_id,created_at),
  CONSTRAINT fk_activity_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_activity_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  action_code VARCHAR(96) NOT NULL,
  entity_type VARCHAR(48) NULL,
  entity_id BIGINT UNSIGNED NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  request_id VARCHAR(64) NULL,
  ip_address VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_entity(company_id,entity_type,entity_id,created_at),
  KEY idx_audit_action(company_id,action_code,created_at),
  CONSTRAINT fk_audit_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions(code,name) VALUES
('supplier.view','View suppliers'),
('supplier.create','Create suppliers'),
('supplier.edit','Edit suppliers'),
('supplier.archive','Archive suppliers'),
('document.view','View supplier documents'),
('document.upload','Upload supplier documents'),
('document.review','Review supplier documents'),
('rate.view','View Rate Master'),
('rate.create','Create rates'),
('rate.edit','Edit draft rates'),
('rate.approve','Approve rate versions'),
('rate.archive','Archive rates'),
('task.view','View tasks'),
('user.manage','Manage users and permissions'),
('audit.view','View audit logs');

INSERT INTO schema_migrations(version) VALUES ('001_phase1_core')
ON DUPLICATE KEY UPDATE version=VALUES(version);
