CREATE TABLE IF NOT EXISTS travel_documents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 booking_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('FULL_ITINERARY','HOTEL_VOUCHER','TRANSFER_VOUCHER','ACTIVITY_VOUCHER','CRUISE_VOUCHER','TRAVEL_PACK') NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_booking_document(booking_id,kind),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(booking_id) REFERENCES bookings(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS travel_document_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_id BIGINT UNSIGNED NOT NULL,
 version_no INT UNSIGNED NOT NULL,
 status ENUM('DRAFT','REVIEW','READY','ISSUED','SENT','SUPERSEDED') NOT NULL DEFAULT 'DRAFT',
 visibility ENUM('HIDE_PRICE','PACKAGE_PRICE') NOT NULL DEFAULT 'HIDE_PRICE',
 public_snapshot_json JSON NOT NULL,
 content_hash CHAR(64) NOT NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 issued_by BIGINT UNSIGNED NULL,
 issued_at DATETIME NULL,
 sent_at DATETIME NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_travel_doc_version(document_id,version_no),
 FOREIGN KEY(document_id) REFERENCES travel_documents(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id),
 FOREIGN KEY(issued_by) REFERENCES users(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions(code,name) VALUES('travel_document.view','View customer travel documents'),('travel_document.manage','Prepare travel document versions'),('travel_document.issue','Review and issue travel documents');
INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code IN ('ADMIN','OPERATIONS') AND p.code IN ('travel_document.view','travel_document.manage','travel_document.issue');
INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='SALES' AND p.code='travel_document.view';
