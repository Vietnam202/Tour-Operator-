-- Owner authorization: ADMIN, SALES and OPERATIONS can confirm quotations.
-- No cost, profit, approval, editing, payment or CRM permission is added.
-- Existing per-user DENY overrides continue to take precedence.
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code='quote.confirm'
 WHERE r.code IN ('ADMIN','SALES','OPERATIONS');
