SET NAMES utf8mb4;
SET time_zone = '+07:00';

CREATE TABLE IF NOT EXISTS guide_rate_rules (
  rate_version_id BIGINT UNSIGNED PRIMARY KEY,
  language VARCHAR(120) NULL,
  destination VARCHAR(160) NULL,
  service_scope ENUM('FULL_DAY','HALF_DAY','TRANSFER','MULTI_DAY','OTHER') NOT NULL DEFAULT 'OTHER',
  overtime_rate DECIMAL(18,2) NULL,
  meal_allowance DECIMAL(18,2) NULL,
  accommodation_allowance DECIMAL(18,2) NULL,
  intercity_allowance DECIMAL(18,2) NULL,
  holiday_surcharge DECIMAL(18,2) NULL,
  CONSTRAINT fk_guide_rule_version FOREIGN KEY(rate_version_id) REFERENCES rate_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tour_rate_rules (
  rate_version_id BIGINT UNSIGNED PRIMARY KEY,
  tour_name VARCHAR(190) NULL,
  tour_mode ENUM('SIC','PRIVATE','BOTH','OTHER') NOT NULL DEFAULT 'OTHER',
  adult_rate DECIMAL(18,2) NULL,
  child_rate DECIMAL(18,2) NULL,
  pickup_zone VARCHAR(190) NULL,
  pickup_surcharge DECIMAL(18,2) NULL,
  departure_days VARCHAR(190) NULL,
  departure_time TIME NULL,
  cutoff_hours DECIMAL(8,2) NULL,
  minimum_pax INT UNSIGNED NULL,
  guide_language VARCHAR(120) NULL,
  included_text TEXT NULL,
  excluded_text TEXT NULL,
  CONSTRAINT fk_tour_rule_version FOREIGN KEY(rate_version_id) REFERENCES rate_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attraction_rate_rules (
  rate_version_id BIGINT UNSIGNED PRIMARY KEY,
  attraction_name VARCHAR(190) NULL,
  adult_rate DECIMAL(18,2) NULL,
  child_rate DECIMAL(18,2) NULL,
  infant_rate DECIMAL(18,2) NULL,
  senior_rate DECIMAL(18,2) NULL,
  age_rule_text VARCHAR(500) NULL,
  height_rule_text VARCHAR(500) NULL,
  group_rule_text VARCHAR(500) NULL,
  meal_option VARCHAR(190) NULL,
  combo_option VARCHAR(190) NULL,
  holiday_surcharge DECIMAL(18,2) NULL,
  CONSTRAINT fk_attraction_rule_version FOREIGN KEY(rate_version_id) REFERENCES rate_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meal_rate_rules (
  rate_version_id BIGINT UNSIGNED PRIMARY KEY,
  restaurant_name VARCHAR(190) NULL,
  cuisine VARCHAR(120) NULL,
  meal_type ENUM('BREAKFAST','LUNCH','DINNER','OTHER') NOT NULL DEFAULT 'OTHER',
  menu_level VARCHAR(120) NULL,
  adult_rate DECIMAL(18,2) NULL,
  child_rate DECIMAL(18,2) NULL,
  beverage_included TINYINT(1) NOT NULL DEFAULT 0,
  guide_meal_included TINYINT(1) NOT NULL DEFAULT 0,
  guide_meal_rate DECIMAL(18,2) NULL,
  notes VARCHAR(1000) NULL,
  CONSTRAINT fk_meal_rule_version FOREIGN KEY(rate_version_id) REFERENCES rate_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_date_periods (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rate_version_id BIGINT UNSIGNED NOT NULL,
  period_type ENUM('SEASON','BLACKOUT','PEAK','HOLIDAY') NOT NULL,
  name VARCHAR(190) NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  adjustment_type ENUM('NONE','FIXED','PERCENT') NOT NULL DEFAULT 'NONE',
  adjustment_value DECIMAL(18,4) NULL,
  notes VARCHAR(1000) NULL,
  KEY idx_rate_period_version(rate_version_id,period_type,start_date,end_date),
  CONSTRAINT fk_rate_period_version FOREIGN KEY(rate_version_id) REFERENCES rate_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_child_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rate_version_id BIGINT UNSIGNED NOT NULL,
  measure_type ENUM('AGE_YEARS','HEIGHT_CM','TEXT') NOT NULL DEFAULT 'TEXT',
  label VARCHAR(190) NULL,
  min_value DECIMAL(10,2) NULL,
  max_value DECIMAL(10,2) NULL,
  price_mode ENUM('FREE','FIXED','PERCENT_ADULT','ADULT_RATE','TEXT_ONLY') NOT NULL DEFAULT 'TEXT_ONLY',
  price_value DECIMAL(18,4) NULL,
  notes VARCHAR(1000) NULL,
  KEY idx_child_rule_version(rate_version_id,measure_type),
  CONSTRAINT fk_child_rule_version FOREIGN KEY(rate_version_id) REFERENCES rate_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_foc_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rate_version_id BIGINT UNSIGNED NOT NULL,
  paying_pax_threshold INT UNSIGNED NOT NULL,
  foc_units INT UNSIGNED NOT NULL DEFAULT 1,
  max_foc INT UNSIGNED NULL,
  applies_to VARCHAR(120) NULL,
  conditions VARCHAR(1000) NULL,
  KEY idx_foc_rule_version(rate_version_id,paying_pax_threshold),
  CONSTRAINT fk_foc_rule_version FOREIGN KEY(rate_version_id) REFERENCES rate_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE rate_import_batches
  MODIFY status ENUM('PREVIEW','COMMITTED','PARTIAL','RECONCILED','FAILED') NOT NULL DEFAULT 'PREVIEW';

ALTER TABLE rate_import_rows
  MODIFY row_status ENUM('READY','WARNING','CREATED','SKIPPED','ERROR','RECONCILED') NOT NULL DEFAULT 'READY',
  ADD COLUMN resolved_by BIGINT UNSIGNED NULL AFTER created_rate_id,
  ADD COLUMN resolved_at DATETIME NULL AFTER resolved_by,
  ADD COLUMN resolution_note VARCHAR(500) NULL AFTER resolved_at,
  ADD CONSTRAINT fk_import_row_resolved_by FOREIGN KEY(resolved_by) REFERENCES users(id);

INSERT IGNORE INTO permissions(code,name) VALUES
('rate.reconcile','Reconcile rate import rows'),
('rate.compare','Compare supplier contract rates');

INSERT INTO schema_migrations(version) VALUES ('003_phase1_contracting_rules_reconciliation')
ON DUPLICATE KEY UPDATE version=VALUES(version);
