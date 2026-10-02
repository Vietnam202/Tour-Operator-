SET NAMES utf8mb4;
SET time_zone = '+07:00';

ALTER TABLE rates
  ADD COLUMN source_origin ENUM('SUPPLIER_DOCUMENT','MANUAL','LEGACY') NOT NULL DEFAULT 'SUPPLIER_DOCUMENT' AFTER linked_trip_ref,
  ADD KEY idx_rates_origin(company_id,source_origin,status);

CREATE TABLE IF NOT EXISTS hotel_rate_rules (
  rate_version_id BIGINT UNSIGNED PRIMARY KEY,
  room_type VARCHAR(190) NULL,
  meal_plan VARCHAR(64) NULL,
  season_name VARCHAR(120) NULL,
  single_rate DECIMAL(18,2) NULL,
  twin_double_rate DECIMAL(18,2) NULL,
  triple_rate DECIMAL(18,2) NULL,
  extra_bed_rate DECIMAL(18,2) NULL,
  child_no_bed_rate DECIMAL(18,2) NULL,
  child_with_bed_rate DECIMAL(18,2) NULL,
  weekend_surcharge DECIMAL(18,2) NULL,
  peak_surcharge DECIMAL(18,2) NULL,
  gala_dinner DECIMAL(18,2) NULL,
  minimum_stay INT UNSIGNED NULL,
  blackout_text TEXT NULL,
  CONSTRAINT fk_hotel_rule_version FOREIGN KEY(rate_version_id) REFERENCES rate_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_rate_rules (
  rate_version_id BIGINT UNSIGNED PRIMARY KEY,
  route_from VARCHAR(190) NULL,
  route_to VARCHAR(190) NULL,
  vehicle_type VARCHAR(120) NULL,
  capacity INT UNSIGNED NULL,
  service_type ENUM('AIRPORT_TRANSFER','POINT_TO_POINT','FULL_DAY','HALF_DAY','INTERCITY','OTHER') NOT NULL DEFAULT 'OTHER',
  hours_included DECIMAL(8,2) NULL,
  km_included DECIMAL(10,2) NULL,
  overtime_rate DECIMAL(18,2) NULL,
  extra_km_rate DECIMAL(18,2) NULL,
  driver_overnight DECIMAL(18,2) NULL,
  parking_fee DECIMAL(18,2) NULL,
  toll_fee DECIMAL(18,2) NULL,
  airport_fee DECIMAL(18,2) NULL,
  holiday_surcharge DECIMAL(18,2) NULL,
  CONSTRAINT fk_transport_rule_version FOREIGN KEY(rate_version_id) REFERENCES rate_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cruise_rate_rules (
  rate_version_id BIGINT UNSIGNED PRIMARY KEY,
  cruise_name VARCHAR(190) NULL,
  route_name VARCHAR(190) NULL,
  duration_code VARCHAR(32) NULL,
  cabin_type VARCHAR(190) NULL,
  deck VARCHAR(64) NULL,
  single_rate DECIMAL(18,2) NULL,
  double_twin_rate DECIMAL(18,2) NULL,
  triple_rate DECIMAL(18,2) NULL,
  child_rate DECIMAL(18,2) NULL,
  single_supplement DECIMAL(18,2) NULL,
  transfer_included TINYINT(1) NOT NULL DEFAULT 0,
  entrance_included TINYINT(1) NOT NULL DEFAULT 0,
  kayak_included TINYINT(1) NOT NULL DEFAULT 0,
  holiday_surcharge DECIMAL(18,2) NULL,
  gala_dinner DECIMAL(18,2) NULL,
  CONSTRAINT fk_cruise_rule_version FOREIGN KEY(rate_version_id) REFERENCES rate_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_import_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  batch_ref VARCHAR(64) NOT NULL,
  import_type ENUM('SUPPLIER_MATRIX','LEGACY_JSON','LEGACY_CSV') NOT NULL,
  source_document_id BIGINT UNSIGNED NULL,
  supplier_id BIGINT UNSIGNED NULL,
  status ENUM('PREVIEW','COMMITTED','PARTIAL','FAILED') NOT NULL DEFAULT 'PREVIEW',
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  ready_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_count INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_count INT UNSIGNED NOT NULL DEFAULT 0,
  summary_json JSON NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  committed_at DATETIME NULL,
  UNIQUE KEY uq_import_batch_ref(company_id,batch_ref),
  KEY idx_import_company(company_id,created_at),
  CONSTRAINT fk_import_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_import_doc FOREIGN KEY(source_document_id) REFERENCES documents(id),
  CONSTRAINT fk_import_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_import_user FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_import_rows (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  row_no INT UNSIGNED NOT NULL,
  product_name VARCHAR(190) NULL,
  option_name VARCHAR(190) NULL,
  amount DECIMAL(18,2) NULL,
  currency CHAR(3) NULL,
  row_status ENUM('READY','WARNING','CREATED','SKIPPED','ERROR') NOT NULL DEFAULT 'READY',
  message VARCHAR(500) NULL,
  payload_json JSON NULL,
  created_rate_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_import_rows_batch(batch_id,row_status),
  CONSTRAINT fk_import_row_batch FOREIGN KEY(batch_id) REFERENCES rate_import_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_import_row_rate FOREIGN KEY(created_rate_id) REFERENCES rates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT INTO schema_migrations(version) VALUES ('002_phase1_rate_rules_import')
ON DUPLICATE KEY UPDATE version=VALUES(version);
