-- Additive migration: preserves the v2.4 sales and booking identities.
CREATE TABLE IF NOT EXISTS campaigns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(190) NOT NULL,
 source VARCHAR(80) NOT NULL,
 status ENUM('ACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_campaign_tenant(company_id,id),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_forms (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 campaign_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(190) NOT NULL,
 public_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 landing_url VARCHAR(1000) NULL,
 status ENUM('ACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_form_token(public_token),
 UNIQUE KEY uq_form_tenant(company_id,id),
 FOREIGN KEY(company_id,campaign_id) REFERENCES campaigns(company_id,id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 form_id BIGINT UNSIGNED NULL,
 campaign_id BIGINT UNSIGNED NULL,
 source VARCHAR(80) NOT NULL,
 submission_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 contact_name VARCHAR(190) NOT NULL,
 email VARCHAR(190) NULL,
 phone VARCHAR(64) NULL,
 travel_date DATE NULL,
 total_guests INT UNSIGNED NOT NULL,
 paying_pax INT UNSIGNED NOT NULL,
 foc INT UNSIGNED NOT NULL DEFAULT 0,
 destination VARCHAR(500) NULL,
 hotel_level VARCHAR(32) NULL,
 message TEXT NULL,
 attribution_json JSON NOT NULL,
 status ENUM('NEW','QUALIFIED','REJECTED') NOT NULL DEFAULT 'NEW',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_request_submission(company_id,submission_key),
 UNIQUE KEY uq_request_tenant(company_id,id),
 KEY idx_request_queue(company_id,status,created_at),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(company_id,form_id) REFERENCES lead_forms(company_id,id),
 FOREIGN KEY(company_id,campaign_id) REFERENCES campaigns(company_id,id),
 CHECK(total_guests > 0 AND paying_pax > 0 AND paying_pax + foc <= total_guests)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 request_id BIGINT UNSIGNED NOT NULL,
 owner_user_id BIGINT UNSIGNED NOT NULL,
 qualification_note TEXT NOT NULL,
 inquiry_id BIGINT UNSIGNED NULL,
 status ENUM('QUALIFIED','CONVERTED') NOT NULL DEFAULT 'QUALIFIED',
 qualified_by BIGINT UNSIGNED NOT NULL,
 qualified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 converted_at DATETIME NULL,
 UNIQUE KEY uq_lead_request(request_id),
 UNIQUE KEY uq_lead_inquiry(inquiry_id),
 KEY idx_lead_tenant(company_id,status),
 FOREIGN KEY(company_id,request_id) REFERENCES lead_requests(company_id,id),
 FOREIGN KEY(owner_user_id) REFERENCES users(id),
 FOREIGN KEY(qualified_by) REFERENCES users(id),
 FOREIGN KEY(inquiry_id) REFERENCES inquiries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_submission_windows (
 form_id BIGINT UNSIGNED NOT NULL,
 window_start DATETIME NOT NULL,
 submissions INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(form_id,window_start),
 FOREIGN KEY(form_id) REFERENCES lead_forms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions(code,name) VALUES
 ('lead.view','View lead requests and attribution'),
 ('lead.manage','Qualify and convert lead requests'),
 ('campaign.manage','Manage campaigns and lead forms');

INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code IN ('ADMIN','SALES') AND p.code IN ('lead.view','lead.manage','campaign.manage');
