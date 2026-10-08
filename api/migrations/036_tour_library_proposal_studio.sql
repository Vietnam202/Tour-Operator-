-- VTA Proposal Studio: additive, company-scoped customer-facing proposal content.
-- Keep supplier costs and financial records in their existing authorized tables.
ALTER TABLE tour_library_programs
 ADD COLUMN proposal_json JSON NULL AFTER terms_text;
