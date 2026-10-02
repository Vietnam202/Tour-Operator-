CREATE TABLE IF NOT EXISTS marketing_landing_pages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 campaign_id BIGINT UNSIGNED NULL,
 client_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 title VARCHAR(190) NOT NULL,
 document_json JSON NOT NULL,
 version_no INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_landing_save(company_id,client_key),
 KEY idx_landing_updated(company_id,updated_at),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(company_id,campaign_id) REFERENCES campaigns(company_id,id),
 FOREIGN KEY(created_by) REFERENCES users(id),
 FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
