CREATE TABLE invoices (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 document_key VARCHAR(128) NOT NULL UNIQUE,
 primary_order_id BIGINT UNSIGNED NULL UNIQUE,
 order_id BIGINT UNSIGNED NOT NULL,
 parent_id BIGINT UNSIGNED NULL,
 replacement_for_id BIGINT UNSIGNED NULL,
 kind VARCHAR(24) NOT NULL CHECK(kind IN ('sale','return','correction_negative','corrected_positive')),
 series_id BIGINT UNSIGNED NOT NULL, series_code VARCHAR(30) NOT NULL,
 number INTEGER NOT NULL, active_number INTEGER NULL,
 issued_at VARCHAR(30) NOT NULL, due_date VARCHAR(10) NOT NULL,
 currency VARCHAR(3) NOT NULL, payment_method VARCHAR(20) NOT NULL,
 payment_state VARCHAR(24) NOT NULL, paid_bani BIGINT NOT NULL DEFAULT 0,
 net_bani BIGINT NOT NULL, tax_bani BIGINT NOT NULL, gross_bani BIGINT NOT NULL, discount_bani BIGINT NOT NULL,
 snapshot_json MEDIUMTEXT NOT NULL, search_text TEXT NOT NULL,
 reason TEXT NOT NULL, notes TEXT NOT NULL,
 fiscal_state VARCHAR(30) NOT NULL DEFAULT 'unconfigured', fiscal_attempted_at VARCHAR(30) NULL, fiscal_reference VARCHAR(200) NULL,
 email_state VARCHAR(30) NOT NULL DEFAULT 'not_sent',
 row_version INTEGER NOT NULL DEFAULT 1, created_by BIGINT UNSIGNED NOT NULL,
 deleted_at VARCHAR(30) NULL, updated_at VARCHAR(30) NOT NULL,
 UNIQUE(series_code,active_number), FOREIGN KEY (primary_order_id) REFERENCES orders(id), FOREIGN KEY (order_id) REFERENCES orders(id), FOREIGN KEY (parent_id) REFERENCES invoices(id), FOREIGN KEY (replacement_for_id) REFERENCES invoices(id), FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX invoices_registry ON invoices(deleted_at,issued_at,id);
CREATE INDEX invoices_order ON invoices(order_id,parent_id);
CREATE TABLE invoice_lines (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, invoice_id BIGINT UNSIGNED NOT NULL,
 parent_line_id BIGINT UNSIGNED NULL, position INTEGER NOT NULL,
 order_item_id BIGINT UNSIGNED NULL, product_id BIGINT UNSIGNED NULL, variant_id BIGINT UNSIGNED NULL,
 kind VARCHAR(24) NOT NULL, name TEXT NOT NULL, sku VARCHAR(200) NOT NULL, unit VARCHAR(30) NOT NULL,
 quantity_units BIGINT NOT NULL, unit_gross VARCHAR(40) NOT NULL, unit_net VARCHAR(40) NOT NULL,
 vat_basis_points INTEGER NOT NULL, discount_bani BIGINT NOT NULL,
 net_bani BIGINT NOT NULL, tax_bani BIGINT NOT NULL, gross_bani BIGINT NOT NULL,
 scope_type VARCHAR(20) NULL, scope_id BIGINT UNSIGNED NULL, warehouse_id BIGINT UNSIGNED NULL,
 snapshot_json MEDIUMTEXT NOT NULL,
 UNIQUE(invoice_id,position), FOREIGN KEY (invoice_id) REFERENCES invoices(id), FOREIGN KEY (parent_line_id) REFERENCES invoice_lines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX invoice_lines_parent ON invoice_lines(parent_line_id);
CREATE TABLE invoice_actions (
 request_key VARCHAR(128) PRIMARY KEY, action VARCHAR(40) NOT NULL, request_hash VARCHAR(64) NOT NULL,
 invoice_id BIGINT UNSIGNED NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY (invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE invoice_audit (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, invoice_id BIGINT UNSIGNED NULL,
 actor_id BIGINT UNSIGNED NULL, action VARCHAR(50) NOT NULL, reason TEXT NOT NULL,
 before_json MEDIUMTEXT NOT NULL, after_json MEDIUMTEXT NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY (invoice_id) REFERENCES invoices(id), FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE invoice_public_links (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, invoice_id BIGINT UNSIGNED NOT NULL,
 token_hash VARCHAR(64) NOT NULL UNIQUE, created_at VARCHAR(30) NOT NULL, revoked_at VARCHAR(30) NULL, FOREIGN KEY (invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE invoice_receipts (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, invoice_id BIGINT UNSIGNED NOT NULL UNIQUE,
 actor_id BIGINT UNSIGNED NOT NULL, received_at VARCHAR(30) NOT NULL,
 lines_json TEXT NOT NULL, notes TEXT NOT NULL, reversed_at VARCHAR(30) NULL, FOREIGN KEY (invoice_id) REFERENCES invoices(id), FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE invoice_receipt_allocations (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, receipt_id BIGINT UNSIGNED NOT NULL,
 invoice_line_id BIGINT UNSIGNED NOT NULL, consumption_id BIGINT UNSIGNED NULL,
 layer_id BIGINT UNSIGNED NULL, quantity_units BIGINT NOT NULL, value_bani BIGINT NOT NULL,
 source_kind VARCHAR(20) NOT NULL, FOREIGN KEY (receipt_id) REFERENCES invoice_receipts(id), FOREIGN KEY (invoice_line_id) REFERENCES invoice_lines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE invoice_return_requests (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, invoice_id BIGINT UNSIGNED NOT NULL,
 order_id BIGINT UNSIGNED NOT NULL, request_key VARCHAR(128) NOT NULL UNIQUE,
 state VARCHAR(24) NOT NULL DEFAULT 'pending', reason TEXT NOT NULL,
 request_json TEXT NOT NULL, decision_json TEXT NOT NULL, credit_invoice_id BIGINT UNSIGNED NULL,
 created_at VARCHAR(30) NOT NULL, decided_at VARCHAR(30) NULL, decided_by BIGINT UNSIGNED NULL, FOREIGN KEY (invoice_id) REFERENCES invoices(id), FOREIGN KEY (order_id) REFERENCES orders(id), FOREIGN KEY (credit_invoice_id) REFERENCES invoices(id), FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE invoice_reminder_dismissals (
 actor_id BIGINT UNSIGNED PRIMARY KEY, dismissed_on VARCHAR(10) NOT NULL, FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE invoice_refunds (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, invoice_id BIGINT UNSIGNED NOT NULL,
 amount_bani BIGINT NOT NULL CHECK(amount_bani>0), reference TEXT NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL, created_at VARCHAR(30) NOT NULL, FOREIGN KEY (invoice_id) REFERENCES invoices(id), FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE invoice_automation_intents ADD COLUMN invoice_id BIGINT UNSIGNED NULL, ADD FOREIGN KEY (invoice_id) REFERENCES invoices(id);
ALTER TABLE invoice_automation_intents ADD COLUMN state VARCHAR(24) NOT NULL DEFAULT 'pending';
ALTER TABLE invoice_automation_intents ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0;
ALTER TABLE invoice_automation_intents ADD COLUMN last_error TEXT NULL;
ALTER TABLE invoice_automation_intents ADD COLUMN updated_at VARCHAR(30) NULL;
ALTER TABLE outbox_messages ADD COLUMN deduplication_key VARCHAR(128) NULL;
CREATE UNIQUE INDEX outbox_deduplication ON outbox_messages(deduplication_key);
