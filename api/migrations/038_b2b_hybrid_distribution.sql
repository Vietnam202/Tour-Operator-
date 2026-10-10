-- RC6.2 B2B hybrid distribution (additive, review before running on staging)
ALTER TABLE b2b_publications
  ADD COLUMN distribution_scope ENUM('COMMON','EXCLUSIVE') NOT NULL DEFAULT 'COMMON',
  ADD COLUMN exclusive_agency_id BIGINT UNSIGNED NULL,
  ADD KEY idx_b2b_publication_access(company_id,distribution_scope,exclusive_agency_id),
  ADD CONSTRAINT fk_b2b_publication_exclusive_agency FOREIGN KEY (company_id,exclusive_agency_id) REFERENCES b2b_agencies(company_id,id);
-- Keep legacy published versions COMMON, never auto-share new Exclusive versions.
-- Enforce scope/agency invariant at application transaction boundary for MariaDB compatibility.
