-- Workflow children refer to the accepted booking snapshot; no historical backfill.
CREATE TABLE booking_handovers (
 booking_id BIGINT UNSIGNED PRIMARY KEY,
 stage VARCHAR(32) NOT NULL DEFAULT 'COMMERCIAL_CONFIRMED',
 intake_status VARCHAR(24) NOT NULL DEFAULT 'NEW_HANDOVER',
 checklist_json JSON NOT NULL,
 source_hash CHAR(64) NOT NULL,
 sales_notes TEXT NOT NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
 created_by BIGINT UNSIGNED NOT NULL,
 submitted_by BIGINT UNSIGNED NULL,
 submitted_at DATETIME NULL,
 accepted_by BIGINT UNSIGNED NULL,
 accepted_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(booking_id) REFERENCES booking_quote_snapshots(booking_id),
 FOREIGN KEY(created_by) REFERENCES users(id),
 FOREIGN KEY(submitted_by) REFERENCES users(id),
 FOREIGN KEY(accepted_by) REFERENCES users(id),
 CHECK(stage IN ('COMMERCIAL_CONFIRMED','HANDOVER_PREPARATION','HANDED_TO_OPERATIONS')),
 CHECK(intake_status IN ('NEW_HANDOVER','NEEDS_REVIEW','READY_TO_BOOK'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE booking_handover_services (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 booking_id BIGINT UNSIGNED NOT NULL,
 booking_service_id BIGINT UNSIGNED NULL,
 source_requirement_id BIGINT UNSIGNED NULL,
 category VARCHAR(32) NOT NULL,
 service_name VARCHAR(255) NOT NULL,
 service_date DATE NULL,
 service_end_date DATE NULL,
 destination VARCHAR(160) NOT NULL,
 pax INT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 specification_json JSON NOT NULL,
 classification VARCHAR(40) NOT NULL DEFAULT 'CONFIRMED_BY_SALES',
 match_rule VARCHAR(40) NOT NULL DEFAULT 'MUST_MATCH',
 source VARCHAR(24) NOT NULL DEFAULT 'QUOTE',
 source_reference_json JSON NOT NULL,
 sales_notes TEXT NOT NULL,
 UNIQUE KEY uq_vs23_handover_requirement(booking_id,source_requirement_id),
 FOREIGN KEY(booking_id) REFERENCES booking_handovers(booking_id),
 FOREIGN KEY(booking_service_id) REFERENCES booking_services(id),
 FOREIGN KEY(source_requirement_id) REFERENCES quote_service_requirements(id),
 CHECK(classification IN ('REQUESTED_BY_CUSTOMER','CONFIRMED_BY_SALES','TO_BE_ARRANGED_BY_OPERATIONS')),
 CHECK(match_rule IN ('MUST_MATCH','FLEXIBLE_EQUIVALENT_ALLOWED')),
 CHECK(source IN ('QUOTE','PROPOSAL','CUSTOMER_MESSAGE','AGENT_MESSAGE','CONTRACT','SALES_NOTE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE booking_handover_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 booking_id BIGINT UNSIGNED NOT NULL,
 event VARCHAR(40) NOT NULL,
 stage VARCHAR(32) NOT NULL,
 intake_status VARCHAR(24) NOT NULL,
 reason VARCHAR(1000) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(booking_id) REFERENCES booking_handovers(booking_id),
 FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE booking_change_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 booking_id BIGINT UNSIGNED NOT NULL,
 requested_by BIGINT UNSIGNED NOT NULL,
 source VARCHAR(24) NOT NULL,
 description TEXT NOT NULL,
 affected_services_json JSON NOT NULL,
 commercial_impact VARCHAR(16) NOT NULL DEFAULT 'UNKNOWN',
 impact_description TEXT NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'OPEN',
 revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
 updated_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(booking_id) REFERENCES bookings(id),
 FOREIGN KEY(requested_by) REFERENCES users(id),
 FOREIGN KEY(updated_by) REFERENCES users(id),
 CHECK(status IN ('OPEN','UNDER_REVIEW','APPROVED','REJECTED','IMPLEMENTED')),
 CHECK(commercial_impact IN ('UNKNOWN','KNOWN'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO permissions(code,name) VALUES
 ('sales.handover_create','Prepare sales handovers'),
 ('sales.handover_submit','Submit sales handovers'),
 ('operations.handover_accept','Accept sales handovers'),
 ('operations.handover_return','Return handovers for clarification'),
 ('booking.change_request_manage','Manage booking change requests');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code IN ('sales.handover_create','sales.handover_submit') WHERE r.code='ADMIN';
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code IN ('operations.handover_accept','operations.handover_return') WHERE r.code='ADMIN';
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='booking.change_request_manage' WHERE r.code='ADMIN';
