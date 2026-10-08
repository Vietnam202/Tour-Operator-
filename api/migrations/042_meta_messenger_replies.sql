-- P8: provider timestamp to enforce the Messenger standard reply window.
-- Missing provider timestamp remains NULL and cannot authorize an outbound reply.
ALTER TABLE social_meta_inbound_jobs ADD COLUMN provider_timestamp_ms BIGINT UNSIGNED NULL AFTER message_text;
ALTER TABLE social_conversations ADD COLUMN last_meta_inbound_at DATETIME NULL AFTER last_message_at;

-- P8: staff-initiated Messenger replies, no access tokens or provider credentials in DB.
CREATE TABLE IF NOT EXISTS marketing_meta_outbound (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 conversation_id BIGINT UNSIGNED NOT NULL,
 source_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 message_text VARCHAR(1200) NOT NULL,
 status ENUM('QUEUED','SENDING','SENT','UNCERTAIN','BLOCKED') NOT NULL DEFAULT 'QUEUED',
 created_by BIGINT UNSIGNED NOT NULL,
 provider_message_id VARCHAR(256) NULL,
 sent_at DATETIME NULL,
 claimed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 status_detail VARCHAR(160) NULL,
 UNIQUE KEY uq_meta_reply_request(company_id,request_key),
 UNIQUE KEY uq_meta_reply_tenant(company_id,id),
 KEY idx_meta_reply_queue(status,id),
 KEY idx_meta_reply_thread(company_id,conversation_id,id),
 FOREIGN KEY(company_id,conversation_id) REFERENCES social_conversations(company_id,id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
