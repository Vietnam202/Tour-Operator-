-- Append-only company policy versions; no defaults/backfill on historical quotes.
CREATE TABLE commercial_policies (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 policy_key VARCHAR(80) NOT NULL,
 version_no INT UNSIGNED NOT NULL,
 name VARCHAR(190) NOT NULL,
 channel VARCHAR(24) NOT NULL,
 policy_mode VARCHAR(24) NOT NULL,
 pricing_value DECIMAL(14,4) NOT NULL,
 minimum_margin_pct DECIMAL(7,4) NOT NULL,
 warning_margin_pct DECIMAL(7,4) NOT NULL,
 commission_pct DECIMAL(7,4) NOT NULL DEFAULT 0,
 deposit_pct DECIMAL(7,4) NOT NULL DEFAULT 0,
 rounding_step DECIMAL(14,2) NOT NULL DEFAULT 0,
 selling_currency CHAR(3) NOT NULL,
 validity_days INT UNSIGNED NOT NULL DEFAULT 14,
 payment_terms TEXT NOT NULL,
 cancellation_policy TEXT NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_vs23_policy_version(company_id,policy_key,version_no),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(created_by) REFERENCES users(id),
 CHECK(channel IN ('B2B_AGENT','B2C_DIRECT')),
 CHECK(commission_pct>=0 AND commission_pct<100),
 CHECK(channel='B2B_AGENT' OR commission_pct=0),
 CHECK(minimum_margin_pct>=0 AND minimum_margin_pct<100 AND warning_margin_pct>=minimum_margin_pct AND warning_margin_pct<100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO permissions(code,name) VALUES
 ('commercial.policy_manage','Manage commercial policy versions'),
 ('commercial.margin_override','Approve commercial margin overrides'),
 ('commercial.matrix_lock','Lock commercial price matrices');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code IN ('commercial.policy_manage','commercial.margin_override') WHERE r.code='ADMIN';
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='commercial.matrix_lock' WHERE r.code='ADMIN';
