-- 037: VTA Marketing Tour Advisor — human-review draft tracking only.
-- Depends on migrations 021 (Tour Library), 024 (Website Inbox), 025 (outbound).
CREATE TABLE IF NOT EXISTS marketing_tour_advisor_drafts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 conversation_id BIGINT UNSIGNED NOT NULL,
 program_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 program_snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 language ENUM('en','vi') NOT NULL DEFAULT 'en',
 draft_text TEXT NOT NULL,
 generated_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_marketing_tour_advisor_request(company_id,conversation_id,request_key),
 UNIQUE KEY uq_marketing_tour_advisor_tenant(company_id,id),
 KEY idx_marketing_tour_advisor_history(company_id,conversation_id,id),
 FOREIGN KEY (company_id,conversation_id) REFERENCES social_conversations(company_id,id),
 FOREIGN KEY (company_id,program_id) REFERENCES tour_library_programs(company_id,id),
 FOREIGN KEY (generated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
