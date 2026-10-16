-- RC6.2 V6.8 Ads landing registry / no production apply.
-- Prerequisite: VIE-6 clone backup/rehearsal, migration ledger and VIE-11 signoff.
-- A published B2C event is required; no Master source/NET document is ever stored.
CREATE TABLE IF NOT EXISTS ads_landing_pages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  slug VARCHAR(90) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  publication_event_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  lead_form_id BIGINT UNSIGNED NOT NULL,
  layout VARCHAR(16) NOT NULL DEFAULT 'detailed',
  status VARCHAR(16) NOT NULL DEFAULT 'PUBLISHED',
  default_utm_campaign VARCHAR(100) NOT NULL DEFAULT '',
  created_by BIGINT UNSIGNED NOT NULL,
  withdrawn_by BIGINT UNSIGNED NULL,
  withdrawn_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uq_ads_slug (slug),
  KEY idx_ads_tenant (company_id, status, publication_event_id),
  KEY idx_ads_campaign (company_id, campaign_id),
  CONSTRAINT fk_ads_company FOREIGN KEY(company_id) REFERENCES companies(id),
  CONSTRAINT fk_ads_b2c_event FOREIGN KEY(publication_event_id) REFERENCES b2c_publication_events(id),
  CONSTRAINT fk_ads_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id),
  CONSTRAINT fk_ads_lead_form FOREIGN KEY(lead_form_id) REFERENCES lead_forms(id),
  CONSTRAINT fk_ads_actor FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aggregated first-party statistics only: no IP, user-agent, cookie ID,
-- fingerprint, session token, email, phone, or other personal data.
CREATE TABLE IF NOT EXISTS ads_landing_metrics (
  landing_id BIGINT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  event_type VARCHAR(12) NOT NULL,
  utm_source VARCHAR(80) NOT NULL DEFAULT '',
  utm_medium VARCHAR(80) NOT NULL DEFAULT '',
  utm_campaign VARCHAR(100) NOT NULL DEFAULT '',
  count_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY(landing_id,day,event_type,utm_source,utm_medium,utm_campaign),
  CONSTRAINT fk_ads_metric_landing FOREIGN KEY(landing_id) REFERENCES ads_landing_pages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent lead attribution linked to canonical LeadHub request. Existing
-- request processing remains atomic; no duplicate conversion for retries.
CREATE TABLE IF NOT EXISTS ads_landing_lead_links (
  landing_id BIGINT UNSIGNED NOT NULL,
  company_id BIGINT UNSIGNED NOT NULL,
  lead_request_id BIGINT UNSIGNED NOT NULL,
  publication_event_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(landing_id,lead_request_id),
  UNIQUE KEY uq_ads_one_lead (company_id,lead_request_id),
  CONSTRAINT fk_ads_link_landing FOREIGN KEY(landing_id) REFERENCES ads_landing_pages(id),
  CONSTRAINT fk_ads_link_lead FOREIGN KEY(lead_request_id) REFERENCES lead_requests(id),
  CONSTRAINT fk_ads_link_publication FOREIGN KEY(publication_event_id) REFERENCES b2c_publication_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
