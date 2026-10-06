-- Historical selections remain option-only with NULL variant.
ALTER TABLE quote_acceptances ADD COLUMN variant_id BIGINT UNSIGNED NULL, ADD CONSTRAINT fk_vs21_accept_variant FOREIGN KEY (variant_id) REFERENCES quote_option_variants(id);

ALTER TABLE booking_quote_snapshots ADD COLUMN variant_id BIGINT UNSIGNED NULL, ADD CONSTRAINT fk_vs21_booking_variant FOREIGN KEY (variant_id) REFERENCES quote_option_variants(id);
