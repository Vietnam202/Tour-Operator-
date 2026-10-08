-- P9: independent, read-only Meta connection diagnostics per VTA company/account.
-- Never store provider access tokens, app secret or OAuth authorization codes.
CREATE TABLE IF NOT EXISTS marketing_meta_connection_checks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 account_alias VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 product ENUM('MESSENGER','INSTAGRAM_DM','FACEBOOK_PAGE','INSTAGRAM_PUBLISH') NOT NULL,
 entity_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('VERIFIED','INVALID','MISSING_PERMISSIONS','EXPIRED','NOT_VERIFIED') NOT NULL,
 checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NULL,
 permissions_json JSON NOT NULL,
 error_code VARCHAR(64) NULL,
 UNIQUE KEY uq_meta_connection_company(company_id,account_alias,product),
 KEY idx_meta_connection_status(company_id,status,checked_at),
 FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
