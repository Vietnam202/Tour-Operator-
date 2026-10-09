-- 038: Versioned, expiring, revocable Tour Library marketing share links.
-- P4 is customer-safe HTML itinerary outline only (no quotes, PDFs or supplier costing.
CREATE TABLE IF NOT EXISTS marketing_tour_shares (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 conversation_id BIGINT UNSIGNED NOT NULL,
 program_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 program_snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_json JSON NOT NULL,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 view_count INT UNSIGNED NOT NULL DEFAULT 0,
 last_opened_at DATETIME NULL,
 UNIQUE KEY uq_tour_share_request(company_id,conversation_id,request_key),
 UNIQUE KEY uq_tour_share_token(token_hash),
 UNIQUE KEY uq_tour_share_tenant(company_id,id),
 KEY idx_tour_share_conversation(company_id,conversation_id,id),
 KEY idx_tour_share_expiry(expires_at),
 FOREIGN KEY(company_id,conversation_id) REFERENCES social_conversations(company_id,id),
 FOREIGN KEY(company_id,program_id) REFERENCES tour_library_programs(company_id,id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
