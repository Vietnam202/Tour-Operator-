-- RC6.2 B2B hybrid distribution (additive, review before running on staging)
ALTER TABLE b2b_publications
  ADD COLUMN distribution_scope ENUM('COMMON','EXCLUSIVE') NOT NULL DEFAULT 'COMMON',
  ADD COLUMN exclusive_agency_id BIGINT UNSIGNED NULL,
  ADD KEY idx_b2b_publication_access(company_id,distribution_scope,exclusive_agency_id),
  ADD CONSTRAINT fk_b2b_publication_exclusive_agency FOREIGN KEY (company_id,exclusive_agency_id) REFERENCES b2b_agencies(company_id,id);
-- Keep legacy published versions COMMON, never auto-share new Exclusive versions.
-- Enforce scope/agency invariant at application transaction boundary for MariaDB compatibility.

-- Sales B2B NET request queue; commercial approval remains in existing quote engine.
CREATE TABLE IF NOT EXISTS b2b_net_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 agency_id BIGINT UNSIGNED NOT NULL,
 partner_tour_id BIGINT UNSIGNED NOT NULL,
 publication_id BIGINT UNSIGNED NOT NULL,
 requested_by BIGINT UNSIGNED NOT NULL,
 departure_date DATE NOT NULL,
 paying_pax INT UNSIGNED NOT NULL,
 foc_pax INT UNSIGNED NOT NULL DEFAULT 0,
 mode ENUM('PRIVATE','SIC') NOT NULL,
 hotel_level CHAR(1) NOT NULL,
 cruise_level CHAR(1) NOT NULL DEFAULT '',
 notes TEXT NOT NULL,
 status ENUM('REQUESTED','IN_REVIEW','CLOSED') NOT NULL DEFAULT 'REQUESTED',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_b2b_net_company_status(company_id,status,created_at),
 KEY idx_b2b_net_agency(company_id,agency_id,created_at),
 FOREIGN KEY (company_id,agency_id,partner_tour_id)
   REFERENCES b2b_partner_tours(company_id,agency_id,id),
 FOREIGN KEY (company_id,publication_id) REFERENCES b2b_publications(company_id,id),
 FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
