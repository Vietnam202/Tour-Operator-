CREATE TABLE IF NOT EXISTS tour_library_programs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL,
 destination VARCHAR(190) NOT NULL DEFAULT '',
 language VARCHAR(16) NOT NULL DEFAULT 'en',
 tags_json JSON NOT NULL,
 days_json JSON NOT NULL,
 included_text MEDIUMTEXT NOT NULL,
 excluded_text MEDIUMTEXT NOT NULL,
 terms_text MEDIUMTEXT NOT NULL,
 source_text MEDIUMTEXT NOT NULL,
 source_name VARCHAR(255) NOT NULL DEFAULT '',
 source_type ENUM('PC','GOOGLE_DRIVE','TEXT','MANUAL') NOT NULL DEFAULT 'MANUAL',
 source_url VARCHAR(2048) NOT NULL DEFAULT '',
 source_storage_path VARCHAR(2048) NULL,
 source_mime VARCHAR(100) NULL,
 source_sha256 CHAR(64) NULL,
 source_size INT UNSIGNED NULL,
 status ENUM('DRAFT','ACTIVE','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
 creation_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
 creation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_tour_library_company(company_id,id),
 UNIQUE KEY uq_tour_library_creation(company_id,creation_key),
 KEY idx_tour_library_status(company_id,status,updated_at),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(created_by) REFERENCES users(id),
 FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tour_library_imports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_name VARCHAR(255) NOT NULL,
 source_type ENUM('PC','GOOGLE_DRIVE') NOT NULL,
 source_url VARCHAR(2048) NOT NULL DEFAULT '',
 storage_path VARCHAR(2048) NOT NULL,
 mime_type VARCHAR(100) NOT NULL,
 source_sha256 CHAR(64) NOT NULL,
 source_size INT UNSIGNED NOT NULL,
 expires_at DATETIME NOT NULL,
 consumed_program_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_tour_import_token(token_hash),
 KEY idx_tour_import_scope(company_id,user_id,expires_at),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(company_id,consumed_program_id) REFERENCES tour_library_programs(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tour_library_quote_sources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 quote_version_id BIGINT UNSIGNED NOT NULL,
 program_id BIGINT UNSIGNED NOT NULL,
 snapshot_json JSON NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_tour_library_quote(quote_version_id),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id) ON DELETE CASCADE,
 FOREIGN KEY(company_id,program_id) REFERENCES tour_library_programs(company_id,id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO permissions(code,name) VALUES
 ('tour_library.view','View tour program library'),
 ('tour_library.manage','Manage tour program library');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='tour_library.view' WHERE r.code IN ('ADMIN','PRODUCT','SALES','OPERATIONS');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='tour_library.manage' WHERE r.code IN ('ADMIN','PRODUCT');
