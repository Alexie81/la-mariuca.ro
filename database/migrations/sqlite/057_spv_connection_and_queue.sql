CREATE TABLE spv_settings (
 id INTEGER PRIMARY KEY CHECK(id=1), environment TEXT NOT NULL DEFAULT 'test',
 sale_mode TEXT NOT NULL DEFAULT 'manual', sale_days INTEGER NOT NULL DEFAULT 1,
 return_mode TEXT NOT NULL DEFAULT 'manual', return_days INTEGER NOT NULL DEFAULT 1,
 deadline_days INTEGER NOT NULL DEFAULT 5, alerts INTEGER NOT NULL DEFAULT 1,
 holidays_json TEXT NOT NULL DEFAULT '[]', revision INTEGER NOT NULL DEFAULT 1,
 updated_at TEXT NULL, updated_by INTEGER NULL REFERENCES users(id)
);
INSERT INTO spv_settings(id) VALUES(1);
CREATE TABLE spv_credentials (
 id INTEGER PRIMARY KEY CHECK(id=1), encrypted_tokens TEXT NULL, expires_at INTEGER NULL,
 refresh_expires_at INTEGER NULL, revision INTEGER NOT NULL DEFAULT 1,
 refresh_lease TEXT NULL, refresh_lease_until INTEGER NULL, connected_at TEXT NULL,
 checked_at TEXT NULL, last_error TEXT NULL
);
INSERT INTO spv_credentials(id) VALUES(1);
ALTER TABLE invoice_fiscal_jobs ADD COLUMN environment TEXT NOT NULL DEFAULT 'test';
ALTER TABLE invoice_fiscal_jobs ADD COLUMN available_at TEXT NULL;
ALTER TABLE invoice_fiscal_jobs ADD COLUMN payload_xml TEXT NULL;
ALTER TABLE invoice_fiscal_jobs ADD COLUMN download_reference TEXT NULL;
ALTER TABLE invoice_fiscal_jobs ADD COLUMN polled_at TEXT NULL;
ALTER TABLE invoice_fiscal_jobs ADD COLUMN archive_path TEXT NULL;
CREATE INDEX idx_fiscal_due ON invoice_fiscal_jobs(state,available_at);
