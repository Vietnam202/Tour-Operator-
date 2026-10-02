CREATE TABLE IF NOT EXISTS marketing_briefs (
 company_id BIGINT UNSIGNED NOT NULL,
 campaign_id BIGINT UNSIGNED NOT NULL,
 market VARCHAR(120) NOT NULL DEFAULT 'India',
 offer TEXT NOT NULL,
 audience TEXT NOT NULL,
 budget DECIMAL(14,2) NOT NULL DEFAULT 0,
 currency CHAR(3) NOT NULL DEFAULT 'INR',
 PRIMARY KEY(company_id,campaign_id),
 FOREIGN KEY(company_id,campaign_id) REFERENCES campaigns(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_content (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 campaign_id BIGINT UNSIGNED NOT NULL,
 channel VARCHAR(40) NOT NULL,
 body TEXT NOT NULL,
 status ENUM('DRAFT','PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'DRAFT',
 created_by BIGINT UNSIGNED NOT NULL,
 decided_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 decided_at DATETIME NULL,
 FOREIGN KEY(company_id,campaign_id) REFERENCES campaigns(company_id,id),
 FOREIGN KEY(created_by) REFERENCES users(id),
 FOREIGN KEY(decided_by) REFERENCES users(id),
 KEY idx_marketing_queue(company_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO permissions(code,name) VALUES ('marketing.approve','Approve marketing content');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='ADMIN' AND p.code='marketing.approve';
