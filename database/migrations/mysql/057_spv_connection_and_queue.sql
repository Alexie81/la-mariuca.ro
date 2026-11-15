CREATE TABLE spv_settings (
 id INT PRIMARY KEY, environment VARCHAR(12) NOT NULL DEFAULT 'test',
 sale_mode VARCHAR(20) NOT NULL DEFAULT 'manual', sale_days INT NOT NULL DEFAULT 1,
 return_mode VARCHAR(20) NOT NULL DEFAULT 'manual', return_days INT NOT NULL DEFAULT 1,
 deadline_days INT NOT NULL DEFAULT 5, alerts INT NOT NULL DEFAULT 1,
 holidays_json LONGTEXT NOT NULL, revision INT NOT NULL DEFAULT 1,
 updated_at VARCHAR(32) NULL, updated_by BIGINT UNSIGNED NULL
) ENGINE=InnoDB;
INSERT INTO spv_settings(id,holidays_json) VALUES(1,'[]');
CREATE TABLE spv_credentials (
 id INT PRIMARY KEY, encrypted_tokens LONGTEXT NULL, expires_at BIGINT NULL,
 refresh_expires_at BIGINT NULL, revision INT NOT NULL DEFAULT 1,
 refresh_lease VARCHAR(64) NULL, refresh_lease_until BIGINT NULL, connected_at VARCHAR(32) NULL,
 checked_at VARCHAR(32) NULL, last_error TEXT NULL
) ENGINE=InnoDB;
INSERT INTO spv_credentials(id) VALUES(1);
ALTER TABLE invoice_fiscal_jobs ADD COLUMN environment VARCHAR(12) NOT NULL DEFAULT 'test';
ALTER TABLE invoice_fiscal_jobs ADD COLUMN available_at VARCHAR(32) NULL;
ALTER TABLE invoice_fiscal_jobs ADD COLUMN payload_xml LONGTEXT NULL;
ALTER TABLE invoice_fiscal_jobs ADD COLUMN download_reference VARCHAR(190) NULL;
ALTER TABLE invoice_fiscal_jobs ADD COLUMN polled_at VARCHAR(32) NULL;
ALTER TABLE invoice_fiscal_jobs ADD COLUMN archive_path TEXT NULL;
CREATE INDEX idx_fiscal_due ON invoice_fiscal_jobs(state,available_at);
