ALTER TABLE quote_cost_items ADD COLUMN charge_basis VARCHAR(32) NOT NULL DEFAULT 'PAYING_PAX', ADD COLUMN review_required TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE quote_versions ADD COLUMN document_language VARCHAR(2) NOT NULL DEFAULT 'en';


UPDATE quote_cost_items c LEFT JOIN rate_versions rv ON rv.id=c.rate_version_id SET c.charge_basis=COALESCE(rv.rate_basis,'PER_SERVICE');
