ALTER TABLE marketing_content
 ADD COLUMN topic VARCHAR(96) NOT NULL DEFAULT 'General',
 ADD COLUMN content_format VARCHAR(32) NOT NULL DEFAULT 'POST',
 ADD COLUMN tags VARCHAR(500) NOT NULL DEFAULT '',
 ADD COLUMN asset_url VARCHAR(1000) NOT NULL DEFAULT '',
 ADD COLUMN rights_note VARCHAR(500) NOT NULL DEFAULT '',
 ADD COLUMN planned_at DATETIME NULL,
 ADD COLUMN parent_content_id BIGINT UNSIGNED NULL,
 ADD KEY idx_content_calendar(company_id,planned_at);

CREATE TABLE IF NOT EXISTS marketing_library (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL,
 topic VARCHAR(96) NOT NULL,
 body TEXT NOT NULL,
 tags VARCHAR(500) NOT NULL DEFAULT '',
 asset_url VARCHAR(1000) NOT NULL DEFAULT '',
 rights_note VARCHAR(500) NOT NULL DEFAULT '',
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(created_by) REFERENCES users(id),
 KEY idx_library_topic(company_id,topic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_batches (
 company_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 result_json JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(company_id,request_key),
 FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_inbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 campaign_id BIGINT UNSIGNED NOT NULL,
 channel VARCHAR(40) NOT NULL,
 contact_name VARCHAR(190) NOT NULL,
 email VARCHAR(190) NOT NULL DEFAULT '',
 phone VARCHAR(64) NOT NULL DEFAULT '',
 message TEXT NOT NULL,
 reply_draft TEXT NOT NULL,
 status ENUM('NEW','FOLLOW_UP','CLOSED') NOT NULL DEFAULT 'NEW',
 owner_user_id BIGINT UNSIGNED NOT NULL,
 lead_request_id BIGINT UNSIGNED NULL,
 version_no INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(company_id,campaign_id) REFERENCES campaigns(company_id,id),
 FOREIGN KEY(company_id,lead_request_id) REFERENCES lead_requests(company_id,id),
 FOREIGN KEY(owner_user_id) REFERENCES users(id),
 FOREIGN KEY(created_by) REFERENCES users(id),
 KEY idx_marketing_inbox(company_id,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
