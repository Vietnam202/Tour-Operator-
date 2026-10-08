-- P6 migration: Instagram single-JPEG publishing metadata.
-- The original P5 queue remains intact. No token or confidential URL parameters stored.
ALTER TABLE marketing_publish_jobs
 ADD COLUMN media_url_snapshot VARCHAR(1000) NULL AFTER message_snapshot,
 ADD COLUMN media_container_id VARCHAR(128) NULL AFTER provider_post_id;

-- Existing provider column in P5 already supports INSTAGRAM_BUSINESS as VARCHAR(32).
-- Worker must never resend a job with an uncertain result.
