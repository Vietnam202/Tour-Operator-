-- 041: signed Meta page/Instagram webhook messages waiting for the first-party inbox.
-- No access tokens or raw webhook JSON are stored. No outbound provider messaging.
CREATE TABLE IF NOT EXISTS social_meta_inbound_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 campaign_id BIGINT UNSIGNED NOT NULL,
 source_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 platform ENUM('FACEBOOK_MESSENGER','INSTAGRAM_DM') NOT NULL,
 event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 sender_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 message_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 message_text TEXT NOT NULL,
 status ENUM('PENDING','PROCESSING','DONE','FAILED') NOT NULL DEFAULT 'PENDING',
 attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
 claimed_at DATETIME NULL,
 conversation_id BIGINT UNSIGNED NULL,
 last_error VARCHAR(120) NULL,
 received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 processed_at DATETIME NULL,
 UNIQUE KEY uq_meta_inbound_event(company_id,source_code,event_key),
 KEY idx_meta_inbound_queue(status,received_at,id),
 KEY idx_meta_inbound_company(company_id,status,id),
 FOREIGN KEY(company_id,campaign_id) REFERENCES campaigns(company_id,id),
 FOREIGN KEY(company_id,conversation_id) REFERENCES social_conversations(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
