CREATE TABLE invoice_return_policy (
 id INTEGER PRIMARY KEY CHECK(id=1), days INTEGER NOT NULL DEFAULT 14,
 allow_companies INTEGER NOT NULL DEFAULT 0, allow_personalized INTEGER NOT NULL DEFAULT 0,
 updated_at VARCHAR(30) NULL, updated_by BIGINT UNSIGNED NULL,
 FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO invoice_return_policy(id) VALUES(1);
CREATE TABLE invoice_fiscal_jobs (
 invoice_id BIGINT UNSIGNED PRIMARY KEY, state VARCHAR(30) NOT NULL,
 attempts INTEGER NOT NULL DEFAULT 0, payload_hash VARCHAR(64) NOT NULL, external_reference VARCHAR(200) NULL,
 last_error TEXT NULL, response_json MEDIUMTEXT NULL, created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL,
 FOREIGN KEY(invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
