-- VS2.1: no historical snapshot updates; widen variant identity without removing data.
ALTER TABLE quote_options
 ADD COLUMN variant_key VARCHAR(80) NOT NULL DEFAULT '',
 ADD COLUMN costing_mode ENUM('PRIVATE','SIC','HYBRID') NULL,
 ADD COLUMN cruise_level ENUM('3*','4*','5*') NULL,
 DROP INDEX uq_option_level,
 ADD UNIQUE KEY uq_option_variant(quote_version_id,hotel_level,variant_key);

CREATE TABLE IF NOT EXISTS quote_guest_profiles (
 quote_version_id BIGINT UNSIGNED PRIMARY KEY,
 visa_pax INT UNSIGNED NULL,
 meal_pax INT UNSIGNED NULL,
 hotel_pax INT UNSIGNED NULL,
 ticket_pax INT UNSIGNED NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_service_requirements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 quote_version_id BIGINT UNSIGNED NOT NULL,
 requirement_key VARCHAR(80) NOT NULL,
 sort_order INT UNSIGNED NOT NULL,
 category VARCHAR(32) NOT NULL,
 service_name VARCHAR(255) NOT NULL,
 service_date DATE NOT NULL,
 quantity INT UNSIGNED NOT NULL DEFAULT 1,
 service_mode ENUM('PRIVATE','SIC','BOTH') NOT NULL DEFAULT 'BOTH',
 scope VARCHAR(120) NOT NULL DEFAULT 'TOUR',
 metadata_json JSON NOT NULL,
 UNIQUE KEY uq_requirement(quote_version_id,requirement_key),
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
