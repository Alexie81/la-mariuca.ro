CREATE TABLE invoices (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 document_key VARCHAR(128) NOT NULL UNIQUE,
 primary_order_id INTEGER NULL UNIQUE REFERENCES orders(id),
 order_id INTEGER NOT NULL REFERENCES orders(id),
 parent_id INTEGER NULL REFERENCES invoices(id),
 replacement_for_id INTEGER NULL REFERENCES invoices(id),
 kind VARCHAR(24) NOT NULL CHECK(kind IN ('sale','return','correction_negative','corrected_positive')),
 series_id INTEGER NOT NULL, series_code VARCHAR(30) NOT NULL,
 number INTEGER NOT NULL, active_number INTEGER NULL,
 issued_at VARCHAR(30) NOT NULL, due_date VARCHAR(10) NOT NULL,
 currency VARCHAR(3) NOT NULL, payment_method VARCHAR(20) NOT NULL,
 payment_state VARCHAR(24) NOT NULL, paid_bani BIGINT NOT NULL DEFAULT 0,
 net_bani BIGINT NOT NULL, tax_bani BIGINT NOT NULL, gross_bani BIGINT NOT NULL, discount_bani BIGINT NOT NULL,
 snapshot_json TEXT NOT NULL, search_text TEXT NOT NULL,
 reason TEXT NOT NULL, notes TEXT NOT NULL,
 fiscal_state VARCHAR(30) NOT NULL DEFAULT 'unconfigured', fiscal_attempted_at VARCHAR(30) NULL, fiscal_reference VARCHAR(200) NULL,
 email_state VARCHAR(30) NOT NULL DEFAULT 'not_sent',
 row_version INTEGER NOT NULL DEFAULT 1, created_by INTEGER NOT NULL REFERENCES users(id),
 deleted_at VARCHAR(30) NULL, updated_at VARCHAR(30) NOT NULL,
 UNIQUE(series_code,active_number)
);
CREATE INDEX invoices_registry ON invoices(deleted_at,issued_at,id);
CREATE INDEX invoices_order ON invoices(order_id,parent_id);
CREATE TABLE invoice_lines (
 id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER NOT NULL REFERENCES invoices(id),
 parent_line_id INTEGER NULL REFERENCES invoice_lines(id), position INTEGER NOT NULL,
 order_item_id INTEGER NULL, product_id INTEGER NULL, variant_id INTEGER NULL,
 kind VARCHAR(24) NOT NULL, name TEXT NOT NULL, sku VARCHAR(200) NOT NULL, unit VARCHAR(30) NOT NULL,
 quantity_units BIGINT NOT NULL, unit_gross VARCHAR(40) NOT NULL, unit_net VARCHAR(40) NOT NULL,
 vat_basis_points INTEGER NOT NULL, discount_bani BIGINT NOT NULL,
 net_bani BIGINT NOT NULL, tax_bani BIGINT NOT NULL, gross_bani BIGINT NOT NULL,
 scope_type VARCHAR(20) NULL, scope_id INTEGER NULL, warehouse_id INTEGER NULL,
 snapshot_json TEXT NOT NULL,
 UNIQUE(invoice_id,position)
);
CREATE INDEX invoice_lines_parent ON invoice_lines(parent_line_id);
CREATE TABLE invoice_actions (
 request_key VARCHAR(128) PRIMARY KEY, action VARCHAR(40) NOT NULL, request_hash VARCHAR(64) NOT NULL,
 invoice_id INTEGER NOT NULL REFERENCES invoices(id), created_at VARCHAR(30) NOT NULL
);
CREATE TABLE invoice_audit (
 id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER NULL REFERENCES invoices(id),
 actor_id INTEGER NULL REFERENCES users(id), action VARCHAR(50) NOT NULL, reason TEXT NOT NULL,
 before_json TEXT NOT NULL, after_json TEXT NOT NULL, created_at VARCHAR(30) NOT NULL
);
CREATE TABLE invoice_public_links (
 id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER NOT NULL REFERENCES invoices(id),
 token_hash VARCHAR(64) NOT NULL UNIQUE, created_at VARCHAR(30) NOT NULL, revoked_at VARCHAR(30) NULL
);
CREATE TABLE invoice_receipts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER NOT NULL UNIQUE REFERENCES invoices(id),
 actor_id INTEGER NOT NULL REFERENCES users(id), received_at VARCHAR(30) NOT NULL,
 lines_json TEXT NOT NULL, notes TEXT NOT NULL, reversed_at VARCHAR(30) NULL
);
CREATE TABLE invoice_receipt_allocations (
 id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL REFERENCES invoice_receipts(id),
 invoice_line_id INTEGER NOT NULL REFERENCES invoice_lines(id), consumption_id INTEGER NULL,
 layer_id INTEGER NULL, quantity_units BIGINT NOT NULL, value_bani BIGINT NOT NULL,
 source_kind VARCHAR(20) NOT NULL
);
CREATE TABLE invoice_return_requests (
 id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER NOT NULL REFERENCES invoices(id),
 order_id INTEGER NOT NULL REFERENCES orders(id), request_key VARCHAR(128) NOT NULL UNIQUE,
 state VARCHAR(24) NOT NULL DEFAULT 'pending', reason TEXT NOT NULL,
 request_json TEXT NOT NULL, decision_json TEXT NOT NULL, credit_invoice_id INTEGER NULL REFERENCES invoices(id),
 created_at VARCHAR(30) NOT NULL, decided_at VARCHAR(30) NULL, decided_by INTEGER NULL REFERENCES users(id)
);
CREATE TABLE invoice_reminder_dismissals (
 actor_id INTEGER PRIMARY KEY REFERENCES users(id), dismissed_on VARCHAR(10) NOT NULL
);
CREATE TABLE invoice_refunds (
 id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER NOT NULL REFERENCES invoices(id),
 amount_bani BIGINT NOT NULL CHECK(amount_bani>0), reference VARCHAR(200) NOT NULL,
 actor_id INTEGER NOT NULL REFERENCES users(id), created_at VARCHAR(30) NOT NULL
);
ALTER TABLE invoice_automation_intents ADD COLUMN invoice_id INTEGER NULL REFERENCES invoices(id);
ALTER TABLE invoice_automation_intents ADD COLUMN state VARCHAR(24) NOT NULL DEFAULT 'pending';
ALTER TABLE invoice_automation_intents ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0;
ALTER TABLE invoice_automation_intents ADD COLUMN last_error TEXT NULL;
ALTER TABLE invoice_automation_intents ADD COLUMN updated_at VARCHAR(30) NULL;
ALTER TABLE outbox_messages ADD COLUMN deduplication_key VARCHAR(128) NULL;
CREATE UNIQUE INDEX outbox_deduplication ON outbox_messages(deduplication_key);
