-- 024: First-party website conversations (additive, staging first).
-- No social provider tokens, outbound provider messages, or AI auto-send.
CREATE TABLE IF NOT EXISTS social_conversations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 campaign_id BIGINT UNSIGNED NOT NULL,
 source_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 external_conversation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 contact_name VARCHAR(190) NOT NULL DEFAULT 'Website visitor',
 email VARCHAR(190) NOT NULL DEFAULT '',
 phone VARCHAR(64) NOT NULL DEFAULT '',
 attribution_json JSON NULL,
 status ENUM('NEW','FOLLOW_UP','CLOSED') NOT NULL DEFAULT 'NEW',
 reply_draft TEXT NOT NULL,
 owner_user_id BIGINT UNSIGNED NULL,
 lead_request_id BIGINT UNSIGNED NULL,
 version_no INT UNSIGNED NOT NULL DEFAULT 1,
 last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_social_conversation_company(company_id,id),
 UNIQUE KEY uq_social_external_thread(company_id,source_code,external_conversation_id),
 KEY idx_social_queue(company_id,status,last_message_at,id),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(company_id,campaign_id) REFERENCES campaigns(company_id,id),
 FOREIGN KEY(company_id,lead_request_id) REFERENCES lead_requests(company_id,id),
 FOREIGN KEY(owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 conversation_id BIGINT UNSIGNED NOT NULL,
 external_message_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 direction ENUM('INBOUND') NOT NULL DEFAULT 'INBOUND',
 body TEXT NOT NULL,
 received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_social_message(company_id,conversation_id,external_message_id),
 KEY idx_social_message_history(company_id,conversation_id,id),
 FOREIGN KEY(company_id,conversation_id) REFERENCES social_conversations(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_webhook_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 source_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 conversation_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_social_webhook(company_id,source_code,event_key),
 KEY idx_social_webhook_recent(company_id,id),
 FOREIGN KEY(company_id,conversation_id) REFERENCES social_conversations(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backpressure for untrusted traffic entering the trusted website relay.
CREATE TABLE IF NOT EXISTS social_webhook_rate_windows (
 company_id BIGINT UNSIGNED NOT NULL,
 source_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 scope_type ENUM('SOURCE','THREAD') NOT NULL,
 scope_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 window_start DATETIME NOT NULL,
 accepted_count INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(company_id,source_code,scope_type,scope_key,window_start),
 FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
