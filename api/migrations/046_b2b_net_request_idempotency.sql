-- RC6.2 VIE-13: safe Request NET retries; no previous request is modified.
-- NULL for historical rows; MySQL/MariaDB permits multiple NULL unique values.
-- Only run after independently verified staging clone/ledger/rollback.
ALTER TABLE b2b_net_requests
  ADD COLUMN request_key_hash CHAR(64) NULL AFTER notes,
  ADD COLUMN request_payload_hash CHAR(64) NULL AFTER request_key_hash,
  ADD UNIQUE KEY uq_b2b_net_retry(company_id,agency_id,request_key_hash);
