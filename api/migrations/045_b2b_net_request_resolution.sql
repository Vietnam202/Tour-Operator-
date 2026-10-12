-- RC6.2 VIE-13: trace NET Request assignment and approved immutable Quote Version.
-- Additive only. Never execute on production without clone/ledger/recovery approval.
ALTER TABLE b2b_net_requests
  ADD COLUMN assigned_to_user_id BIGINT UNSIGNED NULL AFTER status,
  ADD COLUMN source_quote_version_id BIGINT UNSIGNED NULL AFTER assigned_to_user_id,
  ADD COLUMN resolved_by_user_id BIGINT UNSIGNED NULL AFTER source_quote_version_id,
  ADD COLUMN resolved_at DATETIME NULL AFTER resolved_by_user_id,
  ADD KEY idx_b2b_net_owner(company_id,assigned_to_user_id,status),
  ADD KEY idx_b2b_net_source_quote(company_id,source_quote_version_id),
  ADD CONSTRAINT fk_b2b_net_assignee FOREIGN KEY (assigned_to_user_id) REFERENCES users(id),
  ADD CONSTRAINT fk_b2b_net_source_version FOREIGN KEY (source_quote_version_id) REFERENCES quote_versions(id),
  ADD CONSTRAINT fk_b2b_net_resolver FOREIGN KEY (resolved_by_user_id) REFERENCES users(id);
-- Existing REQUESTED/IN_REVIEW/CLOSED rows are preserved unchanged.
-- Old CLOSED rows remain unlinked historical status; never fabricate a source Quote.
