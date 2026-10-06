-- Proposal configuration reuses quote_versions.proposal_json.
-- Issued presentation reuses quote_sent_bundles. No second revision/snapshot ledger.
CREATE TABLE proposal_public_links (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 quote_version_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_vs22_proposal_token (token_hash),
 FOREIGN KEY (company_id) REFERENCES companies(id),
 FOREIGN KEY (quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO permissions(code,name) VALUES
 ('proposal.edit','Edit proposal presentation'),
 ('proposal.send','Publish sent proposal links'),
 ('proposal.white_label','Manage white label branding');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT rp.role_id,np.id FROM role_permissions rp JOIN permissions op ON op.id=rp.permission_id
 JOIN permissions np ON np.code='proposal.edit' WHERE op.code='quote.edit';
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT rp.role_id,np.id FROM role_permissions rp JOIN permissions op ON op.id=rp.permission_id
 JOIN permissions np ON np.code='proposal.send' WHERE op.code='quote.send';
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='proposal.white_label' WHERE r.code IN ('ADMIN','CEO');
