-- RC6 B2B Partner Hub: additive publishing, agency workspaces, request history.
-- Only a reviewed locked quotation may supply published NET amounts.
CREATE TABLE IF NOT EXISTS b2b_agencies (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 agency_name VARCHAR(190) NOT NULL,
 brand_name VARCHAR(190) NOT NULL,
 brand_color CHAR(7) NOT NULL DEFAULT '#1768B0',
 email VARCHAR(190) NOT NULL DEFAULT '',
 whatsapp VARCHAR(64) NOT NULL DEFAULT '',
 logo_path VARCHAR(2048) NULL,
 logo_mime VARCHAR(40) NULL,
 logo_sha256 CHAR(64) NULL,
 status ENUM('ACTIVE','DISABLED') NOT NULL DEFAULT 'ACTIVE',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_b2b_agency_scope(company_id,id),
 KEY idx_b2b_agency_name(company_id,agency_name),
 FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_agency_members (
 company_id BIGINT UNSIGNED NOT NULL,
 agency_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(company_id,agency_id,user_id),
 KEY idx_b2b_member_user(company_id,user_id),
 FOREIGN KEY(company_id,agency_id) REFERENCES b2b_agencies(company_id,id),
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_publications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 program_id BIGINT UNSIGNED NOT NULL,
 version_no INT UNSIGNED NOT NULL,
 content_json JSON NOT NULL,
 rate_json JSON NOT NULL,
 source_quote_version_id BIGINT UNSIGNED NULL,
 status ENUM('PUBLISHED','REVOKED') NOT NULL DEFAULT 'PUBLISHED',
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_b2b_program_version(company_id,program_id,version_no),
 UNIQUE KEY uq_b2b_publication_scope(company_id,id),
 KEY idx_b2b_publication_status(company_id,status,created_at),
 FOREIGN KEY(company_id,program_id) REFERENCES tour_library_programs(company_id,id),
 FOREIGN KEY(source_quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_partner_tours (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 agency_id BIGINT UNSIGNED NOT NULL,
 publication_id BIGINT UNSIGNED NOT NULL,
 content_json JSON NOT NULL,
 markup_type ENUM('PERCENT','FIXED') NOT NULL DEFAULT 'PERCENT',
 markup_value DECIMAL(12,2) NOT NULL DEFAULT 0,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_b2b_working_scope(company_id,agency_id,id),
 KEY idx_b2b_working_company(company_id,agency_id,updated_at),
 FOREIGN KEY(company_id,agency_id) REFERENCES b2b_agencies(company_id,id),
 FOREIGN KEY(company_id,publication_id) REFERENCES b2b_publications(company_id,id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2b_booking_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 agency_id BIGINT UNSIGNED NOT NULL,
 partner_tour_id BIGINT UNSIGNED NOT NULL,
 departure_date DATE NOT NULL,
 paying_pax INT UNSIGNED NOT NULL,
 guest_name VARCHAR(190) NOT NULL DEFAULT '',
 guest_email VARCHAR(190) NOT NULL DEFAULT '',
 notes TEXT NOT NULL,
 client_snapshot_json JSON NOT NULL,
 status ENUM('REQUESTED','IN_REVIEW','CONFIRMED','REJECTED') NOT NULL DEFAULT 'REQUESTED',
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_b2b_booking_scope(company_id,agency_id,id),
 FOREIGN KEY(company_id,agency_id,partner_tour_id) REFERENCES b2b_partner_tours(company_id,agency_id,id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO permissions(code,name) VALUES
 ('b2b.admin','Administer B2B publishing and partners'),
 ('b2b.portal','Use assigned B2B agency workspace');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='b2b.admin'
 WHERE r.code='ADMIN';
INSERT IGNORE INTO roles(company_id,code,name,is_system)
 SELECT c.id,'PARTNER','B2B Agency',1 FROM companies c;
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='b2b.portal'
 WHERE r.code='PARTNER';
