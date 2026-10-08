-- Additive staging-only P0 journal. No modification of existing Landing/CRM tables.
CREATE TABLE IF NOT EXISTS marketing_webhook_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 source_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_type VARCHAR(64) NOT NULL,
 lead_request_id BIGINT UNSIGNED NOT NULL,
 status ENUM('ACCEPTED') NOT NULL DEFAULT 'ACCEPTED',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_webhook_event(company_id,source_code,event_key),
 KEY idx_webhook_recent(company_id,created_at,id),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(company_id,lead_request_id) REFERENCES lead_requests(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
