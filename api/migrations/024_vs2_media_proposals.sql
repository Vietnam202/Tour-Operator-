-- VS2.2 additive entities only. Historical quote snapshots are never rewritten.
CREATE TABLE IF NOT EXISTS media_drive_folders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 folder_id VARCHAR(200) NOT NULL,
 destination VARCHAR(190) NOT NULL DEFAULT '',
 category VARCHAR(64) NOT NULL DEFAULT '',
 tags_json JSON NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_media_folder(company_id,folder_id),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_assets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 owner_id BIGINT UNSIGNED NOT NULL,
 visibility ENUM('PERSONAL','COMPANY','SYNCED') NOT NULL DEFAULT 'PERSONAL',
 status ENUM('DRAFT','APPROVED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
 title VARCHAR(190) NOT NULL,
 destination VARCHAR(190) NOT NULL DEFAULT '',
 category VARCHAR(64) NOT NULL DEFAULT '',
 tags_json JSON NOT NULL,
 original_sha256 CHAR(64) NOT NULL,
 content_sha256 CHAR(64) NOT NULL,
 storage_path VARCHAR(1024) NOT NULL,
 thumbnail_path VARCHAR(1024) NOT NULL,
 width INT UNSIGNED NOT NULL,
 height INT UNSIGNED NOT NULL,
 byte_size INT UNSIGNED NOT NULL,
 source_type ENUM('PC','GOOGLE_DRIVE') NOT NULL DEFAULT 'PC',
 drive_file_id VARCHAR(200) NULL,
 drive_folder_id BIGINT UNSIGNED NULL,
 source_modified_time VARCHAR(64) NULL,
 linked TINYINT NOT NULL DEFAULT 0,
 supersedes_id BIGINT UNSIGNED NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX ix_media_company(company_id,status,visibility),
 INDEX ix_media_duplicate(company_id,original_sha256),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(owner_id) REFERENCES users(id),
 FOREIGN KEY(drive_folder_id) REFERENCES media_drive_folders(id),
 FOREIGN KEY(supersedes_id) REFERENCES media_assets(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_favourites (
 user_id BIGINT UNSIGNED NOT NULL,
 asset_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(user_id,asset_id),
 FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(asset_id) REFERENCES media_assets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_visual_days (
 quote_version_id BIGINT UNSIGNED NOT NULL,
 day_key VARCHAR(80) NOT NULL,
 sort_order INT UNSIGNED NOT NULL,
 schedule_signature CHAR(64) NOT NULL,
 fields_json JSON NOT NULL,
 PRIMARY KEY(quote_version_id,day_key),
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_media_links (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 quote_version_id BIGINT UNSIGNED NOT NULL,
 asset_id BIGINT UNSIGNED NOT NULL,
 role ENUM('COVER','DAY_HERO','DAY_GALLERY','HOTEL','CRUISE','SERVICE') NOT NULL,
 day_key VARCHAR(80) NOT NULL DEFAULT '',
 caption VARCHAR(500) NOT NULL DEFAULT '',
 sort_order INT UNSIGNED NOT NULL,
 UNIQUE KEY uq_quote_media(quote_version_id,role,day_key,asset_id),
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY(asset_id) REFERENCES media_assets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_proposals (
 quote_version_id BIGINT UNSIGNED PRIMARY KEY,
 settings_json JSON NOT NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_proposal_links (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 quote_version_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY uq_proposal_token(token_hash),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions(code,name) VALUES
 ('media.view','View VTA media library'),
 ('media.upload','Upload personal or draft media'),
 ('media.review','Approve/archive company media and map Drive folders');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT rp.role_id,pnew.id FROM role_permissions rp JOIN permissions pold ON pold.id=rp.permission_id JOIN permissions pnew ON pnew.code=CASE pold.code WHEN 'document.view' THEN 'media.view' WHEN 'document.upload' THEN 'media.upload' WHEN 'document.review' THEN 'media.review' END WHERE pold.code IN ('document.view','document.upload','document.review');
INSERT IGNORE INTO user_permissions(user_id,permission_id,effect)
 SELECT up.user_id,pnew.id,up.effect FROM user_permissions up JOIN permissions pold ON pold.id=up.permission_id JOIN permissions pnew ON pnew.code=CASE pold.code WHEN 'document.view' THEN 'media.view' WHEN 'document.upload' THEN 'media.upload' WHEN 'document.review' THEN 'media.review' END WHERE pold.code IN ('document.view','document.upload','document.review');
