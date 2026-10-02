-- VS1: additive handover and internal event execution; existing CRM identities stay intact.
ALTER TABLE lead_requests ADD COLUMN version_no INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE leads
 ADD COLUMN handover_status ENUM('PENDING','ACCEPTED','RETURNED','CONVERTED') NOT NULL DEFAULT 'PENDING',
 ADD COLUMN handover_version INT UNSIGNED NOT NULL DEFAULT 1,
 ADD COLUMN sales_owner_user_id BIGINT UNSIGNED NULL,
 ADD COLUMN next_action_due DATETIME NULL,
 ADD COLUMN accepted_by BIGINT UNSIGNED NULL,
 ADD COLUMN accepted_at DATETIME NULL,
 ADD COLUMN returned_by BIGINT UNSIGNED NULL,
 ADD COLUMN returned_at DATETIME NULL,
 ADD COLUMN return_reason TEXT NULL,
 ADD KEY idx_lead_handover(company_id,handover_status,next_action_due),
 ADD UNIQUE KEY uq_lead_handover_tenant(company_id,id),
 ADD FOREIGN KEY(sales_owner_user_id) REFERENCES users(id),
 ADD FOREIGN KEY(accepted_by) REFERENCES users(id),
 ADD FOREIGN KEY(returned_by) REFERENCES users(id);
-- Historical conversions replay their existing inquiry; no new customer or task is inferred.
UPDATE leads SET handover_status='CONVERTED' WHERE status='CONVERTED' OR inquiry_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS lead_sales_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 lead_id BIGINT UNSIGNED NOT NULL,
 handover_version INT UNSIGNED NOT NULL,
 action_code VARCHAR(48) NOT NULL,
 from_status VARCHAR(24) NULL,
 to_status VARCHAR(24) NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 owner_user_id BIGINT UNSIGNED NOT NULL,
 next_action_due DATETIME NULL,
 reason TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_lead_sales_history(company_id,lead_id,handover_version),
 FOREIGN KEY(company_id,lead_id) REFERENCES leads(company_id,id),
 FOREIGN KEY(actor_user_id) REFERENCES users(id),
 FOREIGN KEY(owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_identity_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 lead_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 handover_version INT UNSIGNED NOT NULL,
 review_key CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 candidate_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_identity_review_key(review_key),
 KEY idx_identity_review_expiry(company_id,expires_at),
 FOREIGN KEY(company_id,lead_id) REFERENCES leads(company_id,id),
 FOREIGN KEY(actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS domain_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 event_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_name VARCHAR(96) NOT NULL,
 entity_type VARCHAR(48) NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 entity_version INT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NULL,
 payload_json JSON NOT NULL,
 status ENUM('PENDING','APPLIED') NOT NULL DEFAULT 'PENDING',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 applied_at DATETIME NULL,
 UNIQUE KEY uq_domain_event(company_id,event_key),
 KEY idx_domain_pending(company_id,status,id),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS domain_execution_keys (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 execution_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 execution_kind ENUM('COMMAND','TASK') NOT NULL,
 entity_type VARCHAR(48) NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 result_json JSON NOT NULL,
 event_id BIGINT UNSIGNED NULL,
 task_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_domain_execution(company_id,execution_key),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(actor_user_id) REFERENCES users(id),
 FOREIGN KEY(event_id) REFERENCES domain_outbox(id),
 FOREIGN KEY(task_id) REFERENCES tasks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions(code,name) VALUES ('lead.sales_accept','Accept or return a qualified lead for Sales');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code IN ('ADMIN','SALES') AND p.code='lead.sales_accept';
