-- VIE-15: reject stale removal/restoration requests, including archive/restore/archive retries.
ALTER TABLE inquiries ADD COLUMN version_no INT UNSIGNED NOT NULL DEFAULT 1;
