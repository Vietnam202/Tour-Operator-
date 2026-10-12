-- Additive: retain request identities and existing qualification statuses.
ALTER TABLE lead_requests
 MODIFY status ENUM('NEW','QUALIFIED','REJECTED','FOLLOW_LATER') NOT NULL DEFAULT 'NEW',
 ADD next_action_due DATETIME NULL,
 ADD followup_owner_user_id BIGINT UNSIGNED NULL,
 ADD KEY idx_request_followup(company_id,status,next_action_due),
 ADD CONSTRAINT fk_request_followup_owner FOREIGN KEY(followup_owner_user_id) REFERENCES users(id);
