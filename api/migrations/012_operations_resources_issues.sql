CREATE TABLE IF NOT EXISTS operation_resources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('GUIDE','DRIVER','VEHICLE') NOT NULL,
 name VARCHAR(190) NOT NULL,
 phone VARCHAR(64) NULL,
 capacity INT UNSIGNED NULL,
 status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_resource_kind(company_id,kind,status),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resource_assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 resource_id BIGINT UNSIGNED NOT NULL,
 service_id BIGINT UNSIGNED NOT NULL,
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 status ENUM('ASSIGNED','CANCELLED') NOT NULL DEFAULT 'ASSIGNED',
 assigned_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_resource_time(resource_id,status,starts_at,ends_at),
 FOREIGN KEY(resource_id) REFERENCES operation_resources(id),
 FOREIGN KEY(service_id) REFERENCES booking_services(id),
 FOREIGN KEY(assigned_by) REFERENCES users(id),
 CHECK(ends_at>starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operational_issues (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 booking_id BIGINT UNSIGNED NOT NULL,
 category VARCHAR(64) NOT NULL,
 severity ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL,
 title VARCHAR(190) NOT NULL,
 description TEXT NOT NULL,
 owner_user_id BIGINT UNSIGNED NOT NULL,
 status ENUM('OPEN','INVESTIGATING','RESOLVED','CLOSED') NOT NULL DEFAULT 'OPEN',
 root_cause TEXT NULL,
 resolution TEXT NULL,
 lessons_learned TEXT NULL,
 financial_impact DECIMAL(18,2) NOT NULL DEFAULT 0,
 currency CHAR(3) NOT NULL DEFAULT 'USD',
 created_by BIGINT UNSIGNED NOT NULL,
 resolved_by BIGINT UNSIGNED NULL,
 resolved_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_issue_queue(company_id,status,severity),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(booking_id) REFERENCES bookings(id),
 FOREIGN KEY(owner_user_id) REFERENCES users(id),
 FOREIGN KEY(created_by) REFERENCES users(id),
 FOREIGN KEY(resolved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
