-- Preserve Sales handover identities, conversion links and prior acceptance.
ALTER TABLE leads
 MODIFY handover_status ENUM('PENDING','ACCEPTED','RETURNED','CONVERTED','FOLLOW_LATER','NOT_POTENTIAL') NOT NULL DEFAULT 'PENDING',
 ADD potential_previous_status VARCHAR(24) NULL;
