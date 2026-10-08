-- 025: native website chat outbound transport; additive and tenant scoped.
-- RELAYED means the website backend acknowledged collection, NOT visitor read.
CREATE TABLE IF NOT EXISTS website_chat_outbound (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 conversation_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 body TEXT NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 status ENUM('QUEUED','RELAYED') NOT NULL DEFAULT 'QUEUED',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 relayed_at DATETIME NULL,
 UNIQUE KEY uq_website_chat_outbound(company_id,request_key),
 UNIQUE KEY uq_website_chat_outbound_tenant(company_id,id),
 KEY idx_website_chat_poll(company_id,conversation_id,id),
 FOREIGN KEY(company_id,conversation_id) REFERENCES social_conversations(company_id,id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
