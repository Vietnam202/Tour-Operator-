-- VS2.1 additive children. Supported baseline: 001-022; no backfill.

CREATE TABLE rate_version_vs2_terms (
 rate_version_id BIGINT UNSIGNED NOT NULL,
 formula_code VARCHAR(40) NOT NULL,
 star_level TINYINT UNSIGNED NULL,
 capacity INT UNSIGNED NULL,
 rate_eligibility_source VARCHAR(32) NOT NULL,
 package_scope_json JSON NOT NULL,
 basis_evidence_json JSON NOT NULL,
 approval_state VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
 content_hash CHAR(64) NULL,
 approved_by BIGINT UNSIGNED NULL,
 approved_at DATETIME NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (rate_version_id),
 FOREIGN KEY (rate_version_id) REFERENCES rate_versions(id),
 FOREIGN KEY (updated_by) REFERENCES users(id),
 FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_version_inclusions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 rate_version_id BIGINT UNSIGNED NOT NULL,
 component_key VARCHAR(80) NOT NULL,
 included_category VARCHAR(32) NOT NULL,
 scope_rule_json JSON NOT NULL,
 coverage_rule_json JSON NOT NULL,
 description VARCHAR(1000) NOT NULL,
 sort_order INT UNSIGNED NOT NULL,
 UNIQUE KEY uq_vs21_component (rate_version_id,component_key),
 FOREIGN KEY (rate_version_id) REFERENCES rate_version_vs2_terms(rate_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

