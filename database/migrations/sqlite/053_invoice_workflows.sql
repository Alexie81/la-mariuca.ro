CREATE TABLE invoice_return_policy (
 id INTEGER PRIMARY KEY CHECK(id=1), days INTEGER NOT NULL DEFAULT 14,
 allow_companies INTEGER NOT NULL DEFAULT 0, allow_personalized INTEGER NOT NULL DEFAULT 0,
 updated_at TEXT NULL, updated_by INTEGER NULL REFERENCES users(id)
);
INSERT INTO invoice_return_policy(id) VALUES(1);
CREATE TABLE invoice_fiscal_jobs (
 invoice_id INTEGER PRIMARY KEY REFERENCES invoices(id), state TEXT NOT NULL,
 attempts INTEGER NOT NULL DEFAULT 0, payload_hash TEXT NOT NULL, external_reference TEXT NULL,
 last_error TEXT NULL, response_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
