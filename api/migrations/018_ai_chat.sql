CREATE TABLE IF NOT EXISTS ai_threads (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL,
 profile VARCHAR(32) NOT NULL,
 context_json JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(user_id) REFERENCES users(id),
 KEY idx_ai_threads_owner(company_id,user_id,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_turns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 thread_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 prompt_hash CHAR(64) NOT NULL,
 prompt TEXT NOT NULL,
 reply MEDIUMTEXT NOT NULL,
 model VARCHAR(120) NOT NULL,
 input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
 output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(thread_id) REFERENCES ai_threads(id),
 UNIQUE KEY uq_ai_turn_request(thread_id,request_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_daily_usage (
 company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 usage_date DATE NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(company_id,user_id,usage_date),
 FOREIGN KEY(company_id) REFERENCES companies(id),
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO permissions(code,name) VALUES('ai.chat','Use private AI draft assistant');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code IN ('ADMIN','SALES','PRODUCT','OPERATIONS') AND p.code='ai.chat';
