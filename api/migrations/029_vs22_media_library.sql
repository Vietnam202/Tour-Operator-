-- Additive media metadata only. Existing quote, itinerary, costing and snapshots are untouched.
CREATE TABLE media_assets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 owner_id BIGINT UNSIGNED NOT NULL,
 quote_id BIGINT UNSIGNED NULL,
 visibility VARCHAR(16) NOT NULL DEFAULT 'PERSONAL',
 status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
 source_type VARCHAR(24) NOT NULL DEFAULT 'PC',
 title VARCHAR(255) NOT NULL,
 original_filename VARCHAR(255) NOT NULL,
 mime_type VARCHAR(64) NOT NULL,
 original_path VARCHAR(1000) NOT NULL,
 storage_path VARCHAR(1000) NOT NULL,
 thumbnail_path VARCHAR(1000) NOT NULL,
 original_sha256 CHAR(64) NOT NULL,
 content_sha256 CHAR(64) NOT NULL,
 width INT UNSIGNED NOT NULL,
 height INT UNSIGNED NOT NULL,
 original_width INT UNSIGNED NOT NULL,
 original_height INT UNSIGNED NOT NULL,
 byte_size BIGINT UNSIGNED NOT NULL,
 original_size BIGINT UNSIGNED NOT NULL,
 orientation VARCHAR(16) NOT NULL,
 destination VARCHAR(190) NOT NULL DEFAULT '',
 category VARCHAR(64) NOT NULL DEFAULT '',
 service_type VARCHAR(32) NOT NULL DEFAULT '',
 property_reference VARCHAR(190) NOT NULL DEFAULT '',
 tags_json JSON NOT NULL,
 markets_json JSON NOT NULL,
 supersedes_id BIGINT UNSIGNED NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_media_library (company_id,status,visibility,destination),
 KEY idx_media_duplicate (company_id,original_sha256),
 FOREIGN KEY (company_id) REFERENCES companies(id),
 FOREIGN KEY (owner_id) REFERENCES users(id),
 FOREIGN KEY (quote_id) REFERENCES quotes(id),
 FOREIGN KEY (supersedes_id) REFERENCES media_assets(id),
 FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media_favourites (
 asset_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (asset_id,user_id),
 FOREIGN KEY (asset_id) REFERENCES media_assets(id),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media_drive_folders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 folder_id VARCHAR(200) NOT NULL,
 destination VARCHAR(190) NOT NULL DEFAULT '',
 category VARCHAR(64) NOT NULL DEFAULT '',
 tags_json JSON NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_vs22_drive_folder (company_id,folder_id),
 FOREIGN KEY (company_id) REFERENCES companies(id),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media_drive_links (
 asset_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 mapping_id BIGINT UNSIGNED NOT NULL,
 drive_file_id VARCHAR(200) NOT NULL,
 source_modified_time VARCHAR(64) NOT NULL,
 source_filename VARCHAR(255) NOT NULL,
 linked TINYINT UNSIGNED NOT NULL DEFAULT 0,
 sync_status VARCHAR(24) NOT NULL DEFAULT 'CURRENT',
 last_checked_at DATETIME NULL,
 FOREIGN KEY (asset_id) REFERENCES media_assets(id),
 FOREIGN KEY (mapping_id) REFERENCES media_drive_folders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media_assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 quote_version_id BIGINT UNSIGNED NOT NULL,
 asset_id BIGINT UNSIGNED NOT NULL,
 role VARCHAR(32) NOT NULL,
 day_key VARCHAR(80) NOT NULL DEFAULT '',
 reference_key VARCHAR(190) NOT NULL DEFAULT '',
 caption VARCHAR(500) NOT NULL DEFAULT '',
 sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 UNIQUE KEY uq_vs22_assignment (quote_version_id,asset_id,role,day_key,reference_key),
 FOREIGN KEY (quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY (asset_id) REFERENCES media_assets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions(code,name) VALUES
 ('media.view','View media library'),
 ('media.manage','Upload and manage own media'),
 ('media.approve','Approve company media');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT rp.role_id,np.id FROM role_permissions rp JOIN permissions op ON op.id=rp.permission_id
 JOIN permissions np ON np.code IN ('media.view','media.manage') WHERE op.code='quote.edit';
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code IN ('media.view','media.manage','media.approve') WHERE r.code IN ('ADMIN','CEO');
