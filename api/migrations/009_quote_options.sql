CREATE TABLE IF NOT EXISTS quote_options (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 quote_version_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(190) NOT NULL,
 hotel_level ENUM('3*','4*','5*') NOT NULL,
 snapshot_json JSON NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_option_level(quote_version_id,hotel_level),
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_bundle_approvals (
 quote_version_id BIGINT UNSIGNED PRIMARY KEY,
 content_hash CHAR(64) NOT NULL,
 reason VARCHAR(1000) NOT NULL,
 approved_by BIGINT UNSIGNED NOT NULL,
 approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY(approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_sent_bundles (
 quote_version_id BIGINT UNSIGNED PRIMARY KEY,
 public_snapshot_json JSON NOT NULL,
 internal_snapshot_json JSON NOT NULL,
 content_hash CHAR(64) NOT NULL,
 sent_by BIGINT UNSIGNED NOT NULL,
 sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY(sent_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_acceptances (
 quote_version_id BIGINT UNSIGNED PRIMARY KEY,
 option_id BIGINT UNSIGNED NOT NULL,
 sent_content_hash CHAR(64) NOT NULL,
 accepted_by BIGINT UNSIGNED NOT NULL,
 accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY(option_id) REFERENCES quote_options(id),
 FOREIGN KEY(accepted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_quote_snapshots (
 booking_id BIGINT UNSIGNED PRIMARY KEY,
 quote_version_id BIGINT UNSIGNED NOT NULL,
 option_id BIGINT UNSIGNED NOT NULL,
 public_snapshot_json JSON NOT NULL,
 internal_snapshot_json JSON NOT NULL,
 source_hash CHAR(64) NOT NULL,
 FOREIGN KEY(booking_id) REFERENCES bookings(id),
 FOREIGN KEY(quote_version_id) REFERENCES quote_versions(id),
 FOREIGN KEY(option_id) REFERENCES quote_options(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
