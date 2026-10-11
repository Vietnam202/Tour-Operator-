CREATE TABLE IF NOT EXISTS b2c_programs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 master_program_id BIGINT UNSIGNED NOT NULL,
 content_json JSON NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 status ENUM('DRAFT','REVIEW','APPROVED','PUBLISHED','WITHDRAWN') NOT NULL DEFAULT 'DRAFT',
 approved_revision INT UNSIGNED NULL,
 approved_by BIGINT UNSIGNED NULL,
 creation_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_b2c_company_id(company_id,id),
 UNIQUE KEY uq_b2c_creation(company_id,creation_key),
 FOREIGN KEY(company_id,master_program_id) REFERENCES tour_library_programs(company_id,id),
 FOREIGN KEY(created_by) REFERENCES users(id),
 FOREIGN KEY(updated_by) REFERENCES users(id),
 FOREIGN KEY(approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2c_revisions (
 program_id BIGINT UNSIGNED NOT NULL,
 revision INT UNSIGNED NOT NULL,
 content_json JSON NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(program_id,revision),
 FOREIGN KEY(program_id) REFERENCES b2c_programs(id),
 FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2c_publication_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 program_id BIGINT UNSIGNED NOT NULL,
 revision INT UNSIGNED NOT NULL,
 status ENUM('PUBLISHED','WITHDRAWN') NOT NULL,
 snapshot_json JSON NOT NULL,
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_b2c_event_scope(company_id,id),
 FOREIGN KEY(company_id,program_id) REFERENCES b2c_programs(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS b2c_lead_links (
 company_id BIGINT UNSIGNED NOT NULL,
 lead_request_id BIGINT UNSIGNED NOT NULL,
 publication_event_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(company_id,lead_request_id),
 FOREIGN KEY(publication_event_id) REFERENCES b2c_publication_events(id),
 FOREIGN KEY(lead_request_id) REFERENCES lead_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO permissions(code,name) VALUES
 ('b2c.view','View B2C programmes'),('b2c.edit','Edit B2C working copies'),('b2c.approve','Approve and publish B2C programmes');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code IN ('b2c.view','b2c.edit') WHERE r.code IN ('ADMIN','PRODUCT','SALES');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='b2c.approve' WHERE r.code IN ('ADMIN','PRODUCT');
CREATE TABLE IF NOT EXISTS b2c_quote_sources (
 company_id BIGINT UNSIGNED NOT NULL,
 quote_version_id BIGINT UNSIGNED NOT NULL,
 program_id BIGINT UNSIGNED NOT NULL,
 program_revision INT UNSIGNED NOT NULL,
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(company_id,quote_version_id),
 FOREIGN KEY(company_id,program_id) REFERENCES b2c_programs(company_id,id),
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY(program_id,program_revision) REFERENCES b2c_revisions(program_id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
