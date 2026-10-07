-- Exact selection attaches to the existing immutable acceptance, not a new master.
CREATE TABLE quote_commercial_acceptances (
 quote_version_id BIGINT UNSIGNED PRIMARY KEY,
 matrix_id BIGINT UNSIGNED NOT NULL,
 cell_id BIGINT UNSIGNED NOT NULL,
 paying_pax INT UNSIGNED NOT NULL,
 commitment_json JSON NOT NULL,
 recheck_json JSON NOT NULL,
 commitment_hash CHAR(64) NOT NULL,
 accepted_by BIGINT UNSIGNED NOT NULL,
 accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(quote_version_id) REFERENCES quote_acceptances(quote_version_id),
 FOREIGN KEY(matrix_id,quote_version_id) REFERENCES quote_price_matrices(id,quote_version_id),
 FOREIGN KEY(cell_id,matrix_id) REFERENCES quote_price_matrix_cells(id,matrix_id),
 FOREIGN KEY(accepted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
