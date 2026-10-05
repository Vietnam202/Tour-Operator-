-- Existing versions remain VS1; issued JSON is untouched.
ALTER TABLE quote_versions ADD COLUMN costing_engine VARCHAR(16) NOT NULL DEFAULT 'VS1', ADD COLUMN costing_revision BIGINT UNSIGNED NOT NULL DEFAULT 0;

-- VS2.1 additive children. Supported baseline: 001-022; no backfill.

CREATE TABLE quote_guest_profiles (
 quote_version_id BIGINT UNSIGNED NOT NULL,
 hotel_pax INT UNSIGNED NULL,
 cruise_pax INT UNSIGNED NULL,
 visa_pax INT UNSIGNED NULL,
 meal_pax INT UNSIGNED NULL,
 ticket_pax INT UNSIGNED NULL,
 review_metadata_json JSON NOT NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (quote_version_id),
 FOREIGN KEY (quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quote_service_requirements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 quote_version_id BIGINT UNSIGNED NOT NULL,
 requirement_key VARCHAR(80) NOT NULL,
 day_key VARCHAR(80) NULL,
 sort_order INT UNSIGNED NOT NULL,
 category VARCHAR(32) NOT NULL,
 service_name VARCHAR(255) NOT NULL,
 service_date DATE NULL,
 service_end_date DATE NULL,
 service_units INT UNSIGNED NULL,
 default_quantity_source VARCHAR(32) NOT NULL,
 service_mode VARCHAR(16) NOT NULL DEFAULT 'BOTH',
 requirement_state VARCHAR(24) NOT NULL DEFAULT 'NEEDS_REVIEW',
 scope_json JSON NOT NULL,
 package_requirement_id BIGINT UNSIGNED NULL,
 package_component_key VARCHAR(80) NULL,
 metadata_json JSON NOT NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_vs21_requirement (quote_version_id,requirement_key),
 FOREIGN KEY (quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY (package_requirement_id) REFERENCES quote_service_requirements(id),
 FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

