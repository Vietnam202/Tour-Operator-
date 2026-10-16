-- VIE-15: reversible inquiry removal. Never delete trips, quotes or sent snapshots.
ALTER TABLE inquiries
 ADD COLUMN deleted_at DATETIME NULL,
 ADD COLUMN deleted_by BIGINT UNSIGNED NULL,
 ADD KEY idx_inquiries_trash(company_id,deleted_at),
 ADD CONSTRAINT fk_inquiry_deleted_by FOREIGN KEY(deleted_by) REFERENCES users(id);
