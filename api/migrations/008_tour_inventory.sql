CREATE TABLE IF NOT EXISTS tour_programs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(64) NOT NULL,
 name VARCHAR(190) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_program_code(company_id,code),
 UNIQUE KEY uq_program_tenant(company_id,id),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tour_program_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 program_id BIGINT UNSIGNED NOT NULL,
 version_no INT UNSIGNED NOT NULL,
 status ENUM('DRAFT','PUBLISHED') NOT NULL DEFAULT 'DRAFT',
 title VARCHAR(190) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 published_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_program_version(program_id,version_no),
 FOREIGN KEY(program_id) REFERENCES tour_programs(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tour_days (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 program_version_id BIGINT UNSIGNED NOT NULL,
 day_no INT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL,
 itinerary TEXT NOT NULL,
 overnight VARCHAR(190) NULL,
 meals VARCHAR(190) NULL,
 UNIQUE KEY uq_tour_day(program_version_id,day_no),
 FOREIGN KEY(program_version_id) REFERENCES tour_program_versions(id),
 CHECK(day_no>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tour_components (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(190) NOT NULL,
 category ENUM('HOTEL','TRANSPORT','GUIDE','CRUISE','ATTRACTION','MEAL','TOUR','VISA','OTHER') NOT NULL,
 destination VARCHAR(160) NOT NULL,
 customer_description TEXT NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY uq_component_tenant(company_id,id),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tour_service_blueprints (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 tour_day_id BIGINT UNSIGNED NOT NULL,
 component_id BIGINT UNSIGNED NOT NULL,
 component_snapshot_json JSON NOT NULL,
 quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
 pax_basis ENUM('TOTAL_GUESTS','PAYING_PAX','ONE') NOT NULL,
 FOREIGN KEY(tour_day_id) REFERENCES tour_days(id),
 FOREIGN KEY(component_id) REFERENCES tour_components(id),
 CHECK(quantity>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tour_variants (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 program_version_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(190) NOT NULL,
 market VARCHAR(120) NULL,
 agent_id BIGINT UNSIGNED NULL,
 hotel_level ENUM('3*','4*','5*') NOT NULL,
 meals VARCHAR(190) NULL,
 payment_terms TEXT NULL,
 FOREIGN KEY(program_version_id) REFERENCES tour_program_versions(id),
 FOREIGN KEY(agent_id) REFERENCES agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_program_sources (
 quote_version_id BIGINT UNSIGNED PRIMARY KEY,
 program_version_id BIGINT UNSIGNED NOT NULL,
 variant_id BIGINT UNSIGNED NULL,
 snapshot_json JSON NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY(program_version_id) REFERENCES tour_program_versions(id),
 FOREIGN KEY(variant_id) REFERENCES tour_variants(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions(code,name) VALUES('product.manage','Manage tour inventory'),('product.view','View tour inventory');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code IN ('ADMIN','PRODUCT') AND p.code IN ('product.manage','product.view');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='SALES' AND p.code='product.view';
